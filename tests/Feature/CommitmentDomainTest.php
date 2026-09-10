<?php

namespace Tests\Feature;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentEvidenceType;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use App\Enums\UserRole;
use App\Models\Commitment;
use App\Models\Person;
use App\Models\User;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\CommitmentStatusService;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Projects\ProjectService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class CommitmentDomainTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_manual_create_is_open_and_due_soon_overdue_likely_done_confirmed_cancel(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Test Manager');
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), 'Show');
            $service = app(CommitmentService::class);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'));

            $open = $service->createManual($user, [
                'title' => 'Надіслати фінальний бюджет',
                'expected_result' => 'Файл бюджету Chicago у фінальній версії',
                'person_id' => $person->id,
                'project_id' => $project->id,
                'deadline_at' => '2026-09-20T12:00:00+00:00',
                'deadline_raw' => 'September 20',
            ]);

            $this->assertSame(CommitmentEffectiveStatus::Open, $open->status);
            $this->assertSame(CommitmentLifecycleStatus::Open, $open->lifecycle_status);
            $this->assertSame(CommitmentSourceType::Manual, $open->source_type);
            $this->assertSame($person->id, $open->person_id);
            $this->assertSame($project->id, $open->project_id);
            $this->assertNotEmpty($open->statusHistory);

            $soon = $open->fresh();
            $soon->deadline_at = CarbonImmutable::parse('2026-09-11 12:00:00', 'UTC');
            $soon->save();
            app(CommitmentStatusService::class)->persist($soon);
            $this->assertSame(CommitmentEffectiveStatus::DueSoon, $soon->fresh()->status);

            $overdue = $soon->fresh();
            $overdue->deadline_at = CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC');
            $overdue->save();
            app(CommitmentStatusService::class)->persist($overdue);
            $this->assertSame(CommitmentEffectiveStatus::Overdue, $overdue->fresh()->status);

            $likely = $service->markLikelyDone($user, $overdue->fresh(), 'Budget file received');
            $this->assertSame(CommitmentLifecycleStatus::LikelyDone, $likely->lifecycle_status);
            $this->assertSame(CommitmentEffectiveStatus::LikelyDone, $likely->status);
            $this->assertTrue($likely->evidence->contains(fn ($row): bool => $row->evidence_type === CommitmentEvidenceType::Completion));

            $confirmed = $service->markConfirmed($user, $likely, 'Owner checked the file');
            $this->assertSame(CommitmentEffectiveStatus::Confirmed, $confirmed->status);
            $this->assertNotNull($confirmed->completed_at);

            $other = $service->createManual($user, [
                'title' => 'Call vendor',
                'person_id' => $person->id,
            ]);
            $cancelled = $service->cancel($user, $other, 'not needed');
            $this->assertSame(CommitmentEffectiveStatus::Cancelled, $cancelled->status);

            $duplicate = $service->createManual($user, ['title' => 'Send budget copy', 'person_id' => $person->id]);
            $merged = $service->merge($user, $confirmed, $duplicate);
            $this->assertSame($confirmed->id, $merged->id);
            $this->assertSame($confirmed->id, $duplicate->fresh()->merged_into_id);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_refresh_statuses_is_deterministic_without_ai(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'UTC'));
            $commitment = Commitment::factory()->create([
                'user_id' => $user->id,
                'title' => 'Send deck',
                'deadline_at' => CarbonImmutable::parse('2026-09-09 10:00:00', 'UTC'),
                'status' => CommitmentEffectiveStatus::Open,
                'lifecycle_status' => CommitmentLifecycleStatus::Open,
            ]);

            $count = app(CommitmentService::class)->refreshStatuses();
            $this->assertSame(1, $count);
            $this->assertSame(CommitmentEffectiveStatus::Overdue, $commitment->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    private function person(User $user, string $name): Person
    {
        return Person::factory()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'normalized_name' => ProjectNameNormalizer::normalize($name),
        ]);
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
