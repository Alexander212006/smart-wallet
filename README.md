# Smart Wallet

A vanilla PHP and MySQL personal finance app. Serve this directory with XAMPP and configure the database connection in `config/database.php`.

## Spending analysis

Open **Spending analysis** in the navigation or select **View analysis** on the overview dashboard. Choose a month from the past twelve months and a currency to see:

- Categories ranked by expense frequency, with spending totals and shares.
- The highest spending day and week, plus weekly spending totals.
- A six-month spending history and a comparison with the previous month. The current month compares the same elapsed days, capped at the previous month's last day.
- Essential, non-essential, and unclassified spending. Choose an optional **Expense type** when adding or editing an expense; existing entries stay unclassified.

Analysis includes expenses from active wallets in the selected currency. Future-dated entries are excluded. Weeks run Monday through Sunday and include only dates in the selected period.

For a new database, import `database/schema.sql`. For an existing database, run `database/migrations/20261109_add_expense_nature.sql` before using the updated app. The migration adds a nullable column, preserves existing transactions, and can be rerun safely.

## Checks

Run the calculation and query-isolation tests with PHP CLI (PDO SQLite required):

```text
php tests/spending_analysis_test.php
```

To test the expense save/edit flow and rendered analysis against the configured MySQL database, start a local server in one terminal and run the workflow test in another:

```text
php -S 127.0.0.1:8765 -t .
php tests/spending_workflow_test.php --run http://127.0.0.1:8765
```

The workflow test requires PHP cURL and creates temporary fixture accounts that it removes after the run.
