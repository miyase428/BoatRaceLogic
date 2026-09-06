<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/PredictionLogicProduction.php';

date_default_timezone_set('Asia/Tokyo');

/**
 * 今日（または指定日）のレースについて、配当傾向サインと荒れ方を一覧する。
 *
 * Usage:
 *   php analysis/list_today_payout_signals.php
 *   php analysis/list_today_payout_signals.php 2026-09-06
 *   php analysis/list_today_payout_signals.php 2026-09-06 all
 *
 * デフォルトは結果前レースのみ。
 * 第2引数 all で結果済みも含める。
 *
 * 表示状態:
 * - Web反映: 展示情報から現行Web本命/対抗まで取得できた
 * - 暫定   : 決まり手ベースのみ。Web本命/対抗系サインは未判定なので過小評価になりうる
 */

$date = trim((string)($argv[1] ?? date('Y-m-d')));
$mode = strtolower(trim((string)($argv[2] ?? 'upcoming')));
$includeFinished = ($mode === 'all');

$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($dt === false || $dt->format('Y-m-d') !== $date) {
    fwrite(STDERR, "日付は YYYY-MM-DD 形式で指定してください。\n");
    exit(1);
}

$prefix = $dt->format('Ymd');

$placeNames = [
    'KRY'=>'桐生','TDA'=>'戸田','EDG'=>'江戸川','HWJ'=>'平和島','TMG'=>'多摩川','HMN'=>'浜名湖',
    'GMG'=>'蒲郡','TKN'=>'常滑','TSU'=>'津','MKN'=>'三国','BWK'=>'びわこ','SME'=>'住之江',
    'AMG'=>'尼崎','NRT'=>'鳴門','MRG'=>'丸亀','KJM'=>'児島','MYJ'=>'宮島','TKY'=>'徳山',
    'SMS'=>'下関','WKM'=>'若松','ASY'=>'芦屋','FKO'=>'福岡','KRT'=>'唐津','OMR'=>'大村',
];

function levelJa(string $level): string
{
    return match ($level) {
        'strong' => '強',
        'watch'  => '注',
        default  => '低',
    };
}

function shortPairs(array $pairs): string
{
    if (!$pairs) return '-';
    return implode(' / ', array_slice($pairs, 0, 2));
}

try {
    $pdo = getPDO();
    $sql = <<<SQL
SELECT
    re.race_code,
    COUNT(DISTINCT re.lane_number)::int AS entry_count,
    COALESCE(rr.result_count, 0)::int AS result_count
FROM boat_race.race_entry re
LEFT JOIN (
    SELECT race_code, COUNT(*)::int AS result_count
    FROM boat_race.race_result_detail
    WHERE race_code LIKE :result_prefix
    GROUP BY race_code
) rr ON rr.race_code = re.race_code
WHERE re.race_code LIKE :entry_prefix
GROUP BY re.race_code, rr.result_count
ORDER BY re.race_code
SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':result_prefix' => $prefix . '%',
        ':entry_prefix' => $prefix . '%',
    ]);
    $races = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "DB取得失敗: {$e->getMessage()}\n");
    exit(1);
}

$api = new ApiClientProduction();
$predictionLogic = new PredictionLogicProduction();

$rows = [];
$waiting = [];
$checked = 0;

foreach ($races as $race) {
    $raceCode = trim((string)($race['race_code'] ?? ''));
    if (!preg_match('/^\d{8}([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
        continue;
    }

    $place = $m[1];
    $raceNo = (int)$m[2];
    $finished = ((int)($race['result_count'] ?? 0)) > 0;
    if (!$includeFinished && $finished) {
        continue;
    }

    $checked++;

    try {
        [$entries, $results, $calcError] = $api->fetchCalcScores($raceCode);
        if ($calcError !== '' || !is_array($results) || count($results) < 5) {
            $waiting[] = [$raceCode, '一次評価取得待ち'];
            continue;
        }

        // 展示前でも同じ艇番=コースの基準で決まり手を取得する。
        [$kimariteData, $kimariteError] = $api->fetchKimarite($raceCode, '123456');
        if ($kimariteError !== '' || !is_array($kimariteData) || !$kimariteData) {
            $waiting[] = [$raceCode, '決まり手取得待ち'];
            continue;
        }

        // Web本命・対抗は展示が取れている時だけ現行ロジックで作る。
        // 取れないレースはsummary空のまま暫定判定する。
        $summary = [];
        $webReady = false;
        try {
            [$tenjiList, $tenjiError] = $api->fetchTenji($raceCode, $results, $place);
            if ($tenjiError === '' && is_array($tenjiList) && count($tenjiList) >= 5) {
                $tenjiTest = $api->fetchTenjiTest($raceCode, $tenjiList);
                $finalPredictions = $predictionLogic->buildFinalPredictions(
                    $tenjiList,
                    $kimariteData,
                    $tenjiTest,
                    $results
                );
                if (is_array($finalPredictions) && $finalPredictions) {
                    $summary = $predictionLogic->buildSummary($finalPredictions);
                    $webReady = isset($summary['honmei_head'], $summary['taikou_head']);
                }
            }
        } catch (Throwable $e) {
            // 展示前などは暫定判定へフォールバック。
            $summary = [];
            $webReady = false;
        }

        $built = PayoutSignalFeatureBuilder::build($raceNo, $kimariteData, $summary);
        if (($built['status'] ?? '') !== 'ok') {
            $waiting[] = [$raceCode, (string)($built['reason'] ?? '特徴量不足')];
            continue;
        }

        $classified = PayoutSignalClassifier::classify($built['input']);
        $medium = $classified['payout']['medium'] ?? [];
        $high = $classified['payout']['high'] ?? [];
        $big = $classified['payout']['big'] ?? [];
        $chaos = $classified['chaos'] ?? [];

        $hasAlert = ($medium['level'] ?? 'low') !== 'low'
            || ($high['level'] ?? 'low') !== 'low'
            || ($big['level'] ?? 'low') !== 'low'
            || ($chaos['primary'] ?? '平常') !== '平常';

        if (!$hasAlert) {
            continue;
        }

        $rows[] = [
            'place' => $placeNames[$place] ?? $place,
            'race_no' => $raceNo,
            'race_code' => $raceCode,
            'finished' => $finished,
            'web_ready' => $webReady,
            'medium' => $medium,
            'high' => $high,
            'big' => $big,
            'chaos' => $chaos,
        ];
    } catch (Throwable $e) {
        $waiting[] = [$raceCode, $e->getMessage()];
    }
}

usort($rows, static function(array $a, array $b): int {
    $weight = static function(array $r): int {
        $map = ['low'=>0, 'watch'=>1, 'strong'=>2];
        return ($map[$r['big']['level'] ?? 'low'] ?? 0) * 100
            + ($map[$r['high']['level'] ?? 'low'] ?? 0) * 10
            + ($map[$r['medium']['level'] ?? 'low'] ?? 0);
    };
    $wa = $weight($a); $wb = $weight($b);
    if ($wa !== $wb) return $wb <=> $wa;
    return [$a['place'], $a['race_no']] <=> [$b['place'], $b['race_no']];
});

$line = str_repeat('=', 168);
echo $line . "\n";
echo "配当傾向サイン 今日の候補一覧\n";
echo "日付       : {$date}\n";
echo "対象       : " . ($includeFinished ? '全レース' : '結果前レースのみ') . "\n";
echo "確認       : {$checked}R\n";
echo "候補       : " . count($rows) . "R\n";
echo "判定待ち   : " . count($waiting) . "R\n";
echo "注         : 『暫定』はWeb本命/対抗未反映のため、中/高配当サインを過小評価する場合あり\n";
echo $line . "\n";

if (!$rows) {
    echo "該当候補はありません。\n";
} else {
    printf("%-8s %3s %-8s | %-14s %-14s %-14s | %-12s | %s\n",
        '場', 'R', '状態', '中配当', '高配当', '大穴', '荒れ方', '強いペア（最大2件）'
    );
    echo str_repeat('-', 168) . "\n";

    foreach ($rows as $r) {
        $m = $r['medium']; $h = $r['high']; $b = $r['big'];
        $pairs = array_merge($m['pairs'] ?? [], $h['pairs'] ?? [], $b['pairs'] ?? []);
        printf(
            "%-8s %2dR %-8s | 中:%s %d/%d     高:%s %d/%d     大:%s %d/%d     | %-12s | %s\n",
            $r['place'],
            $r['race_no'],
            $r['web_ready'] ? 'Web反映' : '暫定',
            levelJa((string)($m['level'] ?? 'low')), (int)($m['score'] ?? 0), (int)($m['max_score'] ?? 0),
            levelJa((string)($h['level'] ?? 'low')), (int)($h['score'] ?? 0), (int)($h['max_score'] ?? 0),
            levelJa((string)($b['level'] ?? 'low')), (int)($b['score'] ?? 0), (int)($b['max_score'] ?? 0),
            (string)($r['chaos']['primary'] ?? '平常'),
            shortPairs(array_values(array_unique($pairs)))
        );
    }
}

if ($waiting) {
    echo "\n【判定待ち（先頭20件）】\n";
    foreach (array_slice($waiting, 0, 20) as [$code, $reason]) {
        echo "{$code}: {$reason}\n";
    }
}

echo $line . "\n";
