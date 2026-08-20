<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$tables = [
    'race_entry',
    'race_master',
    'race_result_detail',
    'exhibition_live',
    'race_history_fact',
];

echo str_repeat('=', 96) . PHP_EOL;
echo "主要履歴テーブル インデックス確認" . PHP_EOL;
echo str_repeat('=', 96) . PHP_EOL;

$stmt = $pdo->prepare(<<<SQL
SELECT
    tablename,
    indexname,
    indexdef
FROM pg_indexes
WHERE schemaname = 'boat_race'
  AND tablename = ANY(?::text[])
ORDER BY tablename, indexname
SQL);

// PDO/pgsqlの配列バインド差を避け、固定テーブル名だけ安全に埋め込む。
$list = implode(',', array_map(
    static fn(string $t): string => $pdo->quote($t),
    $tables
));
$sql = <<<SQL
SELECT tablename, indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'boat_race'
  AND tablename IN ({$list})
ORDER BY tablename, indexname
SQL;
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$current = '';
foreach ($rows as $row) {
    $table = (string)$row['tablename'];
    if ($table !== $current) {
        if ($current !== '') echo PHP_EOL;
        echo "[{$table}]\n";
        $current = $table;
    }
    echo '  ' . (string)$row['indexname'] . PHP_EOL;
    echo '    ' . (string)$row['indexdef'] . PHP_EOL;
}

if (!$rows) {
    echo "対象インデックスを取得できませんでした。\n";
}

echo PHP_EOL . "【特に確認したい複合キー】\n";
echo "race_entry         : (race_code, player_id), (player_id, race_code)\n";
echo "race_result_detail : (race_code, player_id), rank/日付絞り込みに有効な索引\n";
echo "exhibition_live    : (race_code, player_id)\n";
echo "race_master        : (race_code), (race_date, race_code)\n";
echo str_repeat('=', 96) . PHP_EOL;
