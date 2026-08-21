<?php
// app_basic_info_panel.php で作成した6艇マップと表示ヘルパーを共用する。
?>
<section class="app-card app-basic-card app-analysis-card">
    <div class="app-basic-grid">
        <div class="app-basic-section">🚤 艇番・進入</div>
        <div class="app-basic-label app-basic-head-label">艇</div>
        <?php for ($boat = 1; $boat <= 6; $boat++): ?>
            <div class="app-basic-value app-basic-head-cell app-main-head-cell"><?= $appBasicBoatHeader($boat) ?></div>
        <?php endfor; ?>

        <div class="app-basic-section">🎯 1着率</div>
        <?php $appBasicRenderRow('場1着率', static function (int $boat) use ($baseWinBoats, $appBasicPct): string {
            return $appBasicPct($baseWinBoats[$boat]['p0'] ?? null, 1, 100.0);
        }); ?>
        <?php $appBasicRenderRow('基本1着率', static function (int $boat) use ($baseWinBoats, $appBasicPct): string {
            return '<strong>' . $appBasicPct($baseWinBoats[$boat]['normalized_rate'] ?? null, 1) . '</strong>';
        }, 'app-basic-rate-blue'); ?>
        <?php $appBasicRenderRow('補正後1着率', static function (int $boat) use ($correctedWinBoats, $appBasicPct): string {
            $rate = $correctedWinBoats[(string)$boat]['corrected_rate'] ?? $correctedWinBoats[$boat]['corrected_rate'] ?? null;
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>';
        }, 'app-basic-rate-gold'); ?>

        <div class="app-basic-section">🎯 1号艇1着時の2着率</div>
        <?php $appBasicRenderRow('場2着率', static function (int $boat) use ($head1SecondBoats, $appBasicPct): string {
            if ($boat === 1) return '-';
            return $appBasicPct($head1SecondBoats[$boat]['venue_rate'] ?? null, 1);
        }); ?>
        <?php $appBasicRenderRow('基本2着率', static function (int $boat) use ($head1SecondBoats, $appBasicPct): string {
            if ($boat === 1) return '-';
            return '<strong>' . $appBasicPct($head1SecondBoats[$boat]['basic_rate'] ?? null, 1) . '</strong>';
        }, 'app-basic-rate-purple'); ?>

        <div class="app-basic-section">🤖 AI3連対率</div>
        <?php $appBasicRenderRow('基礎3連対率', static function (int $boat) use ($aiTrioBoats, $appBasicPct): string {
            return $appBasicPct($aiTrioBoats[$boat]['base_rate'] ?? $aiTrioBoats[(string)$boat]['base_rate'] ?? null, 1);
        }); ?>
        <?php $appBasicRenderRow('AI3連対率', static function (int $boat) use ($aiTrioBoats, $appBasicPct): string {
            $row = $aiTrioBoats[$boat] ?? $aiTrioBoats[(string)$boat] ?? [];
            $rate = $row['ai_rate'] ?? null;
            $rank = (int)($row['ai_rank'] ?? 0);
            $rankHtml = $rank > 0 ? '<span class="app-basic-rank">AI ' . $rank . '位</span>' : '';
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>' . $rankHtml;
        }, 'app-basic-rate-purple'); ?>

        <div class="app-basic-section">📊 一次評価</div>
        <?php $appBasicRenderRow('地力スコア', static function (int $boat) use ($appBasicResults, $appBasicNum): string {
            return $appBasicNum($appBasicResults[$boat]['jiryoku_score'] ?? null, 3);
        }); ?>
        <?php $appBasicRenderRow('一次総合', static function (int $boat) use ($appBasicResults, $appBasicNum): string {
            return '<strong>' . $appBasicNum($appBasicResults[$boat]['total_score'] ?? null, 3) . '</strong>';
        }, 'app-basic-rate-blue'); ?>
        <?php $appBasicRenderRow('足スコア', static function (int $boat) use ($appBasicResults, $appBasicNum): string {
            return $appBasicNum($appBasicResults[$boat]['ashi_score'] ?? null, 3);
        }); ?>
        <?php $appBasicRenderRow('一次評価', static function (int $boat) use ($appBasicResults, $appBasicEsc): string {
            return '<strong>' . $appBasicEsc($appBasicResults[$boat]['ichiji_eval'] ?? '-') . '</strong>';
        }, 'app-basic-eval'); ?>

        <div class="app-basic-section">⏱ 展示・加工評価</div>
        <?php $appBasicRenderRow('展示タイム\n場平均差', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['ex_diff'] ?? null, 2);
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('展示タイム評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['ex_score'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('ST評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['st_score'] ?? '-');
        }); ?>
        <?php $appBasicRenderRow('周回評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['lap_score'] ?? '-');
        }); ?>
        <?php $appBasicRenderRow('周り足評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['mawari_score'] ?? '-');
        }); ?>
        <?php $appBasicRenderRow('直線評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['straight_score'] ?? '-');
        }); ?>
        <?php $appBasicRenderRow('展示足トータル', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['ex_total'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('攻めポテンシャル', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['attack_potential'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('展示安定感', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['stable_score'] ?? '-');
        }); ?>
        <?php $appBasicRenderRow('展示補正スコア', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['ex_hosei'] ?? null, 3);
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('展示総合スコア', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['ex_sougou'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('展示タイプ名', static function (int $boat) use ($appBasicTenji, $appBasicTypeBadge): string {
            return $appBasicTypeBadge($appBasicTenji[$boat]['dtype'] ?? '');
        }, 'app-basic-compact'); ?>
        <?php $appBasicRenderRow('展開キー', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['tenkai_key'] ?? '-');
        }); ?>
        <?php $appBasicRenderRow('展開もらい補正', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['tenkai_morai'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appBasicRenderRow('最終二次\n予想スコア', static function (int $boat) use ($appBasicTenji): string {
            $v = $appBasicTenji[$boat]['final_2nd_score'] ?? null;
            return is_numeric($v) ? '<strong>' . number_format((float)$v, 0) . '</strong>' : '-';
        }, 'app-basic-score app-basic-small-label'); ?>

        <div class="app-basic-section">決まり手</div>
        <div class="app-basic-period">直近6ヶ月</div>
        <?php foreach (['逃げ / 逃がし', '差され / 差し', '捲られ / 捲り', '捲られ差 / 捲り差し'] as $label): ?>
            <?php $appBasicRenderRow($label, static function (int $boat) use ($appBasicKimarite, $label): string {
                return $appBasicKimarite($boat, '6month', $label);
            }, 'app-basic-kimarite'); ?>
        <?php endforeach; ?>

        <div class="app-basic-period">直近1年</div>
        <?php foreach (['逃げ / 逃がし', '差され / 差し', '捲られ / 捲り', '捲られ差 / 捲り差し'] as $label): ?>
            <?php $appBasicRenderRow($label, static function (int $boat) use ($appBasicKimarite, $label): string {
                return $appBasicKimarite($boat, '1year', $label);
            }, 'app-basic-kimarite'); ?>
        <?php endforeach; ?>
    </div>

    <?php if ($correctedWinStatus !== 'ok'): ?>
        <div class="app-basic-status">補正後1着率：<?= htmlspecialchars($correctedWinError ?: '展示情報待ち', ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</section>