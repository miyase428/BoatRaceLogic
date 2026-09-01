<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 大村・下関 後半7～12R 外枠事前分析 V3
 *
 * V2修正点:
 * - final_prediction_boats の actual_rank が空欄の艇は、従来検証仕様どおり 5.5（着外）として扱う。
 * - 空欄を除外しないため、4～6Cの3連対率の分母が過大に小さくならない。
 *
 * 対象艇の定義はレース前情報のみ:
 * - exhibition_live.entry_course を優先
 * - 展示進入なしは lane_number fallback
 *
 * Usage:
 *   php analysis/analyze_omura_shimonoseki_prerace_outer_v3.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

const TARGET_VENUES = ['大村', '下関'];
const OUTER_COURSES = [4, 5, 6];
const CONDITIONS = [
    'all' => '全体',
    'A1' => 'A1',
    'first_top3' => '一次TOP3',
    'second_top3' => '二次TOP3',
    'final_top3' => '最終TOP3',
    'A1_first_top3' => 'A1×一次TOP3',
    'A1_second_top3' => 'A1×二次TOP3',
    'A1_final_top3' => 'A1×最終TOP3',
];

function usage(): never {
    fwrite(STDERR, "使用方法:\n  php analysis/analyze_omura_shimonoseki_prerace_outer_v3.php KIMARITE_DATASET_CSV BOATS_CSV\n");
    exit(1);
}

function readCsv(string $path): array {
    if (!is_file($path)) throw new RuntimeException("CSVがありません: {$path}");
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if (!$header) throw new RuntimeException("CSVヘッダを読めません: {$path}");
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
        $row = array_combine($header, $cols);
        if (is_array($row)) $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

function sv(array $r, string $k): string { return trim((string)($r[$k] ?? '')); }
function iv(array $r, string $k): int {
    $v = sv($r, $k);
    if ($v === '') return 0;
    if (preg_match('/^-?\d+$/', $v)) return (int)$v;
    if (preg_match('/(\d+)/', $v, $m)) return (int)$m[1];
    return 0;
}
function fv(array $r, string $k): float {
    $v = str_replace('%', '', sv($r, $k));
    return is_numeric($v) ? (float)$v : 0.0;
}
function actualRank(array $r): ?float {
    $v = sv($r, 'actual_rank');
    if ($v === '') return 5.5; // 既存検証仕様: 5/6着・着外はNULL/空欄→5.5
    if (!is_numeric($v)) return null;
    $rank = (float)$v;
    return ($rank >= 1.0 && $rank <= 6.0) ? $rank : null;
}
function raceNo(array $r): int {
    $n = iv($r, 'race_number');
    if ($n >= 1 && $n <= 12) return $n;
    $code = sv($r, 'race_code');
    return preg_match('/(0[1-9]|1[0-2])$/', $code, $m) ? (int)$m[1] : 0;
}
function isFormal(array $r): bool {
    return iv($r, 'result_top3_course_complete') === 1 && iv($r, 'result_boat_match') === 1;
}
function pct(int $n, int $d): string { return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-'; }
function bucket(): array { return ['n'=>0,'first'=>0,'second'=>0,'third'=>0,'top3'=>0]; }
function addBucket(array &$b, float $rank): void {
    $b['n']++;
    if ($rank === 1.0) $b['first']++;
    if ($rank === 2.0) $b['second']++;
    if ($rank === 3.0) $b['third']++;
    if ($rank >= 1.0 && $rank <= 3.0) $b['top3']++;
}
function fmtBucket(array $b): string {
    return sprintf('N=%4d 1着=%7s 2着=%7s 3着=%7s 3連対=%7s',
        $b['n'], pct($b['first'],$b['n']), pct($b['second'],$b['n']), pct($b['third'],$b['n']), pct($b['top3'],$b['n']));
}

function termInfoSql(): string {
    return "(CASE WHEN EXTRACT(MONTH FROM rm.race_date) <= 4 THEN TO_CHAR(rm.race_date - INTERVAL '1 year','YY') || '10' " .
           "WHEN EXTRACT(MONTH FROM rm.race_date) <= 10 THEN TO_CHAR(rm.race_date,'YY') || '04' " .
           "ELSE TO_CHAR(rm.race_date,'YY') || '10' END)";
}

function loadPreRaceCourseMap(PDO $pdo, string $from, string $to): array {
    $term = termInfoSql();
    $sql = <<<SQL
SELECT rm.race_code,
       re.lane_number::integer AS lane_number,
       re.player_id::text AS player_id,
       el.entry_course::integer AS exhibition_course,
       UPPER(TRIM(COALESCE(rr."class"::text, ''))) AS grade
FROM boat_race.race_master rm
JOIN boat_race.race_entry re ON re.race_code = rm.race_code
LEFT JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code AND el.player_id = re.player_id
LEFT JOIN boat_race.racer_results rr
  ON rr.player_id = re.player_id AND rr.term_info::text = {$term}
WHERE rm.race_date BETWEEN :f AND :t
  AND rm.stadium_name IN ('大村','下関')
ORDER BY rm.race_code, re.lane_number
SQL;
    $st = $pdo->prepare($sql);
    $st->execute([':f'=>$from, ':t'=>$to]);

    $byLane = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $code = (string)$r['race_code'];
        $lane = (int)$r['lane_number'];
        if ($lane < 1 || $lane > 6) continue;
        if (!isset($byLane[$code][$lane])) {
            $byLane[$code][$lane] = ['grade'=>strtoupper(trim((string)$r['grade'])),'courses'=>[]];
        }
        $c = (int)($r['exhibition_course'] ?? 0);
        if ($c >= 1 && $c <= 6) $byLane[$code][$lane]['courses'][$c] = true;
    }

    $candidate = [];
    $meta = ['exhibition'=>0,'fallback'=>0,'ambiguous'=>0,'duplicate'=>0];
    foreach ($byLane as $code => $lanes) {
        foreach ($lanes as $lane => $r) {
            $courses = array_map('intval', array_keys($r['courses']));
            if (count($courses) === 1) {
                $course = $courses[0]; $source = 'exhibition'; $meta['exhibition']++;
            } else {
                $course = (int)$lane; $source = 'fallback'; $meta['fallback']++;
                if (count($courses) > 1) $meta['ambiguous']++;
            }
            $candidate[$code][$course][] = ['lane'=>(int)$lane,'grade'=>$r['grade'],'source'=>$source];
        }
    }

    $map = [];
    foreach ($candidate as $code => $courses) {
        foreach ($courses as $course => $items) {
            if (count($items) !== 1) { $meta['duplicate']++; continue; }
            $map[$code][(int)$course] = $items[0];
        }
    }
    return [$map, $meta];
}

function matches(string $cond, string $grade, array $boat): bool {
    $a1 = ($grade === 'A1');
    $p = ($boat['first_rank'] >= 1 && $boat['first_rank'] <= 3);
    $s = ($boat['second_rank'] >= 1 && $boat['second_rank'] <= 3);
    $f = ($boat['final_rank'] >= 1 && $boat['final_rank'] <= 3);
    return match($cond) {
        'all' => true,
        'A1' => $a1,
        'first_top3' => $p,
        'second_top3' => $s,
        'final_top3' => $f,
        'A1_first_top3' => $a1 && $p,
        'A1_second_top3' => $a1 && $s,
        'A1_final_top3' => $a1 && $f,
        default => false,
    };
}

[$script,$datasetPath,$boatsPath] = array_pad($argv,3,null);
if (!$datasetPath || !$boatsPath || count($argv)!==3) usage();

$dataset = readCsv($datasetPath);
$boatRows = readCsv($boatsPath);

$boats = [];
$invalidRank = 0;
$blankAsOutside = 0;
foreach ($boatRows as $r) {
    $venue = sv($r,'stadium_name');
    if (!in_array($venue,TARGET_VENUES,true)) continue;
    $code = sv($r,'race_code'); $lane = iv($r,'lane_number');
    if ($code==='' || $lane<1 || $lane>6) continue;
    $rankRaw = sv($r,'actual_rank');
    $rank = actualRank($r);
    if ($rank === null) { $invalidRank++; continue; }
    if ($rankRaw === '') $blankAsOutside++;
    $boats[$code][$lane] = [
        'first_rank'=>iv($r,'first_rank'),
        'second_rank'=>iv($r,'second_rank'),
        'final_rank'=>iv($r,'final_rank'),
        'actual_rank'=>$rank,
    ];
}

$rows=[]; $from='9999-12-31'; $to='0000-01-01';
foreach ($dataset as $r) {
    $venue=sv($r,'stadium_name');
    if (!in_array($venue,TARGET_VENUES,true) || !isFormal($r)) continue;
    $rn=raceNo($r); if ($rn<7 || $rn>12) continue;
    $rows[]=$r;
    $d=sv($r,'race_date'); if ($d!=='') { if ($d<$from) $from=$d; if ($d>$to) $to=$d; }
}
if (!$rows) throw new RuntimeException('大村・下関の後半正式対象が0件です。');

[$courseMap,$courseMeta]=loadPreRaceCourseMap(getPDO(),$from,$to);
$contexts=[
    'all'=>'後半7～12R 全体',
    'web1'=>'後半7～12R × Web本命=1',
    'escape'=>'後半7～12R × 逃げ目安100%以上',
    'both'=>'後半7～12R × Web本命=1 × 逃げ目安100%以上',
];
$stats=[]; $meta=[];
foreach (TARGET_VENUES as $v) foreach ($contexts as $ck=>$label) {
    $meta[$v][$ck]=['races'=>0,'actual_escape'=>0];
    foreach (OUTER_COURSES as $c) foreach (array_keys(CONDITIONS) as $cond) $stats[$v][$ck][$c][$cond]=bucket();
}
$sourceUsed=['exhibition'=>0,'fallback'=>0]; $courseMissing=0; $boatMissing=0;

foreach ($rows as $r) {
    $v=sv($r,'stadium_name'); $code=sv($r,'race_code');
    $actualEscape=(iv($r,'actual_1st_course')===1 && sv($r,'winner_technique')==='逃げ');
    $web1=(iv($r,'honmei_head')===1);
    $escape=(fv($r,'c1_1y_nige')+fv($r,'c2_1y_nogashi')>=100.0);
    $active=['all']; if($web1)$active[]='web1'; if($escape)$active[]='escape'; if($web1&&$escape)$active[]='both';
    foreach($active as $ck){$meta[$v][$ck]['races']++; if($actualEscape)$meta[$v][$ck]['actual_escape']++;}

    foreach(OUTER_COURSES as $course){
        $cm=$courseMap[$code][$course]??null; if(!$cm){$courseMissing++; continue;}
        $lane=(int)$cm['lane']; $boat=$boats[$code][$lane]??null; if(!$boat){$boatMissing++; continue;}
        $src=(($cm['source']??'')==='exhibition')?'exhibition':'fallback'; $sourceUsed[$src]++;
        foreach($active as $ck) foreach(CONDITIONS as $cond=>$label) {
            if(matches($cond,(string)$cm['grade'],$boat)) addBucket($stats[$v][$ck][$course][$cond],(float)$boat['actual_rank']);
        }
    }
}

echo str_repeat('=',116)."\n";
echo "大村・下関 後半レース 事前条件 × 外枠到達率 V3\n";
echo "期間: {$from} ～ {$to}\n";
echo "艇定義: 展示進入（無ければ枠番） / actual_rank空欄は5.5着外として分母に含める\n";
echo "逃げ目安: 1C・1年逃げ率 + 2C・1年逃し率 >= 100%\n";
echo str_repeat('=',116)."\n";

foreach(TARGET_VENUES as $v){
    echo "\n【{$v}】\n";
    foreach($contexts as $ck=>$ctxLabel){
        $m=$meta[$v][$ck];
        echo "\n--- {$ctxLabel} ---\n";
        echo sprintf("対象=%dR / 実際に1逃げ=%s (%dR)\n",$m['races'],pct($m['actual_escape'],$m['races']),$m['actual_escape']);
        foreach(OUTER_COURSES as $course){
            echo "[展示{$course}C艇]\n";
            $base=$stats[$v][$ck][$course]['all'];
            foreach(CONDITIONS as $cond=>$label){
                $b=$stats[$v][$ck][$course][$cond];
                $delta=($base['n']>0&&$b['n']>0)?(($b['top3']/$b['n'])-($base['top3']/$base['n']))*100:0;
                $dt=($cond==='all'||$b['n']===0)?'':sprintf(' 差=%+6.2fpt',$delta);
                echo sprintf("  %-17s %s%s\n",$label,fmtBucket($b),$dt);
            }
        }
    }
}

echo "\n".str_repeat('=',116)."\n";
echo "actual_rank空欄→5.5着外: {$blankAsOutside}艇 / 不正rank除外: {$invalidRank}艇\n";
echo "DBコース構築: 展示艇={$courseMeta['exhibition']} / 枠番fallback={$courseMeta['fallback']} / 展示複数値={$courseMeta['ambiguous']} / コース重複slot={$courseMeta['duplicate']}\n";
echo "分析時: コースメタ不足={$courseMissing} / Web艇データ不足={$boatMissing}\n";
echo "分析対象4～6Cソース: 展示={$sourceUsed['exhibition']} / fallback={$sourceUsed['fallback']}\n";
echo "※PredictionLogicや買い目補正は変更しません。\n";
echo str_repeat('=',116)."\n";
