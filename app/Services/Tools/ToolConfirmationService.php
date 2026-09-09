<?php

namespace App\Services\Tools;

use App\Enums\ToolConfirmationStatus;
use App\Models\Conversation;
use App\Models\ToolConfirmation;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ToolConfirmationService
{
    public function __construct(
        private readonly ConfirmationIntentParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function createPending(
        User $user,
        Conversation $conversation,
        string $toolName,
        array $arguments,
        ?string $toolCallId = null,
    ): ToolConfirmation {
        $ttl = max(60, (int) config('google_calendar.confirmation_ttl_seconds', 600));

        return ToolConfirmation::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'conversation_id' => $conversation->id,
            'tool_name' => $toolName,
            'tool_call_id' => $toolCallId,
            'arguments_encrypted' => $arguments,
            'status' => ToolConfirmationStatus::Pending,
            'expires_at' => now()->addSeconds($ttl),
        ]);
    }

    public function hasPending(User $user, Conversation $conversation): bool
    {
        return $this->latestPending($user, $conversation) !== null;
    }

    public function latestPending(User $user, Conversation $conversation): ?ToolConfirmation
    {
        $this->expireStale($user, $conversation);

        return ToolConfirmation::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->where('status', ToolConfirmationStatus::Pending)
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();
    }

    public function findOwnedPending(User $user, Conversation $conversation, string $publicId): ?ToolConfirmation
    {
        $this->expireStale($user, $conversation);

        $confirmation = ToolConfirmation::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        if ($confirmation === null) {
            return null;
        }

        if ($confirmation->status === ToolConfirmationStatus::Pending && $confirmation->isExpired()) {
            $confirmation->forceFill(['status' => ToolConfirmationStatus::Expired])->save();
        }

        return $confirmation;
    }

    public function findOwnedByPublicId(User $user, string $publicId): ?ToolConfirmation
    {
        return ToolConfirmation::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->first();
    }

    public function cancel(ToolConfirmation $confirmation): ToolConfirmation
    {
        if ($confirmation->status !== ToolConfirmationStatus::Pending) {
            return $confirmation;
        }

        $confirmation->forceFill([
            'status' => ToolConfirmationStatus::Cancelled,
        ])->save();

        return $confirmation->fresh() ?? $confirmation;
    }

    /**
     * @return array{handled: bool, note: string|null, result: ToolResult|null}
     */
    public function applyInboundIntent(
        User $user,
        Conversation $conversation,
        ToolExecutionContext $context,
        ToolRegistry $registry,
    ): array {
        $intent = $context->confirmationIntent;
        if ($intent === null) {
            $intent = $this->parser->parse($context->inbound?->body);
        }

        if ($intent === null) {
            return ['handled' => false, 'note' => null, 'result' => null];
        }

        $pending = $this->latestPending($user, $conversation);
        if ($pending === null) {
            return ['handled' => false, 'note' => null, 'result' => null];
        }

        if ($intent === ConfirmationIntentParser::CANCEL) {
            $this->cancel($pending);

            return [
                'handled' => true,
                'note' => 'The user cancelled the pending tool action.',
                'result' => null,
            ];
        }

        $result = $this->executeConfirmed($pending, $context, $registry);

        return [
            'handled' => true,
            'note' => $this->resultNote($pending, $result),
            'result' => $result,
        ];
    }

    public function executeConfirmed(
        ToolConfirmation $confirmation,
        ToolExecutionContext $context,
        ToolRegistry $registry,
    ): ToolResult {
        $locked = DB::transaction(function () use ($confirmation): ?ToolConfirmation {
            /** @var ToolConfirmation|null $row */
            $row = ToolConfirmation::query()->whereKey($confirmation->id)->lockForUpdate()->first();

            if ($row === null) {
                return null;
            }

            if ($row->status === ToolConfirmationStatus::Executed) {
                return $row;
            }

            if ($row->status === ToolConfirmationStatus::Cancelled) {
                return $row;
            }

            if ($row->isExpired() || $row->status === ToolConfirmationStatus::Expired) {
                $row->forceFill(['status' => ToolConfirmationStatus::Expired])->save();

                return $row->fresh() ?? $row;
            }

            if ($row->status !== ToolConfirmationStatus::Pending && $row->status !== ToolConfirmationStatus::Confirmed) {
                return $row;
            }

            $row->forceFill([
                'status' => ToolConfirmationStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();

            return $row->fresh() ?? $row;
        });

        if ($locked === null) {
            return ToolResult::failure('confirm', $confirmation->tool_name, [
                'success' => false,
                'error' => 'confirmation_not_found',
            ]);
        }

        if ($locked->status === ToolConfirmationStatus::Executed) {
            return ToolResult::failure($locked->tool_call_id ?? 'confirm', $locked->tool_name, [
                'success' => false,
                'error' => 'confirmation_already_executed',
            ]);
        }

        if ($locked->status === ToolConfirmationStatus::Cancelled) {
            return ToolResult::failure($locked->tool_call_id ?? 'confirm', $locked->tool_name, [
                'success' => false,
                'error' => 'confirmation_cancelled',
            ]);
        }

        if ($locked->status === ToolConfirmationStatus::Expired) {
            return ToolResult::failure($locked->tool_call_id ?? 'confirm', $locked->tool_name, [
                'success' => false,
                'error' => 'confirmation_expired',
            ]);
        }

        $arguments = is_array($locked->arguments_encrypted) ? $locked->arguments_encrypted : [];
        $call = new ToolCall(
            $locked->tool_call_id ?: ('confirm-'.$locked->public_id),
            $locked->tool_name,
            $arguments,
        );

        $bypass = new ToolExecutionContext(
            user: $context->user,
            conversation: $context->conversation,
            inbound: $context->inbound,
            channel: $context->channel,
            explicitUserCommand: $context->explicitUserCommand,
            confirmationIntent: ConfirmationIntentParser::CONFIRM,
            bypassConfirmation: true,
            budgets: $context->budgets,
            working: $context->working,
        );

        $result = $registry->execute($call, $bypass);

        $locked->forceFill([
            'status' => ToolConfirmationStatus::Executed,
            'executed_at' => now(),
        ])->save();

        return $result;
    }

    public function expireStale(User $user, Conversation $conversation): void
    {
        ToolConfirmation::query()
            ->where('user_id', $user->id)
            ->where('conversation_id', $conversation->id)
            ->where('status', ToolConfirmationStatus::Pending)
            ->where('expires_at', '<=', now())
            ->update(['status' => ToolConfirmationStatus::Expired]);
    }

    public function summaryFor(ToolConfirmation $confirmation): string
    {
        return match ($confirmation->tool_name) {
            'delete_calendar_event' => 'Delete the identified Google Calendar event.',
            'create_calendar_event' => 'Create a Google Calendar event.',
            'update_calendar_event' => 'Update the identified Google Calendar event.',
            'create_gmail_draft' => 'Create a Gmail draft. It will not be sent.',
            'modify_gmail_labels' => 'Change Gmail labels on the identified mail.',
            'send_gmail_message' => $this->gmailSendSummary($confirmation),
            'create_github_issue' => 'Create a GitHub issue.',
            'comment_github_issue' => 'Add a GitHub issue or pull request comment.',
            'create_github_branch' => 'Create a GitHub branch.',
            'create_github_pull_request' => 'Create a GitHub pull request. It will not be merged.',
            'delete_storage_file' => 'Delete this file from LAVR Storage. This cannot be undone.',
            default => 'Run the pending tool action '.$confirmation->tool_name.'.',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    public function previewFor(ToolConfirmation $confirmation): ?array
    {
        if ($confirmation->tool_name === 'send_gmail_message') {
            return $this->gmailSendPreview($confirmation);
        }

        return $this->safeArgumentPreview($confirmation);
    }

    private function gmailSendSummary(ToolConfirmation $confirmation): string
    {
        $preview = $this->gmailSendPreview($confirmation);
        $to = implode(', ', $preview['to']);
        $subject = $preview['subject'] !== '' ? $preview['subject'] : '(no subject)';

        return 'Send email to '.$to.' — '.$subject;
    }

    /**
     * @return array{to: list<string>, cc: list<string>, subject: string, body_preview: string}
     */
    private function gmailSendPreview(ToolConfirmation $confirmation): array
    {
        $arguments = is_array($confirmation->arguments_encrypted) ? $confirmation->arguments_encrypted : [];
        $to = $this->stringList($arguments['to'] ?? []);
        $cc = $this->stringList($arguments['cc'] ?? []);
        $subject = trim((string) ($arguments['subject'] ?? ''));
        $body = trim((string) ($arguments['body'] ?? ''));
        $max = max(40, (int) config('google_gmail.body_preview_chars', 200));
        if (mb_strlen($body) > $max) {
            $body = mb_substr($body, 0, $max);
        }

        return [
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'body_preview' => $body,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeArgumentPreview(ToolConfirmation $confirmation): ?array
    {
        $arguments = is_array($confirmation->arguments_encrypted) ? $confirmation->arguments_encrypted : [];
        $keys = match ($confirmation->tool_name) {
            'delete_calendar_event' => ['calendar_id', 'event_id', 'title', 'summary'],
            'create_calendar_event' => ['calendar_id', 'title', 'start', 'end'],
            'update_calendar_event' => ['calendar_id', 'event_id', 'title', 'start', 'end'],
            'create_github_issue' => ['repository', 'title', 'body'],
            'comment_github_issue' => ['repository', 'issue_number', 'body'],
            'create_github_branch' => ['repository', 'branch_name', 'from_ref'],
            'create_github_pull_request' => ['repository', 'title', 'head', 'base', 'body'],
            'delete_storage_file' => ['file_id'],
            default => [],
        };

        if ($keys === []) {
            return null;
        }

        $preview = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $arguments)) {
                continue;
            }

            $value = $arguments[$key];

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $preview[$key] = $value;

                continue;
            }

            $text = trim((string) $value);

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > 200) {
                $text = mb_substr($text, 0, 200);
            }

            $preview[$key] = $text;
        }

        return $preview === [] ? null : $preview;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            $raw = $raw === null || $raw === '' ? [] : [(string) $raw];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $raw,
        )));
    }

    private function resultNote(ToolConfirmation $confirmation, ToolResult $result): string
    {
        $status = $result->success ? 'succeeded' : 'failed';

        return 'Pending tool action '.$confirmation->tool_name.' was confirmed and '.$status.'.';
    }
}
