<?php

namespace Tests\Unit;

use Tests\TestCase;

class WorkspaceStatusRoutesTest extends TestCase
{
    public function test_canonical_workspace_exposes_the_status_endpoint(): void
    {
        $this->assertSame('/lavr/workspace/status', route('jarvis.workspace.status', absolute: false));
    }

    public function test_turn_payload_includes_task_and_notification_counts(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/Conversations/PersonalChatSurfaceService.php'));

        $this->assertStringContainsString('turnCounts($user)', $source);
        $this->assertStringContainsString('active_task_count', (string) file_get_contents(base_path('app/Services/Workspace/WorkspaceSurfaceStateService.php')));
        $this->assertStringContainsString('unread_notification_count', (string) file_get_contents(base_path('app/Services/Workspace/WorkspaceSurfaceStateService.php')));
    }
}
