<?php

require_once __DIR__ . '/PredictionLogic.php';

/**
 * 本番用PredictionLogicラッパー。
 *
 * 2・4号艇の切り保護だけを旧getBonus判定へ一時的に渡し、
 * スコア補正とは完全に分離する。
 *
 * 一次評価3位の艇が現行ロジックで「切る艇」になった場合は、
 * 過去検証で有効だったR3_ONLYルールとして本命買い目だけ切りを解除する。
 * 対抗買い目はR3_ONLY適用前のkiruを使用する。
 *
 * 進入変更時は、親ロジックが前提としている「1..6 = コース順」に
 * tenji / 一次評価 / 3連対率を並べ替えて計算し、最後に艇番へ戻す。
 * 通常進入123456では並べ替え結果が元配列と同一になる。
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

        // 親ロジックの切り判定だけに2・4号艇保護フラグを渡す。
        foreach ($working_tenji as &$t) {
            $t['tenkai_morai'] = (int)($t['kiru_protect_24'] ?? 0);
        }
        unset($t);

        $boatToCourse = [];
        $courseToBoat = [];
        $tenjiByBoat = [];

        foreach ($working_tenji as $idx => $t) {
            $boat = (int)($t['teiban'] ?? ($idx + 1));
            $course = (int)($t['tenji_course'] ?? 0);

            if (
                $boat < 1 || $boat > 6
                || $course < 1 || $course > 6
                || isset($boatToCourse[$boat])
                || isset($courseToBoat[$course])
            ) {
                return $this->buildLegacyOrder(
                    $working_tenji,
                    $kimarite_data,
                    $tenji_test_data,
                    $first_results
                );
            }

            $boatToCourse[$boat] = $course;
            $courseToBoat[$course] = $boat;
            $tenjiByBoat[$boat] = $t;
        }

        if (count($boatToCourse) !== 6 || count($courseToBoat) !== 6) {
            return $this->buildLegacyOrder(
                $working_tenji,
                $kimarite_data,
                $tenji_test_data,
                $first_results
            );
        }

        // 親ロジックを「コース順」で動かす。
        $tenjiByCourse = [];
        $firstByCourse = [];

        for ($course = 1; $course <= 6; $course++) {
            $boat = $courseToBoat[$course];
            $tenjiByCourse[] = $tenjiByBoat[$boat];
            $firstByCourse[] = $first_results[$boat - 1] ?? [];
        }

        $coursePredictions = parent::buildFinalPredictions(
            $tenjiByCourse,
            $kimarite_data,
            $tenji_test_data,
            $firstByCourse
        );

        // 親の出力キー1..6はこの時点では「コース」。実際の艇番へ戻す。
        $final_predictions = [];
        for ($course = 1; $course <= 6; $course++) {
            $boat = $courseToBoat[$course];
            $fp = $coursePredictions[$course] ?? [];

            $fp['boat'] = $boat;
            $fp['waku'] = $boat;
            $fp['course'] = $course;

            // R3_ONLY適用前の切り判定を保存する。
            // 対抗買い目はこの値を使用する。
            $fp['kiru_original'] = (int)($fp['kiru'] ?? 0);

            // 親ロジック内の「★2差し」等はコース番号で生成されるため、
            // 画面上はその評価を受ける実艇番へ戻す。
            foreach (['flg_sashi', 'flg_makuri', 'flg_makurizashi'] as $flagKey) {
                if (isset($fp[$flagKey]) && is_string($fp[$flagKey])) {
                    $fp[$flagKey] = str_replace(
                        "★{$course}",
                        "★{$boat}",
                        $fp[$flagKey]
                    );
                }
            }

            $fp['kiruProtect'] = ($boat === 2 || $boat === 4) ? 1 : 0;
            $fp['getBonus'] = 0;

            $final_predictions[$boat] = $fp;
        }

        ksort($final_predictions);

        // final_predictions上のkiruは「本命買い目用」の切り判定とする。
        return $this->applyPrimaryRank3CutProtection($final_predictions);
    }

    /**
     * HONMEI_ONLY:
     * 親buildSummary()ではR3_ONLY適用後のkiruを使って本命・対抗を作る。
     * その後、対抗買い目だけR3_ONLY適用前のkiru_originalで作り直す。
     */
    public function buildSummary(array $final_predictions): array
    {
        $summary = parent::buildSummary($final_predictions);

        $rankBoats = $summary['rank_boats'] ?? [];
        $taikouHead = (int)($summary['taikou_head'] ?? 0);

        if (count($rankBoats) !== 6 || $taikouHead < 1 || $taikouHead > 6) {
            $summary['r3_only_scope'] = 'HONMEI_ONLY';
            return $summary;
        }

        $taikouKiruBoats = [];
        foreach ($final_predictions as $boat => $fp) {
            $originalKiru = (int)($fp['kiru_original'] ?? ($fp['kiru'] ?? 0));
            if ($originalKiru === 1) {
                $taikouKiruBoats[] = (int)$boat;
            }
        }

        $taikouAite = [];
        $taikouThird = [];

        foreach ($rankBoats as $boat) {
            $boat = (int)$boat;

            if ($boat === $taikouHead) {
                continue;
            }

            if (in_array($boat, $taikouKiruBoats, true)) {
                continue;
            }

            $taikouThird[] = $boat;

            if (count($taikouAite) < 3) {
                $taikouAite[] = $boat;
            }
        }

        sort($taikouAite);
        sort($taikouThird);
        sort($taikouKiruBoats);

        $taikouAiteKako = implode('', $taikouAite);
        $taikouThirdKako = implode('', $taikouThird);

        $summary['taikou_aite_str'] = implode('・', $taikouAite);
        $summary['taikou_aite_kako'] = $taikouAiteKako;
        $summary['taikou_third_kako'] = $taikouThirdKako;
        $summary['taikou_kiru_str'] = implode('・', $taikouKiruBoats);
        $summary['taikou_kiru_kako'] = implode('', $taikouKiruBoats);
        $summary['taikou_kai'] = $taikouHead . '-' . $taikouAiteKako . '-' . $taikouThirdKako;

        // 既存のkiru_str / kiru_kakoは本命買い目用として維持する。
        $summary['honmei_kiru_str'] = (string)($summary['kiru_str'] ?? '');
        $summary['honmei_kiru_kako'] = (string)($summary['kiru_kako'] ?? '');
        $summary['r3_only_scope'] = 'HONMEI_ONLY';

        return $summary;
    }

    /**
     * 展示進入がまだ完全でない時だけ、従来の艇番順ロジックへ戻す。
     */
    private function buildLegacyOrder(
        array $working_tenji,
        array $kimarite_data,
        array $tenji_test_data,
        array $first_results
    ): array {
        $final_predictions = parent::buildFinalPredictions(
            $working_tenji,
            $kimarite_data,
            $tenji_test_data,
            $first_results
        );

        foreach ($final_predictions as $boat => &$fp) {
            $fp['course'] = (int)($working_tenji[$boat - 1]['tenji_course'] ?? $boat);
            $fp['kiru_original'] = (int)($fp['kiru'] ?? 0);
            $fp['kiruProtect'] = ($boat === 2 || $boat === 4) ? 1 : 0;
            $fp['getBonus'] = 0;
        }
        unset($fp);

        return $this->applyPrimaryRank3CutProtection($final_predictions);
    }

    /**
     * R3_ONLY:
     * 現行の切り判定後、一次評価3位の艇だけ切りを解除する。
     * このkiruは本命買い目にだけ使用し、対抗はkiru_originalを使用する。
     *
     * 順位付けはFinalPredictionExporterのfirst_rankと同じく、
     * first_total_score降順・同点時は艇番昇順で1～6位を振る。
     */
    private function applyPrimaryRank3CutProtection(array $final_predictions): array
    {
        if (count($final_predictions) !== 6) {
            return $final_predictions;
        }

        $primarySorted = $final_predictions;

        uasort($primarySorted, static function (array $a, array $b): int {
            $scoreA = (float)($a['first_total_score'] ?? 0);
            $scoreB = (float)($b['first_total_score'] ?? 0);

            if ($scoreA == $scoreB) {
                return (int)($a['boat'] ?? 0) <=> (int)($b['boat'] ?? 0);
            }

            return $scoreA < $scoreB ? 1 : -1;
        });

        $rank = 1;
        foreach ($primarySorted as $fp) {
            if ($rank === 3) {
                $boat = (int)($fp['boat'] ?? 0);

                if (
                    $boat >= 1 && $boat <= 6
                    && isset($final_predictions[$boat])
                    && (int)($final_predictions[$boat]['kiru'] ?? 0) === 1
                ) {
                    $final_predictions[$boat]['kiru'] = 0;
                }

                break;
            }

            $rank++;
        }

        return $final_predictions;
    }
}
