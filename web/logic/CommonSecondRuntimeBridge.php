<?php

declare(strict_types=1);

require_once __DIR__ . '/SecondPlaceProbabilityLogic.php';
require_once __DIR__ . '/FinalSecondCandidateLogic.php';

/**
 * 120通り出目確率を共通2着確率へ変換し、
 * - 1C頭の2連単表示
 * - 現在の本命頭に対する最終予想2着候補
 * の両方へ同じ SecondPlaceProbabilityLogic を接続する橋渡し。
 *
 * 頭・kiru・3着候補は変更せず、2着候補だけを共通確率順位へ置き換える。
 */
class CommonSecondRuntimeBridge
{
    /**
     * @return array{
     *   view_data:array,
     *   head1:array,
     *   honmei:array,
     *   honmei_course:int
     * }
     */
    public function apply(
        array $viewData,
        array $finalPredictions,
        array $trifectaData
    ): array {
        $secondLogic = new SecondPlaceProbabilityLogic();

        // 「イン1着時 2連単」は常に1C頭条件。
        $head1Data = $secondLogic->calculate($trifectaData, 1);

        $honmeiHead = (int)($viewData['honmei_head'] ?? 0);
        $honmeiCourse = $this->findCourseForBoat($trifectaData, $honmeiHead);

        $honmeiData = [
            'status' => 'error',
            'error' => '本命頭の進入コースを特定できません',
            'head_course' => $honmeiCourse,
            'head_boat' => $honmeiHead,
            'rows' => [],
            'probability_by_boat' => [],
            'ranked_second_boats' => [],
        ];

        if ($honmeiCourse >= 1 && $honmeiCourse <= 6) {
            $honmeiData = $secondLogic->calculate($trifectaData, $honmeiCourse);
        }

        $finalSecondLogic = new FinalSecondCandidateLogic();
        $updated = $finalSecondLogic->applyHonmei(
            $viewData,
            $finalPredictions,
            $honmeiData
        );

        $updated['common_second_head_course'] = $honmeiCourse;
        $updated['common_second_head1_data'] = $head1Data;
        $updated['common_second_honmei_data'] = $honmeiData;

        return [
            'view_data' => $updated,
            'head1' => $head1Data,
            'honmei' => $honmeiData,
            'honmei_course' => $honmeiCourse,
        ];
    }

    private function findCourseForBoat(array $trifectaData, int $boat): int
    {
        if ($boat < 1 || $boat > 6) {
            return 0;
        }

        $boatByCourse = is_array($trifectaData['boat_by_course'] ?? null)
            ? $trifectaData['boat_by_course']
            : [];

        for ($course = 1; $course <= 6; $course++) {
            if ((int)($boatByCourse[$course] ?? 0) === $boat) {
                return $course;
            }
        }

        return 0;
    }
}
