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

CREATE TABLE wallets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    wallet_type ENUM('cash', 'checking', 'savings', 'credit', 'investment', 'other') NOT NULL,
    opening_balance DECIMAL(14, 2) NOT NULL DEFAULT 0.00,
    currency_code CHAR(3) NOT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wallets_user_id (user_id),
    CONSTRAINT fk_wallets_user
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
    wallet_id BIGINT UNSIGNED NOT NULL,
    category BIGINT UNSIGNED NULL,
    transaction_type ENUM('income', 'expense') NOT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    description VARCHAR(255) NULL,
    transaction_date DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_transactions_user_date (user_id, transaction_date),
    KEY idx_transactions_wallet_date (wallet_id, transaction_date),
    KEY idx_transactions_category_date (category, transaction_date),
    CONSTRAINT fk_transactions_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_transactions_wallet
        FOREIGN KEY (wallet_id) REFERENCES wallets (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_transactions_category
        FOREIGN KEY (category) REFERENCES categories (id)
        ON DELETE SET NULL
);

CREATE TABLE savings_contributions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    savings_goal_id BIGINT UNSIGNED NOT NULL,
    wallet_id BIGINT UNSIGNED NULL,
    amount DECIMAL(14, 2) NOT NULL,
    contribution_date DATETIME NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_savings_contributions_goal_date (savings_goal_id, contribution_date),
    KEY idx_savings_contributions_wallet_id (wallet_id),
    CONSTRAINT fk_savings_contributions_goal
        FOREIGN KEY (savings_goal_id) REFERENCES savings_goals (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_savings_contributions_wallet
        FOREIGN KEY (wallet_id) REFERENCES wallets (id)
        ON DELETE SET NULL
);

CREATE TABLE expense_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    amount DECIMAL(14, 2) NOT NULL,
    currency_code CHAR(3) NOT NULL,
    period ENUM('weekly', 'monthly', 'yearly') NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_expense_limits_user_dates (user_id, start_date, is_recurring),
    KEY idx_expense_limits_category_id (category_id),
    CONSTRAINT fk_expense_limits_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_expense_limits_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE SET NULL
);
