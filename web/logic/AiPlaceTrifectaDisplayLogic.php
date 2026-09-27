<?php

declare(strict_types=1);

/**
 * 2連単・3連単タブの最終確率を、AI着順率 v1 の同時120通りへ差し替える。
 *
 * 基礎出目は従来の場別履歴確率を比較用に残す。正式買い目などが参照する
 * TrifectaProbabilityLogic の結果自体は変更せず、表示用データにだけ適用する。
 */
final class AiPlaceTrifectaDisplayLogic
{
    private const SOURCE = 'ai_place_v1_joint120';

    public function apply(array $legacyData, array $aiPlaceData): array
    {
        if ((string)($legacyData['status'] ?? '') !== 'ok') {
            return $this->fallback($legacyData);
        }
        if ((string)($aiPlaceData['status'] ?? '') !== 'ok') {
            return $this->fallback($legacyData);
        }

        $combinations = is_array($aiPlaceData['combinations'] ?? null)
            ? $aiPlaceData['combinations']
            : [];
        $mlByKey = [];
        foreach ($combinations as $combination) {
            if (!is_array($combination)) {
                continue;
            }
            $key = trim((string)($combination['key'] ?? ''));
            $probability = $combination['probability'] ?? null;
            if (
                !preg_match('/^[1-6]-[1-6]-[1-6]$/', $key)
                || !is_numeric($probability)
                || !is_finite((float)$probability)
                || (float)$probability < 0.0
            ) {
                continue;
            }
            $mlByKey[$key] = (float)$probability;
        }

        if (count($mlByKey) !== 120) {
            return $this->fallback($legacyData);
        }

        $legacyRows = is_array($legacyData['rows'] ?? null) ? $legacyData['rows'] : [];
        if (empty($legacyRows)) {
            return $this->fallback($legacyData);
        }

        $selectedTotal = 0.0;
        foreach ($legacyRows as $row) {
            $key = $this->rowKey(is_array($row) ? $row : []);
            if ($key === '' || !array_key_exists($key, $mlByKey)) {
                return $this->fallback($legacyData);
            }
            $selectedTotal += $mlByKey[$key];
        }
        if ($selectedTotal <= 0.0) {
            return $this->fallback($legacyData);
        }

        $rows = [];
        foreach ($legacyRows as $legacyRow) {
            $row = is_array($legacyRow) ? $legacyRow : [];
            $key = $this->rowKey($row);
            $row['legacy_probability'] = (float)($row['probability'] ?? 0.0);
            $row['probability'] = $mlByKey[$key] / $selectedTotal;
            $row['probability_source'] = self::SOURCE;
            $rows[] = $row;
        }

        usort($rows, static function (array $a, array $b): int {
            $cmp = (float)($b['probability'] ?? 0.0) <=> (float)($a['probability'] ?? 0.0);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
        });

        $cumulative = 0.0;
        foreach ($rows as $index => &$row) {
            $cumulative += (float)$row['probability'];
            $row['rank'] = $index + 1;
            $row['cumulative_probability'] = $cumulative;
        }
        unset($row);

        $legacyData['rows'] = $rows;
        $legacyData['top20'] = array_slice($rows, 0, 20);
        $legacyData['totals'] = is_array($legacyData['totals'] ?? null) ? $legacyData['totals'] : [];
        $legacyData['totals']['final'] = array_sum(array_column($rows, 'probability'));
        $legacyData['method'] = is_array($legacyData['method'] ?? null) ? $legacyData['method'] : [];
        $legacyData['method']['probability_source'] = self::SOURCE;
        $legacyData['method']['display_name'] = 'AI着順率 v1・同時120通り';
        $legacyData['probability_source'] = self::SOURCE;
        $legacyData['model_version'] = 'v1';
        $legacyData['display_fallback'] = false;

        return $legacyData;
    }

    private function fallback(array $data): array
    {
        $data['probability_source'] = 'legacy_trifecta';
        $data['model_version'] = '';
        $data['display_fallback'] = true;
        return $data;
    }

    private function rowKey(array $row): string
    {
        $key = trim((string)($row['key'] ?? ''));
        if (preg_match('/^[1-6]-[1-6]-[1-6]$/', $key)) {
            return $key;
        }

        $boats = is_array($row['boats'] ?? null) ? array_values($row['boats']) : [];
        if (count($boats) !== 3) {
            return '';
        }
        $boats = array_map('intval', $boats);
        if (count(array_unique($boats)) !== 3 || min($boats) < 1 || max($boats) > 6) {
            return '';
        }
        return implode('-', $boats);
    }
}
