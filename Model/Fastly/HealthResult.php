<?php
/**
 * HealthResult.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Model\Fastly;

use InvalidArgumentException;

/**
 * What the edge said about one URL.
 */
class HealthResult
{
    public const string STATE_HIT = 'HIT';
    public const string STATE_MISS = 'MISS';
    public const string STATE_PASS = 'PASS';
    public const string STATE_UNKNOWN = 'UNKNOWN';

    /**
     * @param string      $url        The probed URL.
     * @param bool        $reachable  Whether the probe completed at all.
     * @param int         $httpStatus HTTP status, or 0 when unreachable.
     * @param string      $cacheState HIT, MISS, PASS or UNKNOWN.
     * @param int|null    $age        Seconds the object has been cached.
     * @param string|null $servedBy   Edge node identifier.
     * @param string|null $error      Why the probe failed, when it did.
     */
    public function __construct(
        public readonly string $url,
        public readonly bool $reachable,
        public readonly int $httpStatus = 0,
        public readonly string $cacheState = self::STATE_UNKNOWN,
        public readonly ?int $age = null,
        public readonly ?string $servedBy = null,
        public readonly ?string $error = null
    ) {
        if (!$this->reachable && $this->error === null) {
            throw new InvalidArgumentException('An unreachable probe must say why it failed.');
        }
    }

    public function isCached(): bool
    {
        return $this->cacheState === self::STATE_HIT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'reachable' => $this->reachable,
            'http_status' => $this->httpStatus,
            'cache_state' => $this->cacheState,
            'age' => $this->age,
            'served_by' => $this->servedBy,
            'error' => $this->error,
        ];
    }
}
