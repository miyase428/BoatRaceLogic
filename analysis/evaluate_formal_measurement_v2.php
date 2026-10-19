<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalEvaluatorV2.php';

// Deliberately read-only by default.  A formal record is created only with
// the explicit --persist flag, after a separate operational authorization.
$persist = in_array('--persist', $argv, true);
$asOf = null;
$windowId = null;
foreach ($argv as $argument) if (str_starts_with($argument, '--as-of=')) $asOf = substr($argument, strlen('--as-of='));
foreach ($argv as $argument) if (str_starts_with($argument, '--window-id=')) $windowId = substr($argument, strlen('--window-id='));
if (!is_string($asOf) || $asOf === '') $asOf = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
$evaluator = new FormalEvaluatorV2(dirname(__DIR__));
$evaluation = is_string($windowId) && $windowId !== ''
    ? $evaluator->evaluateWindow($windowId, $asOf)
    : $evaluator->evaluateProduction($asOf);
if ($persist) $evaluation['record_result'] = $evaluator->persist($evaluation);
echo json_encode($evaluation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
