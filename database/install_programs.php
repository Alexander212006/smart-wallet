<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$mode = $argv[1] ?? '';
if (!in_array($mode, ['--check', '--apply'], true)) {
    fwrite(STDERR, "Usage: php database/install_programs.php --check|--apply\n");
    exit(2);
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sql_runner.php';
require_once __DIR__ . '/program_support.php';

$files = ['views.sql', 'triggers.sql', 'procedures.sql'];
try {
    foreach ($files as $file) {
        wallet_parse_sql_file(__DIR__ . '/' . $file);
    }
    $pdo = getDatabaseConnection();
    $version = wallet_check_program_support($pdo);
    echo 'Preflight passed for ' . $version . '.' . PHP_EOL;

    if ($mode === '--check') {
        echo "No database programs were changed.\n";
        exit(0);
    }

    foreach ($files as $file) {
        wallet_execute_sql_file($pdo, __DIR__ . '/' . $file);
        echo 'Installed ' . $file . PHP_EOL;
    }
    echo "Database programs installed. Existing records and balances were not recalculated.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Database program installation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
