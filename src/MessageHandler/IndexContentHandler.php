<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\Elasticsearch\Indexing\ContentIndexerInterface;
use PsychedCms\Elasticsearch\Message\IndexContentMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IndexContentHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContentIndexerInterface $contentIndexer,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(IndexContentMessage $message): void
    {
        $entity = $this->entityManager->find($message->entityClass, $message->entityId);

        if ($entity === null) {
            $this->logger?->warning('Entity not found for indexing', [
                'class' => $message->entityClass,
                'id' => $message->entityId,
            ]);

            return;
        }

        $this->contentIndexer->indexEntity($entity);
    }
}
