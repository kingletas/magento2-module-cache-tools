<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Warmer;

use Commerce\CacheTools\Model\Warmer\Publisher;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PublisherTest extends TestCase
{
    /** @var array<int, array{topic: string, payload: array<string, mixed>}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->published = [];
    }

    public function testABatchCarriesItsRunTypeAndProducts(): void
    {
        $this->publisher()->publishBatch(7, 'product', [10, 11]);

        $payload = $this->published[0]['payload'];
        $this->assertSame(7, $payload['run_id']);
        $this->assertSame('product', $payload['type']);
        $this->assertSame([10, 11], $payload['product_ids']);
    }

    /**
     * Ids arrive from a collection as strings and are compared against integer
     * entity ids by the consumer.
     */
    public function testTheProductIdsAreNormalisedToIntegers(): void
    {
        $this->publisher()->publishBatch(7, 'product', ['10', '11']);

        $this->assertSame([10, 11], $this->published[0]['payload']['product_ids']);
    }

    /**
     * A sparse array would serialise as a JSON object rather than a list.
     */
    public function testTheIdsAreSerialisedAsAListRatherThanAnObject(): void
    {
        $this->publisher()->publishBatch(7, 'product', [2 => 10, 5 => 11]);

        $this->assertSame([10, 11], $this->published[0]['payload']['product_ids']);
    }

    public function testTheDefaultTopicIsUsedUnlessOneIsConfigured(): void
    {
        $this->publisher()->publishBatch(7, 'product', [10]);
        $this->assertSame(Publisher::DEFAULT_TOPIC, $this->published[0]['topic']);

        $this->published = [];
        $this->publisher('acme.cachetools.warm')->publishBatch(7, 'product', [10]);
        $this->assertSame('acme.cachetools.warm', $this->published[0]['topic']);
    }

    /**
     * An empty batch is still a message the run counts against its total; the
     * publisher does not get to decide whether the run is finished.
     */
    public function testAnEmptyBatchIsStillPublished(): void
    {
        $this->publisher()->publishBatch(7, 'product', []);

        $this->assertCount(1, $this->published);
        $this->assertSame([], $this->published[0]['payload']['product_ids']);
    }

    private function publisher(string $topic = Publisher::DEFAULT_TOPIC): Publisher
    {
        $queuePublisher = $this->createMock(PublisherInterface::class);
        $queuePublisher->method('publish')->willReturnCallback(
            function (string $topicName, $data) {
                $this->published[] = [
                    'topic' => $topicName,
                    'payload' => (array) (new Json())->unserialize((string) $data),
                ];

                return null;
            }
        );

        return new Publisher($queuePublisher, new Json(), $topic);
    }
}
