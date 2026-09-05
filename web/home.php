<?php
require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');

$placeNames = [
    'KRY' => '桐生',   'TDA' => '戸田',   'EDG' => '江戸川', 'HWJ' => '平和島',
    'TMG' => '多摩川', 'HMN' => '浜名湖', 'GMG' => '蒲郡',   'TKN' => '常滑',
    'TSU' => '津',     'MKN' => '三国',   'BWK' => 'びわこ', 'SME' => '住之江',
    'AMG' => '尼崎',   'NRT' => '鳴門',   'MRG' => '丸亀',   'KJM' => '児島',
    'MYJ' => '宮島',   'TKY' => '徳山',   'SMS' => '下関',   'WKM' => '若松',
    'ASY' => '芦屋',   'FKO' => '福岡',   'KRT' => '唐津',   'OMR' => '大村',
];

$placeNumbers = [
    'KRY' => 1,  'TDA' => 2,  'EDG' => 3,  'HWJ' => 4,  'TMG' => 5,  'HMN' => 6,
    'GMG' => 7,  'TKN' => 8,  'TSU' => 9,  'MKN' => 10, 'BWK' => 11, 'SME' => 12,
    'AMG' => 13, 'NRT' => 14, 'MRG' => 15, 'KJM' => 16, 'MYJ' => 17, 'TKY' => 18,
    'SMS' => 19, 'WKM' => 20, 'ASY' => 21, 'FKO' => 22, 'KRT' => 23, 'OMR' => 24,
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

$today = new DateTimeImmutable('today');
$selectedDateText = (string)($_GET['date'] ?? $today->format('Y-m-d'));
if (!validDate($selectedDateText)) {
    $selectedDateText = $today->format('Y-m-d');
}
$selectedDate = new DateTimeImmutable($selectedDateText);
$prevDate = $selectedDate->modify('-1 day')->format('Y-m-d');
$nextDate = $selectedDate->modify('+1 day')->format('Y-m-d');
$isToday = $selectedDate->format('Y-m-d') === $today->format('Y-m-d');
$datePrefix = $selectedDate->format('Ymd');

$userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$isMobile = str_contains($userAgent, 'iphone')
    || str_contains($userAgent, 'ipad')
    || str_contains($userAgent, 'ipod')
    || str_contains($userAgent, 'android');
$predictionPath = $isMobile ? '/web/app.php' : '/web/index.php';

$racesByPlace = [];
$dbError = '';

try {
    $pdo = getPDO();

    $entryStmt = $pdo->prepare(
        "SELECT race_code, COUNT(*) AS row_count\n"
        . "FROM boat_race.race_entry\n"
        . "WHERE race_code LIKE :prefix\n"
        . "GROUP BY race_code\n"
        . "ORDER BY race_code"
    );
    $entryStmt->execute([':prefix' => $datePrefix . '%']);

    foreach ($entryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raceCode = (string)($row['race_code'] ?? '');
        if (!preg_match('/^\\d{8}([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            continue;
        }
        $place = $m[1];
        $raceNo = (int)$m[2];
        if (!isset($placeNames[$place])) {
            continue;
        }
        $racesByPlace[$place][$raceNo] = [
            'race_code' => $raceCode,
            'entry_count' => (int)($row['row_count'] ?? 0),
            'exhibition_count' => 0,
            'result_count' => 0,
            'win_rates' => [],
        ];
    }

    // 左側のレース検索用。予想値ではなく、その日の出走表に紐づく全国勝率を使う。
    $rateStmt = $pdo->prepare(
        "SELECT re.race_code, re.lane_number, MAX(ps.national_win_rate) AS national_win_rate\n"
        . "FROM boat_race.race_entry re\n"
        . "LEFT JOIN boat_race.player_stats ps\n"
        . "  ON ps.race_code = re.race_code\n"
        . " AND ps.player_id = re.player_id\n"
        . "WHERE re.race_code LIKE :prefix\n"
        . "GROUP BY re.race_code, re.lane_number\n"
        . "ORDER BY re.race_code, re.lane_number"
    );
    $rateStmt->execute([':prefix' => $datePrefix . '%']);
    foreach ($rateStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raceCode = (string)($row['race_code'] ?? '');
        if (!preg_match('/^\\d{8}([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            continue;
        }
        $place = $m[1];
        $raceNo = (int)$m[2];
        $lane = (int)($row['lane_number'] ?? 0);
        if (!isset($racesByPlace[$place][$raceNo]) || $lane < 1 || $lane > 6) {
            continue;
        }
        $rate = $row['national_win_rate'];
        $racesByPlace[$place][$raceNo]['win_rates'][$lane] = is_numeric($rate) ? (float)$rate : null;
    }

    $exhibitionStmt = $pdo->prepare(
        "SELECT race_code, COUNT(*) FILTER (WHERE exhibition_time IS NOT NULL OR start_timing IS NOT NULL) AS valid_count\n"
        . "FROM boat_race.exhibition_live\n"
        . "WHERE race_code LIKE :prefix\n"
        . "GROUP BY race_code"
    );
    $exhibitionStmt->execute([':prefix' => $datePrefix . '%']);
    foreach ($exhibitionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raceCode = (string)($row['race_code'] ?? '');
        if (!preg_match('/^\\d{8}([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            continue;
        }
        $place = $m[1];
        $raceNo = (int)$m[2];
        if (isset($racesByPlace[$place][$raceNo])) {
            $racesByPlace[$place][$raceNo]['exhibition_count'] = (int)($row['valid_count'] ?? 0);
        }
    }

    $resultStmt = $pdo->prepare(
        "SELECT race_code, COUNT(*) AS row_count\n"
        . "FROM boat_race.race_result_detail\n"
        . "WHERE race_code LIKE :prefix\n"
        . "GROUP BY race_code"
    );
    $resultStmt->execute([':prefix' => $datePrefix . '%']);
    foreach ($resultStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raceCode = (string)($row['race_code'] ?? '');
        if (!preg_match('/^\\d{8}([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            continue;
        }
        $place = $m[1];
        $raceNo = (int)$m[2];
        if (isset($racesByPlace[$place][$raceNo])) {
            $racesByPlace[$place][$raceNo]['result_count'] = (int)($row['row_count'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$activePlaceCount = count(array_filter($racesByPlace, static fn($races) => !empty($races)));
$raceCount = 0;
foreach ($racesByPlace as $races) {
    $raceCount += count($races);
}

$dayLabel = $selectedDate->format('Y/m/d');
$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
$weekday = $weekdays[(int)$selectedDate->format('w')];
?>
<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f4ecdf">
    <title>艇 BoatRace</title>
    <link rel="stylesheet" href="/web/assets/css/home.css?v=20260905b">
</head>
<body>
<div class="home-shell">
    <header class="home-header">
        <div>
            <div class="home-brand">艇 <span>BoatRace</span></div>
            <div class="home-subtitle">開催場からレースを選択</div>
        </div>
        <div class="home-device"><?= $isMobile ? 'APP' : 'WEB' ?></div>
    </header>

    <section class="date-card">
        <a class="date-nav" href="?date=<?= h($prevDate) ?>" aria-label="前日">‹</a>
        <div class="date-main">
            <div class="date-value"><?= h($dayLabel) ?> <span>(<?= h($weekday) ?>)</span></div>
            <?php if ($isToday): ?>
                <div class="date-today">本日</div>
            <?php else: ?>
                <a class="today-link" href="?date=<?= h($today->format('Y-m-d')) ?>">今日へ戻る</a>
            <?php endif; ?>
        </div>
        <a class="date-nav" href="?date=<?= h($nextDate) ?>" aria-label="翌日">›</a>
    </section>

    <?php if ($dbError !== ''): ?>
        <div class="error-card">開催情報を読み込めませんでした。<br><small><?= h($dbError) ?></small></div>
    <?php endif; ?>

    <div class="home-layout">
        <aside class="search-column">
            <details class="search-card"<?= $isMobile ? '' : ' open' ?>>
                <summary>
                    <span>🔎 レース検索</span>
                    <small>条件から今日のレースを絞り込み</small>
                </summary>
                <form id="race-search-form" class="search-form">
                    <fieldset>
                        <legend>1号艇 全国勝率</legend>
                        <div class="choice-grid choice-grid-2">
                            <label><input type="radio" name="lane1Min" value="" checked><span>指定なし</span></label>
                            <label><input type="radio" name="lane1Min" value="6.0"><span>6.0以上</span></label>
                            <label><input type="radio" name="lane1Min" value="6.5"><span>6.5以上</span></label>
                            <label><input type="radio" name="lane1Min" value="7.0"><span>7.0以上</span></label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>2〜6号艇 最高全国勝率</legend>
                        <div class="choice-grid choice-grid-2">
                            <label><input type="radio" name="outerMax" value="" checked><span>指定なし</span></label>
                            <label><input type="radio" name="outerMax" value="7.0"><span>7.0以下</span></label>
                            <label><input type="radio" name="outerMax" value="6.5"><span>6.5以下</span></label>
                            <label><input type="radio" name="outerMax" value="6.0"><span>6.0以下</span></label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>レース帯</legend>
                        <div class="choice-grid choice-grid-4">
                            <label><input type="radio" name="raceBand" value="all" checked><span>全</span></label>
                            <label><input type="radio" name="raceBand" value="early"><span>1〜4R</span></label>
                            <label><input type="radio" name="raceBand" value="middle"><span>5〜8R</span></label>
                            <label><input type="radio" name="raceBand" value="late"><span>9〜12R</span></label>
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend>データ状況</legend>
                        <div class="choice-grid choice-grid-2">
                            <label><input type="radio" name="raceStatus" value="all" checked><span>すべて</span></label>
                            <label><input type="radio" name="raceStatus" value="entry"><span>展示前</span></label>
                            <label><input type="radio" name="raceStatus" value="exhibition"><span>展示済</span></label>
                            <label><input type="radio" name="raceStatus" value="result"><span>結果済</span></label>
                        </div>
                    </fieldset>

                    <button type="submit" class="search-submit">この条件で検索</button>
                    <button type="button" id="race-search-reset" class="search-reset">条件をクリア</button>
                </form>

                <div class="search-result-box" aria-live="polite">
                    <strong id="search-result-count"><?= (int)$raceCount ?>R</strong>
                    <span id="search-result-label">本日の全レース</span>
                </div>

                <div class="quick-search">
                    <div class="quick-title">クイック検索</div>
                    <div class="quick-buttons">
                        <button type="button" data-quick="late">後半9〜12R</button>
                        <button type="button" data-quick="exhibition">展示済</button>
                        <button type="button" data-quick="unresolved">結果前</button>
                    </div>
                </div>
            </details>
        </aside>

        <div class="race-column">
            <section class="summary-card">
                <div><strong><?= (int)$activePlaceCount ?></strong><span>開催場</span></div>
                <div><strong><?= (int)$raceCount ?></strong><span>レース</span></div>
                <div class="summary-legend"><span class="dot dot-entry"></span>出走表 <span class="dot dot-exhibition"></span>展示 <span class="dot dot-result"></span>結果</div>
            </section>

            <div class="race-column-head">
                <div>
                    <h2>開催一覧</h2>
                    <p>場を見ながら、そのままRを選択できます。</p>
                </div>
                <span id="visible-race-count"><?= (int)$raceCount ?>R表示</span>
            </div>

            <main class="venue-grid">
                <?php foreach ($placeNames as $place => $name): ?>
                    <?php
                        $races = $racesByPlace[$place] ?? [];
                        ksort($races);
                        $isActive = !empty($races);
                        $placeNo = $placeNumbers[$place] ?? 0;
                    ?>
                    <section class="venue-card<?= $isActive ? ' is-active' : ' is-closed' ?>" data-venue-card data-active="<?= $isActive ? '1' : '0' ?>">
                        <div class="venue-head">
                            <div class="venue-name-wrap">
                                <span class="venue-no"><?= sprintf('%02d', $placeNo) ?></span>
                                <strong class="venue-name"><?= h($name) ?></strong>
                            </div>
                            <div class="venue-head-right">
                                <?php if ($isActive): ?><span class="venue-match-count" data-match-count></span><?php endif; ?>
                                <span class="venue-state"><?= $isActive ? '開催' : '休催' ?></span>
                            </div>
                        </div>

                        <?php if ($isActive): ?>
                            <div class="race-grid">
                                <?php foreach ($races as $raceNo => $race): ?>
                                    <?php
                                        $status = 'entry';
                                        $statusLabel = '出走表';
                                        if (($race['result_count'] ?? 0) >= 3) {
                                            $status = 'result';
                                            $statusLabel = '結果';
                                        } elseif (($race['exhibition_count'] ?? 0) >= 5) {
                                            $status = 'exhibition';
                                            $statusLabel = '展示';
                                        }

                                        $winRates = is_array($race['win_rates'] ?? null) ? $race['win_rates'] : [];
                                        $lane1Rate = isset($winRates[1]) && is_numeric($winRates[1]) ? (float)$winRates[1] : null;
                                        $outerRates = [];
                                        for ($lane = 2; $lane <= 6; $lane++) {
                                            if (isset($winRates[$lane]) && is_numeric($winRates[$lane])) {
                                                $outerRates[] = (float)$winRates[$lane];
                                            }
                                        }
                                        $outerMax = $outerRates !== [] ? max($outerRates) : null;

                                        $url = $predictionPath
                                            . '?date=' . rawurlencode($selectedDate->format('Y-m-d'))
                                            . '&place=' . rawurlencode($place)
                                            . '&race=' . rawurlencode((string)$raceNo);
                                    ?>
                                    <a class="race-button status-<?= h($status) ?>"
                                       href="<?= h($url) ?>"
                                       data-race-button
                                       data-race-no="<?= (int)$raceNo ?>"
                                       data-status="<?= h($status) ?>"
                                       data-lane1-rate="<?= $lane1Rate === null ? '' : h(number_format($lane1Rate, 2, '.', '')) ?>"
                                       data-outer-max="<?= $outerMax === null ? '' : h(number_format($outerMax, 2, '.', '')) ?>"
                                       title="<?= $lane1Rate === null ? '' : '1号艇全国勝率 ' . h(number_format($lane1Rate, 2)) ?><?= $outerMax === null ? '' : ' / 2〜6号艇最高 ' . h(number_format($outerMax, 2)) ?>">
                                        <strong><?= (int)$raceNo ?>R</strong>
                                        <span><?= h($statusLabel) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="closed-body">本日の出走表データなし</div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>
            </main>

            <div id="search-empty" class="search-empty" hidden>条件に一致するレースがありません。</div>
        </div>
    </div>

    <footer class="home-footer">BoatRace Analytics / 開催判定はDBの出走表データを使用</footer>
</div>
<script src="/web/assets/js/home.js?v=20260905a" defer></script>
</body>
</html>