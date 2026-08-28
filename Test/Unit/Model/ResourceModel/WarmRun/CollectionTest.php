<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\ResourceModel\WarmRun;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun as WarmRunResource;
use Commerce\CacheTools\Model\ResourceModel\WarmRun\Collection;
use Commerce\CacheTools\Model\Warmer\Run\WarmRun;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The real constructor builds a SELECT through the object manager, which a unit
 * test does not have.
 */
final class CollectionTest extends TestCase
{
    public function testTheCollectionIsWiredToTheEntityAndItsResource(): void
    {
        $collection = $this->collection();

        self::assertSame(WarmRun::class, $collection->getModelName());
        self::assertSame(WarmRunResource::class, $collection->getResourceModelName());
    }

    /**
     * Set through the setter: the parent declares `$_idFieldName` untyped.
     */
    public function testTheIdFieldIsSetThroughTheSetter(): void
    {
        self::assertSame(WarmRunInterface::RUN_ID, $this->collection()->getIdFieldName());
    }

    public function testTheIdFieldIsNotTheFrameworkDefault(): void
    {
        self::assertNotSame('id', $this->collection()->getIdFieldName());
    }

    private function collection(): Collection
    {
        $collection = (new ReflectionClass(Collection::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($collection, '_construct'))->invoke($collection);

        return $collection;
    }
}
