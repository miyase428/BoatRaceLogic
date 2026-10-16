<?php

declare(strict_types=1);

/** @return array{mode:'DECLARE'|'HELP'|'VERSION'} */
function declarationCliArguments(array $argv): array
{
    $arguments = array_values(array_slice($argv, 1));
    if ($arguments === []) return ['mode' => 'DECLARE'];
    if (count($arguments) !== 1) throw new InvalidArgumentException('usage');
    return match ($arguments[0]) {
        '--help', '-h' => ['mode' => 'HELP'],
        '--version' => ['mode' => 'VERSION'],
        default => throw new InvalidArgumentException('unknown_argument:' . $arguments[0]),
    };
}

function declarationCliUsage(): string
{
    return 'Usage: php analysis/declare_window_implementation_identity.php [--help|-h|--version]' . PHP_EOL;
}

try {
    $arguments = declarationCliArguments($argv);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, declarationCliUsage());
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}

if ($arguments['mode'] === 'HELP') {
    echo declarationCliUsage();
    exit(0);
}
if ($arguments['mode'] === 'VERSION') {
    echo "declare-window-implementation-identity/v1" . PHP_EOL;
    exit(0);
}

require_once __DIR__ . '/../web/logic/WindowDeclaredImplementationIdentity.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';

// This creates only the first-write declaration and its immutable source
// artifacts. It does not invoke scheduler/capture, query the database, or
// alter the armed Window manifest.
$context = FormalMeasurementWindowContext::production(dirname(__DIR__));
$result = $context->declaration()->declare();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['accepted'] ? 0 : 1);
