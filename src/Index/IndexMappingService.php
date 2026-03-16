<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Index;

use Doctrine\Common\Collections\Collection;
use PsychedCms\Elasticsearch\Attribute\IndexedField;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;

final class IndexMappingService
{
    public function __construct(
        private readonly EntityMetadataReader $metadataReader,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getMappingForEntity(string $entityClass): array
    {
        $fields = $this->metadataReader->getIndexedFields($entityClass);
        $properties = [];

        // Metadata fields present on every document
        $properties['_content_type'] = ['type' => 'keyword'];
        $properties['_slug'] = ['type' => 'keyword'];
        $properties['_status'] = ['type' => 'keyword'];
        $properties['_locale'] = ['type' => 'keyword'];
        $properties['_created_at'] = ['type' => 'date'];
        $properties['_updated_at'] = ['type' => 'date'];

        foreach ($fields as $propertyName => $attribute) {
            $esType = $this->resolveEsType($entityClass, $propertyName, $attribute);
            $properties[$propertyName] = $this->buildFieldMapping($attribute, $esType);
        }

        return ['properties' => $properties];
    }

    /**
     * @return array<string, mixed>
     */
    public function getIndexSettings(): array
    {
        return [
            'analysis' => [
                'analyzer' => [
                    'autocomplete_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'autocomplete_tokenizer',
                        'filter' => ['lowercase'],
                    ],
                    'autocomplete_search_analyzer' => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'filter' => ['lowercase'],
                    ],
                ],
                'tokenizer' => [
                    'autocomplete_tokenizer' => [
                        'type' => 'edge_ngram',
                        'min_gram' => 2,
                        'max_gram' => 15,
                        'token_chars' => ['letter', 'digit'],
                    ],
                ],
            ],
        ];
    }

    private function resolveEsType(string $entityClass, string $propertyName, IndexedField $attribute): string
    {
        if ($attribute->type !== null) {
            return $attribute->type;
        }

        $phpType = $this->metadataReader->getPropertyType($entityClass, $propertyName);

        return match ($phpType) {
            'string' => 'text',
            'int' => 'integer',
            'float' => 'float',
            'bool' => 'boolean',
            \DateTimeInterface::class, \DateTimeImmutable::class, \DateTime::class => 'date',
            'array' => 'object',
            Collection::class, 'Doctrine\Common\Collections\Collection' => 'nested',
            default => 'text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFieldMapping(IndexedField $attribute, string $esType): array
    {
        if ($esType === 'geo_point') {
            return ['type' => 'geo_point'];
        }

        if ($esType === 'nested' || $esType === 'object') {
            $mapping = ['type' => $esType];
            if ($attribute->properties !== null) {
                $mapping['properties'] = $attribute->properties;
            } else {
                // Default sub-properties for relations
                $mapping['properties'] = [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'keyword'],
                ];
            }

            return $mapping;
        }

        if (\in_array($esType, ['date', 'integer', 'float', 'boolean', 'keyword'], true)) {
            return ['type' => $esType];
        }

        // Text type with optional sub-fields
        $mapping = ['type' => 'text'];

        if ($attribute->analyzer !== null) {
            $mapping['analyzer'] = $attribute->analyzer;
        }

        $subFields = [];

        if ($attribute->autocomplete) {
            $subFields['autocomplete'] = [
                'type' => 'text',
                'analyzer' => 'autocomplete_analyzer',
                'search_analyzer' => 'autocomplete_search_analyzer',
            ];
        }

        if ($attribute->filterable || $attribute->sortable) {
            $subFields['raw'] = [
                'type' => 'keyword',
                'ignore_above' => 256,
            ];
        }

        if ($subFields !== []) {
            $mapping['fields'] = $subFields;
        }

        return $mapping;
    }
}
