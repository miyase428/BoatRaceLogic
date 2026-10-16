<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/ComparativeContractV1.php';
require_once __DIR__ . '/../web/logic/FormalComparativeReadinessEvaluator.php';

if (($argv[1] ?? '') === '') { fwrite(STDERR, "usage: evaluate_comparative_readiness_v1.php <window-root>\n"); exit(2); }
$contract = ComparativeContractV1::production(dirname(__DIR__));
$root = rtrim((string)$argv[1], DIRECTORY_SEPARATOR);
echo ComparativeContractV1::canonicalJson((new FormalComparativeReadinessEvaluator($contract))->evaluateWindow($root)) . "\n";
