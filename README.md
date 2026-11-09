# Smart Wallet

A vanilla PHP and MySQL personal finance app. Serve this directory with XAMPP and configure the database connection in `config/database.php`.

## Database programs and case study

The app uses 17 stored procedures, five views, and seven triggers. Procedures handle finance operations and reports; transaction triggers maintain wallet balances, and a savings trigger completes reached goals. PHP handles sessions, CSRF, password hashing, input feedback, and rendering.

After importing the schema or applying the existing table migrations, install the database programs:

```text
php database/install_programs.php --apply
```

Run the installer before using the updated PHP app, with application writes stopped during installation. It can be rerun and does not recalculate existing balances. The application must not also adjust balances after a transaction; triggers own those changes. Mutating procedures manage their own database transactions and reject an existing outer transaction.

See [the case-study guide](database/CASE_STUDY.md) for the rubric mapping, procedure catalog, SQL examples, and installation details. The implementation is tested on XAMPP's MariaDB 10.4.32.

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

## Checks

Run the calculation tests with PHP CLI:

```text
php tests/spending_analysis_test.php
php tests/overspending_test.php
```

Test procedures, views, triggers, rollback, ownership, and direct SQL balance updates in a disposable database:

```text
php tests/database_programs_test.php --run
```

Test the migration and fresh schema in a disposable MySQL database (requires permission to create databases):

```text
php tests/category_migration_test.php --run
```

To test the expense save/edit flow and rendered analysis against the configured MySQL database, start a local server in one terminal and run the workflow test in another:

```text
php -S 127.0.0.1:8765 -t .
php tests/spending_workflow_test.php --run http://127.0.0.1:8765
```

The workflow test requires PHP cURL and creates temporary fixture accounts that it removes after the run.
