<?php
$appCourseToBoat = [];
if (is_array($prediction_boat_by_course ?? null) && count($prediction_boat_by_course) === 6) {
    for ($course = 1; $course <= 6; $course++) {
        $boat = (int)($prediction_boat_by_course[$course] ?? $course);
        $appCourseToBoat[$course] = ($boat >= 1 && $boat <= 6) ? $boat : $course;
    }
} else {
    $appCourseToBoat = array_combine(range(1, 6), range(1, 6));
}

$appEntriesByBoat = [];
foreach (is_array($entries ?? null) ? $entries : [] as $entry) {
    $boat = (int)($entry['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $appEntriesByBoat[$boat] = $entry;
    }
}

$appTenjiByBoat = [];
foreach (is_array($tenji_list ?? null) ? $tenji_list : [] as $row) {
    $boat = (int)($row['teiban'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $appTenjiByBoat[$boat] = $row;
    }
}

$appBoatBadge = static function (int $boat) use ($lane_colors): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    return '<span class="app-boat-badge" style="background:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:' . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border:1px solid ' . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';">' . $boat . '号</span>';
};

$appRate = static function ($value, int $digits = 1): string {
    return is_numeric($value) ? number_format((float)$value, $digits) . '%' : '-';
};

$appCurrentQuery = http_build_query([
    'date' => (string)$selected_date,
    'place' => (string)$selected_place,
    'race' => (string)$selected_race,
    'simulate_entry' => !empty($simulate_entry) ? '1' : null,
    'virtual_entry' => (string)($virtual_entry ?? '123456'),
]);
$appPcUrl = '/web/index.php?' . $appCurrentQuery;
$appPostUrl = '/web/app.php?' . $appCurrentQuery;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#1683bd">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BoatRace">
    <title>BoatRace</title>
    <link rel="manifest" href="/web/manifest.webmanifest">
    <link rel="stylesheet" href="/web/assets/css/app.css">
</head>
<body>
<div class="app-shell">
    <header class="app-header">
        <h1 class="app-title">艇 BoatRace</h1>
        <div class="app-race-label">
            <?= htmlspecialchars((string)($place_names[$selected_place] ?? $selected_place), ENT_QUOTES, 'UTF-8') ?>
            <?= (int)$selected_race ?>R
        </div>
    </header>

    <section class="app-card">
        <div class="app-card-body">
            <form method="GET" action="/web/app.php">
                <div class="app-form-grid">
                    <div class="app-form-field">
                        <label>日付</label>
                        <input type="date" name="date" value="<?= htmlspecialchars((string)$selected_date, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="app-form-field">
                        <label>開催場</label>
                        <select name="place">
                            <?php foreach ($place_names as $code => $name): ?>
                                <option value="<?= htmlspecialchars((string)$code, ENT_QUOTES, 'UTF-8') ?>" <?= $selected_place === $code ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="app-form-field">
                        <label>R</label>
                        <select name="race">
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <?php $r = sprintf('%02d', $i); ?>
                                <option value="<?= $r ?>" <?= $selected_race === $r ? 'selected' : '' ?>><?= $i ?>R</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <details class="app-entry-details" <?= !empty($simulate_entry) ? 'open' : '' ?>>
                    <summary>進入シミュレーション</summary>
                    <div class="app-entry-row">
                        <span>展示進入 <strong><?= htmlspecialchars((string)($entry_map_ready ? $exhibition_entry_order : '待ち'), ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <label>
                            <input type="checkbox" name="simulate_entry" value="1" <?= !empty($simulate_entry) ? 'checked' : '' ?>>
                            仮想進入
                        </label>
                        <input type="text" name="virtual_entry" maxlength="6" inputmode="numeric" value="<?= htmlspecialchars((string)($virtual_entry ?? '123456'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </details>

                <div class="app-actions">
                    <button class="app-btn" type="submit">レース表示</button>
                    <button class="app-btn app-btn-secondary" type="button" onclick="location.reload()">再読込</button>
                </div>
            </form>

            <form method="POST" action="<?= htmlspecialchars($appPostUrl, ENT_QUOTES, 'UTF-8') ?>" style="margin-top:7px;">
                <input type="hidden" name="race_code" value="<?= htmlspecialchars((string)$race_code, ENT_QUOTES, 'UTF-8') ?>">
                <button class="app-btn app-btn-secondary" style="width:100%;" type="submit" name="update_exhibition" value="1">🔄 展示情報を更新</button>
            </form>

            <?php if (!empty($update_message)): ?>
                <div class="app-note"><?= htmlspecialchars((string)$update_message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if (!empty($virtual_entry_error)): ?>
                <div class="app-note" style="color:#a74932;"><?= htmlspecialchars((string)$virtual_entry_error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="app-code"><?= htmlspecialchars((string)$race_code, ENT_QUOTES, 'UTF-8') ?></div>
        </div>
    </section>

    <section class="app-card">
        <div class="app-card-body" style="padding-bottom:8px;">
            <h2 class="app-section-title">クイック比較</h2>
            <div class="app-note" style="margin-top:-4px;">計算ロジックはPC版と共通。表示だけアプリ用に圧縮。</div>
        </div>
        <div class="app-compare">
            <div class="app-compare-label">進入</div>
            <?php for ($course = 1; $course <= 6; $course++): ?>
                <?php $boat = (int)($appCourseToBoat[$course] ?? $course); ?>
                <div class="app-compare-head">
                    <span><?= $course ?>C</span>
                    <span style="margin-top:3px;"><?= $appBoatBadge($boat) ?></span>
                </div>
            <?php endfor; ?>

            <div class="app-compare-label">基本<br>1着</div>
            <?php for ($course = 1; $course <= 6; $course++): ?>
                <?php
                    $boat = (int)($appCourseToBoat[$course] ?? $course);
                    $rate = $baseWinBoats[$boat]['normalized_rate'] ?? null;
                ?>
                <div><span class="app-rate"><?= $appRate($rate) ?></span></div>
            <?php endfor; ?>

            <div class="app-compare-label">補正<br>1着</div>
            <?php for ($course = 1; $course <= 6; $course++): ?>
                <?php
                    $boat = (int)($appCourseToBoat[$course] ?? $course);
                    $rate = $correctedWinBoats[(string)$boat]['corrected_rate']
                        ?? $correctedWinBoats[$boat]['corrected_rate']
                        ?? null;
                ?>
                <div><span class="app-rate app-rate-win"><?= $appRate($rate) ?></span></div>
            <?php endfor; ?>

            <div class="app-compare-label">AI<br>3連</div>
            <?php for ($course = 1; $course <= 6; $course++): ?>
                <?php
                    $boat = (int)($appCourseToBoat[$course] ?? $course);
                    $row = $aiTrioBoats[$boat] ?? $aiTrioBoats[(string)$boat] ?? [];
                    $rate = $row['ai_rate'] ?? null;
                    $rank = (int)($row['ai_rank'] ?? 0);
                ?>
                <div>
                    <span class="app-rate app-rate-ai"><?= $appRate($rate) ?></span>
                    <?= $rank > 0 ? '<span class="app-rate-sub">' . $rank . '位</span>' : '' ?>
                </div>
            <?php endfor; ?>

            <div class="app-compare-label">1号頭<br>2着</div>
            <?php for ($course = 1; $course <= 6; $course++): ?>
                <?php
                    $boat = (int)($appCourseToBoat[$course] ?? $course);
                    $rate = $head1SecondBoats[$boat]['basic_rate']
                        ?? $head1SecondBoats[(string)$boat]['basic_rate']
                        ?? null;
                ?>
                <div><span class="app-rate"><?= $boat === 1 ? '-' : $appRate($rate) ?></span></div>
            <?php endfor; ?>

            <div class="app-compare-label">最終<br>score</div>
            <?php for ($course = 1; $course <= 6; $course++): ?>
                <?php
                    $boat = (int)($appCourseToBoat[$course] ?? $course);
                    $score = $final_predictions[$boat]['final3'] ?? null;
                ?>
                <div><span class="app-rate app-rate-score"><?= is_numeric($score) ? number_format((float)$score, 0) : '-' ?></span></div>
            <?php endfor; ?>
        </div>
        <?php if ($correctedWinStatus !== 'ok' || $aiTrioStatus !== 'ok' || $head1SecondStatus !== 'ok'): ?>
            <div class="app-card-body" style="padding-top:7px;">
                <?php if ($correctedWinStatus !== 'ok'): ?><div class="app-note">補正1着：<?= htmlspecialchars($correctedWinError ?: '展示情報待ち', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($aiTrioStatus !== 'ok'): ?><div class="app-note">AI3連：<?= htmlspecialchars($aiTrioError ?: '計算待ち', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($head1SecondStatus !== 'ok'): ?><div class="app-note">1号頭2着：<?= htmlspecialchars($head1SecondError ?: '計算待ち', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="app-card">
        <div class="app-card-body">
            <h2 class="app-section-title">📊 最終予想</h2>
            <div class="app-summary-grid">
                <div class="app-summary-item">
                    <div class="app-summary-label">本命</div>
                    <div class="app-summary-main">
                        <?= $appBoatBadge((int)($honmei_head ?? 1)) ?>
                        <span>相手 <?= htmlspecialchars((string)($honmei_aite_str ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <div class="app-summary-item">
                    <div class="app-summary-label">対抗</div>
                    <div class="app-summary-main">
                        <?= $appBoatBadge((int)($taikou_head ?? 2)) ?>
                        <span>相手 <?= htmlspecialchars((string)($taikou_aite_str ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <div class="app-summary-item app-buy">
                    <div class="app-summary-label">買い目候補（現行ロジック）</div>
                    <div class="app-buy-line"><span>本命</span><strong><?= htmlspecialchars((string)($honmei_kai ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <div class="app-buy-line"><span>対抗</span><strong><?= htmlspecialchars((string)($taikou_kai ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <?php if (!empty($kiru_str)): ?>
                        <div class="app-note">本命側の切る艇：<?= htmlspecialchars((string)$kiru_str, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <?php include __DIR__ . '/upset_alert_panel.php'; ?>

    <details class="app-card app-details">
        <summary>展示・選手の詳細</summary>
        <div class="app-details-body">
            <?php for ($course = 1; $course <= 6; $course++): ?>
                <?php
                    $boat = (int)($appCourseToBoat[$course] ?? $course);
                    $entry = $appEntriesByBoat[$boat] ?? [];
                    $tenji = $appTenjiByBoat[$boat] ?? [];
                    $name = (string)($entry['player_name'] ?? '-');
                    $class = (string)($entry['class'] ?? '-');
                    $ex = $tenji['exhibition'] ?? null;
                    $st = $tenji['st'] ?? null;
                ?>
                <div class="app-boat-detail">
                    <div><?= $appBoatBadge($boat) ?><div class="app-rate-sub" style="text-align:center;margin-top:3px;"><?= $course ?>C</div></div>
                    <div class="app-boat-detail-name">
                        <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
                        <div class="app-rate-sub" style="font-size:9px;margin-top:3px;"><?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div class="app-boat-detail-data">
                        展示 <?= is_numeric($ex) ? number_format((float)$ex, 2) : '-' ?><br>
                        ST <?= is_numeric($st) ? number_format((float)$st, 2) : '-' ?>
                    </div>
                </div>
            <?php endfor; ?>
            <a class="app-pc-link" href="<?= htmlspecialchars($appPcUrl, ENT_QUOTES, 'UTF-8') ?>">PC版の詳細画面を開く</a>
        </div>
    </details>
</div>

<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/web/service-worker.js').catch(function () {});
    });
}
</script>
</body>
</html>
