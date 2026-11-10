-- MySQL 8.0.16+ / MariaDB 10.4+ triggers. Do not also adjust balances in PHP.
-- Installing these triggers preserves all existing records and opening balances.
SET @wallet_previous_sql_mode=@@SESSION.sql_mode;
SET SESSION sql_mode=IF(FIND_IN_SET('STRICT_TRANS_TABLES',@@SESSION.sql_mode) OR FIND_IN_SET('STRICT_ALL_TABLES',@@SESSION.sql_mode),@@SESSION.sql_mode,CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_TRANS_TABLES'));
DELIMITER $$
DROP TRIGGER IF EXISTS trg_transactions_validate_insert$$
CREATE TRIGGER trg_transactions_validate_insert
BEFORE INSERT ON transactions FOR EACH ROW
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_owner=NULL;
    SELECT user_id INTO v_owner FROM wallets WHERE id=NEW.wallet_id FOR UPDATE;
    IF v_owner IS NULL OR v_owner<>NEW.user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a wallet belonging to this user.';
    END IF;
    IF NEW.amount=0 OR NEW.amount IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction amount must be nonzero.';
    END IF;
    IF NEW.amount>0 AND NEW.expense_nature IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Income cannot have an expense type.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_transactions_validate_update$$
CREATE TRIGGER trg_transactions_validate_update
BEFORE UPDATE ON transactions FOR EACH ROW
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_owner=NULL;
    IF NEW.user_id<>OLD.user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction ownership cannot change.';
    END IF;
    SELECT user_id INTO v_owner FROM wallets WHERE id=NEW.wallet_id FOR UPDATE;
    IF v_owner IS NULL OR v_owner<>NEW.user_id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a wallet belonging to this user.';
    END IF;
    IF NEW.amount=0 OR NEW.amount IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Transaction amount must be nonzero.';
    END IF;
    IF NEW.amount>0 AND NEW.expense_nature IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Income cannot have an expense type.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_transactions_balance_insert$$
CREATE TRIGGER trg_transactions_balance_insert
AFTER INSERT ON transactions FOR EACH ROW
BEGIN
    UPDATE wallets SET current_balance=current_balance+NEW.amount
    WHERE id=NEW.wallet_id AND user_id=NEW.user_id;
END$$

DROP TRIGGER IF EXISTS trg_transactions_balance_update$$
CREATE TRIGGER trg_transactions_balance_update
AFTER UPDATE ON transactions FOR EACH ROW
BEGIN
    IF OLD.wallet_id=NEW.wallet_id THEN
        IF OLD.amount<>NEW.amount THEN
            UPDATE wallets SET current_balance=current_balance+NEW.amount-OLD.amount
            WHERE id=NEW.wallet_id AND user_id=NEW.user_id;
        END IF;
    ELSE
        UPDATE wallets SET current_balance=current_balance-OLD.amount
        WHERE id=OLD.wallet_id AND user_id=OLD.user_id;
        UPDATE wallets SET current_balance=current_balance+NEW.amount
        WHERE id=NEW.wallet_id AND user_id=NEW.user_id;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_transactions_balance_delete$$
CREATE TRIGGER trg_transactions_balance_delete
AFTER DELETE ON transactions FOR EACH ROW
BEGIN
    UPDATE wallets SET current_balance=current_balance-OLD.amount
    WHERE id=OLD.wallet_id AND user_id=OLD.user_id;
END$$

DROP TRIGGER IF EXISTS trg_savings_contribution_validate$$
CREATE TRIGGER trg_savings_contribution_validate
BEFORE INSERT ON savings_contributions FOR EACH ROW
BEGIN
    DECLARE v_owner BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_currency CHAR(3);
    DECLARE v_status VARCHAR(12);
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_owner=NULL;
    SELECT user_id,currency_code,status INTO v_owner,v_currency,v_status
    FROM savings_goals WHERE id=NEW.savings_goal_id FOR UPDATE;
    IF v_owner IS NULL OR v_status<>'active' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That active savings goal could not be found.';
    END IF;
    IF NEW.amount IS NULL OR NEW.amount<=0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Contribution amount must be positive.';
    END IF;
    IF NEW.wallet_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM wallets WHERE id=NEW.wallet_id AND user_id=v_owner
        AND currency_code=v_currency AND is_archived=0
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a matching, active wallet for this savings goal.';
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_savings_goal_complete$$
CREATE TRIGGER trg_savings_goal_complete
AFTER INSERT ON savings_contributions FOR EACH ROW
BEGIN
    UPDATE savings_goals SET status='completed'
    WHERE id=NEW.savings_goal_id AND status='active'
      AND target_amount<=(SELECT COALESCE(SUM(amount),0) FROM savings_contributions WHERE savings_goal_id=NEW.savings_goal_id);
END$$
DELIMITER ;
SET SESSION sql_mode=@wallet_previous_sql_mode;
