<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Command;

use PsychedCms\Elasticsearch\Index\IndexManager;
use PsychedCms\Elasticsearch\Indexing\ContentIndexerInterface;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'psychedcms:search:migrate-to-locale-indices',
    description: 'Migrate from single indices to per-locale indices (e.g. hilo_bands → hilo_bands_fr + hilo_bands_en)',
)]
final class MigrateToLocaleIndicesCommand extends Command
{
    public function __construct(
        private readonly IndexManager $indexManager,
        private readonly ContentIndexerInterface $contentIndexer,
        private readonly EntityMetadataReader $metadataReader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Batch size for bulk indexing', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = (int) $input->getOption('batch-size');

        $entities = $this->metadataReader->getIndexedEntities();

        if ($entities === []) {
            $io->warning('No indexed entities found.');

            return Command::SUCCESS;
        }

        // Step 1: Delete legacy indices
        $io->section('Step 1: Deleting legacy indices...');
        foreach ($entities as $entityClass) {
            try {
                $deleted = $this->indexManager->deleteLegacyIndex($entityClass);
                if ($deleted) {
                    $io->text(sprintf('  Deleted legacy index for %s', $this->getShortName($entityClass)));
                } else {
                    $io->text(sprintf('  No legacy index found for %s', $this->getShortName($entityClass)));
                }
            } catch (\Throwable $e) {
                $io->warning(sprintf('  Failed to delete legacy index for %s: %s', $this->getShortName($entityClass), $e->getMessage()));
            }
        }

        // Step 2: Create per-locale indices
        $io->section('Step 2: Creating per-locale indices...');
        foreach ($entities as $entityClass) {
            try {
                $this->indexManager->createIndex($entityClass);
                $io->text(sprintf('  Created locale indices for %s', $this->getShortName($entityClass)));
            } catch (\Throwable $e) {
                $io->error(sprintf('  Failed to create indices for %s: %s', $this->getShortName($entityClass), $e->getMessage()));

                return Command::FAILURE;
            }
        }

        // Step 3: Reindex everything
        $io->section('Step 3: Reindexing all content...');
        $totalCount = 0;
        foreach ($entities as $entityClass) {
            try {
                $count = $this->contentIndexer->reindexAll($entityClass, $batchSize);
                $totalCount += $count;
                $io->text(sprintf('  Indexed %d documents for %s', $count, $this->getShortName($entityClass)));
            } catch (\Throwable $e) {
                $io->error(sprintf('  Failed to reindex %s: %s', $this->getShortName($entityClass), $e->getMessage()));
            }
        }

        $io->success(sprintf('Migration complete. %d documents indexed across all locale indices.', $totalCount));

        return Command::SUCCESS;
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return end($parts);
    }
}
