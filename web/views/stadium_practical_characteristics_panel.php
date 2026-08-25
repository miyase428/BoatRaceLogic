<?php
$stadiumPracticalPath = __DIR__ . '/../../config/stadium_practical_characteristics.local.json';
$stadiumPracticalAll = [];

if (is_file($stadiumPracticalPath)) {
    $json = file_get_contents($stadiumPracticalPath);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $stadiumPracticalAll = $decoded;
    }
}

$stadiumPracticalMeta = is_array($stadiumPracticalAll['meta'] ?? null)
    ? $stadiumPracticalAll['meta']
    : [];
$stadiumPracticalRows = is_array($stadiumPracticalAll['stadiums'] ?? null)
    ? $stadiumPracticalAll['stadiums']
    : [];
$stadiumPractical = is_array($stadiumPracticalRows[$selected_place ?? ''] ?? null)
    ? $stadiumPracticalRows[$selected_place]
    : [];

if (!empty($stadiumPractical)):
    $spEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $mode = (string)($stadiumPracticalMode ?? 'pc');
    $venueName = (string)($stadiumPractical['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $periodLabel = (string)($stadiumPracticalMeta['label'] ?? '過去データ');
    $startDate = (string)($stadiumPracticalMeta['start_date'] ?? '');
    $endDate = (string)($stadiumPracticalMeta['end_date'] ?? '');
    $n = (int)($stadiumPractical['n'] ?? 0);
    $lane1Rate = (float)($stadiumPractical['lane1_win_rate'] ?? 0.0);
    $lane1Diff = (float)($stadiumPractical['lane1_vs_all_diff'] ?? 0.0);
    $lane1Strength = (string)($stadiumPractical['lane1_strength'] ?? '-');
    $escapeRate = (float)($stadiumPractical['escape_rate'] ?? 0.0);
    $nonEscapeTop = is_array($stadiumPractical['non_escape_top'] ?? null)
        ? $stadiumPractical['non_escape_top']
        : [];
    $nonEscapeName = (string)($nonEscapeTop['name'] ?? '-');
    $nonEscapeRate = (float)($nonEscapeTop['rate'] ?? 0.0);
    $techniqueRates = is_array($stadiumPractical['technique_rates'] ?? null)
        ? $stadiumPractical['technique_rates']
        : [];
    $second = is_array($stadiumPractical['escape_second'] ?? null)
        ? $stadiumPractical['escape_second']
        : [];
    $third = is_array($stadiumPractical['escape_third'] ?? null)
        ? $stadiumPractical['escape_third']
        : [];
    $patterns = is_array($stadiumPractical['escape_patterns'] ?? null)
        ? $stadiumPractical['escape_patterns']
        : [];

    $courseRanking = static function (array $dist): array {
        $rows = [];
        for ($course = 2; $course <= 6; $course++) {
            $row = is_array($dist[(string)$course] ?? null) ? $dist[(string)$course] : [];
            $rows[] = [
                'course' => $course,
                'rate' => (float)($row['rate'] ?? 0.0),
                'count' => (int)($row['count'] ?? 0),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            if ($a['rate'] === $b['rate']) {
                return $a['course'] <=> $b['course'];
            }
            return $b['rate'] <=> $a['rate'];
        });
        return $rows;
    };

    $secondRank = $courseRanking($second);
    $thirdRank = $courseRanking($third);
    $topSecond = array_slice($secondRank, 0, 3);
    $topThird = array_slice($thirdRank, 0, 3);

    $strengthColors = [
        '強い' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'border' => '#fca5a5'],
        'やや強い' => ['bg' => '#ffedd5', 'text' => '#c2410c', 'border' => '#fdba74'],
        '標準' => ['bg' => '#e2e8f0', 'text' => '#334155', 'border' => '#cbd5e1'],
        'やや弱い' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
        '弱い' => ['bg' => '#e0e7ff', 'text' => '#4338ca', 'border' => '#a5b4fc'],
    ];
    $strengthColor = $strengthColors[$lane1Strength] ?? $strengthColors['標準'];

    $formatDist = static function (array $dist): string {
        $parts = [];
        for ($course = 2; $course <= 6; $course++) {
            $row = is_array($dist[(string)$course] ?? null) ? $dist[(string)$course] : [];
            $parts[] = $course . 'C ' . number_format((float)($row['rate'] ?? 0.0), 1) . '%';
        }
        return implode(' / ', $parts);
    };

    $topCourseText = static function (array $rows): string {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['course'] . 'C ' . number_format((float)$row['rate'], 1) . '%';
        }
        return implode(' → ', $parts);
    };

    $topPatternText = [];
    foreach (array_slice($patterns, 0, 5) as $pattern) {
        if (!is_array($pattern)) {
            continue;
        }
        $topPatternText[] = (string)($pattern['pattern'] ?? '-')
            . ' ' . number_format((float)($pattern['rate'] ?? 0.0), 1) . '%';
    }
?>
<?php if ($mode === 'app'): ?>
    <div style="margin:0 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffaf2; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">🏟️ <?= $spEsc($venueName) ?> 場特性</div>
            <div style="font-size:10px; color:#6b7785;"><?= $spEsc($periodLabel) ?></div>
        </div>

        <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap; margin-top:7px;">
            <span style="display:inline-block; padding:3px 8px; border-radius:999px; font-weight:800; font-size:11px; background:<?= $spEsc($strengthColor['bg']) ?>; color:<?= $spEsc($strengthColor['text']) ?>; border:1px solid <?= $spEsc($strengthColor['border']) ?>;">イン <?= $spEsc($lane1Strength) ?></span>
            <strong style="font-size:12px;">1C勝 <?= number_format($lane1Rate, 1) ?>%</strong>
            <span style="font-size:10px; color:#6b7785;">全場比 <?= $lane1Diff >= 0 ? '+' : '' ?><?= number_format($lane1Diff, 1) ?>pt</span>
        </div>

        <div style="margin-top:7px; font-size:11px; line-height:1.6;">
            <div>非逃げ主力 <strong><?= $spEsc($nonEscapeName) ?> <?= number_format($nonEscapeRate, 1) ?>%</strong></div>
            <div>1逃げ時2着 <strong><?= $spEsc($topCourseText($topSecond)) ?></strong></div>
            <div>1逃げ時3着 <strong><?= $spEsc($topCourseText($topThird)) ?></strong></div>
        </div>

        <?php if ($topPatternText !== []): ?>
            <details style="margin-top:6px; font-size:10px; color:#6b7785;">
                <summary style="cursor:pointer;">決まり手・1逃げ出目の詳細</summary>
                <div style="margin-top:5px; line-height:1.65;">
                    逃げ <?= number_format((float)($techniqueRates['逃げ'] ?? 0.0), 1) ?>% /
                    差し <?= number_format((float)($techniqueRates['差し'] ?? 0.0), 1) ?>% /
                    まくり <?= number_format((float)($techniqueRates['まくり'] ?? 0.0), 1) ?>% /
                    まくり差し <?= number_format((float)($techniqueRates['まくり差し'] ?? 0.0), 1) ?>%<br>
                    TOP <?= $spEsc(implode(' / ', $topPatternText)) ?><br>
                    <?= $spEsc($startDate) ?>〜<?= $spEsc($endDate) ?> / <?= number_format($n) ?>R
                </div>
            </details>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="margin:14px 0; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
            <div>
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">🏟️ 場特性・実戦メモ</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $spEsc($venueName) ?> / <?= $spEsc($periodLabel) ?>（<?= $spEsc($startDate) ?>〜<?= $spEsc($endDate) ?> / <?= number_format($n) ?>R）
                </div>
            </div>
            <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-weight:bold; background:<?= $spEsc($strengthColor['bg']) ?>; color:<?= $spEsc($strengthColor['text']) ?>; border:1px solid <?= $spEsc($strengthColor['border']) ?>;">イン <?= $spEsc($lane1Strength) ?></span>
        </div>

        <div style="display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:13px;">
            <div>1C勝率 <strong style="color:var(--text-strong); font-size:17px;"><?= number_format($lane1Rate, 2) ?>%</strong></div>
            <div>全場比 <strong style="color:var(--text-strong);"><?= $lane1Diff >= 0 ? '+' : '' ?><?= number_format($lane1Diff, 2) ?>pt</strong></div>
            <div>1逃げ率 <strong style="color:var(--text-strong);"><?= number_format($escapeRate, 2) ?>%</strong></div>
            <div>非逃げ主力 <strong style="color:var(--text-strong);"><?= $spEsc($nonEscapeName) ?> <?= number_format($nonEscapeRate, 2) ?>%</strong></div>
        </div>

        <div style="margin-top:10px; padding:9px 10px; border:1px solid var(--border); border-radius:7px; background:var(--surface); font-size:12px; line-height:1.7;">
            <div><strong>1逃げ時 2着:</strong> <?= $spEsc($formatDist($second)) ?></div>
            <div><strong>1逃げ時 3着:</strong> <?= $spEsc($formatDist($third)) ?></div>
            <div style="margin-top:3px; color:var(--text-muted);">
                実戦上位 → 2着 <?= $spEsc($topCourseText($topSecond)) ?> / 3着 <?= $spEsc($topCourseText($topThird)) ?>
            </div>
        </div>

        <details style="margin-top:8px; font-size:11px; color:var(--text-muted);">
            <summary style="cursor:pointer;">決まり手・1逃げ出目TOP</summary>
            <div style="margin-top:5px; line-height:1.65;">
                逃げ <?= number_format((float)($techniqueRates['逃げ'] ?? 0.0), 2) ?>% /
                差し <?= number_format((float)($techniqueRates['差し'] ?? 0.0), 2) ?>% /
                まくり <?= number_format((float)($techniqueRates['まくり'] ?? 0.0), 2) ?>% /
                まくり差し <?= number_format((float)($techniqueRates['まくり差し'] ?? 0.0), 2) ?>%<br>
                1逃げ出目TOP: <?= $topPatternText !== [] ? $spEsc(implode(' / ', $topPatternText)) : '-' ?><br>
                ※表示専用。最終予想・買い目補正にはまだ接続していません。
            </div>
        </details>
    </div>
<?php endif; ?>
<?php endif; ?>
