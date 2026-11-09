<?php

declare(strict_types=1);

/** Drain every CALL result set so the shared PDO connection remains usable. */
function wallet_call_procedure(PDO $pdo, string $procedure, array $parameters = []): array
{
    if (preg_match('/\Asp_[a-z_]+\z/', $procedure) !== 1) {
        throw new InvalidArgumentException('Invalid procedure name.');
    }
    $statement = $pdo->prepare('CALL ' . $procedure . '(' . implode(',', array_fill(0, count($parameters), '?')) . ')');
    try {
        $statement->execute(array_values($parameters));
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        while ($statement->nextRowset()) {
            // CALL includes a final status result set even for a single SELECT.
        }

        return $rows;
    } finally {
        $statement->closeCursor();
    }
}

function wallet_procedure_error(PDOException $error): string
{
    if (($error->errorInfo[0] ?? '') === '45000') {
        return (string) ($error->errorInfo[2] ?? 'Check the details and try again.');
    }
    error_log('Smart Wallet database operation failed: ' . $error->getMessage());

    return 'The change could not be saved. Please try again.';
}
