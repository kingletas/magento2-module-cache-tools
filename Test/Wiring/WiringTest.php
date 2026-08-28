<?php
/**
 * WiringTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Wiring;

use Commerce\Foundation\Test\Support\ModuleWiringTestCase;

/**
 * This module's `etc/` against the code it names.
 */
class WiringTest extends ModuleWiringTestCase
{
    protected function moduleDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @inheritDoc
     */
    protected function settingsWithNoDefault(): array
    {
        return [
            // The Fastly service this install purges, and the extra URLs the
            // Cache Management page offers beside the store's own base URLs.
            'commerce_cachetools/fastly/service_name',
            'commerce_cachetools/fastly/extra_urls',
        ];
    }
}
