<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Index;

use Doctrine\Common\Collections\Collection;
use PsychedCms\Elasticsearch\Attribute\IndexedField;
use PsychedCms\Elasticsearch\Attribute\IndexedRelation;
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
        $properties['_author'] = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'keyword'],
                'username' => ['type' => 'keyword'],
            ],
        ];

        foreach ($fields as $propertyName => $attribute) {
            $esType = $this->resolveEsType($entityClass, $propertyName, $attribute);
            $properties[$propertyName] = $this->buildFieldMapping($attribute, $esType);
        }

        // Include facetable fields from relations with useRelationFacets=true
        foreach ($fields as $propertyName => $attribute) {
            if (!$attribute->useRelationFacets) {
                continue;
            }

            if (!isset($properties[$propertyName]['properties'])) {
                continue;
            }

            $relationFacetMappings = $this->getRelationFacetMappings($entityClass, $propertyName);
            if ($relationFacetMappings !== []) {
                $properties[$propertyName]['properties'] = array_merge(
                    $properties[$propertyName]['properties'],
                    $relationFacetMappings
                );
            }
        }

        // Process IndexedRelation attributes
        $relations = $this->metadataReader->getIndexedRelations($entityClass);
        foreach ($relations as $propertyName => $relation) {
            $properties[$propertyName] = $this->buildRelationMapping($relation);
        }

        return ['properties' => $properties];
    }

    /**
     * @return array<string, mixed>
     */
    public function getIndexSettings(string $locale = 'fr'): array
    {
        $languageAnalyzer = match ($locale) {
            'en' => 'english',
            default => 'french',
        };

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
                    'content_analyzer' => [
                        'type' => $languageAnalyzer,
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getRelationFacetMappings(string $entityClass, string $propertyName): array
    {
        $em = $this->metadataReader->getEntityManager();
        $classMetadata = $em->getClassMetadata($entityClass);

        if (!$classMetadata->hasAssociation($propertyName)) {
            return [];
        }

        $targetClass = $classMetadata->getAssociationTargetClass($propertyName);
        $targetFields = $this->metadataReader->getIndexedFields($targetClass);
        $mappings = [];

        foreach ($targetFields as $targetFieldName => $targetAttribute) {
            if (!$targetAttribute->facetable) {
                continue;
            }

            $targetEsType = $this->resolveEsType($targetClass, $targetFieldName, $targetAttribute);
            $mappings[$targetFieldName] = $this->buildFieldMapping($targetAttribute, $targetEsType);
        }

        return $mappings;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRelationMapping(IndexedRelation $relation): array
    {
        $mapping = ['type' => $relation->type];
        $subProperties = [];

        foreach ($relation->fields as $fieldName => $fieldConfig) {
            $fieldType = $fieldConfig['type'] ?? 'text';
            $enabled = $fieldConfig['enabled'] ?? true;

            if (!$enabled) {
                $subProperties[$fieldName] = ['type' => 'object', 'enabled' => false];
                continue;
            }

            if ($fieldType === 'object' || $fieldType === 'nested') {
                $subMapping = ['type' => $fieldType];
                /** @var array<string, array<string, mixed>> $nestedFieldProps */
                $nestedFieldProps = $fieldConfig['properties'] ?? [];
                if ($nestedFieldProps !== []) {
                    $nestedProps = [];
                    foreach ($nestedFieldProps as $subName => $subConfig) {
                        $nestedProps[$subName] = $this->buildRelationSubFieldMapping($subConfig);
                    }
                    $subMapping['properties'] = $nestedProps;
                }
                $subProperties[$fieldName] = $subMapping;
            } else {
                $subProperties[$fieldName] = $this->buildRelationSubFieldMapping($fieldConfig);
            }
        }

        if ($subProperties !== []) {
            $mapping['properties'] = $subProperties;
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildRelationSubFieldMapping(array $config): array
    {
        $type = $config['type'] ?? 'text';

        if (\in_array($type, ['date', 'integer', 'float', 'boolean', 'keyword', 'geo_point'], true)) {
            $mapping = ['type' => $type];
        } else {
            $mapping = ['type' => 'text'];
        }

        $autocomplete = $config['autocomplete'] ?? false;
        if ($autocomplete && $type !== 'keyword') {
            $mapping['fields'] = [
                'autocomplete' => [
                    'type' => 'text',
                    'analyzer' => 'autocomplete_analyzer',
                    'search_analyzer' => 'autocomplete_search_analyzer',
                ],
            ];
        } elseif ($autocomplete && $type === 'keyword') {
            $mapping['fields'] = [
                'autocomplete' => [
                    'type' => 'text',
                    'analyzer' => 'autocomplete_analyzer',
                    'search_analyzer' => 'autocomplete_search_analyzer',
                ],
            ];
        }

        return $mapping;
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
        // enabled: false — stored in _source but not indexed (for display-only data)
        if (!$attribute->enabled) {
            return ['type' => 'object', 'enabled' => false];
        }

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
                    'id' => ['type' => 'keyword'],
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
