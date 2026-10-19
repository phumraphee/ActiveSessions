<?php
namespace ActiveSessions\Stdlib;

/**
 * Where a request came from, as an address. The same rule as the module Revision
 * History's Capture\Where, copied rather than called so that each works without the other.
 *
 * Behind a reverse proxy (Cloudflare, or a proxy on the same private network) the web
 * server sees only the proxy's private address.
 * CF-Connecting-IP, then X-Forwarded-For, are believed only when the request reached the
 * server from a private address, that is, through the proxy; a request from a public
 * address could have written those headers itself, so its own address is kept.
 */
class Where
{
    public static function ip(): string
    {
        if (PHP_SAPI === 'cli') {
            return '';
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $said = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        $public = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($said !== '' && $remote !== '' && $public === false && filter_var($said, FILTER_VALIDATE_IP)) {
            return mb_substr($said, 0, 45);
        }
        if ($remote !== '' && $public === false) {
            foreach (explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $one) {
                $one = trim($one);
                if ($one !== '' && filter_var($one, FILTER_VALIDATE_IP)) {
                    return mb_substr($one, 0, 45);
                }
            }
        }
        return mb_substr($remote, 0, 45);
    }
}
