<?php

namespace App\Support;

use App\Models\Competition;
use App\Models\Registration;
use Illuminate\Support\Collection;

/**
 * Построитель матрицы «Сводной таблицы оценок судей»
 * (страница ScoresSummary и PDF scores-summary).
 *
 * Учитывает сценарий судейства турнира (R-2.11):
 *  - простой (simple): все судьи в одной группе, «Среднее» = trimmedMean
 *    по всем оценкам (R-4.6: при 3+ оценках отбрасываются одна мин. и одна макс.);
 *  - A/B (R-4.18–R-4.20): судьи сгруппированы по панелям A/B, в каждой панели
 *    своё среднее по всем оценкам панели (без отбрасывания крайних, R-4.19),
 *    итог расчёта = среднее A + среднее B (JudgingCalculator::abTotal).
 *
 * Панель судьи берётся из назначения в бригаде (competition_user.panel) —
 * как на пультах; для старых данных без назначения — из самой оценки
 * (scores.panel). Оценка, выставленная не в своей функции, показывается,
 * но в расчёт не идёт (как и на пультах).
 *
 * Правило R-3.14 (уточнение заказчика 08.10): код сбавки, нажатый только одним
 * судьёй панели A (даже дважды), в среднем A не учитывается (как и на пульте
 * старшего судьи) — засчитываются только коды, замеченные несколькими судьями;
 * в ячейках показываются сохранённые оценки судей.
 */
class ScoresSummaryMatrix
{
    /** Группа судей без назначенной функции (также — вся простая схема). */
    public const GROUP_NONE = 'none';

    /**
     * @return array{
     *     isAb: bool,
     *     groupsOrder: array<int, string>,
     *     judgesByGroup: array<string, Collection>,
     *     rows: array<int, array>,
     * }|null
     */
    public static function build(?Competition $competition): ?array
    {
        if (! $competition) {
            return null;
        }

        $isAb = $competition->isAbScheme();

        $registrations = Registration::query()
            ->where('competition_id', $competition->id)
            ->where('is_completed', true)
            ->with(['athlete', 'athlete.club', 'partner', 'style', 'ageGroup', 'scores.judge', 'scores.deductions'])
            ->join('styles', 'registrations.style_id', '=', 'styles.id')
            ->join('age_groups', 'registrations.age_group_id', '=', 'age_groups.id')
            ->join('athletes', 'registrations.athlete_id', '=', 'athletes.id')
            ->orderBy('styles.sort_order')
            ->orderBy('age_groups.sort_order')
            ->orderBy('athletes.gender')
            ->orderBy('registrations.sort_order')
            ->select('registrations.*')
            ->get();

        // Только судьи, реально оценившие хоть кого-то.
        $judgesById = [];
        foreach ($registrations as $reg) {
            foreach ($reg->scores as $score) {
                if ($score->judge && ! isset($judgesById[$score->judge_id])) {
                    $judgesById[$score->judge_id] = $score->judge;
                }
            }
        }

        $judgePanels = self::resolveJudgePanels($competition, $judgesById, $registrations, $isAb);

        // Порядок колонок: панель A, панель B, без функции; внутри — по имени.
        $groupsOrder = $isAb
            ? [Competition::PANEL_A, Competition::PANEL_B, self::GROUP_NONE]
            : [self::GROUP_NONE];

        $judgesByGroup = [];
        foreach ($groupsOrder as $group) {
            $judgesByGroup[$group] = collect($judgesById)
                ->filter(fn ($judge, int $jid) => ($judgePanels[$jid] ?? self::GROUP_NONE) === $group)
                ->sortBy('name')
                ->values();
        }

        $rows = [];
        foreach ($registrations as $reg) {
            $cells = [];
            $counted = [];

            foreach ($judgesById as $jid => $judge) {
                $score = $reg->scores->firstWhere('judge_id', $jid);
                $val = $score?->score;
                $cells[$jid] = $val !== null ? (float) $val : null;

                if ($val === null) {
                    $counted[$jid] = false;
                } elseif (! $isAb) {
                    $counted[$jid] = true;
                } else {
                    // Правило R-4.18: засчитывается оценка, выставленная в своей
                    // функции (scores.panel совпадает с назначением в бригаде).
                    $sp = $score->panel;
                    $counted[$jid] = $sp === null || $sp === $judgePanels[$jid];
                }
            }

            // Уточнение заказчика 08.10 (R-3.14): код сбавки, нажатый только одним
            // судьёй панели A (даже дважды), не учитывается в вычете — код должен
            // быть замечен несколькими судьями.
            // В ячейках — сохранённые оценки судей, в среднем A — пересчитанные.
            $effectiveA = [];
            if ($isAb) {
                $judgeScoresA = [];
                foreach ($judgesByGroup[Competition::PANEL_A] as $judge) {
                    $jid = $judge->id;
                    if (($cells[$jid] ?? null) !== null && ($counted[$jid] ?? false)) {
                        $sc = $reg->scores->firstWhere('judge_id', $jid);
                        $judgeScoresA[$jid] = [
                            'score' => $cells[$jid],
                            'deductions' => $sc->deductions->map(fn ($d) => ['code' => (string) $d->code, 'value' => (float) $d->value])->values()->all(),
                        ];
                    }
                }

                if ($judgeScoresA !== []) {
                    $effectiveA = JudgingCalculator::confirmedPanelScores($judgeScoresA)['scores'];
                }
            }

            $groups = [];
            foreach ($groupsOrder as $group) {
                $values = [];
                foreach ($judgesByGroup[$group] as $judge) {
                    $jid = $judge->id;
                    if (($cells[$jid] ?? null) !== null && ($counted[$jid] ?? false)) {
                        $values[$jid] = $group === Competition::PANEL_A && isset($effectiveA[$jid])
                            ? $effectiveA[$jid]
                            : $cells[$jid];
                    }
                }
                $groups[$group] = self::groupStats($values, ! $isAb);
            }

            $avg = null;
            if ($isAb) {
                // Правило R-4.20: итог расчёта = среднее A + среднее B.
                $a = $groups[Competition::PANEL_A]['avg'];
                $b = $groups[Competition::PANEL_B]['avg'];
                $avg = ($a !== null && $b !== null) ? JudgingCalculator::abTotal($a, $b) : null;
            } else {
                $avg = $groups[self::GROUP_NONE]['avg'];
            }

            $rows[] = [
                'reg' => $reg,
                'cells' => $cells,
                'counted' => $counted,
                'groups' => $groups,
                'avg' => $avg,
                'final' => $reg->final_score !== null ? (float) $reg->final_score : null,
            ];
        }

        return [
            'isAb' => $isAb,
            'groupsOrder' => $groupsOrder,
            'judgesByGroup' => $judgesByGroup,
            'rows' => $rows,
        ];
    }

    /**
     * Панель каждого судьи (только для A/B): назначение в бригаде, иначе —
     * функция из его оценок, иначе группа «без функции». Используется также
     * в CompetitionAnalyticsBuilder (авто-расчёт A/B).
     *
     * @param  array<int, \App\Models\User>  $judgesById
     * @param  Collection<int, Registration>  $registrations
     * @return array<int, string>
     */
    public static function resolveJudgePanels(Competition $competition, array $judgesById, Collection $registrations, bool $isAb): array
    {
        $panels = [];

        $pivotPanels = [];
        foreach ($competition->judges()->get() as $judge) {
            $panel = $judge->pivot->panel ?? null;
            if (in_array($panel, [Competition::PANEL_A, Competition::PANEL_B], true)) {
                $pivotPanels[$judge->id] = $panel;
            }
        }

        foreach ($judgesById as $jid => $judge) {
            if (! $isAb) {
                $panels[$jid] = self::GROUP_NONE;

                continue;
            }

            $panel = $pivotPanels[$jid] ?? null;

            if ($panel === null) {
                foreach ($registrations as $reg) {
                    $sp = $reg->scores->firstWhere('judge_id', $jid)?->panel;
                    if (in_array($sp, [Competition::PANEL_A, Competition::PANEL_B], true)) {
                        $panel = $sp;
                        break;
                    }
                }
            }

            $panels[$jid] = $panel ?? self::GROUP_NONE;
        }

        return $panels;
    }

    /**
     * Среднее группы. В простом сценарии — trimmedMean и судьи с отброшенными
     * мин./макс.; в A/B — среднее по всем оценкам без отбрасывания (R-4.19).
     *
     * @param  array<int, float>  $values  judge_id => оценка
     * @param  bool  $trim  отбрасывать одну мин. и одну макс. при 3+ оценках
     * @return array{avg: float|null, minJudgeId: int|null, maxJudgeId: int|null}
     */
    private static function groupStats(array $values, bool $trim): array
    {
        $avg = $trim
            ? JudgingCalculator::trimmedMean(array_values($values))['avg']
            : JudgingCalculator::panelMean(array_values($values))['avg'];

        $minJudgeId = null;
        $maxJudgeId = null;

        if ($trim && count($values) >= 3) {
            $minVal = min($values);
            foreach ($values as $jid => $v) {
                if ($v === $minVal) {
                    $minJudgeId = $jid;
                    break;
                }
            }

            $maxVal = max($values);
            foreach ($values as $jid => $v) {
                if ($v === $maxVal && $jid !== $minJudgeId) {
                    $maxJudgeId = $jid;
                    break;
                }
            }
        }

        return ['avg' => $avg, 'minJudgeId' => $minJudgeId, 'maxJudgeId' => $maxJudgeId];
    }
}
