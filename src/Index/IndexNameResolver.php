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

    /**
     * @deprecated Use resolveForLocale() instead. Kept for backwards compatibility.
     */
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

    public function resolveForLocale(string $entityClass, string $locale): string
    {
        return $this->resolve($entityClass) . '_' . $locale;
    }

    /**
     * @return array<string, string> locale => index name
     */
    public function resolveAllLocales(string $entityClass): array
    {
        $locales = $this->metadataReader->getLocalesForEntity($entityClass);
        $indices = [];

        foreach ($locales as $locale) {
            $indices[$locale] = $this->resolveForLocale($entityClass, $locale);
        }

        return $indices;
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
