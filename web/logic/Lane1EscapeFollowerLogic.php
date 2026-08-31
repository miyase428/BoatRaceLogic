<?php

declare(strict_types=1);

/**
 * Web本命①の相手候補へ、場別「1コース逃げ時の2着/3着コース傾向」を反映する。
 *
 * 検証済み固定仕様:
 * - final_rank相当（summary.rank_boatsの並び） + 場別フォロワー順位を1:1順位和で合成
 * - 本命①のみ
 * - 展示進入6艇完備かつ1号艇が1Cの時だけ適用
 * - 現行honmei_kaiの2着/3着候補数を維持
 * - kiru艇は候補から除外
 * - 重み・閾値・場除外は追加しない
 */
class Lane1EscapeFollowerLogic
{
    private ?array $model = null;

    public function apply(
        array $summary,
        array $finalPredictions,
        string $stadiumName,
        array $entryCourseByBoat,
        bool $entryMapReady
    ): array {
        $summary['lane1_escape_follower_applied'] = false;
        $summary['lane1_escape_follower_reason'] = '';

        if ((int)($summary['honmei_head'] ?? 0) !== 1) {
            $summary['lane1_escape_follower_reason'] = '本命①ではない';
            return $summary;
        }

        if (!$entryMapReady || !$this->isCompleteCourseMap($entryCourseByBoat)) {
            $summary['lane1_escape_follower_reason'] = '展示進入6艇不完備';
            return $summary;
        }

        if ((int)($entryCourseByBoat[1] ?? 0) !== 1) {
            $summary['lane1_escape_follower_reason'] = '1号艇が展示1Cではない';
            return $summary;
        }

        $model = $this->loadModel();
        $stadium = $model['stadiums'][$stadiumName] ?? null;
        if (!is_array($stadium)) {
            $summary['lane1_escape_follower_reason'] = '場別モデルなし';
            return $summary;
        }

        $secondRank = $stadium['second_rank'] ?? null;
        $thirdRank = $stadium['third_rank'] ?? null;
        if (!is_array($secondRank) || !is_array($thirdRank)) {
            $summary['lane1_escape_follower_reason'] = '場別順位不備';
            return $summary;
        }

        $parsed = $this->parseFormation((string)($summary['honmei_kai'] ?? ''));
        if ($parsed === null || $parsed[0] !== [1]) {
            $summary['lane1_escape_follower_reason'] = '本命買い目構造不備';
            return $summary;
        }
        [, $currentSecond, $currentThird] = $parsed;

        $rankBoats = array_values(array_map('intval', $summary['rank_boats'] ?? []));
        if (count($rankBoats) !== 6) {
            $summary['lane1_escape_follower_reason'] = 'rank_boats不備';
            return $summary;
        }

        $currentRank = [];
        foreach ($rankBoats as $i => $boat) {
            if ($boat >= 1 && $boat <= 6) {
                $currentRank[$boat] = $i + 1;
            }
        }
        if (count($currentRank) !== 6) {
            $summary['lane1_escape_follower_reason'] = 'rank_boats重複';
            return $summary;
        }

        $allowed = [];
        foreach ($rankBoats as $boat) {
            if ($boat === 1) {
                continue;
            }
            if ((int)($finalPredictions[$boat]['kiru'] ?? 0) === 1) {
                continue;
            }
            if (!isset($entryCourseByBoat[$boat])) {
                continue;
            }
            $allowed[] = $boat;
        }

        $secondOrder = $allowed;
        usort($secondOrder, function (int $a, int $b) use ($currentRank, $entryCourseByBoat, $secondRank): int {
            $courseA = (int)$entryCourseByBoat[$a];
            $courseB = (int)$entryCourseByBoat[$b];
            $scoreA = ($currentRank[$a] ?? 99) + (int)($secondRank[$courseA] ?? 99);
            $scoreB = ($currentRank[$b] ?? 99) + (int)($secondRank[$courseB] ?? 99);
            if ($scoreA !== $scoreB) {
                return $scoreA <=> $scoreB;
            }
            if (($currentRank[$a] ?? 99) !== ($currentRank[$b] ?? 99)) {
                return ($currentRank[$a] ?? 99) <=> ($currentRank[$b] ?? 99);
            }
            return $a <=> $b;
        });

        $newSecond = array_slice($secondOrder, 0, min(count($currentSecond), count($secondOrder)));

        $thirdOrder = $allowed;
        usort($thirdOrder, function (int $a, int $b) use ($currentRank, $entryCourseByBoat, $thirdRank): int {
            $courseA = (int)$entryCourseByBoat[$a];
            $courseB = (int)$entryCourseByBoat[$b];
            $scoreA = ($currentRank[$a] ?? 99) + (int)($thirdRank[$courseA] ?? 99);
            $scoreB = ($currentRank[$b] ?? 99) + (int)($thirdRank[$courseB] ?? 99);
            if ($scoreA !== $scoreB) {
                return $scoreA <=> $scoreB;
            }
            if (($currentRank[$a] ?? 99) !== ($currentRank[$b] ?? 99)) {
                return ($currentRank[$a] ?? 99) <=> ($currentRank[$b] ?? 99);
            }
            return $a <=> $b;
        });

        $newThird = $newSecond;
        foreach ($thirdOrder as $boat) {
            if (count($newThird) >= count($currentThird)) {
                break;
            }
            if (!in_array($boat, $newThird, true)) {
                $newThird[] = $boat;
            }
        }

        if (!$newSecond || !$newThird) {
            $summary['lane1_escape_follower_reason'] = '候補生成失敗';
            return $summary;
        }

        sort($newSecond);
        sort($newThird);

        $summary['honmei_aite_str'] = implode('・', $newSecond);
        $summary['honmei_aite_kako'] = implode('', $newSecond);
        $summary['honmei_third_kako'] = implode('', $newThird);
        $summary['honmei_kai'] = '1-' . $summary['honmei_aite_kako'] . '-' . $summary['honmei_third_kako'];
        $summary['lane1_escape_follower_applied'] = true;
        $summary['lane1_escape_follower_reason'] = '適用';
        $summary['lane1_escape_follower_stadium'] = $stadiumName;
        $summary['lane1_escape_follower_sample_n'] = (int)($stadium['n'] ?? 0);

        return $summary;
    }

    private function loadModel(): array
    {
        if ($this->model !== null) {
            return $this->model;
        }

        $path = __DIR__ . '/../../config/lane1_escape_follower_model.php';
        if (!is_file($path)) {
            $this->model = [];
            return $this->model;
        }

        $model = require $path;
        $this->model = is_array($model) ? $model : [];
        return $this->model;
    }

    private function isCompleteCourseMap(array $courseByBoat): bool
    {
        if (count($courseByBoat) !== 6) {
            return false;
        }

        $courses = [];
        for ($boat = 1; $boat <= 6; $boat++) {
            $course = (int)($courseByBoat[$boat] ?? 0);
            if ($course < 1 || $course > 6) {
                return false;
            }
            $courses[] = $course;
        }

        sort($courses);
        return $courses === [1, 2, 3, 4, 5, 6];
    }

    private function parseFormation(string $formation): ?array
    {
        $parts = explode('-', trim($formation));
        if (count($parts) !== 3) {
            return null;
        }

        $out = [];
        foreach ($parts as $part) {
            $boats = array_values(array_unique(array_map('intval', str_split(trim($part)))));
            $boats = array_values(array_filter($boats, static fn (int $boat): bool => $boat >= 1 && $boat <= 6));
            if (!$boats) {
                return null;
            }
            $out[] = $boats;
        }

        return $out;
    }
}
