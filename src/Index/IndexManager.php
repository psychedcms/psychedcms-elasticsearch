<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Index;

use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;
use Psr\Log\LoggerInterface;

final class IndexManager
{
    public function __construct(
        private readonly ElasticsearchClientInterface $client,
        private readonly IndexMappingService $mappingService,
        private readonly IndexNameResolver $nameResolver,
        private readonly EntityMetadataReader $metadataReader,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function createIndex(string $entityClass): void
    {
        $indexName = $this->nameResolver->resolve($entityClass);
        $settings = $this->mappingService->getIndexSettings();
        $mappings = $this->mappingService->getMappingForEntity($entityClass);

        $this->client->createIndex($indexName, $settings, $mappings);

        $this->logger?->info('Created index', ['index' => $indexName, 'entity' => $entityClass]);
    }

    public function deleteIndex(string $entityClass): void
    {
        $indexName = $this->nameResolver->resolve($entityClass);

        $this->client->deleteIndex($indexName);

        $this->logger?->info('Deleted index', ['index' => $indexName, 'entity' => $entityClass]);
    }

    public function recreateIndex(string $entityClass): void
    {
        $this->deleteIndex($entityClass);
        $this->createIndex($entityClass);
    }

    /**
     * @return array<string, mixed>
     */
    public function getIndexStatus(string $entityClass): array
    {
        $indexName = $this->nameResolver->resolve($entityClass);
        $exists = $this->client->indexExists($indexName);

        $status = [
            'index' => $indexName,
            'entity' => $entityClass,
            'exists' => $exists,
        ];

        if ($exists) {
            $info = $this->client->getIndexInfo($indexName);
            if ($info !== null) {
                $indexStats = $info['stats']['indices'][$indexName] ?? [];
                $status['docs_count'] = $indexStats['primaries']['docs']['count'] ?? 0;
                $status['size'] = $indexStats['primaries']['store']['size_in_bytes'] ?? 0;
            }
        }

        return $status;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllIndicesStatus(): array
    {
        $statuses = [];

        foreach ($this->metadataReader->getIndexedEntities() as $entityClass) {
            $statuses[$entityClass] = $this->getIndexStatus($entityClass);
        }

        return $statuses;
    }
}
