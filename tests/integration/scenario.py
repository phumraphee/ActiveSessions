#!/usr/bin/env python3
"""Signing in, the list, and ending a sign-in, through a real Omeka S over HTTP.
See README.md for the server this expects.

  - only a global admin gets the page; a site admin gets 404, a visitor the login page
  - each signed-in request notes address, browser and person; at most once a minute,
    at once when the address or browser changes; the first address is kept
  - the address behind a proxy: CF-Connecting-IP, else X-Forwarded-For
  - a visitor's session is not noted
  - the list shows every working sign-in, marks the admin's own with no button
  - "Sign out": needs the page's token, refuses the admin's own sign-in, ends another,
    which then goes to the login page; and if a request still running puts the session
    row back, the next request is signed out all the same

  python3 scenario.py
Environment: OMEKA_URL (default http://127.0.0.1:8080), MYSQL (the mysql command line
client with any options, default "mysql"), MYSQL_DB (default omeka), PHP_ERROR_LOG
(optional: PHP's error log, checked for lines from this module).
"""
import hashlib, os, re, shlex, subprocess, sys, urllib.error, urllib.parse, urllib.request

B = os.environ.get('OMEKA_URL', 'http://127.0.0.1:8080').rstrip('/')
MYSQL = shlex.split(os.environ.get('MYSQL', 'mysql'))
DB = os.environ.get('MYSQL_DB', 'omeka')
LOG = os.environ.get('PHP_ERROR_LOG', '')
passed = failed = 0

MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'
IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
EDGE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0'


def sql(q):
    r = subprocess.run(MYSQL + ['-N', '-B', '--raw', DB, '-e', q],
                       capture_output=True, text=True)
    if r.returncode:
        sys.exit('sql: ' + r.stderr)
    return r.stdout.strip()


def t(name, ok, more=''):
    global passed, failed
    if ok:
        passed += 1
    else:
        failed += 1
        print('FAIL ' + name + (('\n     ' + str(more)[:1200]) if more else ''))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


opener = urllib.request.build_opener(NoRedirect)


def req(path, sid=None, ua=MAC, headers=None, data=None):
    h = {'User-Agent': ua}
    if sid:
        h['Cookie'] = '%s=%s' % (CNAME, sid)
    h.update(headers or {})
    body = urllib.parse.urlencode(data).encode() if data is not None else None
    r = urllib.request.Request(B + path, data=body, headers=h, method='POST' if body is not None else 'GET')
    try:
        with opener.open(r, timeout=60) as resp:
            return resp.status, resp.read().decode('utf-8', 'replace'), resp.headers.get('Location', '')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers.get('Location', '')


def blob(uid):
    return '__Laminas|a:0:{}Laminas_Auth|a:1:{s:7:"storage";i:%s;}Omeka|a:0:{}' % uid


def put_session(sid, uid):
    sql("REPLACE INTO session VALUES ('%s','%s',UNIX_TIMESTAMP())" % (sid, blob(uid).replace("'", "''")))


def sha(s):
    return hashlib.sha256(s.encode()).hexdigest()


def seen(sid, cols='user_id, first_ip, ip, user_agent, last_seen, ended, ended_by'):
    return sql("SELECT %s FROM active_sessions_seen WHERE sid='%s'" % (cols, sha(sid))).split('\t')


def token(sid):
    s, body, _ = req('/admin/active-sessions', sid)
    m = re.search(r'name="active_sessions_csrf" value="([^"]+)"', body)
    return m.group(1) if m else ''


# Session ids of [0-9a-v] only (Omeka's 5 bits per character), or Laminas quietly starts a new session.
ADM = 'tasadmin000000000000000001'
SITE = 'tassite0000000000000000001'
SITE2 = 'tassite0000000000000000002'
VIS = 'tasvisit000000000000000001'
# a visitor whose session holds the same shape under ANOTHER name than Laminas_Auth
LOOK = 'tasvisit000000000000000002'
PRE = 'tas'

if sql("SELECT is_active FROM module WHERE id='ActiveSessions'") != '1':
    sys.exit('ActiveSessions is not installed and active in ' + DB)
admin = sql("SELECT id FROM user WHERE role='global_admin' AND is_active=1 ORDER BY id LIMIT 1")
site = sql("SELECT id FROM user WHERE role='site_admin' AND is_active=1 ORDER BY id LIMIT 1")
CNAME = None
with urllib.request.urlopen(B + '/login') as r:
    for hd in r.headers.get_all('Set-Cookie') or []:
        CNAME = hd.split('=')[0]


def clean():
    sql("DELETE FROM session WHERE id LIKE '%s%%'" % PRE)
    for s in (ADM, SITE, SITE2, VIS, LOOK):
        sql("DELETE FROM active_sessions_seen WHERE sid='%s'" % sha(s))


log0 = os.path.getsize(LOG) if LOG and os.path.exists(LOG) else 0
clean()
try:
    put_session(ADM, admin)
    put_session(SITE, site)
    put_session(SITE2, site)
    sql("REPLACE INTO session VALUES ('%s','__Laminas|a:0:{}Omeka|a:0:{}',UNIX_TIMESTAMP())" % VIS)
    sql("REPLACE INTO session VALUES ('%s','__Laminas|a:0:{}Laminas_Auth|O:26:\"Laminas\\\\Stdlib\\\\ArrayObject\":4:{s:7:\"storage\";a:0:{}}"
        "Other|O:26:\"Laminas\\\\Stdlib\\\\ArrayObject\":4:{s:7:\"storage\";a:1:{s:7:\"storage\";i:%s;}}',UNIX_TIMESTAMP())" % (LOOK, admin))

    # ------------------------------------------------------------ who may come in
    s, _, _ = req('/admin/active-sessions', SITE)
    t('a site admin gets 404', s == 404, s)
    s, _, loc = req('/admin/active-sessions')
    t('a visitor is sent to the login page', s == 302 and '/login' in loc, (s, loc))
    s, body, _ = req('/admin/active-sessions', ADM)
    t('a global admin gets the page', s == 200 and 'Signed-in sessions' in body, s)

    # ------------------------------------------------------------ recording
    # the 404 above was a signed-in request of SITE's too: start its record afresh
    sql("DELETE FROM active_sessions_seen WHERE sid='%s'" % sha(SITE))
    s, _, _ = req('/admin', SITE, IPHONE, {'X-Forwarded-For': '198.51.100.7'})
    row = seen(SITE)
    t('a signed-in request is noted: person, address, browser',
      row[:4] == [site, '198.51.100.7', '198.51.100.7', IPHONE], row)
    req('/admin', VIS)
    t('a visitor is not noted', sql("SELECT COUNT(*) FROM active_sessions_seen WHERE sid='%s'" % sha(VIS)) == '0')
    req('/admin', SITE2, EDGE, {'CF-Connecting-IP': '192.0.2.44', 'X-Forwarded-For': '10.0.0.1'})
    t('CF-Connecting-IP is preferred to X-Forwarded-For', seen(SITE2)[2] == '192.0.2.44', seen(SITE2))

    sql("UPDATE active_sessions_seen SET last_seen=UNIX_TIMESTAMP()-30 WHERE sid='%s'" % sha(SITE))
    before = seen(SITE)[4]
    req('/admin', SITE, IPHONE, {'X-Forwarded-For': '198.51.100.7'})
    t('within a minute, the same address and browser are not written again', seen(SITE)[4] == before, (before, seen(SITE)))
    sql("UPDATE active_sessions_seen SET last_seen=UNIX_TIMESTAMP()-61 WHERE sid='%s'" % sha(SITE))
    before = seen(SITE)[4]
    req('/admin', SITE, IPHONE, {'X-Forwarded-For': '198.51.100.7'})
    t('after a minute it is written again', int(seen(SITE)[4]) > int(before), (before, seen(SITE)))
    req('/admin', SITE, IPHONE, {'X-Forwarded-For': '198.51.100.99'})
    row = seen(SITE)
    t('a new address is written at once, and the first one kept', row[1] == '198.51.100.7' and row[2] == '198.51.100.99', row)

    # ------------------------------------------------------------ the list
    s, body, _ = req('/admin/active-sessions', ADM)
    mine = re.search(r'<tr class="me" data-sid="%s">(.*?)</tr>' % sha(ADM), body, re.S)
    t('the admin\'s own sign-in is marked and has no button',
      bool(mine) and 'you, this browser' in mine.group(1) and 'name="sid"' not in mine.group(1), mine and mine.group(1)[:600])
    other = re.search(r'<tr class="" data-sid="%s">(.*?)</tr>' % sha(SITE), body, re.S)
    t('another sign-in is listed with its device, address and a button',
      bool(other) and 'Safari 18 on iPhone' in other.group(1) and '198.51.100.99' in other.group(1)
      and 'first seen from' in other.group(1) and 'value="%s"' % sha(SITE) in other.group(1), other and other.group(1)[:800])
    t('a visitor is not listed', sha(VIS) not in body)
    t('a visitor with a user id under another name than Laminas_Auth is not listed', sha(LOOK) not in body)
    t('Edge is said as Edge', 'Edge 141 on Windows' in body)

    # ------------------------------------------------------------ ending one
    s, _, _ = req('/admin/active-sessions/end', ADM, data={'sid': sha(SITE)})
    t('no token: nothing ends', sql("SELECT COUNT(*) FROM session WHERE id='%s'" % SITE) == '1')
    s, _, _ = req('/admin/active-sessions/end', ADM, data={'sid': sha(SITE), 'active_sessions_csrf': 'forged'})
    t('a wrong token: nothing ends', sql("SELECT COUNT(*) FROM session WHERE id='%s'" % SITE) == '1')
    s, _, _ = req('/admin/active-sessions/end', SITE2, EDGE, {'CF-Connecting-IP': '192.0.2.44', 'X-Forwarded-For': '10.0.0.1'},
                  data={'sid': sha(SITE), 'active_sessions_csrf': token(ADM)})
    t('a site admin cannot end anything', s == 404 and sql("SELECT COUNT(*) FROM session WHERE id='%s'" % SITE) == '1', s)
    s, _, _ = req('/admin/active-sessions/end', ADM, data={'sid': sha(ADM), 'active_sessions_csrf': token(ADM)})
    s2, body, _ = req('/admin/active-sessions', ADM)
    t('the admin\'s own sign-in is refused, and still works',
      sql("SELECT COUNT(*) FROM session WHERE id='%s'" % ADM) == '1' and s2 == 200 and 'you are using now' in body, (s2, body[:300]))
    s, _, _ = req('/admin/active-sessions/end', ADM, data={'sid': '0' * 64, 'active_sessions_csrf': token(ADM)})
    s2, body, _ = req('/admin/active-sessions', ADM)
    t('an unknown sign-in: told so, nothing ends',
      'already ended' in body and sql("SELECT COUNT(*) FROM session WHERE id LIKE '%s%%'" % PRE) == '5', body[:300])

    s, _, loc = req('/admin/active-sessions/end', ADM, data={'sid': sha(SITE), 'active_sessions_csrf': token(ADM)})
    t('a real end: the session row is gone', sql("SELECT COUNT(*) FROM session WHERE id='%s'" % SITE) == '0', (s, loc))
    row = seen(SITE)
    t('...it is marked ended, by whom', row[5] != 'NULL' and row[6] == admin, row)
    t('...the other sign-in of the same person is untouched', sql("SELECT COUNT(*) FROM session WHERE id='%s'" % SITE2) == '1')
    s, body, _ = req('/admin/active-sessions', ADM)
    t('...the page says so, and lists it under ended', 'Signed out:' in body and 'Ended from this page' in body
      and re.search(r'Ended from this page.*198\.51\.100\.99', body, re.S), re.sub(r'\s+', ' ', re.sub('<[^>]+>', ' ', body[body.find('Ended from'):]))[:800])
    s, _, loc = req('/admin', SITE, IPHONE)
    t('...its next click goes to the login page', s == 302 and '/login' in loc, (s, loc))

    # A request of that person's still running when the row went writes it back as it
    # ends (Omeka's save handler upserts). The next request must still be signed out.
    put_session(SITE, site)
    s, _, loc = req('/admin', SITE, IPHONE)
    t('a session written back after the end is signed out on its next request', s == 302 and '/login' in loc, (s, loc))
    data = sql("SELECT data FROM session WHERE id='%s'" % SITE)
    t('...and holds nobody afterwards', 'storage";i:' not in data, data[:300])
    s, body, _ = req('/admin/active-sessions', ADM)
    t('...and is not listed', 'data-sid="%s"' % sha(SITE) not in body)

finally:
    clean()

if LOG and os.path.exists(LOG):
    with open(LOG, errors='replace') as f:
        f.seek(log0)
        new = [l for l in f.read().splitlines() if 'ActiveSessions' in l]
    t('no PHP warning or error from ActiveSessions', not new, new[:3])

print('scenario: %d passed, %d failed' % (passed, failed))
sys.exit(1 if failed else 0)
