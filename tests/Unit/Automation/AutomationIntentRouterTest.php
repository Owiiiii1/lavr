<?php

namespace Tests\Unit\Automation;

use App\Enums\AutomationIntentKind;
use App\Services\Automation\AutomationIntentRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AutomationIntentRouterTest extends TestCase
{
    #[DataProvider('phrases')]
    public function test_classifies_owner_phrases(string $text, AutomationIntentKind $kind): void
    {
        $this->assertSame($kind, (new AutomationIntentRouter)->classify($text));
    }

    /**
     * @return array<string, array{0: string, 1: AutomationIntentKind}>
     */
    public static function phrases(): array
    {
        return [
            'reminder' => ['Напомни завтра в 9 позвонить Сергію', AutomationIntentKind::Reminder],
            'digest' => ['Каждое утро в 8 дай сводку писем и календаря', AutomationIntentKind::ScheduledReport],
            'event' => ['Следи, когда придёт письмо от школы', AutomationIntentKind::Watcher],
            'file' => ['Каждый день проверяй, пришёл ли файл от бухгалтерии', AutomationIntentKind::Watcher],
            'evening' => ['Каждый вечер подведи итоги дня', AutomationIntentKind::ScheduledReport],
            'morning_check' => ['Каждое утро проверь почту', AutomationIntentKind::ScheduledReport],
            'ambiguous' => ['Каждое утро дай сводку почты и следи когда придёт файл', AutomationIntentKind::Clarify],
        ];
    }
}
