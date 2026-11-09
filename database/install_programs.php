<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php database/install_programs.php --apply\n");
    exit(2);
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/sql_runner.php';
$pdo = getDatabaseConnection();
foreach (['views.sql', 'triggers.sql', 'procedures.sql'] as $file) {
    wallet_execute_sql_file($pdo, __DIR__ . '/' . $file);
    echo 'Installed ' . $file . PHP_EOL;
}
echo "Database programs installed. Existing records and balances were not recalculated.\n";
