<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Index;

use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;

final class IndexNameResolver
{
    public function __construct(
        private readonly EntityMetadataReader $metadataReader,
        private readonly string $indexPrefix = 'psychedcms_',
    ) {
    }

    public function resolve(string $entityClass): string
    {
        $indexed = $this->metadataReader->getIndexedAttribute($entityClass);

        if ($indexed === null) {
            throw new \InvalidArgumentException(
                sprintf('Entity "%s" is not marked with #[Indexed].', $entityClass)
            );
        }

        $indexName = $indexed->indexName ?? $this->getShortName($entityClass);

        return $this->indexPrefix . strtolower($indexName);
    }

    public function getPrefix(): string
    {
        return $this->indexPrefix;
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return strtolower(end($parts));
    }
}
