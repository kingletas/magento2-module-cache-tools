<?php
/**
 * UrlHostResolver.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Environment;

/**
 * Extracts the lower-cased host from an absolute http(s) URL.
 */
class UrlHostResolver
{
    /**
     * @return string The host, lower-cased, or '' when the URL has none.
     */
    public function resolve(string $url): string
    {
        if (!preg_match('#^https?://([^/?\#]+)#i', trim($url), $matches)) {
            return '';
        }

        $authority = $matches[1];

        // Strip any userinfo: everything up to and including the last '@'.
        $userInfoAt = strrpos($authority, '@');

        if ($userInfoAt !== false) {
            $authority = substr($authority, $userInfoAt + 1);
        }

        // A bracketed IPv6 literal keeps its brackets and may be followed by a
        // port; the colons inside the brackets are part of the address.
        if (str_starts_with($authority, '[')) {
            $closingBracket = strpos($authority, ']');

            return $closingBracket === false
                ? mb_strtolower($authority)
                : mb_strtolower(substr($authority, 0, $closingBracket + 1));
        }

        $portAt = strpos($authority, ':');

        if ($portAt !== false) {
            $authority = substr($authority, 0, $portAt);
        }

        return mb_strtolower($authority);
    }
}
