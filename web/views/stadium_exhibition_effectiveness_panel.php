<?php
$stadiumExEffectPath = __DIR__ . '/../../config/stadium_exhibition_effectiveness.local.json';
$stadiumExEffectAll = [];

if (is_file($stadiumExEffectPath)) {
    $json = file_get_contents($stadiumExEffectPath);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (is_array($decoded)) {
        $stadiumExEffectAll = $decoded;
    }
}

$stadiumExEffectMeta = is_array($stadiumExEffectAll['meta'] ?? null)
    ? $stadiumExEffectAll['meta']
    : [];
$stadiumExEffectRows = is_array($stadiumExEffectAll['stadiums'] ?? null)
    ? $stadiumExEffectAll['stadiums']
    : [];
$stadiumExEffect = is_array($stadiumExEffectRows[$selected_place ?? ''] ?? null)
    ? $stadiumExEffectRows[$selected_place]
    : [];

if (!empty($stadiumExEffect)):
    $seEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $mode = (string)($stadiumExEffectMode ?? 'pc');
    $venueName = (string)($stadiumExEffect['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $periodLabel = (string)($stadiumExEffectMeta['label'] ?? '過去データ');
    $startDate = (string)($stadiumExEffectMeta['start_date'] ?? '');
    $endDate = (string)($stadiumExEffectMeta['end_date'] ?? '');
    $items = is_array($stadiumExEffect['items'] ?? null) ? $stadiumExEffect['items'] : [];
    $ranking = is_array($stadiumExEffect['ranking'] ?? null) ? $stadiumExEffect['ranking'] : [];

    $gapText = static function ($value): string {
        if ($value === null || !is_numeric($value)) return '-';
        $v = (float)$value;
        return ($v >= 0 ? '+' : '') . number_format($v, 1) . 'pt';
    };

    $rateText = static function ($value): string {
        return ($value !== null && is_numeric($value)) ? number_format((float)$value, 1) . '%' : '-';
    };

    $rankingParts = [];
    $rankNo = 0;
    foreach ($ranking as $key) {
        $row = is_array($items[$key] ?? null) ? $items[$key] : [];
        if (($row['top3_gap'] ?? null) === null) continue;
        $rankNo++;
        $rankingParts[] = $rankNo . '位 ' . (string)($row['name'] ?? $key)
            . ' ' . $gapText($row['top3_gap'] ?? null);
    }
?>
<?php if ($mode === 'app'): ?>
    <div style="margin:0 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffaf2; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">🔎 <?= $seEsc($venueName) ?> 展示・STの効き方</div>
            <div style="font-size:10px; color:#6b7785;"><?= $seEsc($periodLabel) ?></div>
        </div>

        <?php if ($rankingParts !== []): ?>
            <div style="margin-top:6px; font-size:10px; line-height:1.6; color:#6b7785;">
                3連対差順: <strong style="color:#334155;"><?= $seEsc(implode(' → ', array_slice($rankingParts, 0, 5))) ?></strong>
            </div>
        <?php endif; ?>

        <div style="margin-top:8px; overflow-x:auto; -webkit-overflow-scrolling:touch;">
            <table style="width:100%; min-width:720px; border-collapse:collapse; font-size:10px; text-align:center;">
                <thead>
                <tr>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3; text-align:left;">項目</th>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;">良1着</th>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;">悪1着</th>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;">1着差</th>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;">良3連</th>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;">悪3連</th>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;">3連差</th>
                    <th style="padding:4px; border:1px solid #ded6c9; background:#f4ede3;">全場比</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ranking as $key): ?>
                    <?php $row = is_array($items[$key] ?? null) ? $items[$key] : []; ?>
                    <tr>
                        <th style="padding:4px; border:1px solid #ded6c9; background:#faf6ef; text-align:left; white-space:nowrap;">
                            <?= $seEsc($row['name'] ?? $key) ?><br>
                            <span style="font-size:9px; color:#6b7785;">良N=<?= number_format((int)($row['good_n'] ?? 0)) ?> / 悪N=<?= number_format((int)($row['bad_n'] ?? 0)) ?></span>
                        </th>
                        <td style="padding:4px; border:1px solid #ded6c9;"><?= $seEsc($rateText($row['good_first_rate'] ?? null)) ?></td>
                        <td style="padding:4px; border:1px solid #ded6c9;"><?= $seEsc($rateText($row['bad_first_rate'] ?? null)) ?></td>
                        <td style="padding:4px; border:1px solid #ded6c9;"><strong><?= $seEsc($gapText($row['first_gap'] ?? null)) ?></strong></td>
                        <td style="padding:4px; border:1px solid #ded6c9;"><?= $seEsc($rateText($row['good_top3_rate'] ?? null)) ?></td>
                        <td style="padding:4px; border:1px solid #ded6c9;"><?= $seEsc($rateText($row['bad_top3_rate'] ?? null)) ?></td>
                        <td style="padding:4px; border:1px solid #ded6c9;"><strong><?= $seEsc($gapText($row['top3_gap'] ?? null)) ?></strong></td>
                        <td style="padding:4px; border:1px solid #ded6c9;"><?= $seEsc($gapText($row['vs_all']['top3_gap'] ?? null)) ?></td>
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
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">🔎 展示・STの効き方</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $seEsc($venueName) ?> / <?= $seEsc($periodLabel) ?>（<?= $seEsc($startDate) ?>〜<?= $seEsc($endDate) ?>）
                </div>
            </div>
            <div style="font-size:11px; color:var(--text-muted);">良評価4〜5点 vs 悪評価1〜2点</div>
        </div>

        <?php if ($rankingParts !== []): ?>
            <div style="margin-top:9px; padding:7px 9px; border:1px solid var(--border); border-radius:6px; background:var(--surface); font-size:12px; line-height:1.6;">
                <strong>3連対差での効き順:</strong> <?= $seEsc(implode(' → ', $rankingParts)) ?>
            </div>
        <?php endif; ?>

        <div style="margin-top:10px; overflow-x:auto;">
            <table style="width:100%; min-width:820px; border-collapse:collapse; font-size:11px; text-align:center;">
                <thead>
                <tr>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left;">項目</th>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);">良1着</th>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);">悪1着</th>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);">1着差</th>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);">良3連対</th>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);">悪3連対</th>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);">3連対差</th>
                    <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface);">3連差 全場比</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ranking as $key): ?>
                    <?php $row = is_array($items[$key] ?? null) ? $items[$key] : []; ?>
                    <tr>
                        <th style="padding:6px 7px; border:1px solid var(--border); background:var(--surface); text-align:left; white-space:nowrap;">
                            <?= $seEsc($row['name'] ?? $key) ?>
                            <div style="margin-top:2px; font-size:9px; color:var(--text-muted);">良N=<?= number_format((int)($row['good_n'] ?? 0)) ?> / 悪N=<?= number_format((int)($row['bad_n'] ?? 0)) ?></div>
                        </th>
                        <td style="padding:6px 7px; border:1px solid var(--border);"><?= $seEsc($rateText($row['good_first_rate'] ?? null)) ?></td>
                        <td style="padding:6px 7px; border:1px solid var(--border);"><?= $seEsc($rateText($row['bad_first_rate'] ?? null)) ?></td>
                        <td style="padding:6px 7px; border:1px solid var(--border);"><strong><?= $seEsc($gapText($row['first_gap'] ?? null)) ?></strong></td>
                        <td style="padding:6px 7px; border:1px solid var(--border);"><?= $seEsc($rateText($row['good_top3_rate'] ?? null)) ?></td>
                        <td style="padding:6px 7px; border:1px solid var(--border);"><?= $seEsc($rateText($row['bad_top3_rate'] ?? null)) ?></td>
                        <td style="padding:6px 7px; border:1px solid var(--border);"><strong style="color:var(--text-strong);"><?= $seEsc($gapText($row['top3_gap'] ?? null)) ?></strong></td>
                        <td style="padding:6px 7px; border:1px solid var(--border);"><?= $seEsc($gapText($row['vs_all']['top3_gap'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <details style="margin-top:8px; font-size:11px; color:var(--text-muted);">
            <summary style="cursor:pointer;">展示・ST効き方の見方</summary>
            <div style="margin-top:5px; line-height:1.6;">
                「差」は良評価艇の率 − 悪評価艇の率。プラスが大きいほど、その項目の良し悪しが実着に結びついています。<br>
                効き順は3連対率差の大きい順。「全場比」は、その差が全24場平均より何pt強い/弱いかです。<br>
                展示タイムは現行の場6か月平均との差、周回・周り足・直線はレース6艇平均との差、STは実測値の現行点数基準です。<br>
                ※表示専用。最終予想・買い目補正にはまだ接続していません。
            </div>
        </details>
    </div>
<?php endif; ?>
<?php endif; ?>
