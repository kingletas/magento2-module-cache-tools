<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Model\Cache;

use Commerce\CacheTools\Api\KeyPatternPurgerInterface;
use Credis_Client;
use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Purges SKU-keyed cache entries from Redis with `SCAN ... MATCH`.
 */
class RedisKeyPatternPurger implements KeyPatternPurgerInterface
{
    /** Rows Redis returns per SCAN page. */
    private const int SCAN_COUNT = 1000;

    /** Keys per DEL command. */
    private const int DELETE_BATCH = 500;

    private const float CONNECT_TIMEOUT = 2.5;

    /**
     * Cache key prefix Magento's Redis backend writes under.
     */
    public const string DEFAULT_KEY_PREFIX = 'zc:k:';

    private ?Credis_Client $client = null;

    private bool $connectionFailed = false;

    /**
     * @param string $deploymentConfigPath Where the cache Redis connection is declared.
     * @param string $guardPrefix          Namespace for the de-duplication claim keys.
     * @param int    $guardTtl             Seconds a claim survives. Long enough to span
     *                                     one sync's batches, short enough that a genuinely
     *                                     later update is not skipped for long.
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly LoggerInterface $logger,
        private readonly string $deploymentConfigPath = 'cache/frontend/default/backend_options',
        private readonly string $keyPrefix = self::DEFAULT_KEY_PREFIX,
        private readonly string $guardPrefix = 'commerce:cachetools:purged:',
        private readonly int $guardTtl = 60
    ) {
    }

    public function isSupported(): bool
    {
        return class_exists(Credis_Client::class) && $this->getClient() !== null;
    }

    /**
     * @inheritDoc
     */
    public function purgeBySkus(array $skus): int
    {
        $normalised = $this->normalise($skus);

        if ($normalised === []) {
            return 0;
        }

        $client = $this->getClient();

        if ($client === null) {
            return 0;
        }

        $processed = 0;

        foreach ($normalised as $sku) {
            if (!$this->claim($client, $sku)) {
                continue;
            }

            $this->purgePattern($client, $this->keyPrefix . '*' . $sku . '*');
            $processed++;
        }

        return $processed;
    }

    /**
     * Upper-case and de-duplicate.
     *
     * @param string[] $skus
     * @return string[]
     */
    private function normalise(array $skus): array
    {
        $unique = [];

        foreach ($skus as $sku) {
            // Magento upper-cases cache ids, so the pattern must match that.
            $sku = strtoupper(trim((string) $sku));

            if ($sku !== '') {
                $unique[$sku] = $sku;
            }
        }

        return array_values($unique);
    }

    /**
     * Claim a SKU for the guard window.
     */
    private function claim(Credis_Client $client, string $sku): bool
    {
        try {
            return (bool) $client->set($this->guardPrefix . $sku, '1', ['nx', 'ex' => $this->guardTtl]);
        } catch (Throwable) {
            return true;
        }
    }

    private function purgePattern(Credis_Client $client, string $pattern): void
    {
        $deleted = 0;

        try {
            $iterator = null;

            do {
                /** @var string[]|false $keys */
                $keys = $client->scan($iterator, $pattern, self::SCAN_COUNT);

                if (!is_array($keys) || $keys === []) {
                    continue;
                }

                foreach (array_chunk($keys, self::DELETE_BATCH) as $chunk) {
                    $client->del(...$chunk);
                    $deleted += count($chunk);
                }
            } while ((int) $iterator !== 0);
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('Failed purging cache keys matching "%s": %s', $pattern, $e->getMessage()),
                ['exception' => $e]
            );

            return;
        }

        if ($deleted > 0) {
            // Once per pattern with a count, not inside the delete loop: at 500
            // keys a chunk, logging in the loop floods the log during a large
            // sync.
            $this->logger->info(sprintf('Purged %d cache key(s) matching "%s".', $deleted, $pattern));
        }
    }

    private function getClient(): ?Credis_Client
    {
        if ($this->client !== null || $this->connectionFailed) {
            return $this->client;
        }

        /** @var array<string, mixed> $options */
        $options = $this->deploymentConfig->get($this->deploymentConfigPath) ?? [];

        // Guessing defaults would connect to the wrong Redis and purge someone
        // else's keys, so bail instead.
        if (!isset($options['server'], $options['port'], $options['database'])) {
            $this->connectionFailed = true;
            $this->logger->error(sprintf(
                'No cache Redis connection is declared at "%s"; key-pattern purging is unavailable.',
                $this->deploymentConfigPath
            ));

            return null;
        }

        try {
            $client = new Credis_Client(
                (string) $options['server'],
                (int) $options['port'],
                self::CONNECT_TIMEOUT,
                '',
                (int) $options['database'],
                $options['password'] ?? null
            );
            $client->connect();
            $client->setCloseOnDestruct(false);

            $this->client = $client;
        } catch (Throwable $e) {
            $this->connectionFailed = true;
            $this->logger->error(
                'Unable to connect to the cache Redis for key-pattern purging.',
                ['exception' => $e]
            );
        }

        return $this->client;
    }
}
