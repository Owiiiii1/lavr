<?php

namespace App\Services\Readiness;

use App\Enums\AcceptanceState;

final class ProductionSmokeService
{
    public function __construct(
        private readonly LavrDiagnosticsService $diagnostics,
    ) {}

    /**
     * @return array{overall: string, exit_fail: bool, checks: array<string, mixed>}
     */
    public function run(): array
    {
        $snapshot = $this->diagnostics->snapshot();
        $critical = ['database', 'owner', 'registration', 'app_debug', 'storage'];
        $fail = false;

        foreach ($critical as $key) {
            if (($snapshot['checks'][$key]['acceptance'] ?? null) === AcceptanceState::Fail->value) {
                $fail = true;
                break;
            }
        }

        return [
            'overall' => $fail ? AcceptanceState::Fail->value : (string) $snapshot['overall'],
            'exit_fail' => $fail,
            'generated_at' => $snapshot['generated_at'],
            'checks' => $snapshot['checks'],
            'checklist' => $snapshot['checklist'],
        ];
    }
}
