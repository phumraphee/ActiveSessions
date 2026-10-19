<?php
namespace ActiveSessions\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * Who is signed in, read from Omeka's own `session` table, plus what this module has seen
 * of each sign-in in its table `active_sessions_seen`.
 *
 * `session` holds only id, data and modified: no address, no browser. Thousands of its
 * rows are alive at any time and nearly all belong to visitors. A signed-in one is the row
 * whose data holds Laminas_Auth with a user id in "storage". So the list of sign-ins comes
 * from `session` (it is the truth: a row there is a sign-in that still works), and the
 * address and browser come from `active_sessions_seen`, written by Recorder on the signed-in
 * person's own requests.
 *
 * `active_sessions_seen` is keyed by the sha256 of the session id, never the id itself. The id is
 * all it takes to be that person, and a module's table gets exported and backed up on its
 * own; a hash in it lets nobody sign in as anybody.
 */
class Sessions
{
    /** @var Connection */
    protected $conn;

    /** @var int seconds a session lives unused (config/local.config.php gc_maxlifetime) */
    protected $lifetime;

    public function __construct(Connection $conn, int $lifetime)
    {
        $this->conn = $conn;
        $this->lifetime = $lifetime > 0 ? $lifetime : 1440;
    }

    public function lifetime(): int
    {
        return $this->lifetime;
    }

    public static function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /**
     * The user id signed in by one row of session data, or 0.
     *
     * Omeka 4 writes Laminas_Auth as an ArrayObject whose "storage" is an array whose
     * "storage" is the id:
     *   Laminas_Auth|O:26:"Laminas\Stdlib\ArrayObject":4:{s:7:"storage";a:1:{s:7:"storage";i:23;}...
     * and a signed-out visitor has  ...{s:7:"storage";a:0:{}...  The plain-array shape
     *   Laminas_Auth|a:1:{s:7:"storage";i:23;}
     * is the older shape, and what tests write.
     */
    public static function userIn(string $data): int
    {
        if (preg_match('/(?:^|[;}])Laminas_Auth\|(?:O:\d+:"[^"]+":\d+:\{s:7:"storage";)?a:1:\{s:7:"storage";(?:i:(\d+)|s:\d+:"(\d+)");/', $data, $m)) {
            return (int) ($m[1] !== '' ? $m[1] : $m[2]);
        }
        return 0;
    }

    /**
     * Every sign-in that still works: [hash => ['id', 'user_id', 'modified']].
     * The LIKEs only narrow thousands of rows to the few dozen worth reading; userIn() decides.
     */
    public function signedIn(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, data, modified FROM session WHERE modified > ?'
            . ' AND (data LIKE ? OR data LIKE ?)',
            [time() - $this->lifetime, '%"storage";a:1:{s:7:"storage";%', '%Laminas_Auth|a:1:{s:7:"storage";%']
        );
        $out = [];
        foreach ($rows as $r) {
            $uid = self::userIn((string) $r['data']);
            if ($uid > 0) {
                $out[self::hash((string) $r['id'])] = ['id' => (string) $r['id'], 'user_id' => $uid, 'modified' => (int) $r['modified']];
            }
        }
        return $out;
    }

    /** What active_sessions_seen knows of these hashes: [hash => row]. */
    public function seen(array $hashes): array
    {
        if (!$hashes) {
            return [];
        }
        $rows = $this->conn->fetchAllAssociative(
            'SELECT * FROM active_sessions_seen WHERE sid IN (?)',
            [array_values($hashes)],
            [Connection::PARAM_STR_ARRAY]
        );
        return array_column($rows, null, 'sid');
    }

    /** Sign-ins ended from the page lately, newest first, for the record at the bottom. */
    public function endedLately(int $days = 30): array
    {
        return $this->conn->fetchAllAssociative(
            'SELECT * FROM active_sessions_seen WHERE ended IS NOT NULL AND ended > ? ORDER BY ended DESC LIMIT 50',
            [time() - $days * 86400]
        );
    }

    /**
     * End one sign-in. Marks it ended FIRST, then deletes the session row.
     *
     * Omeka's save handler writes with INSERT ... ON DUPLICATE KEY UPDATE, so a request
     * of that person's that is still running when the row goes will put it back as it
     * ends. The mark is what catches that: Recorder sees it on the next request and
     * signs the session out there and then.
     *
     * Returns the session's user id, or 0 when no working sign-in has this hash.
     */
    public function end(string $hash, int $byUser): int
    {
        $all = $this->signedIn();
        if (!isset($all[$hash])) {
            return 0;
        }
        $s = $all[$hash];
        $now = time();
        $this->conn->executeStatement(
            'INSERT INTO active_sessions_seen (sid, user_id, first_seen, last_seen, ended, ended_by)'
            . ' VALUES (?, ?, 0, 0, ?, ?) ON DUPLICATE KEY UPDATE ended = VALUES(ended), ended_by = VALUES(ended_by)',
            [$hash, $s['user_id'], $now, $byUser]
        );
        $this->conn->executeStatement('DELETE FROM session WHERE id = ?', [$s['id']]);
        return $s['user_id'];
    }

    /** Rows for sessions that can no longer be alive, kept a week past that for the record. */
    public function forgetOld(): void
    {
        $this->conn->executeStatement(
            'DELETE FROM active_sessions_seen WHERE GREATEST(last_seen, COALESCE(ended, 0)) < ?',
            [time() - $this->lifetime - 7 * 86400]
        );
    }
}
