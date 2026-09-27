<?php

declare(strict_types=1);

/**
 * 120通りの出目確率から P(3着 | 1着, 2着) を作る表示用ロジック。
 * 買い目や既存候補には書き戻さない。
 */
final class ThirdPlaceProbabilityLogic
{
    public function calculate(array $trifectaData, int $headCourse, int $secondCourse): array
    {
        $empty = ['status' => 'error', 'error' => '', 'rows' => []];
        if ($headCourse < 1 || $headCourse > 6 || $secondCourse < 1 || $secondCourse > 6 || $headCourse === $secondCourse) {
            $empty['error'] = '1着・2着コースが不正です';
            return $empty;
        }
        if ((string)($trifectaData['status'] ?? '') !== 'ok') {
            $empty['error'] = (string)($trifectaData['error'] ?? '3連単出目確率が未計算です');
            return $empty;
        }

        $boatByCourse = is_array($trifectaData['boat_by_course'] ?? null) ? $trifectaData['boat_by_course'] : [];
        $rows = is_array($trifectaData['rows'] ?? null) ? $trifectaData['rows'] : [];
        $byThird = [];
        for ($course = 1; $course <= 6; $course++) {
            if ($course !== $headCourse && $course !== $secondCourse && (int)($boatByCourse[$course] ?? 0) >= 1) {
                $byThird[$course] = 0.0;
            }
        }
        if (count($byThird) < 3) {
            $empty['error'] = '進入マップが不完全です';
            return $empty;
        }

        $mass = 0.0;
        foreach ($rows as $row) {
            $courses = is_array($row['courses'] ?? null) ? $row['courses'] : [];
            if (count($courses) !== 3 || (int)$courses[0] !== $headCourse || (int)$courses[1] !== $secondCourse) {
                continue;
            }
            $thirdCourse = (int)$courses[2];
            if (!array_key_exists($thirdCourse, $byThird)) {
                continue;
            }
            $p = max(0.0, (float)($row['probability'] ?? 0.0));
            $byThird[$thirdCourse] += $p;
            $mass += $p;
        }
        if ($mass <= 0.0) {
            $empty['error'] = '指定した1-2着の確率がありません';
            return $empty;
        }

        $result = [];
        foreach ($byThird as $course => $raw) {
            $result[] = [
                'head_course' => $headCourse,
                'second_course' => $secondCourse,
                'third_course' => $course,
                'head_boat' => (int)$boatByCourse[$headCourse],
                'second_boat' => (int)$boatByCourse[$secondCourse],
                'third_boat' => (int)$boatByCourse[$course],
                'ai' => $raw / $mass,
                'ai_rank' => 0,
            ];
        }
        usort($result, static fn(array $a, array $b): int => ((float)$b['ai'] <=> (float)$a['ai']) ?: ((int)$a['third_course'] <=> (int)$b['third_course']));
        foreach ($result as $index => &$row) {
            $row['ai_rank'] = $index + 1;
        }
        unset($row);
        return ['status' => 'ok', 'error' => '', 'rows' => $result];
    }
}
