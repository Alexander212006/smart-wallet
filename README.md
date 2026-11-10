# Smart Wallet

A vanilla PHP personal finance app for MySQL 8.0.16+ or XAMPP MariaDB 10.4+. Serve this directory with XAMPP and configure the database connection in `config/database.php`.

The connection also accepts `SMART_WALLET_DB_DSN`, `SMART_WALLET_DB_USER`, and `SMART_WALLET_DB_PASSWORD` environment variables. For example, a DSN of `mysql:host=127.0.0.1;port=3307;dbname=smart_wallet;charset=utf8mb4` selects a separate local MySQL server. Without overrides, the existing XAMPP connection settings apply.

## Database programs and case study

The app uses 17 stored procedures, five views, and seven triggers. Procedures handle finance operations and reports; transaction triggers maintain wallet balances, and a savings trigger completes reached goals. PHP handles sessions, CSRF, password hashing, input feedback, and rendering.

After importing the schema or applying the existing table migrations, run the compatibility preflight and then install the database programs:

```text
php database/install_programs.php --check
php database/install_programs.php --apply
```

Run the installer before using the updated PHP app, with application writes stopped during installation. The same SQL files support MySQL 8.0.16+ and MariaDB 10.4+, can be rerun after a partial failure, and do not recalculate existing balances. MySQL requires enabled Performance Schema transaction instrumentation; MariaDB uses its native transaction status and has no Performance Schema requirement. The application must not also adjust balances after a transaction; triggers own those changes. Mutating procedures manage their own database transactions and reject an existing outer transaction or disabled autocommit.

See [the case-study guide](database/CASE_STUDY.md) for all six rubric requirements, the procedure catalog, SQL examples, and engine-specific installation details.

## Spending analysis

Open **Spending analysis** in the navigation or select **View analysis** on the overview dashboard. Choose a month from the past twelve months and a currency to see:

- Categories ranked by expense frequency, with spending totals and shares.
- The highest spending day and week, plus weekly spending totals.
- A six-month spending history and a comparison with the previous month. The current month compares the same elapsed days, capped at the previous month's last day.
- Essential, non-essential, and unclassified spending. Choose an optional **Expense type** when adding or editing an expense; existing entries stay unclassified.

Analysis includes expenses from active wallets in the selected currency. Future-dated entries are excluded. Weeks run Monday through Sunday and include only dates in the selected period.

Categories are stored as names in `transactions.category` and `expense_limits.category`. Income amounts are positive and expense amounts are negative; the forms still accept a positive amount. The generated `transactions.transaction_type` column displays `income` or `expense` automatically from the amount. There is no categories table.

For a new database, import `database/schema.sql`. For an existing database, stop application writes and run these migrations in order before using the updated app:

1. `database/migrations/20261109_add_expense_nature.sql`
2. `database/migrations/20261109_inline_categories.sql`
3. `database/migrations/20261109_add_transaction_type.sql`

The second migration copies category names into transactions and expense limits, converts expense amounts to negative values, then removes category references, the categories table, and the old writable transaction type column. The third adds a generated type column that stays consistent with the amount. Wallet balances and record timestamps are preserved. All migrations can be rerun safely.

## Overspending alerts

Create an expense limit in **Expenses** to enable overspending alerts. Overview and Expenses show a warning whenever spending exceeds an active weekly, monthly, or yearly limit, including the exact amount over the limit. Categories and currencies are evaluated separately; income, archived wallets, and future-dated expenses do not count.

A toast appears the first time an overspend is detected in your session, when the excess increases, or when a resolved limit is exceeded again. The warning panel stays visible until spending is back within the limit or the limit is no longer active. Reaching the exact limit does not trigger an overspending alert.

Select **Review limits** in the warning panel to open a modal with each exceeded limit's period, category, spending, and excess amount. **Edit limit** opens its edit form. Close the review with **Done**, the close button, Escape, or a click outside the modal.

## Financial Reports

Open **Financial Reports** in the desktop or mobile navigation. Reports support ISO weeks (Monday through Sunday), calendar months, and calendar years. Each report is kept to one active-wallet currency and includes:

- Total income, total expenses, transaction-derived net savings, and savings rate.
- A reconciling category breakdown with transaction counts and percentages.
- Applicable weekly, monthly, and yearly budget performance.
- An elapsed-time-matched comparison with the immediately preceding period.
- Dependency-free PDF and UTF-8 CSV downloads generated from the same report data as the page.

Income is the sum of positive transactions. Expenses are the absolute sum of negative transactions. Net savings is income minus expenses, and savings rate is `net savings / income * 100`; a zero-income period displays `N/A`. All database amounts are accumulated as integer cents rather than binary floating-point values. Null and blank categories are grouped as **Uncategorized**.

Current incomplete periods include today and exclude later dates. Their previous-period comparison uses the same number of elapsed calendar days, capped at the previous period's end. Completed periods compare against the complete previous period. Percentage change is `(current - previous) / abs(previous) * 100`; a zero previous baseline is `N/A`. Savings-rate differences are percentage points.

Reports follow the existing analytics policy: transactions from archived wallets and future-dated transactions are excluded. Currencies are never combined or converted. Queries are scoped to both the authenticated user and wallets owned by that user.

Budget cycles stay anchored to each limit's `start_date` and respect its period, inclusive `end_date`, and recurrence setting. A report that covers complete cycles receives their complete allocations. Any first, last, stopped, or currently incomplete cycle is prorated by covered calendar days; this is labelled on the page and in exports. Each overlapping budget is evaluated independently, and budget rows are never added together to derive total spending.

PDF export uses the project's built-in A4 writer with PDF base fonts, wrapped tables, repeated headers, and pagination. For dependency-free portability, PDF text outside the Windows-1252 character set is transliterated when possible; the CSV export remains lossless UTF-8. CSV includes metadata, summary, categories, budgets, and comparisons, and user-controlled text is protected against spreadsheet-formula injection. No Composer package, JavaScript chart library, or database migration is required for Financial Reports.

### Run in XAMPP

1. Start Apache and MySQL from the XAMPP control panel.
2. Configure the PDO connection in `config/database.php`. For a new installation, import `database/schema.sql`; for an existing installation, apply the migrations listed above. Run the database program installer before opening the app.
3. Open `http://localhost/vanilla-php/smart-wallet/`, sign in, and choose **Financial Reports**.
4. Choose Weekly, Monthly, or Yearly, select a date and one of your active-wallet currencies, then select **Generate Report**.
5. Use **Export PDF** or **Export CSV** to download the exact displayed report.

## Checks

PHP is not added to `PATH` by a default XAMPP installation. In PowerShell, use `C:\xampp\php\php.exe` in place of `php` in the commands below when necessary.

Run the Financial Reports calculation, isolation, budget, CSV, and PDF tests (PDO SQLite required):

```text
php tests/financial_reports_test.php
```

Check database-program SQL framing and both engines' transaction guards without connecting to or changing a database:

```text
php tests/database_programs_static_test.php
```

Run the spending, overspending, and shared expense-limit calculation tests with PHP CLI:

```text
php tests/spending_analysis_test.php
php tests/overspending_test.php
php tests/overview_expense_limits_test.php
```

Test procedures, views, triggers, rollback, ownership, direct SQL balance updates, and the SQL demo in a disposable database on the configured engine:

```text
php tests/database_programs_test.php --run
```

Test the migration and fresh schema in a disposable MySQL database (requires permission to create databases):

```text
php tests/category_migration_test.php --run
```

To test the expense save/edit flow, rendered analytics and reports, authenticated PDF/CSV downloads, and export authorization against the configured MySQL database, start a local server in one terminal and run the workflow test in another:

```text
php -S 127.0.0.1:8765 -t .
php tests/spending_workflow_test.php --run http://127.0.0.1:8765
```

The workflow test requires PHP cURL and creates temporary fixture accounts that it removes after the run.
