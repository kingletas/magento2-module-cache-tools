<?php
/**
 * HealthCheckCommand.php
 *
 * @package     Commerce_CacheTools
 * @copyright   Copyright (c) the Commerce modules authors
 * @license     OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

namespace Commerce\CacheTools\Console\Varnish;

use Commerce\CacheTools\Model\Fastly\VarnishHealthCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reports whether the edge is serving given URLs from cache.
 */
class HealthCheckCommand extends Command
{
    private const string ARGUMENT_URLS = 'urls';

    public function __construct(
        private readonly VarnishHealthCheck $healthCheck,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription('Report how the edge cache is serving one or more URLs.')
            ->addArgument(
                self::ARGUMENT_URLS,
                InputArgument::IS_ARRAY | InputArgument::REQUIRED,
                'One or more absolute URLs to probe.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string[] $urls */
        $urls = (array) $input->getArgument(self::ARGUMENT_URLS);

        $table = new Table($output);
        $table->setHeaders(['URL', 'HTTP', 'Cache', 'Age', 'Served by']);

        $allReachable = true;

        foreach ($urls as $url) {
            $result = $this->healthCheck->check((string) $url);

            if (!$result->reachable) {
                $allReachable = false;
                $table->addRow([$result->url, '-', '<error>unreachable</error>', '-', $result->error ?? '']);
                continue;
            }

            $table->addRow([
                $result->url,
                $result->httpStatus,
                $result->isCached()
                    ? sprintf('<info>%s</info>', $result->cacheState)
                    : sprintf('<comment>%s</comment>', $result->cacheState),
                $result->age !== null ? $result->age . 's' : '-',
                $result->servedBy ?? '-',
            ]);
        }

        $table->render();

        return $allReachable ? Command::SUCCESS : Command::FAILURE;
    }
}
