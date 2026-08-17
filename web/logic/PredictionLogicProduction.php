<?php

require_once __DIR__ . '/PredictionLogic.php';

/**
 * 本番用PredictionLogicラッパー。
 *
 * 2・4号艇の切り保護だけを旧getBonus判定へ一時的に渡し、
 * スコア補正とは完全に分離する。
 */
class PredictionLogicProduction extends PredictionLogic
{
    public function buildFinalPredictions(
        array $tenji_list,
        array $kimarite_data,
        array $tenji_test_data,
        array $first_results = []
    ): array {
        $working_tenji = $tenji_list;

        // 親ロジックの切り判定だけに保護フラグを渡す。
        foreach ($working_tenji as &$t) {
            $t['tenkai_morai'] = (int)($t['kiru_protect_24'] ?? 0);
        }
        unset($t);

        $final_predictions = parent::buildFinalPredictions(
            $working_tenji,
            $kimarite_data,
            $tenji_test_data,
            $first_results
        );

        // 出力上は「展開もらいボーナス」を残さない。
        // 切り保護は独立した kiruProtect フラグとして明示する。
        foreach ($final_predictions as $boat => &$fp) {
            $fp['kiruProtect'] = ($boat === 2 || $boat === 4) ? 1 : 0;
            $fp['getBonus'] = 0;
        }
        unset($fp);

        return $final_predictions;
    }
}
