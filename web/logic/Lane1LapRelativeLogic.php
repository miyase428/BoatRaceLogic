<?php

/**
 * 1号艇の周回タイムを、同一レース6艇平均との差で読む表示専用ロジック。
 *
 * SUM・AI確率・買い目には加減算しない。周回タイムは既存のSUM/AIで
 * 利用済みのため、ここでは「インを過信しない」ための補足だけを出す。
 */
class Lane1LapRelativeLogic
{
    private const DEATH_GAP = 0.20;

    public function evaluate(array $tenjiList): array
    {
        $laps = [];
        foreach ($tenjiList as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $boat = (int)($row['teiban'] ?? ($index + 1));
            $lap = $row['lap'] ?? null;
            if ($boat < 1 || $boat > 6 || isset($laps[$boat]) || !is_numeric($lap)) {
                continue;
            }
            $laps[$boat] = (float)$lap;
        }

        if (count($laps) !== 6 || !isset($laps[1])) {
            return [
                'status' => 'waiting',
                'show' => false,
                'error' => '周回タイム待ち',
            ];
        }

        $average = array_sum($laps) / 6.0;
        $delta = $laps[1] - $average;
        if ($delta + 1.0e-9 < self::DEATH_GAP) {
            return [
                'status' => 'ok',
                'show' => false,
                'lane1_lap' => round($laps[1], 2),
                'average_lap' => round($average, 2),
                'delta' => round($delta, 2),
                'error' => '',
            ];
        }

        return [
            'status' => 'ok',
            'show' => true,
            'lane1_lap' => round($laps[1], 2),
            'average_lap' => round($average, 2),
            'delta' => round($delta, 2),
            'historical_escape_lift_points' => -13.2,
            'error' => '',
        ];
    }
}
