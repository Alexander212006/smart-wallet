-- Show the direction in database views without requiring a second writable value.
-- Run after 20261109_inline_categories.sql. Existing records are classified automatically.
-- Reruns preserve the generated column and all transaction data.
USE smart_wallet;

SET @transaction_type_migration = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'transaction_type'
), 'DO 0',
    'ALTER TABLE transactions ADD COLUMN transaction_type VARCHAR(7) GENERATED ALWAYS AS (CASE WHEN amount < 0 THEN ''expense'' WHEN amount > 0 THEN ''income'' ELSE NULL END) STORED AFTER amount'
);
PREPARE transaction_type_statement FROM @transaction_type_migration;
EXECUTE transaction_type_statement;
DEALLOCATE PREPARE transaction_type_statement;
