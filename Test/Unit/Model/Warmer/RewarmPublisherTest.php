<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer;

use Commerce\CacheTools\Model\Warmer\RewarmPublisher;
use Commerce\CacheTools\Test\Unit\Fake\RecordingLogger;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RewarmPublisherTest extends TestCase
{
    /** @var array<int, array{topic: string, url: string}> */
    private array $published = [];

    private ?\Throwable $publishFailure = null;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->published = [];
        $this->publishFailure = null;
        $this->logger = new RecordingLogger();
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
        $this->publishFailure = new RuntimeException('AMQP connection refused');

        $this->publisher()->publish('https://shop.test/scrub-top.html');

        $this->assertCount(1, $this->logger->warnings);
        $this->assertStringContainsString('https://shop.test/scrub-top.html', $this->logger->warnings[0]);
    }

    /**
     * A warning rather than an error: nothing is broken, the next shopper just
     * pays for one miss.
     */
    public function testAFailedQueueIsNotReportedAsAnError(): void
    {
        $this->publishFailure = new RuntimeException('AMQP connection refused');

        $this->publisher()->publish('https://shop.test/scrub-top.html');

        $this->assertSame([], $this->logger->errors);
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
