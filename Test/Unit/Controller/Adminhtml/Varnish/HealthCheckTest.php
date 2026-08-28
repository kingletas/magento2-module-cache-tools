<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Controller\Adminhtml\Varnish;

use Commerce\CacheTools\Controller\Adminhtml\Varnish\HealthCheck;
use Commerce\CacheTools\Model\Fastly\HealthResult;
use Commerce\CacheTools\Model\Fastly\VarnishHealthCheck;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HealthCheckTest extends TestCase
{
    private ?int $status = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var string[] */
    private array $probed = [];

    private string $url = 'https://shop.test/scrub-top.html';
    private ?HealthResult $result = null;

    protected function setUp(): void
    {
        $this->status = null;
        $this->data = [];
        $this->probed = [];
        $this->url = 'https://shop.test/scrub-top.html';
        $this->result = null;
    }

    /**
     * Read-only, so GET is correct - and declaring it keeps Magento from
     * demanding a form key the panel's first request cannot have.
     */
    public function testItOnlyAnswersGets(): void
    {
        self::assertInstanceOf(HttpGetActionInterface::class, $this->controller());
    }

    /**
     * Probing is a different capability from purging, so it carries its own ACL
     * resource: read-only access to the panel need not include a flush.
     */
    public function testItIsGuardedBySeparateAclResource(): void
    {
        self::assertSame('Commerce_CacheTools::varnish_health', HealthCheck::ADMIN_RESOURCE);
        self::assertNotSame(
            \Commerce\CacheTools\Controller\Adminhtml\Varnish\Flush::ADMIN_RESOURCE,
            HealthCheck::ADMIN_RESOURCE
        );
    }

    public function testTheRequestedUrlIsProbedAndItsResultReturned(): void
    {
        $this->controller()->execute();

        self::assertSame(['https://shop.test/scrub-top.html'], $this->probed);
        self::assertTrue($this->data['success']);
        self::assertSame(HealthResult::STATE_HIT, $this->data['result']['cache_state']);
    }

    public function testTheUrlIsTrimmedBeforeProbing(): void
    {
        $this->url = "  https://shop.test/a.html \n";

        $this->controller()->execute();

        self::assertSame(['https://shop.test/a.html'], $this->probed);
    }

    public function testAnEmptyUrlIsRefusedWithoutProbing(): void
    {
        $this->url = '  ';

        $this->controller()->execute();

        self::assertSame(400, $this->status);
        self::assertFalse($this->data['success']);
        self::assertSame([], $this->probed);
    }

    /**
     * The panel shows the sentence an operator reads and the raw result the
     * JavaScript colours from.
     */
    public function testTheResponseCarriesBothASentenceAndTheRawResult(): void
    {
        $this->controller()->execute();

        self::assertStringContainsString('HTTP 200', $this->data['message']);
        self::assertStringContainsString('age 120s', $this->data['message']);
        self::assertStringContainsString('cache-lhr-1', $this->data['message']);
        self::assertIsArray($this->data['result']);
    }

    /**
     * A response with no age or node prints neither clause rather than "age s"
     * and a trailing "served by".
     */
    public function testTheMissingPartsAreOmittedFromTheSentence(): void
    {
        $this->result = new HealthResult(
            'https://shop.test/a.html',
            reachable: true,
            httpStatus: 200,
            cacheState: HealthResult::STATE_MISS
        );

        $this->controller()->execute();

        self::assertStringNotContainsString('age', $this->data['message']);
        self::assertStringNotContainsString('served by', $this->data['message']);
    }

    /**
     * An unreachable URL is the answer the operator asked for, not a server
     * error - the panel renders it as a red row rather than an exception.
     */
    public function testAnUnreachableUrlIsAnAnswerRatherThanAnError(): void
    {
        $this->result = new HealthResult(
            'https://shop.test/a.html',
            reachable: false,
            error: 'Connection timed out'
        );

        $this->controller()->execute();

        self::assertNull($this->status);
        self::assertFalse($this->data['success']);
        self::assertStringContainsString('could not be reached', $this->data['message']);
    }

    private function controller(): HealthCheck
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (): string => $this->url);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $healthCheck = $this->createMock(VarnishHealthCheck::class);
        $healthCheck->method('check')->willReturnCallback(
            function (string $url): HealthResult {
                $this->probed[] = $url;

                return $this->result ?? new HealthResult(
                    $url,
                    reachable: true,
                    httpStatus: 200,
                    cacheState: HealthResult::STATE_HIT,
                    age: 120,
                    servedBy: 'cache-lhr-1'
                );
            }
        );

        return new HealthCheck($context, $this->jsonFactory(), $healthCheck);
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
