<?php

namespace App\Services\OperationalControl;

use App\Models\Commitment;
use App\Models\IntegrationAccount;
use App\Models\Meeting;
use App\Models\SourceItem;
use App\Models\User;
use Throwable;

final class OperationalControlHooks
{
    public function onUser(User $user): void
    {
        try {
            app(OperationalControlScanService::class)->scanUser($user);
        } catch (Throwable) {
        }
    }

    public function onCommitment(Commitment $commitment): void
    {
        $user = $commitment->user ?? User::query()->find($commitment->user_id);
        if ($user instanceof User) {
            $this->onUser($user);
        }
    }

    public function onMeeting(Meeting $meeting): void
    {
        $user = $meeting->user ?? User::query()->find($meeting->user_id);
        if ($user instanceof User) {
            $this->onUser($user);
        }
    }

    public function onSourceItem(SourceItem $item): void
    {
        $user = $item->user ?? User::query()->find($item->user_id);
        if ($user instanceof User) {
            $this->onUser($user);
        }
    }

    public function onIntegration(IntegrationAccount $account): void
    {
        $user = $account->user ?? User::query()->find($account->user_id);
        if ($user instanceof User) {
            $this->onUser($user);
        }
    }
}
