<?php
/**
 * ReapRunsCommand.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Console\Warmer;

use Commerce\CacheTools\Model\Warmer\Run\StaleRunReaper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Closes warm runs that have stopped making progress.
 */
class ReapRunsCommand extends Command
{
    public function __construct(
        private readonly StaleRunReaper $reaper,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Close cache-warming runs that have made no progress within the configured window.');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $reaped = $this->reaper->reap();
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Reaping failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln($reaped === 0
            ? '<info>No stale runs to reap.</info>'
            : sprintf('<info>Reaped %d stale run(s).</info>', $reaped));

        return Command::SUCCESS;
    }
}
