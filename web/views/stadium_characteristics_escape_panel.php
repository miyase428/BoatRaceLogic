<?php
$stadiumEscapePath = __DIR__ . '/../../config/stadium_practical_characteristics.local.json';
$stadiumEscapeAll = [];

if (is_file($stadiumEscapePath)) {
    $json = file_get_contents($stadiumEscapePath);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $stadiumEscapeAll = $decoded;
    }
}

$stadiumEscapeMeta = is_array($stadiumEscapeAll['meta'] ?? null)
    ? $stadiumEscapeAll['meta']
    : [];
$stadiumEscapeRows = is_array($stadiumEscapeAll['stadiums'] ?? null)
    ? $stadiumEscapeAll['stadiums']
    : [];
$stadiumEscape = is_array($stadiumEscapeRows[$selected_place ?? ''] ?? null)
    ? $stadiumEscapeRows[$selected_place]
    : [];

if (!empty($stadiumEscape)):
    $seEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $mode = (string)($stadiumCharacteristicsMode ?? 'pc');
    $venueName = (string)($stadiumEscape['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $periodLabel = (string)($stadiumEscapeMeta['label'] ?? '過去データ');
    $startDate = (string)($stadiumEscapeMeta['start_date'] ?? '');
    $endDate = (string)($stadiumEscapeMeta['end_date'] ?? '');
    $n = (int)($stadiumEscape['n'] ?? 0);
    $lane1Rate = (float)($stadiumEscape['lane1_win_rate'] ?? 0.0);
    $escapeRate = (float)($stadiumEscape['escape_rate'] ?? 0.0);
    $second = is_array($stadiumEscape['escape_second'] ?? null)
        ? $stadiumEscape['escape_second']
        : [];
    $third = is_array($stadiumEscape['escape_third'] ?? null)
        ? $stadiumEscape['escape_third']
        : [];
    $patterns = is_array($stadiumEscape['escape_patterns'] ?? null)
        ? $stadiumEscape['escape_patterns']
        : [];
    $techniqueRates = is_array($stadiumEscape['technique_rates'] ?? null)
        ? $stadiumEscape['technique_rates']
        : [];

    $courseRows = static function (array $dist): array {
        $rows = [];
        for ($course = 2; $course <= 6; $course++) {
            $row = is_array($dist[(string)$course] ?? null) ? $dist[(string)$course] : [];
            $rows[] = [
                'course' => $course,
                'rate' => (float)($row['rate'] ?? 0.0),
                'count' => (int)($row['count'] ?? 0),
            ];
        }
        return $rows;
    };

    $rankRows = static function (array $rows): array {
        usort($rows, static function (array $a, array $b): int {
            if ($a['rate'] === $b['rate']) {
                return $a['course'] <=> $b['course'];
            }
            return $b['rate'] <=> $a['rate'];
        });
        return $rows;
    };

    $secondRows = $courseRows($second);
    $thirdRows = $courseRows($third);
    $secondTop = array_slice($rankRows($secondRows), 0, 3);
    $thirdTop = array_slice($rankRows($thirdRows), 0, 3);

    $topText = static function (array $rows): string {
        $parts = [];
        foreach ($rows as $row) {
            $parts[] = $row['course'] . 'C ' . number_format((float)$row['rate'], 1) . '%';
        }
        return implode(' → ', $parts);
    };

    $topPatterns = [];
    foreach (array_slice($patterns, 0, 5) as $pattern) {
        if (!is_array($pattern)) continue;
        $topPatterns[] = (string)($pattern['pattern'] ?? '-')
            . ' ' . number_format((float)($pattern['rate'] ?? 0.0), 1) . '%';
    }
?>
<?php if ($mode === 'app'): ?>
    <div style="margin:0 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffaf2; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">🚤 <?= $seEsc($venueName) ?> 逃げ時</div>
            <div style="font-size:10px; color:#6b7785;"><?= $seEsc($periodLabel) ?></div>
        </div>

        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-top:7px; font-size:11px;">
            <div>1C勝 <strong><?= number_format($lane1Rate, 1) ?>%</strong></div>
            <div>1逃げ率 <strong><?= number_format($escapeRate, 1) ?>%</strong></div>
        </div>

        <div style="margin-top:8px; overflow-x:auto; -webkit-overflow-scrolling:touch;">
            <table style="width:100%; min-width:520px; border-collapse:collapse; font-size:10px; text-align:center;">
                <thead>
                <tr>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3; text-align:left;">1逃げ時</th>
                    <?php for ($course = 2; $course <= 6; $course++): ?>
                        <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;"><?= $course ?>C</th>
                    <?php endfor; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (['2着' => $second, '3着' => $third] as $label => $dist): ?>
                    <tr>
                        <th style="padding:4px; border:1px solid #ded6c9; background:#faf6ef; text-align:left;"><?= $seEsc($label) ?></th>
                        <?php for ($course = 2; $course <= 6; $course++): ?>
                            <?php $row = is_array($dist[(string)$course] ?? null) ? $dist[(string)$course] : []; ?>
                            <td style="padding:4px; border:1px solid #ded6c9;"><strong><?= number_format((float)($row['rate'] ?? 0.0), 1) ?>%</strong></td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:7px; font-size:10px; line-height:1.6; color:#6b7785;">
            2着上位 <strong style="color:#334155;"><?= $seEsc($topText($secondTop)) ?></strong><br>
            3着上位 <strong style="color:#334155;"><?= $seEsc($topText($thirdTop)) ?></strong>
        </div>

        <?php if ($topPatterns !== []): ?>
            <details style="margin-top:6px; font-size:10px; color:#6b7785;">
                <summary style="cursor:pointer;">1逃げ出目・決まり手詳細</summary>
                <div style="margin-top:5px; line-height:1.65;">
                    1逃げ出目TOP: <?= $seEsc(implode(' / ', $topPatterns)) ?><br>
                    逃げ <?= number_format((float)($techniqueRates['逃げ'] ?? 0.0), 1) ?>% /
                    差し <?= number_format((float)($techniqueRates['差し'] ?? 0.0), 1) ?>% /
                    まくり <?= number_format((float)($techniqueRates['まくり'] ?? 0.0), 1) ?>% /
                    まくり差し <?= number_format((float)($techniqueRates['まくり差し'] ?? 0.0), 1) ?>%
                </div>
            </details>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="margin:14px 0; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap;">
            <div>
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">🚤 逃げ時</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $seEsc($venueName) ?> / <?= $seEsc($periodLabel) ?>（<?= $seEsc($startDate) ?>〜<?= $seEsc($endDate) ?> / <?= number_format($n) ?>R）
                </div>
            </div>
            <div style="font-size:11px; color:var(--text-muted);">1号艇が逃げ切ったレースの相手構造</div>
        </div>

        <div style="display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:13px;">
            <div>1C勝率 <strong style="color:var(--text-strong);"><?= number_format($lane1Rate, 2) ?>%</strong></div>
            <div>1逃げ率 <strong style="color:var(--text-strong); font-size:17px;"><?= number_format($escapeRate, 2) ?>%</strong></div>
        </div>

        <div style="margin-top:10px; overflow-x:auto;">
            <table style="width:100%; min-width:620px; border-collapse:collapse; font-size:11px; text-align:center;">
                <thead>
                <tr>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left;">1逃げ時</th>
                    <?php for ($course = 2; $course <= 6; $course++): ?>
                        <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);"><?= $course ?>C</th>
                    <?php endfor; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (['2着率' => $second, '3着率' => $third] as $label => $dist): ?>
                    <tr>
                        <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left;"><?= $seEsc($label) ?></th>
                        <?php for ($course = 2; $course <= 6; $course++): ?>
                            <?php $row = is_array($dist[(string)$course] ?? null) ? $dist[(string)$course] : []; ?>
                            <td style="padding:6px 7px; border:1px solid var(--border);"><strong style="color:var(--text-strong);"><?= number_format((float)($row['rate'] ?? 0.0), 2) ?>%</strong></td>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:9px; padding:8px 10px; border:1px solid var(--border); border-radius:7px; background:var(--surface); font-size:12px; line-height:1.7;">
            <div><strong>2着上位:</strong> <?= $seEsc($topText($secondTop)) ?></div>
            <div><strong>3着上位:</strong> <?= $seEsc($topText($thirdTop)) ?></div>
        </div>

        <details style="margin-top:8px; font-size:11px; color:var(--text-muted);">
            <summary style="cursor:pointer;">1逃げ出目・決まり手詳細</summary>
            <div style="margin-top:5px; line-height:1.65;">
                1逃げ出目TOP: <?= $topPatterns !== [] ? $seEsc(implode(' / ', $topPatterns)) : '-' ?><br>
                全体決まり手: 逃げ <?= number_format((float)($techniqueRates['逃げ'] ?? 0.0), 2) ?>% /
                差し <?= number_format((float)($techniqueRates['差し'] ?? 0.0), 2) ?>% /
                まくり <?= number_format((float)($techniqueRates['まくり'] ?? 0.0), 2) ?>% /
                まくり差し <?= number_format((float)($techniqueRates['まくり差し'] ?? 0.0), 2) ?>%<br>
                ※表示専用で、最終予想・買い目補正にはまだ接続していません。
            </div>
        </details>
    </div>
<?php endif; ?>
<?php endif; ?>
