<?php
$stadiumBasicPath = __DIR__ . '/../../config/stadium_practical_characteristics.local.json';
$stadiumBasicAll = [];

if (is_file($stadiumBasicPath)) {
    $json = file_get_contents($stadiumBasicPath);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $stadiumBasicAll = $decoded;
    }
}

$stadiumBasicMeta = is_array($stadiumBasicAll['meta'] ?? null)
    ? $stadiumBasicAll['meta']
    : [];
$stadiumBasicRows = is_array($stadiumBasicAll['stadiums'] ?? null)
    ? $stadiumBasicAll['stadiums']
    : [];
$stadiumBasic = is_array($stadiumBasicRows[$selected_place ?? ''] ?? null)
    ? $stadiumBasicRows[$selected_place]
    : [];

if (!empty($stadiumBasic)):
    $sbEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $mode = (string)($stadiumCharacteristicsMode ?? 'pc');
    $venueName = (string)($stadiumBasic['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $periodLabel = (string)($stadiumBasicMeta['label'] ?? '過去データ');
    $startDate = (string)($stadiumBasicMeta['start_date'] ?? '');
    $endDate = (string)($stadiumBasicMeta['end_date'] ?? '');
    $n = (int)($stadiumBasic['n'] ?? 0);
    $lane1Rate = (float)($stadiumBasic['lane1_win_rate'] ?? 0.0);
    $lane1Diff = (float)($stadiumBasic['lane1_vs_all_diff'] ?? 0.0);
    $lane1Strength = (string)($stadiumBasic['lane1_strength'] ?? '-');
    $nonEscapeTop = is_array($stadiumBasic['non_escape_top'] ?? null)
        ? $stadiumBasic['non_escape_top']
        : [];
    $nonEscapeName = (string)($nonEscapeTop['name'] ?? '-');
    $nonEscapeRate = (float)($nonEscapeTop['rate'] ?? 0.0);
    $courseResults = is_array($stadiumBasic['course_results'] ?? null)
        ? $stadiumBasic['course_results']
        : [];

    $strengthColors = [
        '強い' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'border' => '#fca5a5'],
        'やや強い' => ['bg' => '#ffedd5', 'text' => '#c2410c', 'border' => '#fdba74'],
        '標準' => ['bg' => '#e2e8f0', 'text' => '#334155', 'border' => '#cbd5e1'],
        'やや弱い' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
        '弱い' => ['bg' => '#e0e7ff', 'text' => '#4338ca', 'border' => '#a5b4fc'],
    ];
    $strengthColor = $strengthColors[$lane1Strength] ?? $strengthColors['標準'];

    $courseMetric = static function (array $courseResults, int $course, string $metric): array {
        $row = is_array($courseResults[(string)$course] ?? null)
            ? $courseResults[(string)$course]
            : [];
        $vsAll = is_array($row['vs_all'] ?? null) ? $row['vs_all'] : [];
        return [
            'rate' => (float)($row[$metric . '_rate'] ?? 0.0),
            'diff' => (float)($vsAll[$metric] ?? 0.0),
        ];
    };

    $diffText = static function (float $diff): string {
        return ($diff >= 0 ? '+' : '') . number_format($diff, 1) . 'pt';
    };
?>
<?php if ($mode === 'app'): ?>
    <div style="margin:0 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffaf2; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">🏟️ <?= $sbEsc($venueName) ?> 基本特性</div>
            <div style="font-size:10px; color:#6b7785;"><?= $sbEsc($periodLabel) ?></div>
        </div>

        <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:7px;">
            <span style="display:inline-block; padding:3px 8px; border-radius:999px; font-weight:800; font-size:11px; background:<?= $sbEsc($strengthColor['bg']) ?>; color:<?= $sbEsc($strengthColor['text']) ?>; border:1px solid <?= $sbEsc($strengthColor['border']) ?>;">イン <?= $sbEsc($lane1Strength) ?></span>
            <strong style="font-size:12px;">1C勝 <?= number_format($lane1Rate, 1) ?>%</strong>
            <span style="font-size:10px; color:#6b7785;">全場比 <?= $lane1Diff >= 0 ? '+' : '' ?><?= number_format($lane1Diff, 1) ?>pt</span>
        </div>

        <div style="margin-top:6px; font-size:10px; color:#6b7785;">
            非逃げ主力 <strong style="color:#334155;"><?= $sbEsc($nonEscapeName) ?> <?= number_format($nonEscapeRate, 1) ?>%</strong>
        </div>

        <?php if ($courseResults !== []): ?>
            <div style="margin-top:8px; overflow-x:auto; -webkit-overflow-scrolling:touch;">
                <table style="width:100%; min-width:560px; border-collapse:collapse; font-size:10px; text-align:center;">
                    <thead>
                    <tr>
                        <th style="padding:4px 5px; border:1px solid #ded6c9; background:#f4ede3; text-align:left;">コース成績</th>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <th style="padding:4px 5px; border:1px solid #ded6c9; background:#f4ede3;"><?= $course ?>C</th>
                        <?php endfor; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (['first' => '1着率', 'top2' => '2連対率', 'top3' => '3連対率'] as $metric => $label): ?>
                        <tr>
                            <th style="padding:4px 5px; border:1px solid #ded6c9; background:#faf6ef; text-align:left; white-space:nowrap;"><?= $sbEsc($label) ?></th>
                            <?php for ($course = 1; $course <= 6; $course++): ?>
                                <?php $m = $courseMetric($courseResults, $course, $metric); ?>
                                <td style="padding:4px 5px; border:1px solid #ded6c9; white-space:nowrap;">
                                    <strong><?= number_format($m['rate'], 1) ?>%</strong><br>
                                    <span style="font-size:9px; color:#6b7785;"><?= $sbEsc($diffText($m['diff'])) ?></span>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="margin:14px 0; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
            <div>
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">🏟️ 基本特性</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $sbEsc($venueName) ?> / <?= $sbEsc($periodLabel) ?>（<?= $sbEsc($startDate) ?>〜<?= $sbEsc($endDate) ?> / <?= number_format($n) ?>R）
                </div>
            </div>
            <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-weight:bold; background:<?= $sbEsc($strengthColor['bg']) ?>; color:<?= $sbEsc($strengthColor['text']) ?>; border:1px solid <?= $sbEsc($strengthColor['border']) ?>;">イン <?= $sbEsc($lane1Strength) ?></span>
        </div>

        <div style="display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:13px;">
            <div>1C勝率 <strong style="color:var(--text-strong); font-size:17px;"><?= number_format($lane1Rate, 2) ?>%</strong></div>
            <div>全場比 <strong style="color:var(--text-strong);"><?= $lane1Diff >= 0 ? '+' : '' ?><?= number_format($lane1Diff, 2) ?>pt</strong></div>
            <div>非逃げ主力 <strong style="color:var(--text-strong);"><?= $sbEsc($nonEscapeName) ?> <?= number_format($nonEscapeRate, 2) ?>%</strong></div>
        </div>

        <?php if ($courseResults !== []): ?>
            <div style="margin-top:10px; overflow-x:auto;">
                <table style="width:100%; min-width:680px; border-collapse:collapse; font-size:11px; text-align:center;">
                    <thead>
                    <tr>
                        <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left;">場×コース成績</th>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);"><?= $course ?>C</th>
                        <?php endfor; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (['first' => '1着率', 'top2' => '2連対率', 'top3' => '3連対率'] as $metric => $label): ?>
                        <tr>
                            <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left; white-space:nowrap;"><?= $sbEsc($label) ?></th>
                            <?php for ($course = 1; $course <= 6; $course++): ?>
                                <?php $m = $courseMetric($courseResults, $course, $metric); ?>
                                <td style="padding:6px 7px; border:1px solid var(--border); white-space:nowrap;">
                                    <strong style="color:var(--text-strong);"><?= number_format($m['rate'], 2) ?>%</strong>
                                    <div style="margin-top:2px; font-size:9px; color:var(--text-muted);">全場比 <?= $sbEsc($diffText($m['diff'])) ?></div>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div style="margin-top:7px; font-size:10px; color:var(--text-muted);">
            ※場×コース成績の小さい数値は全24場平均との差。表示専用で予想ロジックには未接続です。
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
