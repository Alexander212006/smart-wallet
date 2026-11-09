-- Store category names directly and replace the old writable type with signed amounts.
-- Run after 20261109_add_expense_nature.sql, with application writes stopped.
-- Category names and transaction IDs are preserved; wallet balances do not change.
-- Staged columns and schema checks make this migration safe to resume or rerun.
USE smart_wallet;

SET @inline_transaction_categories = EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND COLUMN_NAME = 'category' AND DATA_TYPE <> 'varchar'
);
SET @inline_limit_categories = EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expense_limits' AND COLUMN_NAME = 'category_id'
);

SET @inline_sql = IF(@inline_transaction_categories AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'category_name'
), 'ALTER TABLE transactions ADD COLUMN category_name VARCHAR(80) NULL', 'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(@inline_transaction_categories,
    'UPDATE transactions t LEFT JOIN categories c ON c.id = t.category AND (c.user_id = t.user_id OR c.user_id IS NULL) SET t.category_name = c.name, t.updated_at = t.updated_at',
    'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(@inline_limit_categories AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expense_limits' AND COLUMN_NAME = 'category_name'
), 'ALTER TABLE expense_limits ADD COLUMN category_name VARCHAR(80) NULL', 'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(@inline_limit_categories,
    'UPDATE expense_limits b LEFT JOIN categories c ON c.id = b.category_id AND (c.user_id = b.user_id OR c.user_id IS NULL) SET b.category_name = c.name, b.updated_at = b.updated_at',
    'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions'
      AND CONSTRAINT_NAME = 'fk_transactions_category' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
), 'ALTER TABLE transactions DROP FOREIGN KEY fk_transactions_category', 'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'expense_limits'
      AND CONSTRAINT_NAME = 'fk_expense_limits_category' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
), 'ALTER TABLE expense_limits DROP FOREIGN KEY fk_expense_limits_category', 'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(@inline_transaction_categories,
    'ALTER TABLE transactions DROP INDEX idx_transactions_category_date, DROP COLUMN category, CHANGE COLUMN category_name category VARCHAR(80) NULL, ADD INDEX idx_transactions_category_date (category, transaction_date)',
    'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(@inline_limit_categories,
    'ALTER TABLE expense_limits DROP INDEX idx_expense_limits_category_id, DROP COLUMN category_id, CHANGE COLUMN category_name category VARCHAR(80) NULL, ADD INDEX idx_expense_limits_category (category)',
    'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'transaction_type'
      AND EXTRA NOT LIKE '%GENERATED%'
), 'UPDATE transactions SET amount = CASE WHEN transaction_type = ''expense'' THEN -ABS(amount) ELSE ABS(amount) END, updated_at = updated_at', 'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

SET @inline_sql = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'transaction_type'
      AND EXTRA NOT LIKE '%GENERATED%'
), 'ALTER TABLE transactions DROP COLUMN transaction_type, MODIFY COLUMN amount DECIMAL(14, 2) NOT NULL COMMENT ''Positive income; negative expense''', 'DO 0');
PREPARE inline_statement FROM @inline_sql;
EXECUTE inline_statement;
DEALLOCATE PREPARE inline_statement;

DROP TABLE IF EXISTS categories;
