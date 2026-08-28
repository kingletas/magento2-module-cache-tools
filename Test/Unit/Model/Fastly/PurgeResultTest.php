<?php
/**
 * PurgeResultTest.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Model\Fastly;

use Commerce\CacheTools\Model\Fastly\PurgeResult;
use PHPUnit\Framework\TestCase;

class PurgeResultTest extends TestCase
{
    /**
     * The array shape is what the admin controller renders.
     */
    public function testTheArrayShapeDerivesItsStatusFromTheBoolean(): void
    {
        $success = new PurgeResult('https://example.test/p', isSuccess: true, message: __('Sent.'));
        $failure = new PurgeResult('keys', isSuccess: false, message: __('No.'));

        self::assertSame(
            ['target' => 'https://example.test/p', 'status' => 'success', 'message' => 'Sent.'],
            $success->toArray()
        );
        self::assertSame(['target' => 'keys', 'status' => 'error', 'message' => 'No.'], $failure->toArray());
    }
}
