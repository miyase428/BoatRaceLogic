<?php

declare(strict_types=1);

/**
 * 前方2期間で固定条件の再現を確認した「1号艇判断シグナル」を表示専用で返す。
 *
 * RESCUE:
 *   現行Web本命 != 1号艇 × 1号艇一次順位1位
 *
 * DANGER:
 *   現行Web本命 = 1号艇 × 1号艇一次順位4位以下 × 1号艇二次順位1位
 *
 * どちらもPredictionLogic・本命/対抗・cut・買い目には接続しない。
 */
class Lane1DecisionSignalLogic
{
    public function evaluate(array $finalPredictions, int $currentHead): array
    {
        $base = [
            'ready' => false,
            'current_head' => $currentHead,
            'lane1_primary_rank' => 0,
            'lane1_secondary_rank' => 0,
            'rescue' => false,
            'danger' => false,
            'status' => 'waiting',
            'label' => '判定待ち',
            'detail' => '最終予想データ待ち',
        ];

        if (count($finalPredictions) !== 6 || $currentHead < 1 || $currentHead > 6) {
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

        $rescue = $currentHead !== 1 && $lane1PrimaryRank === 1;
        $danger = $currentHead === 1 && $lane1PrimaryRank >= 4 && $lane1SecondaryRank === 1;

        $status = 'normal';
        $label = '通常';
        $detail = sprintf(
            '①一次%d位 / 二次%d位 / 現行本命%d号艇',
            $lane1PrimaryRank,
            $lane1SecondaryRank,
            $currentHead
        );

        if ($rescue) {
            $status = 'rescue';
            $label = '①レスキュー候補';
            $detail = sprintf(
                '現行本命%d号艇ですが①は一次1位。①を強く再確認する精度シグナルです。',
                $currentHead
            );
        } elseif ($danger) {
            $status = 'danger';
            $label = '①1着注意';
            $detail = sprintf(
                '①は一次%d位・二次1位。①1着固定を慎重に見る危険シグナルです。',
                $lane1PrimaryRank
            );
        }

        return [
            'ready' => true,
            'current_head' => $currentHead,
            'lane1_primary_rank' => $lane1PrimaryRank,
            'lane1_secondary_rank' => $lane1SecondaryRank,
            'rescue' => $rescue,
            'danger' => $danger,
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
            'rescue' => [
                'bg' => '#e8f2fb',
                'border' => '#92bddd',
                'title' => '#2f789f',
                'badge_bg' => '#d8ebf8',
                'badge_text' => '#245f7d',
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

        $note = '参考表示 / 現行本命・買い目は変更しません';
        if ($status === 'rescue') {
            $note = '前方2期間で的中改善を再現 / ROIは期間差ありのため自動変更なし';
        } elseif ($status === 'danger') {
            $note = '前方2期間で①失敗率上昇を再現 / 危険度表示のみ';
        } elseif (!$ready) {
            $note = '最終予想がそろうと判定します';
        }

        $inner = '<div id="lane1-decision-signal-panel" style="background:' . $colors['bg']
            . '; border:1px solid ' . $colors['border']
            . '; border-radius:8px; padding:10px 12px; color:#3f4b5a;">'
            . '<div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap;">'
            . '<div style="font-size:13px; font-weight:bold; color:' . $colors['title'] . ';">🧭 ①判断シグナル</div>'
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
