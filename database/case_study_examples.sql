-- Select smart_wallet first. Import schema and install database programs before this demo.
-- Temporary DDL happens before the transaction because ALTER TABLE can commit a transaction.
CREATE TEMPORARY TABLE wallet_case_study_example (id INT PRIMARY KEY, topic VARCHAR(80));
ALTER TABLE wallet_case_study_example ADD COLUMN explanation VARCHAR(255) NULL;
INSERT INTO wallet_case_study_example(id,topic,explanation)
VALUES(1,'Database automation','Procedures execute operations; triggers maintain balances.');
SELECT * FROM wallet_case_study_example;

-- All application-table demo records below are rolled back at the end.
START TRANSACTION;
INSERT INTO users(name,email,password_hash)
VALUES('Case study demo',CONCAT('case-study-',UUID(),'@example.test'),'demo-only-not-a-login-password');
SET @demo_user=LAST_INSERT_ID();
INSERT INTO wallets(user_id,name,wallet_type,current_balance,currency_code)
VALUES(@demo_user,'Demo cash','cash',1000.00,'PHP');
SET @demo_wallet=LAST_INSERT_ID();
INSERT INTO transactions(user_id,wallet_id,category,amount,description,transaction_date,expense_nature)
VALUES(@demo_user,@demo_wallet,'Food & dining',-100.00,'Lunch',NOW(),'essential');
SET @demo_transaction=LAST_INSERT_ID();

-- JOIN: each transaction is combined with its wallet, currency and user.
SELECT u.name AS user_name,w.name AS wallet_name,t.category,t.amount,t.transaction_type
FROM users u INNER JOIN wallets w ON w.user_id=u.id
INNER JOIN transactions t ON t.wallet_id=w.id AND t.user_id=u.id
WHERE u.id=@demo_user AND t.amount<0 AND t.category LIKE 'Food%';

-- Aggregate functions and operators: SUM, COUNT, AVG, MIN, MAX, CASE, AND, BETWEEN.
SELECT COUNT(*) AS expense_count,SUM(-amount) AS spent,AVG(-amount) AS average_expense,
       MIN(-amount) AS smallest_expense,MAX(-amount) AS largest_expense,
       SUM(CASE WHEN expense_nature='essential' THEN -amount ELSE 0 END) AS essential_spending
FROM transactions
WHERE user_id=@demo_user AND amount<0 AND DATE(transaction_date) BETWEEN CURDATE() AND CURDATE();

-- The INSERT trigger changed the balance from 1,000 to 900.
SELECT name,current_balance FROM wallets WHERE id=@demo_wallet;
UPDATE transactions SET amount=-125.00 WHERE id=@demo_transaction AND user_id=@demo_user;
-- The UPDATE trigger applies only the extra 25, leaving a balance of 875.
SELECT name,current_balance FROM wallets WHERE id=@demo_wallet;

-- Summarized views and reporting procedures use the same data as the app.
SELECT * FROM vw_monthly_cash_flow WHERE user_id=@demo_user;
SELECT * FROM vw_monthly_category_spending WHERE user_id=@demo_user;
CALL sp_get_monthly_summary(@demo_user,'PHP',CURDATE());
CALL sp_get_cash_flow(@demo_user,'PHP',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 1 DAY));

DELETE FROM transactions WHERE id=@demo_transaction AND user_id=@demo_user;
-- The DELETE trigger reverses the expense and restores 1,000.
SELECT name,current_balance FROM wallets WHERE id=@demo_wallet;
ROLLBACK;
DROP TEMPORARY TABLE wallet_case_study_example;
