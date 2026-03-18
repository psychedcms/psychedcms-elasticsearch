<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Command;

use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Elasticsearch\Index\IndexManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'psychedcms:search:status',
    description: 'Show Elasticsearch index status',
)]
final class SearchStatusCommand extends Command
{
    public function __construct(
        private readonly ElasticsearchClientInterface $client,
        private readonly IndexManager $indexManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check cluster availability
        if (!$this->client->isAvailable()) {
            $io->error('Elasticsearch cluster is not available.');

            return Command::FAILURE;
        }

        $io->success('Elasticsearch cluster is available.');

        // Get all index statuses
        $statuses = $this->indexManager->getAllIndicesStatus();

        if ($statuses === []) {
            $io->warning('No indexed entities found.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($statuses as $status) {
            $rows[] = [
                $status['index'],
                $this->getShortName($status['entity']),
                $status['locale'] ?? '-',
                $status['exists'] ? 'Yes' : 'No',
                $status['docs_count'] ?? '-',
                isset($status['size']) ? $this->formatBytes($status['size']) : '-',
            ];
        }

        $io->table(
            ['Index', 'Entity', 'Locale', 'Exists', 'Docs', 'Size'],
            $rows
        );

        return Command::SUCCESS;
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return end($parts);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 1) . ' ' . $units[$i];
    }
}
