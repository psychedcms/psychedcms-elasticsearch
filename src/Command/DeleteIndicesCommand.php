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
    name: 'psychedcms:search:delete-indices',
    description: 'Delete Elasticsearch indices',
)]
final class DeleteIndicesCommand extends Command
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
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Specific entity class to delete index for')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Required to confirm deletion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('force')) {
            $io->error('This command requires the --force option to confirm deletion.');

            return Command::FAILURE;
        }

        $entityClass = $input->getOption('entity');

        $entities = $entityClass !== null
            ? [$entityClass]
            : $this->metadataReader->getIndexedEntities();

        foreach ($entities as $entity) {
            try {
                $this->indexManager->deleteIndex($entity);
                $io->success(sprintf('Deleted index for %s', $entity));
            } catch (\Throwable $e) {
                $io->error(sprintf('Failed for %s: %s', $entity, $e->getMessage()));
            }
        }

        return Command::SUCCESS;
    }
}
