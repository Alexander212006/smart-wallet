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

/** Verify requirements used by the MySQL-specific transaction ownership guard. */
function wallet_check_mysql_program_support(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || $database === '') {
        throw new RuntimeException('No target database is selected.');
    }

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    if (stripos($version, 'MariaDB') !== false
        || preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) !== 1
        || version_compare($match[1], '8.0.16', '<')) {
        throw new RuntimeException('MySQL 8.0.16 or newer is required; connected server reports ' . $version . '.');
    }

    if ((int) $pdo->query('SELECT @@GLOBAL.performance_schema')->fetchColumn() !== 1) {
        throw new RuntimeException('MySQL Performance Schema must be enabled for transaction ownership checks.');
    }
    $consumer = $pdo->query(
        "SELECT ENABLED FROM performance_schema.setup_consumers WHERE NAME='events_transactions_current'"
    )->fetchColumn();
    if ($consumer !== 'YES') {
        throw new RuntimeException('Enable the Performance Schema events_transactions_current consumer before installing.');
    }
    $pdo->query(
        "SELECT EXISTS(SELECT 1 FROM performance_schema.events_transactions_current "
        . "WHERE THREAD_ID=PS_CURRENT_THREAD_ID() AND STATE='ACTIVE')"
    )->closeCursor();

    return $version;
}

$files = ['views.sql', 'triggers.sql', 'procedures.sql'];
try {
    foreach ($files as $file) {
        wallet_parse_sql_file(__DIR__ . '/' . $file);
    }
    $pdo = getDatabaseConnection();
    $version = wallet_check_mysql_program_support($pdo);
    echo 'Preflight passed for MySQL ' . $version . '.' . PHP_EOL;

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
