<?php
/* ===========================================================================
   SUPPEX — Database access
   ---------------------------------------------------------------------------
   One PDO handle, opened on first use. Every query in this project goes
   through db_query() with bound parameters; there is no code path anywhere
   that concatenates a value into SQL, which is what keeps injection off the
   table rather than relying on remembering to escape.
   =========================================================================== */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $c = suppex_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $c['host'],
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );

    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            /* Real prepared statements, not the driver emulating them by
               interpolating strings. With emulation on, a bound integer is
               still sent as a quoted string and some edge cases of injection
               survive; off, the value never touches the SQL text at all. */
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('[suppex] database connection failed: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Database unavailable.\n");
    }

    return $pdo;
}

/** Run a statement and return it. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** First row, or null. */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** All rows. */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** First column of the first row, or null. */
function db_value(string $sql, array $params = [])
{
    $val = db_query($sql, $params)->fetchColumn();
    return $val === false ? null : $val;
}

/* ---------------------------------------------------------------------------
   Executing a .sql file.

   Statements are split on a semicolon at end of line, then whole-line `--`
   comments are removed from each one. That second step is the part that
   matters: schema.sql documents every table with a comment block above it, so
   the obvious "skip any chunk starting with --" throws away the CREATE TABLE
   that follows the comment. The database then comes up half-built, and the
   only symptom is a foreign-key error on some unrelated table further down —
   which points at the wrong place entirely.

   Only full comment lines are stripped, never a `--` inside a value. This is
   written for the schema file in this repository, not for arbitrary dumps: it
   knows nothing about DELIMITER, stored procedures, or a semicolon inside a
   string literal.
   --------------------------------------------------------------------------- */
function db_sql_statements(string $sql): array
{
    /* Real line terminators only.

       PCRE's \R also matches the single byte 0x85 - NEL - when the pattern is
       not in UTF-8 mode. The Persian letter م is D9 85 in UTF-8, so \R cut
       it in half: the D9 was orphaned at the end of one line, the halves were
       rejoined with a newline, and every م in a restored backup became a
       question mark followed by a line break. db/schema.sql happens to contain
       no م, which is the only reason this went unnoticed. */
    $eol = '(?:\r\n|\n|\r)';

    $out = [];
    foreach (preg_split('/;[ \t]*' . $eol . '/', $sql) ?: [] as $chunk) {
        $lines = [];
        foreach (preg_split('/' . $eol . '/', $chunk) ?: [] as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $statement = trim(implode("\n", $lines));
        if ($statement !== '') {
            $out[] = $statement;
        }
    }
    return $out;
}

/** @return int how many statements ran */
function db_run_sql_file(string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('could not read ' . $path);
    }
    $count = 0;
    foreach (db_sql_statements($sql) as $statement) {
        db()->exec($statement);
        $count++;
    }
    return $count;
}

function db_insert(string $sql, array $params = []): int
{
    db_query($sql, $params);
    return (int) db()->lastInsertId();
}
