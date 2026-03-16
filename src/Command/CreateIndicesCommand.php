<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Command;

use PsychedCms\Elasticsearch\Index\IndexManager;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'psychedcms:search:create-indices',
    description: 'Create Elasticsearch indices for indexed entities',
)]
final class CreateIndicesCommand extends Command
{
    public function __construct(
        private readonly IndexManager $indexManager,
        private readonly EntityMetadataReader $metadataReader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Specific entity class to create index for')
            ->addOption('recreate', null, InputOption::VALUE_NONE, 'Delete and recreate existing indices');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $entityClass = $input->getOption('entity');
        $recreate = $input->getOption('recreate');

        $entities = $entityClass !== null
            ? [$entityClass]
            : $this->metadataReader->getIndexedEntities();

        if ($entities === []) {
            $io->warning('No indexed entities found.');

            return Command::SUCCESS;
        }

        foreach ($entities as $entity) {
            try {
                if ($recreate) {
                    $this->indexManager->recreateIndex($entity);
                    $io->success(sprintf('Recreated index for %s', $entity));
                } else {
                    $this->indexManager->createIndex($entity);
                    $io->success(sprintf('Created index for %s', $entity));
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed for %s: %s', $entity, $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
