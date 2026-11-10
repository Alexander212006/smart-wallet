<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../database/sql_runner.php';

$assertions = 0;
function database_static_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function database_static_object_names(string $sql, string $verb, string $type): array
{
    $pattern = '/^\s*' . preg_quote($verb, '/') . '\s+' . preg_quote($type, '/')
        . '(?:\s+IF\s+EXISTS)?\s+`?([a-z0-9_]+)`?/mi';
    preg_match_all($pattern, $sql, $matches);
    return $matches[1];
}

$directory = __DIR__ . '/../database';
$expectedStatements = [
    'views.sql' => 5,
    'triggers.sql' => 17,
    'procedures.sql' => 37,
];
foreach ($expectedStatements as $file => $expected) {
    database_static_assert(
        count(wallet_parse_sql_file($directory . '/' . $file)) === $expected,
        $file . ' should parse into ' . $expected . ' complete statements.'
    );
}

$triggers = (string) file_get_contents($directory . '/triggers.sql');
$procedures = (string) file_get_contents($directory . '/procedures.sql');
$views = (string) file_get_contents($directory . '/views.sql');

database_static_assert(
    preg_match('/CREATE\s+OR\s+REPLACE\s+(?:TRIGGER|PROCEDURE)/i', $triggers . $procedures) === 0,
    'MySQL-incompatible CREATE OR REPLACE must not be used for triggers or procedures.'
);

$triggerDrops = database_static_object_names($triggers, 'DROP', 'TRIGGER');
$triggerCreates = database_static_object_names($triggers, 'CREATE', 'TRIGGER');
database_static_assert(count($triggerCreates) === 7, 'Seven triggers should be defined.');
database_static_assert($triggerDrops === $triggerCreates, 'Every trigger must be dropped immediately before it is recreated.');

$procedureDrops = database_static_object_names($procedures, 'DROP', 'PROCEDURE');
$procedureCreates = database_static_object_names($procedures, 'CREATE', 'PROCEDURE');
database_static_assert(count($procedureCreates) === 17, 'Seventeen procedures should be defined.');
database_static_assert($procedureDrops === $procedureCreates, 'Every procedure must be dropped immediately before it is recreated.');

database_static_assert(
    substr_count($procedures, '/*M!100400 OR @@SESSION.in_transaction<>0 */') === 12,
    'All twelve MariaDB transaction guards must use MariaDB-only executable comments.'
);

database_static_assert(
    substr_count($procedures, 'IF @@SESSION.autocommit=0') === 12
        && substr_count($procedures, '/*!80016 OR EXISTS') === 12,
    'All twelve MySQL transaction guards must use version comments ignored by MariaDB.'
);

database_static_assert(
    substr_count($procedures, 'performance_schema.events_transactions_current') === 12
        && substr_count($procedures, 'PS_CURRENT_THREAD_ID()') === 12,
    'Transaction guards should inspect the current MySQL Performance Schema thread.'
);
database_static_assert(
    preg_match_all('/CREATE\s+OR\s+REPLACE\s+SQL\s+SECURITY\s+INVOKER\s+VIEW/i', $views) === 5,
    'All five views should remain rerunnable invoker-security views.'
);

echo 'Database program static tests passed (' . $assertions . " assertions).\n";
