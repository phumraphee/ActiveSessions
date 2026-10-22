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

        /* how long each sign-in may last (Limits; 0 = no limit): kept on its device, the
           person's own time, else their role's */
        $settings = $this->services->get('Omeka\Settings');
        $choices = Limits::personChoices($conn);
        $ceiling = Limits::ceiling($this->services->get('Config'));
        $rows = [];
        foreach ($all as $h => $s) {
            $uid = (int) $s['user_id'];
            $role = (string) ($users[$uid]['role'] ?? '');
            $keep = Limits::keepHolds($seen[$h] ?? null, $role, (bool) ($users[$uid]['is_active'] ?? false));
            [$limit, $why] = Limits::effective($role, $choices[$uid] ?? null, $keep, $settings, $ceiling);
            $rows[] = ['hash' => $h, 'mine' => hash_equals($h, $mine), 'session' => $s, 'seen' => $seen[$h] ?? null,
                'limit' => $limit, 'why' => $why, 'kept' => $keep];
        }
        /* the people a global admin may give their own time: everybody active, and anybody with one */
        $people = $conn->fetchAllAssociative('SELECT id, name, email, role, is_active FROM user WHERE is_active = 1 OR id IN (?) ORDER BY name',
            [array_keys($choices) ?: [0]], [\Doctrine\DBAL\Connection::PARAM_INT_ARRAY]);
        foreach ($people as $u) {
            $users[(int) $u['id']] = $users[(int) $u['id']] ?? $u;
        }
        $log = array_slice(Limits::history($conn), 0, 30);
        foreach ($log as $l) {
            foreach ([(int) $l['user'], (int) $l['by']] as $id) {
                if ($id > 0 && !isset($users[$id])) {
                    $u = $conn->fetchAssociative('SELECT id, name, email, role, is_active FROM user WHERE id = ?', [$id]);
                    if ($u) {
                        $users[$id] = $u;
                    }
                }
            }
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
            'choices' => $choices,
            'ceiling' => $ceiling,
            'persons' => $people,
            'log' => $log,
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

    /** Keep one sign-in on its device for the longest the server allows, or stop (POST sid, on=1|0). */
    public function keepAction()
    {
        if ($no = $this->guard(true)) {
            return $no;
        }
        $hash = (string) $this->params()->fromPost('sid', '');
        $on = (string) $this->params()->fromPost('on', '') === '1';
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            $this->messenger()->addError('That sign-in was not found. Load the page again.');
            return $this->redirect()->toRoute('admin/active-sessions');
        }
        $store = $this->store();
        $all = $store->signedIn();
        $conn = $this->services->get('Omeka\Connection');
        $u = isset($all[$hash]) ? $conn->fetchAssociative('SELECT id, name, role, is_active FROM user WHERE id = ?', [$all[$hash]['user_id']]) : false;
        if (!$u) {
            $this->messenger()->addWarning('That sign-in had already ended.');
            return $this->redirect()->toRoute('admin/active-sessions');
        }
        if ($on && Limits::ceiling($this->services->get('Config')) <= 0) {
            $this->messenger()->addError('The server sets no session lifetime to keep a device for.');
            return $this->redirect()->toRoute('admin/active-sessions');
        }
        if ($on && !$u['is_active']) {
            $this->messenger()->addError('That person is deactivated: their sign-in cannot be kept.');
            return $this->redirect()->toRoute('admin/active-sessions');
        }
        $uid = $store->keep($hash, $on, (int) $this->identity()->getId(), (string) $u['role']);
        $seen = $store->seen([$hash]);
        $device = isset($seen[$hash]) && $seen[$hash]['user_agent'] !== '' ? \ActiveSessions\Stdlib\Device::describe((string) $seen[$hash]['user_agent']) : 'a sign-in not seen yet';
        $ip = isset($seen[$hash]) ? (string) $seen[$hash]['ip'] : '';
        $ceiling = Limits::ceiling($this->services->get('Config'));
        Limits::log($conn, ($on ? 'device kept for the longest the server allows (' . Limits::words($ceiling) . '): ' : 'device no longer kept: ') . $device . ($ip !== '' ? ' · ' . $ip : ''),
            $uid, (int) $this->identity()->getId(), time());
        $this->messenger()->addSuccess(sprintf($on ? 'Kept signed in: %1$s on %2$s, for %3$s from its sign-in (the longest the server allows).' : 'No longer kept: %1$s on %2$s follows the rule again.',
            (string) $u['name'], $device, Limits::words($ceiling)));
        return $this->redirect()->toRoute('admin/active-sessions');
    }

    /** One person's own time (POST user, limit = role or one of Limits::offered()). */
    public function personAction()
    {
        if ($no = $this->guard(true)) {
            return $no;
        }
        $uid = (int) $this->params()->fromPost('user', 0);
        $choice = (string) $this->params()->fromPost('limit', 'role');
        $conn = $this->services->get('Omeka\Connection');
        $u = $uid > 0 ? $conn->fetchAssociative('SELECT id, name, is_active FROM user WHERE id = ?', [$uid]) : false;
        $ceiling = Limits::ceiling($this->services->get('Config'));
        if (!$u || ($choice !== 'role' && !in_array($choice, Limits::offered($ceiling), true))) {
            $this->messenger()->addError('Choose a person and a time.');
            return $this->redirect()->toRoute('admin/active-sessions');
        }
        $was = Limits::personChoice($conn, $uid);
        if ($choice === 'role') {
            $conn->executeStatement('DELETE FROM user_setting WHERE id = ? AND user_id = ?', [Limits::U_LIMIT, $uid]);
        } elseif ($was === null) {
            $conn->executeStatement('INSERT INTO user_setting (id, user_id, value) VALUES (?, ?, ?)', [Limits::U_LIMIT, $uid, json_encode($choice)]);
        } else {
            $conn->executeStatement('UPDATE user_setting SET value = ? WHERE id = ? AND user_id = ?', [json_encode($choice), Limits::U_LIMIT, $uid]);
        }
        $now = $choice === 'role' ? null : $choice;
        if ($was !== $now) {
            Limits::log($conn, 'person: ' . Limits::choiceWords($was, $ceiling) . ' → ' . Limits::choiceWords($now, $ceiling), $uid, (int) $this->identity()->getId(), time());
        }
        $this->messenger()->addSuccess(sprintf('%1$s: sign-ins last %2$s, from their next click.', (string) $u['name'], Limits::choiceWords($now, $ceiling)));
        return $this->redirect()->toRoute('admin/active-sessions');
    }

    /** Sign out every sign-in of one person, kept devices too (but the admin's own current one). */
    public function endAllAction()
    {
        if ($no = $this->guard(true)) {
            return $no;
        }
        $uid = (int) $this->params()->fromPost('user', 0);
        $me = (int) $this->identity()->getId();
        $n = $uid > 0 ? $this->store()->endAllOf($uid, $me, Sessions::hash((string) session_id())) : 0;
        $conn = $this->services->get('Omeka\Connection');
        $name = (string) $conn->fetchOne('SELECT name FROM user WHERE id = ?', [$uid]);
        if ($n) {
            Limits::log($conn, 'signed out on every device (' . $n . ')', $uid, $me, time());
            $this->messenger()->addSuccess(sprintf('Signed out on every device (%1$d): %2$s.', $n, $name !== '' ? $name : '#' . $uid));
        } else {
            $this->messenger()->addWarning('Nothing to sign out.');
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
