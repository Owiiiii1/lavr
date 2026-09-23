<?php

namespace App\Services\Meetings;

/**
 * Turns extracted meeting facts into an executive review.
 *
 * Counts, coverage, dedupe, and superseded filtering are deterministic.
 * Wording is a grounded template, not a score and not a personality judgment.
 */
final class MeetingReviewComposer
{
    public const SCHEMA_VERSION = 2;

    /**
     * @param  array<string, mixed>  $extraction
     * @param  array{id: int, name: string}|null  $subject
     * @param  list<array{person_id?: int|null, display_name?: string|null}>  $participants
     * @return array<string, mixed>
     */
    public function compose(
        array $extraction,
        ?array $subject,
        bool $subjectMatched,
        bool $generateLeadership,
        string $locale = 'en',
        int $unresolvedParticipants = 0,
        int $commitmentsCreated = 0,
        array $participants = [],
    ): array {
        $locale = in_array($locale, ['en', 'ru', 'uk'], true) ? $locale : 'en';
        $actions = $this->annotateActions($this->rows($extraction['action_items'] ?? []));
        $commitments = $this->dedupeCommitments($this->rows($extraction['commitments_detected'] ?? []), $actions);
        $decisions = $this->rows($extraction['decisions'] ?? []);
        $questions = $this->rows($extraction['open_questions'] ?? []);
        $risks = $this->rows($extraction['risks'] ?? []);
        $followUps = $this->rows($extraction['follow_ups'] ?? []);

        [$questions, $risks] = $this->resolveAgainstDecisions($questions, $risks, $decisions);

        $currentActions = $this->current($actions);
        $currentDecisions = $this->current($decisions);
        $currentQuestions = $this->current($questions);
        $currentRisks = $this->rankRisks($this->current($risks));
        $primaryCommitments = array_values(array_filter(
            $this->current($commitments),
            fn (array $row): bool => empty($row['duplicate_of_action']),
        ));

        $subjectName = $subject['name'] ?? null;
        $subjectActions = $subjectName === null
            ? []
            : array_values(array_filter($currentActions, fn (array $row): bool => $this->attributedTo($row, $subjectName, $participants, $subject['id'] ?? null)));

        $metrics = $this->metrics($currentActions, $primaryCommitments, $currentDecisions, $currentQuestions, $subjectActions, $subjectName);
        $leadershipStatus = $this->leadershipStatus($subject, $subjectMatched, $generateLeadership);
        $indicators = $leadershipStatus === 'completed'
            ? $this->indicators($metrics, $subjectActions, $currentQuestions, $locale, (string) $subjectName)
            : [];
        $strengths = $leadershipStatus === 'completed' ? $this->strengths($metrics, $subjectActions, $locale, (string) $subjectName) : [];
        $improvements = $leadershipStatus === 'completed' ? $this->improvements($metrics, $subjectActions, $locale, (string) $subjectName) : [];
        $recommendations = $leadershipStatus === 'completed' ? $this->recommendations($metrics, $locale) : [];

        $review = [
            'schema_version' => self::SCHEMA_VERSION,
            'leadership_status' => $leadershipStatus,
            'review_subject' => $subject,
            'subject_matched' => $subjectMatched,
            'main_insight' => $this->insight($metrics, $locale),
            'metrics' => $metrics,
            'indicators' => $indicators,
            'strengths' => array_slice($strengths, 0, 5),
            'improvements' => array_slice($improvements, 0, 5),
            'recommendations' => array_slice($recommendations, 0, 3),
            'decisions' => $currentDecisions,
            'actions' => $currentActions,
            'risks' => $currentRisks,
            'open_questions' => $currentQuestions,
            'follow_ups' => $this->linkFollowUps($this->current($followUps), $currentActions, $currentQuestions, $extraction['likely_project'] ?? null),
            'commitments' => [
                'detected' => count($primaryCommitments),
                'duplicates_hidden' => count($commitments) - count($primaryCommitments),
                'need_review' => count(array_filter($primaryCommitments, fn (array $row): bool => ($row['confidence'] ?? 'medium') !== 'high' || blank($row['person_name'] ?? $row['person_ref'] ?? null))),
            ],
            'assistant' => [
                'commitments_created' => $commitmentsCreated,
                'commitments_need_confirmation' => count(array_filter($primaryCommitments, fn (array $row): bool => ($row['confidence'] ?? 'medium') !== 'high')),
                'actions_without_deadline' => $metrics['actions_without_deadline'],
                'unresolved_participants' => $unresolvedParticipants,
            ],
        ];

        $this->assertNoPersonalityLanguage($review);
        $this->assertNoScore($review);

        $extraction['action_items'] = $actions;
        $extraction['commitments_detected'] = $commitments;
        $extraction['decisions'] = $decisions;
        $extraction['open_questions'] = $questions;
        $extraction['risks'] = $risks;
        $extraction['follow_ups'] = $followUps;
        $extraction['review'] = $review;

        return $extraction;
    }

    public function namesMatch(?string $left, ?string $right): bool
    {
        $left = $this->normalizeName($left);
        $right = $this->normalizeName($right);

        return $left !== '' && $left === $right;
    }

    /**
     * @param  list<array{person_id?: int|null, display_name?: string|null}>  $participants
     * @param  list<string>  $mentioned
     */
    public function subjectIsMatched(string $name, int $personId, array $participants, array $mentioned = []): bool
    {
        foreach ($participants as $participant) {
            if ((int) ($participant['person_id'] ?? 0) === $personId) {
                return true;
            }

            if ($this->namesMatch($name, $participant['display_name'] ?? null)) {
                return true;
            }
        }

        foreach ($mentioned as $label) {
            if ($this->namesMatch($name, $label)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $items = [];

        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['_order'] = $index;
            $row['current'] = $this->isCurrent($row);
            $items[] = $row;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isCurrent(array $row): bool
    {
        $kind = mb_strtolower((string) ($row['kind'] ?? ''));

        if (in_array($kind, ['discussion_only', 'hypothesis', 'brainstorm'], true)) {
            return false;
        }

        $state = mb_strtolower((string) ($row['state'] ?? $row['resolution'] ?? ''));

        if (in_array($state, ['superseded', 'resolved'], true)) {
            return false;
        }

        if (! empty($row['superseded']) || ! empty($row['resolved_by_later_context'])) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function annotateActions(array $actions): array
    {
        foreach ($actions as $index => $row) {
            $actions[$index]['missing_owner'] = blank($row['owner'] ?? null);
            $actions[$index]['missing_deadline'] = blank($row['deadline_at'] ?? null) && blank($row['deadline_raw'] ?? null);
        }

        return $actions;
    }

    /**
     * @param  list<array<string, mixed>>  $commitments
     * @param  list<array<string, mixed>>  $actions
     * @return list<array<string, mixed>>
     */
    private function dedupeCommitments(array $commitments, array $actions): array
    {
        foreach ($commitments as $index => $commitment) {
            $text = (string) ($commitment['action'] ?? '');

            foreach ($actions as $action) {
                if (! ($action['current'] ?? true)) {
                    continue;
                }

                if ($this->overlaps($text, (string) ($action['task'] ?? ''))) {
                    $commitments[$index]['duplicate_of_action'] = true;
                    $commitments[$index]['current'] = false;

                    break;
                }
            }
        }

        return $commitments;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array<string, mixed>>  $risks
     * @param  list<array<string, mixed>>  $decisions
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function resolveAgainstDecisions(array $questions, array $risks, array $decisions): array
    {
        foreach ($decisions as $decision) {
            if (! ($decision['current'] ?? false)) {
                continue;
            }

            $questions = $this->supersedeEarlier($questions, $decision, 'text');
            $risks = $this->supersedeEarlier($risks, $decision, 'text');
        }

        return [$questions, $risks];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $decision
     * @return list<array<string, mixed>>
     */
    private function supersedeEarlier(array $rows, array $decision, string $textKey): array
    {
        $decisionText = (string) ($decision['text'] ?? '');

        foreach ($rows as $index => $row) {
            if (! ($row['current'] ?? false)) {
                continue;
            }

            if (! $this->isEarlier($row, $decision)) {
                continue;
            }

            if (! $this->overlaps((string) ($row[$textKey] ?? ''), $decisionText)) {
                continue;
            }

            $rows[$index]['current'] = false;
            $rows[$index]['state'] = 'superseded';
            $rows[$index]['resolved_by_later_context'] = true;
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function current(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row): bool => (bool) ($row['current'] ?? false)));
    }

    /**
     * @param  list<array<string, mixed>>  $risks
     * @return list<array<string, mixed>>
     */
    private function rankRisks(array $risks): array
    {
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];

        usort($risks, function (array $left, array $right) use ($rank): int {
            $leftRank = $rank[mb_strtolower((string) ($left['severity'] ?? 'medium'))] ?? 1;
            $rightRank = $rank[mb_strtolower((string) ($right['severity'] ?? 'medium'))] ?? 1;

            return $leftRank <=> $rightRank;
        });

        return $risks;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $commitments
     * @param  list<array<string, mixed>>  $decisions
     * @param  list<array<string, mixed>>  $questions
     * @param  list<array<string, mixed>>  $subjectActions
     * @return array<string, int|float>
     */
    private function metrics(array $actions, array $commitments, array $decisions, array $questions, array $subjectActions, ?string $subjectName): array
    {
        $withOwner = count(array_filter($actions, fn (array $row): bool => empty($row['missing_owner'])));
        $withDeadline = count(array_filter($actions, fn (array $row): bool => empty($row['missing_deadline'])));
        $total = count($actions);
        $delegated = $subjectName === null
            ? 0
            : count(array_filter($actions, function (array $row) use ($subjectName): bool {
                $owner = $this->normalizeName($row['owner'] ?? null);

                return $owner !== '' && $owner !== $this->normalizeName($subjectName);
            }));
        $subjectWithDeadline = count(array_filter($subjectActions, fn (array $row): bool => empty($row['missing_deadline'])));
        $commitmentsWithDeadline = count(array_filter(
            $commitments,
            fn (array $row): bool => filled($row['deadline_at'] ?? null) || filled($row['deadline_raw'] ?? null),
        ));

        return [
            'actions_total' => $total,
            'actions_with_owner' => $withOwner,
            'actions_without_owner' => $total - $withOwner,
            'actions_with_deadline' => $withDeadline,
            'actions_without_deadline' => $total - $withDeadline,
            'commitments_total' => count($commitments),
            'commitments_with_deadline' => $commitmentsWithDeadline,
            'open_questions_end' => count($questions),
            'decisions_total' => count($decisions),
            'delegated_actions' => $delegated,
            'review_subject_actions' => count($subjectActions),
            'review_subject_actions_without_deadline' => count($subjectActions) - $subjectWithDeadline,
            'owner_coverage_pct' => $total === 0 ? 0 : (int) round(($withOwner / $total) * 100),
            'deadline_coverage_pct' => $total === 0 ? 0 : (int) round(($withDeadline / $total) * 100),
            'delegation_pct' => $total === 0 ? 0 : (int) round(($delegated / $total) * 100),
        ];
    }

    /**
     * @param  array{id: int, name: string}|null  $subject
     */
    private function leadershipStatus(?array $subject, bool $subjectMatched, bool $generateLeadership): string
    {
        if (! $generateLeadership || $subject === null) {
            return 'skipped';
        }

        if (! $subjectMatched) {
            return 'pending_subject';
        }

        return 'completed';
    }

    /**
     * @param  array<string, int|float>  $metrics
     * @param  list<array<string, mixed>>  $subjectActions
     * @param  list<array<string, mixed>>  $questions
     * @return list<array{key: string, status: string, reason: string}>
     */
    private function indicators(array $metrics, array $subjectActions, array $questions, string $locale, string $name): array
    {
        $owned = (int) $metrics['review_subject_actions'];
        $missingDeadline = (int) $metrics['review_subject_actions_without_deadline'];

        return [
            $this->indicator('task_clarity', $this->taskStatus($subjectActions), $this->taskReason($subjectActions, $locale, $name)),
            $this->indicator('owner_clarity', $owned === 0 ? 'insufficient_data' : 'good', $owned === 0
                ? $this->t($locale, 'insufficient_subject')
                : $this->t($locale, 'owner_clear', ['name' => $name, 'count' => $owned])),
            $this->indicator('deadline_clarity', $this->deadlineStatus($owned, $missingDeadline), $this->deadlineReason($owned, $missingDeadline, $locale, $name)),
            $this->indicator('decision_closure', count($questions) === 0 ? 'good' : 'insufficient_data', count($questions) === 0
                ? $this->t($locale, 'questions_closed')
                : $this->t($locale, 'questions_unattributed', ['count' => count($questions)])),
            $this->indicator('follow_up', $owned === 0 ? 'insufficient_data' : ($missingDeadline > 0 ? 'needs_attention' : 'good'), $owned === 0
                ? $this->t($locale, 'insufficient_subject')
                : $this->t($locale, 'follow_up_reason', ['missing' => $missingDeadline, 'count' => $owned])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $subjectActions
     */
    private function taskStatus(array $subjectActions): string
    {
        if (count($subjectActions) < 2) {
            return 'insufficient_data';
        }

        $clear = count(array_filter($subjectActions, fn (array $row): bool => mb_strlen(trim((string) ($row['task'] ?? ''))) >= 20));

        return ($clear / count($subjectActions)) >= 0.7 ? 'good' : 'needs_attention';
    }

    /**
     * @param  list<array<string, mixed>>  $subjectActions
     */
    private function taskReason(array $subjectActions, string $locale, string $name): string
    {
        if (count($subjectActions) < 2) {
            return $this->t($locale, 'insufficient_subject');
        }

        return $this->t($locale, 'task_reason', ['name' => $name, 'count' => count($subjectActions)]);
    }

    private function deadlineStatus(int $owned, int $missing): string
    {
        if ($owned < 2) {
            return 'insufficient_data';
        }

        if ($missing === 0) {
            return 'good';
        }

        return ($missing / $owned) >= 0.4 ? 'needs_attention' : 'good';
    }

    private function deadlineReason(int $owned, int $missing, string $locale, string $name): string
    {
        if ($owned < 2) {
            return $this->t($locale, 'insufficient_subject');
        }

        return $this->t($locale, 'deadline_reason', [
            'missing' => $missing,
            'count' => $owned,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<string, int|float>  $metrics
     * @param  list<array<string, mixed>>  $subjectActions
     * @return list<array<string, mixed>>
     */
    private function strengths(array $metrics, array $subjectActions, string $locale, string $name): array
    {
        $items = [];

        if ((int) $metrics['actions_total'] >= 3 && (int) $metrics['delegation_pct'] >= 50) {
            $items[] = [
                'key' => 'delegation',
                'title' => $this->t($locale, 'delegation_title'),
                'observation' => $this->t($locale, 'delegation_body', [
                    'delegated' => $metrics['delegated_actions'],
                    'count' => $metrics['actions_total'],
                    'name' => $name,
                ]),
                'evidence' => $this->evidenceSample($subjectActions !== [] ? $subjectActions : []),
            ];
        }

        if ((int) $metrics['decisions_total'] > 0 && (int) $metrics['open_questions_end'] === 0) {
            $items[] = [
                'key' => 'decision_closure',
                'title' => $this->t($locale, 'closure_title'),
                'observation' => $this->t($locale, 'closure_body', ['count' => $metrics['decisions_total']]),
                'evidence' => [],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, int|float>  $metrics
     * @param  list<array<string, mixed>>  $subjectActions
     * @return list<array<string, mixed>>
     */
    private function improvements(array $metrics, array $subjectActions, string $locale, string $name): array
    {
        $items = [];
        $owned = (int) $metrics['review_subject_actions'];
        $missing = (int) $metrics['review_subject_actions_without_deadline'];

        if ($owned >= 2 && $missing > 0 && ($missing / $owned) >= 0.4) {
            $sample = null;

            foreach ($subjectActions as $action) {
                if (! empty($action['missing_deadline'])) {
                    $sample = $action;

                    break;
                }
            }

            $items[] = [
                'key' => 'deadline_clarity',
                'title' => $this->t($locale, 'deadline_title'),
                'observation' => $this->t($locale, 'deadline_reason', ['missing' => $missing, 'count' => $owned, 'name' => $name]),
                'suggestion' => $this->t($locale, 'deadline_suggestion'),
                'evidence' => $sample === null ? [] : [$this->excerpt($sample)],
            ];
        }

        if ($owned >= 4 && ($owned / max(1, (int) $metrics['actions_total'])) >= 0.7) {
            $items[] = [
                'key' => 'owner_dependency',
                'title' => $this->t($locale, 'dependency_title'),
                'observation' => $this->t($locale, 'dependency_body', ['name' => $name, 'count' => $owned, 'total' => $metrics['actions_total']]),
                'suggestion' => $this->t($locale, 'dependency_suggestion'),
                'evidence' => [],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, int|float>  $metrics
     * @return list<string>
     */
    private function recommendations(array $metrics, string $locale): array
    {
        $items = [];

        if ((int) $metrics['review_subject_actions_without_deadline'] > 0 || (int) $metrics['actions_without_deadline'] > 0) {
            $items[] = $this->t($locale, 'rec_close');
        }

        if ((int) $metrics['open_questions_end'] > 0) {
            $items[] = $this->t($locale, 'rec_decide');
        }

        if ((int) $metrics['actions_total'] > 0) {
            $items[] = $this->t($locale, 'rec_recap');
        }

        return array_slice($items, 0, 3);
    }

    /**
     * @param  array<string, int|float>  $metrics
     */
    private function insight(array $metrics, string $locale): string
    {
        if ((int) $metrics['actions_total'] === 0) {
            return $this->t($locale, 'insight_empty', ['decisions' => $metrics['decisions_total']]);
        }

        if ((int) $metrics['actions_without_deadline'] === 0) {
            return $this->t($locale, 'insight_clear', [
                'actions' => $metrics['actions_total'],
                'decisions' => $metrics['decisions_total'],
            ]);
        }

        return $this->t($locale, 'insight_deadlines', [
            'actions' => $metrics['actions_total'],
            'missing' => $metrics['actions_without_deadline'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $followUps
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $questions
     * @return list<array<string, mixed>>
     */
    private function linkFollowUps(array $followUps, array $actions, array $questions, mixed $project): array
    {
        $projectName = is_string($project) ? $project : null;

        foreach ($followUps as $index => $row) {
            $text = (string) ($row['text'] ?? '');
            $row['project'] = $row['project'] ?? $projectName;
            $row['person'] = $row['person'] ?? $row['owner'] ?? null;
            $row['commitment_ref'] = $row['commitment_ref'] ?? null;
            $row['question_ref'] = $row['question_ref'] ?? null;

            foreach ($actions as $action) {
                if ($this->overlaps($text, (string) ($action['task'] ?? ''))) {
                    $row['person'] = $row['person'] ?? ($action['owner'] ?? null);

                    break;
                }
            }

            foreach ($questions as $question) {
                if ($this->overlaps($text, (string) ($question['text'] ?? ''))) {
                    $row['question_ref'] = $question['text'] ?? null;

                    break;
                }
            }

            $followUps[$index] = $row;
        }

        return $followUps;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{person_id?: int|null, display_name?: string|null}>  $participants
     */
    private function attributedTo(array $row, string $subjectName, array $participants, ?int $subjectId): bool
    {
        if ($this->namesMatch($subjectName, $row['owner'] ?? null)) {
            return true;
        }

        $speaker = is_array($row['evidence'] ?? null) ? ($row['evidence']['speaker'] ?? null) : null;

        if ($this->namesMatch($subjectName, is_string($speaker) ? $speaker : null)) {
            return true;
        }

        if (! empty($row['led_by_subject'])) {
            return true;
        }

        foreach ($participants as $participant) {
            if ($subjectId !== null && (int) ($participant['person_id'] ?? 0) === $subjectId && $this->namesMatch($participant['display_name'] ?? null, $row['owner'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function overlaps(string $left, string $right): bool
    {
        $leftTokens = $this->tokens($left);
        $rightTokens = $this->tokens($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $shared = array_intersect($leftTokens, $rightTokens);

        if (count($shared) < 2) {
            return false;
        }

        return (count($shared) / min(count($leftTokens), count($rightTokens))) >= 0.4;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $stop = ['this', 'that', 'with', 'from', 'have', 'will', 'then', 'это', 'что', 'как', 'для', 'или', 'если', 'надо', 'нужно', 'буде', 'будем', 'тогда'];

        return array_values(array_unique(array_filter(
            $parts,
            fn (string $token): bool => mb_strlen($token) >= 4 && ! in_array($token, $stop, true),
        )));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $later
     */
    private function isEarlier(array $row, array $later): bool
    {
        $rowClock = $this->clockMinutes($row);
        $laterClock = $this->clockMinutes($later);

        if ($rowClock !== null && $laterClock !== null) {
            return $rowClock < $laterClock;
        }

        return (int) ($row['_order'] ?? 0) < (int) ($later['_order'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function clockMinutes(array $row): ?int
    {
        $timestamp = is_array($row['evidence'] ?? null) ? ($row['evidence']['timestamp'] ?? null) : null;
        $timestamp = is_string($timestamp) ? $timestamp : (is_string($row['timestamp'] ?? null) ? $row['timestamp'] : '');

        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?/', $timestamp, $match) !== 1) {
            return null;
        }

        return ((int) $match[1] * 60) + (int) $match[2];
    }

    private function normalizeName(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }

    /**
     * @return array{key: string, status: string, reason: string}
     */
    private function indicator(string $key, string $status, string $reason): array
    {
        return [
            'key' => $key,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function evidenceSample(array $rows): array
    {
        $sample = [];

        foreach (array_slice($rows, 0, 2) as $row) {
            $excerpt = $this->excerpt($row);

            if ($excerpt['excerpt'] !== null) {
                $sample[] = $excerpt;
            }
        }

        return $sample;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{excerpt: string|null, speaker: string|null, timestamp: string|null}
     */
    private function excerpt(array $row): array
    {
        $evidence = is_array($row['evidence'] ?? null) ? $row['evidence'] : [];
        $text = $evidence['excerpt'] ?? $row['task'] ?? $row['text'] ?? null;

        return [
            'excerpt' => is_string($text) ? mb_substr(trim($text), 0, 180) : null,
            'speaker' => is_string($evidence['speaker'] ?? null) ? $evidence['speaker'] : (is_string($row['owner'] ?? null) ? $row['owner'] : null),
            'timestamp' => is_string($evidence['timestamp'] ?? null) ? $evidence['timestamp'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private function assertNoPersonalityLanguage(array $review): void
    {
        $blob = mb_strtolower(json_encode($review, JSON_UNESCAPED_UNICODE) ?: '');
        $banned = ['lazy', 'toxic', 'weak leader', 'chaotic personality', 'psychological', 'unmotivated'];

        foreach ($banned as $word) {
            if (str_contains($blob, $word)) {
                $review['main_insight'] = 'Meeting facts are ready. Personal wording was withheld.';
            }
        }
    }

    /**
     * @param  array<string, mixed>  $review
     */
    private function assertNoScore(array $review): void
    {
        unset($review['score'], $review['rating'], $review['overall_score']);
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private function t(string $locale, string $key, array $replace = []): string
    {
        $catalog = [
            'en' => [
                'insight_deadlines' => 'The meeting produced :actions concrete actions, and :missing of them have no explicit deadline. The strongest improvement is to close each agreement with an owner, an expected result, and a deadline.',
                'insight_clear' => 'The meeting produced :actions actions and :decisions decisions. Recorded actions include both an owner and a deadline.',
                'insight_empty' => 'The meeting recorded :decisions decisions and no separate operational actions.',
                'insufficient_subject' => 'Not enough actions are tied to the selected person.',
                'owner_clear' => ':count actions are tied to :name by name or by who said them.',
                'task_reason' => ':count actions tied to :name were checked for a concrete result.',
                'deadline_reason' => ':missing of :count actions accepted by :name have no explicit deadline.',
                'deadline_title' => 'Deadline clarity',
                'deadline_suggestion' => 'Close it with an owner, a result, and a clock time.',
                'follow_up_reason' => ':missing of :count actions tied to this person still have no deadline to follow up.',
                'questions_closed' => 'No operational question remained open at the end.',
                'questions_unattributed' => ':count questions stayed open, without a clear link to the selected person.',
                'delegation_title' => 'Delegation',
                'delegation_body' => ':delegated of :count operational actions were assigned to someone other than :name.',
                'closure_title' => 'Decision closure',
                'closure_body' => ':count decisions stayed current, and no operational question remained open.',
                'dependency_title' => 'Owner dependency',
                'dependency_body' => ':count of :total actions stayed with :name.',
                'dependency_suggestion' => 'Name another owner before the topic changes.',
                'rec_close' => 'Close each action with an owner, an expected result, and a deadline.',
                'rec_decide' => 'Before changing topic, ask whether this question is decided.',
                'rec_recap' => 'End the meeting with a two-minute recap of owners and deadlines.',
            ],
            'ru' => [
                'insight_deadlines' => 'Встреча дала :actions конкретных действий, и у :missing из них нет явного срока. Самое полезное улучшение — закрывать каждую договорённость владельцем, результатом и сроком.',
                'insight_clear' => 'Встреча дала :actions действий и :decisions решений. У записанных действий есть и владелец, и срок.',
                'insight_empty' => 'На встрече зафиксировано решений: :decisions. Отдельных операционных действий нет.',
                'insufficient_subject' => 'С выбранным человеком связано слишком мало действий.',
                'owner_clear' => 'С :name связано действий: :count — по имени владельца или по тому, кто это сказал.',
                'task_reason' => 'Проверено действий, связанных с :name: :count. Смотрели, назван ли конкретный результат.',
                'deadline_reason' => 'У :missing из :count действий, которые принял :name, нет явного срока.',
                'deadline_title' => 'Ясность сроков',
                'deadline_suggestion' => 'Закрывать фразой: кто, какой результат и к какому времени.',
                'follow_up_reason' => 'У :missing из :count действий этого человека всё ещё нет срока для проверки.',
                'questions_closed' => 'К концу встречи операционных вопросов не осталось.',
                'questions_unattributed' => 'Открытых вопросов: :count. Связи с выбранным человеком недостаточно.',
                'delegation_title' => 'Делегирование',
                'delegation_body' => ':delegated из :count операционных действий назначены не :name.',
                'closure_title' => 'Закрытие решений',
                'closure_body' => 'Актуальных решений: :count. Операционных вопросов на конец встречи не осталось.',
                'dependency_title' => 'Зависимость от ведущего',
                'dependency_body' => ':count из :total действий остались на :name.',
                'dependency_suggestion' => 'Называть другого владельца до смены темы.',
                'rec_close' => 'Закрывать каждое действие: владелец, результат и срок.',
                'rec_decide' => 'Перед сменой темы спрашивать: это уже решено?',
                'rec_recap' => 'Заканчивать встречу двухминутным повтором владельцев и сроков.',
            ],
            'uk' => [
                'insight_deadlines' => 'Зустріч дала :actions конкретних дій, і в :missing із них немає явного строку. Найкорисніше покращення — закривати кожну домовленість власником, результатом і строком.',
                'insight_clear' => 'Зустріч дала :actions дій і :decisions рішень. У записаних дій є і власник, і строк.',
                'insight_empty' => 'На зустрічі зафіксовано рішень: :decisions. Окремих операційних дій немає.',
                'insufficient_subject' => 'З обраною людиною пов’язано замало дій.',
                'owner_clear' => 'З :name пов’язано дій: :count — за ім’ям власника або за тим, хто це сказав.',
                'task_reason' => 'Перевірено дій, пов’язаних із :name: :count. Дивились, чи названо конкретний результат.',
                'deadline_reason' => 'У :missing із :count дій, які прийняв :name, немає явного строку.',
                'deadline_title' => 'Ясність строків',
                'deadline_suggestion' => 'Закривати фразою: хто, який результат і до якого часу.',
                'follow_up_reason' => 'У :missing із :count дій цієї людини досі немає строку для перевірки.',
                'questions_closed' => 'На кінець зустрічі операційних питань не лишилося.',
                'questions_unattributed' => 'Відкритих питань: :count. Зв’язку з обраною людиною недостатньо.',
                'delegation_title' => 'Делегування',
                'delegation_body' => ':delegated із :count операційних дій призначено не :name.',
                'closure_title' => 'Закриття рішень',
                'closure_body' => 'Актуальних рішень: :count. Операційних питань на кінець зустрічі не лишилося.',
                'dependency_title' => 'Залежність від ведучого',
                'dependency_body' => ':count із :total дій лишилися на :name.',
                'dependency_suggestion' => 'Називати іншого власника до зміни теми.',
                'rec_close' => 'Закривати кожну дію: власник, результат і строк.',
                'rec_decide' => 'Перед зміною теми питати: це вже вирішено?',
                'rec_recap' => 'Закінчувати зустріч двохвилинним повтором власників і строків.',
            ],
        ];

        $template = $catalog[$locale][$key] ?? $catalog['en'][$key] ?? $key;

        foreach ($replace as $name => $value) {
            $template = str_replace(':'.$name, (string) $value, $template);
        }

        return $template;
    }
}
