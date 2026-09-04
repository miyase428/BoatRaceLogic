<?php

/**
 * 3連単の最終出目確率から、指定した頭コース条件の2着確率を取り出す共通ロジック。
 *
 * 通常6艇=120通り、実質5艇立て=60通りのどちらでも同じ考え方で集約する。
 */
class SecondPlaceProbabilityLogic
{
    public function calculate(array $trifectaData, int $headCourse = 1): array
    {
        $empty = [
            'status' => 'error',
            'error' => '',
            'head_course' => $headCourse,
            'head_boat' => 0,
            'base_mass' => 0.0,
            'ai_mass' => 0.0,
            'rows' => [],
            'probability_by_course' => [],
            'probability_by_boat' => [],
            'ranked_second_boats' => [],
        ];

        if ($headCourse < 1 || $headCourse > 6) {
            $empty['error'] = '頭コースが不正です';
            return $empty;
        }

        if ((string)($trifectaData['status'] ?? '') !== 'ok') {
            $empty['error'] = (string)($trifectaData['error'] ?? '3連単出目確率が未計算です');
            return $empty;
        }

        $trifectaRows = is_array($trifectaData['rows'] ?? null)
            ? $trifectaData['rows']
            : [];
        $boatByCourse = is_array($trifectaData['boat_by_course'] ?? null)
            ? $trifectaData['boat_by_course']
            : [];
        $activeBoats = is_array($trifectaData['active_boats'] ?? null)
            ? array_values(array_unique(array_filter(
                array_map('intval', $trifectaData['active_boats']),
                static fn(int $boat): bool => $boat >= 1 && $boat <= 6
            )))
            : range(1, 6);
        sort($activeBoats, SORT_NUMERIC);

        $activeCount = count($activeBoats);
        $expectedCount = (int)($trifectaData['outcome_count'] ?? 0);
        if ($expectedCount <= 0 && $activeCount >= 3) {
            $expectedCount = $activeCount * ($activeCount - 1) * ($activeCount - 2);
        }
        if ($expectedCount <= 0 || count($trifectaRows) !== $expectedCount) {
            $empty['error'] = '3連単出目確率が必要件数そろっていません';
            return $empty;
        }

        $courseMap = [];
        for ($course = 1; $course <= 6; $course++) {
            $boat = (int)($boatByCourse[$course] ?? 0);
            if ($boat < 1 || $boat > 6 || in_array($boat, $courseMap, true)) {
                $empty['error'] = '進入マップが不完全です';
                return $empty;
            }
            $courseMap[$course] = $boat;
        }

        $activeSet = array_fill_keys($activeBoats, true);
        $headBoat = (int)$courseMap[$headCourse];
        if (!isset($activeSet[$headBoat])) {
            $empty['error'] = '指定頭コースの艇は欠場扱いです';
            return $empty;
        }

        $baseBySecondCourse = [];
        $aiBySecondCourse = [];
        for ($course = 1; $course <= 6; $course++) {
            if ($course === $headCourse) {
                continue;
            }
            $secondBoat = (int)$courseMap[$course];
            if (!isset($activeSet[$secondBoat])) {
                continue;
            }
            $baseBySecondCourse[$course] = 0.0;
            $aiBySecondCourse[$course] = 0.0;
        }

        $baseMass = 0.0;
        $aiMass = 0.0;

        foreach ($trifectaRows as $row) {
            $courses = is_array($row['courses'] ?? null) ? $row['courses'] : [];
            if (count($courses) !== 3 || (int)$courses[0] !== $headCourse) {
                continue;
            }

            $secondCourse = (int)$courses[1];
            if (!array_key_exists($secondCourse, $aiBySecondCourse)) {
                continue;
            }

            $baseP = max(0.0, (float)($row['base_probability'] ?? 0.0));
            $aiP = max(0.0, (float)($row['probability'] ?? 0.0));

            $baseBySecondCourse[$secondCourse] += $baseP;
            $aiBySecondCourse[$secondCourse] += $aiP;
            $baseMass += $baseP;
            $aiMass += $aiP;
        }

        if ($aiMass <= 0.0) {
            $empty['error'] = '指定頭コースのAI確率質量がありません';
            return $empty;
        }

        $rows = [];
        $probabilityByCourse = [];
        $probabilityByBoat = [];

        foreach ($aiBySecondCourse as $secondCourse => $aiRaw) {
            $secondBoat = (int)$courseMap[$secondCourse];
            $base = $baseMass > 0.0
                ? (float)$baseBySecondCourse[$secondCourse] / $baseMass
                : 0.0;
            $ai = (float)$aiRaw / $aiMass;

            $probabilityByCourse[$secondCourse] = $ai;
            $probabilityByBoat[$secondBoat] = $ai;

            $rows[] = [
                'second_course' => (int)$secondCourse,
                'head_course' => $headCourse,
                'head_boat' => $headBoat,
                'second_boat' => $secondBoat,
                'base' => $base,
                'ai' => $ai,
                'delta' => $ai - $base,
                'ai_rank' => 0,
            ];
        }

        $ranked = $rows;
        usort($ranked, static function (array $a, array $b): int {
            $cmp = ((float)$b['ai']) <=> ((float)$a['ai']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $courseCmp = ((int)$a['second_course']) <=> ((int)$b['second_course']);
            if ($courseCmp !== 0) {
                return $courseCmp;
            }
            return ((int)$a['second_boat']) <=> ((int)$b['second_boat']);
        });

        $rankByBoat = [];
        $rankedSecondBoats = [];
        foreach ($ranked as $idx => $row) {
            $boat = (int)$row['second_boat'];
            $rankByBoat[$boat] = $idx + 1;
            $rankedSecondBoats[] = $boat;
        }

        foreach ($rows as &$row) {
            $row['ai_rank'] = (int)($rankByBoat[(int)$row['second_boat']] ?? 0);
        }
        unset($row);

        ksort($probabilityByCourse);
        ksort($probabilityByBoat);

        return [
            'status' => 'ok',
            'error' => '',
            'head_course' => $headCourse,
            'head_boat' => $headBoat,
            'base_mass' => $baseMass,
            'ai_mass' => $aiMass,
            'rows' => $rows,
            'probability_by_course' => $probabilityByCourse,
            'probability_by_boat' => $probabilityByBoat,
            'ranked_second_boats' => $rankedSecondBoats,
        ];
    }
}
