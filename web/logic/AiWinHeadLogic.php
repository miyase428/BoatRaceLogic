<?php

declare(strict_types=1);

/**
 * AI1着率 v5 を本命・対抗の「頭」だけへ反映する。
 *
 * 2着・3着の優先順と切る艇判定は既存PredictionLogicの結果を維持する。
 * v5が未計算・不完全な場合はsummaryを変更せず、従来予想へ戻す。
 */
final class AiWinHeadLogic
{
    public function apply(
        array $summary,
        array $finalPredictions,
        array $aiWinRateData
    ): array {
        $summary['ai_win_head_applied'] = false;
        $summary['ai_win_head_source'] = 'legacy_prediction';
        $summary['ai_win_head_reason'] = '';
        $summary['ai_win_rank_boats'] = [];
        $summary['ai_win_rates'] = [];
        $summary['ai_win_legacy_honmei_head'] = (int)($summary['honmei_head'] ?? 0);
        $summary['ai_win_legacy_taikou_head'] = (int)($summary['taikou_head'] ?? 0);

        if ((string)($aiWinRateData['status'] ?? '') !== 'ok') {
            $summary['ai_win_head_reason'] = 'ai_winrate_not_ready';
            return $summary;
        }

        $boats = is_array($aiWinRateData['boats'] ?? null)
            ? $aiWinRateData['boats']
            : [];
        $rates = [];
        foreach (range(1, 6) as $boat) {
            $row = $boats[$boat] ?? $boats[(string)$boat] ?? null;
            $rate = is_array($row) ? ($row['ai_rate'] ?? null) : null;
            if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate < 0.0) {
                $summary['ai_win_head_reason'] = 'ai_winrate_incomplete';
                return $summary;
            }
            $rates[$boat] = (float)$rate;
        }

        $aiRank = range(1, 6);
        usort($aiRank, static function (int $a, int $b) use ($rates): int {
            $cmp = $rates[$b] <=> $rates[$a];
            return $cmp !== 0 ? $cmp : ($a <=> $b);
        });

        $legacyRank = array_values(array_map('intval', (array)($summary['rank_boats'] ?? [])));
        $legacyRank = array_values(array_unique(array_filter(
            $legacyRank,
            static fn(int $boat): bool => $boat >= 1 && $boat <= 6
        )));
        if (count($legacyRank) !== 6) {
            $summary['ai_win_head_reason'] = 'legacy_rank_incomplete';
            return $summary;
        }

        $honmeiHead = (int)$aiRank[0];
        $taikouHead = (int)$aiRank[1];

        $honmeiCut = $this->cutBoats($finalPredictions, 'kiru');
        $taikouCut = $this->cutBoats($finalPredictions, 'kiru_original');
        $honmeiCut = array_values(array_diff($honmeiCut, [$honmeiHead]));
        $taikouCut = array_values(array_diff($taikouCut, [$taikouHead]));

        [$honmeiAite, $honmeiThird, $honmeiPriority] = $this->buildCandidates(
            $legacyRank,
            $honmeiCut,
            $honmeiHead
        );
        [$taikouAite, $taikouThird, $taikouPriority] = $this->buildCandidates(
            $legacyRank,
            $taikouCut,
            $taikouHead
        );

        if ($honmeiAite === [] || $honmeiThird === [] || $taikouAite === [] || $taikouThird === []) {
            $summary['ai_win_head_reason'] = 'candidate_build_failed';
            return $summary;
        }

        $honmeiAiteKako = implode('', $honmeiAite);
        $honmeiThirdKako = implode('', $honmeiThird);
        $taikouAiteKako = implode('', $taikouAite);
        $taikouThirdKako = implode('', $taikouThird);

        $summary['honmei_head'] = $honmeiHead;
        $summary['taikou_head'] = $taikouHead;
        $summary['honmei_aite_str'] = implode('・', $honmeiPriority);
        $summary['taikou_aite_str'] = implode('・', $taikouPriority);
        $summary['honmei_aite_kako'] = $honmeiAiteKako;
        $summary['honmei_third_kako'] = $honmeiThirdKako;
        $summary['taikou_aite_kako'] = $taikouAiteKako;
        $summary['taikou_third_kako'] = $taikouThirdKako;
        $summary['honmei_kai'] = $honmeiHead . '-' . $honmeiAiteKako . '-' . $honmeiThirdKako;
        $summary['taikou_kai'] = $taikouHead . '-' . $taikouAiteKako . '-' . $taikouThirdKako;
        $summary['kiru_str'] = implode('・', $honmeiCut);
        $summary['kiru_kako'] = implode('', $honmeiCut);
        $summary['ai_win_head_applied'] = true;
        $summary['ai_win_head_source'] = 'ai_winrate_v5';
        $summary['ai_win_head_reason'] = 'applied';
        $summary['ai_win_rank_boats'] = $aiRank;
        $summary['ai_win_rates'] = $rates;
        $summary['ai_win_honmei_rate'] = $rates[$honmeiHead];
        $summary['ai_win_taikou_rate'] = $rates[$taikouHead];

        return $summary;
    }

    private function cutBoats(array $finalPredictions, string $key): array
    {
        $cuts = [];
        foreach ($finalPredictions as $boatKey => $row) {
            if (!is_array($row)) {
                continue;
            }
            $boat = (int)($row['boat'] ?? $boatKey);
            if ($boat < 1 || $boat > 6) {
                continue;
            }
            $value = $row[$key] ?? $row['kiru'] ?? 0;
            if ((int)$value === 1) {
                $cuts[] = $boat;
            }
        }
        sort($cuts);
        return array_values(array_unique($cuts));
    }

    /** @return array{0:array<int>,1:array<int>,2:array<int>} */
    private function buildCandidates(array $rankBoats, array $cutBoats, int $head): array
    {
        $priority = [];
        $third = [];
        foreach ($rankBoats as $boat) {
            if ($boat === $head || in_array($boat, $cutBoats, true)) {
                continue;
            }
            $third[] = $boat;
            if (count($priority) < 3) {
                $priority[] = $boat;
            }
        }

        $aite = $priority;
        sort($aite);
        sort($third);
        return [$aite, $third, $priority];
    }
}
