<?php

declare(strict_types=1);

/** Parse one of this project's SQL files, including CREATE routines with DELIMITER blocks. */
function wallet_parse_sql_file(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read SQL file: ' . basename($path));
    }
    $delimiter = ';';
    $buffer = '';
    $statementLine = 1;
    $statements = [];
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        throw new RuntimeException('Cannot parse SQL file: ' . basename($path));
    }
    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Unfinished SQL before delimiter change in ' . basename($path) . ' at line ' . $lineNumber . '.');
            }
            $delimiter = $match[1];
            continue;
        }
        if (trim($line) === '' || preg_match('/^\s*--/', $line) === 1) {
            continue;
        }
        if ($buffer === '') {
            $statementLine = $lineNumber;
        }
        $buffer .= $line . "\n";
        $statement = rtrim($buffer);
        if (str_ends_with($statement, $delimiter)) {
            $statements[] = [
                'sql' => substr($statement, 0, -strlen($delimiter)),
                'line' => $statementLine,
            ];
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException('Unfinished SQL statement in ' . basename($path));
    }

    return $statements;
}

/** Execute a completely parsed SQL file so syntax framing errors occur before any DDL runs. */
function wallet_execute_sql_file(PDO $pdo, string $path): void
{
    $statements = wallet_parse_sql_file($path);
    foreach ($statements as $index => $statement) {
        try {
            $result = $pdo->exec($statement['sql']);
            if ($result === false) {
                $details = $pdo->errorInfo();
                throw new RuntimeException($details[2] ?? 'Unknown database error.');
            }
        } catch (Throwable $error) {
            throw new RuntimeException(
                sprintf(
                    '%s statement %d (starting at line %d) failed: %s',
                    basename($path),
                    $index + 1,
                    $statement['line'],
                    $error->getMessage()
                ),
                0,
                $error
            );
        }
    }
}
