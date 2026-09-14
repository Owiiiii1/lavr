<?php

namespace App\Services\LeadershipReview;

use App\Enums\CommitmentEffectiveStatus;

final class LeadershipReviewMetrics
{
    public const MIN_SAMPLE = 3;

    /**
     * @param  array<string, mixed>  $collected
     * @return array<string, mixed>
     */
    public function compute(array $collected): array
    {
        $commitments = is_array($collected['commitments'] ?? null) ? $collected['commitments'] : [];
        $meetings = is_array($collected['meetings'] ?? null) ? $collected['meetings'] : [];
        $projects = is_array($collected['projects'] ?? null) ? $collected['projects'] : [];
        $automations = is_array($collected['automation_runs'] ?? null) ? $collected['automation_runs'] : [];
        $ownerIds = is_array($collected['owner_person_ids'] ?? null) ? $collected['owner_person_ids'] : [];
        $people = is_array($collected['people'] ?? null) ? $collected['people'] : [];
        $ownerName = $this->normalize((string) ($collected['owner_name'] ?? ''));

        $total = count($commitments);
        $open = 0;
        $overdue = 0;
        $confirmed = 0;
        $withoutDeadline = 0;
        $vagueDeadline = 0;
        $withoutPerson = 0;
        $likelyDone = 0;
        $detected = 0;
        $overdueNoFollowup = 0;
        $ownerAssigned = 0;
        $assigned = 0;
        $activeByPerson = [];
        $dueSoonByPerson = [];
        $overdueByPerson = [];

        $now = time();
        $weekEnd = $now + 7 * 86400;

        foreach ($commitments as $row) {
            if (! is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $personId = isset($row['person_id']) ? (int) $row['person_id'] : 0;
            $unresolved = (bool) ($row['unresolved_person'] ?? false);
            $hasPerson = $personId > 0 && ! $unresolved;
            $deadlineAt = $this->timestamp($row['deadline_at'] ?? null);
            $deadlineRaw = trim((string) ($row['deadline_raw'] ?? ''));

            if (in_array($status, [
                CommitmentEffectiveStatus::Detected->value,
                CommitmentEffectiveStatus::Open->value,
                CommitmentEffectiveStatus::DueSoon->value,
                CommitmentEffectiveStatus::Overdue->value,
                CommitmentEffectiveStatus::LikelyDone->value,
            ], true)) {
                $open++;
                if ($personId > 0) {
                    $activeByPerson[$personId] = ($activeByPerson[$personId] ?? 0) + 1;
                    if ($deadlineAt !== null && $deadlineAt <= $weekEnd && $deadlineAt >= $now) {
                        $dueSoonByPerson[$personId] = ($dueSoonByPerson[$personId] ?? 0) + 1;
                    }
                }
            }

            if ($status === CommitmentEffectiveStatus::Overdue->value) {
                $overdue++;
                if ($personId > 0) {
                    $overdueByPerson[$personId] = ($overdueByPerson[$personId] ?? 0) + 1;
                }
                if (empty($row['last_notified_at'])) {
                    $overdueNoFollowup++;
                }
            }
            if ($status === CommitmentEffectiveStatus::Confirmed->value) {
                $confirmed++;
            }
            if ($status === CommitmentEffectiveStatus::LikelyDone->value) {
                $likelyDone++;
            }
            if ($status === CommitmentEffectiveStatus::Detected->value) {
                $detected++;
            }
            if ($deadlineAt === null) {
                $withoutDeadline++;
                if ($deadlineRaw !== '') {
                    $vagueDeadline++;
                }
            }
            if (! $hasPerson) {
                $withoutPerson++;
            }
            if ($hasPerson) {
                $assigned++;
                if (in_array($personId, $ownerIds, true) || $this->nameMatches($ownerName, (string) ($row['person_name'] ?? ''))) {
                    $ownerAssigned++;
                }
            }
        }

        $actionItems = 0;
        $actionsWithoutOwner = 0;
        $actionsWithoutDeadline = 0;
        $actionsVague = 0;
        $decisionLike = 0;
        $decisionsWithoutAction = 0;
        $openQuestions = 0;
        $unresolvedIdentities = 0;
        $analyzedMeetings = 0;
        $topicMeetings = [];
        $decisionFingerprints = [];
        $projectsWithBlockers = [];
        $ownerActionOwners = 0;

        foreach ($meetings as $meeting) {
            if (! is_array($meeting)) {
                continue;
            }
            if (($meeting['analyzed'] ?? false) === true) {
                $analyzedMeetings++;
            }
            $actions = is_array($meeting['action_items'] ?? null) ? $meeting['action_items'] : [];
            $decisions = is_array($meeting['decisions'] ?? null) ? $meeting['decisions'] : [];
            $decisionLike += count($decisions);
            if ($decisions !== [] && $actions === []) {
                $decisionsWithoutAction++;
            }
            $openQuestions += (int) ($meeting['open_questions'] ?? 0);
            $unresolvedIdentities += count(is_array($meeting['unresolved_identities'] ?? null) ? $meeting['unresolved_identities'] : []);
            $projectId = isset($meeting['project_id']) ? (int) $meeting['project_id'] : 0;
            if (((int) ($meeting['risks_count'] ?? 0)) > 0 && $projectId > 0) {
                $projectsWithBlockers[$projectId] = true;
            }

            foreach ($actions as $action) {
                if (! is_array($action)) {
                    continue;
                }
                $actionItems++;
                $owner = trim((string) ($action['owner'] ?? ''));
                if ($owner === '' || $this->isVagueOwner($owner)) {
                    $actionsWithoutOwner++;
                    if ($projectId > 0) {
                        $projectsWithBlockers[$projectId] = true;
                    }
                } elseif ($this->nameMatches($ownerName, $owner)) {
                    $ownerActionOwners++;
                }
                $deadlineAt = trim((string) ($action['deadline_at'] ?? ''));
                $deadlineRaw = trim((string) ($action['deadline_raw'] ?? ''));
                if ($deadlineAt === '' && $deadlineRaw === '') {
                    $actionsWithoutDeadline++;
                }
                if ($this->isVagueAction((string) ($action['task'] ?? ''))) {
                    $actionsVague++;
                }
            }

            foreach (is_array($meeting['topics'] ?? null) ? $meeting['topics'] : [] as $topic) {
                $key = $this->normalize((string) $topic);
                if ($key === '') {
                    continue;
                }
                $topicMeetings[$key]['label'] = (string) $topic;
                $topicMeetings[$key]['meeting_ids'][] = (int) $meeting['id'];
            }
            foreach ($decisions as $decision) {
                if (! is_array($decision)) {
                    continue;
                }
                $key = $this->normalize((string) ($decision['text'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $decisionFingerprints[$key]['label'] = (string) $decision['text'];
                $decisionFingerprints[$key]['meeting_ids'][] = (int) $meeting['id'];
            }
        }

        foreach ($commitments as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['status'] ?? '') === CommitmentEffectiveStatus::Overdue->value && isset($row['project_id']) && (int) $row['project_id'] > 0) {
                $projectsWithBlockers[(int) $row['project_id']] = true;
            }
        }

        $reopenedTopics = 0;
        $reopenedTopicRefs = [];
        foreach ($topicMeetings as $row) {
            $ids = array_values(array_unique($row['meeting_ids'] ?? []));
            if (count($ids) >= 2) {
                $reopenedTopics++;
                $reopenedTopicRefs[] = [
                    'label' => (string) ($row['label'] ?? ''),
                    'meeting_ids' => $ids,
                ];
            }
        }

        $reopenedDecisions = 0;
        $reopenedDecisionRefs = [];
        foreach ($decisionFingerprints as $row) {
            $ids = array_values(array_unique($row['meeting_ids'] ?? []));
            if (count($ids) >= 2) {
                $reopenedDecisions++;
                $reopenedDecisionRefs[] = [
                    'label' => (string) ($row['label'] ?? ''),
                    'meeting_ids' => $ids,
                ];
            }
        }

        $maxActive = 0;
        $maxActivePersonId = null;
        foreach ($activeByPerson as $id => $count) {
            if ($count > $maxActive) {
                $maxActive = $count;
                $maxActivePersonId = (int) $id;
            }
        }

        $maxDueWindow = 0;
        $maxDuePersonId = null;
        foreach ($dueSoonByPerson as $id => $count) {
            if ($count > $maxDueWindow) {
                $maxDueWindow = $count;
                $maxDuePersonId = (int) $id;
            }
        }

        $repeatOverduePersonId = null;
        $repeatOverdueCount = 0;
        foreach ($overdueByPerson as $id => $count) {
            if ($count > $repeatOverdueCount) {
                $repeatOverdueCount = $count;
                $repeatOverduePersonId = (int) $id;
            }
        }

        $projectsWithoutOwner = 0;
        foreach ($projects as $project) {
            if (is_array($project) && empty($project['owner_person_id'])) {
                $projectsWithoutOwner++;
            }
        }

        $ownerCoverage = $actionItems > 0
            ? (int) round((($actionItems - $actionsWithoutOwner) / $actionItems) * 100)
            : null;
        $deadlineCoverage = $actionItems > 0
            ? (int) round((($actionItems - $actionsWithoutDeadline) / $actionItems) * 100)
            : null;
        $reviewable = $total - $this->countStatus($commitments, [CommitmentEffectiveStatus::Cancelled->value, CommitmentEffectiveStatus::Discarded->value]);
        $confirmationPct = $reviewable > 0 ? (int) round(($confirmed / $reviewable) * 100) : null;
        $overdueRatio = $open > 0 ? round($overdue / $open, 2) : null;
        $followupGap = $overdue > 0 ? round($overdueNoFollowup / $overdue, 2) : null;
        $ownerShare = $assigned > 0 ? round($ownerAssigned / $assigned, 2) : null;

        $personNames = [];
        foreach ($people as $person) {
            if (is_array($person)) {
                $personNames[(int) ($person['id'] ?? 0)] = (string) ($person['display_name'] ?? '');
            }
        }

        return [
            'commitments_total' => $total,
            'commitments_open' => $open,
            'commitments_overdue' => $overdue,
            'commitments_confirmed' => $confirmed,
            'commitments_without_deadline' => $withoutDeadline,
            'commitments_vague_deadline' => $vagueDeadline,
            'commitments_without_person' => $withoutPerson,
            'commitments_likely_done' => $likelyDone,
            'commitments_detected' => $detected,
            'likely_done_unconfirmed' => $likelyDone,
            'detected_unreviewed' => $detected,
            'meeting_action_items' => $actionItems,
            'meeting_actions_without_owner' => $actionsWithoutOwner,
            'meeting_actions_without_deadline' => $actionsWithoutDeadline,
            'meeting_actions_vague' => $actionsVague,
            'decision_like_items' => $decisionLike,
            'decisions_without_action' => $decisionsWithoutAction,
            'reopened_topics' => $reopenedTopics,
            'reopened_decisions' => $reopenedDecisions,
            'open_questions' => $openQuestions,
            'unresolved_identities' => $unresolvedIdentities,
            'projects_with_blockers' => count($projectsWithBlockers),
            'projects_without_owner' => $projectsWithoutOwner,
            'automation_blocked_count' => count($automations),
            'meetings_total' => count($meetings),
            'meetings_analyzed' => $analyzedMeetings,
            'owner_assigned_commitments' => $ownerAssigned,
            'assigned_commitments' => $assigned,
            'owner_action_items' => $ownerActionOwners,
            'owner_coverage_pct' => $ownerCoverage,
            'deadline_coverage_pct' => $deadlineCoverage,
            'commitment_confirmation_pct' => $confirmationPct,
            'overdue_ratio' => $overdueRatio,
            'followup_gap_ratio' => $followupGap,
            'owner_dependency_share' => $ownerShare,
            'workload_max_active' => $maxActive,
            'workload_person_id' => $maxActivePersonId,
            'workload_due_window' => $maxDueWindow,
            'workload_due_person_id' => $maxDuePersonId,
            'repeat_overdue_count' => $repeatOverdueCount,
            'repeat_overdue_person_id' => $repeatOverduePersonId,
            'sample_commitments' => $total,
            'sample_meetings' => count($meetings),
            'sample_actions' => $actionItems,
            'reopened_topic_refs' => $reopenedTopicRefs,
            'reopened_decision_refs' => $reopenedDecisionRefs,
            'blocked_project_ids' => array_map('intval', array_keys($projectsWithBlockers)),
            'person_names' => $personNames,
            'overdue_no_followup' => $overdueNoFollowup,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $commitments
     * @param  list<string>  $statuses
     */
    private function countStatus(array $commitments, array $statuses): int
    {
        $count = 0;
        foreach ($commitments as $row) {
            if (is_array($row) && in_array((string) ($row['status'] ?? ''), $statuses, true)) {
                $count++;
            }
        }

        return $count;
    }

    public function isVagueOwner(string $owner): bool
    {
        $normalized = $this->normalize($owner);

        return $normalized === '' || in_array($normalized, [
            'tbd', 'tba', 'n/a', 'na', 'someone', 'ктось', 'хтось', 'кто-то', 'кто то', '-', 'tbc',
        ], true);
    }

    public function isVagueAction(string $task): bool
    {
        $normalized = $this->normalize($task);
        if ($normalized === '' || mb_strlen($normalized) < 8) {
            return true;
        }

        return (bool) preg_match('/^(discuss|talk|check|follow ?up|look into|todo|tbd|обговорить|уточнити|перевірити|подумать|обсудить|поговорити)$/u', $normalized);
    }

    public function nameMatches(string $left, string $right): bool
    {
        $a = $this->normalize($left);
        $b = $this->normalize($right);

        return $a !== '' && $b !== '' && ($a === $b || str_contains($a, $b) || str_contains($b, $a));
    }

    public function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : $time;
    }
}
