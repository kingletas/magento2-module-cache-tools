<?php
/**
 * UrlHostResolverTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Environment;

use Commerce\CacheTools\Model\Environment\UrlHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlHostResolverTest extends TestCase
{
    private UrlHostResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new UrlHostResolver();
    }

    #[DataProvider('urlProvider')]
    public function testResolvesTheHost(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolve($url));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function urlProvider(): array
    {
        return [
            'plain' => ['https://www.example.com/path', 'www.example.com'],
            'lower-cased' => ['https://WWW.Example.COM/', 'www.example.com'],
            'with port' => ['https://www.example.com:8443/x', 'www.example.com'],
            'with userinfo' => ['https://user:pass@www.example.com/x', 'www.example.com'],
            'userinfo containing @' => ['https://u%40b:p@www.example.com/', 'www.example.com'],
            'no path' => ['https://www.example.com', 'www.example.com'],
            'query only' => ['https://www.example.com?a=1', 'www.example.com'],
            'fragment' => ['https://www.example.com#top', 'www.example.com'],
            'http' => ['http://example.test/', 'example.test'],
            'whitespace' => ['  https://example.test/  ', 'example.test'],
            'not a url' => ['not a url', ''],
            'relative' => ['/just/a/path', ''],
            'unsupported scheme' => ['ftp://example.test/', ''],
        ];
    }

    /**
     * Cutting at the first colon would resolve a bracketed IPv6 literal to "[".
     */
    #[DataProvider('ipv6Provider')]
    public function testHandlesBracketedIpv6Literals(string $url, string $expected): void
    {
        $this->assertSame($expected, $this->resolver->resolve($url));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ipv6Provider(): array
    {
        return [
            'loopback with port' => ['http://[::1]:8080/x', '[::1]'],
            'loopback without port' => ['http://[::1]/x', '[::1]'],
            'full address' => ['https://[2001:db8::8a2e:370:7334]:443/', '[2001:db8::8a2e:370:7334]'],
        ];
    }
}
