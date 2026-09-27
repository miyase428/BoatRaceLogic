<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialOddsLogic.php';
require_once __DIR__ . '/PredictionForwardSnapshotStore.php';

/**
 * 表示専用の8点フォーメーションを組み立てる。
 * 既存の本命・対抗買い目を変更せず、前向き検証できる形で返す。
 */
final class EightPointFormationLogic
{
    public const ODDS_SKIP_THRESHOLD = 2.3;

    public function apply(
        array $viewData,
        array $finalPredictions,
        array $aiTrioBoats,
        array $recentTrioBoats = []
    ): array
    {
        $empty = [
            'visible' => false,
            'variants' => [],
            'recommended_type' => '',
            'recommendation_reason' => '',
            'odds' => ['status' => 'not_available', 'threshold' => self::ODDS_SKIP_THRESHOLD],
        ];

        $heads = $this->uniqueBoats([
            (int)($viewData['honmei_head'] ?? 0),
            (int)($viewData['taikou_head'] ?? 0),
        ]);
        if (count($heads) !== 2 || count($finalPredictions) < 6) {
            $viewData['eight_point_formation'] = $empty;
            return $viewData;
        }

        $secondRank = $this->rankBy($finalPredictions, 'second_score');
        $combinedTrioRank = $this->combinedTrioRank($finalPredictions, $aiTrioBoats, $recentTrioBoats);
        if (count($secondRank) !== 6 || count($combinedTrioRank) !== 6) {
            $viewData['eight_point_formation'] = $empty;
            return $viewData;
        }

        // 3着拡大型: 本命・対抗を頭、頭以外で最上位の2着評価を軸へ追加し、
        // さらに2着評価順の次点を3着へ加える。常に既検証の8通りになる形だけ採用する。
        $additionalSecond = $this->firstOutside($secondRank, $heads);
        $thirdWide = $heads;
        if ($additionalSecond > 0) {
            $thirdWide[] = $additionalSecond;
            $thirdWide[] = $this->firstOutside($secondRank, $thirdWide);
        }
        $thirdWide = $this->uniqueBoats($thirdWide);
        $thirdExpanded = $this->makeVariant('third_wide', '3着拡大型', $heads, $this->uniqueBoats([...$heads, $additionalSecond]), $thirdWide);

        // 2着拡大型: 本命頭/対抗頭それぞれの現行2着候補を合算する。
        // 4艇を超えた時は重複候補を優先し、残りを2着評価順で補う。
        $honmeiSecond = $this->parseSecond((string)($viewData['honmei_kai'] ?? ''));
        $taikouSecond = $this->parseSecond((string)($viewData['taikou_kai'] ?? ''));
        $secondWide = $this->selectSecondWide($honmeiSecond, $taikouSecond, $secondRank);
        $thirdTopThree = array_slice($combinedTrioRank, 0, 3);
        $secondExpanded = $this->makeVariant('second_wide', '2着拡大型', $heads, $secondWide, $thirdTopThree);

        $variants = [];
        $referenceVariants = [];
        foreach ([$thirdExpanded, $secondExpanded] as $variant) {
            if (($variant['points'] ?? 0) === 8) {
                $variants[$variant['type']] = $variant;
            } elseif (($variant['points'] ?? 0) > 0) {
                $variant['reference_reason'] = '重複を除いた実買い目が'
                    . (int)$variant['points']
                    . '点となり、8点型の対象外です。';
                $referenceVariants[$variant['type']] = $variant;
            }
        }
        if ($variants === []) {
            $viewData['eight_point_formation'] = $empty;
            return $viewData;
        }

        $topSecondOutsideHeads = array_slice(array_values(array_filter(
            $secondRank,
            static fn(int $boat): bool => !in_array($boat, $heads, true)
        )), 0, 3);
        $trioTopThree = array_slice($combinedTrioRank, 0, 3);
        $secondPriorityOutsideTrio = array_values(array_filter(
            $topSecondOutsideHeads,
            static fn(int $boat): bool => !in_array($boat, $trioTopThree, true)
        ));

        if (isset($variants['second_wide']) && $secondPriorityOutsideTrio !== []) {
            $recommendedType = 'second_wide';
            $recommendationReason = '2着評価上位に、合算3連対率トップ3外の艇がいるため。';
        } elseif (isset($variants['third_wide'])) {
            $recommendedType = 'third_wide';
            $recommendationReason = '合算3連対率上位を3着まで広く押さえる形のため。';
        } else {
            $recommendedType = 'second_wide';
            $recommendationReason = '8点条件に合う候補がこの型のみのため。';
        }

        foreach ($variants as $type => &$variant) {
            $variant['recommended'] = $type === $recommendedType;
        }
        unset($variant);

        $odds = $this->loadOdds((string)($viewData['race_code'] ?? ''), $variants[$recommendedType]['bets'] ?? []);
        $viewData['eight_point_formation'] = [
            'visible' => true,
            'variants' => $variants,
            // 8点にならない型は買い目候補へは加えず、理由を確認できる参考情報として残す。
            'reference_variants' => $referenceVariants,
            'recommended_type' => $recommendedType,
            'recommendation_reason' => $recommendationReason,
            'second_priority_outside_trio' => $secondPriorityOutsideTrio,
            'combined_trio_top3' => $trioTopThree,
            'odds' => $odds,
            'logic_version' => 'eight-point-formation-v1',
        ];
        return $viewData;
    }

    private function makeVariant(string $type, string $label, array $heads, array $seconds, array $thirds): array
    {
        $heads = $this->uniqueBoats($heads);
        $seconds = $this->uniqueBoats($seconds);
        $thirds = $this->uniqueBoats($thirds);
        sort($heads);
        sort($seconds);
        sort($thirds);
        $formation = implode('', $heads) . '-' . implode('', $seconds) . '-' . implode('', $thirds);
        $bets = PredictionForwardSnapshotStore::expandTrifecta($formation);
        return [
            'type' => $type,
            'label' => $label,
            'formation' => $formation,
            'heads' => $heads,
            'seconds' => $seconds,
            'thirds' => $thirds,
            'bets' => $bets,
            'points' => count($bets),
            'recommended' => false,
        ];
    }

    private function selectSecondWide(array $honmei, array $taikou, array $secondRank): array
    {
        $all = $this->uniqueBoats([...$honmei, ...$taikou]);
        if (count($all) <= 4) {
            return $all;
        }
        $overlap = array_values(array_intersect($honmei, $taikou));
        $selected = $this->uniqueBoats($overlap);
        foreach ($secondRank as $boat) {
            if (in_array($boat, $all, true) && !in_array($boat, $selected, true)) {
                $selected[] = $boat;
            }
            if (count($selected) === 4) {
                break;
            }
        }
        return $selected;
    }

    private function combinedTrioRank(array $finalPredictions, array $aiTrioBoats, array $recentTrioBoats): array
    {
        $scores = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $final = (array)($finalPredictions[$boat] ?? []);
            $ai = (array)($aiTrioBoats[$boat] ?? $aiTrioBoats[(string)$boat] ?? []);
            $recent = (array)($recentTrioBoats[$boat] ?? $recentTrioBoats[(string)$boat] ?? []);
            if ($final === [] || !isset($ai['ai_rate'])) {
                return [];
            }
            $scores[$boat] = (float)($recent['rate3_dec'] ?? $final['rate3_dec'] ?? 0.0)
                + (float)($recent['rate6_dec'] ?? $final['rate6_dec'] ?? 0.0)
                + ((float)$ai['ai_rate'] / 100.0);
        }
        return $this->sortScores($scores);
    }

    private function rankBy(array $finalPredictions, string $field): array
    {
        $scores = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            if (!isset($finalPredictions[$boat])) {
                return [];
            }
            $scores[$boat] = (float)($finalPredictions[$boat][$field] ?? 0.0);
        }
        return $this->sortScores($scores);
    }

    private function sortScores(array $scores): array
    {
        uksort($scores, static function (int $a, int $b) use ($scores): int {
            $comparison = $scores[$b] <=> $scores[$a];
            return $comparison !== 0 ? $comparison : ($a <=> $b);
        });
        return array_map('intval', array_keys($scores));
    }

    private function parseSecond(string $formation): array
    {
        $parts = explode('-', trim($formation));
        return count($parts) === 3 ? $this->uniqueBoats(str_split(trim($parts[1]))) : [];
    }

    private function firstOutside(array $ranked, array $excluded): int
    {
        foreach ($ranked as $boat) {
            if (!in_array($boat, $excluded, true)) {
                return $boat;
            }
        }
        return 0;
    }

    private function uniqueBoats(array $boats): array
    {
        $out = [];
        foreach ($boats as $boat) {
            $boat = (int)$boat;
            if ($boat >= 1 && $boat <= 6 && !in_array($boat, $out, true)) {
                $out[] = $boat;
            }
        }
        return $out;
    }

    private function loadOdds(string $raceCode, array $bets): array
    {
        $base = ['status' => 'waiting', 'threshold' => self::ODDS_SKIP_THRESHOLD, 'combined_odds' => null, 'skip_candidate' => false];
        if (count($bets) !== 8 || !preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode)) {
            return $base;
        }
        $data = (new OfficialOddsLogic())->load($raceCode);
        if (($data['status'] ?? '') !== 'ok') {
            $base['message'] = (string)($data['error'] ?? '公式オッズ待ちです。');
            return $base;
        }
        $inverse = 0.0;
        foreach ($bets as $bet) {
            $odds = (float)($data['odds'][$bet] ?? 0.0);
            if ($odds <= 0.0) {
                $base['message'] = '一部の公式オッズを取得できません。';
                return $base;
            }
            $inverse += 1.0 / $odds;
        }
        $combined = $inverse > 0.0 ? 1.0 / $inverse : 0.0;
        return [
            'status' => 'ok',
            'threshold' => self::ODDS_SKIP_THRESHOLD,
            'combined_odds' => $combined,
            'skip_candidate' => $combined > 0.0 && $combined < self::ODDS_SKIP_THRESHOLD,
            'fetched_at' => (string)($data['fetched_at'] ?? ''),
        ];
    }
}
