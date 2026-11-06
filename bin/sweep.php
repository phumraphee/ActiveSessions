<?php
/**
 * sweep — end every sign-in past its time and forget old records, from cron (0.3.1, 2026-11-02).
 *
 * The same Limits::sweep() Omeka runs every 5 minutes on a routed request. On a quiet day no
 * request comes, and pages outside Omeka that read the `session` table (a site's own staff
 * pages) took a sign-in past its time for a working one until somebody opened an Omeka page.
 * Also Sessions::forgetOld(), which otherwise runs only when the sessions page is opened, and
 * (0.4.0) Sessions::deleteOld(): `session` rows unused longer than a session lives, which PHP
 * leaves in the table when its own clean-up is off (session.gc_probability = 0).
 *
 *   php modules/ActiveSessions/bin/sweep.php
 *
 * crontab, as the web server's user:
 *   * /5 * * * * php <omeka>/modules/ActiveSessions/bin/sweep.php >> <omeka>/logs/active-sessions.log 2>&1
 *
 * Silent when nothing was ended, forgotten or deleted; otherwise one line. Module not active: nothing.
 * Exit 1 on an error. A sweep run here counts as the web's (activesessions_last_sweep), so the
 * web one waits its 5 minutes after it.
 */
use ActiveSessions\Stdlib\Limits;
use ActiveSessions\Stdlib\Sessions;

error_reporting(E_ALL & ~E_DEPRECATED);
try {
    chdir(dirname(__DIR__, 3));
    require 'bootstrap.php';
    $app = Omeka\Mvc\Application::init(require 'application/config/application.config.php');
    $sm = $app->getServiceManager();
    $module = $sm->get('ModuleManager')->getModule('ActiveSessions');
    if (!$module) {
        exit(0);
    }
    $conn = $sm->get('Omeka\Connection');
    $life = $module->sessionLifetime();
    $ended = Limits::sweep($conn, $sm->get('Omeka\Settings'), $life, time(), true, Limits::ceiling($sm->get('Config')));
    $store = new Sessions($conn, $life);
    $forgotten = $store->forgetOld();
    $deleted = $store->deleteOld();
    if ($ended || $forgotten || $deleted) {
        echo gmdate('c'), " sweep: $ended sign-in(s) ended by time, $forgotten old record(s) forgotten, $deleted unused session row(s) deleted\n";
    }
} catch (\Throwable $t) {
    fwrite(STDERR, gmdate('c') . ' sweep: ' . $t->getMessage() . "\n");
    exit(1);
}
