<?php

namespace App\Services\OperationalControl\Contracts;

use App\Models\User;
use App\Services\OperationalControl\OperationalRuleMatch;

interface OperationalRule
{
    public function key(): string;

    /**
     * @return list<OperationalRuleMatch>
     */
    public function evaluate(User $user): array;
}
