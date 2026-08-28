<?php
/**
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Test\Unit\Fake;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that keeps what it was told, by level.
 */
class RecordingLogger extends AbstractLogger
{
    /** @var array<int, string> */
    public array $warnings = [];

    /** @var array<int, string> */
    public array $errors = [];

    /** @var array<int, string> */
    public array $infos = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $message = (string) $message;

        match ((string) $level) {
            'info' => $this->infos[] = $message,
            'warning' => $this->warnings[] = $message,
            'error', 'critical', 'alert', 'emergency' => $this->errors[] = $message,
            default => null,
        };
    }
}
