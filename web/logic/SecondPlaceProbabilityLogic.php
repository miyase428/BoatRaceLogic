<?php

/**
 * 120通りの最終出目確率から、指定した頭コース条件の2着確率を取り出す共通ロジック。
 *
 * 現在の「イン1着時 2連単」で使っている考え方（③ AI_FINAL）を、
 * 表示側・最終予想側の両方から再利用できる形へ切り出す。
 *
 * 重要:
 * - このクラス自身は TrifectaProbabilityLogic の重みや確率を変更しない。
 * - 120通りの final probability を条件付き2着分布へ集約するだけ。
 * - headCourse=1 が、検証済みの「イン1着時 2連単」と同じ定義。
 * - headCourse!=1 も計算自体は可能だが、最終予想へ本番適用する前に別途検証する。
 */
class SecondPlaceProbabilityLogic
{
    /**
     * @param array $trifectaData TrifectaProbabilityLogic::calculate() の戻り値
     * @param int   $headCourse   1着と仮定するコース
     *
     * @return array{
     *   status:string,
     *   error:string,
     *   head_course:int,
     *   head_boat:int,
     *   base_mass:float,
     *   ai_mass:float,
     *   rows:array,
     *   probability_by_course:array,
     *   probability_by_boat:array,
     *   ranked_second_boats:array
     * }
     */
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
            $empty['error'] = (string)($trifectaData['error'] ?? '120通り確率が未計算です');
            return $empty;
        }

        $trifectaRows = is_array($trifectaData['rows'] ?? null)
            ? $trifectaData['rows']
            : [];
        $boatByCourse = is_array($trifectaData['boat_by_course'] ?? null)
            ? $trifectaData['boat_by_course']
            : [];

        if (count($trifectaRows) !== 120) {
            $empty['error'] = '120通り確率が揃っていません';
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

        $headBoat = (int)$courseMap[$headCourse];
        $baseBySecondCourse = [];
        $aiBySecondCourse = [];
        for ($course = 1; $course <= 6; $course++) {
            if ($course === $headCourse) {
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
