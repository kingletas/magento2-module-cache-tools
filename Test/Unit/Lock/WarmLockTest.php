<?php
/**
 * WarmLockTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Lock;

use Commerce\CacheTools\Lock\WarmLock;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WarmLockTest extends TestCase
{
    private LockManagerInterface&MockObject $lockManager;
    private LoggerInterface&MockObject $logger;
    private WarmLock $lock;

    protected function setUp(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->lock = new WarmLock($this->lockManager, $this->logger, 0);
    }

    public function testRunsTheCallbackAndReleasesTheLock(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock')->with('warm');

        $this->assertSame('done', $this->lock->runLocked('warm', static fn (): string => 'done'));
    }

    public function testSkipsWhenTheLockIsAlreadyHeld(): void
    {
        $this->lockManager->method('lock')->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');

        $ran = false;
        $result = $this->lock->runLocked('warm', static function () use (&$ran): string {
            $ran = true;

            return 'done';
        });

        $this->assertNull($result);
        $this->assertFalse($ran, 'The callback must not run when the lock is held.');
    }

    /**
     * A lock left held by a thrown callback blocks every future run.
     */
    public function testReleasesTheLockWhenTheCallbackThrows(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->expects($this->once())->method('unlock')->with('warm');

        $this->expectException(RuntimeException::class);

        $this->lock->runLocked('warm', static function (): void {
            throw new RuntimeException('boom');
        });
    }

    /**
     * Release runs inside a finally, so an exception there would mask the
     * original one.
     */
    public function testAFailingReleaseIsLoggedRatherThanThrown(): void
    {
        $this->lockManager->method('lock')->willReturn(true);
        $this->lockManager->method('unlock')->willThrowException(new RuntimeException('redis gone'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame('done', $this->lock->runLocked('warm', static fn (): string => 'done'));
    }

    /**
     * Acquisition must be one atomic call.
     */
    public function testAcquisitionIsASingleAtomicCall(): void
    {
        $this->lockManager->expects($this->once())->method('lock')->with('warm', 0)->willReturn(true);
        $this->lockManager->expects($this->never())->method('isLocked');

        $this->lock->runLocked('warm', static fn (): bool => true);
    }
}
