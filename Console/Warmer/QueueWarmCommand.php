<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Console\Warmer;

use Commerce\CacheTools\Model\Warmer\BatchQueuer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Queues a cache-warming run.
 */
class QueueWarmCommand extends Command
{
    private const string OPTION_TYPE = 'type';

    private const string TYPE_BOTH = 'both';

    public function __construct(
        private readonly BatchQueuer $batchQueuer,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Queue a cache-warming run for simple products, configurables, or both.')
            ->addOption(
                self::OPTION_TYPE,
                't',
                InputOption::VALUE_REQUIRED,
                sprintf(
                    'Which products to warm: "%s", "%s" or "%s".',
                    BatchQueuer::TYPE_SIMPLE,
                    BatchQueuer::TYPE_CONFIGURABLE,
                    self::TYPE_BOTH
                ),
                self::TYPE_BOTH
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $type = (string) $input->getOption(self::OPTION_TYPE);
        $types = $this->resolveTypes($type);

        if ($types === []) {
            $output->writeln(sprintf('<error>Unknown type "%s".</error>', $type));

            return Command::INVALID;
        }

        // Warming resolves frontend URLs and renders frontend blocks, so the
        // area must be set before any of that runs.
        $this->ensureFrontendArea();

        $queued = 0;

        foreach ($types as $warmType) {
            try {
                $runId = $this->batchQueuer->queue($warmType);
            } catch (Throwable $e) {
                $output->writeln(sprintf(
                    '<error>Failed to queue the %s run: %s</error>',
                    $warmType,
                    $e->getMessage()
                ));

                return Command::FAILURE;
            }

            if ($runId === null) {
                $output->writeln(sprintf(
                    '<comment>Skipped %s: a run of this type is already in progress.</comment>',
                    $warmType
                ));
                continue;
            }

            $queued++;
            $output->writeln(sprintf('<info>Queued %s warm run #%d.</info>', $warmType, $runId));
        }

        if ($queued > 0) {
            $output->writeln(
                'Start the consumer to process it: bin/magento queue:consumers:start commerce.cachetools.warm'
            );
        }

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function resolveTypes(string $type): array
    {
        return match ($type) {
            BatchQueuer::TYPE_SIMPLE => [BatchQueuer::TYPE_SIMPLE],
            BatchQueuer::TYPE_CONFIGURABLE => [BatchQueuer::TYPE_CONFIGURABLE],
            self::TYPE_BOTH => [BatchQueuer::TYPE_SIMPLE, BatchQueuer::TYPE_CONFIGURABLE],
            default => [],
        };
    }

    private function ensureFrontendArea(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (Throwable) {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        }
    }
}
