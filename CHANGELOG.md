# Changelog

## 0.3.0 — 2026-10-22

- **One person's own time**: a global admin gives a person 7 days, 30 days, 180 days or the
  longest the server allows, instead of their role's (sessions page, "One person's own time").
- **One device kept**: "Keep this device signed in longest" / "Stop keeping" on each sign-in of
  the list; that sign-in lasts the longest the server allows from its sign-in, the person's other
  devices follow the rule. Dropped when the person's role changes or they are deactivated.
- **The longest the server allows** is read as the site runs (the shorter of `gc_maxlifetime` and
  `cookie_lifetime`); nothing is set past it, longer times are not offered, and one set before is
  cut to it (the page says so). No "for ever".
- "Sign out on every device" for one person. Sign out ends kept sign-ins too.
- An exception never lets in a deactivated person: their next request is signed out.
- Every change noted ("Changes to sign-in times"). Nothing set = 0.2 exactly.
- Upgrade from 0.2.0: Modules → Active Sessions → Upgrade (adds `keep`, `keep_by`, `keep_role`).

## 0.2.0 — 2026-10-22

- **How long a sign-in lasts, by role**, kept on the server: everybody 12 hours, the roles you
  choose (default: global admin) 7 days. Both are module settings; 0 = no limit.
- Each sign-in has a clock (`since`, a new column in `active_sessions_seen`). It starts at
  log-in (Omeka's `user.login` event). Sign-ins from before the upgrade start their clock when
  first seen, so upgrading signs nobody out at once.
- Past its time, a sign-in is ended on the person's next request (the login page says why), and
  every few minutes on anybody's request (its session row deleted), so pages outside Omeka
  that read the `session` table see it gone too.
- The session cookie is sent again to expire when the sign-in ends. A cookie kept longer by
  hand changes nothing.
- Settings page: hours for everybody, days for the longer roles, which roles. It warns when
  PHP's `gc_maxlifetime` is shorter than the longest limit.
- The sessions page says the rule as set at the top, and when each sign-in ends: its time
  limit or unused, whichever comes first ("time limit starts at its next use" for one not seen
  since the upgrade). It shows "Time limit" for sign-ins ended this way.
- Upgrade from 0.1.0: Modules → Active Sessions → Upgrade (adds the column).

## 0.1.0 — 2026-10-20

- First release: who is signed in (address, device and browser, when each sign-in began and
  was last used), and Sign out for a global admin.
