<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== '--run') {
    fwrite(STDERR, "Usage: php tests/category_migration_test.php --run\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
$pdo = getDatabaseConnection();
$originalDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$testDatabase = 'smart_wallet_migration_test_' . bin2hex(random_bytes(6));
$assertions = 0;

function migration_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function migration_run_sql(PDO $pdo, string $path): void
{
    $sql = str_replace("\r\n", "\n", (string) file_get_contents($path));
    // Keep the script in the disposable database selected by this test.
    $sql = (string) preg_replace('/^USE smart_wallet;\s*$/m', '', $sql);
    $sql = (string) preg_replace('/CREATE DATABASE IF NOT EXISTS smart_wallet\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci;/', '', $sql);
    foreach (explode(";\n", $sql) as $statement) {
        if (trim($statement) !== '') {
            $result = $pdo->query($statement);
            $result->closeCursor();
        }
    }
}

try {
    $pdo->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $testDatabase . '`');
    $pdo->exec('CREATE TABLE users (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE wallets (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED,
        current_balance DECIMAL(14,2)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE categories (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NULL,
        name VARCHAR(80)) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE transactions (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED,
        wallet_id BIGINT UNSIGNED, category BIGINT UNSIGNED NULL,
        transaction_type ENUM('income','expense') NOT NULL, amount DECIMAL(14,2) NOT NULL,
        transaction_date DATETIME NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_transactions_category_date (category,transaction_date),
        CONSTRAINT fk_transactions_category FOREIGN KEY (category) REFERENCES categories(id)) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE expense_limits (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED,
        category_id BIGINT UNSIGNED NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_expense_limits_category_id(category_id),
        CONSTRAINT fk_expense_limits_category FOREIGN KEY(category_id) REFERENCES categories(id)) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO users VALUES (1),(2)');
    $pdo->exec('INSERT INTO wallets VALUES (1,1,700.25)');
    $pdo->exec("INSERT INTO categories VALUES (1,1,'Food & dining'),(2,NULL,'Salary'),(3,2,'Private category'),(4,1,'食費')");
    $pdo->exec("INSERT INTO transactions VALUES
        (1,1,1,1,'expense',20.10,'2026-01-01 12:00:00','2026-01-01 13:00:00'),
        (2,1,1,2,'income',100.20,'2026-01-01 12:00:00','2026-01-01 13:00:00'),
        (3,1,1,NULL,'expense',0.01,'2026-01-01 12:00:00','2026-01-01 13:00:00'),
        (4,1,1,3,'expense',5.00,'2026-01-01 12:00:00','2026-01-01 13:00:00'),
        (5,1,1,4,'expense',4.00,'2026-01-01 12:00:00','2026-01-01 13:00:00')");
    $pdo->exec("INSERT INTO expense_limits VALUES (1,1,1,'2026-01-01 13:00:00'),(2,1,NULL,'2026-01-01 13:00:00')");
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_add_expense_nature.sql');
    $pdo->exec("UPDATE transactions SET expense_nature='essential', updated_at=updated_at WHERE id=1");
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_inline_categories.sql');
    $transactions = $pdo->query('SELECT id,category,amount,expense_nature,updated_at FROM transactions ORDER BY id')->fetchAll();
    migration_assert(count($transactions) === 5, 'All transaction records survive.');
    migration_assert(array_column($transactions, 'category') === ['Food & dining','Salary',null,null,'食費'],
        'Names, Unicode and missing categories migrate without exposing another user category.');
    migration_assert(array_column($transactions, 'amount') === ['-20.10','100.20','-0.01','-5.00','-4.00'],
        'Expenses become negative and income remains positive with exact cents.');
    migration_assert($transactions[0]['expense_nature'] === 'essential', 'Expense classification survives.');
    migration_assert(count(array_unique(array_column($transactions, 'updated_at'))) === 1
        && $transactions[0]['updated_at'] === '2026-01-01 13:00:00', 'Transaction timestamps survive.');
    $limits = $pdo->query('SELECT id,category,updated_at FROM expense_limits ORDER BY id')->fetchAll();
    migration_assert(array_column($limits, 'category') === ['Food & dining',null], 'Expense limit names and all-category scope survive.');
    migration_assert($limits[0]['updated_at'] === '2026-01-01 13:00:00', 'Limit timestamps survive.');
    migration_assert($pdo->query('SELECT current_balance FROM wallets WHERE id=1')->fetchColumn() === '700.25', 'Wallet balance does not change.');
    migration_assert($pdo->query("SHOW TABLES LIKE 'categories'")->fetch() === false, 'Categories table is removed.');
    migration_assert($pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch() === false, 'Transaction type is removed.');
    migration_assert($pdo->query("SHOW COLUMNS FROM expense_limits LIKE 'category_id'")->fetch() === false, 'Limit category reference is removed.');
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_inline_categories.sql');
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_add_expense_nature.sql');
    migration_assert($pdo->query('SELECT id,category,amount,expense_nature,updated_at FROM transactions ORDER BY id')->fetchAll() === $transactions,
        'Rerunning both migrations does not change migrated transactions.');
    migration_assert($pdo->query('SELECT id,category,updated_at FROM expense_limits ORDER BY id')->fetchAll() === $limits,
        'Rerunning preserves expense limits.');

    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_add_transaction_type.sql');
    migration_assert($pdo->query('SELECT transaction_type FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        === ['expense','income','expense','expense','expense'], 'Existing signed amounts generate the correct direction.');
    $pdo->exec('UPDATE transactions SET amount=-7.25, updated_at=updated_at WHERE id=2');
    migration_assert($pdo->query('SELECT transaction_type FROM transactions WHERE id=2')->fetchColumn() === 'expense',
        'Changing the sign automatically changes the generated direction.');
    $pdo->exec('UPDATE transactions SET amount=0, updated_at=updated_at WHERE id=2');
    migration_assert($pdo->query('SELECT transaction_type FROM transactions WHERE id=2')->fetchColumn() === null,
        'A zero amount has no misleading income or expense direction.');
    $pdo->exec('UPDATE transactions SET amount=100.20, updated_at=updated_at WHERE id=2');
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_add_transaction_type.sql');
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_inline_categories.sql');
    migration_assert($pdo->query('SELECT transaction_type FROM transactions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        === ['expense','income','expense','expense','expense'], 'Rerunning migrations preserves the generated column.');
    migration_assert($pdo->query('SELECT id,category,amount,expense_nature,updated_at FROM transactions ORDER BY id')->fetchAll() === $transactions,
        'Adding and rerunning the generated type migration preserves existing values and timestamps.');

    // Recreate only the disposable database, then verify a new installation too.
    $pdo->exec('DROP DATABASE `' . $testDatabase . '`');
    $pdo->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $testDatabase . '`');
    migration_run_sql($pdo, __DIR__ . '/../database/schema.sql');
    migration_assert(count($pdo->query('SHOW TABLES')->fetchAll()) === 6, 'Fresh schema contains six application tables.');
    migration_assert($pdo->query("SHOW COLUMNS FROM transactions LIKE 'category'")->fetch()['Type'] === 'varchar(80)', 'Fresh transactions store category names.');
    migration_assert($pdo->query("SHOW COLUMNS FROM expense_limits LIKE 'category'")->fetch()['Type'] === 'varchar(80)', 'Fresh limits store category names.');
    migration_assert(str_contains($pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch()['Extra'], 'GENERATED'),
        'Fresh schema includes the generated transaction type.');
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_add_expense_nature.sql');
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_inline_categories.sql');
    migration_run_sql($pdo, __DIR__ . '/../database/migrations/20261109_add_transaction_type.sql');
    migration_assert(count($pdo->query('SHOW TABLES')->fetchAll()) === 6, 'Migrations are also safe on the fresh schema.');
    migration_assert(str_contains($pdo->query("SHOW COLUMNS FROM transactions LIKE 'transaction_type'")->fetch()['Extra'], 'GENERATED'),
        'Fresh-schema migration reruns retain the generated column.');
} finally {
    $pdo->exec('USE `' . str_replace('`', '``', $originalDatabase) . '`');
    if (preg_match('/\Asmart_wallet_migration_test_[a-f0-9]{12}\z/', $testDatabase) !== 1) {
        throw new RuntimeException('Unexpected disposable database name.');
    }
    $pdo->exec('DROP DATABASE IF EXISTS `' . $testDatabase . '`');
}

echo 'Category migration tests passed (' . $assertions . " assertions); disposable database removed.\n";
