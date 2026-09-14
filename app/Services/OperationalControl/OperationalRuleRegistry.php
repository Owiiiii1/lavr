<?php

namespace App\Services\OperationalControl;

use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\Productivity\ProductivitySettingsService;

final class OperationalRuleRegistry
{
    /**
     * @param  list<OperationalRule>  $rules
     */
    public function __construct(
        private readonly array $rules,
        private readonly ProductivitySettingsService $settings,
    ) {}

    /**
     * @return list<OperationalRule>
     */
    public function enabledFor(User $user): array
    {
        $disabled = $this->disabledKeys($user);

        return array_values(array_filter(
            $this->rules,
            fn (OperationalRule $rule): bool => ! in_array($rule->key(), $disabled, true),
        ));
    }

    /**
     * @return list<OperationalRuleMatch>
     */
    public function evaluate(User $user): array
    {
        $matches = [];
        foreach ($this->enabledFor($user) as $rule) {
            foreach ($rule->evaluate($user) as $match) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function disabledKeys(User $user): array
    {
        $settings = $this->settings->for($user);
        $raw = $settings->disabled_operational_rules ?? [];

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $key): string => is_string($key) ? $key : '',
            $raw,
        )));
    }
}
