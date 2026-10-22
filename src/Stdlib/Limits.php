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
 *
 * 0.3: two exceptions a global admin sets on the sessions page, both off unless set:
 *   - for one PERSON (user setting activesessions_user_limit): 7 days, 30 days, 180 days or
 *     the longest the server allows, instead of their role's;
 *   - for one DEVICE (one sign-in, active_sessions_seen.keep): the longest the server allows,
 *     from its sign-in — kept while the person stays active and keeps the role they had when
 *     it was set (keep_role); a role changed or the person deactivated drops it.
 * THE LONGEST THE SERVER ALLOWS (ceiling()) is read as the site runs: the shorter of the
 * session's lifetime unused (gc_maxlifetime) and its cookie's (cookie_lifetime), as Omeka uses
 * them. Nothing is ever set past it: a choice longer than it is cut to it, and the page says
 * so (the owner, 2026-10-22: no "for ever"; find the most the server gives, and use that).
 * A device beats the person, the person beats the role. Nothing set = 0.2 exactly. Every
 * change is noted (setting activesessions_log, shown on the page).
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

    const S_LOG = 'activesessions_log';             // the changes of 0.3's exceptions, latest 200
    const U_LIMIT = 'activesessions_user_limit';    // user setting: one of CHOICES
    /** a person's own time, instead of their role's: seconds; 'max' = the ceiling */
    const CHOICES = ['7d' => 604800, '30d' => 2592000, '180d' => 15552000, 'max' => 0];

    /**
     * The longest a sign-in can last on this server, in seconds: the shorter of gc_maxlifetime
     * (a session unused longer is deleted) and cookie_lifetime (when the browser drops the
     * cookie; 0 = when it closes, which says nothing of how long), as Omeka uses them —
     * config/local.config.php 'session' => ['config' => …] first, else PHP's own.
     */
    public static function ceiling(array $config): int
    {
        $c = $config['session']['config'] ?? [];
        $gc = (int) ($c['gc_maxlifetime'] ?? 0);
        $gc = $gc > 0 ? $gc : (int) ini_get('session.gc_maxlifetime');
        $ck = (int) ($c['cookie_lifetime'] ?? ini_get('session.cookie_lifetime'));
        $vals = array_filter([$gc, $ck], fn($v) => $v > 0);
        return $vals ? min($vals) : 0;
    }

    /** Seconds a person's choice gives under this ceiling (cut to it); 0 = no choice. */
    public static function choiceSeconds(?string $choice, int $ceiling): int
    {
        if ($choice === null || !array_key_exists($choice, self::CHOICES)) {
            return 0;
        }
        $v = self::CHOICES[$choice];
        if ($v === 0) {
            return $ceiling;
        }
        return $ceiling > 0 ? min($v, $ceiling) : $v;
    }

    /** The choices worth offering under this ceiling: those shorter than it, and 'max'. */
    public static function offered(int $ceiling): array
    {
        return array_values(array_filter(array_keys(self::CHOICES), fn($k) => $k === 'max' || $ceiling <= 0 || self::CHOICES[$k] < $ceiling));
    }

    /**
     * The time one sign-in may last and why: [seconds (0 = no limit), 'device'|'person'|'role'].
     *
     * @param string      $role    the person's role now
     * @param string|null $person  their own choice (U_LIMIT), null = none
     * @param bool        $device  this sign-in is kept on its device (keepHolds())
     * @param int         $ceiling the longest the server allows (ceiling())
     */
    public static function effective(string $role, ?string $person, bool $device, $settings, int $ceiling = 0): array
    {
        if ($device && $ceiling > 0) {
            return [$ceiling, 'device'];
        }
        if ($person !== null && array_key_exists($person, self::CHOICES) && self::choiceSeconds($person, $ceiling) > 0) {
            return [self::choiceSeconds($person, $ceiling), 'person'];
        }
        return [self::forRole($role, $settings), 'role'];
    }

    /** Does a device's "keep" still hold: set, the person active, the role the one it was set for. */
    public static function keepHolds(?array $seenRow, string $role, bool $active): bool
    {
        return $seenRow !== null && ($seenRow['keep'] ?? null) !== null && $active
            && (string) ($seenRow['keep_role'] ?? '') === $role;
    }

    /** One person's own choice (U_LIMIT), null when they follow their role. */
    public static function personChoice(Connection $conn, int $userId): ?string
    {
        $v = $conn->fetchOne('SELECT value FROM user_setting WHERE id = ? AND user_id = ?', [self::U_LIMIT, $userId]);
        $v = $v === false ? null : json_decode((string) $v, true);
        return is_string($v) && array_key_exists($v, self::CHOICES) ? $v : null;
    }

    /** Every person's own choice: [user id => choice]. */
    public static function personChoices(Connection $conn): array
    {
        $out = [];
        foreach ($conn->fetchAllAssociative('SELECT user_id, value FROM user_setting WHERE id = ?', [self::U_LIMIT]) as $r) {
            $v = json_decode((string) $r['value'], true);
            if (is_string($v) && array_key_exists($v, self::CHOICES)) {
                $out[(int) $r['user_id']] = $v;
            }
        }
        return $out;
    }

    /** What a choice is called on the page, under this ceiling: cut to it, it says so. */
    public static function choiceWords(?string $choice, int $ceiling = 0): string
    {
        if ($choice === null || !isset(self::CHOICES[$choice])) {
            return 'as the role';
        }
        if ($choice === 'max') {
            return 'the longest the server allows (' . self::words($ceiling) . ')';
        }
        $v = self::CHOICES[$choice];
        return $ceiling > 0 && $v > $ceiling
            ? self::words($ceiling) . ' (set to ' . self::words($v) . ', cut to the longest the server allows)'
            : self::words($v);
    }

    /**
     * Note one change (latest 200). Never throws: a note must not stop the change.
     * $what: e.g. "device kept", "person: 30 days"; $userId the person it is about; $by who did it (0 = the rule).
     */
    public static function log(Connection $conn, string $what, int $userId, int $by, int $now): void
    {
        try {
            $v = $conn->fetchOne('SELECT value FROM setting WHERE id = ?', [self::S_LOG]);
            $list = $v === false ? [] : (json_decode((string) $v, true) ?: []);
            array_unshift($list, ['at' => $now, 'what' => mb_substr($what, 0, 300), 'user' => $userId, 'by' => $by]);
            $json = json_encode(array_slice($list, 0, 200));
            if ($v === false) {
                $conn->executeStatement('INSERT INTO setting (id, value) VALUES (?, ?)', [self::S_LOG, $json]);
            } else {
                $conn->executeStatement('UPDATE setting SET value = ? WHERE id = ?', [$json, self::S_LOG]);
            }
        } catch (\Throwable $e) {
            error_log('ActiveSessions log: ' . $e->getMessage());
        }
    }

    /** The changes noted, newest first. */
    public static function history(Connection $conn): array
    {
        $v = $conn->fetchOne('SELECT value FROM setting WHERE id = ?', [self::S_LOG]);
        $v = $v === false ? [] : json_decode((string) $v, true);
        return is_array($v) ? $v : [];
    }

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
    public static function sweep(Connection $conn, $settings, int $sessionLifetime, int $now, bool $force = false, int $ceiling = 0): int
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
        $active = [];
        foreach ($conn->fetchAllAssociative('SELECT id, role, is_active FROM user WHERE id IN (?)', [$ids], [Connection::PARAM_INT_ARRAY]) as $u) {
            $roles[(int) $u['id']] = (string) $u['role'];
            $active[(int) $u['id']] = (bool) $u['is_active'];
        }
        $choices = self::personChoices($conn);
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
            $uid = (int) $s['user_id'];
            $role = $roles[$uid] ?? '';
            $keep = self::keepHolds($seen[$hash] ?? null, $role, $active[$uid] ?? false);
            if (!$keep && ($seen[$hash]['keep'] ?? null) !== null) {
                self::dropKeep($conn, $hash, $uid, $now);
            }
            [$limit] = self::effective($role, $choices[$uid] ?? null, $keep, $settings, $ceiling);
            if (self::over($since, $limit, $now)) {
                self::endOne($conn, $hash, (int) $s['user_id'], $now);
                $conn->executeStatement('DELETE FROM session WHERE id = ?', [$s['id']]);
                $ended++;
            }
        }
        return $ended;
    }

    /** A device's "keep" that no longer holds (role changed, deactivated): gone, and noted. */
    public static function dropKeep(Connection $conn, string $hash, int $userId, int $now): void
    {
        $conn->executeStatement('UPDATE active_sessions_seen SET keep = NULL, keep_by = NULL, keep_role = NULL WHERE sid = ?', [$hash]);
        self::log($conn, 'device no longer kept (role changed or deactivated)', $userId, 0, $now);
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
