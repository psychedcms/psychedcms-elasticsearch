<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Indexing;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\Elasticsearch\Attribute\Indexed;
use PsychedCms\Elasticsearch\Attribute\IndexedField;

final class EntityMetadataReader
{
    /** @var array<string, Indexed|null> */
    private array $indexedCache = [];

    /** @var array<string, array<string, IndexedField>> */
    private array $fieldsCache = [];

    /** @var array<string>|null */
    private ?array $indexedEntitiesCache = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getIndexedAttribute(string $entityClass): ?Indexed
    {
        if (\array_key_exists($entityClass, $this->indexedCache)) {
            return $this->indexedCache[$entityClass];
        }

        $reflectionClass = new \ReflectionClass($entityClass);
        $attributes = $reflectionClass->getAttributes(Indexed::class);

        $this->indexedCache[$entityClass] = $attributes !== []
            ? $attributes[0]->newInstance()
            : null;

        return $this->indexedCache[$entityClass];
    }

    public function isIndexed(string $entityClass): bool
    {
        return $this->getIndexedAttribute($entityClass) !== null;
    }

    /**
     * @return array<string, IndexedField>
     */
    public function getIndexedFields(string $entityClass): array
    {
        if (isset($this->fieldsCache[$entityClass])) {
            return $this->fieldsCache[$entityClass];
        }

        $fields = [];
        $reflectionClass = new \ReflectionClass($entityClass);

        foreach ($reflectionClass->getProperties() as $property) {
            $attributes = $property->getAttributes(IndexedField::class);
            if ($attributes !== []) {
                $fields[$property->getName()] = $attributes[0]->newInstance();
            }
        }

        $this->fieldsCache[$entityClass] = $fields;

        return $fields;
    }

    /**
     * @return array<string> Entity class names with #[Indexed]
     */
    public function getIndexedEntities(): array
    {
        if ($this->indexedEntitiesCache !== null) {
            return $this->indexedEntitiesCache;
        }

        $entities = [];
        $allMetadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        foreach ($allMetadata as $metadata) {
            $className = $metadata->getName();
            if ($this->isIndexed($className)) {
                $entities[] = $className;
            }
        }

        $this->indexedEntitiesCache = $entities;

        return $entities;
    }

    /**
     * Get the PHP type of a property via reflection.
     */
    public function getPropertyType(string $entityClass, string $propertyName): ?string
    {
        $reflectionClass = new \ReflectionClass($entityClass);
        $property = $reflectionClass->getProperty($propertyName);
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return null;
    }
}
