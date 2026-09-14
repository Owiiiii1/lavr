<?php

namespace App\Services\LeadershipReview;

use App\Enums\LeadershipFindingCategory;
use App\Enums\LeadershipFindingConfidence;
use App\Enums\LeadershipFindingSeverity;
use App\Enums\OwnerLocale;

final class LeadershipReviewFindings
{
    public function __construct(
        private readonly LeadershipReviewMetrics $metricsEngine = new LeadershipReviewMetrics,
        private readonly LeadershipReviewWordingGuard $guard = new LeadershipReviewWordingGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>|null  $previousMetrics
     * @return array{
     *     findings: list<array<string, mixed>>,
     *     strengths: list<array<string, mixed>>,
     *     attention: list<array<string, mixed>>,
     *     trends: list<array<string, mixed>>,
     *     insufficient_trend: bool
     * }
     */
    public function build(array $collected, array $metrics, ?array $previousMetrics, OwnerLocale $locale): array
    {
        $findings = [];
        $findings = array_merge($findings, $this->ownership($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->deadlines($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->clarity($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->followUp($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->reliability($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->meetings($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->decisions($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->ownerDependency($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->workload($collected, $metrics, $locale));
        $findings = array_merge($findings, $this->bottlenecks($collected, $metrics, $locale));

        $trends = $this->trends($metrics, $previousMetrics, $locale);
        $insufficientTrend = (bool) ($trends['insufficient'] ?? false);
        $trendRows = is_array($trends['items'] ?? null) ? $trends['items'] : [];

        $strengths = array_merge($this->strengths($metrics, $locale), array_values(array_filter(
            $trendRows,
            static fn (array $row): bool => ($row['kind'] ?? '') === 'improvement',
        )));

        $attention = array_values(array_filter(
            $findings,
            function (array $finding): bool {
                $severity = (string) ($finding['severity'] ?? '');

                return in_array($severity, [
                    LeadershipFindingSeverity::Critical->value,
                    LeadershipFindingSeverity::High->value,
                    LeadershipFindingSeverity::Medium->value,
                ], true);
            },
        ));

        usort($attention, function (array $left, array $right): int {
            return $this->severityRank($right['severity'] ?? '') <=> $this->severityRank($left['severity'] ?? '');
        });

        return [
            'findings' => $findings,
            'strengths' => $strengths,
            'attention' => $attention,
            'trends' => $trendRows,
            'insufficient_trend' => $insufficientTrend,
        ];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function ownership(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $withoutOwner = (int) ($metrics['meeting_actions_without_owner'] ?? 0);
        $actions = (int) ($metrics['meeting_action_items'] ?? 0);
        $withoutPerson = (int) ($metrics['commitments_without_person'] ?? 0);
        $coverage = $metrics['owner_coverage_pct'];
        $out = [];

        if ($withoutOwner > 0 && $actions > 0) {
            $severity = $this->coverageSeverity($coverage, $actions);
            $refs = $this->actionRefs($collected, static fn (array $action): bool => trim((string) ($action['owner'] ?? '')) === '');
            $out[] = $this->finding(
                LeadershipFindingCategory::Ownership,
                $severity,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Action items without owner',
                    OwnerLocale::Ru => 'Action items без владельца',
                    default => 'Action items без власника',
                },
                match ($locale) {
                    OwnerLocale::En => 'Of '.$actions.' action items, '.$withoutOwner.' have no owner.',
                    OwnerLocale::Ru => 'Из '.$actions.' action items у '.$withoutOwner.' нет владельца.',
                    default => 'З '.$actions.' action items у '.$withoutOwner.' немає власника.',
                },
                $refs,
                ['name' => 'meeting_actions_without_owner', 'value' => $withoutOwner],
                match ($locale) {
                    OwnerLocale::En => 'Record an owner for each action item at the next meeting.',
                    OwnerLocale::Ru => 'На следующей встрече фиксировать владельца для каждого action item.',
                    default => 'На наступній зустрічі фіксувати owner для кожного action item.',
                },
                $this->guard->sampleConfidence($actions),
            );
        }

        if ($withoutPerson > 0 && (int) ($metrics['commitments_total'] ?? 0) >= 1) {
            $refs = $this->commitmentRefs($collected, static fn (array $row): bool => empty($row['person_id']) || ! empty($row['unresolved_person']));
            $out[] = $this->finding(
                LeadershipFindingCategory::Ownership,
                $withoutPerson >= 2 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Commitments with unresolved owner',
                    OwnerLocale::Ru => 'Обязательства с неясным владельцем',
                    default => 'Зобов’язання з невизначеним власником',
                },
                match ($locale) {
                    OwnerLocale::En => $withoutPerson.' commitments have no resolved person.',
                    OwnerLocale::Ru => 'У '.$withoutPerson.' обязательств нет подтверждённого человека.',
                    default => 'У '.$withoutPerson.' зобов’язань немає підтвердженої людини.',
                },
                $refs,
                ['name' => 'commitments_without_person', 'value' => $withoutPerson],
                match ($locale) {
                    OwnerLocale::En => 'Assign a person or mark the identity as unresolved for follow-up.',
                    OwnerLocale::Ru => 'Назначить человека или явно оставить identity для follow-up.',
                    default => 'Призначити людину або явно лишити identity для follow-up.',
                },
                $this->guard->sampleConfidence((int) ($metrics['commitments_total'] ?? 0)),
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function deadlines(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $out = [];
        $without = (int) ($metrics['commitments_without_deadline'] ?? 0);
        $actionsMissing = (int) ($metrics['meeting_actions_without_deadline'] ?? 0);
        $actions = (int) ($metrics['meeting_action_items'] ?? 0);
        $total = (int) ($metrics['commitments_total'] ?? 0);

        if ($without > 0 && $total > 0) {
            $refs = $this->commitmentRefs($collected, static fn (array $row): bool => empty($row['deadline_at']));
            $out[] = $this->finding(
                LeadershipFindingCategory::Deadlines,
                $without >= 4 || ($total >= 3 && $without / max(1, $total) >= 0.4) ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Commitments without a deadline',
                    OwnerLocale::Ru => 'Обязательства без срока',
                    default => 'Зобов’язання без дедлайну',
                },
                match ($locale) {
                    OwnerLocale::En => $without.' of '.$total.' commitments have no deadline.',
                    OwnerLocale::Ru => 'У '.$without.' из '.$total.' обязательств нет срока.',
                    default => 'У '.$without.' з '.$total.' зобов’язань немає дедлайну.',
                },
                $refs,
                ['name' => 'commitments_without_deadline', 'value' => $without],
                match ($locale) {
                    OwnerLocale::En => 'Add a calendar date for each open commitment.',
                    OwnerLocale::Ru => 'Добавить календарную дату для каждого открытого обязательства.',
                    default => 'Додати календарну дату для кожного відкритого зобов’язання.',
                },
                $this->guard->sampleConfidence($total),
            );
        }

        if ($actionsMissing > 0 && $actions > 0) {
            $refs = $this->actionRefs($collected, static fn (array $action): bool => trim((string) ($action['deadline_at'] ?? '')) === '' && trim((string) ($action['deadline_raw'] ?? '')) === '');
            $out[] = $this->finding(
                LeadershipFindingCategory::Deadlines,
                $this->coverageSeverity($metrics['deadline_coverage_pct'], $actions),
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Meeting actions without a deadline',
                    OwnerLocale::Ru => 'Action items без срока',
                    default => 'Action items без строку',
                },
                match ($locale) {
                    OwnerLocale::En => 'Of '.$actions.' action items, only '.($actions - $actionsMissing).' have a deadline.',
                    OwnerLocale::Ru => 'Из '.$actions.' action items срок есть только у '.($actions - $actionsMissing).'.',
                    default => 'З '.$actions.' action items строк мають лише '.($actions - $actionsMissing).'.',
                },
                $refs,
                ['name' => 'meeting_actions_without_deadline', 'value' => $actionsMissing],
                match ($locale) {
                    OwnerLocale::En => 'Capture a deadline for every action item before the meeting closes.',
                    OwnerLocale::Ru => 'Фиксировать срок для каждого action item до конца встречи.',
                    default => 'Фіксувати строк для кожного action item до кінця зустрічі.',
                },
                $this->guard->sampleConfidence($actions),
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function clarity(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $vague = (int) ($metrics['meeting_actions_vague'] ?? 0);
        $actions = (int) ($metrics['meeting_action_items'] ?? 0);
        if ($vague === 0 || $actions === 0) {
            return [];
        }

        $refs = $this->actionRefs($collected, fn (array $action): bool => $this->metricsEngine->isVagueAction((string) ($action['task'] ?? '')));

        return [$this->finding(
            LeadershipFindingCategory::Clarity,
            $vague >= 3 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
            $locale,
            match ($locale) {
                OwnerLocale::En => 'Vague action wording',
                OwnerLocale::Ru => 'Размытые формулировки действий',
                default => 'Розмиті формулювання дій',
            },
            match ($locale) {
                OwnerLocale::En => $vague.' action items lack a concrete next action.',
                OwnerLocale::Ru => 'У '.$vague.' action items нет конкретного следующего действия.',
                default => 'У '.$vague.' action items немає конкретної наступної дії.',
            },
            $refs,
            ['name' => 'meeting_actions_vague', 'value' => $vague],
            match ($locale) {
                OwnerLocale::En => 'Rewrite each item as a verb plus expected result.',
                OwnerLocale::Ru => 'Переформулировать каждый пункт как действие и ожидаемый результат.',
                default => 'Переформулювати кожен пункт як дію і очікуваний результат.',
            },
            $this->guard->sampleConfidence($actions),
        )];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function followUp(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $out = [];
        $gap = (int) ($metrics['overdue_no_followup'] ?? 0);
        $likely = (int) ($metrics['likely_done_unconfirmed'] ?? 0);
        $detected = (int) ($metrics['detected_unreviewed'] ?? 0);

        if ($gap > 0) {
            $refs = $this->commitmentRefs($collected, static fn (array $row): bool => ($row['status'] ?? '') === 'overdue' && empty($row['last_notified_at']));
            $out[] = $this->finding(
                LeadershipFindingCategory::FollowUp,
                $gap >= 2 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Overdue commitments without follow-up',
                    OwnerLocale::Ru => 'Просроченные обязательства без follow-up',
                    default => 'Прострочені зобов’язання без follow-up',
                },
                match ($locale) {
                    OwnerLocale::En => $gap.' overdue commitments have no recorded follow-up.',
                    OwnerLocale::Ru => 'У '.$gap.' просроченных обязательств нет зафиксированного follow-up.',
                    default => 'У '.$gap.' прострочених зобов’язань немає зафіксованого follow-up.',
                },
                $refs,
                ['name' => 'overdue_no_followup', 'value' => $gap],
                match ($locale) {
                    OwnerLocale::En => 'Review overdue items and record confirmation or a new date.',
                    OwnerLocale::Ru => 'Проверить просроченные пункты и зафиксировать подтверждение или новую дату.',
                    default => 'Перевірити прострочені пункти і зафіксувати підтвердження або нову дату.',
                },
                $this->guard->sampleConfidence((int) ($metrics['commitments_overdue'] ?? 0)),
            );
        }

        if ($likely > 0) {
            $refs = $this->commitmentRefs($collected, static fn (array $row): bool => ($row['status'] ?? '') === 'likely_done');
            $out[] = $this->finding(
                LeadershipFindingCategory::FollowUp,
                LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Likely-done items not confirmed',
                    OwnerLocale::Ru => 'Likely done без подтверждения',
                    default => 'Likely done без підтвердження',
                },
                match ($locale) {
                    OwnerLocale::En => $likely.' commitments are likely done and still unconfirmed.',
                    OwnerLocale::Ru => $likely.' обязательств помечены likely done и не подтверждены.',
                    default => $likely.' зобов’язань позначені likely done і не підтверджені.',
                },
                $refs,
                ['name' => 'likely_done_unconfirmed', 'value' => $likely],
                match ($locale) {
                    OwnerLocale::En => 'Confirm or reopen each likely-done commitment.',
                    OwnerLocale::Ru => 'Подтвердить или вернуть в работу каждый likely-done пункт.',
                    default => 'Підтвердити або повернути в роботу кожне likely-done зобов’язання.',
                },
                $this->guard->sampleConfidence($likely),
            );
        }

        if ($detected > 0) {
            $refs = $this->commitmentRefs($collected, static fn (array $row): bool => ($row['status'] ?? '') === 'detected');
            $out[] = $this->finding(
                LeadershipFindingCategory::FollowUp,
                $detected >= 3 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Detected commitments never reviewed',
                    OwnerLocale::Ru => 'Detected обязательства не разобраны',
                    default => 'Detected зобов’язання не розібрані',
                },
                match ($locale) {
                    OwnerLocale::En => $detected.' detected commitments are still unreviewed.',
                    OwnerLocale::Ru => $detected.' detected обязательств ещё не разобраны.',
                    default => $detected.' detected зобов’язань ще не розібрані.',
                },
                $refs,
                ['name' => 'detected_unreviewed', 'value' => $detected],
                match ($locale) {
                    OwnerLocale::En => 'Confirm or discard the detected commitments.',
                    OwnerLocale::Ru => 'Подтвердить или отклонить detected обязательства.',
                    default => 'Підтвердити або відхилити detected зобов’язання.',
                },
                $this->guard->sampleConfidence($detected),
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function reliability(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $repeat = (int) ($metrics['repeat_overdue_count'] ?? 0);
        $personId = isset($metrics['repeat_overdue_person_id']) ? (int) $metrics['repeat_overdue_person_id'] : 0;
        if ($repeat < 2 || $personId <= 0) {
            return [];
        }

        $name = (string) (($metrics['person_names'][$personId] ?? '') ?: '#'.$personId);
        $totals = $this->personCommitmentCounts($collected, $personId);
        $refs = $this->commitmentRefs($collected, static fn (array $row): bool => (int) ($row['person_id'] ?? 0) === $personId && ($row['status'] ?? '') === 'overdue');

        return [$this->finding(
            LeadershipFindingCategory::CommitmentReliability,
            $repeat >= 3 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
            $locale,
            match ($locale) {
                OwnerLocale::En => 'Repeated overdue pattern',
                OwnerLocale::Ru => 'Повторяющийся паттерн просрочек',
                default => 'Повторюваний патерн прострочень',
            },
            match ($locale) {
                OwnerLocale::En => 'In this period '.$name.': '.$totals['total'].' commitments, '.$totals['overdue'].' overdue, '.$totals['confirmed'].' confirmed, '.$totals['likely'].' likely done.',
                OwnerLocale::Ru => 'За период у '.$name.': '.$totals['total'].' обязательств, '.$totals['overdue'].' просрочено, '.$totals['confirmed'].' подтверждено, '.$totals['likely'].' likely done.',
                default => 'За період у '.$name.': '.$totals['total'].' зобов’язань, '.$totals['overdue'].' прострочено, '.$totals['confirmed'].' підтверджено, '.$totals['likely'].' likely done.',
            },
            $refs,
            ['name' => 'repeat_overdue_count', 'value' => $repeat],
            match ($locale) {
                OwnerLocale::En => 'Review dates and follow-up cadence for this set of commitments.',
                OwnerLocale::Ru => 'Пересмотреть сроки и ритм follow-up по этому набору обязательств.',
                default => 'Переглянути строки і ритм follow-up для цього набору зобов’язань.',
            },
            $this->guard->sampleConfidence($totals['total']),
            $personId,
        )];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function meetings(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $actions = (int) ($metrics['meeting_action_items'] ?? 0);
        $meetings = (int) ($metrics['meetings_analyzed'] ?? 0);
        if ($actions === 0 || $meetings === 0) {
            return [];
        }

        $ownerPct = $metrics['owner_coverage_pct'];
        $deadlinePct = $metrics['deadline_coverage_pct'];
        if (! is_int($ownerPct) && ! is_int($deadlinePct)) {
            return [];
        }

        $lowOwner = is_int($ownerPct) && $ownerPct < 70;
        $lowDeadline = is_int($deadlinePct) && $deadlinePct < 70;
        if (! $lowOwner && ! $lowDeadline) {
            return [];
        }

        $refs = $this->meetingRefs($collected, static fn (array $meeting): bool => ($meeting['analyzed'] ?? false) === true);

        return [$this->finding(
            LeadershipFindingCategory::MeetingEffectiveness,
            ($lowOwner && $lowDeadline) ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
            $locale,
            match ($locale) {
                OwnerLocale::En => 'Meeting action completeness',
                OwnerLocale::Ru => 'Полнота итогов встреч',
                default => 'Повнота підсумків зустрічей',
            },
            match ($locale) {
                OwnerLocale::En => 'Analyzed meetings: owner coverage '.(is_int($ownerPct) ? $ownerPct.'%' : '—').', deadline coverage '.(is_int($deadlinePct) ? $deadlinePct.'%' : '—').'.',
                OwnerLocale::Ru => 'По разобранным встречам: покрытие владельцами '.(is_int($ownerPct) ? $ownerPct.'%' : '—').', сроками '.(is_int($deadlinePct) ? $deadlinePct.'%' : '—').'.',
                default => 'За розібраними зустрічами: покриття власниками '.(is_int($ownerPct) ? $ownerPct.'%' : '—').', дедлайнами '.(is_int($deadlinePct) ? $deadlinePct.'%' : '—').'.',
            },
            $refs,
            ['name' => 'owner_coverage_pct', 'value' => $ownerPct],
            match ($locale) {
                OwnerLocale::En => 'Close each meeting with owner and deadline on every action item.',
                OwnerLocale::Ru => 'Закрывать каждую встречу owner и сроком на каждый action item.',
                default => 'Закривати кожну зустріч owner і строком на кожен action item.',
            },
            $this->guard->sampleConfidence($meetings),
        )];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function decisions(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $out = [];
        $without = (int) ($metrics['decisions_without_action'] ?? 0);
        if ($without > 0) {
            $refs = $this->meetingRefs($collected, static fn (array $meeting): bool => (is_array($meeting['decisions'] ?? null) ? $meeting['decisions'] : []) !== []
                && (is_array($meeting['action_items'] ?? null) ? $meeting['action_items'] : []) === []);
            $out[] = $this->finding(
                LeadershipFindingCategory::DecisionFollowthrough,
                $without >= 2 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Decisions without related actions',
                    OwnerLocale::Ru => 'Решения без связанных действий',
                    default => 'Рішення без пов’язаних дій',
                },
                match ($locale) {
                    OwnerLocale::En => $without.' meetings recorded a decision with no action item.',
                    OwnerLocale::Ru => 'В '.$without.' встречах решение записано без action item.',
                    default => 'У '.$without.' зустрічах рішення записано без action item.',
                },
                $refs,
                ['name' => 'decisions_without_action', 'value' => $without],
                match ($locale) {
                    OwnerLocale::En => 'Attach an owner and next action to each recorded decision.',
                    OwnerLocale::Ru => 'К каждому записанному решению добавить owner и следующее действие.',
                    default => 'До кожного записаного рішення додати owner і наступну дію.',
                },
                $this->guard->sampleConfidence((int) ($metrics['decision_like_items'] ?? 0)),
            );
        }

        $reopened = is_array($metrics['reopened_topic_refs'] ?? null) ? $metrics['reopened_topic_refs'] : [];
        if ($reopened !== []) {
            $ids = [];
            foreach ($reopened as $row) {
                foreach ($row['meeting_ids'] ?? [] as $id) {
                    $ids[] = (int) $id;
                }
            }
            $refs = $this->meetingRefs($collected, static fn (array $meeting): bool => in_array((int) ($meeting['id'] ?? 0), $ids, true));
            $label = (string) ($reopened[0]['label'] ?? '');
            $out[] = $this->finding(
                LeadershipFindingCategory::DecisionFollowthrough,
                LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Topic reopened across meetings',
                    OwnerLocale::Ru => 'Тема повторно открывалась на встречах',
                    default => 'Тему повторно відкривали на зустрічах',
                },
                match ($locale) {
                    OwnerLocale::En => 'The topic "'.$label.'" appeared in '.count(array_unique($ids)).' meetings.',
                    OwnerLocale::Ru => 'Тема «'.$label.'» встречалась на '.count(array_unique($ids)).' встречах.',
                    default => 'Тема «'.$label.'» з’являлась на '.count(array_unique($ids)).' зустрічах.',
                },
                $refs,
                ['name' => 'reopened_topics', 'value' => (int) ($metrics['reopened_topics'] ?? 0)],
                match ($locale) {
                    OwnerLocale::En => 'Close the topic with an owner, date, and expected result.',
                    OwnerLocale::Ru => 'Закрыть тему owner, датой и ожидаемым результатом.',
                    default => 'Закрити тему owner, датою і очікуваним результатом.',
                },
                $this->guard->sampleConfidence((int) ($metrics['meetings_analyzed'] ?? 0)),
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function ownerDependency(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $share = $metrics['owner_dependency_share'] ?? null;
        $assigned = (int) ($metrics['assigned_commitments'] ?? 0);
        $ownerAssigned = (int) ($metrics['owner_assigned_commitments'] ?? 0);
        $threshold = (float) config('leadership_review.owner_dependency_share', 0.5);
        $projectsWithout = (int) ($metrics['projects_without_owner'] ?? 0);

        $out = [];
        if (is_numeric($share) && $assigned >= 4 && (float) $share >= $threshold) {
            $pct = (int) round(((float) $share) * 100);
            $refs = $this->commitmentRefs($collected, function (array $row) use ($collected): bool {
                $ownerIds = is_array($collected['owner_person_ids'] ?? null) ? $collected['owner_person_ids'] : [];

                return in_array((int) ($row['person_id'] ?? 0), $ownerIds, true);
            });
            $out[] = $this->finding(
                LeadershipFindingCategory::OwnerDependency,
                (float) $share >= 0.7 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Commitments concentrated on Owner',
                    OwnerLocale::Ru => 'Обязательства сконцентрированы на Owner',
                    default => 'Зобов’язання сконцентровані на Owner',
                },
                match ($locale) {
                    OwnerLocale::En => $ownerAssigned.' of '.$assigned.' assigned commitments sit with Owner ('.$pct.'%).',
                    OwnerLocale::Ru => $ownerAssigned.' из '.$assigned.' назначенных обязательств на Owner ('.$pct.'%).',
                    default => $ownerAssigned.' з '.$assigned.' призначених зобов’язань на Owner ('.$pct.'%).',
                },
                $refs,
                ['name' => 'owner_dependency_share', 'value' => $share],
                match ($locale) {
                    OwnerLocale::En => 'Move at least three commitments to a delegated owner with a date.',
                    OwnerLocale::Ru => 'Передать минимум три обязательства делегированному owner со сроком.',
                    default => 'Передати щонайменше три зобов’язання делегованому owner зі строком.',
                },
                $this->guard->sampleConfidence($assigned),
            );
        }

        if ($projectsWithout > 0) {
            $refs = $this->projectRefs($collected, static fn (array $project): bool => empty($project['owner_person_id']));
            $out[] = $this->finding(
                LeadershipFindingCategory::OwnerDependency,
                LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Projects without a delegated owner',
                    OwnerLocale::Ru => 'Проекты без делегированного owner',
                    default => 'Проєкти без делегованого owner',
                },
                match ($locale) {
                    OwnerLocale::En => $projectsWithout.' projects have no delegated owner.',
                    OwnerLocale::Ru => 'У '.$projectsWithout.' проектов нет делегированного owner.',
                    default => 'У '.$projectsWithout.' проєктів немає делегованого owner.',
                },
                $refs,
                ['name' => 'projects_without_owner', 'value' => $projectsWithout],
                match ($locale) {
                    OwnerLocale::En => 'Name a delegated owner on each active project.',
                    OwnerLocale::Ru => 'Назначить делегированного owner на каждый активный проект.',
                    default => 'Призначити делегованого owner на кожен активний проєкт.',
                },
                $this->guard->sampleConfidence(count(is_array($collected['projects'] ?? null) ? $collected['projects'] : [])),
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function workload(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $max = (int) ($metrics['workload_max_active'] ?? 0);
        $personId = isset($metrics['workload_person_id']) ? (int) $metrics['workload_person_id'] : 0;
        $due = (int) ($metrics['workload_due_window'] ?? 0);
        $threshold = (int) config('leadership_review.workload_active_threshold', 8);
        $window = (int) config('leadership_review.workload_same_window', 5);
        if ($personId <= 0 || ($max < $threshold && $due < $window)) {
            return [];
        }

        $name = (string) (($metrics['person_names'][$personId] ?? '') ?: '#'.$personId);
        $refs = $this->commitmentRefs($collected, static fn (array $row): bool => (int) ($row['person_id'] ?? 0) === $personId);

        return [$this->finding(
            LeadershipFindingCategory::WorkloadConcentration,
            ($max >= $threshold && $due >= $window) ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
            $locale,
            match ($locale) {
                OwnerLocale::En => 'Commitments concentrated on one person',
                OwnerLocale::Ru => 'Обязательства сконцентрированы на одном человеке',
                default => 'Зобов’язання сконцентровані на одній людині',
            },
            match ($locale) {
                OwnerLocale::En => $name.' currently has '.$max.' active commitments'.($due > 0 ? ', '.$due.' due this week' : '').'.',
                OwnerLocale::Ru => 'У '.$name.' сейчас '.$max.' активных обязательств'.($due > 0 ? ', '.$due.' до конца недели' : '').'.',
                default => 'На '.$name.' зараз '.$max.' активних зобов’язань'.($due > 0 ? ', '.$due.' до кінця тижня' : '').'.',
            },
            $refs,
            ['name' => 'workload_max_active', 'value' => $max],
            match ($locale) {
                OwnerLocale::En => 'Redistribute commitments that share the same due window.',
                OwnerLocale::Ru => 'Перераспределить обязательства с одним окном срока.',
                default => 'Перерозподілити зобов’язання з однаковим вікном дедлайну.',
            },
            $this->guard->sampleConfidence($max),
            $personId,
        )];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function bottlenecks(array $collected, array $metrics, OwnerLocale $locale): array
    {
        $blocked = (int) ($metrics['projects_with_blockers'] ?? 0);
        $automation = (int) ($metrics['automation_blocked_count'] ?? 0);
        $out = [];
        if ($blocked > 0) {
            $ids = is_array($metrics['blocked_project_ids'] ?? null) ? $metrics['blocked_project_ids'] : [];
            $refs = $this->projectRefs($collected, static fn (array $project): bool => in_array((int) ($project['id'] ?? 0), $ids, true));
            $out[] = $this->finding(
                LeadershipFindingCategory::Bottlenecks,
                $blocked >= 2 ? LeadershipFindingSeverity::High : LeadershipFindingSeverity::Medium,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Projects with blocked actions',
                    OwnerLocale::Ru => 'Проекты с заблокированными действиями',
                    default => 'Проєкти із заблокованими діями',
                },
                match ($locale) {
                    OwnerLocale::En => $blocked.' projects have overdue commitments or meeting risks.',
                    OwnerLocale::Ru => 'У '.$blocked.' проектов есть просроченные обязательства или риски встреч.',
                    default => 'У '.$blocked.' проєктів є прострочені зобов’язання або ризики зустрічей.',
                },
                $refs,
                ['name' => 'projects_with_blockers', 'value' => $blocked],
                match ($locale) {
                    OwnerLocale::En => 'Clear the blocker or reassign the open action.',
                    OwnerLocale::Ru => 'Снять блокер или переназначить открытое действие.',
                    default => 'Зняти блокер або перепризначити відкриту дію.',
                },
                $this->guard->sampleConfidence($blocked),
            );
        }

        if ($automation > 0) {
            $refs = [];
            foreach (is_array($collected['automation_runs'] ?? null) ? $collected['automation_runs'] : [] as $run) {
                if (! is_array($run)) {
                    continue;
                }
                $refs[] = [
                    'type' => 'automation_run',
                    'id' => (int) ($run['id'] ?? 0),
                    'href' => '/automation-runs',
                    'label' => (string) ($run['automation_type'] ?? 'automation'),
                ];
            }
            $out[] = $this->finding(
                LeadershipFindingCategory::Bottlenecks,
                LeadershipFindingSeverity::Low,
                $locale,
                match ($locale) {
                    OwnerLocale::En => 'Blocked automation runs',
                    OwnerLocale::Ru => 'Заблокированные automation runs',
                    default => 'Заблоковані automation runs',
                },
                match ($locale) {
                    OwnerLocale::En => $automation.' automation runs failed or need retry in this period.',
                    OwnerLocale::Ru => $automation.' automation runs завершились ошибкой или ждут retry.',
                    default => $automation.' automation runs завершились помилкою або чекають retry.',
                },
                $refs,
                ['name' => 'automation_blocked_count', 'value' => $automation],
                match ($locale) {
                    OwnerLocale::En => 'Open Automation Runs and retry the failed jobs.',
                    OwnerLocale::Ru => 'Открыть Automation Runs и повторить failed jobs.',
                    default => 'Відкрити Automation Runs і повторити failed jobs.',
                },
                LeadershipFindingConfidence::High,
            );
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return list<array<string, mixed>>
     */
    private function strengths(array $metrics, OwnerLocale $locale): array
    {
        $sample = max((int) ($metrics['sample_actions'] ?? 0), (int) ($metrics['sample_commitments'] ?? 0), (int) ($metrics['sample_meetings'] ?? 0));
        if ($sample < LeadershipReviewMetrics::MIN_SAMPLE) {
            return [];
        }

        $rows = [];
        $ownerPct = $metrics['owner_coverage_pct'] ?? null;
        if (is_int($ownerPct) && $ownerPct >= 80) {
            $rows[] = $this->strength($locale, match ($locale) {
                OwnerLocale::En => 'High owner coverage: '.$ownerPct.'%.',
                OwnerLocale::Ru => 'Высокое покрытие владельцами: '.$ownerPct.'%.',
                default => 'Високе покриття власниками: '.$ownerPct.'%.',
            }, 'owner_coverage_pct', $ownerPct);
        }
        $deadlinePct = $metrics['deadline_coverage_pct'] ?? null;
        if (is_int($deadlinePct) && $deadlinePct >= 80) {
            $rows[] = $this->strength($locale, match ($locale) {
                OwnerLocale::En => 'High deadline coverage: '.$deadlinePct.'%.',
                OwnerLocale::Ru => 'Высокое покрытие сроками: '.$deadlinePct.'%.',
                default => 'Високе покриття дедлайнами: '.$deadlinePct.'%.',
            }, 'deadline_coverage_pct', $deadlinePct);
        }
        $confirm = $metrics['commitment_confirmation_pct'] ?? null;
        if (is_int($confirm) && $confirm >= 50 && (int) ($metrics['commitments_total'] ?? 0) >= LeadershipReviewMetrics::MIN_SAMPLE) {
            $rows[] = $this->strength($locale, match ($locale) {
                OwnerLocale::En => 'Commitments are regularly confirmed ('.$confirm.'%).',
                OwnerLocale::Ru => 'Обязательства регулярно подтверждаются ('.$confirm.'%).',
                default => 'Зобов’язання регулярно підтверджуються ('.$confirm.'%).',
            }, 'commitment_confirmation_pct', $confirm);
        }
        $overdueRatio = $metrics['overdue_ratio'] ?? null;
        if (is_numeric($overdueRatio) && (float) $overdueRatio <= 0.15 && (int) ($metrics['commitments_open'] ?? 0) >= LeadershipReviewMetrics::MIN_SAMPLE) {
            $rows[] = $this->strength($locale, match ($locale) {
                OwnerLocale::En => 'Low overdue rate among open commitments.',
                OwnerLocale::Ru => 'Низкая доля просрочки среди открытых обязательств.',
                default => 'Низька частка прострочення серед відкритих зобов’язань.',
            }, 'overdue_ratio', $overdueRatio);
        }
        $share = $metrics['owner_dependency_share'] ?? null;
        if (is_numeric($share) && (float) $share < 0.35 && (int) ($metrics['assigned_commitments'] ?? 0) >= 4) {
            $rows[] = $this->strength($locale, match ($locale) {
                OwnerLocale::En => 'Commitments are delegated across people, not concentrated on Owner.',
                OwnerLocale::Ru => 'Обязательства распределены по людям, а не сконцентрированы на Owner.',
                default => 'Зобов’язання розподілені між людьми, а не сконцентровані на Owner.',
            }, 'owner_dependency_share', $share);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>|null  $previous
     * @return array{items: list<array<string, mixed>>, insufficient: bool}
     */
    private function trends(array $metrics, ?array $previous, OwnerLocale $locale): array
    {
        $currentSample = (int) ($metrics['sample_meetings'] ?? 0) + (int) ($metrics['sample_commitments'] ?? 0);
        $previousSample = $previous === null ? 0 : ((int) ($previous['sample_meetings'] ?? 0) + (int) ($previous['sample_commitments'] ?? 0));
        if ($previous === null || $currentSample < LeadershipReviewMetrics::MIN_SAMPLE || $previousSample < LeadershipReviewMetrics::MIN_SAMPLE) {
            return [
                'items' => [[
                    'kind' => 'insufficient',
                    'title' => LeadershipReviewCopy::insufficientTrend($locale),
                    'observation' => LeadershipReviewCopy::insufficientTrend($locale),
                    'confidence' => LeadershipFindingConfidence::Low->value,
                ]],
                'insufficient' => true,
            ];
        }

        $items = [];
        foreach ([
            ['deadline_coverage_pct', LeadershipReviewCopy::deadlineLabel($locale)],
            ['owner_coverage_pct', LeadershipReviewCopy::ownerLabel($locale)],
        ] as [$key, $label]) {
            $from = $previous[$key] ?? null;
            $to = $metrics[$key] ?? null;
            if (! is_int($from) || ! is_int($to) || $from === $to) {
                continue;
            }
            $kind = $to > $from ? 'improvement' : 'deterioration';
            $observation = $kind === 'improvement'
                ? LeadershipReviewCopy::coverageImproved($locale, $label, $from, $to)
                : LeadershipReviewCopy::coverageWorsened($locale, $label, $from, $to);
            $items[] = [
                'kind' => $kind,
                'title' => $observation,
                'observation' => $observation,
                'metric' => ['name' => $key, 'from' => $from, 'to' => $to],
                'confidence' => $this->guard->sampleConfidence($currentSample)->value,
            ];
        }

        return ['items' => $items, 'insufficient' => false];
    }

    /**
     * @param  list<array<string, mixed>>  $refs
     * @param  array{name: string, value: mixed}  $metric
     * @return array<string, mixed>
     */
    private function finding(
        LeadershipFindingCategory $category,
        LeadershipFindingSeverity $severity,
        OwnerLocale $locale,
        string $title,
        string $observation,
        array $refs,
        array $metric,
        string $recommendation,
        LeadershipFindingConfidence $confidence,
        ?int $personId = null,
        ?int $projectId = null,
        ?int $meetingId = null,
    ): array {
        return [
            'id' => substr(hash('sha256', $category->value.'|'.$title.'|'.json_encode($metric)), 0, 16),
            'category' => $category->value,
            'severity' => $severity->value,
            'title' => $title,
            'observation' => $observation,
            'evidence_refs' => $refs,
            'metric' => $metric,
            'recommendation' => $recommendation,
            'confidence' => $confidence->value,
            'person_id' => $personId,
            'project_id' => $projectId,
            'meeting_id' => $meetingId,
            'locale' => $locale->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function strength(OwnerLocale $locale, string $observation, string $metric, mixed $value): array
    {
        return [
            'kind' => 'strength',
            'title' => $observation,
            'observation' => $observation,
            'metric' => ['name' => $metric, 'value' => $value],
            'confidence' => LeadershipFindingConfidence::Medium->value,
            'locale' => $locale->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  callable(array<string, mixed>): bool  $predicate
     * @return list<array<string, mixed>>
     */
    private function commitmentRefs(array $collected, callable $predicate): array
    {
        $refs = [];
        foreach (is_array($collected['commitments'] ?? null) ? $collected['commitments'] : [] as $row) {
            if (! is_array($row) || ! $predicate($row)) {
                continue;
            }
            $refs[] = [
                'type' => 'commitment',
                'id' => (int) ($row['id'] ?? 0),
                'href' => '/lavr/commitments/'.(int) ($row['id'] ?? 0),
                'label' => (string) ($row['title'] ?? ''),
            ];
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  callable(array<string, mixed>): bool  $predicate
     * @return list<array<string, mixed>>
     */
    private function actionRefs(array $collected, callable $predicate): array
    {
        $refs = [];
        foreach (is_array($collected['meetings'] ?? null) ? $collected['meetings'] : [] as $meeting) {
            if (! is_array($meeting)) {
                continue;
            }
            foreach (is_array($meeting['action_items'] ?? null) ? $meeting['action_items'] : [] as $action) {
                if (! is_array($action) || ! $predicate($action)) {
                    continue;
                }
                $refs[] = [
                    'type' => 'meeting',
                    'id' => (int) ($meeting['id'] ?? 0),
                    'href' => '/lavr/meetings/'.(int) ($meeting['id'] ?? 0),
                    'label' => (string) ($meeting['title'] ?? $action['task'] ?? ''),
                ];
                break;
            }
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  callable(array<string, mixed>): bool  $predicate
     * @return list<array<string, mixed>>
     */
    private function meetingRefs(array $collected, callable $predicate): array
    {
        $refs = [];
        foreach (is_array($collected['meetings'] ?? null) ? $collected['meetings'] : [] as $meeting) {
            if (! is_array($meeting) || ! $predicate($meeting)) {
                continue;
            }
            $refs[] = [
                'type' => 'meeting',
                'id' => (int) ($meeting['id'] ?? 0),
                'href' => '/lavr/meetings/'.(int) ($meeting['id'] ?? 0),
                'label' => (string) ($meeting['title'] ?? ''),
            ];
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $collected
     * @param  callable(array<string, mixed>): bool  $predicate
     * @return list<array<string, mixed>>
     */
    private function projectRefs(array $collected, callable $predicate): array
    {
        $refs = [];
        foreach (is_array($collected['projects'] ?? null) ? $collected['projects'] : [] as $project) {
            if (! is_array($project) || ! $predicate($project)) {
                continue;
            }
            $refs[] = [
                'type' => 'project',
                'id' => (int) ($project['id'] ?? 0),
                'href' => '/lavr/projects/'.(int) ($project['id'] ?? 0),
                'label' => (string) ($project['name'] ?? ''),
            ];
        }

        return array_slice($refs, 0, 12);
    }

    /**
     * @param  array<string, mixed>  $collected
     * @return array{total: int, overdue: int, confirmed: int, likely: int}
     */
    private function personCommitmentCounts(array $collected, int $personId): array
    {
        $total = 0;
        $overdue = 0;
        $confirmed = 0;
        $likely = 0;
        foreach (is_array($collected['commitments'] ?? null) ? $collected['commitments'] : [] as $row) {
            if (! is_array($row) || (int) ($row['person_id'] ?? 0) !== $personId) {
                continue;
            }
            $total++;
            $status = (string) ($row['status'] ?? '');
            if ($status === 'overdue') {
                $overdue++;
            }
            if ($status === 'confirmed') {
                $confirmed++;
            }
            if ($status === 'likely_done') {
                $likely++;
            }
        }

        return compact('total', 'overdue', 'confirmed', 'likely');
    }

    private function coverageSeverity(mixed $pct, int $sample): LeadershipFindingSeverity
    {
        if ($sample >= 5 && is_int($pct) && $pct === 0) {
            return LeadershipFindingSeverity::Critical;
        }
        if ($sample >= 5 && is_int($pct) && $pct < 40) {
            return LeadershipFindingSeverity::High;
        }
        if ($sample >= 3 && is_int($pct) && $pct < 70) {
            return LeadershipFindingSeverity::Medium;
        }

        return LeadershipFindingSeverity::Low;
    }

    private function severityRank(mixed $severity): int
    {
        return LeadershipFindingSeverity::tryFrom((string) $severity)?->rank() ?? 0;
    }
}
