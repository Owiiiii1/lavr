<?php

namespace App\Services\Tools\Synthesis;

use App\Enums\SynthesisType;
use App\Enums\ToolOperationClass;
use App\Models\Person;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Commitments\CommitmentService;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Meetings\MeetingService;
use App\Services\OperationalControl\ProactiveProposalService;
use App\Services\Sources\CrossSourceStatusService;
use App\Services\Synthesis\CrossSourceSynthesisService;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Synthesis\Exceptions\SynthesisException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetPersonStatusTool implements JarvisTool
{
    public const NAME = 'get_person_status';

    public function __construct(
        private readonly CrossSourceSynthesisService $synthesis,
        private readonly DirectoryService $directory,
        private readonly CommitmentService $commitments,
        private readonly MeetingService $meetings,
        private readonly CrossSourceStatusService $crossSource,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Person status: canonical People, then active first-class commitments, projects, recent meetings, then Knowledge fallback. Foreign ids fail.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'person_id' => ['type' => 'INTEGER', 'description' => 'Canonical people.id'],
                    'entity_id' => ['type' => 'INTEGER', 'description' => 'Owned knowledge person entity id.'],
                    'person' => ['type' => 'STRING', 'description' => 'Person name in People or the user’s Knowledge graph.'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::KNOWLEDGE, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && ($context->user->canUseCapability(UserCapability::PEOPLE)
                || $context->user->canUseCapability(UserCapability::KNOWLEDGE));
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $personId = isset($call->arguments['person_id']) ? (int) $call->arguments['person_id'] : 0;
        $entityId = isset($call->arguments['entity_id']) ? (int) $call->arguments['entity_id'] : 0;
        $name = trim((string) ($call->arguments['person'] ?? $call->arguments['name'] ?? ''));

        if ($personId < 1 && $entityId < 1 && $name === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        if ($context->user->canUseCapability(UserCapability::PEOPLE)) {
            try {
                $structured = $this->directory->personStatus(
                    $context->user,
                    $personId > 0 ? $personId : null,
                    $name !== '' ? $name : null,
                );
            } catch (DirectoryException) {
                $structured = null;
            }

            if (is_array($structured)) {
                $knowledge = null;
                $personIdResolved = (int) ($structured['id'] ?? 0);
                $activeCommitments = [];
                $recentMeetings = [];

                if ($personIdResolved > 0 && $context->user->canUseCapability(UserCapability::COMMITMENTS)) {
                    $person = Person::query()->where('user_id', $context->user->id)->whereKey($personIdResolved)->first();
                    if ($person !== null) {
                        $activeCommitments = $this->commitments->forPerson($context->user, $person)
                            ->map(fn ($commitment): array => $this->commitments->serializeSummary($commitment))
                            ->values()
                            ->all();
                    }
                }

                if ($personIdResolved > 0 && $context->user->canUseCapability(UserCapability::MEETINGS)) {
                    $recentMeetings = $this->meetings->list($context->user)
                        ->filter(fn ($meeting): bool => $meeting->participants->contains(fn ($participant): bool => (int) $participant->person_id === $personIdResolved))
                        ->take(5)
                        ->map(fn ($meeting): array => $this->meetings->serializeSummary($meeting))
                        ->values()
                        ->all();
                }

                if ($context->user->canUseCapability(UserCapability::KNOWLEDGE) && ($entityId > 0 || $name !== '') && $activeCommitments === []) {
                    try {
                        $knowledge = $this->synthesis->synthesize(new SynthesisScope(
                            user: $context->user,
                            type: SynthesisType::PersonStatus,
                            entityId: $entityId > 0 ? $entityId : null,
                            personName: $name !== '' ? $name : null,
                        ))->toArray();
                    } catch (SynthesisException) {
                        $knowledge = null;
                    }
                }

                $sourceFacts = [];
                if ($personIdResolved > 0) {
                    $personModel = Person::query()->where('user_id', $context->user->id)->whereKey($personIdResolved)->first();
                    if ($personModel !== null) {
                        $sourceFacts = $this->crossSource->person($context->user, $personModel);
                    }
                }

                return ToolResult::success($call->id, $this->name(), [
                    'success' => true,
                    ...$structured,
                    'commitments' => $activeCommitments,
                    'recent_meetings' => $recentMeetings,
                    'proposals' => $personIdResolved > 0
                        ? array_map(
                            fn ($proposal): array => [
                                'id' => $proposal->id,
                                'title' => $proposal->title,
                                'href' => '/lavr/proactive/'.$proposal->id,
                            ],
                            app(ProactiveProposalService::class)->pendingForPerson($context->user, $personIdResolved),
                        )
                        : [],
                    'knowledge' => $knowledge,
                    ...$sourceFacts,
                ]);
            }
        }

        if ($entityId < 1 && $name === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'not_found',
            ]);
        }

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: SynthesisType::PersonStatus,
                entityId: $entityId > 0 ? $entityId : null,
                personName: $name !== '' ? $name : null,
            ));
        } catch (SynthesisException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$result->toArray(),
        ]);
    }
}
