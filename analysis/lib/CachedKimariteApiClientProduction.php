<?php
declare(strict_types=1);

require_once __DIR__ . '/../../web/api/ApiClientProduction.php';

/**
 * FinalPredictionExporter 高速化用。
 *
 * kimarite_api.php と完全一致確認済みの期間キャッシュを使い、
 * 1レースごとの決まり手HTTP + SQL集計を回避する。
 *
 * キャッシュに対象レースがない場合、または通常進入123456以外は
 * 親の現行APIへフォールバックするため、安全側に倒す。
 */
class CachedKimariteApiClientProduction extends ApiClientProduction
{
    private array $kimariteCache = [];

    public function __construct(string $cachePath)
    {
        if (!is_file($cachePath)) {
            throw new RuntimeException("決まり手キャッシュがありません: {$cachePath}");
        }

        $json = file_get_contents($cachePath);
        if ($json === false) {
            throw new RuntimeException("決まり手キャッシュを読み込めません: {$cachePath}");
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("決まり手キャッシュのJSON形式が不正です: {$cachePath}");
        }

        $this->kimariteCache = $decoded;
    }

    public function fetchKimarite(string $race_code, string $in_course): array
    {
        if (
            $in_course === '123456'
            && isset($this->kimariteCache[$race_code])
            && is_array($this->kimariteCache[$race_code])
        ) {
            return [$this->kimariteCache[$race_code], ''];
        }

        return parent::fetchKimarite($race_code, $in_course);
    }
}
