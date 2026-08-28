<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Source;

use Commerce\CacheTools\Model\Fastly\Purge\PurgeStrategyPool;
use Commerce\CacheTools\Model\Source\PurgeStrategy;
use Magento\Framework\Data\OptionSourceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PurgeStrategyTest extends TestCase
{
    /** @var string[] */
    private array $codes = ['surrogate_key', 'url'];

    public function testItIsUsableAsAConfigurationSource(): void
    {
        self::assertInstanceOf(OptionSourceInterface::class, $this->source());
    }

    /**
     * Built from the strategies actually registered, so the dropdown can never
     * offer one that is not installed.
     */
    public function testOnlyTheRegisteredStrategiesAreOffered(): void
    {
        self::assertSame(['surrogate_key', 'url'], array_column($this->source()->toOptionArray(), 'value'));

        $this->codes = ['url'];

        self::assertSame(['url'], array_column($this->source()->toOptionArray(), 'value'));
    }

    public function testAConfiguredLabelIsUsed(): void
    {
        $options = $this->source(['surrogate_key' => 'Surrogate keys (recommended)'])->toOptionArray();

        self::assertSame('Surrogate keys (recommended)', (string) $options[0]['label']);
    }

    /**
     * A strategy added by a third-party module has no label in this module's
     * di.xml, and an option with a blank label is one an admin cannot pick.
     */
    public function testAStrategyWithNoConfiguredLabelStillReadsAsSomething(): void
    {
        $this->codes = ['third_party_cdn'];

        $options = $this->source()->toOptionArray();

        self::assertSame('Third party cdn', (string) $options[0]['label']);
    }

    public function testAnInstallWithNoStrategiesOffersNothingRatherThanADefault(): void
    {
        $this->codes = [];

        self::assertSame([], $this->source()->toOptionArray());
    }

    /**
     * @param array<string, string> $labels
     */
    private function source(array $labels = []): PurgeStrategy
    {
        $pool = $this->createMock(PurgeStrategyPool::class);
        $pool->method('getAvailableCodes')->willReturnCallback(fn (): array => $this->codes);

        return new PurgeStrategy($pool, $labels);
    }
}
