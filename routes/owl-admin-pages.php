<?php

use App\Http\Controllers\CabinetChatController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CommitmentController;
use App\Http\Controllers\Jarvis\JarvisAttachmentController;
use App\Http\Controllers\Jarvis\JarvisConfirmationController;
use App\Http\Controllers\Jarvis\JarvisKnowledgeController;
use App\Http\Controllers\Jarvis\JarvisNotificationController;
use App\Http\Controllers\Jarvis\JarvisProductivitySettingsController;
use App\Http\Controllers\Jarvis\JarvisPushSubscriptionController;
use App\Http\Controllers\Jarvis\JarvisRealtimeVoiceController;
use App\Http\Controllers\Jarvis\JarvisReminderController;
use App\Http\Controllers\Jarvis\JarvisScheduledReportController;
use App\Http\Controllers\Jarvis\JarvisStorageController;
use App\Http\Controllers\Jarvis\JarvisSynthesisController;
use App\Http\Controllers\Jarvis\JarvisTaskController;
use App\Http\Controllers\Jarvis\JarvisTodayController;
use App\Http\Controllers\Jarvis\JarvisVoiceController;
use App\Http\Controllers\Jarvis\JarvisWatcherController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceCommitmentsController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceMeetingsController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceOrganizationsController;
use App\Http\Controllers\Jarvis\JarvisWorkspacePageController;
use App\Http\Controllers\Jarvis\JarvisWorkspacePeopleController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceProjectsController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceSearchController;
use App\Http\Controllers\Jarvis\JarvisWorkspaceStatusController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\OrganizationsController;
use App\Http\Controllers\PeopleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\Settings\AiSettingsController;
use App\Http\Controllers\Settings\GitHubOAuthController;
use App\Http\Controllers\Settings\GoogleOAuthController;
use App\Http\Controllers\Settings\GoogleOAuthSettingsController;
use App\Http\Controllers\Settings\IntegrationsController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\TelegramSettingsController;
use App\Http\Controllers\Settings\VoiceSettingsController;
use App\Http\Controllers\Settings\WebResearchSettingsController;
use App\Http\Controllers\Settings\ZoomIntegrationController;
use App\Http\Controllers\Telegram\TelegramWebAppController;
use App\Http\Controllers\TelegramGroupController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\UserAiSettingsController;
use App\Http\Controllers\ZoomWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use OwlSolutions\CustomAdminKit\Support\AdminRouteMiddleware;

/*
| Admin preset pages (v0.5).
| Loaded from routes/web.php via:
| require __DIR__.'/owl-admin-pages.php';
*/

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
        ValidateCsrfToken::class,
    ])
    ->name('telegram.webhook');

Route::post('/webhooks/zoom', ZoomWebhookController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
        ValidateCsrfToken::class,
    ])
    ->middleware('throttle:zoom-webhook')
    ->name('webhooks.zoom');

Route::middleware('web')->group(function (): void {
    Route::get('/telegram/webapp', [TelegramWebAppController::class, 'show'])
        ->name('telegram.webapp.show');
    Route::post('/telegram/webapp/session', [TelegramWebAppController::class, 'store'])
        ->middleware('throttle:telegram-webapp')
        ->name('telegram.webapp.session');
});

Route::middleware(['web', 'auth', 'user.active'])->group(function () {
    Route::get('/cabinet', function () {
        return redirect()->route('jarvis.index');
    })->name('cabinet.index');
    Route::get('/cabinet/ai-settings', function () {
        return redirect()->route('jarvis.index');
    })->name('cabinet.ai-settings.edit');
    Route::patch('/cabinet/ai-settings', [UserAiSettingsController::class, 'update'])
        ->name('cabinet.ai-settings.update');
    Route::get('/cabinet/chats/{conversation}', function (int $conversation) {
        return redirect()->route('jarvis.chats.show', $conversation);
    })->name('cabinet.chats.show');
    Route::post('/cabinet/chats', [CabinetChatController::class, 'store'])->name('cabinet.chats.store');
    Route::patch('/cabinet/chats/{conversation}', [CabinetChatController::class, 'update'])->name('cabinet.chats.update');
    Route::get('/cabinet/chats/{conversation}/messages', [CabinetChatController::class, 'messages'])->name('cabinet.chats.messages.index');
    Route::post('/cabinet/chats/{conversation}/messages', [CabinetChatController::class, 'storeMessage'])->name('cabinet.chats.messages.store');
});

$registerPersonalWorkspace = static function (string $prefix, string $as, array $middleware, bool $ownerStorage): void {
    Route::middleware($middleware)->prefix($prefix)->name($as.'.')->group(function () use ($ownerStorage): void {
        Route::get('/', [JarvisWorkspaceController::class, 'index'])->name('index');
        Route::get('/today', [JarvisTodayController::class, 'show'])->name('today.show');
        Route::get('/people', [JarvisWorkspacePeopleController::class, 'index'])->name('people.index');
        Route::get('/people/{person}', [JarvisWorkspacePeopleController::class, 'show'])->name('people.show');
        Route::get('/organizations', [JarvisWorkspaceOrganizationsController::class, 'index'])->name('organizations.index');
        Route::get('/organizations/{organization}', [JarvisWorkspaceOrganizationsController::class, 'show'])->name('organizations.show');
        Route::get('/search', [JarvisWorkspaceSearchController::class, 'show'])->name('search.show');
        Route::get('/more', [JarvisWorkspacePageController::class, 'more'])->name('more.show');
        Route::get('/meetings', [JarvisWorkspaceMeetingsController::class, 'index'])->name('meetings.index');
        Route::post('/meetings', [JarvisWorkspaceMeetingsController::class, 'store'])->name('meetings.store');
        Route::get('/meetings/{meeting}', [JarvisWorkspaceMeetingsController::class, 'show'])->name('meetings.show');
        Route::patch('/meetings/{meeting}', [JarvisWorkspaceMeetingsController::class, 'update'])->name('meetings.update');
        Route::post('/meetings/{meeting}/rerun', [JarvisWorkspaceMeetingsController::class, 'rerun'])->name('meetings.rerun');
        Route::post('/meetings/{meeting}/zoom-retry', [JarvisWorkspaceMeetingsController::class, 'retryZoom'])->name('meetings.zoom-retry');
        Route::post('/meetings/{meeting}/participants/{participant}/link', [JarvisWorkspaceMeetingsController::class, 'linkParticipant'])->name('meetings.participants.link');
        Route::post('/meetings/{meeting}/participants/{participant}/unlink', [JarvisWorkspaceMeetingsController::class, 'unlinkParticipant'])->name('meetings.participants.unlink');
        Route::post('/meetings/{meeting}/participants/{participant}/create-person', [JarvisWorkspaceMeetingsController::class, 'createPersonFromParticipant'])->name('meetings.participants.create-person');
        Route::post('/meetings/{meeting}/commitments/promote', [JarvisWorkspaceMeetingsController::class, 'promoteCommitment'])->name('meetings.commitments.promote');
        Route::get('/meetings/{meeting}/artifacts/{artifact}/download', [JarvisWorkspaceMeetingsController::class, 'downloadArtifact'])->name('meetings.artifacts.download');
        Route::get('/commitments', [JarvisWorkspaceCommitmentsController::class, 'index'])->name('commitments.index');
        Route::post('/commitments', [JarvisWorkspaceCommitmentsController::class, 'store'])->name('commitments.store');
        Route::get('/commitments/{commitment}', [JarvisWorkspaceCommitmentsController::class, 'show'])->name('commitments.show');
        Route::patch('/commitments/{commitment}', [JarvisWorkspaceCommitmentsController::class, 'update'])->name('commitments.update');
        Route::post('/commitments/{commitment}/confirm', [JarvisWorkspaceCommitmentsController::class, 'confirm'])->name('commitments.confirm');
        Route::post('/commitments/{commitment}/dismiss', [JarvisWorkspaceCommitmentsController::class, 'dismiss'])->name('commitments.dismiss');
        Route::post('/commitments/{commitment}/cancel', [JarvisWorkspaceCommitmentsController::class, 'cancel'])->name('commitments.cancel');
        Route::post('/commitments/{commitment}/likely-done', [JarvisWorkspaceCommitmentsController::class, 'likelyDone'])->name('commitments.likely-done');
        Route::post('/commitments/{commitment}/complete', [JarvisWorkspaceCommitmentsController::class, 'complete'])->name('commitments.complete');
        Route::post('/commitments/{commitment}/evidence', [JarvisWorkspaceCommitmentsController::class, 'evidence'])->name('commitments.evidence');
        Route::get('/projects', [JarvisWorkspaceProjectsController::class, 'index'])->name('workspace.projects.index');
        Route::get('/projects/{project}', [JarvisWorkspaceProjectsController::class, 'show'])->name('workspace.projects.show');
        Route::get('/workspace/status', [JarvisWorkspaceStatusController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('workspace.status');
        Route::get('/knowledge', [JarvisKnowledgeController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('knowledge.index');
        Route::get('/knowledge/entities/{entity}', [JarvisKnowledgeController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('knowledge.entities.show');
        Route::get('/synthesis', [JarvisSynthesisController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('synthesis.index');
        Route::get('/synthesis/project/{project}', [JarvisSynthesisController::class, 'project'])
            ->middleware('throttle:30,1')
            ->name('synthesis.project');
        Route::get('/synthesis/entity/{entity}', [JarvisSynthesisController::class, 'entity'])
            ->middleware('throttle:30,1')
            ->name('synthesis.entity');
        Route::post('/chats', [JarvisWorkspaceController::class, 'store'])->name('chats.store');
        Route::get('/chats/{conversation}', [JarvisWorkspaceController::class, 'show'])->name('chats.show');
        Route::patch('/chats/{conversation}', [JarvisWorkspaceController::class, 'update'])->name('chats.update');
        Route::delete('/chats/{conversation}', [JarvisWorkspaceController::class, 'destroy'])->name('chats.destroy');
        Route::post('/chats/{conversation}/messages', [JarvisWorkspaceController::class, 'storeMessage'])->name('messages.store');
        Route::get('/chats/{conversation}/messages/older', [JarvisWorkspaceController::class, 'olderMessages'])->name('messages.older');
        Route::get('/chats/{conversation}/attachments/{attachment}/preview', [JarvisAttachmentController::class, 'preview'])
            ->name('attachments.preview');
        Route::get('/chats/{conversation}/attachments/{attachment}', [JarvisAttachmentController::class, 'show'])
            ->name('attachments.show');
        Route::post('/confirmations/{confirmation}/confirm', [JarvisConfirmationController::class, 'confirm'])
            ->name('confirmations.confirm');
        Route::post('/confirmations/{confirmation}/cancel', [JarvisConfirmationController::class, 'cancel'])
            ->name('confirmations.cancel');
        Route::patch('/settings/general-prompt', [JarvisWorkspaceController::class, 'updateGeneralPrompt'])
            ->name('settings.prompt.update');
        Route::patch('/settings/profile', [JarvisWorkspaceController::class, 'updateProfile'])
            ->name('settings.profile.update');
        Route::patch('/settings/locales', [JarvisWorkspaceController::class, 'updateLocales'])
            ->name('settings.locales.update');
        Route::put('/settings/password', [JarvisWorkspaceController::class, 'updatePassword'])
            ->name('settings.password.update');
        Route::post('/onboarding', [JarvisWorkspaceController::class, 'startOnboarding'])
            ->name('onboarding.start');
        Route::get('/reminders', [JarvisReminderController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('reminders.index');
        Route::get('/reminders/push', [JarvisPushSubscriptionController::class, 'status'])
            ->middleware('throttle:30,1')
            ->name('reminders.push.status');
        Route::post('/reminders/push', [JarvisPushSubscriptionController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('reminders.push.store');
        Route::delete('/reminders/push', [JarvisPushSubscriptionController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('reminders.push.destroy');
        Route::patch('/reminders/{reminder}', [JarvisReminderController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('reminders.update');
        Route::post('/reminders/{reminder}/snooze', [JarvisReminderController::class, 'snooze'])
            ->middleware('throttle:30,1')
            ->name('reminders.snooze');
        Route::post('/reminders/{reminder}/complete', [JarvisReminderController::class, 'complete'])
            ->middleware('throttle:30,1')
            ->name('reminders.complete');
        Route::post('/reminders/{reminder}/cancel', [JarvisReminderController::class, 'cancel'])
            ->middleware('throttle:30,1')
            ->name('reminders.cancel');
        Route::get('/tasks', [JarvisTaskController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('tasks.index');
        Route::post('/tasks', [JarvisTaskController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('tasks.store');
        Route::patch('/tasks/{task}', [JarvisTaskController::class, 'update'])
            ->middleware('throttle:30,1')
            ->name('tasks.update');
        Route::post('/tasks/{task}/start', [JarvisTaskController::class, 'start'])
            ->middleware('throttle:30,1')
            ->name('tasks.start');
        Route::post('/tasks/{task}/complete', [JarvisTaskController::class, 'complete'])
            ->middleware('throttle:30,1')
            ->name('tasks.complete');
        Route::post('/tasks/{task}/cancel', [JarvisTaskController::class, 'cancel'])
            ->middleware('throttle:30,1')
            ->name('tasks.cancel');
        Route::post('/tasks/{task}/reopen', [JarvisTaskController::class, 'reopen'])
            ->middleware('throttle:30,1')
            ->name('tasks.reopen');
        Route::post('/tasks/{task}/subtasks', [JarvisTaskController::class, 'storeSubtask'])
            ->middleware('throttle:20,1')
            ->name('tasks.subtasks.store');
        Route::get('/watchers', [JarvisWatcherController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('watchers.index');
        Route::post('/watchers', [JarvisWatcherController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('watchers.store');
        Route::get('/watchers/{watcher}', [JarvisWatcherController::class, 'show'])
            ->middleware('throttle:30,1')
            ->name('watchers.show');
        Route::patch('/watchers/{watcher}', [JarvisWatcherController::class, 'update'])
            ->middleware('throttle:20,1')
            ->name('watchers.update');
        Route::post('/watchers/{watcher}/pause', [JarvisWatcherController::class, 'pause'])
            ->middleware('throttle:30,1')
            ->name('watchers.pause');
        Route::post('/watchers/{watcher}/resume', [JarvisWatcherController::class, 'resume'])
            ->middleware('throttle:30,1')
            ->name('watchers.resume');
        Route::post('/watchers/{watcher}/cancel', [JarvisWatcherController::class, 'cancel'])
            ->middleware('throttle:30,1')
            ->name('watchers.cancel');
        Route::get('/watchers/{watcher}/occurrences', [JarvisWatcherController::class, 'occurrences'])
            ->middleware('throttle:30,1')
            ->name('watchers.occurrences');
        Route::get('/reports', [JarvisScheduledReportController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('reports.index');
        Route::post('/reports/{report}/pause', [JarvisScheduledReportController::class, 'pause'])
            ->middleware('throttle:30,1')
            ->name('reports.pause');
        Route::post('/reports/{report}/resume', [JarvisScheduledReportController::class, 'resume'])
            ->middleware('throttle:30,1')
            ->name('reports.resume');
        Route::post('/reports/{report}/cancel', [JarvisScheduledReportController::class, 'cancel'])
            ->middleware('throttle:30,1')
            ->name('reports.cancel');
        Route::get('/notifications', [JarvisNotificationController::class, 'index'])
            ->middleware('throttle:30,1')
            ->name('notifications.index');
        Route::post('/notifications/read-all', [JarvisNotificationController::class, 'markAllRead'])
            ->middleware('throttle:20,1')
            ->name('notifications.read-all');
        Route::post('/notifications/{notification}/read', [JarvisNotificationController::class, 'markRead'])
            ->middleware('throttle:30,1')
            ->name('notifications.read');
        Route::post('/notifications/{notification}/dismiss', [JarvisNotificationController::class, 'dismiss'])
            ->middleware('throttle:30,1')
            ->name('notifications.dismiss');
        Route::patch('/settings/productivity', [JarvisProductivitySettingsController::class, 'update'])
            ->name('settings.productivity.update');
        Route::post('/chats/{conversation}/voice/sessions', [JarvisVoiceController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('voice.sessions.store');
        Route::get('/voice/sessions/{session}', [JarvisVoiceController::class, 'show'])
            ->name('voice.sessions.show');
        Route::post('/voice/sessions/{session}/listen', [JarvisVoiceController::class, 'listen'])
            ->middleware('throttle:60,1')
            ->name('voice.sessions.listen');
        Route::post('/voice/sessions/{session}/audio', [JarvisVoiceController::class, 'audio'])
            ->middleware('throttle:30,1')
            ->name('voice.sessions.audio');
        Route::post('/voice/sessions/{session}/interrupt', [JarvisVoiceController::class, 'interrupt'])
            ->middleware('throttle:60,1')
            ->name('voice.sessions.interrupt');
        Route::post('/voice/sessions/{session}/mute', [JarvisVoiceController::class, 'mute'])
            ->middleware('throttle:60,1')
            ->name('voice.sessions.mute');
        Route::post('/voice/sessions/{session}/resume', [JarvisVoiceController::class, 'resume'])
            ->middleware('throttle:60,1')
            ->name('voice.sessions.resume');
        Route::delete('/voice/sessions/{session}', [JarvisVoiceController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('voice.sessions.destroy');
        Route::post('/chats/{conversation}/voice/realtime/session', [JarvisRealtimeVoiceController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('voice.realtime.session.store');
        Route::get('/voice/realtime/sessions/{session}', [JarvisRealtimeVoiceController::class, 'show'])
            ->name('voice.realtime.session.show');
        Route::post('/voice/realtime/sessions/{session}/metrics', [JarvisRealtimeVoiceController::class, 'metrics'])
            ->middleware('throttle:60,1')
            ->name('voice.realtime.session.metrics');
        Route::delete('/voice/realtime/sessions/{session}', [JarvisRealtimeVoiceController::class, 'destroy'])
            ->middleware('throttle:20,1')
            ->name('voice.realtime.session.destroy');

        if ($ownerStorage) {
            Route::get('/storage', [JarvisStorageController::class, 'index'])->name('storage.index');
            Route::post('/storage', [JarvisStorageController::class, 'store'])->name('storage.store');
            Route::get('/storage/{file}', [JarvisStorageController::class, 'show'])->name('storage.show');
            Route::patch('/storage/{file}', [JarvisStorageController::class, 'update'])->name('storage.update');
            Route::delete('/storage/{file}', [JarvisStorageController::class, 'destroy'])->name('storage.destroy');
            Route::get('/storage/{file}/download', [JarvisStorageController::class, 'download'])->name('storage.download');
        }
    });
};

$registerPersonalWorkspace('/lavr', 'jarvis', ['web', 'auth', 'user.active'], true);

$redirectLegacyWorkspace = static function (string $from): void {
    Route::get($from, function () {
        $query = request()->getQueryString();

        return redirect('/lavr'.($query ? '?'.$query : ''));
    })->middleware('web');

    Route::get($from.'/{path}', function (string $path) {
        $query = request()->getQueryString();

        return redirect('/lavr/'.$path.($query ? '?'.$query : ''));
    })->where('path', '.*')->middleware('web');
};

$redirectLegacyWorkspace('/jarvis');
$redirectLegacyWorkspace('/chat');

Route::middleware(array_merge(AdminRouteMiddleware::stack(), ['user.active', 'owner']))->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/telegram-groups', [TelegramGroupController::class, 'index'])->name('telegram-groups.index');
    Route::get('/telegram-groups/archive', [TelegramGroupController::class, 'archive'])->name('telegram-groups.archive');
    Route::get('/telegram-groups/{telegramGroup}', [TelegramGroupController::class, 'show'])->name('telegram-groups.show');
    Route::patch('/telegram-groups/{telegramGroup}', [TelegramGroupController::class, 'update'])->name('telegram-groups.update');
    Route::get('/telegram-groups/{telegramGroup}/messages', [TelegramGroupController::class, 'messages'])->name('telegram-groups.messages.index');
    Route::post('/telegram-groups/{telegramGroup}/messages', [TelegramGroupController::class, 'storeMessage'])->name('telegram-groups.messages.store');
    Route::post('/telegram-groups/{telegramGroup}/analysis', [TelegramGroupController::class, 'storeAnalysis'])->name('telegram-groups.analysis.store');
    Route::post('/telegram-groups/{telegramGroup}/analysis-runs/{run}/retry', [TelegramGroupController::class, 'retryAnalysis'])->name('telegram-groups.analysis.retry');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project}/archive', [ProjectController::class, 'archive'])->name('projects.archive');
    Route::post('/projects/{project}/restore', [ProjectController::class, 'restore'])->name('projects.restore');
    Route::post('/projects/{project}/conversations', [ProjectController::class, 'attachConversation'])->name('projects.conversations.store');
    Route::delete('/projects/{project}/conversations/{conversation}', [ProjectController::class, 'detachConversation'])->name('projects.conversations.destroy');
    Route::post('/projects/{project}/topics', [ProjectController::class, 'attachTopic'])->name('projects.topics.store');
    Route::delete('/projects/{project}/topics/{topic}', [ProjectController::class, 'detachTopic'])->name('projects.topics.destroy');
    Route::post('/projects/{project}/memories', [ProjectController::class, 'attachMemory'])->name('projects.memories.store');
    Route::delete('/projects/{project}/memories/{memory}', [ProjectController::class, 'detachMemory'])->name('projects.memories.destroy');
    Route::post('/projects/{project}/groups', [ProjectController::class, 'attachGroup'])->name('projects.groups.store');
    Route::delete('/projects/{project}/groups/{telegramGroup}', [ProjectController::class, 'detachGroup'])->name('projects.groups.destroy');
    Route::post('/projects/{project}/people', [ProjectController::class, 'attachPerson'])->name('projects.people.store');
    Route::delete('/projects/{project}/people/{person}', [ProjectController::class, 'detachPerson'])->name('projects.people.destroy');
    Route::post('/projects/{project}/organizations', [ProjectController::class, 'attachOrganization'])->name('projects.organizations.store');
    Route::delete('/projects/{project}/organizations/{organization}', [ProjectController::class, 'detachOrganization'])->name('projects.organizations.destroy');

    Route::get('/people', [PeopleController::class, 'index'])->name('people.index');
    Route::post('/people', [PeopleController::class, 'store'])->name('people.store');
    Route::get('/people/{person}', [PeopleController::class, 'show'])->name('people.show');
    Route::patch('/people/{person}', [PeopleController::class, 'update'])->name('people.update');
    Route::post('/people/{person}/archive', [PeopleController::class, 'archive'])->name('people.archive');
    Route::post('/people/{person}/restore', [PeopleController::class, 'restore'])->name('people.restore');
    Route::patch('/people/{person}/employee', [PeopleController::class, 'updateEmployee'])->name('people.employee.update');
    Route::post('/people/{person}/identities', [PeopleController::class, 'storeIdentity'])->name('people.identities.store');
    Route::post('/people/{person}/projects', [PeopleController::class, 'attachProject'])->name('people.projects.store');
    Route::delete('/people/{person}/projects/{project}', [PeopleController::class, 'detachProject'])->name('people.projects.destroy');
    Route::post('/people/{person}/relationships', [PeopleController::class, 'storeRelationship'])->name('people.relationships.store');
    Route::post('/people/{person}/knowledge', [PeopleController::class, 'linkKnowledge'])->name('people.knowledge.store');
    Route::post('/people/{person}/merge', [PeopleController::class, 'merge'])->name('people.merge');

    Route::get('/organizations', [OrganizationsController::class, 'index'])->name('organizations.index');
    Route::post('/organizations', [OrganizationsController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organization}', [OrganizationsController::class, 'show'])->name('organizations.show');
    Route::patch('/organizations/{organization}', [OrganizationsController::class, 'update'])->name('organizations.update');
    Route::post('/organizations/{organization}/archive', [OrganizationsController::class, 'archive'])->name('organizations.archive');
    Route::post('/organizations/{organization}/restore', [OrganizationsController::class, 'restore'])->name('organizations.restore');
    Route::post('/organizations/{organization}/projects', [OrganizationsController::class, 'attachProject'])->name('organizations.projects.store');
    Route::delete('/organizations/{organization}/projects/{project}', [OrganizationsController::class, 'detachProject'])->name('organizations.projects.destroy');
    Route::post('/organizations/{organization}/knowledge', [OrganizationsController::class, 'linkKnowledge'])->name('organizations.knowledge.store');

    Route::get('/meetings', [MeetingController::class, 'index'])->name('meetings.index');
    Route::post('/meetings', [MeetingController::class, 'store'])->name('meetings.store');
    Route::get('/meetings/{meeting}', [MeetingController::class, 'show'])->name('meetings.show');
    Route::patch('/meetings/{meeting}', [MeetingController::class, 'update'])->name('meetings.update');
    Route::post('/meetings/{meeting}/archive', [MeetingController::class, 'archive'])->name('meetings.archive');
    Route::post('/meetings/{meeting}/restore', [MeetingController::class, 'restore'])->name('meetings.restore');
    Route::post('/meetings/{meeting}/rerun', [MeetingController::class, 'rerun'])->name('meetings.rerun');
    Route::post('/meetings/{meeting}/zoom-retry', [MeetingController::class, 'retryZoom'])->name('meetings.zoom-retry');
    Route::post('/meetings/{meeting}/participants/{participant}/link', [MeetingController::class, 'linkParticipant'])->name('meetings.participants.link');
    Route::post('/meetings/{meeting}/participants/{participant}/unlink', [MeetingController::class, 'unlinkParticipant'])->name('meetings.participants.unlink');
    Route::post('/meetings/{meeting}/participants/{participant}/create-person', [MeetingController::class, 'createPersonFromParticipant'])->name('meetings.participants.create-person');
    Route::get('/meetings/{meeting}/artifacts/{artifact}/download', [MeetingController::class, 'downloadArtifact'])->name('meetings.artifacts.download');
    Route::post('/meetings/{meeting}/commitments/promote', [MeetingController::class, 'promoteCommitment'])->name('meetings.commitments.promote');

    Route::get('/commitments', [CommitmentController::class, 'index'])->name('commitments.index');
    Route::post('/commitments', [CommitmentController::class, 'store'])->name('commitments.store');
    Route::get('/commitments/{commitment}', [CommitmentController::class, 'show'])->name('commitments.show');
    Route::patch('/commitments/{commitment}', [CommitmentController::class, 'update'])->name('commitments.update');
    Route::post('/commitments/{commitment}/confirm', [CommitmentController::class, 'confirm'])->name('commitments.confirm');
    Route::post('/commitments/{commitment}/dismiss', [CommitmentController::class, 'dismiss'])->name('commitments.dismiss');
    Route::post('/commitments/{commitment}/cancel', [CommitmentController::class, 'cancel'])->name('commitments.cancel');
    Route::post('/commitments/{commitment}/likely-done', [CommitmentController::class, 'likelyDone'])->name('commitments.likely-done');
    Route::post('/commitments/{commitment}/complete', [CommitmentController::class, 'complete'])->name('commitments.complete');
    Route::post('/commitments/{commitment}/evidence', [CommitmentController::class, 'evidence'])->name('commitments.evidence');
    Route::post('/commitments/{commitment}/merge', [CommitmentController::class, 'merge'])->name('commitments.merge');

    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/settings/integrations', [IntegrationsController::class, 'index'])->name('settings.integrations.index');
    Route::get('/settings/integrations/accounts/{integrationAccount}', [IntegrationsController::class, 'showAccount'])->name('settings.integrations.accounts.show');
    Route::post('/settings/integrations/google', [GoogleOAuthSettingsController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('settings.integrations.google.update');
    Route::post('/settings/integrations/zoom', [ZoomIntegrationController::class, 'update'])
        ->middleware('throttle:10,1')
        ->name('settings.integrations.zoom.update');
    Route::post('/settings/integrations/zoom/test', [ZoomIntegrationController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('settings.integrations.zoom.test');
    Route::post('/settings/integrations/zoom/disconnect', [ZoomIntegrationController::class, 'disconnect'])
        ->name('settings.integrations.zoom.disconnect');
    Route::get('/settings/integrations/google/connect', [GoogleOAuthController::class, 'connect'])
        ->middleware('throttle:10,1')
        ->name('integrations.google.connect');
    Route::post('/settings/integrations/google/disconnect', [GoogleOAuthController::class, 'disconnect'])
        ->name('integrations.google.disconnect');
    Route::get('/integrations/google/callback', [GoogleOAuthController::class, 'callback'])
        ->name('integrations.google.callback');
    Route::get('/settings/integrations/github/connect', [GitHubOAuthController::class, 'connect'])
        ->middleware('throttle:10,1')
        ->name('integrations.github.connect');
    Route::post('/settings/integrations/github/disconnect', [GitHubOAuthController::class, 'disconnect'])
        ->name('integrations.github.disconnect');
    Route::get('/integrations/github/callback', [GitHubOAuthController::class, 'callback'])
        ->name('integrations.github.callback');
    Route::post('/settings/language', [SettingsController::class, 'updateLanguage'])->name('settings.language.update');

    Route::post('/settings/web-research', [WebResearchSettingsController::class, 'update'])
        ->name('settings.web-research.update');
    Route::post('/settings/web-research/tavily-key', [WebResearchSettingsController::class, 'saveTavilyKey'])
        ->name('settings.web-research.tavily-key');
    Route::post('/settings/web-research/tavily-key/clear', [WebResearchSettingsController::class, 'clearTavilyKey'])
        ->name('settings.web-research.tavily-key.clear');
    Route::post('/settings/voice', [VoiceSettingsController::class, 'update'])
        ->name('settings.voice.update');
    Route::post('/settings/voice/elevenlabs-key', [VoiceSettingsController::class, 'saveElevenLabsKey'])
        ->name('settings.voice.elevenlabs-key');
    Route::post('/settings/voice/elevenlabs-key/clear', [VoiceSettingsController::class, 'clearElevenLabsKey'])
        ->name('settings.voice.elevenlabs-key.clear');
    Route::post('/settings/telegram/token', [TelegramSettingsController::class, 'saveToken'])
        ->middleware('throttle:10,1')
        ->name('settings.telegram.save-token');
    Route::post('/settings/telegram/check', [TelegramSettingsController::class, 'check'])
        ->name('settings.telegram.check');
    Route::post('/settings/telegram/set-webhook', [TelegramSettingsController::class, 'setWebhook'])
        ->name('settings.telegram.set-webhook');
    Route::post('/settings/telegram/remove-webhook', [TelegramSettingsController::class, 'removeWebhook'])
        ->name('settings.telegram.remove-webhook');

    Route::get('/app-settings', function () {
        return redirect()->route('settings.index', ['tab' => 'app']);
    })->name('app-settings.index');

    Route::get('/ai-settings', [AiSettingsController::class, 'index'])->name('ai-settings.index');
    Route::post('/ai-settings/{provider}/key', [AiSettingsController::class, 'saveKey'])->name('ai-settings.save-key');
    Route::post('/ai-settings/{provider}/check', [AiSettingsController::class, 'check'])->name('ai-settings.check');
    Route::post('/ai-settings/{provider}/activate', [AiSettingsController::class, 'activate'])->name('ai-settings.activate');
    Route::post('/ai-settings/deactivate', [AiSettingsController::class, 'deactivate'])->name('ai-settings.deactivate');
    Route::patch('/ai-settings/roles/{roleKey}', [AiSettingsController::class, 'updateRole'])->name('ai-settings.roles.update');

    Route::get('/statistics/logs', function () {
        return Inertia::render('Statistics/Logs');
    })->name('statistics.logs');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
