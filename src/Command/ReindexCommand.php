<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Command;

use PsychedCms\Elasticsearch\Indexing\ContentIndexerInterface;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'psychedcms:search:reindex',
    description: 'Reindex all entities to Elasticsearch',
)]
final class ReindexCommand extends Command
{
    public function __construct(
        private readonly ContentIndexerInterface $contentIndexer,
        private readonly EntityMetadataReader $metadataReader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Specific entity class to reindex')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for bulk indexing', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entityClass = $input->getOption('entity');
        $batchSize = (int) $input->getOption('batch-size');

        $entities = $entityClass !== null
            ? [$entityClass]
            : $this->metadataReader->getIndexedEntities();

        if ($entities === []) {
            $io->warning('No indexed entities found.');

            return Command::SUCCESS;
        }

        $totalCount = 0;

        foreach ($entities as $entity) {
            $io->section(sprintf('Reindexing %s...', $entity));

            try {
                $count = $this->contentIndexer->reindexAll($entity, $batchSize);
                $totalCount += $count;
                $io->success(sprintf('Indexed %d documents for %s', $count, $entity));
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed for %s: %s', $entity, $e->getMessage()));
            }
        }

        $io->success(sprintf('Total: %d documents indexed.', $totalCount));

        return Command::SUCCESS;
    }
}
