<?php
namespace ActiveSessions\Stdlib;

use Doctrine\DBAL\Connection;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Authentication\Storage\Session as SessionStorage;

/**
 * On every request of somebody signed in: note the address and the browser of this
 * sign-in in active_sessions_seen, and sign it out if it was ended from the sessions page.
 *
 * One primary-key read per signed-in request (staff only: visitors are not signed in and
 * cost nothing), and a write at most once a minute per sign-in, or at once when the
 * address, the browser or the person changed.
 *
 * Only a sign-in held in the SESSION counts: a request signed with an API key has its
 * identity in storage that is not the session's, and is left alone.
 */
class Recorder
{
    const EVERY = 60;

    /**
     * @param mixed $settings Omeka's settings (the time limits, Limits); null = no limits
     * @return bool true when this request's sign-in was just ended by its time limit
     */
    public static function run(Connection $conn, AuthenticationServiceInterface $auth, $settings = null, ?int $now = null): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        /* Omeka wraps the storage in a DoctrineWrapper, around Session for a sign-in and
           around NonPersistent for a request that signs with an API key. */
        $storage = $auth->getStorage();
        if (method_exists($storage, 'getStorage')) {
            $storage = $storage->getStorage();
        }
        if (!($storage instanceof SessionStorage)) {
            return false;
        }
        /* hasIdentity() reads the session, which starts it; only then is there an id. */
        if (!$auth->hasIdentity() || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $sid = session_id();
        if (!is_string($sid) || $sid === '') {
            return false;
        }
        $user = $auth->getIdentity();
        $uid = is_object($user) && method_exists($user, 'getId') ? (int) $user->getId() : 0;
        if ($uid <= 0) {
            return false;
        }
        $hash = Sessions::hash($sid);
        $row = $conn->fetchAssociative('SELECT user_id, ip, user_agent, last_seen, ended, since FROM active_sessions_seen WHERE sid = ?', [$hash]);
        if ($row && $row['ended'] !== null) {
            /* Ended from the sessions page, and come back: a request that was still running
               when the row was deleted wrote it again. Sign it out now. The empty Laminas_Auth
               is what gets written back at the end of this request. */
            $auth->clearIdentity();
            return false;
        }
        $now = $now ?? time();
        /* The time limit (Limits): a sign-in this rule has not seen yet starts its clock now. */
        $limit = $settings !== null && method_exists($user, 'getRole') ? Limits::forRole((string) $user->getRole(), $settings) : 0;
        $since = $row ? (int) $row['since'] : 0;
        if ($settings !== null && Limits::over($since, $limit, $now)) {
            Limits::endOne($conn, $hash, $uid, $now);
            $auth->clearIdentity();
            return true;
        }
        $ip = Where::ip();
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        if ($row && $since > 0 && (int) $row['user_id'] === $uid && $row['ip'] === $ip && $row['user_agent'] === $ua
            && $now - (int) $row['last_seen'] < self::EVERY) {
            return false;
        }
        $conn->executeStatement(
            'INSERT INTO active_sessions_seen (sid, user_id, first_ip, ip, user_agent, first_seen, last_seen, since)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), ip = VALUES(ip),'
            . ' user_agent = VALUES(user_agent), last_seen = VALUES(last_seen), since = COALESCE(since, VALUES(since))',
            [$hash, $uid, $ip, $ip, $ua, $now, $now, $now]
        );
        /* once a minute at most, like the row: the cookie ends when the sign-in ends */
        Limits::cookie($since > 0 ? $since : $now, $limit);
        return false;
    }

    /**
     * Somebody has just signed in (Omeka's user.login, also sent by modules that sign in
     * the same way): this new session's clock starts now, and its cookie ends with it.
     */
    public static function signedIn(Connection $conn, $user, $settings, ?int $now = null): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || !is_object($user) || !method_exists($user, 'getId')) {
            return;
        }
        $sid = session_id();
        if (!is_string($sid) || $sid === '') {
            return;
        }
        $now = $now ?? time();
        $ip = Where::ip();
        $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $conn->executeStatement(
            'INSERT INTO active_sessions_seen (sid, user_id, first_ip, ip, user_agent, first_seen, last_seen, since)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), ip = VALUES(ip), user_agent = VALUES(user_agent),'
            . ' last_seen = VALUES(last_seen), since = VALUES(since), ended = NULL, ended_by = NULL',
            [Sessions::hash($sid), (int) $user->getId(), $ip, $ip, $ua, $now, $now, $now]
        );
        Limits::cookie($now, Limits::forRole(method_exists($user, 'getRole') ? (string) $user->getRole() : '', $settings));
    }
}
