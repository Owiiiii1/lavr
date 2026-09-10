<?php

namespace Tests\Support;

use App\Enums\ConversationKind;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\DirectoryRelationship;
use App\Models\EmployeeProfile;
use App\Models\IntegrationAccount;
use App\Models\JarvisNotification;
use App\Models\KnowledgeAnalysisRun;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEntityAlias;
use App\Models\KnowledgeEntitySource;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\MeetingArtifact;
use App\Models\MeetingParticipant;
use App\Models\Memory;
use App\Models\MemoryAnalysisRun;
use App\Models\MemoryRevision;
use App\Models\MemorySource;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageTopicRelation;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\Task;
use App\Models\TelegramGroup;
use App\Models\TelegramGroupAnalysisRun;
use App\Models\TelegramGroupKnowledge;
use App\Models\TelegramGroupKnowledgeRevision;
use App\Models\TelegramGroupKnowledgeSource;
use App\Models\TelegramGroupParticipant;
use App\Models\ToolConfirmation;
use App\Models\ToolExecutionLog;
use App\Models\Topic;
use App\Models\User;
use App\Models\UserAiSetting;
use App\Models\UserAssistantProfile;
use App\Models\UserProductivitySetting;
use App\Models\UserProfile;
use App\Models\VoiceSession;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Models\ZoomWebhookEvent;
use App\Services\Users\AccessCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait CleansTemporaryJarvisRecords
{
    private function createTemporaryUser(): User
    {
        $generator = app(AccessCodeGenerator::class);

        return User::query()->create([
            'name' => 'Jarvis Conversation Test User',
            'email' => 'jarvis-test-'.Str::lower(Str::random(12)).'@invalid.local',
            'password' => Hash::make('temporary-test-password'),
            'role' => UserRole::User,
            'access_code' => $generator->generate(),
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);
    }

    private function createTemporaryTelegramIdentity(User $user, string $externalUserId): ChannelIdentity
    {
        return ChannelIdentity::query()->create([
            'user_id' => $user->id,
            'channel' => ChannelIdentity::CHANNEL_TELEGRAM,
            'external_user_id' => $externalUserId,
            'external_chat_id' => $externalUserId,
            'username' => 'jarvis_test',
            'first_name' => 'Jarvis',
            'last_name' => 'Test',
            'linked_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function deleteTemporaryUser(?User $user): void
    {
        if ($user === null) {
            return;
        }

        if (! str_contains($user->email, '@invalid.local') || ! str_starts_with($user->email, 'jarvis-test-')) {
            return;
        }

        if ($user->role === UserRole::Owner) {
            $user->forceFill(['role' => UserRole::User])->save();
        }

        if (Schema::hasTable('zoom_webhook_events') && Schema::hasTable('integration_accounts')) {
            $zoomAccounts = IntegrationAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', 'zoom')
                ->get();
            foreach ($zoomAccounts as $account) {
                $envelope = is_array($account->credentials_encrypted) ? $account->credentials_encrypted : [];
                $accountId = trim((string) ($envelope['account_id'] ?? ''));
                if ($accountId !== '') {
                    ZoomWebhookEvent::query()->where('account_id', $accountId)->delete();
                }
            }
        }
        if (Schema::hasTable('zoom_webhook_events') && Schema::hasTable('meetings')) {
            $meetingIds = Meeting::query()->where('user_id', $user->id)->pluck('id');
            ZoomWebhookEvent::query()->whereIn('meeting_id', $meetingIds)->delete();
        }
        if (Schema::hasTable('tool_confirmations')) {
            ToolConfirmation::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('tool_execution_logs')) {
            ToolExecutionLog::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('integration_accounts')) {
            IntegrationAccount::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('watcher_occurrences')) {
            WatcherOccurrence::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('watchers')) {
            Watcher::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('scheduled_report_runs')) {
            ScheduledReportRun::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('scheduled_reports')) {
            ScheduledReport::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('jarvis_notifications')) {
            JarvisNotification::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('user_productivity_settings')) {
            UserProductivitySetting::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('knowledge_entities')) {
            $eventIds = KnowledgeEvent::query()->where('user_id', $user->id)->pluck('id');
            if ($eventIds->isNotEmpty()) {
                DB::table('knowledge_event_entities')->whereIn('knowledge_event_id', $eventIds)->delete();
            }
            KnowledgeEntitySource::query()->where('user_id', $user->id)->delete();
            KnowledgeEntityAlias::query()->where('user_id', $user->id)->delete();
            KnowledgeRelationship::query()->where('user_id', $user->id)->delete();
            KnowledgeEvent::query()->where('user_id', $user->id)->delete();
            KnowledgeAnalysisRun::query()->where('user_id', $user->id)->delete();
            KnowledgeEntity::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('directory_relationships')) {
            DirectoryRelationship::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('meetings')) {
            $meetingIds = Meeting::query()->where('user_id', $user->id)->pluck('id');
            Meeting::query()->where('user_id', $user->id)->update(['current_analysis_id' => null]);
            MeetingAnalysis::query()->whereIn('meeting_id', $meetingIds)->delete();
            MeetingArtifact::query()->whereIn('meeting_id', $meetingIds)->delete();
            MeetingParticipant::query()->whereIn('meeting_id', $meetingIds)->delete();
            Meeting::query()->where('user_id', $user->id)->delete();
            Storage::disk((string) config('meetings.disk', 'local'))->deleteDirectory('meetings/'.$user->id);
        }
        if (Schema::hasTable('employee_profiles') && Schema::hasTable('people')) {
            EmployeeProfile::query()
                ->whereIn('person_id', Person::query()->where('user_id', $user->id)->select('id'))
                ->update(['manager_person_id' => null]);
        }
        if (Schema::hasTable('people')) {
            Person::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable('organizations')) {
            Organization::query()->where('user_id', $user->id)->delete();
        }
        Project::query()->where('user_id', $user->id)->delete();
        $memoryIds = Memory::query()->where('user_id', $user->id)->pluck('id');
        MemorySource::query()->whereIn('memory_id', $memoryIds)->delete();
        MemoryRevision::query()->whereIn('memory_id', $memoryIds)->delete();
        Memory::query()->where('user_id', $user->id)->delete();
        $topicIds = Topic::query()->where('user_id', $user->id)->pluck('id');
        MessageTopicRelation::query()->whereIn('topic_id', $topicIds)->delete();
        Topic::query()->where('user_id', $user->id)->delete();
        ConversationSummary::query()->where('user_id', $user->id)->delete();
        MemoryAnalysisRun::query()->where('user_id', $user->id)->delete();
        UserProfile::query()->where('user_id', $user->id)->delete();
        UserAssistantProfile::query()->where('user_id', $user->id)->delete();
        Reminder::query()->where('user_id', $user->id)->delete();
        Task::query()->where('user_id', $user->id)->whereNotNull('parent_task_id')->delete();
        Task::query()->where('user_id', $user->id)->delete();
        $conversationIds = Conversation::query()->where('user_id', $user->id)->pluck('id');
        $groupIds = TelegramGroup::query()->whereIn('conversation_id', $conversationIds)->pluck('id');
        $knowledgeIds = TelegramGroupKnowledge::query()->whereIn('telegram_group_id', $groupIds)->pluck('id');
        TelegramGroupKnowledgeRevision::query()->whereIn('knowledge_id', $knowledgeIds)->delete();
        TelegramGroupKnowledgeSource::query()->whereIn('knowledge_id', $knowledgeIds)->delete();
        TelegramGroupKnowledge::query()->whereIn('id', $knowledgeIds)->delete();
        TelegramGroupAnalysisRun::query()->whereIn('telegram_group_id', $groupIds)->delete();
        TelegramGroupParticipant::query()->whereIn('telegram_group_id', $groupIds)->delete();
        if (Schema::hasTable('message_attachments')) {
            MessageAttachment::query()->where('user_id', $user->id)->delete();
        }
        Message::query()->whereIn('telegram_group_id', $groupIds)->delete();
        TelegramGroup::query()->whereIn('id', $groupIds)->delete();
        Message::query()->where('user_id', $user->id)->delete();
        if (Schema::hasTable('voice_sessions')) {
            VoiceSession::query()->where('user_id', $user->id)->delete();
        }
        UserAiSetting::query()->where('user_id', $user->id)->delete();
        ChannelIdentity::query()->where('user_id', $user->id)->update(['active_conversation_id' => null]);
        Conversation::query()->where('user_id', $user->id)->delete();
        ChannelIdentity::query()->where('user_id', $user->id)->delete();
        User::query()->whereKey($user->id)->delete();
    }

    private function deleteTelegramIdentity(string $externalUserId): void
    {
        if (! preg_match('/^(9\d{5})$/', $externalUserId)) {
            return;
        }

        $identity = ChannelIdentity::findTelegramByExternalUserId($externalUserId);

        if ($identity === null) {
            return;
        }

        $identity->forceFill(['active_conversation_id' => null])->save();
        $identity->delete();
    }

    private function isTestTelegramChatId(string $telegramChatId): bool
    {
        return (bool) preg_match('/^-91\d{6,12}$/', $telegramChatId);
    }

    private function deleteTestTelegramGroup(string $telegramChatId): void
    {
        if (! $this->isTestTelegramChatId($telegramChatId)) {
            return;
        }

        $group = TelegramGroup::query()->where('telegram_chat_id', $telegramChatId)->first();

        if ($group === null) {
            return;
        }

        $conversationId = (int) $group->conversation_id;
        $group->projects()->detach();
        TelegramGroupKnowledgeRevision::query()
            ->whereIn('knowledge_id', TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->select('id'))
            ->delete();
        TelegramGroupKnowledgeSource::query()
            ->whereIn('knowledge_id', TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->select('id'))
            ->delete();
        TelegramGroupKnowledge::query()->where('telegram_group_id', $group->id)->delete();
        TelegramGroupAnalysisRun::query()->where('telegram_group_id', $group->id)->delete();
        TelegramGroupParticipant::query()->where('telegram_group_id', $group->id)->delete();
        Message::query()->where('conversation_id', $conversationId)->delete();
        $group->delete();
        Conversation::query()
            ->whereKey($conversationId)
            ->where('kind', ConversationKind::Group)
            ->delete();
    }
}
