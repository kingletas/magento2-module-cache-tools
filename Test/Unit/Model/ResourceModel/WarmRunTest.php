<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\ResourceModel;

use Commerce\CacheTools\Api\Data\WarmRunInterface;
use Commerce\CacheTools\Model\ResourceModel\WarmRun;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class WarmRunTest extends TestCase
{
    private const NOW = '2026-08-26 12:00:00';

    /** @var array<int, array{values: array<string, mixed>, where: mixed}> */
    private array $updates = [];

    /** @var array<int, array{condition: string, value: mixed}> */
    private array $conditions = [];

    private int $affected = 1;
    private mixed $fetchOneResult = '1';
    private ?int $limit = null;
    private AdapterInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->updates = [];
        $this->conditions = [];
        $this->affected = 1;
        $this->fetchOneResult = '1';
        $this->limit = null;

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use (&$select): Select {
                $this->conditions[] = ['condition' => $condition, 'value' => $value];

                return $select;
            }
        );
        $select->method('limit')->willReturnCallback(function ($count = null) use (&$select): Select {
            $this->limit = (int) $count;

            return $select;
        });

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('quoteIdentifier')
            ->willReturnCallback(static fn ($id): string => '`' . $id . '`');
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, $value): string => str_replace('?', "'" . $value . "'", $text)
        );
        $this->connection->method('fetchOne')->willReturnCallback(fn () => $this->fetchOneResult);
        $this->connection->method('update')->willReturnCallback(
            function (string $table, array $values, $where = ''): int {
                $this->updates[] = ['values' => $values, 'where' => $where];

                return $this->affected;
            }
        );
    }

    public function testTheResourceIsWiredToItsTableAndKey(): void
    {
        $resource = (new ReflectionClass(WarmRun::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod($resource, '_construct'))->invoke($resource);

        self::assertSame(
            WarmRun::TABLE_NAME,
            (new ReflectionProperty(WarmRun::class, '_mainTable'))->getValue($resource)
        );
        self::assertSame(WarmRunInterface::RUN_ID, $resource->getIdFieldName());
    }

    /**
     * The database does the arithmetic.
     */
    public function testProgressIsIncrementedByTheDatabaseRatherThanReadAndWritten(): void
    {
        $this->resource()->incrementProgress(5, 10, 2);

        self::assertSame(
            '`processed_products` + 10',
            (string) $this->updates[0]['values'][WarmRunInterface::PROCESSED_PRODUCTS]
        );
        self::assertSame(
            '`failed_products` + 2',
            (string) $this->updates[0]['values'][WarmRunInterface::FAILED_PRODUCTS]
        );
    }

    /**
     * Only a running run is advanced: a late message for a reaped run would
     * otherwise push a stale row's counters past its total.
     */
    public function testOnlyARunningRunIsAdvanced(): void
    {
        $this->resource()->incrementProgress(5, 10, 2);

        $where = implode(' ', (array) $this->updates[0]['where']);
        self::assertStringContainsString("run_id = '5'", $where);
        self::assertStringContainsString("status = '" . WarmRunInterface::STATUS_RUNNING . "'", $where);
    }

    /**
     * Zero affected rows means the run no longer exists, which the caller needs
     * to tell from a successful increment.
     */
    public function testTheNumberOfAffectedRowsIsReported(): void
    {
        $this->affected = 0;

        self::assertSame(0, $this->resource()->incrementProgress(404, 10, 2));
    }

    /**
     * The completion check and the write are one statement, so no run finishes
     * twice.
     */
    public function testCompletionIsDecidedAndWrittenInOneStatement(): void
    {
        self::assertTrue($this->resource()->completeIfDone(5, self::NOW));

        $update = $this->updates[0];
        self::assertSame(WarmRunInterface::STATUS_COMPLETE, $update['values'][WarmRunInterface::STATUS]);
        self::assertSame(self::NOW, $update['values'][WarmRunInterface::FINISHED_AT]);

        $where = implode(' ', (array) $update['where']);
        self::assertStringContainsString('`processed_products` >= `total_products`', $where);
        self::assertStringContainsString("status = '" . WarmRunInterface::STATUS_RUNNING . "'", $where);
    }

    /**
     * Only the call that actually closed the run reports true, so exactly one
     * consumer announces the completion.
     */
    public function testOnlyTheCallThatClosedTheRunReportsSo(): void
    {
        $this->affected = 0;

        self::assertFalse($this->resource()->completeIfDone(5, self::NOW));
    }

    /**
     * Staleness is measured from the last progress update, not from the start.
     */
    public function testStalenessIsMeasuredFromTheLastProgressUpdate(): void
    {
        self::assertSame(1, $this->resource()->markStaleRuns('2026-08-26 08:00:00', self::NOW));

        $where = implode(' ', (array) $this->updates[0]['where']);
        self::assertStringContainsString("updated_at < '2026-08-26 08:00:00'", $where);
        self::assertStringNotContainsString('started_at', $where);
    }

    public function testOnlyRunningRunsAreReaped(): void
    {
        $this->resource()->markStaleRuns('2026-08-26 08:00:00', self::NOW);

        $where = implode(' ', (array) $this->updates[0]['where']);
        self::assertStringContainsString("status = '" . WarmRunInterface::STATUS_RUNNING . "'", $where);
    }

    public function testAReapedRunIsMarkedStaleAndStamped(): void
    {
        $this->resource()->markStaleRuns('2026-08-26 08:00:00', self::NOW);

        self::assertSame(WarmRunInterface::STATUS_STALE, $this->updates[0]['values'][WarmRunInterface::STATUS]);
        self::assertSame(self::NOW, $this->updates[0]['values'][WarmRunInterface::FINISHED_AT]);
    }

    /**
     * Only one run per type may be open, so the check has to be per type - a
     * product warm in flight must not block a swatch warm.
     */
    public function testAnOpenRunIsLookedUpByTypeAndStatus(): void
    {
        self::assertTrue($this->resource()->hasOpenRun('product'));

        self::assertSame(
            [
                ['condition' => WarmRunInterface::WARM_TYPE . ' = ?', 'value' => 'product'],
                ['condition' => WarmRunInterface::STATUS . ' = ?', 'value' => WarmRunInterface::STATUS_RUNNING],
            ],
            $this->conditions
        );
    }

    public function testTheOpenRunCheckStopsAtTheFirstMatch(): void
    {
        $this->resource()->hasOpenRun('product');

        self::assertSame(1, $this->limit);
    }

    public function testNoOpenRunReadsAsFalse(): void
    {
        $this->fetchOneResult = false;

        self::assertFalse($this->resource()->hasOpenRun('product'));
    }

    private function resource(): WarmRun&MockObject
    {
        $resource = $this->getMockBuilder(WarmRun::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'getMainTable'])
            ->getMock();
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn(WarmRun::TABLE_NAME);

        return $resource;
    }
}
