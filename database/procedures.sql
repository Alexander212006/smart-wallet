-- Smart Wallet stored procedures for MariaDB 10.4+. Select smart_wallet before importing.
-- Mutating procedures own their transactions. Triggers maintain wallet balances.
SET @wallet_previous_sql_mode=@@SESSION.sql_mode;
SET SESSION sql_mode=IF(FIND_IN_SET('STRICT_TRANS_TABLES',@@SESSION.sql_mode) OR FIND_IN_SET('STRICT_ALL_TABLES',@@SESSION.sql_mode),@@SESSION.sql_mode,CONCAT_WS(',',@@SESSION.sql_mode,'STRICT_TRANS_TABLES'));
DELIMITER $$

CREATE OR REPLACE PROCEDURE sp_create_wallet(IN p_user_id BIGINT UNSIGNED,IN p_name VARCHAR(120),IN p_wallet_type VARCHAR(20),IN p_balance DECIMAL(14,2),IN p_currency CHAR(3))
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_name IS NULL OR CHAR_LENGTH(TRIM(p_name)) NOT BETWEEN 1 AND 120 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a valid name.'; END IF;
        IF p_wallet_type IS NULL OR p_wallet_type NOT IN ('cash','checking','savings','credit','investment','other') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid wallet type.'; END IF;
        IF p_currency IS NULL OR p_currency NOT IN ('PHP','USD','EUR','GBP','JPY','AUD','CAD','SGD') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a supported currency.'; END IF;
        IF p_balance IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a valid starting balance.'; END IF;
        IF NOT EXISTS(SELECT 1 FROM users WHERE id=p_user_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='User could not be found.'; END IF;
        INSERT INTO wallets(user_id,name,wallet_type,current_balance,currency_code) VALUES(p_user_id,TRIM(p_name),p_wallet_type,p_balance,p_currency);
        SET v_id=LAST_INSERT_ID();
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_update_wallet(IN p_user_id BIGINT UNSIGNED,IN p_wallet_id BIGINT UNSIGNED,IN p_name VARCHAR(120),IN p_wallet_type VARCHAR(20))
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_name IS NULL OR CHAR_LENGTH(TRIM(p_name)) NOT BETWEEN 1 AND 120 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a valid name.'; END IF;
        IF p_wallet_type IS NULL OR p_wallet_type NOT IN ('cash','checking','savings','credit','investment','other') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid wallet type.'; END IF;
        SELECT id INTO v_id FROM wallets WHERE id=p_wallet_id AND user_id=p_user_id FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That wallet could not be found.'; END IF;
        UPDATE wallets SET name=TRIM(p_name),wallet_type=p_wallet_type WHERE id=v_id AND user_id=p_user_id;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_set_wallet_archived(IN p_user_id BIGINT UNSIGNED,IN p_wallet_id BIGINT UNSIGNED,IN p_archived TINYINT)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_archived IS NULL OR p_archived NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid wallet state.'; END IF;
        SELECT id INTO v_id FROM wallets WHERE id=p_wallet_id AND user_id=p_user_id FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That wallet could not be found.'; END IF;
        UPDATE wallets SET is_archived=p_archived WHERE id=v_id AND user_id=p_user_id;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_save_transaction(IN p_user_id BIGINT UNSIGNED,IN p_transaction_id BIGINT UNSIGNED,IN p_wallet_id BIGINT UNSIGNED,
IN p_category VARCHAR(80),IN p_amount DECIMAL(14,2),IN p_description VARCHAR(255),IN p_date DATETIME,IN p_expense_nature VARCHAR(20))
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_wallet BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_old_amount DECIMAL(14,2);
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_amount IS NULL OR p_amount=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a nonzero transaction amount.'; END IF;
        IF p_category IS NULL OR CHAR_LENGTH(TRIM(p_category)) NOT BETWEEN 1 AND 80 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid category.'; END IF;
        IF p_date IS NULL OR p_date<'1000-01-01' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid transaction date.'; END IF;
        IF p_expense_nature IS NOT NULL AND (p_amount>0 OR p_expense_nature NOT IN ('essential','non_essential')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid expense type.'; END IF;
        IF p_transaction_id IS NOT NULL THEN
            SELECT id,amount INTO v_id,v_old_amount FROM transactions WHERE id=p_transaction_id AND user_id=p_user_id FOR UPDATE;
            IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That transaction could not be found.'; END IF;
            IF SIGN(v_old_amount)<>SIGN(p_amount) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cannot change a transaction between income and expense.'; END IF;
        END IF;
        SELECT id INTO v_wallet FROM wallets WHERE id=p_wallet_id AND user_id=p_user_id AND is_archived=0 FOR UPDATE;
        IF v_missing OR v_wallet IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose an active wallet belonging to this user.'; END IF;
        IF p_transaction_id IS NULL THEN
            INSERT INTO transactions(user_id,wallet_id,category,amount,description,transaction_date,expense_nature)
            VALUES(p_user_id,p_wallet_id,TRIM(p_category),p_amount,NULLIF(p_description,''),p_date,p_expense_nature);
            SET v_id=LAST_INSERT_ID();
        ELSE
            UPDATE transactions SET wallet_id=p_wallet_id,category=TRIM(p_category),amount=p_amount,
                description=NULLIF(p_description,''),transaction_date=p_date,expense_nature=p_expense_nature
            WHERE id=v_id AND user_id=p_user_id;
        END IF;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_delete_transaction(IN p_user_id BIGINT UNSIGNED,IN p_transaction_id BIGINT UNSIGNED,IN p_expected_type VARCHAR(7))
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_expected_type IS NULL OR p_expected_type NOT IN ('income','expense') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid transaction direction.'; END IF;
        SELECT id INTO v_id FROM transactions WHERE id=p_transaction_id AND user_id=p_user_id AND transaction_type=p_expected_type FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That transaction could not be found.'; END IF;
        DELETE FROM transactions WHERE id=v_id AND user_id=p_user_id;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_save_savings_goal(IN p_user_id BIGINT UNSIGNED,IN p_goal_id BIGINT UNSIGNED,IN p_name VARCHAR(120),IN p_description TEXT,
IN p_target DECIMAL(14,2),IN p_currency CHAR(3),IN p_target_date DATE)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_currency CHAR(3);
    DECLARE v_contribution_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_name IS NULL OR CHAR_LENGTH(TRIM(p_name)) NOT BETWEEN 1 AND 120 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a valid name.'; END IF;
        IF NOT EXISTS(SELECT 1 FROM wallets WHERE user_id=p_user_id AND currency_code=p_currency) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a currency used by your wallets.'; END IF;
        IF p_target IS NULL OR p_target<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a positive savings target.'; END IF;
        IF p_goal_id IS NULL AND p_target_date IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a target date for the new goal.'; END IF;
        IF p_goal_id IS NULL THEN
            INSERT INTO savings_goals(user_id,name,description,target_amount,currency_code,target_date)
            VALUES(p_user_id,TRIM(p_name),NULLIF(p_description,''),p_target,p_currency,p_target_date);
            SET v_id=LAST_INSERT_ID();
        ELSE
            SELECT id,currency_code INTO v_id,v_currency FROM savings_goals WHERE id=p_goal_id AND user_id=p_user_id FOR UPDATE;
            IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That savings goal could not be found.'; END IF;
            SELECT COUNT(*) INTO v_contribution_count FROM savings_contributions WHERE savings_goal_id=v_id FOR UPDATE;
            IF v_currency<>p_currency AND v_contribution_count>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A goal with contributions cannot change currency.'; END IF;
            UPDATE savings_goals SET name=TRIM(p_name),description=NULLIF(p_description,''),target_amount=p_target,
                currency_code=p_currency,target_date=p_target_date WHERE id=v_id AND user_id=p_user_id;
        END IF;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_set_savings_goal_status(IN p_user_id BIGINT UNSIGNED,IN p_goal_id BIGINT UNSIGNED,IN p_status VARCHAR(12))
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_status IS NULL OR p_status NOT IN ('active','paused','completed','cancelled') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid goal status.'; END IF;
        SELECT id INTO v_id FROM savings_goals WHERE id=p_goal_id AND user_id=p_user_id FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That savings goal could not be found.'; END IF;
        UPDATE savings_goals SET status=p_status WHERE id=v_id AND user_id=p_user_id;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_delete_savings_goal(IN p_user_id BIGINT UNSIGNED,IN p_goal_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        SELECT id INTO v_id FROM savings_goals WHERE id=p_goal_id AND user_id=p_user_id FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That savings goal could not be found.'; END IF;
        DELETE FROM savings_goals WHERE id=v_id AND user_id=p_user_id;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_contribute_savings(IN p_user_id BIGINT UNSIGNED,IN p_goal_id BIGINT UNSIGNED,IN p_wallet_id BIGINT UNSIGNED,
IN p_amount DECIMAL(14,2),IN p_date DATETIME,IN p_note VARCHAR(255))
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_wallet BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_currency CHAR(3);
    DECLARE v_contribution BIGINT UNSIGNED;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_amount IS NULL OR p_amount<=0 OR p_date IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a valid positive contribution and date.'; END IF;
        SELECT id,currency_code INTO v_id,v_currency FROM savings_goals WHERE id=p_goal_id AND user_id=p_user_id AND status='active' FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That active savings goal could not be found.'; END IF;
        IF p_wallet_id IS NOT NULL THEN
            SELECT id INTO v_wallet FROM wallets WHERE id=p_wallet_id AND user_id=p_user_id AND currency_code=v_currency AND is_archived=0 FOR UPDATE;
            IF v_missing OR v_wallet IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a matching, active wallet for this savings goal.'; END IF;
        END IF;
        INSERT INTO savings_contributions(savings_goal_id,wallet_id,amount,contribution_date,note)
        VALUES(v_id,p_wallet_id,p_amount,p_date,NULLIF(p_note,''));
        SET v_contribution=LAST_INSERT_ID();
        COMMIT;
    END;
    SELECT v_contribution AS id,status FROM savings_goals WHERE id=v_id AND user_id=p_user_id;
END$$

CREATE OR REPLACE PROCEDURE sp_save_expense_limit(IN p_user_id BIGINT UNSIGNED,IN p_limit_id BIGINT UNSIGNED,IN p_name VARCHAR(120),IN p_category VARCHAR(80),
IN p_amount DECIMAL(14,2),IN p_currency CHAR(3),IN p_period VARCHAR(8),IN p_start DATE,IN p_end DATE,IN p_recurring TINYINT)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE v_min_end DATE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_name IS NULL OR CHAR_LENGTH(TRIM(p_name)) NOT BETWEEN 1 AND 120 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a valid name.'; END IF;
        IF NOT EXISTS(SELECT 1 FROM wallets WHERE user_id=p_user_id AND currency_code=p_currency) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a currency used by your wallets.'; END IF;
        IF p_amount IS NULL OR p_amount<=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Enter a positive expense limit.'; END IF;
        IF p_category IS NOT NULL AND CHAR_LENGTH(TRIM(p_category)) NOT BETWEEN 1 AND 80 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid limit category.'; END IF;
        IF p_period IS NULL OR p_period NOT IN ('weekly','monthly','yearly') OR p_start IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid period and start date.'; END IF;
        IF p_recurring IS NULL OR p_recurring NOT IN (0,1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid recurrence setting.'; END IF;
        SET v_min_end=DATE_SUB(CASE p_period WHEN 'weekly' THEN DATE_ADD(p_start,INTERVAL 7 DAY)
            WHEN 'monthly' THEN DATE_ADD(p_start,INTERVAL 1 MONTH) ELSE DATE_ADD(p_start,INTERVAL 1 YEAR) END,INTERVAL 1 DAY);
        IF p_end IS NOT NULL AND (p_end<p_start OR (p_recurring=0 AND p_end<v_min_end)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='An end date must include at least one full selected period.'; END IF;
        IF p_limit_id IS NULL THEN
            INSERT INTO expense_limits(user_id,name,category,amount,currency_code,period,start_date,end_date,is_recurring)
            VALUES(p_user_id,TRIM(p_name),TRIM(p_category),p_amount,p_currency,p_period,p_start,p_end,p_recurring);
            SET v_id=LAST_INSERT_ID();
        ELSE
            SELECT id INTO v_id FROM expense_limits WHERE id=p_limit_id AND user_id=p_user_id FOR UPDATE;
            IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That expense limit could not be found.'; END IF;
            UPDATE expense_limits SET name=TRIM(p_name),category=TRIM(p_category),amount=p_amount,currency_code=p_currency,
                period=p_period,start_date=p_start,end_date=p_end,is_recurring=p_recurring WHERE id=v_id AND user_id=p_user_id;
        END IF;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_set_expense_limit_recurring(IN p_user_id BIGINT UNSIGNED,IN p_limit_id BIGINT UNSIGNED,IN p_resume TINYINT,IN p_today DATE)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        IF p_resume IS NULL OR p_resume NOT IN (0,1) OR p_today IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid recurrence action.'; END IF;
        SELECT id INTO v_id FROM expense_limits WHERE id=p_limit_id AND user_id=p_user_id AND is_recurring=1 FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That recurring expense limit could not be found.'; END IF;
        UPDATE expense_limits SET end_date=IF(p_resume=1,NULL,p_today) WHERE id=v_id AND user_id=p_user_id;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_delete_expense_limit(IN p_user_id BIGINT UNSIGNED,IN p_limit_id BIGINT UNSIGNED)
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DECLARE v_missing BOOLEAN DEFAULT FALSE;
    DECLARE v_id BIGINT UNSIGNED DEFAULT NULL;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_missing=TRUE;
    IF @@in_transaction<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Call this procedure outside an existing transaction.'; END IF;
    work: BEGIN
        DECLARE EXIT HANDLER FOR SQLEXCEPTION BEGIN ROLLBACK; RESIGNAL; END;
        START TRANSACTION;
        SELECT id INTO v_id FROM expense_limits WHERE id=p_limit_id AND user_id=p_user_id FOR UPDATE;
        IF v_missing OR v_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='That expense limit could not be found.'; END IF;
        DELETE FROM expense_limits WHERE id=v_id AND user_id=p_user_id;
        COMMIT;
    END;
    SELECT v_id AS id;
END$$

CREATE OR REPLACE PROCEDURE sp_get_expense_limits(IN p_user_id BIGINT UNSIGNED,IN p_today DATE)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN

    IF p_today IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Choose a valid reporting date.'; END IF;
    WITH offsets AS (
        SELECT b.*,GREATEST(0,CASE period WHEN 'weekly' THEN FLOOR(DATEDIFF(p_today,start_date)/7)
            WHEN 'monthly' THEN (YEAR(p_today)-YEAR(start_date))*12+MONTH(p_today)-MONTH(start_date)
            ELSE YEAR(p_today)-YEAR(start_date) END) AS raw_offset
        FROM expense_limits b WHERE user_id=p_user_id
    ), candidates AS (
        SELECT offsets.*,CASE period WHEN 'weekly' THEN DATE_ADD(start_date,INTERVAL ((raw_offset)*7) DAY)
        WHEN 'monthly' THEN DATE_ADD(start_date,INTERVAL (raw_offset) MONTH)
        ELSE DATE_ADD(start_date,INTERVAL (raw_offset) YEAR) END AS candidate_start FROM offsets
    ), periods AS (
        SELECT candidates.*,CASE WHEN is_recurring=0 THEN 0 WHEN candidate_start>p_today THEN GREATEST(0,raw_offset-1)
            ELSE raw_offset END AS period_offset FROM candidates
    ), boundaries AS (
        SELECT periods.*,CASE period WHEN 'weekly' THEN DATE_ADD(start_date,INTERVAL ((period_offset)*7) DAY)
        WHEN 'monthly' THEN DATE_ADD(start_date,INTERVAL (period_offset) MONTH)
        ELSE DATE_ADD(start_date,INTERVAL (period_offset) YEAR) END AS period_start,
            CASE period WHEN 'weekly' THEN DATE_ADD(start_date,INTERVAL ((period_offset+1)*7) DAY)
        WHEN 'monthly' THEN DATE_ADD(start_date,INTERVAL (period_offset+1) MONTH)
        ELSE DATE_ADD(start_date,INTERVAL (period_offset+1) YEAR) END AS period_end FROM periods
    ), ranges AS (
        SELECT boundaries.*,LEAST(period_end,COALESCE(DATE_ADD(end_date,INTERVAL 1 DAY),period_end)) AS effective_end FROM boundaries
    ), active_limits AS (
        SELECT ranges.*,(p_today>=period_start AND p_today<effective_end) AS range_active FROM ranges
    )
    SELECT id,user_id,name,category,COALESCE(category,'All categories') AS category_name,amount,currency_code,period,
        start_date,end_date,is_recurring,created_at,updated_at,range_active,
        IF(range_active,period_start,NULL) AS range_start,IF(range_active,effective_end,NULL) AS range_end,
        CASE WHEN range_active THEN COALESCE((
            SELECT SUM(-t.amount) FROM transactions t INNER JOIN wallets w ON w.id=t.wallet_id AND w.user_id=t.user_id
            WHERE t.user_id=p_user_id AND w.is_archived=0 AND w.currency_code=active_limits.currency_code
                AND t.amount<0 AND t.transaction_date>=period_start
                AND t.transaction_date<LEAST(effective_end,DATE_ADD(p_today,INTERVAL 1 DAY))
                AND (active_limits.category IS NULL OR t.category=active_limits.category)
        ),0) ELSE 0 END AS spent
    FROM active_limits ORDER BY start_date DESC,name,id;
END$$

CREATE OR REPLACE PROCEDURE sp_get_spending_rows(IN p_user_id BIGINT UNSIGNED,IN p_currency CHAR(3),IN p_start DATE,IN p_end DATE)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN

    SELECT transaction_date,-amount AS amount,category_name,expense_nature FROM vw_transaction_details
    WHERE user_id=p_user_id AND currency_code=p_currency AND is_archived=0 AND amount<0
        AND transaction_date>=p_start AND transaction_date<p_end ORDER BY transaction_date,id;
END$$

CREATE OR REPLACE PROCEDURE sp_get_monthly_summary(IN p_user_id BIGINT UNSIGNED,IN p_currency CHAR(3),IN p_month DATE)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN

    SELECT COALESCE((SELECT balance FROM vw_wallet_summary WHERE user_id=p_user_id AND currency_code=p_currency),0) AS balance,
        COALESCE((SELECT income FROM vw_monthly_cash_flow WHERE user_id=p_user_id AND currency_code=p_currency
            AND month_start=CAST(DATE_FORMAT(p_month,'%Y-%m-01') AS DATE)),0) AS income,
        COALESCE((SELECT expenses FROM vw_monthly_cash_flow WHERE user_id=p_user_id AND currency_code=p_currency
            AND month_start=CAST(DATE_FORMAT(p_month,'%Y-%m-01') AS DATE)),0) AS expenses;
END$$

CREATE OR REPLACE PROCEDURE sp_get_cash_flow(IN p_user_id BIGINT UNSIGNED,IN p_currency CHAR(3),IN p_start DATE,IN p_end DATE)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN

    SELECT DATE(transaction_date) AS activity_date,SUM(CASE WHEN amount>0 THEN amount ELSE 0 END) AS income,
        SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END) AS expenses FROM vw_transaction_details
    WHERE user_id=p_user_id AND currency_code=p_currency AND is_archived=0
        AND transaction_date>=p_start AND transaction_date<p_end GROUP BY DATE(transaction_date) ORDER BY activity_date;
END$$

CREATE OR REPLACE PROCEDURE sp_get_category_spending(IN p_user_id BIGINT UNSIGNED,IN p_currency CHAR(3),IN p_month DATE)
SQL SECURITY INVOKER
READS SQL DATA
BEGIN

    SELECT name,total,transaction_count,average_expense,largest_expense FROM vw_monthly_category_spending
    WHERE user_id=p_user_id AND currency_code=p_currency AND month_start=CAST(DATE_FORMAT(p_month,'%Y-%m-01') AS DATE)
    ORDER BY total DESC,name;
END$$

DELIMITER ;
SET SESSION sql_mode=@wallet_previous_sql_mode;
