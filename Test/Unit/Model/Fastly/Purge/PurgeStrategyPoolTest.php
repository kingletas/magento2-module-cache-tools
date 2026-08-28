<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly\Purge;

use Commerce\CacheTools\Api\PurgeStrategyInterface;
use Commerce\CacheTools\Model\Config;
use Commerce\CacheTools\Model\Fastly\Purge\PurgeStrategyPool;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * A di.xml array argument is unchecked, so the pool validates its own contents
 * at construction.
 */
class PurgeStrategyPoolTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testTheConfiguredStrategyIsReturned(): void
    {
        $surrogate = $this->strategy();

        $pool = $this->pool('surrogate', ['surrogate' => $surrogate, 'url' => $this->strategy()]);

        $this->assertSame($surrogate, $pool->get());
    }

    public function testAnUnregisteredCodeReturnsNullRatherThanGuessing(): void
    {
        $pool = $this->pool('typo', ['surrogate' => $this->strategy()]);

        $this->assertNull($pool->get());
    }

    /**
     * The null has to be explained, or a purge that quietly does nothing looks
     * exactly like a purge with nothing to do.
     */
    public function testAnUnregisteredCodeIsLoggedWithTheCodesThatDoExist(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->callback(
                static fn (string $message): bool=> str_contains($message, '"typo"')
                    && str_contains($message, 'surrogate, url')
            ));

        $this->pool('typo', ['surrogate' => $this->strategy(), 'url' => $this->strategy()])->get();
    }

    public function testAnEmptyPoolSaysSoRatherThanListingNothing(): void
    {
        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('(none)'));

        $this->pool('surrogate', [])->get();
    }

    /**
     * An array argument in di.xml is unvalidated by the container.
     */
    public function testANonStrategyInThePoolIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"broken"');

        new PurgeStrategyPool(
            $this->createMock(Config::class),
            $this->logger,
            ['broken' => new stdClass()]
        );
    }

    public function testTheRejectionNamesTheTypeThatWasActuallyGiven(): void
    {
        try {
            new PurgeStrategyPool($this->createMock(Config::class), $this->logger, ['broken' => 'a string']);
            $this->fail('A string is not a purge strategy.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('string', $e->getMessage());
            $this->assertStringContainsString(PurgeStrategyInterface::class, $e->getMessage());
        }
    }

    public function testAvailableCodesListsWhatWasRegistered(): void
    {
        $pool = $this->pool('surrogate', ['surrogate' => $this->strategy(), 'url' => $this->strategy()]);

        $this->assertSame(['surrogate', 'url'], $pool->getAvailableCodes());
    }

    public function testAnEmptyPoolHasNoAvailableCodes(): void
    {
        $this->assertSame([], $this->pool('surrogate', [])->getAvailableCodes());
    }

    /**
     * @param array<string, PurgeStrategyInterface> $strategies
     */
    private function pool(string $configured, array $strategies): PurgeStrategyPool
    {
        $config = $this->createMock(Config::class);
        $config->method('getPurgeStrategy')->willReturn($configured);

        return new PurgeStrategyPool($config, $this->logger, $strategies);
    }

    private function strategy(): PurgeStrategyInterface
    {
        return $this->createMock(PurgeStrategyInterface::class);
    }
}
