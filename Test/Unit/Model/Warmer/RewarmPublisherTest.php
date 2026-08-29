<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer;

use Commerce\CacheTools\Model\Warmer\RewarmPublisher;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class RewarmPublisherTest extends TestCase
{
    /** @var array<int, array{topic: string, url: string}> */
    private array $published = [];

    private ?\Throwable $publishFailure = null;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->published = [];
        $this->publishFailure = null;
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Re-warming on a worker repopulates the edge before the next shopper pays
     * for the miss.
     */
    public function testAPurgedUrlIsQueuedForRefetching(): void
    {
        $this->publisher()->publish('https://shop.test/scrub-top.html');

        $this->assertSame(
            [['topic' => RewarmPublisher::DEFAULT_TOPIC, 'url' => 'https://shop.test/scrub-top.html']],
            $this->published
        );
    }

    public function testTheTopicIsConfigurable(): void
    {
        $this->publisher('acme.cachetools.rewarm')->publish('https://shop.test/x');

        $this->assertSame('acme.cachetools.rewarm', $this->published[0]['topic']);
    }

    /**
     * A blank URL is a resolver that found nothing; queueing it would have a
     * worker fetch the store root over and over.
     */
    public function testABlankUrlIsNotQueued(): void
    {
        $publisher = $this->publisher();

        $publisher->publish('');
        $publisher->publish('   ');

        $this->assertSame([], $this->published);
    }

    /**
     * A failed re-warm must not fail the purge that triggered it.
     */
    public function testABrokerOutageIsLoggedRatherThanFailingThePurge(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('https://shop.test/scrub-top.html'));

        $this->publishFailure = new RuntimeException('AMQP connection refused');

        $this->publisher()->publish('https://shop.test/scrub-top.html');
    }

    /**
     * A warning rather than an error: nothing is broken, the next shopper just
     * pays for one miss.
     */
    public function testAFailedQueueIsNotReportedAsAnError(): void
    {
        $this->logger->expects($this->never())->method('error');

        $this->publishFailure = new RuntimeException('AMQP connection refused');

        $this->publisher()->publish('https://shop.test/scrub-top.html');
    }

    private function publisher(string $topic = RewarmPublisher::DEFAULT_TOPIC): RewarmPublisher
    {
        $queuePublisher = $this->createMock(PublisherInterface::class);
        $queuePublisher->method('publish')->willReturnCallback(
            function (string $topicName, $data) {
                if ($this->publishFailure !== null) {
                    throw $this->publishFailure;
                }

                $this->published[] = ['topic' => $topicName, 'url' => (string) $data];

                return null;
            }
        );

        return new RewarmPublisher($queuePublisher, $this->logger, $topic);
    }
}
