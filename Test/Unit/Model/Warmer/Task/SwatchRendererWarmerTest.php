<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer\Task;

use Commerce\CacheTools\Api\SwatchCacheWarmerInterface;
use Commerce\CacheTools\Model\Warmer\Task\SwatchRendererWarmer;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Swatches\Block\Product\Renderer\Configurable as SwatchRenderer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SwatchRendererWarmerTest extends TestCase
{
    /** @var string[] */
    private array $createdBlocks = [];

    private int $renders = 0;
    private ?Product $renderedFor = null;
    private ?\Throwable $renderFailure = null;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->createdBlocks = [];
        $this->renders = 0;
        $this->renderedFor = null;
        $this->renderFailure = null;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testItIsTheDefaultSwatchWarmer(): void
    {
        $this->assertInstanceOf(SwatchCacheWarmerInterface::class, $this->warmer());
    }

    /**
     * Rendering the JSON config is the point: the call populates whatever cache
     * the renderer writes to, and the return value is discarded.
     */
    public function testTheRendererIsAskedForTheProductsJsonConfig(): void
    {
        $product = $this->product();

        $this->assertTrue($this->warmer()->warm($product));
        $this->assertSame(1, $this->renders);
        $this->assertSame($product, $this->renderedFor);
    }

    public function testTheConfiguredBlockIsTheOneBuilt(): void
    {
        $this->warmer()->warm($this->product());

        $this->assertSame([SwatchRendererWarmer::DEFAULT_BLOCK], $this->createdBlocks);
    }

    /**
     * A store that has swapped the renderer names its own block; the module has
     * no business insisting on Magento's.
     */
    public function testTheBlockClassIsConfigurable(): void
    {
        $this->warmer(Template::class)->warm($this->product());

        $this->assertSame([Template::class], $this->createdBlocks);
    }

    /**
     * A store that has removed the swatch module gets a skipped warm rather
     * than a fatal error in a queue consumer.
     */
    public function testAnUninstalledRendererIsSkippedRatherThanFatal(): void
    {
        $this->assertFalse($this->warmer('Acme\\Uninstalled\\Swatches\\Renderer')->warm($this->product()));
        $this->assertSame([], $this->createdBlocks);
    }

    /**
     * A block that is not a swatch renderer has neither method, so the warm is
     * skipped.
     */
    public function testABlockThatCannotRenderSwatchesIsSkipped(): void
    {
        $this->assertFalse($this->warmer(Template::class)->warm($this->product()));
        $this->assertSame(0, $this->renders);
    }

    /**
     * Warming is best-effort work on a queue worker: a product whose swatch
     * payload will not build is worth a line, not a dead consumer.
     */
    public function testAFailingRenderIsContainedAndLogged(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('SKU-1'));

        $this->renderFailure = new RuntimeException('missing attribute option');

        $this->assertFalse($this->warmer()->warm($this->product()));
    }

    public function testTheDefaultBlockIsMagentosOwnSwatchRenderer(): void
    {
        $this->assertSame(SwatchRenderer::class, SwatchRendererWarmer::DEFAULT_BLOCK);
    }

    private function warmer(string $blockClass = SwatchRendererWarmer::DEFAULT_BLOCK): SwatchRendererWarmer
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('createBlock')->willReturnCallback(
            function (string $type, string $name = '', array $arguments = []): BlockInterface {
                $this->createdBlocks[] = $type;

                if ($type === Template::class) {
                    return $this->createMock(Template::class);
                }

                $block = $this->createMock(SwatchRenderer::class);
                $block->method('setProduct')->willReturnCallback(
                    function ($product) use (&$block) {
                        $this->renderedFor = $product;

                        return $block;
                    }
                );
                $block->method('getJsonConfig')->willReturnCallback(
                    function (): string {
                        if ($this->renderFailure !== null) {
                            throw $this->renderFailure;
                        }

                        $this->renders++;

                        return '{}';
                    }
                );

                return $block;
            }
        );

        return new SwatchRendererWarmer($layout, $this->logger, $blockClass);
    }

    /**
     * The concrete model, because Magento's renderer declares
     * `setProduct(Product $product)`.
     */
    private function product(): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn('SKU-1');

        return $product;
    }
}
