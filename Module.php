<?php
namespace ActiveSessions;

use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;
use ActiveSessions\Stdlib\Recorder;

/**
 * WHO IS SIGNED IN, FROM WHERE, ON WHAT — AND A WAY TO END IT.
 *
 * The longer a sign-in lasts, the longer a lost phone or a shared computer stays signed
 * in. A site that keeps people signed in for weeks needs a way to see every sign-in and
 * to stop one.
 *
 * Omeka's `session` table knows none of it (id, data, modified only), and the web server's
 * own log does not say which request belonged to whom. So this module notes, on the
 * signed-in person's own requests, the address and the browser of each sign-in
 * (Stdlib\Recorder → active_sessions_seen), and /admin/active-sessions lists every sign-in
 * that still works with what is known of it, with a button to end it. Global admin only.
 *
 * Sign-ins that began before the module was installed show up at once (the list comes
 * from `session`), and get their address and browser on their next request.
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);
        /* Priority 100: before routing and before Omeka's own route listeners, so a sign-in
           ended from the page is gone before Omeka decides who may come in. */
        $event->getApplication()->getEventManager()->attach(MvcEvent::EVENT_ROUTE, function (MvcEvent $e) {
            try {
                $sm = $e->getApplication()->getServiceManager();
                Recorder::run($sm->get('Omeka\Connection'), $sm->get('Omeka\AuthenticationService'));
            } catch (\Throwable $t) {
                /* Noting a sign-in must never cost anybody the page. */
                error_log('ActiveSessions: ' . $t->getMessage());
            }
        }, 100);
    }

    public function install(ServiceLocatorInterface $services)
    {
        /* Times are Unix seconds, like session.modified, so the two compare without a time zone. */
        $services->get('Omeka\Connection')->executeStatement(
            'CREATE TABLE IF NOT EXISTS active_sessions_seen (
                sid CHAR(64) NOT NULL,
                user_id INT NOT NULL,
                first_ip VARCHAR(45) NOT NULL DEFAULT \'\',
                ip VARCHAR(45) NOT NULL DEFAULT \'\',
                user_agent VARCHAR(500) NOT NULL DEFAULT \'\',
                first_seen INT NOT NULL,
                last_seen INT NOT NULL,
                ended INT DEFAULT NULL,
                ended_by INT DEFAULT NULL,
                PRIMARY KEY(sid),
                INDEX active_sessions_seen_user (user_id),
                INDEX active_sessions_seen_ended (ended)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $this->install($services);
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        /* Nothing in it is needed once the module is gone: Omeka's own sign-ins go on as before. */
        $services->get('Omeka\Connection')->executeStatement('DROP TABLE IF EXISTS active_sessions_seen');
    }
}
