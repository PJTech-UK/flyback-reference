<?php
declare(strict_types=1);

/** Single read-only SQLite connection, opened lazily. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $path = dirname(__DIR__) . '/database/database.sqlite';
            if (!is_file($path)) {
                throw new RuntimeException("database.sqlite not found — run: php bin/build-db.php");
            }
            // Open read-only so the web process can never mutate the dataset.
            $dsn = 'sqlite:' . $path;
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA query_only = ON');
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /**
     * The deployed version, from the VERSION file rather than from the database.
     *
     * meta.version is stamped in when bin/build-db.php runs, so it reports
     * whichever release was current at build time. That is correct in
     * production, where the deploy builds the database after checkout, and
     * wrong everywhere else -- a working copy that has been released twice
     * without a rebuild shows a version two behind. The file ships with the
     * code and cannot drift from it.
     */
    public static function version(): ?string
    {
        static $v = false;
        if ($v !== false) return $v;
        $f = dirname(__DIR__) . '/VERSION';
        $raw = is_file($f) ? trim((string)@file_get_contents($f)) : '';
        return $v = ($raw !== '' ? $raw : self::meta('version'));
    }

    public static function meta(string $key): ?string
    {
        $s = self::get()->prepare('SELECT value FROM meta WHERE key = ?');
        $s->execute([$key]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    }
}
