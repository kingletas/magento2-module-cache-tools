<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Console\Varnish;

use Commerce\CacheTools\Console\Varnish\HealthCheckCommand;
use Commerce\CacheTools\Model\Fastly\HealthResult;
use Commerce\CacheTools\Model\Fastly\VarnishHealthCheck;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class HealthCheckCommandTest extends TestCase
{
    /** @var array<string, HealthResult> */
    private array $results = [];

    /** @var string[] */
    private array $probed = [];

    protected function setUp(): void
    {
        $this->results = [];
        $this->probed = [];
    }

    public function testEveryUrlGivenIsProbed(): void
    {
        $this->results = [
            'https://shop.test/a.html' => $this->hit('https://shop.test/a.html'),
            'https://shop.test/b.html' => $this->hit('https://shop.test/b.html'),
        ];

        $this->tester()->execute(['urls' => array_keys($this->results)]);

        self::assertSame(array_keys($this->results), $this->probed);
    }

    /**
     * The three facts an operator asks for: is it cached, how old is the copy,
     * and which node answered.
     */
    public function testTheReportShowsTheCacheStateAgeAndNode(): void
    {
        $this->results = ['https://shop.test/a.html' => $this->hit('https://shop.test/a.html')];

        $tester = $this->tester();
        $tester->execute(['urls' => ['https://shop.test/a.html']]);

        self::assertStringContainsString('HIT', $tester->getDisplay());
        self::assertStringContainsString('120s', $tester->getDisplay());
        self::assertStringContainsString('cache-lhr-1', $tester->getDisplay());
    }

    /**
     * A probe that could not connect has no status, age or node, and does not
     * print zeroes.
     */
    public function testAnUnreachableUrlIsReportedAsSuchWithItsError(): void
    {
        $this->results = ['https://shop.test/a.html' => new HealthResult(
            'https://shop.test/a.html',
            reachable: false,
            error: 'Connection timed out'
        )];

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['urls' => ['https://shop.test/a.html']]));
        self::assertStringContainsString('unreachable', $tester->getDisplay());
        self::assertStringContainsString('Connection timed out', $tester->getDisplay());
    }

    /**
     * A non-zero exit lets a deployment check gate on the edge being reachable
     * without parsing the table.
     */
    public function testAllReachableUrlsExitZero(): void
    {
        $this->results = ['https://shop.test/a.html' => $this->hit('https://shop.test/a.html')];

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['urls' => ['https://shop.test/a.html']]));
    }

    /**
     * A miss is a reachable edge - the page is simply not cached yet - and
     * failing on it would make every first probe after a purge look broken.
     */
    public function testAMissIsStillASuccessfulProbe(): void
    {
        $this->results = ['https://shop.test/a.html' => new HealthResult(
            'https://shop.test/a.html',
            reachable: true,
            httpStatus: 200,
            cacheState: HealthResult::STATE_MISS
        )];

        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['urls' => ['https://shop.test/a.html']]));
        self::assertStringContainsString(HealthResult::STATE_MISS, $tester->getDisplay());
    }

    /**
     * One unreachable URL fails the run, and the reachable ones are still
     * reported.
     */
    public function testOneUnreachableUrlFailsTheRunWithoutHidingTheRest(): void
    {
        $this->results = [
            'https://shop.test/a.html' => $this->hit('https://shop.test/a.html'),
            'https://shop.test/b.html' => new HealthResult(
                'https://shop.test/b.html',
                reachable: false,
                error: 'Connection timed out'
            ),
        ];

        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['urls' => array_keys($this->results)]));
        self::assertStringContainsString('a.html', $tester->getDisplay());
        self::assertStringContainsString('b.html', $tester->getDisplay());
    }

    /**
     * A response with no age header prints a dash rather than "0s", which would
     * claim the copy was fetched this instant.
     */
    public function testAMissingAgeOrNodePrintsADashRatherThanZero(): void
    {
        $this->results = ['https://shop.test/a.html' => new HealthResult(
            'https://shop.test/a.html',
            reachable: true,
            httpStatus: 200,
            cacheState: HealthResult::STATE_MISS
        )];

        $tester = $this->tester();
        $tester->execute(['urls' => ['https://shop.test/a.html']]);

        self::assertStringNotContainsString('0s', $tester->getDisplay());
    }

    private function hit(string $url): HealthResult
    {
        return new HealthResult(
            $url,
            reachable: true,
            httpStatus: 200,
            cacheState: HealthResult::STATE_HIT,
            age: 120,
            servedBy: 'cache-lhr-1'
        );
    }

    private function command(): HealthCheckCommand
    {
        $healthCheck = $this->createMock(VarnishHealthCheck::class);
        $healthCheck->method('check')->willReturnCallback(
            function (string $url): HealthResult {
                $this->probed[] = $url;

                return $this->results[$url]
                    ?? new HealthResult($url, reachable: false, error: 'No result was scripted.');
            }
        );

        return new HealthCheckCommand($healthCheck);
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->command());
    }
}
