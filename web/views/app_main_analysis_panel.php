<?php
require_once __DIR__ . '/../logic/CommonSecondRuntimeBridge.php';

// アプリでもPC版と同じ共通2着確率エンジンを使う。
// app.phpで作成済みの120通り出目確率を再利用し、
// 1C頭2連単表示と本命買い目の2着候補を同じ③ AI_FINALへ揃える。
if (is_array($trifectaData ?? null) && is_array($viewData ?? null)) {
    $commonSecondBridge = new CommonSecondRuntimeBridge();
    $commonSecondBridgeResult = $commonSecondBridge->apply(
        $viewData,
        is_array($final_predictions ?? null) ? $final_predictions : [],
        $trifectaData
    );

    $viewData = is_array($commonSecondBridgeResult['view_data'] ?? null)
        ? $commonSecondBridgeResult['view_data']
        : $viewData;
    extract($viewData, EXTR_OVERWRITE);

    $head1CommonData = is_array($commonSecondBridgeResult['head1'] ?? null)
        ? $commonSecondBridgeResult['head1']
        : [];
    $appHead1ExactaRows = (string)($head1CommonData['status'] ?? '') === 'ok'
        && is_array($head1CommonData['rows'] ?? null)
        ? $head1CommonData['rows']
        : [];
}

// app_basic_info_panel.php で作成した6艇マップと表示ヘルパーを共用する。
// メイン情報はPC版と同じく、艇番順ではなく「現在の進入コース順」で並べる。
$appMainBoatOrder = [];
for ($boat = 1; $boat <= 6; $boat++) {
    $course = (int)($appBasicCourseByBoat[$boat] ?? $boat);
    if ($course >= 1 && $course <= 6) {
        $appMainBoatOrder[$course] = $boat;
    }
}
for ($course = 1; $course <= 6; $course++) {
    if (!isset($appMainBoatOrder[$course])) {
        $appMainBoatOrder[$course] = $course;
    }
}
ksort($appMainBoatOrder);

$appMainRenderRow = static function (string $label, callable $valueFn, string $extraClass = '') use ($appMainBoatOrder): void {
    echo '<div class="app-basic-label ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') . '">' . $label . '</div>';
    for ($course = 1; $course <= 6; $course++) {
        $boat = (int)($appMainBoatOrder[$course] ?? $course);
        echo '<div class="app-basic-value ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') . '">';
        echo $valueFn($boat);
        echo '</div>';
    }
};
?>
<section class="app-card app-basic-card app-analysis-card">
    <div class="app-basic-grid">
        <div class="app-basic-section">🚤 艇番・進入</div>
        <div class="app-basic-label app-basic-head-label">進入</div>
        <?php for ($course = 1; $course <= 6; $course++): ?>
            <?php $boat = (int)($appMainBoatOrder[$course] ?? $course); ?>
            <div class="app-basic-value app-basic-head-cell app-main-head-cell"><?= $appBasicBoatHeader($boat) ?></div>
        <?php endfor; ?>

        <div class="app-basic-section">🎯 1着率</div>
        <?php $appMainRenderRow('場1着率', static function (int $boat) use ($baseWinBoats, $appBasicPct): string {
            return $appBasicPct($baseWinBoats[$boat]['p0'] ?? null, 1, 100.0);
        }); ?>
        <?php $appMainRenderRow('基本1着率', static function (int $boat) use ($baseWinBoats, $appBasicPct): string {
            return '<strong>' . $appBasicPct($baseWinBoats[$boat]['normalized_rate'] ?? null, 1) . '</strong>';
        }, 'app-basic-rate-blue'); ?>
        <?php $appMainRenderRow('補正後1着率', static function (int $boat) use ($correctedWinBoats, $appBasicPct): string {
            $rate = $correctedWinBoats[(string)$boat]['corrected_rate'] ?? $correctedWinBoats[$boat]['corrected_rate'] ?? null;
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>';
        }, 'app-basic-rate-gold'); ?>

        <div class="app-basic-section">🎯 1号艇1着時の2着率</div>
        <?php $appMainRenderRow('場2着率', static function (int $boat) use ($head1SecondBoats, $appBasicPct): string {
            if ($boat === 1) return '-';
            return $appBasicPct($head1SecondBoats[$boat]['venue_rate'] ?? null, 1);
        }); ?>
        <?php $appMainRenderRow('基本2着率', static function (int $boat) use ($head1SecondBoats, $appBasicPct): string {
            if ($boat === 1) return '-';
            return '<strong>' . $appBasicPct($head1SecondBoats[$boat]['basic_rate'] ?? null, 1) . '</strong>';
        }, 'app-basic-rate-purple'); ?>

        <div class="app-basic-section">🤖 AI3連対率</div>
        <?php $appMainRenderRow('基礎3連対率', static function (int $boat) use ($aiTrioBoats, $appBasicPct): string {
            return $appBasicPct($aiTrioBoats[$boat]['base_rate'] ?? $aiTrioBoats[(string)$boat]['base_rate'] ?? null, 1);
        }); ?>
        <?php $appMainRenderRow('AI3連対率', static function (int $boat) use ($aiTrioBoats, $appBasicPct): string {
            $row = $aiTrioBoats[$boat] ?? $aiTrioBoats[(string)$boat] ?? [];
            $rate = $row['ai_rate'] ?? null;
            $rank = (int)($row['ai_rank'] ?? 0);
            $rankHtml = $rank > 0 ? '<span class="app-basic-rank">AI ' . $rank . '位</span>' : '';
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>' . $rankHtml;
        }, 'app-basic-rate-purple'); ?>

        <div class="app-basic-section">📊 一次評価</div>
        <?php $appMainRenderRow('地力スコア', static function (int $boat) use ($appBasicResults, $appBasicNum): string {
            return $appBasicNum($appBasicResults[$boat]['jiryoku_score'] ?? null, 3);
        }); ?>
        <?php $appMainRenderRow('一次総合', static function (int $boat) use ($appBasicResults, $appBasicNum): string {
            return '<strong>' . $appBasicNum($appBasicResults[$boat]['total_score'] ?? null, 3) . '</strong>';
        }, 'app-basic-rate-blue'); ?>
        <?php $appMainRenderRow('足スコア', static function (int $boat) use ($appBasicResults, $appBasicNum): string {
            return $appBasicNum($appBasicResults[$boat]['ashi_score'] ?? null, 3);
        }); ?>
        <?php $appMainRenderRow('一次評価', static function (int $boat) use ($appBasicResults, $appBasicEsc): string {
            return '<strong>' . $appBasicEsc($appBasicResults[$boat]['ichiji_eval'] ?? '-') . '</strong>';
        }, 'app-basic-eval'); ?>

        <div class="app-basic-section">⏱ 展示・加工評価</div>
        <?php $appMainRenderRow('展示タイム\n場平均差', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['ex_diff'] ?? null, 2);
        }, 'app-basic-small-label'); ?>
        <?php $appMainRenderRow('展示タイム評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['ex_score'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appMainRenderRow('ST評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['st_score'] ?? '-');
        }); ?>
        <?php $appMainRenderRow('周回評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['lap_score'] ?? '-');
        }); ?>
        <?php $appMainRenderRow('周り足評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['mawari_score'] ?? '-');
        }); ?>
        <?php $appMainRenderRow('直線評価', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['straight_score'] ?? '-');
        }); ?>
        <?php $appMainRenderRow('展示足トータル', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['ex_total'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appMainRenderRow('攻めポテンシャル', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['attack_potential'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appMainRenderRow('展示安定感', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['stable_score'] ?? '-');
        }); ?>
        <?php $appMainRenderRow('展示補正スコア', static function (int $boat) use ($appBasicTenji, $appBasicNum): string {
            return $appBasicNum($appBasicTenji[$boat]['ex_hosei'] ?? null, 3);
        }, 'app-basic-small-label'); ?>
        <?php $appMainRenderRow('展示総合スコア', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['ex_sougou'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appMainRenderRow('展示タイプ名', static function (int $boat) use ($appBasicTenji, $appBasicTypeBadge): string {
            return $appBasicTypeBadge($appBasicTenji[$boat]['dtype'] ?? '');
        }, 'app-basic-compact'); ?>
        <?php $appMainRenderRow('展開キー', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['tenkai_key'] ?? '-');
        }); ?>
        <?php $appMainRenderRow('展開もらい補正', static function (int $boat) use ($appBasicTenji, $appBasicEsc): string {
            return $appBasicEsc($appBasicTenji[$boat]['tenkai_morai'] ?? '-');
        }, 'app-basic-small-label'); ?>
        <?php $appMainRenderRow('最終二次\n予想スコア', static function (int $boat) use ($appBasicTenji): string {
            $v = $appBasicTenji[$boat]['final_2nd_score'] ?? null;
            return is_numeric($v) ? '<strong>' . number_format((float)$v, 0) . '</strong>' : '-';
        }, 'app-basic-score app-basic-small-label'); ?>

        <div class="app-basic-section">決まり手</div>
        <div class="app-basic-period">直近6ヶ月</div>
        <?php foreach (['逃げ / 逃がし', '差され / 差し', '捲られ / 捲り', '捲られ差 / 捲り差し'] as $label): ?>
            <?php $appMainRenderRow($label, static function (int $boat) use ($appBasicKimarite, $label): string {
                return $appBasicKimarite($boat, '6month', $label);
            }, 'app-basic-kimarite'); ?>
        <?php endforeach; ?>

        <div class="app-basic-period">直近1年</div>
        <?php foreach (['逃げ / 逃がし', '差され / 差し', '捲られ / 捲り', '捲られ差 / 捲り差し'] as $label): ?>
            <?php $appMainRenderRow($label, static function (int $boat) use ($appBasicKimarite, $label): string {
                return $appBasicKimarite($boat, '1year', $label);
            }, 'app-basic-kimarite'); ?>
        <?php endforeach; ?>
    </div>

    <?php if ($correctedWinStatus !== 'ok'): ?>
        <div class="app-basic-status">補正後1着率：<?= htmlspecialchars($correctedWinError ?: '展示情報待ち', ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</section>