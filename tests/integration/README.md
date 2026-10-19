# Integration test

Needs a real Omeka S 4 with this module installed and active, sessions kept in its
`session` table (the default: no `save_handler` in `config/local.config.php`), MySQL 8,
and at least one active global admin and one active site admin account. The test writes
its own rows into `session`, all with ids starting `tas`, and removes them when it ends.

    OMEKA_URL=http://127.0.0.1:8080 MYSQL="mysql --defaults-file=/path/my.cnf" MYSQL_DB=omeka python3 scenario.py

Over HTTP, with sessions made directly in the table for each role: who may open the page,
what is noted on each signed-in request (and when not), the address behind a proxy, the
list, and ending a sign-in — including one that a request still running writes back.

`php tests/run.php` covers the parts that need no server.

Passed (28) on 2026-10-20 against Omeka S 4.2.1, PHP 8.2 and MySQL 8.0.
