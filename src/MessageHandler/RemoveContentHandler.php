<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\MessageHandler;

use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Elasticsearch\Index\IndexNameResolver;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;
use PsychedCms\Elasticsearch\Message\RemoveContentMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RemoveContentHandler
{
    public function __construct(
        private ElasticsearchClientInterface $client,
        private IndexNameResolver $nameResolver,
        private EntityMetadataReader $metadataReader,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(RemoveContentMessage $message): void
    {
        if (!$this->metadataReader->isIndexed($message->entityClass)) {
            return;
        }

        $indexName = $this->nameResolver->resolve($message->entityClass);
        $shortName = strtolower($this->getShortName($message->entityClass));

        // Remove documents for all possible locales by using a wildcard-style approach
        // Since we don't have the entity anymore, delete by known document ID pattern
        // We'll try common locales; the delete is idempotent (404 = success)
        $commonLocales = ['en', 'fr', 'de', 'es', 'it', 'nl', 'pt', 'ja', 'zh', 'ko'];

        foreach ($commonLocales as $locale) {
            $documentId = sprintf('%s_%s_%s', $shortName, $message->entityId, $locale);
            $this->client->delete($indexName, $documentId);
        }

        $this->logger?->info('Removed entity from index', [
            'class' => $message->entityClass,
            'id' => $message->entityId,
        ]);
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return end($parts);
    }
}
