<?php

/**
 * 共通2着確率を、最終予想の「2着候補」に反映するための純粋ロジック。
 *
 * 現時点の本番適用条件は検証済み範囲だけに限定する。
 * - SecondPlaceProbabilityLogic の head_course = 1
 * - 共通2着確率の head_boat と最終予想の本命頭が一致
 * - 既存の切る艇判定はそのまま維持
 * - 3着候補集合は変更しない
 * - 2着候補だけ、共通2着確率順位の上位最大3艇へ置き換える
 *
 * このクラス自身は頭選定・切る艇判定・3着候補・確率計算を変更しない。
 */
class FinalSecondCandidateLogic
{
    /**
     * @param array $summary PredictionLogic::buildSummary() 後のsummary
     * @param array $finalPredictions 艇番キーの最終予想配列
     * @param array $secondPlaceData SecondPlaceProbabilityLogic::calculate() の戻り値
     *
     * @return array 更新後summary
     */
    public function applyHonmei(
        array $summary,
        array $finalPredictions,
        array $secondPlaceData
    ): array {
        $summary['common_second_applied'] = false;
        $summary['common_second_reason'] = '';
        $summary['common_second_ranked_boats'] = [];
        $summary['common_second_probability_by_boat'] = [];

        if ((string)($secondPlaceData['status'] ?? '') !== 'ok') {
            $summary['common_second_reason'] = 'second_place_not_ready';
            return $summary;
        }

        $headCourse = (int)($secondPlaceData['head_course'] ?? 0);
        $headBoat = (int)($secondPlaceData['head_boat'] ?? 0);
        $honmeiHead = (int)($summary['honmei_head'] ?? 0);

        // 現時点で統計検証済みなのは「1C頭」のみ。
        if ($headCourse !== 1) {
            $summary['common_second_reason'] = 'head_course_not_verified';
            return $summary;
        }

        if ($headBoat < 1 || $headBoat > 6 || $honmeiHead !== $headBoat) {
            $summary['common_second_reason'] = 'honmei_head_mismatch';
            return $summary;
        }

        $ranked = is_array($secondPlaceData['ranked_second_boats'] ?? null)
            ? array_values($secondPlaceData['ranked_second_boats'])
            : [];
        $probabilityByBoat = is_array($secondPlaceData['probability_by_boat'] ?? null)
            ? $secondPlaceData['probability_by_boat']
            : [];

        $ranked = array_values(array_filter(array_map('intval', $ranked), static function (int $boat) use ($headBoat): bool {
            return $boat >= 1 && $boat <= 6 && $boat !== $headBoat;
        }));
        $ranked = array_values(array_unique($ranked));

        if (count($ranked) !== 5) {
            $summary['common_second_reason'] = 'ranked_second_incomplete';
            return $summary;
        }

        // 本命買い目に使う現行kiruだけを維持する。
        $kiruBoats = [];
        foreach ($finalPredictions as $boatKey => $fp) {
            $boat = (int)($fp['boat'] ?? $boatKey);
            if ($boat < 1 || $boat > 6) {
                continue;
            }
            if ((int)($fp['kiru'] ?? 0) === 1) {
                $kiruBoats[] = $boat;
            }
        }
        $kiruBoats = array_values(array_unique($kiruBoats));

        $aitePriority = [];
        foreach ($ranked as $boat) {
            if (in_array($boat, $kiruBoats, true)) {
                continue;
            }
            $aitePriority[] = $boat;
            if (count($aitePriority) >= 3) {
                break;
            }
        }

        if (empty($aitePriority)) {
            $summary['common_second_reason'] = 'no_second_candidate_after_kiru';
            return $summary;
        }

        // 3着候補集合は既存summaryを優先してそのまま維持する。
        $thirdKako = (string)($summary['honmei_third_kako'] ?? '');
        if ($thirdKako === '') {
            $third = [];
            foreach (range(1, 6) as $boat) {
                if ($boat === $headBoat || in_array($boat, $kiruBoats, true)) {
                    continue;
                }
                $third[] = $boat;
            }
            sort($third);
            $thirdKako = implode('', $third);
        }

        // 既存買い目文字列は艇番昇順を維持し、表示だけ確率順位を見せる。
        $aiteForBet = $aitePriority;
        sort($aiteForBet);
        $aiteKako = implode('', $aiteForBet);

        $summary['honmei_aite_str'] = implode('・', $aitePriority);
        $summary['honmei_aite_kako'] = $aiteKako;
        $summary['honmei_kai'] = $headBoat . '-' . $aiteKako . '-' . $thirdKako;
        $summary['common_second_applied'] = true;
        $summary['common_second_reason'] = 'applied';
        $summary['common_second_ranked_boats'] = $ranked;
        $summary['common_second_probability_by_boat'] = $probabilityByBoat;

        return $summary;
    }
}
