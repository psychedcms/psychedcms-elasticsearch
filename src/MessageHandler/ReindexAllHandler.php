<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\MessageHandler;

use PsychedCms\Elasticsearch\Indexing\ContentIndexerInterface;
use PsychedCms\Elasticsearch\Message\ReindexAllMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ReindexAllHandler
{
    public function __construct(
        private ContentIndexerInterface $contentIndexer,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ReindexAllMessage $message): void
    {
        $count = $this->contentIndexer->reindexAll($message->entityClass);

        $this->logger?->info('Reindex all completed', [
            'class' => $message->entityClass,
            'count' => $count,
        ]);
    }
}
