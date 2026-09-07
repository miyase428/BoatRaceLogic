<?php
require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/controllers/IndexController.php';
require_once __DIR__ . '/logic/AiTrioRateLogic.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function homeHighlightsJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function homeHighlightsValidDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

$dateText = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!homeHighlightsValidDate($dateText)) {
    homeHighlightsJson([
        'status' => 'error',
        'error' => '日付の形式が不正です。',
        'rows' => [],
    ], 400);
}

$force = (string)($_GET['force'] ?? '') === '1';
$datePrefix = str_replace('-', '', $dateText);
$cacheFile = sys_get_temp_dir() . '/boatrace_home_upset_' . $datePrefix . '.json';
$cacheTtl = 90;

if (!$force && is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < $cacheTtl) {
    $cached = file_get_contents($cacheFile);
    if (is_string($cached) && $cached !== '') {
        $data = json_decode($cached, true);
        if (is_array($data)) {
            $data['cache_used'] = true;
            homeHighlightsJson($data);
        }
    }
}

$placeNames = [
    'KRY' => '桐生',   'TDA' => '戸田',   'EDG' => '江戸川', 'HWJ' => '平和島',
    'TMG' => '多摩川', 'HMN' => '浜名湖', 'GMG' => '蒲郡',   'TKN' => '常滑',
    'TSU' => '津',     'MKN' => '三国',   'BWK' => 'びわこ', 'SME' => '住之江',
    'AMG' => '尼崎',   'NRT' => '鳴門',   'MRG' => '丸亀',   'KJM' => '児島',
    'MYJ' => '宮島',   'TKY' => '徳山',   'SMS' => '下関',   'WKM' => '若松',
    'ASY' => '芦屋',   'FKO' => '福岡',   'KRT' => '唐津',   'OMR' => '大村',
];

try {
    $pdo = getPDO();

    // TOP画面では「展示が入り、まだ結果が出ていないレース」だけを判定する。
    // 全144Rを毎回再計算せず、実際に今買える候補へ絞ることで負荷を抑える。
    $stmt = $pdo->prepare(<<<SQL
        WITH entries AS (
            SELECT race_code, COUNT(*) AS entry_count
            FROM boat_race.race_entry
            WHERE race_code LIKE :prefix_entries
            GROUP BY race_code
        ), exhibitions AS (
            SELECT
                race_code,
                COUNT(*) FILTER (
                    WHERE exhibition_time IS NOT NULL OR start_timing IS NOT NULL
                ) AS exhibition_count
            FROM boat_race.exhibition_live
            WHERE race_code LIKE :prefix_exhibitions
            GROUP BY race_code
        ), results AS (
            SELECT race_code, COUNT(*) AS result_count
            FROM boat_race.race_result_detail
            WHERE race_code LIKE :prefix_results
            GROUP BY race_code
        )
        SELECT e.race_code
        FROM entries e
        LEFT JOIN exhibitions x ON x.race_code = e.race_code
        LEFT JOIN results r ON r.race_code = e.race_code
        WHERE e.entry_count >= 6
          AND COALESCE(x.exhibition_count, 0) >= 5
          AND COALESCE(r.result_count, 0) < 3
        ORDER BY e.race_code
    SQL);
    $stmt->execute([
        ':prefix_entries' => $datePrefix . '%',
        ':prefix_exhibitions' => $datePrefix . '%',
        ':prefix_results' => $datePrefix . '%',
    ]);
    $raceCodes = array_values(array_filter(array_map(
        static fn(array $row): string => (string)($row['race_code'] ?? ''),
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    )));

    $controller = new IndexController();
    $aiTrioLogic = new AiTrioRateLogic();
    $rows = [];
    $evaluated = 0;
    $errors = [];

    $originalGet = $_GET;
    $originalPost = $_POST;

    foreach ($raceCodes as $raceCode) {
        if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            continue;
        }

        $place = $m[2];
        $raceNo = (int)$m[3];
        if (!isset($placeNames[$place])) {
            continue;
        }

        try {
            $_GET = [
                'date' => $dateText,
                'place' => $place,
                'race' => (string)$raceNo,
            ];
            $_POST = [];

            $view = $controller->handle();
            $correctedData = is_array($view['corrected_win_rate_data'] ?? null)
                ? $view['corrected_win_rate_data']
                : [];
            $correctedBoats = is_array($correctedData['boats'] ?? null)
                ? $correctedData['boats']
                : [];

            if ((string)($correctedData['status'] ?? '') !== 'ok' || count($correctedBoats) !== 6) {
                continue;
            }

            $courseByBoat = is_array($view['entry_course_by_boat'] ?? null)
                ? $view['entry_course_by_boat']
                : [];
            if (count($courseByBoat) !== 6 || empty($view['entry_map_ready'])) {
                continue;
            }

            $aiTrio = $aiTrioLogic->calculate(
                $raceCode,
                is_array($view['results'] ?? null) ? $view['results'] : [],
                is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [],
                $courseByBoat,
                false
            );
            if ((string)($aiTrio['status'] ?? '') !== 'ok') {
                continue;
            }

            $inBoat = 0;
            foreach ($courseByBoat as $boatKey => $course) {
                $boat = (int)$boatKey;
                if ((int)$course === 1 && $boat >= 1 && $boat <= 6) {
                    $inBoat = $boat;
                    break;
                }
            }
            if ($inBoat < 1 || $inBoat > 6) {
                continue;
            }

            $correctedRates = [];
            for ($boat = 1; $boat <= 6; $boat++) {
                $rate = $correctedBoats[$boat]['corrected_rate']
                    ?? $correctedBoats[(string)$boat]['corrected_rate']
                    ?? null;
                if (!is_numeric($rate)) {
                    $correctedRates = [];
                    break;
                }
                $correctedRates[$boat] = (float)$rate;
            }
            if (count($correctedRates) !== 6) {
                continue;
            }

            $ranked = range(1, 6);
            usort($ranked, static function (int $a, int $b) use ($correctedRates): int {
                $cmp = ($correctedRates[$b] <=> $correctedRates[$a]);
                return $cmp !== 0 ? $cmp : ($a <=> $b);
            });

            $aiHead = (int)($ranked[0] ?? 0);
            $currentHead = (int)($view['honmei_head'] ?? 0);
            $inRate = $correctedRates[$inBoat] ?? null;
            $evaluated++;

            // 本番の upset_alert_panel.php と同じ荒れ警戒の入口。
            // AI1着率ではインが最上位なのに、現行本命がイン以外へ振れた時だけ警戒する。
            if ($aiHead !== $inBoat || $currentHead === $inBoat || $inRate === null) {
                continue;
            }

            $level = 'normal';
            $label = '通常';
            if ($inRate < 40.0) {
                $level = 'very_high';
                $label = '非常に高';
            } elseif ($inRate < 50.0) {
                $level = 'high';
                $label = '高';
            } elseif ($inRate < 55.0) {
                $level = 'attention';
                $label = '注意';
            }

            if ($level === 'normal') {
                continue;
            }

            $rows[] = [
                'race_code' => $raceCode,
                'place' => $place,
                'venue' => $placeNames[$place],
                'race_no' => $raceNo,
                'level' => $level,
                'level_label' => $label,
                'in_boat' => $inBoat,
                'in_rate' => round((float)$inRate, 2),
                'current_head' => $currentHead,
            ];
        } catch (Throwable $raceError) {
            $errors[] = [
                'race_code' => $raceCode,
                'error' => $raceError->getMessage(),
            ];
        }
    }

    $_GET = $originalGet;
    $_POST = $originalPost;

    $priority = ['very_high' => 0, 'high' => 1, 'attention' => 2];
    usort($rows, static function (array $a, array $b) use ($priority): int {
        $pa = $priority[(string)($a['level'] ?? '')] ?? 9;
        $pb = $priority[(string)($b['level'] ?? '')] ?? 9;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $rateCmp = ((float)($a['in_rate'] ?? 999.0)) <=> ((float)($b['in_rate'] ?? 999.0));
        if ($rateCmp !== 0) {
            return $rateCmp;
        }
        return strcmp((string)($a['race_code'] ?? ''), (string)($b['race_code'] ?? ''));
    });

    $payload = [
        'status' => 'ok',
        'date' => $dateText,
        'rows' => array_slice($rows, 0, 8),
        'total_alerts' => count($rows),
        'candidate_races' => count($raceCodes),
        'evaluated_races' => $evaluated,
        'generated_at' => date(DATE_ATOM),
        'cache_used' => false,
        'errors' => array_slice($errors, 0, 5),
    ];

    @file_put_contents(
        $cacheFile,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        LOCK_EX
    );

    homeHighlightsJson($payload);
} catch (Throwable $e) {
    homeHighlightsJson([
        'status' => 'error',
        'error' => $e->getMessage(),
        'rows' => [],
    ], 500);
}
