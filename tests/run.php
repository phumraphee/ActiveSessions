<?php
/**
 * Tests that need no Omeka and no database: which session data holds a signed-in
 * person, and how a browser is named.
 *
 *   php tests/run.php
 *
 * Signing in, the address behind a proxy (Where::ip() says nothing on the command
 * line), the list and ending a sign-in can only be tested against a real Omeka —
 * see tests/integration/README.md.
 */
spl_autoload_register(function ($c) {
    if (strpos($c, 'ActiveSessions\\') === 0) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($c, 15)) . '.php';
    }
});
if (!class_exists('Doctrine\DBAL\Connection')) {
    eval('namespace Doctrine\DBAL; class Connection { const PARAM_STR_ARRAY = 102; }');
}

use ActiveSessions\Stdlib\Device;
use ActiveSessions\Stdlib\Sessions;

$pass = 0;
$fail = 0;
function check(string $what, $got, $want): void
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL  $what\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n";
}

/* ------------------------------------------------------------ who a session holds */
$omeka4 = '__Laminas|a:0:{}Laminas_Auth|O:26:"Laminas\Stdlib\ArrayObject":4:{s:7:"storage";a:1:{s:7:"storage";i:23;}s:4:"flag";i:2;s:13:"iteratorClass";s:13:"ArrayIterator";s:19:"protectedProperties";a:0:{}}Omeka|a:0:{}';
check('Omeka 4 shape: the user id', Sessions::userIn($omeka4), 23);
check('older plain-array shape', Sessions::userIn('__Laminas|a:0:{}Laminas_Auth|a:1:{s:7:"storage";i:7;}'), 7);
check('the id as a string', Sessions::userIn('Laminas_Auth|a:1:{s:7:"storage";s:2:"41";}'), 41);
check('a signed-out visitor', Sessions::userIn('__Laminas|a:0:{}Laminas_Auth|O:26:"Laminas\Stdlib\ArrayObject":4:{s:7:"storage";a:0:{}}'), 0);
check('no Laminas_Auth at all', Sessions::userIn('__Laminas|a:0:{}Omeka|a:0:{}'), 0);
check('the same shape under another name is not a sign-in',
    Sessions::userIn('__Laminas|a:0:{}Laminas_Auth|O:26:"Laminas\Stdlib\ArrayObject":4:{s:7:"storage";a:0:{}}Other|O:26:"Laminas\Stdlib\ArrayObject":4:{s:7:"storage";a:1:{s:7:"storage";i:5;}}'), 0);
check('a name ending in Laminas_Auth is not it', Sessions::userIn('MyLaminas_Auth|a:1:{s:7:"storage";i:9;}'), 0);
check('the hash is sha256 of the id', Sessions::hash('abc'), hash('sha256', 'abc'));
check('the hash is never the id', Sessions::hash('abc') !== 'abc', true);

/* ------------------------------------------------------------ browsers in words */
$uas = [
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' => 'Chrome 141 on Mac',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1' => 'Safari 18 on iPhone',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0' => 'Edge 141 on Windows',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36' => 'Samsung Internet 25 on Android phone',
    'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' => 'Chrome 141 on Android tablet',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/141.0.0.0 Mobile/15E148 Safari/604.1' => 'Chrome 141 on iPhone',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:132.0) Gecko/20100101 Firefox/132.0' => 'Firefox 132 on Mac',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15' => 'Safari 18 on Mac',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 OPR/120.0.0.0' => 'Opera 120 on Windows',
    'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' => 'Chrome 141 on ChromeOS',
    'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0' => 'Firefox 132 on Linux',
    'curl/8.4.0' => 'curl/8.4.0',
    '' => '',
];
foreach ($uas as $ua => $want) {
    check('browser: ' . ($want === '' ? '(empty)' : $want), Device::describe((string) $ua), $want);
}

echo "tests/run.php: $pass passed, $fail failed\n";
exit($fail ? 1 : 0);
