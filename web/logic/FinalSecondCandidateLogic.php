<?php

/**
 * 共通2着確率を、最終予想の「2着候補」に反映するための純粋ロジック。
 *
 * AI着順率v1の完全ホールドアウト検証で、1C頭・NON1C頭の両方において
 * 同一点数の現行買い目より的中数が改善したことを確認済み。
 *
 * 適用仕様:
 * - SecondPlaceProbabilityLogic の head_course は1～6Cを許可
 * - 共通2着確率の head_boat と本命・対抗それぞれの頭が一致する時だけ適用
 * - 既存の切る艇判定はそのまま維持
 * - 3着候補集合は変更しない
 * - 2着候補数を維持し、共通2着確率順位の上位艇へ置き換える
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
        return $this->applySide($summary, $finalPredictions, $secondPlaceData, 'honmei');
    }

    public function applyTaikou(
        array $summary,
        array $finalPredictions,
        array $secondPlaceData
    ): array {
        return $this->applySide($summary, $finalPredictions, $secondPlaceData, 'taikou');
    }

    private function applySide(
        array $summary,
        array $finalPredictions,
        array $secondPlaceData,
        string $side
    ): array {
        $isHonmei = $side === 'honmei';
        $meta = $isHonmei ? 'common_second' : 'taikou_common_second';
        $headKey = $side . '_head';
        $aiteStrKey = $side . '_aite_str';
        $aiteKakoKey = $side . '_aite_kako';
        $thirdKakoKey = $side . '_third_kako';
        $kaiKey = $side . '_kai';

        $summary[$meta . '_applied'] = false;
        $summary[$meta . '_reason'] = '';
        $summary[$meta . '_head_course'] = 0;
        $summary[$meta . '_head_boat'] = 0;
        $summary[$meta . '_ranked_boats'] = [];
        $summary[$meta . '_probability_by_boat'] = [];
        $summary[$meta . '_probability_source'] = (string)($secondPlaceData['probability_source'] ?? 'legacy_trifecta');
        $summary[$meta . '_model_version'] = (string)($secondPlaceData['model_version'] ?? '');

        if ((string)($secondPlaceData['status'] ?? '') !== 'ok') {
            $summary[$meta . '_reason'] = 'second_place_not_ready';
            return $summary;
        }

        $headCourse = (int)($secondPlaceData['head_course'] ?? 0);
        $headBoat = (int)($secondPlaceData['head_boat'] ?? 0);
        $expectedHead = (int)($summary[$headKey] ?? 0);

        $summary[$meta . '_head_course'] = $headCourse;
        $summary[$meta . '_head_boat'] = $headBoat;

        if ($headCourse < 1 || $headCourse > 6) {
            $summary[$meta . '_reason'] = 'head_course_invalid';
            return $summary;
        }

        if ($headBoat < 1 || $headBoat > 6 || $expectedHead !== $headBoat) {
            $summary[$meta . '_reason'] = $side . '_head_mismatch';
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
            $summary[$meta . '_reason'] = 'ranked_second_incomplete';
            return $summary;
        }

        // 本命・対抗それぞれの現行kiruと3着候補集合を維持する。
        $kiruBoats = [];
        $kiruKey = $isHonmei ? 'kiru' : 'kiru_original';
        foreach ($finalPredictions as $boatKey => $fp) {
            $boat = (int)($fp['boat'] ?? $boatKey);
            if ($boat < 1 || $boat > 6) {
                continue;
            }
            if ((int)($fp[$kiruKey] ?? ($fp['kiru'] ?? 0)) === 1) {
                $kiruBoats[] = $boat;
            }
        }
        $kiruBoats = array_values(array_unique($kiruBoats));

        // 3着候補集合は既存summaryを優先してそのまま維持する。
        $thirdKako = preg_replace('/[^1-6]/', '', (string)($summary[$thirdKakoKey] ?? '')) ?? '';
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
        $thirdBoats = array_values(array_unique(array_map('intval', str_split($thirdKako))));

        $currentAiteText = preg_replace('/[^1-6]/', '', (string)($summary[$aiteKakoKey] ?? '')) ?? '';
        $currentAite = array_values(array_unique(array_map('intval', str_split($currentAiteText))));
        $currentAite = array_values(array_filter(
            $currentAite,
            static fn(int $boat): bool => $boat >= 1 && $boat <= 6 && $boat !== $headBoat
        ));
        $desiredCount = count($currentAite);
        if ($desiredCount <= 0) {
            $desiredCount = 3;
        }

        $aitePriority = [];
        foreach ($ranked as $boat) {
            if (!in_array($boat, $thirdBoats, true)) {
                continue;
            }
            $aitePriority[] = $boat;
            if (count($aitePriority) >= $desiredCount) {
                break;
            }
        }

        if (empty($aitePriority)) {
            $summary[$meta . '_reason'] = 'no_second_candidate_after_kiru';
            return $summary;
        }

        // 買い目文字列は艇番昇順を維持し、表示だけ確率順位を見せる。
        $aiteForBet = $aitePriority;
        sort($aiteForBet);
        $aiteKako = implode('', $aiteForBet);

        $summary[$aiteStrKey] = implode('・', $aitePriority);
        $summary[$aiteKakoKey] = $aiteKako;
        $summary[$kaiKey] = $headBoat . '-' . $aiteKako . '-' . $thirdKako;
        $summary[$meta . '_applied'] = true;
        $summary[$meta . '_reason'] = 'applied';
        $summary[$meta . '_ranked_boats'] = $ranked;
        $summary[$meta . '_probability_by_boat'] = $probabilityByBoat;

        return $summary;
    }
}
