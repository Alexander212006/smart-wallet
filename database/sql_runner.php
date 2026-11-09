<?php

declare(strict_types=1);

/** Execute this project's SQL files, including CREATE routines with DELIMITER blocks. */
function wallet_execute_sql_file(PDO $pdo, string $path): void
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read SQL file: ' . basename($path));
    }
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $contents) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
            if (trim($buffer) !== '') {
                throw new RuntimeException('Unfinished SQL before delimiter change.');
            }
            $delimiter = $match[1];
            continue;
        }
        if (trim($line) === '' || preg_match('/^\s*--/', $line) === 1) {
            continue;
        }
        $buffer .= $line . "\n";
        $statement = rtrim($buffer);
        if (str_ends_with($statement, $delimiter)) {
            $result = $pdo->query(substr($statement, 0, -strlen($delimiter)));
            $result->closeCursor();
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        throw new RuntimeException('Unfinished SQL statement in ' . basename($path));
    }
}
