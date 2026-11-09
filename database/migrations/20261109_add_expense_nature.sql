-- Optional manual classification for expense analytics.
-- Existing transactions remain unclassified (NULL); income behavior is unchanged.
-- Run once against the existing database, for example:
-- mysql -u root smart_wallet < database/migrations/20261109_add_expense_nature.sql
-- The column check makes reruns safe in both MySQL and MariaDB.
USE smart_wallet;

SET @expense_nature_migration = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE transactions ADD COLUMN expense_nature ENUM(''essential'', ''non_essential'') NULL DEFAULT NULL AFTER transaction_type',
        'SELECT 1'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'expense_nature'
);
PREPARE expense_nature_statement FROM @expense_nature_migration;
EXECUTE expense_nature_statement;
DEALLOCATE PREPARE expense_nature_statement;
