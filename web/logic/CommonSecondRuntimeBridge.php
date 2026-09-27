<?php

declare(strict_types=1);

require_once __DIR__ . '/SecondPlaceProbabilityLogic.php';
require_once __DIR__ . '/FinalSecondCandidateLogic.php';
require_once __DIR__ . '/AiSecondCutRescueLogic.php';

/**
 * 120通り出目確率（AI着順率v1を優先、従来版へフォールバック）を
 * 共通2着確率へ変換し、
 * - 1C頭の2連単表示
 * - 現在の本命・対抗頭に対する最終予想2着候補
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
     *   honmei_course:int,
     *   taikou:array,
     *   taikou_course:int
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

        $taikouHead = (int)($viewData['taikou_head'] ?? 0);
        $taikouCourse = $this->findCourseForBoat($trifectaData, $taikouHead);
        $taikouData = [
            'status' => 'error',
            'error' => '対抗頭の進入コースを特定できません',
            'head_course' => $taikouCourse,
            'head_boat' => $taikouHead,
            'rows' => [],
            'probability_by_boat' => [],
            'ranked_second_boats' => [],
            'probability_source' => (string)($trifectaData['probability_source'] ?? 'legacy_trifecta'),
            'model_version' => (string)($trifectaData['model_version'] ?? ''),
        ];
        if ($taikouCourse >= 1 && $taikouCourse <= 6) {
            $taikouData = $secondLogic->calculate($trifectaData, $taikouCourse);
        }

        $finalSecondLogic = new FinalSecondCandidateLogic();
        $updated = $finalSecondLogic->applyHonmei(
            $viewData,
            $finalPredictions,
            $honmeiData
        );
        $updated = $finalSecondLogic->applyTaikou(
            $updated,
            $finalPredictions,
            $taikouData
        );

        // AI2着率が既存候補最下位の1.5倍以上なら、切る艇を2着だけへ最大1艇救済する。
        $updated = (new AiSecondCutRescueLogic())->apply($updated, $finalPredictions);

        $updated['common_second_head_course'] = $honmeiCourse;
        $updated['common_second_head1_data'] = $head1Data;
        $updated['common_second_honmei_data'] = $honmeiData;
        $updated['taikou_common_second_head_course'] = $taikouCourse;
        $updated['taikou_common_second_data'] = $taikouData;

        return [
            'view_data' => $updated,
            'head1' => $head1Data,
            'honmei' => $honmeiData,
            'honmei_course' => $honmeiCourse,
            'taikou' => $taikouData,
            'taikou_course' => $taikouCourse,
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
