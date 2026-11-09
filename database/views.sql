-- Select the smart_wallet database before importing. Views use the caller's privileges.
CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_transaction_details AS
SELECT t.id, t.user_id, t.wallet_id, t.category, t.expense_nature, t.amount,
       t.transaction_type, t.description, t.transaction_date, t.created_at, t.updated_at,
       w.name AS account_name, w.currency_code, w.is_archived,
       COALESCE(t.category, 'Uncategorized') AS category_name
FROM transactions t
INNER JOIN wallets w ON w.id=t.wallet_id AND w.user_id=t.user_id;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_wallet_summary AS
SELECT user_id, currency_code,
       SUM(CASE WHEN is_archived=0 THEN current_balance ELSE 0 END) AS balance,
       SUM(CASE WHEN is_archived=0 THEN 1 ELSE 0 END) AS active_wallet_count,
       SUM(CASE WHEN is_archived=1 THEN 1 ELSE 0 END) AS archived_wallet_count
FROM wallets GROUP BY user_id, currency_code;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_monthly_cash_flow AS
SELECT user_id, currency_code, CAST(DATE_FORMAT(transaction_date,'%Y-%m-01') AS DATE) AS month_start,
       SUM(CASE WHEN amount>0 THEN amount ELSE 0 END) AS income,
       SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END) AS expenses,
       SUM(amount) AS net_cash_flow, COUNT(*) AS transaction_count
FROM vw_transaction_details WHERE is_archived=0
GROUP BY user_id,currency_code,CAST(DATE_FORMAT(transaction_date,'%Y-%m-01') AS DATE);

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_monthly_category_spending AS
SELECT user_id,currency_code,CAST(DATE_FORMAT(transaction_date,'%Y-%m-01') AS DATE) AS month_start,
       category_name AS name,COUNT(*) AS transaction_count,SUM(-amount) AS total,
       AVG(-amount) AS average_expense,MAX(-amount) AS largest_expense
FROM vw_transaction_details WHERE amount<0 AND is_archived=0
GROUP BY user_id,currency_code,CAST(DATE_FORMAT(transaction_date,'%Y-%m-01') AS DATE),category_name;

CREATE OR REPLACE SQL SECURITY INVOKER VIEW vw_savings_goal_progress AS
SELECT g.id,g.user_id,g.name,g.description,g.target_amount,g.currency_code,g.target_date,g.status,
       g.created_at,g.updated_at,COALESCE(SUM(sc.amount),0) AS saved,
       COUNT(sc.id) AS contribution_count,MIN(sc.contribution_date) AS first_contribution_date
FROM savings_goals g LEFT JOIN savings_contributions sc ON sc.savings_goal_id=g.id
GROUP BY g.id,g.user_id,g.name,g.description,g.target_amount,g.currency_code,g.target_date,g.status,g.created_at,g.updated_at;
