<?php

namespace Tests\Unit;

use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\CreateTaskTool;
use App\Services\Tools\Directory\FindPersonTool;
use App\Services\Tools\Directory\GetProjectTool;
use App\Services\Tools\GetAssistantProfileTool;
use App\Services\Tools\ListRemindersTool;
use App\Services\Tools\ListTasksTool;
use App\Services\Tools\Meetings\ListMeetingsTool;
use App\Services\Tools\ToolRegistry;
use Tests\TestCase;

class ToolRegistryResolutionTest extends TestCase
{
    public function test_container_resolves_the_tool_registry_with_core_tools(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertInstanceOf(CreateReminderTool::class, $registry->resolve(CreateReminderTool::NAME));
        $this->assertInstanceOf(ListRemindersTool::class, $registry->resolve(ListRemindersTool::NAME));
        $this->assertInstanceOf(GetAssistantProfileTool::class, $registry->resolve(GetAssistantProfileTool::NAME));
        $this->assertInstanceOf(CreateTaskTool::class, $registry->resolve(CreateTaskTool::NAME));
        $this->assertInstanceOf(ListTasksTool::class, $registry->resolve(ListTasksTool::NAME));
        $this->assertInstanceOf(FindPersonTool::class, $registry->resolve(FindPersonTool::NAME));
        $this->assertInstanceOf(GetProjectTool::class, $registry->resolve(GetProjectTool::NAME));
        $this->assertInstanceOf(ListMeetingsTool::class, $registry->resolve(ListMeetingsTool::NAME));
    }
}
