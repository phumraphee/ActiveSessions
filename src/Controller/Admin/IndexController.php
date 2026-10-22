<?php
namespace ActiveSessions\Controller\Admin;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Psr\Container\ContainerInterface;
use ActiveSessions\Stdlib\Limits;
use ActiveSessions\Stdlib\Sessions;

class IndexController extends AbstractActionController
{
    /** @var ContainerInterface */
    protected $services;

    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    public function indexAction()
    {
        if ($no = $this->guard()) {
            return $no;
        }
        $store = $this->store();
        $store->forgetOld();
        $all = $store->signedIn();
        $seen = $store->seen(array_keys($all));
        $mine = Sessions::hash((string) session_id());
        $conn = $this->services->get('Omeka\Connection');

        $ended = $store->endedLately();
        $uids = array_unique(array_map('intval', array_merge(array_column($all, 'user_id'), array_column($ended, 'user_id'), array_column($ended, 'ended_by'))));
        $users = [];
        if ($uids) {
            foreach ($conn->fetchAllAssociative('SELECT id, name, email, role, is_active FROM user WHERE id IN (?)', [array_values($uids)], [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]) as $u) {
                $users[(int) $u['id']] = $u;
            }
        }
        /* For a sign-in not seen since recording began: where this person last signed in,
           from Revision History when it is there. It is the person's, not this session's,
           and the page says so. */
        $lastSignIn = [];
        $notSeen = [];
        foreach ($all as $h => $s) {
            if (!isset($seen[$h])) {
                $notSeen[$s['user_id']] = true;
            }
        }
        if ($notSeen && $this->hasTable('revision_history_event')) {
            foreach (array_keys($notSeen) as $uid) {
                $r = $conn->fetchAssociative(
                    "SELECT ip, happened_at FROM revision_history_event WHERE resource_kind = 'user' AND action = 'signed in' AND user_id = ? ORDER BY id DESC LIMIT 1",
                    [$uid]
                );
                if ($r) {
                    $lastSignIn[$uid] = $r;
                }
            }
        }

        /* how long each sign-in may last, by its person's role (Limits; 0 = no limit) */
        $settings = $this->services->get('Omeka\Settings');
        $rows = [];
        foreach ($all as $h => $s) {
            $limit = Limits::forRole((string) ($users[(int) $s['user_id']]['role'] ?? ''), $settings);
            $rows[] = ['hash' => $h, 'mine' => hash_equals($h, $mine), 'session' => $s, 'seen' => $seen[$h] ?? null, 'limit' => $limit];
        }
        $longRoles = $settings->get(Limits::S_ROLES, Limits::ROLES);
        usort($rows, function ($a, $b) {
            return [$b['mine'], $b['session']['modified']] <=> [$a['mine'], $a['session']['modified']];
        });

        return new ViewModel([
            'rows' => $rows,
            'users' => $users,
            'lastSignIn' => $lastSignIn,
            'ended' => $ended,
            'lifetime' => $store->lifetime(),
            'limits' => [
                'short' => Limits::forRole('', $settings),
                'long' => is_array($longRoles) && $longRoles ? Limits::forRole((string) reset($longRoles), $settings) : 0,
                'roles' => is_array($longRoles) ? array_values(array_filter($longRoles, 'is_string')) : [],
            ],
            'form' => $this->form(),
            'tz' => $this->timeZone(),
            'me' => $this->identity(),
            /* Omeka keeps sessions in its `session` table unless a save handler is set in
               local.config.php; then there is nothing here to read. */
            'otherHandler' => (string) ($this->services->get('Config')['session']['save_handler'] ?? ''),
        ]);
    }

    public function endAction()
    {
        if ($no = $this->guard(true)) {
            return $no;
        }
        $hash = (string) $this->params()->fromPost('sid', '');
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            $this->messenger()->addError('That sign-in was not found. Load the page again.');
            return $this->redirect()->toRoute('admin/active-sessions');
        }
        if (hash_equals($hash, Sessions::hash((string) session_id()))) {
            /* The page shows no button here; this is for a form sent some other way. */
            $this->messenger()->addError('That is the sign-in you are using now. To end it, use Log out.');
            return $this->redirect()->toRoute('admin/active-sessions');
        }
        $uid = $this->store()->end($hash, (int) $this->identity()->getId());
        if (!$uid) {
            $this->messenger()->addWarning('That sign-in had already ended.');
        } else {
            $name = (string) $this->services->get('Omeka\Connection')->fetchOne('SELECT name FROM user WHERE id = ?', [$uid]);
            $this->messenger()->addSuccess(sprintf('Signed out: %s. Their next click goes to the login page.', $name !== '' ? $name : '#' . $uid));
        }
        return $this->redirect()->toRoute('admin/active-sessions');
    }

    /* ================================================================= helpers */

    protected function guard(bool $post = false)
    {
        $me = $this->identity();
        if (!$me || $me->getRole() !== 'global_admin') {
            return $this->notFound();
        }
        if ($post) {
            if (!$this->getRequest()->isPost()) {
                return $this->redirect()->toRoute('admin/active-sessions');
            }
            $form = $this->form();
            $form->setData($this->params()->fromPost());
            if (!$form->isValid()) {
                $this->messenger()->addError('The page was open too long, or came from somewhere else. Load it again and retry.');
                return $this->redirect()->toRoute('admin/active-sessions');
            }
        }
        return null;
    }

    protected function notFound()
    {
        $this->getResponse()->setStatusCode(404);
        return (new ViewModel())->setTemplate('error/404');
    }

    protected function form(): Form
    {
        $form = new Form('active_sessions');
        $form->add((new Element\Csrf('active_sessions_csrf'))->setCsrfValidatorOptions(['timeout' => 3600]));
        return $form;
    }

    protected function store(): Sessions
    {
        $config = $this->services->get('Config');
        $life = (int) ($config['session']['config']['gc_maxlifetime'] ?? 0);
        if ($life <= 0) {
            $life = (int) ini_get('session.gc_maxlifetime');
        }
        return new Sessions($this->services->get('Omeka\Connection'), $life);
    }

    protected function timeZone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) $this->settings()->get('time_zone', 'UTC'));
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    protected function hasTable(string $name): bool
    {
        return (bool) $this->services->get('Omeka\Connection')->fetchOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$name]
        );
    }
}
