# Smart Wallet database case study

The implementation is tested on **MariaDB 10.4.32 in XAMPP**, using InnoDB tables. The PHP app calls the stored procedures and reads the views; these SQL objects are part of the running application.

## Rubric mapping

| Requirement | Project evidence |
| --- | --- |
| `CREATE`, `ALTER` | `schema.sql` creates six related tables. The migrations alter category storage and add the generated transaction type. `views.sql`, `procedures.sql`, and `triggers.sql` create database programs. |
| `INSERT`, `SELECT`, `UPDATE`, `DELETE` | Finance procedures create, retrieve, modify, and delete project records. `case_study_examples.sql` demonstrates each statement with disposable data. |
| Operators and aggregate functions | Procedures use comparisons, arithmetic, `AND`, `OR`, `IN`, `NOT IN`, `BETWEEN`, `CASE`, and date operations. Views use `SUM`, `COUNT`, `AVG`, `MIN`, `MAX`, and `COALESCE`. |
| Joins | `vw_transaction_details` joins transactions to their owned wallets. `vw_savings_goal_progress` uses a left join so goals with no contributions still appear. The demo also joins users, wallets, and transactions. |
| Views | Five views organize transaction details and summarize wallet balances, monthly cash flow, category spending, and savings progress. |
| Stored procedures | Seventeen procedures perform the application's finance writes and report queries. |
| Triggers | Seven triggers validate transaction ownership, automate balance updates, validate savings contributions, and complete reached savings goals. |

## Installation

1. For a new database, import `schema.sql`. For an existing database, apply the three table migrations listed in the main README.
2. Stop application writes during installation, then run from the project root:

   ```text
   php database/install_programs.php --apply
   ```

3. The installer loads `views.sql`, `triggers.sql`, and `procedures.sql` in that order. You can also import those files in that order through phpMyAdmin after selecting `smart_wallet`.
4. Open the app. Reinstalling the SQL programs replaces their definitions while preserving table records and existing balances.

The installation account needs privileges to create/replace views, routines and triggers. Procedures and views use `SQL SECURITY INVOKER`, and no account name is hardcoded as a definer. The scripts preserve the connection's SQL mode while defining mutating routines/triggers with strict table checks, so balance overflow rolls back instead of silently clipping values.

## Stored procedure catalog

All procedures take the signed-in user's ID as their first parameter. The PHP app supplies that value from the session. IDs and ownership are checked inside mutating procedures. Routine names and their ordered parameters can be inspected with `SHOW CREATE PROCEDURE` or phpMyAdmin's Routines tab.

| Procedure | Purpose and app caller |
| --- | --- |
| `sp_create_wallet` | Create a wallet with a starting balance; `wallet.php`. |
| `sp_update_wallet` | Update an owned wallet's name/type; `wallet.php`. |
| `sp_set_wallet_archived` | Archive or restore an owned wallet; `wallet.php`. |
| `sp_save_transaction` | Create (`NULL` ID) or edit income/expenses; `income.php`, `expenses.php`. |
| `sp_delete_transaction` | Delete an owned transaction of the expected income/expense direction. |
| `sp_save_savings_goal` | Create or edit a goal, preserving the currency of contributed goals; `savings.php`. |
| `sp_set_savings_goal_status` | Change a goal's active/paused/completed/cancelled status. |
| `sp_delete_savings_goal` | Delete a goal and its contribution history through the foreign key cascade. |
| `sp_contribute_savings` | Add a positive contribution to an active goal with an optional matching wallet. |
| `sp_save_expense_limit` | Create or edit a category-specific or all-category limit; `expenses.php`. |
| `sp_set_expense_limit_recurring` | Stop or resume a recurring limit on its original schedule. |
| `sp_delete_expense_limit` | Delete an owned limit. |
| `sp_get_expense_limits` | Calculate current recurring date ranges and spending through the requested date; overspending alerts and limit cards. |
| `sp_get_spending_rows` | Retrieve scoped expenses for the spending analysis; `includes/spending_analysis.php`. |
| `sp_get_monthly_summary` | Read currency-specific wallet balance, monthly income, and expenses through summary views; `index.php`. |
| `sp_get_cash_flow` | Group income and expenses by day within an exclusive-end date range; `index.php`. |
| `sp_get_category_spending` | Read a month's category totals, counts, average and largest expense; `index.php`. |

Mutating procedures use `START TRANSACTION`, row locks, an exception handler with `ROLLBACK`/`RESIGNAL`, and `COMMIT`. Call them outside an existing transaction; they explicitly reject a nested transaction instead of committing someone else's work. Validation errors use SQLSTATE `45000`. PHP translates those messages into the existing alert system and drains all `CALL` result sets before issuing another query.

Example calls, using IDs belonging to a case-study account:

```sql
CALL sp_create_wallet(@user_id, 'Everyday cash', 'cash', 1000.00, 'PHP');
-- Use the returned wallet ID for the following calls.
CALL sp_save_transaction(@user_id, NULL, @wallet_id, 'Food & dining',
    -100.00, 'Lunch', NOW(), 'essential');
-- Use the returned transaction ID to edit or delete it.
CALL sp_save_transaction(@user_id, @transaction_id, @wallet_id, 'Food & dining',
    -125.00, 'Lunch and transport', NOW(), 'essential');
CALL sp_delete_transaction(@user_id, @transaction_id, 'expense');
CALL sp_get_expense_limits(@user_id, CURDATE());
CALL sp_get_monthly_summary(@user_id, 'PHP', CURDATE());
```

## Views

| View | Result |
| --- | --- |
| `vw_transaction_details` | Transaction fields plus owned wallet name, currency, archive state and readable category. |
| `vw_wallet_summary` | Active wallet balances and active/archived wallet counts per user and currency. |
| `vw_monthly_cash_flow` | Monthly income, expenses, net cash flow and transaction count for active wallets. |
| `vw_monthly_category_spending` | Monthly category total, count, average and largest expense. |
| `vw_savings_goal_progress` | Each goal's saved amount, contribution count and first contribution date, including empty goals. |

The app always filters views by the session user and, where appropriate, currency. Monthly summary views include all records dated in that month, matching the existing dashboard totals. Spending analysis and expense-limit reporting apply explicit end dates to exclude future spending.

## Triggers and balance ownership

| Trigger | Event and action |
| --- | --- |
| `trg_transactions_validate_insert` | Before insert: require a matching wallet owner, nonzero amount, and no expense label on income. |
| `trg_transactions_validate_update` | Before update: keep ownership immutable and validate the new wallet/amount. |
| `trg_transactions_balance_insert` | After insert: add the signed amount to the wallet balance. |
| `trg_transactions_balance_update` | After update: apply the amount difference, or reverse the old wallet effect and apply the new one. |
| `trg_transactions_balance_delete` | After delete: reverse the old signed amount. |
| `trg_savings_contribution_validate` | Before insert: require an active goal, positive contribution and an owned, active, same-currency wallet when one is selected. |
| `trg_savings_goal_complete` | After contribution insert: mark an active goal completed once its contribution total reaches its target. |

**Do not update wallet balances again in PHP after saving a transaction.** Both application `CALL`s and direct transaction `INSERT`/`UPDATE`/`DELETE` statements run the balance triggers. Existing opening balances are preserved; installing triggers does not rebuild balances from historical transactions. Procedures restrict new app entries to active wallets. Direct SQL can maintain archived history, whose balances still update but whose spending is excluded from active reports.

Savings contributions continue to track goal progress without debiting wallet balances. Goal deletion cascades to its contribution history. MariaDB does not activate triggers for foreign-key cascade actions, so the application does not rely on contribution-delete triggers during goal deletion. [MariaDB trigger limitations](https://mariadb.com/docs/server/server-usage/triggers-events/triggers/trigger-limitations)

## Demonstration and validation

Open `case_study_examples.sql` in phpMyAdmin after installing the programs. It demonstrates temporary-table `CREATE`/`ALTER`, app-table DML, operators, aggregates, joins, views and reporting `CALL`s. The app-table demo is wrapped in a transaction and ends with `ROLLBACK`; the temporary table is removed separately. Mutating procedure examples above must run outside that outer transaction.

```text
php tests/database_programs_test.php --run
php tests/spending_analysis_test.php
php tests/overspending_test.php
```

The program test creates and removes a disposable database. It checks procedure permissions, direct SQL triggers, exact balance deltas, wallet moves, overflow rollback, nested-transaction rejection, generated transaction types, savings completion, currency protection, recurring month-end/leap-year dates, reporting scope and rerunnable installation. Run the HTTP workflow test from the main README to exercise the actual PHP forms.

MariaDB references: [views](https://mariadb.com/docs/server/server-usage/views/create-view), [transaction handling](https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction), and [exception handlers](https://mariadb.com/docs/server/reference/sql-statements/programmatic-compound-statements/declare-handler).
