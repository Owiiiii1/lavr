<?php

namespace Tests\Unit;

use App\Enums\ExecutiveBriefPriority;
use App\Services\ExecutiveBrief\ExecutiveBriefPrioritizer;
use PHPUnit\Framework\TestCase;

class ExecutiveBriefPrioritizerTest extends TestCase
{
    public function test_overdue_outranks_fyi_and_due_today_is_high(): void
    {
        $scorer = new ExecutiveBriefPrioritizer;

        $overdue = $scorer->rank(['overdue' => true]);
        $fyi = $scorer->rank(['fyi' => true]);
        $dueToday = $scorer->rank(['due_today' => true]);
        $soon = $scorer->rank(['hours_until' => 2]);
        $later = $scorer->rank(['hours_until' => 8]);
        $blocked = $scorer->rank(['blocked' => true]);

        $this->assertSame(ExecutiveBriefPriority::Critical, $overdue['priority']);
        $this->assertSame(ExecutiveBriefPriority::Low, $fyi['priority']);
        $this->assertGreaterThan($fyi['score'], $overdue['score']);
        $this->assertSame(ExecutiveBriefPriority::High, $dueToday['priority']);
        $this->assertSame(ExecutiveBriefPriority::High, $soon['priority']);
        $this->assertSame(ExecutiveBriefPriority::Normal, $later['priority']);
        $this->assertSame(ExecutiveBriefPriority::Critical, $blocked['priority']);
        $this->assertGreaterThan($dueToday['score'], $overdue['score']);
    }
}
