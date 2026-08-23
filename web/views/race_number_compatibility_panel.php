<?php
$raceCompatDefaultPath = __DIR__ . '/../../config/stadium_race_number_compatibility.json';
$raceCompatLocalPath = __DIR__ . '/../../config/stadium_race_number_compatibility.local.json';
$raceCompatPath = is_file($raceCompatLocalPath)
    ? $raceCompatLocalPath
    : $raceCompatDefaultPath;

$raceCompatAll = [];
if (is_file($raceCompatPath)) {
    $json = file_get_contents($raceCompatPath);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $raceCompatAll = $decoded;
    }
}

$raceCompatMeta = is_array($raceCompatAll['meta'] ?? null)
    ? $raceCompatAll['meta']
    : [];
$raceCompatStadiums = is_array($raceCompatAll['stadiums'] ?? null)
    ? $raceCompatAll['stadiums']
    : [];
$raceCompatVenue = is_array($raceCompatStadiums[$selected_place ?? ''] ?? null)
    ? $raceCompatStadiums[$selected_place]
    : [];
$raceCompatRows = is_array($raceCompatVenue['races'] ?? null)
    ? $raceCompatVenue['races']
    : [];

if (!empty($raceCompatVenue) && !empty($raceCompatRows)):
    $rcEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $rcMode = (string)($raceNumberCompatibilityMode ?? 'pc');
    $rcSelectedRace = (int)($selected_race ?? 0);
    $rcCurrent = is_array($raceCompatRows[(string)$rcSelectedRace] ?? null)
        ? $raceCompatRows[(string)$rcSelectedRace]
        : [];
    $rcVenueName = (string)($raceCompatVenue['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $rcLabel = (string)($raceCompatMeta['label'] ?? '過去データ');
    $rcStart = (string)($raceCompatMeta['start_date'] ?? '');
    $rcEnd = (string)($raceCompatMeta['end_date'] ?? '');

    $rcColors = [
        'A' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#86efac'],
        'B' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
        'C' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
        'D' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'border' => '#fca5a5'],
        '参考' => ['bg' => '#e2e8f0', 'text' => '#475569', 'border' => '#cbd5e1'],
    ];

    $rcGoodRaces = [];
    foreach ($raceCompatRows as $raceNo => $row) {
        if (!is_array($row)) {
            continue;
        }
        $grade = (string)($row['grade'] ?? '参考');
        if ($grade === 'A' || $grade === 'B') {
            $rcGoodRaces[] = ((int)$raceNo) . 'R';
        }
    }

    $rcCurrentGrade = (string)($rcCurrent['grade'] ?? '参考');
    $rcCurrentColor = $rcColors[$rcCurrentGrade] ?? $rcColors['参考'];
?>
<?php if ($rcMode === 'app'): ?>
    <div style="margin:-2px 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffdf8; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">🎯 R別予想相性</div>
            <div style="font-size:10px; color:#6b7785;"><?= $rcEsc($rcLabel) ?></div>
        </div>

        <?php if (!empty($rcCurrent)): ?>
            <div style="display:flex; gap:7px; align-items:center; flex-wrap:wrap; margin-top:7px;">
                <span style="display:inline-block; padding:3px 8px; border-radius:999px; font-weight:800; font-size:12px; background:<?= $rcEsc($rcCurrentColor['bg']) ?>; color:<?= $rcEsc($rcCurrentColor['text']) ?>; border:1px solid <?= $rcEsc($rcCurrentColor['border']) ?>;"><?= $rcSelectedRace ?>R 相性<?= $rcEsc($rcCurrentGrade) ?></span>
                <strong style="font-size:12px;">本命1着 <?= number_format((float)($rcCurrent['honmei_first_rate'] ?? 0), 1) ?>%</strong>
                <span style="font-size:11px;">的中 <?= number_format((float)($rcCurrent['bet_hit_rate'] ?? 0), 1) ?>%</span>
                <span style="font-size:11px;">直近 <?= number_format((float)($rcCurrent['recent_bet_hit_rate'] ?? 0), 1) ?>%</span>
            </div>
            <div style="margin-top:5px; font-size:10px; color:#6b7785;">
                TOP3≧2 <?= number_format((float)($rcCurrent['top3_2plus_rate'] ?? 0), 1) ?>% / 回収 <?= number_format((float)($rcCurrent['roi'] ?? 0), 1) ?>% / N=<?= (int)($rcCurrent['n'] ?? 0) ?>
            </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:5px; margin-top:8px;">
            <?php for ($raceNo = 1; $raceNo <= 12; $raceNo++): ?>
                <?php
                    $row = is_array($raceCompatRows[(string)$raceNo] ?? null)
                        ? $raceCompatRows[(string)$raceNo]
                        : [];
                    $grade = (string)($row['grade'] ?? '参考');
                    $color = $rcColors[$grade] ?? $rcColors['参考'];
                    $isCurrent = $raceNo === $rcSelectedRace;
                ?>
                <div style="text-align:center; padding:5px 2px; border-radius:7px; background:<?= $rcEsc($color['bg']) ?>; color:<?= $rcEsc($color['text']) ?>; border:<?= $isCurrent ? '2px' : '1px' ?> solid <?= $rcEsc($color['border']) ?>; font-size:11px; font-weight:800;">
                    <?= $raceNo ?>R <?= $rcEsc($grade) ?>
                </div>
            <?php endfor; ?>
        </div>

        <?php if ($rcGoodRaces): ?>
            <div style="margin-top:6px; font-size:10px; color:#475569;">A/B候補: <?= $rcEsc(implode('・', $rcGoodRaces)) ?></div>
        <?php endif; ?>
        <div style="margin-top:4px; font-size:9px; line-height:1.4; color:#7c8795;">A/Bは現行Webとの噛み合いやすさ。回収率とは別の参考指標。</div>
    </div>
<?php else: ?>
    <div style="margin:-4px 0 14px; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
            <div>
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">🎯 R別予想相性</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $rcEsc($rcVenueName) ?> / <?= $rcEsc($rcLabel) ?>（<?= $rcEsc($rcStart) ?>〜<?= $rcEsc($rcEnd) ?>）
                </div>
            </div>
            <?php if ($rcGoodRaces): ?>
                <div style="font-size:12px; color:var(--text-muted);">A/B候補: <strong style="color:var(--text-strong);"><?= $rcEsc(implode('・', $rcGoodRaces)) ?></strong></div>
            <?php endif; ?>
        </div>

        <?php if (!empty($rcCurrent)): ?>
            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:10px; font-size:13px;">
                <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-weight:bold; background:<?= $rcEsc($rcCurrentColor['bg']) ?>; color:<?= $rcEsc($rcCurrentColor['text']) ?>; border:1px solid <?= $rcEsc($rcCurrentColor['border']) ?>;"><?= $rcSelectedRace ?>R 相性 <?= $rcEsc($rcCurrentGrade) ?></span>
                <div>本命1着 <strong style="color:var(--text-strong); font-size:16px;"><?= number_format((float)($rcCurrent['honmei_first_rate'] ?? 0), 1) ?>%</strong></div>
                <div>TOP3≧2 <strong style="color:var(--text-strong);"><?= number_format((float)($rcCurrent['top3_2plus_rate'] ?? 0), 1) ?>%</strong></div>
                <div>買い目的中 <strong style="color:var(--text-strong);"><?= number_format((float)($rcCurrent['bet_hit_rate'] ?? 0), 1) ?>%</strong></div>
                <div>直近的中 <strong style="color:var(--text-strong);"><?= number_format((float)($rcCurrent['recent_bet_hit_rate'] ?? 0), 1) ?>%</strong></div>
                <div>回収率 <strong style="color:var(--text-strong);"><?= number_format((float)($rcCurrent['roi'] ?? 0), 1) ?>%</strong></div>
                <div>N=<strong style="color:var(--text-strong);"><?= (int)($rcCurrent['n'] ?? 0) ?></strong></div>
            </div>
        <?php endif; ?>

        <div style="display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:6px; margin-top:10px;">
            <?php for ($raceNo = 1; $raceNo <= 12; $raceNo++): ?>
                <?php
                    $row = is_array($raceCompatRows[(string)$raceNo] ?? null)
                        ? $raceCompatRows[(string)$raceNo]
                        : [];
                    $grade = (string)($row['grade'] ?? '参考');
                    $color = $rcColors[$grade] ?? $rcColors['参考'];
                    $isCurrent = $raceNo === $rcSelectedRace;
                ?>
                <div style="text-align:center; padding:7px 4px; border-radius:7px; background:<?= $rcEsc($color['bg']) ?>; color:<?= $rcEsc($color['text']) ?>; border:<?= $isCurrent ? '2px' : '1px' ?> solid <?= $rcEsc($color['border']) ?>; font-size:12px;">
                    <strong><?= $raceNo ?>R</strong> <?= $rcEsc($grade) ?>
                </div>
            <?php endfor; ?>
        </div>

        <div style="margin-top:7px; font-size:11px; color:var(--text-muted);">
            A/Bは現行Webとの噛み合いやすさの参考。回収率100%以上を意味しません。
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
