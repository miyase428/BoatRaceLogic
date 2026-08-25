<?php
$stadiumNonLane1Path = __DIR__ . '/../../config/stadium_non_lane1_practical.local.json';
$stadiumNonLane1All = [];

if (is_file($stadiumNonLane1Path)) {
    $json = file_get_contents($stadiumNonLane1Path);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $stadiumNonLane1All = $decoded;
    }
}

$stadiumNonLane1Meta = is_array($stadiumNonLane1All['meta'] ?? null)
    ? $stadiumNonLane1All['meta']
    : [];
$stadiumNonLane1Rows = is_array($stadiumNonLane1All['stadiums'] ?? null)
    ? $stadiumNonLane1All['stadiums']
    : [];
$stadiumNonLane1 = is_array($stadiumNonLane1Rows[$selected_place ?? ''] ?? null)
    ? $stadiumNonLane1Rows[$selected_place]
    : [];

if (!empty($stadiumNonLane1)):
    $snlEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $mode = (string)($stadiumNonLane1Mode ?? 'pc');
    $venueName = (string)($stadiumNonLane1['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $periodLabel = (string)($stadiumNonLane1Meta['label'] ?? '過去データ');
    $startDate = (string)($stadiumNonLane1Meta['start_date'] ?? '');
    $endDate = (string)($stadiumNonLane1Meta['end_date'] ?? '');
    $n = (int)($stadiumNonLane1['n'] ?? 0);
    $non1Count = (int)($stadiumNonLane1['non1_count'] ?? 0);
    $non1Rate = (float)($stadiumNonLane1['non1_rate'] ?? 0.0);
    $non1Diff = (float)($stadiumNonLane1['non1_vs_all_diff'] ?? 0.0);
    $winnerCourses = is_array($stadiumNonLane1['winner_course'] ?? null)
        ? $stadiumNonLane1['winner_course']
        : [];
    $ranking = is_array($stadiumNonLane1['winner_course_ranking'] ?? null)
        ? $stadiumNonLane1['winner_course_ranking']
        : [];
    $technique = is_array($stadiumNonLane1['technique'] ?? null)
        ? $stadiumNonLane1['technique']
        : [];
    $remain = is_array($stadiumNonLane1['lane1_remain'] ?? null)
        ? $stadiumNonLane1['lane1_remain']
        : [];

    $diffText = static function (float $diff): string {
        return ($diff >= 0 ? '+' : '') . number_format($diff, 1) . 'pt';
    };

    $topTextParts = [];
    foreach (array_slice($ranking, 0, 5) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $course = (int)($row['course'] ?? 0);
        if ($course < 2 || $course > 6) {
            continue;
        }
        $topTextParts[] = $course . 'C ' . number_format((float)($row['rate'] ?? 0.0), 1) . '%';
    }
    $topText = implode(' → ', $topTextParts);

    $techTextParts = [];
    foreach (['差し', 'まくり', 'まくり差し', '抜き', '恵まれ', 'その他'] as $name) {
        $row = is_array($technique[$name] ?? null) ? $technique[$name] : [];
        $count = (int)($row['count'] ?? 0);
        if ($count <= 0) {
            continue;
        }
        $techTextParts[] = $name . ' ' . number_format((float)($row['rate'] ?? 0.0), 1) . '%';
    }
    $techText = implode(' / ', $techTextParts);
?>
<?php if ($mode === 'app'): ?>
    <div style="margin:0 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffaf2; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">⚠️ <?= $snlEsc($venueName) ?> イン敗戦時</div>
            <div style="font-size:10px; color:#6b7785;"><?= $snlEsc($periodLabel) ?></div>
        </div>

        <div style="display:flex; gap:7px; align-items:center; flex-wrap:wrap; margin-top:7px;">
            <strong style="font-size:12px;">1C敗戦 <?= number_format($non1Rate, 1) ?>%</strong>
            <span style="font-size:10px; color:#6b7785;">全場比 <?= $snlEsc($diffText($non1Diff)) ?> / <?= number_format($non1Count) ?>R</span>
        </div>

        <?php if ($topText !== ''): ?>
            <div style="margin-top:7px; font-size:11px; line-height:1.6;">
                <div>敗戦時の頭 <strong><?= $snlEsc($topText) ?></strong></div>
                <div>①残り <strong><?= number_format((float)($remain['top3_rate'] ?? 0.0), 1) ?>%</strong>
                    <span style="font-size:10px; color:#6b7785;">（2着 <?= number_format((float)($remain['second_rate'] ?? 0.0), 1) ?>% / 3着 <?= number_format((float)($remain['third_rate'] ?? 0.0), 1) ?>%）</span>
                </div>
            </div>
        <?php endif; ?>

        <details style="margin-top:6px; font-size:10px; color:#6b7785;">
            <summary style="cursor:pointer;">頭コース・決まり手の詳細</summary>
            <div style="margin-top:5px; line-height:1.65;">
                <?php for ($course = 2; $course <= 6; $course++): ?>
                    <?php $row = is_array($winnerCourses[(string)$course] ?? null) ? $winnerCourses[(string)$course] : []; ?>
                    <?= $course ?>C <?= number_format((float)($row['rate'] ?? 0.0), 1) ?>%<?= $course < 6 ? ' / ' : '' ?>
                <?php endfor; ?><br>
                <?= $snlEsc($techText) ?><br>
                ①圏外 <?= number_format((float)($remain['out_rate'] ?? 0.0), 1) ?>% / <?= $snlEsc($startDate) ?>〜<?= $snlEsc($endDate) ?>
            </div>
        </details>
    </div>
<?php else: ?>
    <div style="margin:14px 0; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
            <div>
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">⚠️ イン敗戦時の場特性</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $snlEsc($venueName) ?> / <?= $snlEsc($periodLabel) ?>（<?= $snlEsc($startDate) ?>〜<?= $snlEsc($endDate) ?> / 全<?= number_format($n) ?>R）
                </div>
            </div>
            <div style="font-size:12px; color:var(--text-muted);">1C敗戦 <?= number_format($non1Count) ?>R</div>
        </div>

        <div style="display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:13px;">
            <div>1C敗戦率 <strong style="color:var(--text-strong); font-size:17px;"><?= number_format($non1Rate, 2) ?>%</strong></div>
            <div>全場比 <strong style="color:var(--text-strong);"><?= $snlEsc($diffText($non1Diff)) ?></strong></div>
            <div>敗戦時①残り <strong style="color:var(--text-strong);"><?= number_format((float)($remain['top3_rate'] ?? 0.0), 2) ?>%</strong></div>
            <div>①圏外 <strong style="color:var(--text-strong);"><?= number_format((float)($remain['out_rate'] ?? 0.0), 2) ?>%</strong></div>
        </div>

        <div style="margin-top:10px; overflow-x:auto;">
            <table style="width:100%; min-width:560px; border-collapse:collapse; font-size:11px; text-align:center;">
                <thead>
                <tr>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left;">1C敗戦時の勝ちコース</th>
                    <?php for ($course = 2; $course <= 6; $course++): ?>
                        <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);"><?= $course ?>C</th>
                    <?php endfor; ?>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <th style="padding:7px; border:1px solid var(--border); background:var(--surface); text-align:left;">頭率</th>
                    <?php for ($course = 2; $course <= 6; $course++): ?>
                        <?php $row = is_array($winnerCourses[(string)$course] ?? null) ? $winnerCourses[(string)$course] : []; ?>
                        <td style="padding:7px; border:1px solid var(--border); white-space:nowrap;">
                            <strong style="color:var(--text-strong); font-size:13px;"><?= number_format((float)($row['rate'] ?? 0.0), 2) ?>%</strong>
                            <div style="margin-top:2px; font-size:9px; color:var(--text-muted);">全場比 <?= $snlEsc($diffText((float)($row['vs_all'] ?? 0.0))) ?></div>
                        </td>
                    <?php endfor; ?>
                </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:8px; padding:8px 10px; border:1px solid var(--border); border-radius:7px; background:var(--surface); font-size:12px; line-height:1.7;">
            <div><strong>実戦上位:</strong> <?= $topText !== '' ? $snlEsc($topText) : '-' ?></div>
            <div><strong>①残り:</strong> 2着 <?= number_format((float)($remain['second_rate'] ?? 0.0), 1) ?>% / 3着 <?= number_format((float)($remain['third_rate'] ?? 0.0), 1) ?>% / 2・3着合計 <?= number_format((float)($remain['top3_rate'] ?? 0.0), 1) ?>% / 圏外 <?= number_format((float)($remain['out_rate'] ?? 0.0), 1) ?>%</div>
        </div>

        <details style="margin-top:8px; font-size:11px; color:var(--text-muted);">
            <summary style="cursor:pointer;">イン敗戦時の決まり手</summary>
            <div style="margin-top:5px; line-height:1.65;">
                <?= $techText !== '' ? $snlEsc($techText) : '-' ?><br>
                ※すべて「1Cが1着ではなかったレース」を分母にした割合。表示専用で、最終予想・買い目補正には未接続。
            </div>
        </details>
    </div>
<?php endif; ?>
<?php endif; ?>
