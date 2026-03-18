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
        $mappings = $this->mappingService->getMappingForEntity($entityClass);
        $localeIndices = $this->nameResolver->resolveAllLocales($entityClass);

        foreach ($localeIndices as $locale => $indexName) {
            $settings = $this->mappingService->getIndexSettings($locale);
            $this->client->createIndex($indexName, $settings, $mappings);

            $this->logger?->info('Created index', ['index' => $indexName, 'entity' => $entityClass, 'locale' => $locale]);
        }
    }

    public function deleteIndex(string $entityClass): void
    {
        $localeIndices = $this->nameResolver->resolveAllLocales($entityClass);

        foreach ($localeIndices as $locale => $indexName) {
            if ($this->client->indexExists($indexName)) {
                $this->client->deleteIndex($indexName);
                $this->logger?->info('Deleted index', ['index' => $indexName, 'entity' => $entityClass, 'locale' => $locale]);
            }
        }
    }

    public function recreateIndex(string $entityClass): void
    {
        $this->deleteIndex($entityClass);
        $this->createIndex($entityClass);
    }

    /**
     * Delete legacy (non-locale) index if it exists.
     */
    public function deleteLegacyIndex(string $entityClass): bool
    {
        $legacyName = $this->nameResolver->resolve($entityClass);

        if ($this->client->indexExists($legacyName)) {
            $this->client->deleteIndex($legacyName);
            $this->logger?->info('Deleted legacy index', ['index' => $legacyName]);

            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getIndexStatus(string $entityClass): array
    {
        $localeIndices = $this->nameResolver->resolveAllLocales($entityClass);
        $statuses = [];

        foreach ($localeIndices as $locale => $indexName) {
            $exists = $this->client->indexExists($indexName);

            $status = [
                'index' => $indexName,
                'entity' => $entityClass,
                'locale' => $locale,
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

            $statuses[$locale] = $status;
        }

        return $statuses;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllIndicesStatus(): array
    {
        $statuses = [];

        foreach ($this->metadataReader->getIndexedEntities() as $entityClass) {
            $localeStatuses = $this->getIndexStatus($entityClass);
            foreach ($localeStatuses as $locale => $status) {
                $key = $entityClass . '_' . $locale;
                $statuses[$key] = $status;
            }
        }

        return $statuses;
    }
}
