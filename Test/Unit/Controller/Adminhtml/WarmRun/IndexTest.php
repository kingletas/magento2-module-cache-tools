<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Controller\Adminhtml\WarmRun;

use Commerce\CacheTools\Controller\Adminhtml\WarmRun\Index;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    /** @var string[] */
    private array $titles = [];

    private ?string $activeMenu = null;

    protected function setUp(): void
    {
        $this->titles = [];
        $this->activeMenu = null;
    }

    /**
     * The grid only reads; declaring it a GET keeps it out of the POST-only
     * CSRF path it has no state to protect.
     */
    public function testItOnlyAnswersGets(): void
    {
        $controller = $this->controller();

        $this->assertInstanceOf(HttpGetActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpPostActionInterface::class, $controller);
    }

    public function testItIsGuardedByItsOwnAclResource(): void
    {
        $this->assertSame('Commerce_CacheTools::warm_runs', Index::ADMIN_RESOURCE);
    }

    public function testThePageIsRenderedUnderItsOwnMenuEntry(): void
    {
        $this->controller()->execute();

        $this->assertSame(Index::ADMIN_RESOURCE, $this->activeMenu);
    }

    /**
     * Prepended rather than set, so the store's own admin title suffix
     * survives.
     */
    public function testTheTitleIsPrependedRatherThanReplaced(): void
    {
        $this->controller()->execute();

        $this->assertSame(['Cache Warm Runs'], $this->titles);
    }

    private function controller(): Index
    {
        $title = $this->createMock(Title::class);
        $title->method('prepend')->willReturnCallback(function ($value) use (&$title): Title {
            $this->titles[] = (string) $value;

            return $title;
        });

        $config = $this->createMock(Config::class);
        $config->method('getTitle')->willReturn($title);

        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($config);
        $page->method('setActiveMenu')->willReturnCallback(function (string $menu) use (&$page): Page {
            $this->activeMenu = $menu;

            return $page;
        });

        $factory = $this->createMock(PageFactory::class);
        $factory->method('create')->willReturn($page);

        return new Index($this->createMock(Context::class), $factory);
    }
}
