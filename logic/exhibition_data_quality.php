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

function hasCompleteExhibitionData(array $data): bool
{
    if (count($data) !== 6) {
        return false;
    }

    $courses = [];
    $required = [
        'player_id',
        'exhibition_time',
        'start_timing',
        'lap_time',
        'around_time',
        'straight_time',
    ];

    foreach ($data as $row) {
        if (!is_array($row)) {
            return false;
        }

        $course = (int)($row['entry_course'] ?? 0);
        if ($course < 1 || $course > 6 || isset($courses[$course])) {
            return false;
        }
        $courses[$course] = true;

        foreach ($required as $field) {
            if (!exhibitionDataValuePresent($row[$field] ?? null)) {
                return false;
            }
        }
    }

    return count($courses) === 6;
}
