<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Console\Warmer;

use Commerce\CacheTools\Console\Warmer\ReapRunsCommand;
use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ReapRunsCommandTest extends TestCase
{
    private int $reaped = 2;
    private ?\Throwable $reapFailure = null;

    protected function setUp(): void
    {
        $this->reaped = 2;
        $this->reapFailure = null;
    }

    /**
     * The same work the nightly cron does, for when a stuck run is blocking a
     * new one right now.
     */
    public function testReapedRunsAreReportedWithTheirCount(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Reaped 2 stale run(s)', $tester->getDisplay());
    }

    /**
     * Nothing to reap is a normal, successful outcome - and saying so is what
     * tells an operator that a stuck run is not what is blocking them.
     */
    public function testASweepThatFoundNothingSaysSoAndSucceeds(): void
    {
        $this->reaped = 0;

        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('No stale runs to reap', $tester->getDisplay());
    }

    public function testAFailureIsReportedAndExitsNonZero(): void
    {
        $this->reapFailure = new RuntimeException('lock wait timeout');

        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('lock wait timeout', $tester->getDisplay());
    }

    private function command(): ReapRunsCommand
    {
        $reaper = $this->createMock(StaleRunReaper::class);
        $reaper->method('reap')->willReturnCallback(
            function (): int {
                if ($this->reapFailure !== null) {
                    throw $this->reapFailure;
                }

                return $this->reaped;
            }
        );

        return new ReapRunsCommand($reaper);
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->command());
    }
}
