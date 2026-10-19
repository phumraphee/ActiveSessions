<?php
namespace ActiveSessions\Stdlib;

/**
 * A user agent string, said the way a person would say it: "Chrome 141 on Windows",
 * "Safari on iPhone". Only the browsers and systems staff actually use are named; for
 * anything else the page shows the raw string, which is always one hover away anyway.
 *
 * Order matters. Edge, Opera and Samsung Internet all say "Chrome" too, Chrome says
 * "Safari", and an iPad that asks for the desktop site says "Macintosh".
 */
class Device
{
    public static function describe(string $ua): string
    {
        $ua = trim($ua);
        if ($ua === '') {
            return '';
        }
        $browser = '';
        $rules = [
            ['/\bEdg(?:e|A|iOS)?\/(\d+)/', 'Edge'],
            ['/\bOPR\/(\d+)/', 'Opera'],
            ['/\bSamsungBrowser\/(\d+)/', 'Samsung Internet'],
            ['/\bLine\/(\d+)/', 'LINE in-app browser'],
            ['/\bFBAV\/(\d+)/', 'Facebook in-app browser'],
            ['/\b(?:Firefox|FxiOS)\/(\d+)/', 'Firefox'],
            ['/\b(?:CriOS|Chrome)\/(\d+)/', 'Chrome'],
            ['/\bVersion\/(\d+)(?:\.\d+)*.*Safari\//', 'Safari'],
        ];
        foreach ($rules as [$re, $name]) {
            if (preg_match($re, $ua, $m)) {
                $browser = $name . (isset($m[1]) && $m[1] !== '' ? ' ' . $m[1] : '');
                break;
            }
        }
        $os = '';
        if (preg_match('/\biPhone\b/', $ua)) {
            $os = 'iPhone';
        } elseif (preg_match('/\biPad\b/', $ua)) {
            $os = 'iPad';
        } elseif (preg_match('/\bAndroid\b/', $ua)) {
            $os = preg_match('/\bMobile\b/', $ua) ? 'Android phone' : 'Android tablet';
        } elseif (preg_match('/\bCrOS\b/', $ua)) {
            $os = 'ChromeOS';
        } elseif (preg_match('/\bWindows\b/', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/\bMac OS X\b|\bMacintosh\b/', $ua)) {
            $os = 'Mac';
        } elseif (preg_match('/\bLinux\b/', $ua)) {
            $os = 'Linux';
        }
        if ($browser === '' && $os === '') {
            return mb_substr($ua, 0, 80);
        }
        if ($browser === '') {
            return $os;
        }
        return $os === '' ? $browser : $browser . ' on ' . $os;
    }
}
