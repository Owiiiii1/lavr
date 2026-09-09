<?php

namespace App\Services\Watchers;

use App\Models\User;
use App\Models\Watcher;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatMessage;
use App\Services\Ai\DTO\AiChatRequest;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\DTO\WatcherObservation;
use Throwable;

final class WatcherAnalysisService
{
    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly AiChatGateway $gateway,
        private readonly GmailWatcherClient $gmail,
    ) {}

    public function summarize(User $user, Watcher $watcher, WatcherObservation $observation): string
    {
        $snippet = $observation->title;
        $source = is_array($watcher->source_config) ? $watcher->source_config : [];

        if ($observation->sourceType === 'gmail') {
            try {
                $detail = $this->gmail->snippet($user, $source, $observation->sourceId);
                $snippet = trim((string) ($detail['subject'] ?? '').' '.(string) ($detail['snippet'] ?? ''));
            } catch (Throwable) {
            }
        }

        $snippet = WatcherSupport::summary($snippet);
        if ($snippet === '') {
            $snippet = $observation->title;
        }

        try {
            $configuration = $this->resolver->resolveAnalysis();
            $response = $this->gateway->chat($configuration, new AiChatRequest(
                model: (string) $configuration->model,
                systemPrompt: 'You are LAVR analysis. Write a short actionable brief for the owner. No chain of thought. No secrets. Max 80 words.',
                messages: [new AiChatMessage('user', 'Watcher: '.$watcher->name."\nObservation: ".$snippet)],
                parameters: [
                    'temperature' => 0.2,
                    'max_tokens' => 220,
                ],
            ));
            $text = WatcherSupport::summary((string) $response->text);
            if ($text !== '') {
                return $text;
            }
        } catch (Throwable) {
        }

        unset($user);

        return 'Watcher «'.$watcher->name.'» matched: '.$snippet;
    }
}
