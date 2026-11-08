-- Initial schema for the smart wallet application.
-- Safe to run from the MySQL command line without selecting a database first.

CREATE DATABASE IF NOT EXISTS smart_wallet
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE smart_wallet;
SET default_storage_engine = InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
);

CREATE TABLE app_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    session_id VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    payload MEDIUMTEXT NOT NULL,
    last_activity TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_sessions_session_id (session_id),
    KEY idx_app_sessions_user_id (user_id),
    KEY idx_app_sessions_last_activity (last_activity),
    CONSTRAINT fk_app_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
);

CREATE TABLE accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    account_type ENUM('cash', 'checking', 'savings', 'credit', 'investment', 'other') NOT NULL,
    opening_balance DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    currency_code CHAR(3) NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_accounts_user_id (user_id),
    CONSTRAINT fk_accounts_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
);

CREATE TABLE categories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    name VARCHAR(80) NOT NULL,
    category_type ENUM('income', 'expense') NOT NULL,
    icon VARCHAR(64) NULL,
    color CHAR(7) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_categories_user_type (user_id, category_type),
    CONSTRAINT fk_categories_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
);

CREATE TABLE savings_goals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    target_amount DECIMAL(14, 2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    target_date DATE NULL,
    status ENUM('active', 'completed', 'paused', 'cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_savings_goals_user_status (user_id, status),
    CONSTRAINT fk_savings_goals_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
);

CREATE TABLE transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    to_account_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    transaction_type ENUM('income', 'expense', 'transfer') NOT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    description VARCHAR(255) NULL,
    transaction_date DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transactions_user_date (user_id, transaction_date),
    KEY idx_transactions_account_date (account_id, transaction_date),
    KEY idx_transactions_to_account_id (to_account_id),
    KEY idx_transactions_category_date (category_id, transaction_date),
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_transactions_account
        FOREIGN KEY (account_id) REFERENCES accounts (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_to_account
        FOREIGN KEY (to_account_id) REFERENCES accounts (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL
);

CREATE TABLE savings_contributions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    savings_goal_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    amount DECIMAL(14, 2) NOT NULL,
    contribution_date DATETIME NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_savings_contributions_goal_date (savings_goal_id, contribution_date),
    KEY idx_savings_contributions_account_id (account_id),
    CONSTRAINT fk_savings_contributions_goal
        FOREIGN KEY (savings_goal_id) REFERENCES savings_goals (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_savings_contributions_account
        FOREIGN KEY (account_id) REFERENCES accounts (id)
        ON DELETE SET NULL
);

CREATE TABLE budgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    period ENUM('weekly', 'monthly', 'yearly', 'custom') NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_budgets_user_dates (user_id, start_date, end_date),
    KEY idx_budgets_category_id (category_id),
    CONSTRAINT fk_budgets_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_budgets_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL
);

CREATE TABLE transaction_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    action ENUM('created', 'updated', 'deleted') NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transaction_audit_logs_transaction (transaction_id, created_at),
    KEY idx_transaction_audit_logs_user (user_id, created_at),
    CONSTRAINT fk_transaction_audit_logs_transaction
        FOREIGN KEY (transaction_id) REFERENCES transactions (id)
        ON DELETE SET NULL,
    CONSTRAINT fk_transaction_audit_logs_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE SET NULL
);
