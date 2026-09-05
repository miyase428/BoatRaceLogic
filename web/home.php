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
        . "FROM race_entry\n"
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
        ];
    }

    $exhibitionStmt = $pdo->prepare(
        "SELECT race_code, COUNT(*) FILTER (WHERE exhibition_time IS NOT NULL OR start_timing IS NOT NULL) AS valid_count\n"
        . "FROM exhibition_live\n"
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
        . "FROM race_result_detail\n"
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
    <link rel="stylesheet" href="/web/assets/css/home.css?v=20260905a">
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

    <section class="summary-card">
        <div><strong><?= (int)$activePlaceCount ?></strong><span>開催場</span></div>
        <div><strong><?= (int)$raceCount ?></strong><span>レース</span></div>
        <div class="summary-legend"><span class="dot dot-entry"></span>出走表 <span class="dot dot-exhibition"></span>展示 <span class="dot dot-result"></span>結果</div>
    </section>

    <?php if ($dbError !== ''): ?>
        <div class="error-card">開催情報を読み込めませんでした。<br><small><?= h($dbError) ?></small></div>
    <?php endif; ?>

    <main class="venue-grid">
        <?php foreach ($placeNames as $place => $name): ?>
            <?php
                $races = $racesByPlace[$place] ?? [];
                ksort($races);
                $isActive = !empty($races);
                $placeNo = $placeNumbers[$place] ?? 0;
            ?>
            <section class="venue-card<?= $isActive ? ' is-active' : ' is-closed' ?>">
                <div class="venue-head">
                    <div class="venue-name-wrap">
                        <span class="venue-no"><?= sprintf('%02d', $placeNo) ?></span>
                        <strong class="venue-name"><?= h($name) ?></strong>
                    </div>
                    <span class="venue-state"><?= $isActive ? '開催' : '休催' ?></span>
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
                                $url = $predictionPath
                                    . '?date=' . rawurlencode($selectedDate->format('Y-m-d'))
                                    . '&place=' . rawurlencode($place)
                                    . '&race=' . rawurlencode((string)$raceNo);
                            ?>
                            <a class="race-button status-<?= h($status) ?>" href="<?= h($url) ?>">
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

    <footer class="home-footer">BoatRace Analytics / 開催判定はDBの出走表データを使用</footer>
</div>
</body>
</html>
