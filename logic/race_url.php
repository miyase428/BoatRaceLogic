<?php

function raceCodeToKyoteiBiyoriUrl(string $race_code): string
{
    // race_code = YYYYMMDD + stadium_code(3) + race_no(2)
    $hiduke = substr($race_code, 0, 8);
    $stadium_code = substr($race_code, 8, 3);
    $race_no = intval(substr($race_code, 11, 2)); // "01" → 1

    // place_no 対応表を読み込み
    $map = require __DIR__ . '/../config/place_map_reverse.php';

    if (!isset($map[$stadium_code])) {
        throw new Exception("Unknown stadium_code: {$stadium_code}");
    }

    $place_no = $map[$stadium_code];

    // 競艇日和の展示情報URLを生成
    $url = "https://kyoteibiyori.com/race_shusso.php"
         . "?place_no={$place_no}"
         . "&race_no={$race_no}"
         . "&hiduke={$hiduke}"
         . "&slider=4";

    return $url;
}