<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Controller\Adminhtml\Varnish;

use Commerce\CacheTools\Controller\Adminhtml\Varnish\Flush;
use Commerce\CacheTools\Model\Fastly\PurgeResult;
use Commerce\CacheTools\Model\Fastly\Purger;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlushTest extends TestCase
{
    private ?int $status = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var string[] */
    private array $purged = [];

    private string $url = 'https://shop.test/scrub-top.html';
    private bool $purgeSucceeds = true;

    protected function setUp(): void
    {
        $this->status = null;
        $this->data = [];
        $this->purged = [];
        $this->url = 'https://shop.test/scrub-top.html';
        $this->purgeSucceeds = true;
    }

    /**
     * Magento validates the form key on POST only, so a flush on GET is
     * CSRF-triggerable.
     */
    public function testItOnlyAnswersPosts(): void
    {
        $controller = $this->controller();

        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $controller);
    }

    /**
     * Purging the edge is not something every admin user should be able to do;
     * the ACL resource is what an integrator grants.
     */
    public function testItIsGuardedByItsOwnAclResource(): void
    {
        $this->assertSame('Commerce_CacheTools::varnish_flush', Flush::ADMIN_RESOURCE);
    }

    public function testTheRequestedUrlIsPurged(): void
    {
        $this->controller()->execute();

        $this->assertSame(['https://shop.test/scrub-top.html'], $this->purged);
        $this->assertTrue($this->data['success']);
        $this->assertSame(200, $this->status);
    }

    public function testTheUrlIsTrimmedBeforePurging(): void
    {
        $this->url = "  https://shop.test/a.html \n";

        $this->controller()->execute();

        $this->assertSame(['https://shop.test/a.html'], $this->purged);
    }

    /**
     * An empty field is the operator forgetting to choose a URL; spending a
     * purge request on it is a round trip for nothing.
     */
    public function testAnEmptyUrlIsRefusedWithoutPurging(): void
    {
        $this->url = '   ';

        $this->controller()->execute();

        $this->assertSame(400, $this->status);
        $this->assertFalse($this->data['success']);
        $this->assertSame([], $this->purged);
    }

    /**
     * The button's JavaScript branches on the status code, so a refused purge
     * answering 200 would render as a success.
     */
    public function testARefusedPurgeIsReportedAsUnprocessable(): void
    {
        $this->purgeSucceeds = false;

        $this->controller()->execute();

        $this->assertSame(422, $this->status);
        $this->assertFalse($this->data['success']);
    }

    /**
     * The purger's own wording is what reaches the operator - it is where the
     * guard's refusal and the CDN's rejection are already phrased.
     */
    public function testThePurgersMessageIsPassedThrough(): void
    {
        $this->purgeSucceeds = false;

        $this->controller()->execute();

        $this->assertStringContainsString('purge', $this->data['message']);
    }

    private function controller(): Flush
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (): string => $this->url);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $purger = $this->createMock(Purger::class);
        $purger->method('purgeUrl')->willReturnCallback(
            function (string $url, ?bool $soft = null): PurgeResult {
                $this->purged[] = $url;

                return new PurgeResult(
                    $url,
                    $this->purgeSucceeds,
                    $this->purgeSucceeds
                        ? __('A cache purge has been sent for %1.', $url)
                        : __('The cache purge for %1 failed; see the log.', $url)
                );
            }
        );

        return new Flush($context, $this->jsonFactory(), $purger);
    }

    private function jsonFactory(): JsonFactory&MockObject
    {
        $json = $this->createMock(Json::class);
        $json->method('setHttpResponseCode')->willReturnCallback(function (int $code) use (&$json): Json {
            $this->status = $code;

            return $json;
        });
        $json->method('setData')->willReturnCallback(function ($data) use (&$json): Json {
            $this->data = (array) $data;

            return $json;
        });

        $factory = $this->createMock(JsonFactory::class);
        $factory->method('create')->willReturn($json);

        return $factory;
    }
}
