# Active Sessions

**Active Sessions** is an Omeka S module that shows **who is signed in** to the admin side
right now — from which **address**, on which **device and browser**, when each sign-in began
and when it was last used — and lets a global admin **sign any of them out** (a lost phone,
a shared computer, someone who has left).

![Active Sessions: every sign-in that still works, with device, address and a Sign out button](https://raw.githubusercontent.com/phumraphee/ActiveSessions/main/docs/images/list.png)

- Omeka S 4.x · no other module required
- Works with [Revision History](https://github.com/phumraphee/RevisionHistory) when it is
  installed (see below)
- Licence: GPL-3.0-or-later (see `LICENSE`)
- Issues and questions: https://github.com/phumraphee/ActiveSessions/issues

## Screenshots

**After Sign out** — the person's next click goes to the login page, and the sign-in is
listed under *Ended from this page* with who ended it:

![The page after signing a session out, with the record of sign-ins ended from it](https://raw.githubusercontent.com/phumraphee/ActiveSessions/main/docs/images/ended.png)

All screenshots are from a demo site with made-up people. The addresses are from the
ranges set aside for documentation.

## Where to find it

**Admin → Modules → Signed-in sessions** (`/admin/active-sessions`). Global admin only: any other
role gets *page not found*, as if the page did not exist, and a visitor the login page.

- Every sign-in that still works, yours first, then most recently used.
- *Who* — name, email and role. *Device* — the browser and system in words ("Safari 18 on
  iPhone"); hover for the browser's own description. *Address* — the latest one, and the first
  one when it has changed. *Signed in* — when this module first saw that sign-in. *Last used* —
  and when it will end by itself if nobody uses it.
- **Sign out** on every row but your own current one (for that, use Log out). The browser
  asks once before it is sent.
- *Ended from this page (last 30 days)* — when, whose, device, address, and by whom.

## Where the list comes from

**Omeka's own `session` table.** Omeka keeps every PHP session in the database by default,
one row per session (id, data, last modified). A signed-in session is a row whose data holds
an account id under `Laminas_Auth`; everything else is a visitor. The list is read from there,
so a row there is a sign-in that still works, and nothing else is.

**This module does not work if sessions are kept anywhere else.** If
`config/local.config.php` sets a `save_handler` under `session` (files, Redis, Memcached…),
the `session` table is not used and there is nothing for this page to read. The page says so
at the top. Sessions in the table that have not been used for longer than the session lifetime
(`session.config.gc_maxlifetime` in `local.config.php`, otherwise PHP's
`session.gc_maxlifetime`) are not shown: PHP would throw them away anyway.

`session` has no address and no browser. Those come from this module's own table,
`active_sessions_seen`, written on the signed-in person's own requests:

- on every request of somebody signed in, one read by primary key; visitors cost nothing
- a write at most once a minute per sign-in, or at once when the address, the browser or the
  account changed; the first address is kept beside the latest
- requests signed with an API key are not sessions and are left alone
- sign-ins that began before the module was installed are listed at once, as *not seen yet*,
  and get their address and browser on their next request; their *Signed in* says *before
  this list began recording*

Nothing here can cost anybody a page: a failure is written to PHP's error log and the request
goes on.

## Session ids are kept as a hash

`active_sessions_seen` is keyed by the **sha256 of the session id**, never the id itself. The
id is all it takes to be that person, and a module's table gets exported, copied and backed up
on its own; a hash in it lets nobody sign in as anybody. The page and its forms carry only the
hash too.

## Which address is recorded

- When the web server sees a **public** address, that address is kept, and the
  `CF-Connecting-IP` and `X-Forwarded-For` headers are ignored: anyone could have written them.
- When the web server sees a **private** address (10.x, 172.16–31.x, 192.168.x, and the like),
  the request is taken to have come through a reverse proxy such as Cloudflare or a proxy on
  the same network. The visitor's address is then read from `CF-Connecting-IP`, or else from
  the first address in `X-Forwarded-For`.

If your Omeka is reached **directly on a local network, with no proxy in front of it**, a
computer on that network can send those headers itself and have any address written down.
The address is then only a hint. This is the same rule Revision History uses, copied rather
than shared so that each works without the other.

## How long a sign-in lasts (0.2)

A sign-in ends after a set time, by role: **everybody 12 hours**, the roles you choose
(**global admin**) **7 days** — both in the module's settings (0 = no limit). The time is kept
on the server, not only in the cookie: each sign-in's clock (`since` in `active_sessions_seen`)
starts when the person logs in (Omeka's `user.login` event — the password form and modules that
sign in the same way), and

- on each of the person's requests, a sign-in past its time is signed out there and then (the
  login page says why);
- every few minutes, on anybody's request, every sign-in past its time is ended and its session
  row deleted, so pages outside Omeka that read the `session` table see it gone too;
- the session cookie is sent again to expire when the sign-in ends; a cookie kept longer by hand
  changes nothing.

Sign-ins that began before 0.2 (or before a change of the settings' roles) start their clock
when first seen, so installing or upgrading never signs everybody out at once. The sessions page
lists sign-ins ended this way with "Time limit" as who ended them. Its line at the top says the
rule as set, and each sign-in says when it ends: its time limit, or unused, whichever comes first
("time limit starts at its next use" for one not seen since).

**The session must live as long as the longest limit**: `gc_maxlifetime` in
`config/local.config.php` (`'session' => ['config' => [...]]`) at least 7 days, or a sign-in
ends when PHP throws its session away; the settings page warns when it is shorter.

## Signing a session out

**Sign out** marks the sign-in ended in `active_sessions_seen` first, then deletes its row
from `session`. The person's next click finds no session and goes to the login page. Their
other sign-ins (another browser, another device) are not touched.

**A row that is written back.** Omeka saves a session as each request ends, with
`INSERT … ON DUPLICATE KEY UPDATE`. So a request of that person's that was still running when
the row was deleted puts it back as it finishes, still signed in. The mark is what catches
that: on that browser's next request this module sees the sign-in was ended, signs it out
there and then, and that request goes to the login page. Until that next request the
written-back sign-in can appear in the list again; ending it again does no harm.

The form carries Omeka's own kind of token (valid for an hour), so a page from somewhere else
cannot end a sign-in. A global admin cannot end their own current sign-in here.

## With Revision History

Optional. When [Revision History](https://github.com/phumraphee/RevisionHistory) is installed,
a sign-in this module has not seen yet shows where **that person** last signed in, read from
Revision History's record of sign-ins (`revision_history_event`). It is the person's last
sign-in, not necessarily this session's, and the page says so. Without Revision History that
column just says *not seen yet*.

## Personal data

`active_sessions_seen` holds, per sign-in: the account id, the hash of the session id, the
first and the latest address, the browser's description, when it was first and last seen, and
— when it was ended from the page — when and by which account. Nothing is sent anywhere.
Rows are removed a week after their sign-in can no longer be alive (whenever the page is
opened). If your site must answer requests to remove personal data, include this table.

## Uninstall

Drops `active_sessions_seen`. Omeka's own sessions are not touched: everybody stays signed in.

## Requirements

Omeka S 4.x, sessions in the database (Omeka's default). PHP 7.4+. MySQL 8 or MariaDB.

## Tests

- `php tests/run.php` — reading who a session holds from its data, the hash, browsers in
  words. No Omeka needed.
- `tests/integration/` — who may open the page, what is recorded and when, the address behind
  a proxy, and signing out (including a row written back), against a real Omeka.

## Licence

Copyright (C) 2026 Phumraphee Sae-tang

This program is free software: you can redistribute it and/or modify it under the terms of
the GNU General Public License as published by the Free Software Foundation, either version 3
of the License, or (at your option) any later version. See `LICENSE`.
