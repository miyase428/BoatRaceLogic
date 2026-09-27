<?php

declare(strict_types=1);

/** AI2着率・AI3着率 v1 のPython推論ブリッジ。 */
final class AiPlaceRateLogic
{
    public function calculate(
        string $raceCode,
        array $aiWinBoats,
        array $finalPredictions,
        array $courseByBoat,
        array $legacyRankBoats
    ): array {
        $root = realpath(__DIR__ . '/../..');
        if ($root === false) {
            return $this->error('AI着順率: ルートを取得できません');
        }
        $python = $root . '/.venv-models/bin/python';
        $script = realpath($root . '/forecast/ai_place_live.py');
        if (!is_executable($python) || $script === false) {
            return $this->error('AI着順率スクリプトを実行できません');
        }

        $payload = json_encode([
            'race_code' => $raceCode,
            'ai_win_boats' => $aiWinBoats,
            'final_predictions' => $finalPredictions,
            'course_by_boat' => $courseByBoat,
            'legacy_rank_boats' => array_values($legacyRankBoats),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return $this->error('AI着順率の入力JSONを作成できません');
        }

        $process = proc_open(
            [$python, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root
        );
        if (!is_resource($process)) {
            return $this->error('AI着順率プロセスを開始できません');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $data = json_decode(trim((string)$stdout), true);
        if (!is_array($data)) {
            return $this->error('AI着順率JSONの解析に失敗しました' . ($stderr !== '' ? ': ' . trim($stderr) : ''));
        }
        return $data;
    }

    private function error(string $message): array
    {
        return ['status' => 'error', 'boats' => [], 'combinations' => [], 'error' => $message];
    }
}
