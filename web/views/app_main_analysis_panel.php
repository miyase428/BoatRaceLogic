<?php
require_once __DIR__ . '/../logic/CommonSecondRuntimeBridge.php';
require_once __DIR__ . '/../logic/AiThirdCandidatePruneLogic.php';

// アプリでもPC版と同じ共通2着確率エンジンを使う。
// 本命買い目の2着候補はAI着順率v1を優先し、利用できない時だけ従来版へ戻す。
if (is_array($trifectaData ?? null) && is_array($viewData ?? null)) {
    $formalSecondTrifectaData = is_array($appFinalSecondTrifectaData ?? null)
        ? $appFinalSecondTrifectaData
        : $trifectaData;
    $commonSecondBridge = new CommonSecondRuntimeBridge();
    $commonSecondBridgeResult = $commonSecondBridge->apply(
        $viewData,
        is_array($final_predictions ?? null) ? $final_predictions : [],
        $formalSecondTrifectaData
    );

    $viewData = is_array($commonSecondBridgeResult['view_data'] ?? null)
        ? $commonSecondBridgeResult['view_data']
        : $viewData;
    $viewData = (new AiThirdCandidatePruneLogic())->apply(
        $viewData,
        is_array($ai_place_rate_data ?? null) ? $ai_place_rate_data : []
    );
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

$appAiWinApplied = (string)($aiWinStatus ?? '') === 'ok' && count($aiWinBoats ?? []) === 6;
$appAiPlaceApplied = (string)($aiPlaceStatus ?? '') === 'ok' && count($aiPlaceBoats ?? []) === 6;
$appAiTrioApplied = (string)($aiTrioDisplaySource ?? '') === 'ai_place_v1_joint120';
$appAiWinLabel = 'AI1着率' . ($appAiWinApplied ? ' v5' : '');
$appAiPlaceLabel = 'AI2連対率' . ($appAiPlaceApplied ? ' v1' : '');
$appAiTrioLabel = 'AI3連対率' . ($appAiTrioApplied ? ' v1' : '');

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
        <?php $appMainRenderRow($appAiWinLabel, static function (int $boat) use ($aiWinBoats, $appBasicPct): string {
            $rate = $aiWinBoats[(string)$boat]['ai_rate'] ?? $aiWinBoats[$boat]['ai_rate'] ?? null;
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>';
        }, 'app-basic-rate-purple'); ?>

        <div class="app-basic-section">🤖 <?= htmlspecialchars($appAiPlaceLabel, ENT_QUOTES, 'UTF-8') ?></div>
        <?php $appMainRenderRow($appAiPlaceLabel, static function (int $boat) use ($aiPlaceBoats, $appBasicPct): string {
            $rate = $aiPlaceBoats[(string)$boat]['ai_top2_rate'] ?? $aiPlaceBoats[$boat]['ai_top2_rate'] ?? null;
            $rank = (int)($aiPlaceBoats[(string)$boat]['ai_top2_rank'] ?? $aiPlaceBoats[$boat]['ai_top2_rank'] ?? 0);
            $rankHtml = $rank > 0 ? '<span class="app-basic-rank">AI ' . $rank . '位</span>' : '';
            return '<strong>' . $appBasicPct($rate, 1) . '</strong>' . $rankHtml;
        }, 'app-basic-rate-blue'); ?>

        <div class="app-basic-section">🤖 <?= htmlspecialchars($appAiTrioLabel, ENT_QUOTES, 'UTF-8') ?><?= $appAiTrioApplied ? '（機械学習）' : '（従来値）' ?></div>
        <?php $appMainRenderRow('基礎3連対率', static function (int $boat) use ($aiTrioBoats, $appBasicPct): string {
            return $appBasicPct($aiTrioBoats[$boat]['base_rate'] ?? $aiTrioBoats[(string)$boat]['base_rate'] ?? null, 1);
        }); ?>
        <?php $appMainRenderRow($appAiTrioLabel, static function (int $boat) use ($aiTrioDisplayBoats, $appBasicPct): string {
            $row = $aiTrioDisplayBoats[$boat] ?? $aiTrioDisplayBoats[(string)$boat] ?? [];
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
    <?php if ($aiWinStatus !== 'ok'): ?>
        <div class="app-basic-status">AI1着率：<?= htmlspecialchars($aiWinError ?: '展示情報待ち', ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($aiPlaceStatus !== 'ok'): ?>
        <div class="app-basic-status">AI2・3着率：<?= htmlspecialchars($aiPlaceError ?: '計算待ち', ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/app_sam_slit_panel.php'; ?>
