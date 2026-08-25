<?php
$stadiumOuterPath = __DIR__ . '/../../config/stadium_outer_reach.local.json';
$stadiumOuterAll = [];

if (is_file($stadiumOuterPath)) {
    $json = file_get_contents($stadiumOuterPath);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $stadiumOuterAll = $decoded;
    }
}

$stadiumOuterMeta = is_array($stadiumOuterAll['meta'] ?? null)
    ? $stadiumOuterAll['meta']
    : [];
$stadiumOuterRows = is_array($stadiumOuterAll['stadiums'] ?? null)
    ? $stadiumOuterAll['stadiums']
    : [];
$stadiumOuter = is_array($stadiumOuterRows[$selected_place ?? ''] ?? null)
    ? $stadiumOuterRows[$selected_place]
    : [];

if (!empty($stadiumOuter)):
    $soEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $mode = (string)($stadiumOuterMode ?? 'pc');
    $venueName = (string)($stadiumOuter['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $periodLabel = (string)($stadiumOuterMeta['label'] ?? '過去データ');
    $startDate = (string)($stadiumOuterMeta['start_date'] ?? '');
    $endDate = (string)($stadiumOuterMeta['end_date'] ?? '');

    $contexts = [
        'all' => '全レース',
        'escape' => '1逃げ時',
        'non1' => 'イン敗戦時',
    ];

    $metric = static function (array $ctx, int $course, string $name): array {
        $courses = is_array($ctx['courses'] ?? null) ? $ctx['courses'] : [];
        $row = is_array($courses[(string)$course] ?? null) ? $courses[(string)$course] : [];
        $vsAll = is_array($row['vs_all'] ?? null) ? $row['vs_all'] : [];
        return [
            'rate' => (float)($row[$name . '_rate'] ?? 0.0),
            'diff' => (float)($vsAll[$name] ?? 0.0),
        ];
    };

    $diffText = static function (float $diff): string {
        return ($diff >= 0 ? '+' : '') . number_format($diff, 1) . 'pt';
    };

    $allCtx = is_array($stadiumOuter['all'] ?? null) ? $stadiumOuter['all'] : [];
    $escapeCtx = is_array($stadiumOuter['escape'] ?? null) ? $stadiumOuter['escape'] : [];
    $non1Ctx = is_array($stadiumOuter['non1'] ?? null) ? $stadiumOuter['non1'] : [];
?>
<?php if ($mode === 'app'): ?>
    <div style="margin:0 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffaf2; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">🌊 <?= $soEsc($venueName) ?> 外枠到達率</div>
            <div style="font-size:10px; color:#6b7785;"><?= $soEsc($periodLabel) ?></div>
        </div>

        <div style="margin-top:7px; font-size:10px; color:#6b7785; line-height:1.6;">
            全R ⑤⑥どちらか3連対 <strong style="color:#334155;"><?= number_format((float)($allCtx['outer_any_top3_rate'] ?? 0.0), 1) ?>%</strong> /
            1逃げ時 <strong style="color:#334155;"><?= number_format((float)($escapeCtx['outer_any_top3_rate'] ?? 0.0), 1) ?>%</strong> /
            イン敗戦時 <strong style="color:#334155;"><?= number_format((float)($non1Ctx['outer_any_top3_rate'] ?? 0.0), 1) ?>%</strong>
        </div>

        <div style="margin-top:8px; overflow-x:auto; -webkit-overflow-scrolling:touch;">
            <table style="width:100%; min-width:690px; border-collapse:collapse; font-size:10px; text-align:center;">
                <thead>
                <tr>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3; text-align:left;">条件</th>
                    <?php foreach ([5, 6] as $course): ?>
                        <?php foreach (['second' => '2着', 'third' => '3着', 'top3' => '3連対'] as $name => $label): ?>
                            <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;"><?= $course ?>C <?= $soEsc($label) ?></th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($contexts as $key => $label): ?>
                    <?php $ctx = is_array($stadiumOuter[$key] ?? null) ? $stadiumOuter[$key] : []; ?>
                    <tr>
                        <th style="padding:4px; border:1px solid #ded6c9; background:#faf6ef; text-align:left; white-space:nowrap;"><?= $soEsc($label) ?><br><span style="font-size:9px; color:#6b7785;">N=<?= number_format((int)($ctx['n'] ?? 0)) ?></span></th>
                        <?php foreach ([5, 6] as $course): ?>
                            <?php foreach (['second', 'third', 'top3'] as $name): ?>
                                <?php $m = $metric($ctx, $course, $name); ?>
                                <td style="padding:4px; border:1px solid #ded6c9; white-space:nowrap;">
                                    <strong><?= number_format($m['rate'], 1) ?>%</strong><br>
                                    <span style="font-size:9px; color:#6b7785;"><?= $soEsc($diffText($m['diff'])) ?></span>
                                </td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div style="margin:14px 0; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
            <div>
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">🌊 外枠到達率</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $soEsc($venueName) ?> / <?= $soEsc($periodLabel) ?>（<?= $soEsc($startDate) ?>〜<?= $soEsc($endDate) ?>）
                </div>
            </div>
            <div style="font-size:11px; color:var(--text-muted);">5C・6Cの2着 / 3着 / 3連対</div>
        </div>

        <div style="display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:12px;">
            <div>全R ⑤⑥どちらか3連対 <strong style="color:var(--text-strong);"><?= number_format((float)($allCtx['outer_any_top3_rate'] ?? 0.0), 2) ?>%</strong></div>
            <div>1逃げ時 <strong style="color:var(--text-strong);"><?= number_format((float)($escapeCtx['outer_any_top3_rate'] ?? 0.0), 2) ?>%</strong></div>
            <div>イン敗戦時 <strong style="color:var(--text-strong);"><?= number_format((float)($non1Ctx['outer_any_top3_rate'] ?? 0.0), 2) ?>%</strong></div>
        </div>

        <div style="margin-top:10px; overflow-x:auto;">
            <table style="width:100%; min-width:760px; border-collapse:collapse; font-size:11px; text-align:center;">
                <thead>
                <tr>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left;">条件</th>
                    <?php foreach ([5, 6] as $course): ?>
                        <?php foreach (['second' => '2着率', 'third' => '3着率', 'top3' => '3連対率'] as $name => $label): ?>
                            <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);"><?= $course ?>C <?= $soEsc($label) ?></th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($contexts as $key => $label): ?>
                    <?php $ctx = is_array($stadiumOuter[$key] ?? null) ? $stadiumOuter[$key] : []; ?>
                    <tr>
                        <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left; white-space:nowrap;">
                            <?= $soEsc($label) ?><div style="font-size:9px; color:var(--text-muted);">N=<?= number_format((int)($ctx['n'] ?? 0)) ?></div>
                        </th>
                        <?php foreach ([5, 6] as $course): ?>
                            <?php foreach (['second', 'third', 'top3'] as $name): ?>
                                <?php $m = $metric($ctx, $course, $name); ?>
                                <td style="padding:6px 7px; border:1px solid var(--border); white-space:nowrap;">
                                    <strong style="color:var(--text-strong);"><?= number_format($m['rate'], 2) ?>%</strong>
                                    <div style="margin-top:2px; font-size:9px; color:var(--text-muted);">全場比 <?= $soEsc($diffText($m['diff'])) ?></div>
                                </td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <details style="margin-top:8px; font-size:11px; color:var(--text-muted);">
            <summary style="cursor:pointer;">外枠到達率の見方</summary>
            <div style="margin-top:5px; line-height:1.6;">
                3連対率は1〜3着に入った割合。1逃げ時は5C/6Cが頭にならないため、2着率+3着率と同義です。<br>
                小さい数値は同じ条件での全24場平均との差。表示専用で、最終予想・買い目補正にはまだ接続していません。
            </div>
        </details>
    </div>
<?php endif; ?>
<?php endif; ?>
