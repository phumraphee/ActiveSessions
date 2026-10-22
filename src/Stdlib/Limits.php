<?php
namespace ActiveSessions\Stdlib;

use Doctrine\DBAL\Connection;

/**
 * HOW LONG ONE SIGN-IN MAY LAST, BY ROLE — KEPT ON THE SERVER, NOT ONLY IN THE COOKIE.
 *
 * Everybody: a few hours (setting, default 12 h). The roles in the "longer" list (default:
 * global_admin): longer (default 7 days). 0 = no limit of this module's own.
 *
 * The clock of a sign-in is active_sessions_seen.since: set when somebody signs in (the
 * user.login event: the password form and any sign-in module that triggers user.login too),
 * and — for a sign-in that began before this rule — when it is first seen, so a new rule
 * never ends everybody at once.
 *
 * Kept three ways:
 *   1 on each of the person's own requests (Recorder): past its time, the sign-in is ended
 *     there and then, and the browser goes to the log-in page;
 *   2 every few minutes, on anybody's request (sweep): every sign-in past its time is ended
 *     and its session row deleted — pages outside Omeka that read the session table (a
 *     site's own staff pages) see it gone even if the person never comes back to Omeka;
 *   3 the cookie: sent again with an expiry at the sign-in's end, so a browser forgets it.
 * A cookie made to last longer by hand changes nothing: the server keeps the time.
 */
class Limits
{
    const S_SHORT = 'activesessions_limit_short';   // seconds, everybody
    const S_LONG = 'activesessions_limit_long';     // seconds, the roles below
    const S_ROLES = 'activesessions_long_roles';    // list of role names
    const S_SWEPT = 'activesessions_last_sweep';    // unix time of the last sweep

    const SHORT = 43200;        // 12 hours
    const LONG = 604800;        // 7 days
    const ROLES = ['global_admin'];
    const SWEEP_EVERY = 300;

    /** Seconds a sign-in of this role may last; 0 = no limit. */
    public static function forRole(string $role, $settings): int
    {
        $roles = $settings->get(self::S_ROLES, self::ROLES);
        $long = is_array($roles) && in_array($role, $roles, true);
        $v = $settings->get($long ? self::S_LONG : self::S_SHORT, $long ? self::LONG : self::SHORT);
        return max(0, (int) $v);
    }

    /** Has a sign-in that began at $since, under $limit seconds, ended at $now? */
    public static function over(int $since, int $limit, int $now): bool
    {
        return $limit > 0 && $since > 0 && $now - $since >= $limit;
    }

    /** Send the session cookie again, ending when the sign-in ends (or as it was, for no limit). */
    public static function cookie(int $since, int $limit): void
    {
        if ($limit <= 0 || headers_sent() || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $p = session_get_cookie_params();
        setcookie(session_name(), (string) session_id(), [
            'expires' => $since + $limit,
            'path' => $p['path'] ?: '/',
            'domain' => $p['domain'] ?? '',
            'secure' => (bool) ($p['secure'] ?? false),
            'httponly' => (bool) ($p['httponly'] ?? true),
            'samesite' => ($p['samesite'] ?? '') ?: 'Lax',
        ]);
    }

    /**
     * Every sign-in past its time: ended (marked, as the sessions page marks one) and its
     * session row deleted. A sign-in this rule has not seen yet gets its clock now.
     * At most once every SWEEP_EVERY seconds for the whole site. Returns how many ended.
     */
    public static function sweep(Connection $conn, $settings, int $sessionLifetime, int $now, bool $force = false): int
    {
        $last = (int) $conn->fetchOne('SELECT value FROM setting WHERE id = ?', [self::S_SWEPT]);
        if (!$force && $last > 0 && $now - $last < self::SWEEP_EVERY) {
            return 0;
        }
        $mark = json_encode($now);
        if ($conn->executeStatement('UPDATE setting SET value = ? WHERE id = ?', [$mark, self::S_SWEPT]) === 0
            && $conn->fetchOne('SELECT 1 FROM setting WHERE id = ?', [self::S_SWEPT]) === false) {
            $conn->executeStatement('INSERT INTO setting (id, value) VALUES (?, ?)', [self::S_SWEPT, $mark]);
        }
        $sessions = new Sessions($conn, $sessionLifetime);
        $all = $sessions->signedIn();
        if (!$all) {
            return 0;
        }
        $seen = $sessions->seen(array_keys($all));
        $ids = array_values(array_unique(array_column($all, 'user_id')));
        $roles = [];
        foreach ($conn->fetchAllAssociative('SELECT id, role FROM user WHERE id IN (?)', [$ids], [Connection::PARAM_INT_ARRAY]) as $u) {
            $roles[(int) $u['id']] = (string) $u['role'];
        }
        $ended = 0;
        foreach ($all as $hash => $s) {
            $since = (int) ($seen[$hash]['since'] ?? 0);
            if ($since <= 0) {
                $conn->executeStatement(
                    'INSERT INTO active_sessions_seen (sid, user_id, first_seen, last_seen, since) VALUES (?, ?, ?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE since = COALESCE(since, VALUES(since))',
                    [$hash, $s['user_id'], $now, (int) $s['modified'], $now]
                );
                continue;
            }
            $limit = self::forRole($roles[$s['user_id']] ?? '', $settings);
            if (self::over($since, $limit, $now)) {
                self::endOne($conn, $hash, (int) $s['user_id'], $now);
                $conn->executeStatement('DELETE FROM session WHERE id = ?', [$s['id']]);
                $ended++;
            }
        }
        return $ended;
    }

    /** Mark one sign-in ended by its time (ended_by 0 = the time limit, not a person). */
    public static function endOne(Connection $conn, string $hash, int $userId, int $now): void
    {
        $conn->executeStatement(
            'INSERT INTO active_sessions_seen (sid, user_id, first_seen, last_seen, ended, ended_by) VALUES (?, ?, 0, 0, ?, 0)'
            . ' ON DUPLICATE KEY UPDATE ended = VALUES(ended), ended_by = 0',
            [$hash, $userId, $now]
        );
    }

    /** "12 hours", "7 days", for the messages and the settings page. */
    public static function words(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'no limit';
        }
        if ($seconds % 86400 === 0) {
            $n = intdiv($seconds, 86400);
            return $n . ($n === 1 ? ' day' : ' days');
        }
        if ($seconds % 3600 === 0) {
            $n = intdiv($seconds, 3600);
            return $n . ($n === 1 ? ' hour' : ' hours');
        }
        $n = (int) ceil($seconds / 60);
        return $n . ($n === 1 ? ' minute' : ' minutes');
    }
}
