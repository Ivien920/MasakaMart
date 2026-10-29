<?php
/** Test-only PDO subclass: lets the app's MySQL SQL run on SQLite. Not for production. */
class SqliteMysqlShim extends PDO
{
    public static function tr(string $sql): string
    {
        $s = trim($sql);
        if (preg_match('/^SHOW COLUMNS FROM (\w+)/i', $s, $m)) return "SELECT name AS Field FROM pragma_table_info('{$m[1]}')";
        if (preg_match('/^(ALTER TABLE|CREATE TABLE IF NOT EXISTS)/i', $s)) return 'SELECT 1';
        $s = preg_replace('/\s+FOR UPDATE\b/i', '', $s);
        $s = preg_replace('/TIMESTAMPDIFF\(SECOND,\s*([\w\.]+),\s*NOW\(\)\)/i', "CAST((strftime('%s','now') - strftime('%s', $1)) AS INTEGER)", $s);
        $s = preg_replace('/\(NOW\(\)\s*-\s*INTERVAL\s+(\d+)\s+MINUTE\)/i', "datetime('now','-$1 minutes')", $s);
        return preg_replace('/\bNOW\(\)/i', "datetime('now')", $s);
    }
    public function prepare(string $q, array $o = []): PDOStatement|false { return parent::prepare(self::tr($q), $o); }
    public function query(string $q, ?int $m = null, ...$a): PDOStatement|false { return parent::query(self::tr($q)); }
    public function raw(string $q): int|false { return parent::exec($q); }
    public function exec(string $q): int|false { return parent::exec(self::tr($q)); }
}
