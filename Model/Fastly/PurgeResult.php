<?php
/**
 * PurgeResult.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly;

use Magento\Framework\Phrase;

/**
 * Outcome of one purge.
 */
class PurgeResult
{
    public function __construct(
        public readonly string $target,
        public readonly bool $isSuccess,
        public readonly Phrase $message
    ) {
    }

    /**
     * @return array{target: string, status: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'target' => $this->target,
            'status' => $this->isSuccess ? 'success' : 'error',
            'message' => (string) $this->message,
        ];
    }
}
