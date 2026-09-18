<?php

/**
 * 展示情報を「完備」とみなすための共通判定。
 * 一部項目だけ取得できた6艇分は完備扱いにせず、次回取得対象として残す。
 */
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

function hasCompleteExhibitionData(array $data): bool
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
        foreach (['exhibition_time', 'lap_time', 'around_time', 'straight_time'] as $field) {
            if (!exhibitionPositiveNumberPresent($row[$field] ?? null)) {
                return false;
            }
        }
    }

    return count($courses) === 6;
}
