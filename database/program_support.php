<?php

declare(strict_types=1);

/** Check the selected server before replacing any of its database programs. */
function wallet_check_program_support(PDO $pdo): string
{
    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!is_string($database) || $database === '') {
        throw new RuntimeException('No target database is selected.');
    }
    if ($pdo->inTransaction() || (int) $pdo->query('SELECT @@SESSION.autocommit')->fetchColumn() !== 1) {
        throw new RuntimeException('Run the database program installer outside a transaction with autocommit enabled.');
    }

    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $mariaDB = stripos($version, 'MariaDB') !== false;
    // Some MariaDB installations advertise a 5.5.5 compatibility prefix.
    $versionNumber = $mariaDB ? preg_replace('/^5\.5\.5-/', '', $version) : $version;
    $minimum = $mariaDB ? '10.4.0' : '8.0.16';
    if (preg_match('/^(\d+\.\d+\.\d+)/', $versionNumber, $match) !== 1
        || version_compare($match[1], $minimum, '<')) {
        throw new RuntimeException('MySQL 8.0.16+ or MariaDB 10.4+ is required; connected server reports ' . $version . '.');
    }

    if ($mariaDB) {
        $pdo->query('SELECT @@SESSION.in_transaction')->closeCursor();
        return $version;
    }

    if ((int) $pdo->query('SELECT @@GLOBAL.performance_schema')->fetchColumn() !== 1) {
        throw new RuntimeException('MySQL Performance Schema must be enabled for transaction ownership checks.');
    }
    $consumers = $pdo->query(
        "SELECT NAME, ENABLED FROM performance_schema.setup_consumers "
        . "WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_transactions_current')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['global_instrumentation', 'thread_instrumentation', 'events_transactions_current'] as $consumer) {
        if (($consumers[$consumer] ?? '') !== 'YES') {
            throw new RuntimeException('Enable the MySQL Performance Schema ' . $consumer . ' consumer before installing.');
        }
    }
    if ($pdo->query("SELECT ENABLED FROM performance_schema.setup_instruments WHERE NAME='transaction'")->fetchColumn() !== 'YES') {
        throw new RuntimeException('Enable the MySQL Performance Schema transaction instrument before installing.');
    }

    // Probe the same connection/condition the routines use, including an empty explicit transaction.
    // Checking only consumer settings misses disabled per-thread instrumentation.
    $pdo->beginTransaction();
    try {
        $visible = (int) $pdo->query(
            "SELECT EXISTS(SELECT 1 FROM performance_schema.events_transactions_current "
            . "WHERE THREAD_ID=PS_CURRENT_THREAD_ID() AND STATE='ACTIVE')"
        )->fetchColumn();
        if ($visible !== 1) {
            throw new RuntimeException('The MySQL transaction guard cannot observe this connection. Enable transaction instrumentation for the application account.');
        }
    } finally {
        $pdo->rollBack();
    }

    return $version;
}
