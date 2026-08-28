<?php
/**
 * PurgeGuardTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly;

use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Environment\EnvironmentPolicy;
use Commerce\CacheTools\Model\Environment\UrlHostResolver;
use Commerce\CacheTools\Model\Fastly\PurgeGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The guard is all that stands between a restored stage database and
 * production's cache.
 */
class PurgeGuardTest extends TestCase
{
    private EnvironmentPolicy&MockObject $environment;
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->environment = $this->createMock(EnvironmentPolicy::class);
        $this->environment->method('describe')->willReturn('environment=stage, host=stage.example.com');

        $this->config = $this->createMock(Config::class);
        $this->config->method('getProductionHost')->willReturn('www.example.com');
        $this->config->method('getNonProductionMarker')->willReturn('stage');
    }

    private function guard(): PurgeGuard
    {
        return new PurgeGuard(
            $this->environment,
            new UrlHostResolver(),
            $this->config,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testProductionMayPurgeItsOwnUrl(): void
    {
        $this->environment->method('isProduction')->willReturn(true);

        self::assertNull($this->guard()->blockReasonForUrl('https://www.example.com/tops.html'));
    }

    public function testANonProductionEnvironmentIsRefusedTheProductionUrl(): void
    {
        $this->environment->method('isProduction')->willReturn(false);

        $reason = $this->guard()->blockReasonForUrl('https://www.example.com/tops.html');

        self::assertNotNull($reason);
        self::assertStringContainsString('Refusing to purge', (string) $reason);
    }

    public function testANonProductionEnvironmentMayPurgeItsOwnUrl(): void
    {
        $this->environment->method('isProduction')->willReturn(false);

        self::assertNull($this->guard()->blockReasonForUrl('https://stage.example.com/tops.html'));
    }

    /**
     * With no service name there is nothing to verify against, and a
     * service-wide purge is the destructive one.
     */
    public function testAnUnnamedServiceIsRefusedFromNonProduction(): void
    {
        $this->environment->method('isProduction')->willReturn(false);

        self::assertNotNull($this->guard()->blockReasonForService(''));
    }

    public function testANonProductionServiceIsAllowedFromNonProduction(): void
    {
        $this->environment->method('isProduction')->willReturn(false);

        self::assertNull($this->guard()->blockReasonForService('example-stage-html'));
    }

    public function testTheProductionServiceIsRefusedFromNonProduction(): void
    {
        $this->environment->method('isProduction')->willReturn(false);

        self::assertNotNull($this->guard()->blockReasonForService('www.example.com'));
    }

    public function testProductionIsNeverBlocked(): void
    {
        $this->environment->method('isProduction')->willReturn(true);

        self::assertNull($this->guard()->blockReasonForService('www.example.com'));
        self::assertNull($this->guard()->blockReasonForService(''));
    }

    /**
     * Reasons are rendered in the admin, so they must be Phrase objects built
     * from literal __() calls rather than interpolated strings.
     */
    public function testAReasonIsATranslatablePhrase(): void
    {
        $this->environment->method('isProduction')->willReturn(false);

        self::assertInstanceOf(
            \Magento\Framework\Phrase::class,
            $this->guard()->blockReasonForService('www.example.com')
        );
    }
}
