<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Queue;

use Commerce\CacheTools\Model\Warmer\UrlWarmer;
use Commerce\CacheTools\Queue\RewarmConsumer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RewarmConsumerTest extends TestCase
{
    /** @var string[] */
    private array $warmed = [];

    /** @var string[] */
    private array $areaCalls = [];

    private bool $areaAlreadySet = false;

    protected function setUp(): void
    {
        $this->warmed = [];
        $this->areaCalls = [];
        $this->areaAlreadySet = false;
    }

    public function testTheQueuedUrlIsRefetched(): void
    {
        $this->consumer()->process('https://shop.test/scrub-top.html');

        self::assertSame(['https://shop.test/scrub-top.html'], $this->warmed);
    }

    /**
     * A queue consumer starts with no area, so it is set before the fetch.
     */
    public function testTheFrontendAreaIsSetBeforeFetching(): void
    {
        $this->consumer()->process('https://shop.test/a.html');

        self::assertSame(['get', 'set:' . Area::AREA_FRONTEND], $this->areaCalls);
    }

    /**
     * Setting the area twice is a fatal, and a consumer running several
     * messages in one process has already set it after the first.
     */
    public function testAnAreaThatIsAlreadySetIsLeftAlone(): void
    {
        $this->areaAlreadySet = true;

        $this->consumer()->process('https://shop.test/a.html');

        self::assertSame(['get'], $this->areaCalls);
    }

    private function consumer(): RewarmConsumer
    {
        $warmer = $this->createMock(UrlWarmer::class);
        $warmer->method('warm')->willReturnCallback(function (string $url): bool {
            $this->warmed[] = $url;

            return true;
        });

        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturnCallback(
            function (): string {
                $this->areaCalls[] = 'get';

                if (!$this->areaAlreadySet) {
                    throw new LocalizedException(__('Area code is not set'));
                }

                return Area::AREA_FRONTEND;
            }
        );
        $appState->method('setAreaCode')->willReturnCallback(function (string $code): void {
            $this->areaCalls[] = 'set:' . $code;
        });

        return new RewarmConsumer($warmer, $appState);
    }
}
