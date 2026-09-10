<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\Log;

final class AutomationEventBus
{
    public function emit(AutomationEvent $event): void
    {
        Log::info('automation event', [
            'event_type' => $event->type,
            'user_id' => $event->userId,
            'source_id' => $event->sourceId,
        ]);
    }
}
