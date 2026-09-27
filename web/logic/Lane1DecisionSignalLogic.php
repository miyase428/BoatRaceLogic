<?php

declare(strict_types=1);

require_once __DIR__ . '/HomeBackLinkInjector.php';

/**
 * AI1着率 v5を基準に、①の信頼度を表示専用で返す。
 *
 * 2026-09-11～09-21の前方検証で、AI本命が①でも
 * 「AI1着率50%未満」「2位との差10pt未満」は①勝率低下を再現。
 * 旧一次1位による①レスキューは発生数が少なく不安定なため廃止する。
 * PredictionLogic・本命/対抗・cut・買い目には接続しない。
 */
class Lane1DecisionSignalLogic
{
    public function evaluate(
        array $finalPredictions,
        int $currentHead,
        array $aiWinRateData = []
    ): array
    {
        $base = [
            'ready' => false,
            'current_head' => $currentHead,
            'lane1_primary_rank' => 0,
            'lane1_secondary_rank' => 0,
            'lane1_ai_rate' => null,
            'ai_head_rate' => null,
            'ai_margin' => null,
            'rescue' => false,
            'danger' => false,
            'status' => 'waiting',
            'label' => '判定待ち',
            'detail' => '最終予想データ待ち',
        ];

        if (
            count($finalPredictions) !== 6
            || $currentHead < 1
            || $currentHead > 6
            || (string)($aiWinRateData['status'] ?? '') !== 'ok'
        ) {
            return $base;
        }

        foreach (range(1, 6) as $boat) {
            if (!isset($finalPredictions[$boat]) || !is_array($finalPredictions[$boat])) {
                return $base;
            }
        }

        $primaryRank = $this->makeRankMap($finalPredictions, 'first_total_score');
        $secondaryRank = $this->makeRankMap($finalPredictions, 'second_score');

        $lane1PrimaryRank = (int)($primaryRank[1] ?? 0);
        $lane1SecondaryRank = (int)($secondaryRank[1] ?? 0);

        if ($lane1PrimaryRank < 1 || $lane1SecondaryRank < 1) {
            return $base;
        }

        $rates = [];
        $aiBoats = is_array($aiWinRateData['boats'] ?? null)
            ? $aiWinRateData['boats']
            : [];
        foreach (range(1, 6) as $boat) {
            $row = $aiBoats[$boat] ?? $aiBoats[(string)$boat] ?? null;
            $rate = is_array($row) ? ($row['ai_rate'] ?? null) : null;
            if (!is_numeric($rate) || !is_finite((float)$rate)) {
                return $base;
            }
            $rates[$boat] = max(0.0, (float)$rate);
        }

        $order = range(1, 6);
        usort($order, static function (int $a, int $b) use ($rates): int {
            $cmp = $rates[$b] <=> $rates[$a];
            return $cmp !== 0 ? $cmp : ($a <=> $b);
        });
        $aiHead = (int)$order[0];
        $currentHead = $aiHead;
        $lane1Rate = $rates[1];
        $runnerUpRate = $rates[(int)$order[1]];
        $margin = $aiHead === 1
            ? $lane1Rate - $runnerUpRate
            : $rates[$aiHead] - $lane1Rate;

        $status = 'normal';
        $label = '通常';
        $detail = sprintf(
            '① AI1着率 %.1f%%・首位差 %.1fpt（一次%d位 / 二次%d位）',
            $lane1Rate,
            $margin,
            $lane1PrimaryRank,
            $lane1SecondaryRank
        );

        if ($aiHead !== 1) {
            $status = 'away';
            $label = '①はAI非本命';
            $detail = sprintf(
                'AI本命は%d号艇 %.1f%%。①は%.1f%%で、差は%.1fptです。',
                $aiHead,
                $rates[$aiHead],
                $lane1Rate,
                $margin
            );
        } elseif ($lane1Rate < 40.0 || $margin < 10.0) {
            $status = 'danger';
            $label = '①警戒：高';
            $detail = sprintf(
                '①はAI本命ですが1着率%.1f%%・2位との差%.1fpt。①1着固定は慎重に。',
                $lane1Rate,
                $margin
            );
        } elseif ($lane1Rate < 50.0) {
            $status = 'caution';
            $label = '①警戒：中';
            $detail = sprintf(
                '①はAI本命ですが1着率%.1f%%。イン逃げの信頼は中位です。',
                $lane1Rate
            );
        } elseif ($lane1Rate >= 60.0 && $margin >= 15.0) {
            $status = 'strong';
            $label = '①信頼：高';
            $detail = sprintf(
                '①はAI1着率%.1f%%・2位との差%.1fpt。AI上は明確な本命です。',
                $lane1Rate,
                $margin
            );
        }

        return [
            'ready' => true,
            'current_head' => $currentHead,
            'lane1_primary_rank' => $lane1PrimaryRank,
            'lane1_secondary_rank' => $lane1SecondaryRank,
            'lane1_ai_rate' => $lane1Rate,
            'ai_head_rate' => $rates[$aiHead],
            'ai_margin' => $margin,
            'rescue' => false,
            'danger' => $status === 'danger',
            'status' => $status,
            'label' => $label,
            'detail' => $detail,
        ];
    }

    public function render(array $signal, bool $app = false): string
    {
        $ready = !empty($signal['ready']);
        $status = (string)($signal['status'] ?? 'waiting');
        $label = (string)($signal['label'] ?? '判定待ち');
        $detail = (string)($signal['detail'] ?? '最終予想データ待ち');

        $palette = [
            'away' => [
                'bg' => '#e8f2fb',
                'border' => '#92bddd',
                'title' => '#2f789f',
                'badge_bg' => '#d8ebf8',
                'badge_text' => '#245f7d',
            ],
            'caution' => [
                'bg' => '#fff7df',
                'border' => '#e2c46b',
                'title' => '#8a6500',
                'badge_bg' => '#f5e5a9',
                'badge_text' => '#725400',
            ],
            'strong' => [
                'bg' => '#e9f5ec',
                'border' => '#8cc59a',
                'title' => '#317342',
                'badge_bg' => '#d5ecd9',
                'badge_text' => '#286238',
            ],
            'danger' => [
                'bg' => '#f8eadf',
                'border' => '#dfb18d',
                'title' => '#a45d2f',
                'badge_bg' => '#f4d9c6',
                'badge_text' => '#8a4721',
            ],
            'normal' => [
                'bg' => '#f5f3ef',
                'border' => '#d6d3cd',
                'title' => '#57534e',
                'badge_bg' => '#e7e3dd',
                'badge_text' => '#57534e',
            ],
            'waiting' => [
                'bg' => '#f5f3ef',
                'border' => '#d6d3cd',
                'title' => '#78716c',
                'badge_bg' => '#e7e3dd',
                'badge_text' => '#78716c',
            ],
        ];

        $colors = $palette[$status] ?? $palette['waiting'];
        $escLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $escDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');

        $note = 'AI1着率 v5基準 / 表示専用・本命と買い目は変更しません';
        if (!$ready) {
            $note = '最終予想がそろうと判定します';
        }

        $inner = '<div id="lane1-decision-signal-panel" style="background:' . $colors['bg']
            . '; border:1px solid ' . $colors['border']
            . '; border-radius:8px; padding:10px 12px; color:#3f4b5a;">'
            . '<div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">'
            . '<div style="font-size:13px; font-weight:bold; color:' . $colors['title'] . ';">🧭 ①判断シグナル v2</div>'
            . '<span style="display:inline-block; padding:3px 8px; border-radius:999px; background:' . $colors['badge_bg']
            . '; color:' . $colors['badge_text'] . '; font-size:11px; font-weight:bold;">' . $escLabel . '</span>'
            . '</div>'
            . '<div style="margin-top:7px; font-size:12px; line-height:1.55;">' . $escDetail . '</div>'
            . '<div style="margin-top:5px; font-size:10px; color:#6b7785;">'
            . htmlspecialchars($note, ENT_QUOTES, 'UTF-8')
            . '</div>'
            . '</div>';

        if ($app) {
            return '<section class="app-card app-lane1-decision-signal"><div class="app-card-body" style="padding:9px;">'
                . $inner
                . '</div></section>';
        }

        return '<div style="margin:8px 0 10px;">' . $inner . '</div>';
    }

    private function makeRankMap(array $rows, string $scoreKey): array
    {
        $scores = [];
        foreach (range(1, 6) as $boat) {
            $scores[$boat] = (float)($rows[$boat][$scoreKey] ?? 0.0);
        }

        uksort($scores, static function (int|string $boatA, int|string $boatB) use ($scores): int {
            $scoreA = $scores[$boatA];
            $scoreB = $scores[$boatB];

            if ($scoreA == $scoreB) {
                return (int)$boatA <=> (int)$boatB;
            }

            return $scoreA < $scoreB ? 1 : -1;
        });

        $rankMap = [];
        $rank = 1;
        foreach ($scores as $boat => $_score) {
            $rankMap[(int)$boat] = $rank++;
        }

        return $rankMap;
    }
}
