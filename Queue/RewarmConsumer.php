<?php
/**
 * RewarmConsumer.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Queue;

use Commerce\CacheTools\Model\Warmer\UrlWarmer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Throwable;

/**
 * Re-fetches a purged URL so the edge repopulates it.
 */
class RewarmConsumer
{
    public function __construct(
        private readonly UrlWarmer $urlWarmer,
        private readonly State $appState
    ) {
    }

    public function process(string $url): void
    {
        $this->ensureFrontendArea();

        $this->urlWarmer->warm($url);
    }

    private function ensureFrontendArea(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (Throwable) {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        }
    }
}
