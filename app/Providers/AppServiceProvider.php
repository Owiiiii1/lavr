<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\TelegramGroup;
use App\Policies\ProjectPolicy;
use App\Policies\TelegramGroupPolicy;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\ProviderAiChatGateway;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\Google\GoogleGmailService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Integrations\Providers\ElevenLabsIntegrationProvider;
use App\Services\Integrations\Providers\GitHubIntegrationProvider;
use App\Services\Integrations\Providers\GoogleIntegrationProvider;
use App\Services\Integrations\Providers\TelegramIntegrationProvider;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationInbox;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Productivity\ProactiveDispatchService;
use App\Services\Productivity\ProactivePolicy;
use App\Services\Productivity\ProactiveTriggerDetector;
use App\Services\Productivity\ProductivityBriefAiSynthesizer;
use App\Services\Productivity\ProductivityBriefCollector;
use App\Services\Productivity\ProductivityBriefRenderer;
use App\Services\Productivity\ProductivityBriefService;
use App\Services\Productivity\SynthesizesProductivityBrief;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use App\Services\Reminders\Contracts\SendsWebPush;
use App\Services\Reminders\MinishlinkWebPushSender;
use App\Services\Reminders\PushPayloadBuilder;
use App\Services\Reminders\PushSubscriptionService;
use App\Services\Reminders\TelegramReminderSender;
use App\Services\Reports\ScheduledReportCollector;
use App\Services\Reports\ScheduledReportComposer;
use App\Services\Synthesis\CrossSourceSynthesisService;
use App\Services\Telegram\Contracts\CompletesTelegramUserTurn;
use App\Services\Telegram\Contracts\LooksUpTelegramInbound;
use App\Services\Telegram\SpokenTextNormalizer;
use App\Services\Telegram\TelegramBotManager;
use App\Services\Telegram\TelegramChatKeyboard;
use App\Services\Telegram\TelegramConversationTurnBridge;
use App\Services\Telegram\TelegramInboundLookup;
use App\Services\Telegram\TelegramReplyDeliveryService;
use App\Services\Telegram\TelegramVoiceInboundService;
use App\Services\Telegram\TelegramVoiceSuitabilityPolicy;
use App\Services\Tools\CancelReminderTool;
use App\Services\Tools\CancelTaskTool;
use App\Services\Tools\CancelToolActionTool;
use App\Services\Tools\CompleteAssistantOnboardingTool;
use App\Services\Tools\CompleteReminderTool;
use App\Services\Tools\CompleteTaskTool;
use App\Services\Tools\ConfirmToolActionTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\CreateSubtaskTool;
use App\Services\Tools\CreateTaskTool;
use App\Services\Tools\GetAssistantProfileTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\GetTaskTool;
use App\Services\Tools\GetTelegramResponseModeTool;
use App\Services\Tools\GitHub\CommentGitHubIssueTool;
use App\Services\Tools\GitHub\CompareGitHubRefsTool;
use App\Services\Tools\GitHub\CreateGitHubBranchTool;
use App\Services\Tools\GitHub\CreateGitHubIssueTool;
use App\Services\Tools\GitHub\CreateGitHubPullRequestTool;
use App\Services\Tools\GitHub\GetGitHubCommitTool;
use App\Services\Tools\GitHub\GetGitHubFileTool;
use App\Services\Tools\GitHub\GetGitHubIssueTool;
use App\Services\Tools\GitHub\GetGitHubPullRequestDiffTool;
use App\Services\Tools\GitHub\GetGitHubPullRequestTool;
use App\Services\Tools\GitHub\GetGitHubRepositoryTool;
use App\Services\Tools\GitHub\GetGitHubWorkflowRunTool;
use App\Services\Tools\GitHub\ListGitHubBranchesTool;
use App\Services\Tools\GitHub\ListGitHubCommitsTool;
use App\Services\Tools\GitHub\ListGitHubIssuesTool;
use App\Services\Tools\GitHub\ListGitHubPullRequestsTool;
use App\Services\Tools\GitHub\ListGitHubRepositoriesTool;
use App\Services\Tools\GitHub\ListGitHubWorkflowRunsTool;
use App\Services\Tools\GitHub\SearchGitHubCodeTool;
use App\Services\Tools\Google\CreateCalendarEventTool;
use App\Services\Tools\Google\CreateGmailDraftTool;
use App\Services\Tools\Google\DeleteCalendarEventTool;
use App\Services\Tools\Google\GetCalendarEventTool;
use App\Services\Tools\Google\GetGmailMessageTool;
use App\Services\Tools\Google\GetGmailThreadTool;
use App\Services\Tools\Google\GoogleCalendarFreebusyTool;
use App\Services\Tools\Google\ListCalendarEventsTool;
use App\Services\Tools\Google\ListGmailLabelsTool;
use App\Services\Tools\Google\ListGmailMessagesTool;
use App\Services\Tools\Google\ListGoogleCalendarsTool;
use App\Services\Tools\Google\ModifyGmailLabelsTool;
use App\Services\Tools\Google\SearchCalendarEventsTool;
use App\Services\Tools\Google\SearchGmailTool;
use App\Services\Tools\Google\SendGmailMessageTool;
use App\Services\Tools\Google\UpdateCalendarEventTool;
use App\Services\Tools\Knowledge\AddKnowledgeNoteTool;
use App\Services\Tools\Knowledge\GetEntityRelationshipsTool;
use App\Services\Tools\Knowledge\GetEntityTimelineTool;
use App\Services\Tools\Knowledge\GetEntityTool;
use App\Services\Tools\Knowledge\LinkEntitiesTool;
use App\Services\Tools\Knowledge\ListRelatedEntitiesTool;
use App\Services\Tools\Knowledge\RememberEntityTool;
use App\Services\Tools\Knowledge\SearchKnowledgeTool;
use App\Services\Tools\LinkTaskReminderTool;
use App\Services\Tools\ListRemindersTool;
use App\Services\Tools\ListTasksTool;
use App\Services\Tools\Reports\CancelScheduledReportTool;
use App\Services\Tools\Reports\CreateScheduledReportTool;
use App\Services\Tools\Reports\GetScheduledReportTool;
use App\Services\Tools\Reports\ListScheduledReportsTool;
use App\Services\Tools\Reports\PauseScheduledReportTool;
use App\Services\Tools\Reports\ResumeScheduledReportTool;
use App\Services\Tools\Reports\UpdateScheduledReportTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\SearchGroupKnowledgeTool;
use App\Services\Tools\SetTelegramResponseModeTool;
use App\Services\Tools\SnoozeReminderTool;
use App\Services\Tools\StartTaskTool;
use App\Services\Tools\Storage\DeleteStorageFileTool;
use App\Services\Tools\Storage\GetStorageFileTool;
use App\Services\Tools\Storage\ListStorageFilesTool;
use App\Services\Tools\Storage\ReadStorageFileChunksTool;
use App\Services\Tools\Storage\SearchStorageFileContentsTool;
use App\Services\Tools\Storage\SearchStorageFilesTool;
use App\Services\Tools\Synthesis\GetPersonStatusTool;
use App\Services\Tools\Synthesis\GetProjectStatusTool;
use App\Services\Tools\Synthesis\GetSynthesisTool;
use App\Services\Tools\Synthesis\ListCommitmentsTool;
use App\Services\Tools\Synthesis\ListWaitingForTool;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\UpdateAssistantProfileTool;
use App\Services\Tools\UpdateReminderTool;
use App\Services\Tools\UpdateTaskTool;
use App\Services\Tools\Watchers\CancelWatcherTool;
use App\Services\Tools\Watchers\CreateWatcherTool;
use App\Services\Tools\Watchers\GetWatcherTool;
use App\Services\Tools\Watchers\ListWatcherOccurrencesTool;
use App\Services\Tools\Watchers\ListWatchersTool;
use App\Services\Tools\Watchers\PauseWatcherTool;
use App\Services\Tools\Watchers\ResumeWatcherTool;
use App\Services\Tools\Watchers\RunWatcherNowTool;
use App\Services\Tools\Watchers\UpdateWatcherTool;
use App\Services\Tools\WebResearch\FetchWebPageTool;
use App\Services\Tools\WebResearch\SearchWebTool;
use App\Services\Users\ResolvesTelegramResponseMode;
use App\Services\Users\UserChannelPreferenceService;
use App\Services\Voice\Contracts\RecordsVoiceMetrics;
use App\Services\Voice\Contracts\ResolvesTelegramTtsSpeed;
use App\Services\Voice\Contracts\ResolvesUserVoice;
use App\Services\Voice\Contracts\SpeechSynthesizer;
use App\Services\Voice\Contracts\SpeechToTextProvider;
use App\Services\Voice\Contracts\StoresEphemeralVoiceAudio;
use App\Services\Voice\Contracts\TextToSpeechProvider;
use App\Services\Voice\Contracts\TranscribesSpeech;
use App\Services\Voice\SpeechToTextManager;
use App\Services\Voice\TextToSpeechManager;
use App\Services\Voice\VoiceMetricsLogger;
use App\Services\Voice\VoiceSettingsService;
use App\Services\Voice\VoiceTempAudioStore;
use App\Services\Watchers\Adapters\CalendarWatcherSource;
use App\Services\Watchers\Adapters\GitHubWatcherSource;
use App\Services\Watchers\Adapters\GmailWatcherSource;
use App\Services\Watchers\Adapters\KnowledgeWatcherSource;
use App\Services\Watchers\Adapters\ReminderWatcherSource;
use App\Services\Watchers\Adapters\TaskWatcherSource;
use App\Services\Watchers\Adapters\TimeWatcherSource;
use App\Services\Watchers\Clients\LiveCalendarWatcherClient;
use App\Services\Watchers\Clients\LiveGitHubWatcherClient;
use App\Services\Watchers\Clients\LiveGmailWatcherClient;
use App\Services\Watchers\Contracts\CalendarWatcherClient;
use App\Services\Watchers\Contracts\GitHubWatcherClient;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\WatcherSourceRegistry;
use App\Services\WebResearch\Contracts\WebSearchProvider;
use App\Services\WebResearch\WebSearchManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ResolvesTelegramResponseMode::class, UserChannelPreferenceService::class);
        $this->app->bind(ResolvesUserVoice::class, VoiceSettingsService::class);
        $this->app->bind(ResolvesTelegramTtsSpeed::class, VoiceSettingsService::class);
        $this->app->bind(SpeechSynthesizer::class, TextToSpeechManager::class);
        $this->app->bind(StoresEphemeralVoiceAudio::class, VoiceTempAudioStore::class);
        $this->app->bind(RecordsVoiceMetrics::class, VoiceMetricsLogger::class);
        $this->app->bind(TranscribesSpeech::class, SpeechToTextManager::class);
        $this->app->bind(LooksUpTelegramInbound::class, TelegramInboundLookup::class);
        $this->app->bind(CompletesTelegramUserTurn::class, TelegramConversationTurnBridge::class);

        $this->app->singleton(TelegramVoiceInboundService::class, function ($app): TelegramVoiceInboundService {
            return new TelegramVoiceInboundService(
                $app->make(LooksUpTelegramInbound::class),
                $app->make(TranscribesSpeech::class),
                $app->make(StoresEphemeralVoiceAudio::class),
                $app->make(CompletesTelegramUserTurn::class),
                $app->make(RecordsVoiceMetrics::class),
                max(1024, (int) config('voice.telegram_voice.max_inbound_bytes', 2_000_000)),
                max(1, (int) config('voice.telegram_voice.max_inbound_seconds', 30)),
                max(1024, (int) config('voice.telegram_voice.api_download_max_bytes', 20_000_000)),
            );
        });

        $this->app->singleton(TelegramReplyDeliveryService::class, function ($app): TelegramReplyDeliveryService {
            return new TelegramReplyDeliveryService(
                $app->make(ResolvesTelegramResponseMode::class),
                $app->make(ResolvesUserVoice::class),
                $app->make(ResolvesTelegramTtsSpeed::class),
                $app->make(SpeechSynthesizer::class),
                $app->make(StoresEphemeralVoiceAudio::class),
                $app->make(TelegramVoiceSuitabilityPolicy::class),
                $app->make(TelegramChatKeyboard::class),
                $app->make(RecordsVoiceMetrics::class),
                $app->make(TelegramBotManager::class),
                max(1024, (int) config('voice.max_audio_chunk_bytes', 2_000_000)),
            );
        });

        $this->app->singleton(TelegramVoiceSuitabilityPolicy::class, function ($app): TelegramVoiceSuitabilityPolicy {
            return new TelegramVoiceSuitabilityPolicy(
                $app->make(SpokenTextNormalizer::class),
                max(200, (int) config('voice.telegram_voice.max_spoken_chars', 2000)),
                max(50, (int) config('voice.telegram_voice.max_code_fence_chars', 400)),
                max(2, (int) config('voice.telegram_voice.max_table_rows', 4)),
            );
        });

        $this->app->singleton(AiChatGateway::class, ProviderAiChatGateway::class);

        $this->app->bind(WebSearchProvider::class, function ($app): WebSearchProvider {
            return $app->make(WebSearchManager::class)->activeProvider();
        });

        $this->app->bind(SpeechToTextProvider::class, function ($app): SpeechToTextProvider {
            return $app->make(SpeechToTextManager::class)->activeProvider();
        });

        $this->app->bind(TextToSpeechProvider::class, function ($app): TextToSpeechProvider {
            return $app->make(TextToSpeechManager::class)->activeProvider();
        });

        $this->app->bind(SendsReminderTelegram::class, TelegramReminderSender::class);
        $this->app->bind(SendsWebPush::class, MinishlinkWebPushSender::class);
        $this->app->bind(SynthesizesProductivityBrief::class, ProductivityBriefAiSynthesizer::class);
        $this->app->bind(GmailWatcherClient::class, LiveGmailWatcherClient::class);
        $this->app->bind(CalendarWatcherClient::class, LiveCalendarWatcherClient::class);
        $this->app->bind(GitHubWatcherClient::class, LiveGitHubWatcherClient::class);

        $this->app->singleton(WatcherSourceRegistry::class, function ($app): WatcherSourceRegistry {
            return new WatcherSourceRegistry([
                $app->make(KnowledgeWatcherSource::class),
                $app->make(TaskWatcherSource::class),
                $app->make(ReminderWatcherSource::class),
                $app->make(TimeWatcherSource::class),
                $app->make(GmailWatcherSource::class),
                $app->make(CalendarWatcherSource::class),
                $app->make(GitHubWatcherSource::class),
            ]);
        });

        $this->app->singleton(JarvisNotificationService::class, function ($app): JarvisNotificationService {
            return new JarvisNotificationService(
                new NotificationInbox,
                new NotificationUrlPolicy,
                new PushPayloadBuilder,
                $app->make(PushSubscriptionService::class),
                $app->make(SendsWebPush::class),
            );
        });

        $this->app->singleton(ProductivityBriefCollector::class, function ($app): ProductivityBriefCollector {
            return new ProductivityBriefCollector(
                $app->make(CrossSourceSynthesisService::class),
            );
        });

        $this->app->singleton(ProductivityBriefService::class, function ($app): ProductivityBriefService {
            return new ProductivityBriefService(
                $app->make(ProductivityBriefCollector::class),
                new ProductivityBriefRenderer,
                $app->make(SynthesizesProductivityBrief::class),
            );
        });

        $this->app->singleton(ScheduledReportCollector::class, function ($app): ScheduledReportCollector {
            return new ScheduledReportCollector(
                $app->make(ProductivityBriefCollector::class),
                $app->make(IntegrationAccountService::class),
                $app->make(GoogleCalendarService::class),
                $app->make(GoogleGmailService::class),
            );
        });

        $this->app->singleton(ScheduledReportComposer::class, function ($app): ScheduledReportComposer {
            return new ScheduledReportComposer(
                $app->make(SynthesizesProductivityBrief::class),
            );
        });

        $this->app->singleton(ProactiveDispatchService::class, function ($app): ProactiveDispatchService {
            return new ProactiveDispatchService(
                new ProactivePolicy,
                new ProactiveTriggerDetector,
                $app->make(JarvisNotificationService::class),
                new NotificationUrlPolicy,
                $app->make(SynthesizesProductivityBrief::class),
                $app->make(CrossSourceSynthesisService::class),
            );
        });

        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            return new ToolRegistry([
                $app->make(CreateReminderTool::class),
                $app->make(ListRemindersTool::class),
                $app->make(UpdateReminderTool::class),
                $app->make(SnoozeReminderTool::class),
                $app->make(CompleteReminderTool::class),
                $app->make(CancelReminderTool::class),
                $app->make(CreateTaskTool::class),
                $app->make(ListTasksTool::class),
                $app->make(GetTaskTool::class),
                $app->make(UpdateTaskTool::class),
                $app->make(StartTaskTool::class),
                $app->make(CompleteTaskTool::class),
                $app->make(CancelTaskTool::class),
                $app->make(CreateSubtaskTool::class),
                $app->make(LinkTaskReminderTool::class),
                $app->make(GetAssistantProfileTool::class),
                $app->make(UpdateAssistantProfileTool::class),
                $app->make(CompleteAssistantOnboardingTool::class),
                $app->make(GetTelegramResponseModeTool::class),
                $app->make(SetTelegramResponseModeTool::class),
                $app->make(SearchConversationHistoryTool::class),
                $app->make(SearchKnowledgeTool::class),
                $app->make(GetEntityTool::class),
                $app->make(GetEntityTimelineTool::class),
                $app->make(GetEntityRelationshipsTool::class),
                $app->make(ListRelatedEntitiesTool::class),
                $app->make(RememberEntityTool::class),
                $app->make(LinkEntitiesTool::class),
                $app->make(AddKnowledgeNoteTool::class),
                $app->make(GetProjectContextTool::class),
                $app->make(GetProjectStatusTool::class),
                $app->make(GetSynthesisTool::class),
                $app->make(GetPersonStatusTool::class),
                $app->make(ListWaitingForTool::class),
                $app->make(ListCommitmentsTool::class),
                $app->make(SearchGroupKnowledgeTool::class),
                $app->make(CreateWatcherTool::class),
                $app->make(ListWatchersTool::class),
                $app->make(GetWatcherTool::class),
                $app->make(UpdateWatcherTool::class),
                $app->make(PauseWatcherTool::class),
                $app->make(ResumeWatcherTool::class),
                $app->make(CancelWatcherTool::class),
                $app->make(ListWatcherOccurrencesTool::class),
                $app->make(RunWatcherNowTool::class),
                $app->make(CreateScheduledReportTool::class),
                $app->make(ListScheduledReportsTool::class),
                $app->make(GetScheduledReportTool::class),
                $app->make(UpdateScheduledReportTool::class),
                $app->make(PauseScheduledReportTool::class),
                $app->make(ResumeScheduledReportTool::class),
                $app->make(CancelScheduledReportTool::class),
                $app->make(ListGoogleCalendarsTool::class),
                $app->make(ListCalendarEventsTool::class),
                $app->make(GetCalendarEventTool::class),
                $app->make(SearchCalendarEventsTool::class),
                $app->make(GoogleCalendarFreebusyTool::class),
                $app->make(CreateCalendarEventTool::class),
                $app->make(UpdateCalendarEventTool::class),
                $app->make(DeleteCalendarEventTool::class),
                $app->make(SearchGmailTool::class),
                $app->make(ListGmailMessagesTool::class),
                $app->make(GetGmailMessageTool::class),
                $app->make(GetGmailThreadTool::class),
                $app->make(ListGmailLabelsTool::class),
                $app->make(CreateGmailDraftTool::class),
                $app->make(SendGmailMessageTool::class),
                $app->make(ModifyGmailLabelsTool::class),
                $app->make(ListGitHubRepositoriesTool::class),
                $app->make(GetGitHubRepositoryTool::class),
                $app->make(ListGitHubBranchesTool::class),
                $app->make(ListGitHubCommitsTool::class),
                $app->make(GetGitHubCommitTool::class),
                $app->make(CompareGitHubRefsTool::class),
                $app->make(GetGitHubFileTool::class),
                $app->make(SearchGitHubCodeTool::class),
                $app->make(ListGitHubIssuesTool::class),
                $app->make(GetGitHubIssueTool::class),
                $app->make(ListGitHubPullRequestsTool::class),
                $app->make(GetGitHubPullRequestTool::class),
                $app->make(GetGitHubPullRequestDiffTool::class),
                $app->make(ListGitHubWorkflowRunsTool::class),
                $app->make(GetGitHubWorkflowRunTool::class),
                $app->make(CreateGitHubIssueTool::class),
                $app->make(CommentGitHubIssueTool::class),
                $app->make(CreateGitHubBranchTool::class),
                $app->make(CreateGitHubPullRequestTool::class),
                $app->make(ListStorageFilesTool::class),
                $app->make(SearchStorageFilesTool::class),
                $app->make(GetStorageFileTool::class),
                $app->make(SearchStorageFileContentsTool::class),
                $app->make(ReadStorageFileChunksTool::class),
                $app->make(DeleteStorageFileTool::class),
                $app->make(SearchWebTool::class),
                $app->make(FetchWebPageTool::class),
                $app->make(ConfirmToolActionTool::class),
                $app->make(CancelToolActionTool::class),
            ]);
        });

        $this->app->singleton(IntegrationRegistry::class, function ($app): IntegrationRegistry {
            return new IntegrationRegistry([
                $app->make(GoogleIntegrationProvider::class),
                $app->make(TelegramIntegrationProvider::class),
                $app->make(ElevenLabsIntegrationProvider::class),
                $app->make(GitHubIntegrationProvider::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(TelegramGroup::class, TelegramGroupPolicy::class);

        RateLimiter::for('telegram-webapp', function (Request $request) {
            $perMinute = max(5, (int) config('telegram.webapp.rate_limit_per_minute', 20));

            return Limit::perMinute($perMinute)->by($request->ip());
        });
    }
}
