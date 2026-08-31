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
 * 現行本命が5号艇または6号艇になった場合だけ、凍結済みkimariteモデルで
 * 2～4号艇から新しい本命頭を選ぶ。モデルは
 * config/kimarite_head_model.php が存在する時だけ有効になる。
 *
 * STEP9の検証済み相手補正として、本命買い目だけ次を適用する。
 * - 事前本命1 × 3C攻め率>=15% → 3を2着候補側へ昇格
 * - 事前本命1 × 4C攻め率>=20% → 4を2着候補側へ昇格
 * - 事前本命3 × 3C攻め率>=15% → 1を2着候補側へ昇格
 * 相手補正ではrank_boatsと対抗買い目は変更しない。
 *
 * 進入変更時は、親ロジックが前提としている「1..6 = コース順」に
 * tenji / 一次評価 / 3連対率を並べ替えて計算し、最後に艇番へ戻す。
 * 通常進入123456では並べ替え結果が元配列と同一になる。
 */
class PredictionLogicProduction extends PredictionLogic
{
    private static bool $kimariteHeadModelLoaded = false;
    private static array $kimariteHeadModel = [];

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
        $final_predictions = $this->applyPrimaryRank3CutProtection($final_predictions);

        // 凍結モデルの入力は検証時と同じく「艇番2/3/4に対応するkimarite値」。
        // 展示進入変更の有無にかかわらず、検証済み定義をそのまま維持する。
        return $this->attachKimariteHeadScores($final_predictions, $kimarite_data);
    }

    /**
     * HONMEI_ONLY:
     * 親buildSummary()ではR3_ONLY適用後のkiruを使って本命・対抗を作る。
     * その後、⑤⑥本命kimarite補正を適用し、さらに検証済みの相手補正を
     * 本命買い目だけへ適用する。
     * 対抗買い目だけR3_ONLY適用前のkiru_originalで作り直す。
     */
    public function buildSummary(array $final_predictions): array
    {
        $summary = parent::buildSummary($final_predictions);
        $originalHonmeiHead = (int)($summary['honmei_head'] ?? 0);

        $summary = $this->applyKimariteHeadOverride($summary, $final_predictions);
        $summary = $this->applyKimariteOpponentOverride(
            $summary,
            $final_predictions,
            $originalHonmeiHead
        );

        $rankBoats = $summary['rank_boats'] ?? [];
        $honmeiHead = (int)($summary['honmei_head'] ?? 0);
        $taikouHead = (int)($summary['taikou_head'] ?? 0);

        if (
            count($rankBoats) !== 6
            || $honmeiHead < 1 || $honmeiHead > 6
            || $taikouHead < 1 || $taikouHead > 6
        ) {
            $summary['r3_only_scope'] = 'HONMEI_ONLY';
            return $summary;
        }

        // 表示上の「相手」だけは最終評価の優先順を残す。
        // 買い目用 *_aite_kako / *_kai は従来どおり艇番順のまま維持する。
        // STEP9相手補正が発動条件を満たした場合は、補正側で作った優先順を優先する。
        $opponentPriorityActive = !empty($summary['kimarite_opponent_a3'])
            || !empty($summary['kimarite_opponent_a4'])
            || !empty($summary['kimarite_opponent_h3']);

        if (!$opponentPriorityActive) {
            $honmeiKiruBoats = [];
            foreach ($final_predictions as $boat => $fp) {
                if ((int)($fp['kiru'] ?? 0) === 1) {
                    $honmeiKiruBoats[] = (int)$boat;
                }
            }

            [, , $honmeiAitePriority] = $this->buildBetCandidates(
                $rankBoats,
                $honmeiKiruBoats,
                $honmeiHead
            );
            $summary['honmei_aite_str'] = implode('・', $honmeiAitePriority);
        }

        $taikouKiruBoats = [];
        foreach ($final_predictions as $boat => $fp) {
            $originalKiru = (int)($fp['kiru_original'] ?? ($fp['kiru'] ?? 0));
            if ($originalKiru === 1) {
                $taikouKiruBoats[] = (int)$boat;
            }
        }

        [$taikouAite, $taikouThird, $taikouAitePriority] = $this->buildBetCandidates(
            $rankBoats,
            $taikouKiruBoats,
            $taikouHead
        );

        sort($taikouKiruBoats);

        $taikouAiteKako = implode('', $taikouAite);
        $taikouThirdKako = implode('', $taikouThird);

        $summary['taikou_aite_str'] = implode('・', $taikouAitePriority);
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

        $final_predictions = $this->applyPrimaryRank3CutProtection($final_predictions);

        return $this->attachKimariteHeadScores($final_predictions, $kimarite_data);
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

    /**
     * 凍結済み⑤⑥本命補正モデルの2～4号艇スコアを付与する。
     */
    private function attachKimariteHeadScores(
        array $final_predictions,
        array $kimarite_data
    ): array {
        $model = $this->getKimariteHeadModel();
        if (empty($model['courses']) || !is_array($model['courses'])) {
            return $final_predictions;
        }

        foreach ([2, 3, 4] as $boat) {
            if (!isset($final_predictions[$boat])) {
                continue;
            }

            $courseModel = $model['courses'][$boat] ?? null;
            if (!is_array($courseModel)) {
                continue;
            }

            $raw = $kimarite_data[(string)$boat] ?? $kimarite_data[$boat] ?? [];
            $k = is_array($raw)
                ? ($raw['6month'] ?? $raw)
                : [];

            if (!is_array($k)) {
                $k = [];
            }

            $sampleN = (int)($k['_sample_n'] ?? 0);
            if ($boat === 2) {
                $featurePct = $this->rateToPercent($k['sashi'] ?? 0);
            } else {
                $featurePct =
                    $this->rateToPercent($k['makuri'] ?? 0)
                    + $this->rateToPercent($k['makurizashi'] ?? 0);
            }

            $band = $this->kimariteHeadBand($featurePct);
            $baseP = (float)($courseModel['base_p'] ?? 0.0);
            $minSample = (int)($model['min_sample'] ?? 10);
            $score = $baseP;

            if ($sampleN >= $minSample) {
                $bandRow = $courseModel['bands'][$band] ?? null;
                if (is_array($bandRow) && array_key_exists('p', $bandRow)) {
                    $score = (float)$bandRow['p'];
                }
            }

            $final_predictions[$boat]['kimarite_head_score'] = $score;
            $final_predictions[$boat]['kimarite_head_feature_pct'] = $featurePct;
            $final_predictions[$boat]['kimarite_head_sample_n'] = $sampleN;
            $final_predictions[$boat]['kimarite_head_band'] = $band;
            $final_predictions[$boat]['kimarite_head_model_version'] = (string)($model['version'] ?? '');
        }

        return $final_predictions;
    }

    /**
     * STEP4まで済んだ現行本命が⑤/⑥の時だけ、2～4号艇の凍結kimariteスコアで頭を差し替える。
     * 2～4号艇同士の通常順位は触らず、⑤⑥本命時だけ発動する。
     */
    private function applyKimariteHeadOverride(
        array $summary,
        array $final_predictions
    ): array {
        $rankBoats = array_values($summary['rank_boats'] ?? []);
        if (count($rankBoats) !== 6) {
            return $summary;
        }

        $currentHead = (int)($rankBoats[0] ?? 0);
        $summary['kimarite_head_override_condition'] = in_array($currentHead, [5, 6], true);
        $summary['kimarite_head_override_applied'] = false;
        $summary['kimarite_head_override_from'] = null;
        $summary['kimarite_head_override_to'] = null;
        $summary['kimarite_head_override_score'] = null;
        $summary['kimarite_head_model_version'] = '';

        if (!in_array($currentHead, [5, 6], true)) {
            return $summary;
        }

        $model = $this->getKimariteHeadModel();
        if (empty($model['courses']) || !is_array($model['courses'])) {
            return $summary;
        }

        $bestBoat = null;
        $bestScore = null;

        foreach ([2, 3, 4] as $boat) {
            if (!isset($final_predictions[$boat])) {
                continue;
            }

            if (!array_key_exists('kimarite_head_score', $final_predictions[$boat])) {
                continue;
            }

            $score = (float)$final_predictions[$boat]['kimarite_head_score'];

            if (
                $bestBoat === null
                || $score > (float)$bestScore
                || ($score == (float)$bestScore && $boat < $bestBoat)
            ) {
                $bestBoat = $boat;
                $bestScore = $score;
            }
        }

        if ($bestBoat === null) {
            return $summary;
        }

        // 検証時のmove_head()と同じく、選択頭だけ先頭へ移し、残りの順位は維持する。
        $rankBoats = array_values(array_filter(
            $rankBoats,
            static fn($boat): bool => (int)$boat !== $bestBoat
        ));
        array_unshift($rankBoats, $bestBoat);

        $honmeiKiruBoats = [];
        foreach ($final_predictions as $boat => $fp) {
            if ((int)($fp['kiru'] ?? 0) === 1) {
                $honmeiKiruBoats[] = (int)$boat;
            }
        }
        sort($honmeiKiruBoats);

        $honmeiHead = (int)$rankBoats[0];
        $taikouHead = (int)$rankBoats[1];

        [$honmeiAite, $honmeiThird, $honmeiAitePriority] = $this->buildBetCandidates(
            $rankBoats,
            $honmeiKiruBoats,
            $honmeiHead
        );
        [$taikouAite, $taikouThird, $taikouAitePriority] = $this->buildBetCandidates(
            $rankBoats,
            $honmeiKiruBoats,
            $taikouHead
        );

        $honmeiAiteKako = implode('', $honmeiAite);
        $honmeiThirdKako = implode('', $honmeiThird);
        $taikouAiteKako = implode('', $taikouAite);
        $taikouThirdKako = implode('', $taikouThird);

        $summary['rank_boats'] = $rankBoats;
        $summary['honmei_head'] = $honmeiHead;
        $summary['taikou_head'] = $taikouHead;
        $summary['honmei_aite_str'] = implode('・', $honmeiAitePriority);
        $summary['taikou_aite_str'] = implode('・', $taikouAitePriority);
        $summary['honmei_aite_kako'] = $honmeiAiteKako;
        $summary['honmei_third_kako'] = $honmeiThirdKako;
        $summary['taikou_aite_kako'] = $taikouAiteKako;
        $summary['taikou_third_kako'] = $taikouThirdKako;
        $summary['honmei_kai'] = $honmeiHead . '-' . $honmeiAiteKako . '-' . $honmeiThirdKako;
        $summary['taikou_kai'] = $taikouHead . '-' . $taikouAiteKako . '-' . $taikouThirdKako;
        $summary['kimarite_head_override_applied'] = true;
        $summary['kimarite_head_override_from'] = $currentHead;
        $summary['kimarite_head_override_to'] = $bestBoat;
        $summary['kimarite_head_override_score'] = $bestScore;
        $summary['kimarite_head_model_version'] = (string)($model['version'] ?? '');

        return $summary;
    }

    /**
     * STEP9の検証済み相手補正を、本命買い目だけへ適用する。
     * トリガーは⑤⑥頭補正前の事前本命で判定し、rank_boatsや対抗は変更しない。
     */
    private function applyKimariteOpponentOverride(
        array $summary,
        array $final_predictions,
        int $originalHonmeiHead
    ): array {
        $summary['kimarite_opponent_override_applied'] = false;
        $summary['kimarite_opponent_original_head'] = $originalHonmeiHead;
        $summary['kimarite_opponent_a3'] = false;
        $summary['kimarite_opponent_a4'] = false;
        $summary['kimarite_opponent_h3'] = false;

        $rankBoats = array_values($summary['rank_boats'] ?? []);
        $currentHead = (int)($summary['honmei_head'] ?? 0);

        if (count($rankBoats) !== 6 || $currentHead < 1 || $currentHead > 6) {
            return $summary;
        }

        $candidateRank = $rankBoats;
        $a3 = false;
        $a4 = false;
        $h3 = false;

        if ($originalHonmeiHead === 1 && $currentHead === 1) {
            $a3 = $this->kimariteOpponentCondition($final_predictions, 3, 15.0);
            $a4 = $this->kimariteOpponentCondition($final_predictions, 4, 20.0);

            // 統合検証と同じくA4→A3の順に適用し、A3を優先する。
            if ($a4) {
                $candidateRank = $this->promoteKimariteOpponentToSecond(
                    $candidateRank,
                    $currentHead,
                    4
                );
            }
            if ($a3) {
                $candidateRank = $this->promoteKimariteOpponentToSecond(
                    $candidateRank,
                    $currentHead,
                    3
                );
            }
        } elseif ($originalHonmeiHead === 3 && $currentHead === 3) {
            $h3 = $this->kimariteOpponentCondition($final_predictions, 3, 15.0);
            if ($h3) {
                $candidateRank = $this->promoteKimariteOpponentToSecond(
                    $candidateRank,
                    $currentHead,
                    1
                );
            }
        }

        $summary['kimarite_opponent_a3'] = $a3;
        $summary['kimarite_opponent_a4'] = $a4;
        $summary['kimarite_opponent_h3'] = $h3;

        if (!$a3 && !$a4 && !$h3) {
            return $summary;
        }

        $honmeiKiruBoats = [];
        foreach ($final_predictions as $boat => $fp) {
            if ((int)($fp['kiru'] ?? 0) === 1) {
                $honmeiKiruBoats[] = (int)$boat;
            }
        }
        sort($honmeiKiruBoats);

        [$honmeiAite, $honmeiThird, $honmeiAitePriority] = $this->buildBetCandidates(
            $candidateRank,
            $honmeiKiruBoats,
            $currentHead
        );

        $honmeiAiteKako = implode('', $honmeiAite);
        $honmeiThirdKako = implode('', $honmeiThird);
        $oldHonmeiAiteKako = (string)($summary['honmei_aite_kako'] ?? '');

        $summary['honmei_aite_str'] = implode('・', $honmeiAitePriority);
        $summary['honmei_aite_kako'] = $honmeiAiteKako;
        $summary['honmei_third_kako'] = $honmeiThirdKako;
        $summary['honmei_kai'] = $currentHead . '-' . $honmeiAiteKako . '-' . $honmeiThirdKako;
        $summary['kimarite_opponent_override_applied'] = $honmeiAiteKako !== $oldHonmeiAiteKako;

        return $summary;
    }

    private function kimariteOpponentCondition(
        array $final_predictions,
        int $boat,
        float $threshold
    ): bool {
        if (!isset($final_predictions[$boat])) {
            return false;
        }

        $fp = $final_predictions[$boat];
        $sampleN = (int)($fp['kimarite_head_sample_n'] ?? 0);
        $featurePct = (float)($fp['kimarite_head_feature_pct'] ?? 0.0);

        return $sampleN >= 10 && $featurePct >= $threshold;
    }

    private function promoteKimariteOpponentToSecond(
        array $rankBoats,
        int $head,
        int $target
    ): array {
        if ($head === $target) {
            return $rankBoats;
        }

        $targetPos = array_search($target, $rankBoats, true);
        if ($targetPos === false) {
            return $rankBoats;
        }

        array_splice($rankBoats, (int)$targetPos, 1);
        $headPos = array_search($head, $rankBoats, true);
        if ($headPos === false) {
            return $rankBoats;
        }

        array_splice($rankBoats, (int)$headPos + 1, 0, [$target]);
        return array_values($rankBoats);
    }

    /**
     * 現行Webと同じ買い目候補作成。
     * 2着=切る艇を除く上位最大3艇、3着=切る艇を除く全艇。
     * 戻り値3つ目は表示専用の2着優先順。買い目用は従来どおり艇番順にする。
     */
    private function buildBetCandidates(
        array $rankBoats,
        array $kiruBoats,
        int $head
    ): array {
        $aite = [];
        $third = [];

        foreach ($rankBoats as $boat) {
            $boat = (int)$boat;

            if ($boat === $head) {
                continue;
            }

            if (in_array($boat, $kiruBoats, true)) {
                continue;
            }

            $third[] = $boat;

            if (count($aite) < 3) {
                $aite[] = $boat;
            }
        }

        $aitePriority = $aite;
        sort($aite);
        sort($third);

        return [$aite, $third, $aitePriority];
    }

    private function getKimariteHeadModel(): array
    {
        if (self::$kimariteHeadModelLoaded) {
            return self::$kimariteHeadModel;
        }

        self::$kimariteHeadModelLoaded = true;
        $path = __DIR__ . '/../../config/kimarite_head_model.php';

        if (!is_file($path)) {
            self::$kimariteHeadModel = [];
            return self::$kimariteHeadModel;
        }

        $model = require $path;
        self::$kimariteHeadModel = is_array($model) ? $model : [];

        return self::$kimariteHeadModel;
    }

    /**
     * kimarite_api.php は率を 0～100 のパーセント値で返す。
     * 分析CSVも同じ値をそのまま使っているため、本番側でも変換せず揃える。
     */
    private function rateToPercent($value): float
    {
        return (float)$value;
    }

    private function kimariteHeadBand(float $value): string
    {
        if ($value < 5.0) {
            return '0-5';
        }
        if ($value < 10.0) {
            return '5-10';
        }
        if ($value < 15.0) {
            return '10-15';
        }
        if ($value < 20.0) {
            return '15-20';
        }
        if ($value < 25.0) {
            return '20-25';
        }
        return '25+';
    }
}
