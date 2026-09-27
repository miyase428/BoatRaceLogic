<?php

/**
 * 展示情報を「完備」とみなすための共通判定。
 * 直線タイムを公表しないAMG/TKY/SMEでは、直線を除く4項目で判定する。
 */
function exhibitionRequiresStraight(?string $placeCode): bool
{
    return !in_array(strtoupper(trim((string)$placeCode)), ['AMG', 'TKY', 'SME'], true);
}

function exhibitionDataValuePresent($value): bool
{
    if (!is_scalar($value)) {
        return false;
    }

    $text = trim((string)$value);
    return $text !== '' && $text !== '-' && $text !== '--';
}

function exhibitionPositiveNumberPresent($value): bool
{
    return exhibitionDataValuePresent($value)
        && is_numeric((string)$value)
        && (float)$value > 0.0;
}

function hasCompleteExhibitionData(array $data, ?string $placeCode = null): bool
{
    if (count($data) !== 6) {
        return false;
    }

    $courses = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            return false;
        }

        $course = (int)($row['entry_course'] ?? 0);
        if ($course < 1 || $course > 6 || isset($courses[$course])) {
            return false;
        }
        $courses[$course] = true;

        if (!exhibitionDataValuePresent($row['player_id'] ?? null)) {
            return false;
        }

        // 展示STの0.00は有効値。一方、各展示タイムの0は未取得値として扱う。
        if (!exhibitionDataValuePresent($row['start_timing'] ?? null)) {
            return false;
        }
        foreach (['exhibition_time', 'lap_time', 'around_time'] as $field) {
            if (!exhibitionPositiveNumberPresent($row[$field] ?? null)) {
                return false;
            }
        }
        if (exhibitionRequiresStraight($placeCode)
            && !exhibitionPositiveNumberPresent($row['straight_time'] ?? null)) {
            return false;
        }
    }

    return count($courses) === 6;
}
