<?php
namespace ActiveSessions;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use ActiveSessions\Stdlib\Limits;
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
 *
 * 0.2: how long a sign-in may last, by role, kept on the server (Stdlib\Limits): everybody
 * 12 hours, the roles chosen in the settings (global_admin) 7 days, by default. A sign-in
 * past its time is ended like one ended from the page. The session's own lifetime
 * (gc_maxlifetime in config/local.config.php) must be at least the longest of these.
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
                $settings = $sm->get('Omeka\Settings');
                $conn = $sm->get('Omeka\Connection');
                $auth = $sm->get('Omeka\AuthenticationService');
                $role = $auth->hasIdentity() && method_exists($auth->getIdentity(), 'getRole') ? (string) $auth->getIdentity()->getRole() : '';
                if (Recorder::run($conn, $auth, $settings)) {
                    $sm->get('ControllerPluginManager')->get('messenger')->addWarning(sprintf(
                        'Your sign-in ended after %s, the time a sign-in lasts here. Please log in again.', // @translate
                        Limits::words(Limits::forRole($role, $settings))
                    ));
                }
                if (PHP_SAPI !== 'cli' && time() - (int) $settings->get(Limits::S_SWEPT, 0) >= Limits::SWEEP_EVERY) {
                    Limits::sweep($conn, $settings, $this->sessionLifetime(), time());
                }
            } catch (\Throwable $t) {
                /* Noting a sign-in must never cost anybody the page. */
                error_log('ActiveSessions: ' . $t->getMessage());
            }
        }, 100);
    }

    public function attachListeners(SharedEventManagerInterface $shared)
    {
        /* the password form, and modules that sign in the same way (they send user.login too) */
        $shared->attach('*', 'user.login', function (Event $event) {
            try {
                $sm = $this->getServiceLocator();
                $user = $event->getParams();
                if (!is_object($user)) {
                    $user = $sm->get('Omeka\AuthenticationService')->getIdentity();
                }
                Recorder::signedIn($sm->get('Omeka\Connection'), $user, $sm->get('Omeka\Settings'));
            } catch (\Throwable $t) {
                error_log('ActiveSessions: ' . $t->getMessage());
            }
        });
    }

    /** Seconds a session lives unused (config gc_maxlifetime, else PHP's). */
    public function sessionLifetime(): int
    {
        $config = $this->getServiceLocator()->get('Config');
        $life = (int) ($config['session']['config']['gc_maxlifetime'] ?? 0);
        return $life > 0 ? $life : (int) ini_get('session.gc_maxlifetime');
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = $this->form();
        $form->setData([
            'short_hours' => round((int) $settings->get(Limits::S_SHORT, Limits::SHORT) / 3600, 2),
            'long_days' => round((int) $settings->get(Limits::S_LONG, Limits::LONG) / 86400, 2),
            'long_roles' => $settings->get(Limits::S_ROLES, Limits::ROLES),
        ]);
        $life = $this->sessionLifetime();
        $longest = max((int) $settings->get(Limits::S_SHORT, Limits::SHORT), (int) $settings->get(Limits::S_LONG, Limits::LONG));
        $note = $life < $longest
            ? sprintf($renderer->translate('Warning: sessions are kept only %s unused (gc_maxlifetime). A sign-in cannot last longer than that, whatever is set here.'), Limits::words($life))
            : sprintf($renderer->translate('Sessions are kept %s unused (gc_maxlifetime): enough for the times below.'), Limits::words($life));
        return '<p>' . $renderer->escapeHtml($note) . '</p>' . $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $form = $this->form();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addFormErrors($form);
            return false;
        }
        $d = $form->getData();
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set(Limits::S_SHORT, max(0, (int) round((float) $d['short_hours'] * 3600)));
        $settings->set(Limits::S_LONG, max(0, (int) round((float) $d['long_days'] * 86400)));
        $settings->set(Limits::S_ROLES, array_values(array_filter((array) ($d['long_roles'] ?? []), 'is_string')));
        return true;
    }

    protected function form(): Form
    {
        $roles = [];
        foreach ($this->getServiceLocator()->get('Omeka\Acl')->getRoleLabels() as $role => $label) {
            $roles[$role] = $label;
        }
        $form = new Form('activesessions');
        $form->add(['name' => 'short_hours', 'type' => Element\Number::class, 'attributes' => ['min' => 0, 'step' => 'any'],
            'options' => ['label' => 'Sign-in lasts (hours), for everybody', // @translate
                'info' => 'After this, the person must log in again, even if the cookie was kept. 0 = no limit.']]); // @translate
        $form->add(['name' => 'long_days', 'type' => Element\Number::class, 'attributes' => ['min' => 0, 'step' => 'any'],
            'options' => ['label' => 'Sign-in lasts (days), for the roles below', // @translate
                'info' => '0 = no limit.']]); // @translate
        $form->add(['name' => 'long_roles', 'type' => Element\MultiCheckbox::class,
            'options' => ['label' => 'Roles with the longer sign-in', 'value_options' => $roles]]); // @translate
        $form->getInputFilter()->get('long_roles')->setRequired(false);
        return $form;
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
                since INT DEFAULT NULL,
                PRIMARY KEY(sid),
                INDEX active_sessions_seen_user (user_id),
                INDEX active_sessions_seen_ended (ended)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB'
        );
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $services)
    {
        $this->install($services);
        /* 0.2.0: the clock of each sign-in (Stdlib\Limits). Left empty: every sign-in that
           exists now starts its clock when it is next seen, so nobody is ended at once. */
        $conn = $services->get('Omeka\Connection');
        $has = $conn->fetchOne("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'active_sessions_seen' AND COLUMN_NAME = 'since'");
        if (!(int) $has) {
            $conn->executeStatement('ALTER TABLE active_sessions_seen ADD since INT DEFAULT NULL');
        }
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        /* Omeka does not load a module's classes while it uninstalls it */
        require_once __DIR__ . '/src/Stdlib/Limits.php';
        /* Nothing in it is needed once the module is gone: Omeka's own sign-ins go on as before. */
        $services->get('Omeka\Connection')->executeStatement('DROP TABLE IF EXISTS active_sessions_seen');
        foreach ([Limits::S_SHORT, Limits::S_LONG, Limits::S_ROLES, Limits::S_SWEPT] as $id) {
            $services->get('Omeka\Settings')->delete($id);
        }
    }
}
