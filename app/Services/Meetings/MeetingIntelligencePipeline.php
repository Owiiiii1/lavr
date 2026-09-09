<?php

namespace App\Services\Meetings;

use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\MeetingArtifact;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Meetings\Exceptions\MeetingIntelligenceException;
use App\Services\Memory\StructuredJsonParser;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MeetingIntelligencePipeline
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly AiConfigurationResolver $resolver,
        private readonly TranscriptChunker $chunker,
        private readonly MeetingIntelligenceValidator $validator,
        private readonly MeetingIntelligenceMerger $merger,
        private readonly MeetingIntelligencePrompt $prompts,
    ) {}

    public function analyze(User $user, Meeting $meeting, ?MeetingArtifact $artifact = null): MeetingAnalysis
    {
        $artifact ??= $meeting->artifacts()->latest('id')->first();

        if ($artifact === null || blank($artifact->normalized_text)) {
            throw new MeetingIntelligenceException('missing_transcript', 'No normalized transcript is available.');
        }

        $version = (int) $meeting->analyses()->max('version') + 1;
        $analysis = MeetingAnalysis::query()->create([
            'meeting_id' => $meeting->id,
            'version' => $version,
            'prompt_version' => MeetingConfig::promptVersion(),
            'status' => MeetingAnalysisStatus::Processing,
        ]);

        $meeting->forceFill([
            'analysis_status' => MeetingAnalysisStatus::Processing,
            'current_analysis_id' => $analysis->id,
        ])->save();

        $chunks = $this->chunker->chunk((string) $artifact->normalized_text);
        $knownPeople = Person::query()
            ->where('user_id', $user->id)
            ->orderBy('display_name')
            ->limit(80)
            ->pluck('display_name')
            ->all();
        $knownProjects = Project::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->limit(40)
            ->pluck('name')
            ->all();

        try {
            $partials = [];
            $provider = null;
            $model = null;

            foreach ($chunks as $index => $chunk) {
                $result = $this->analyzeChunk(
                    $meeting,
                    $chunk,
                    $knownPeople,
                    $knownProjects,
                    $index + 1,
                    count($chunks),
                );
                $partials[] = $result['payload'];
                $provider = $result['provider'];
                $model = $result['model'];
            }

            $merged = $this->merger->merge($partials);
            $validated = $this->validator->validate($merged, $meeting);
            $summaryText = $this->summaryText($validated);

            $analysis->forceFill([
                'provider' => $provider,
                'model' => $model,
                'status' => MeetingAnalysisStatus::Completed,
                'result_json' => $validated,
                'summary' => $summaryText,
                'error_class' => null,
                'error_message' => null,
                'processed_at' => now(),
                'metadata' => [
                    'chunk_count' => count($chunks),
                    'checksum' => $artifact->checksum_sha256,
                ],
            ])->save();

            $meeting->forceFill([
                'analysis_status' => MeetingAnalysisStatus::Completed,
                'status' => $meeting->status === MeetingStatus::Archived ? MeetingStatus::Archived : MeetingStatus::Ready,
                'summary' => $summaryText,
                'current_analysis_id' => $analysis->id,
            ])->save();

            Log::info('meeting intelligence completed', [
                'meeting_id' => $meeting->id,
                'analysis_id' => $analysis->id,
                'provider' => $provider,
                'model' => $model,
                'chunk_count' => count($chunks),
                'checksum' => $artifact->checksum_sha256,
            ]);

            return $analysis->fresh() ?? $analysis;
        } catch (Throwable $exception) {
            $errorClass = class_basename($exception);
            $safe = $exception instanceof MeetingIntelligenceException
                ? $exception->error
                : 'analysis_failed';

            $analysis->forceFill([
                'status' => MeetingAnalysisStatus::Failed,
                'error_class' => $errorClass,
                'error_message' => $safe,
                'processed_at' => now(),
            ])->save();

            $meeting->forceFill([
                'analysis_status' => MeetingAnalysisStatus::Failed,
                'current_analysis_id' => $analysis->id,
            ])->save();

            Log::warning('meeting intelligence failed', [
                'meeting_id' => $meeting->id,
                'analysis_id' => $analysis->id,
                'error_class' => $errorClass,
                'error' => $safe,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $knownPeople
     * @param  list<string>  $knownProjects
     * @return array{payload: array<string, mixed>, provider: string, model: string}
     */
    private function analyzeChunk(
        Meeting $meeting,
        string $chunk,
        array $knownPeople,
        array $knownProjects,
        int $index,
        int $count,
    ): array {
        $configuration = $this->resolver->resolveAnalysis();
        $attempts = MeetingConfig::chunkAiRetries();
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->gateway->chat($configuration, new AiChatRequest(
                    model: (string) $configuration->model,
                    systemPrompt: $this->prompts->systemPrompt(),
                    messages: [new AiChatMessage('user', $this->prompts->userPrompt(
                        $chunk,
                        $meeting->title,
                        $meeting->started_at?->toIso8601String(),
                        $knownPeople,
                        $knownProjects,
                        $index,
                        $count,
                    ))],
                    parameters: [
                        'temperature' => 0.1,
                        'max_tokens' => 4000,
                    ],
                ));

                $parsed = StructuredJsonParser::objectFromText((string) $response->text);
                $validated = $this->validator->validate($parsed, $meeting);

                Log::info('meeting intelligence chunk analyzed', [
                    'meeting_id' => $meeting->id,
                    'chunk_index' => $index,
                    'chunk_count' => $count,
                    'attempt' => $attempt,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'outcome' => 'ok',
                ]);

                return [
                    'payload' => $validated,
                    'provider' => (string) $response->provider,
                    'model' => (string) $response->model,
                ];
            } catch (Throwable $exception) {
                $last = $exception;
                Log::warning('meeting intelligence chunk retry', [
                    'meeting_id' => $meeting->id,
                    'chunk_index' => $index,
                    'attempt' => $attempt,
                    'outcome' => 'invalid_output',
                    'error_class' => class_basename($exception),
                ]);
            }
        }

        throw $last instanceof MeetingIntelligenceException
            ? $last
            : new MeetingIntelligenceException('invalid_output', 'Analysis AI did not return valid Meeting Intelligence JSON.');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summaryText(array $result): string
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        $executive = trim((string) ($summary['executive'] ?? ''));

        if ($executive !== '') {
            return mb_substr($executive, 0, 2000);
        }

        $outcomes = is_array($summary['outcomes'] ?? null) ? $summary['outcomes'] : [];

        return mb_substr(implode(' ', array_slice($outcomes, 0, 3)), 0, 2000);
    }
}
