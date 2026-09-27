<?php

declare(strict_types=1);

/**
 * 画面表示用のAI3連対率を、AI着順モデルの120通り周辺確率へ差し替える。
 *
 * 既存AiTrioRateLogicの値は買い目・120通り・警報などが参照するため変更しない。
 * AI着順モデルが未計算の場合だけ、表示も従来値へ安全に戻す。
 */
final class AiTrioDisplayLogic
{
    public function apply(array $legacyData, array $aiPlaceData): array
    {
        if ((string)($aiPlaceData['status'] ?? '') !== 'ok') {
            $legacyData['display_source'] = 'legacy_ai_trio';
            $legacyData['display_fallback'] = true;
            return $legacyData;
        }

        $placeBoats = is_array($aiPlaceData['boats'] ?? null)
            ? $aiPlaceData['boats']
            : [];
        $rates = [];
        foreach (range(1, 6) as $boat) {
            $row = $placeBoats[$boat] ?? $placeBoats[(string)$boat] ?? null;
            $rate = is_array($row) ? ($row['ai_trio_rate'] ?? null) : null;
            if (!is_numeric($rate) || !is_finite((float)$rate) || (float)$rate < 0.0) {
                $legacyData['display_source'] = 'legacy_ai_trio';
                $legacyData['display_fallback'] = true;
                return $legacyData;
            }
            $rates[$boat] = (float)$rate;
        }

        $order = range(1, 6);
        usort($order, static function (int $a, int $b) use ($rates): int {
            $cmp = $rates[$b] <=> $rates[$a];
            return $cmp !== 0 ? $cmp : ($a <=> $b);
        });
        $ranks = [];
        foreach ($order as $index => $boat) {
            $ranks[$boat] = $index + 1;
        }

        $legacyBoats = is_array($legacyData['boats'] ?? null) ? $legacyData['boats'] : [];
        $displayBoats = [];
        foreach (range(1, 6) as $boat) {
            $legacy = $legacyBoats[$boat] ?? $legacyBoats[(string)$boat] ?? [];
            $place = $placeBoats[$boat] ?? $placeBoats[(string)$boat] ?? [];
            $displayBoats[$boat] = is_array($legacy) ? $legacy : [];
            $displayBoats[$boat]['lane'] = $boat;
            $displayBoats[$boat]['course'] = (int)($place['course'] ?? $legacy['course'] ?? $boat);
            $displayBoats[$boat]['legacy_ai_rate'] = $legacy['ai_rate'] ?? null;
            $displayBoats[$boat]['ai_rate'] = $rates[$boat];
            $displayBoats[$boat]['ai_rank'] = $ranks[$boat];
            $displayBoats[$boat]['ai_source'] = 'ai_place_v1_joint120';
        }

        $totals = is_array($legacyData['totals'] ?? null) ? $legacyData['totals'] : [];
        $totals['ai'] = array_sum($rates);
        $method = is_array($legacyData['method'] ?? null) ? $legacyData['method'] : [];
        $method['display_name'] = 'AI着順率 v1・120通り周辺確率';
        $method['display_source'] = 'ai_place_v1_joint120';

        return [
            'status' => 'ok',
            'error' => '',
            'boats' => $displayBoats,
            'totals' => $totals,
            'method' => $method,
            'display_source' => 'ai_place_v1_joint120',
            'display_fallback' => false,
        ];
    }
}
