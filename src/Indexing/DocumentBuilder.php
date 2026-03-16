<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Indexing;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use PsychedCms\Elasticsearch\Attribute\IndexedField;
use PsychedCms\Elasticsearch\Attribute\IndexedRelation;

class DocumentBuilder
{
    public function __construct(
        private readonly EntityMetadataReader $metadataReader,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(object $entity, string $locale, string $defaultLocale): array
    {
        $entityClass = $entity::class;
        $fields = $this->metadataReader->getIndexedFields($entityClass);

        $document = $this->buildMetadata($entity, $entityClass, $locale);

        $translations = ($locale !== $defaultLocale)
            ? $this->getTranslationsForLocale($entity, $locale)
            : [];

        foreach ($fields as $propertyName => $attribute) {
            $value = $this->extractFieldValue($entity, $propertyName, $locale, $defaultLocale, $translations);

            if ($value === null) {
                continue;
            }

            $document[$propertyName] = $this->normalizeValue($value, $attribute);
        }

        // Process IndexedRelation attributes
        $relations = $this->metadataReader->getIndexedRelations($entityClass);
        foreach ($relations as $propertyName => $relation) {
            $document[$propertyName] = $this->buildRelationData($entity, $propertyName, $relation);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMetadata(object $entity, string $entityClass, string $locale): array
    {
        $shortName = $this->getShortName($entityClass);

        $document = [
            '_content_type' => strtolower($shortName),
            '_locale' => $locale,
        ];

        if (method_exists($entity, 'getSlug')) {
            $document['_slug'] = $entity->getSlug();
        }

        if (method_exists($entity, 'getStatus')) {
            $document['_status'] = $entity->getStatus();
        }

        if (method_exists($entity, 'getCreatedAt')) {
            $createdAt = $entity->getCreatedAt();
            if ($createdAt instanceof \DateTimeInterface) {
                $document['_created_at'] = $createdAt->format('c');
            }
        }

        if (method_exists($entity, 'getUpdatedAt')) {
            $updatedAt = $entity->getUpdatedAt();
            if ($updatedAt instanceof \DateTimeInterface) {
                $document['_updated_at'] = $updatedAt->format('c');
            }
        }

        if (method_exists($entity, 'getAuthor')) {
            $author = $entity->getAuthor();
            if ($author !== null && method_exists($author, 'getId') && method_exists($author, 'getUsername')) {
                $document['_author'] = [
                    'id' => $author->getId(),
                    'username' => $author->getUsername(),
                ];
            }
        }

        return $document;
    }

    private function extractFieldValue(
        object $entity,
        string $propertyName,
        string $locale,
        string $defaultLocale,
        array $translations,
    ): mixed {
        // For non-default locale translatable fields, use translations
        if ($locale !== $defaultLocale && $this->isTranslatable($entity, $propertyName)) {
            return $translations[$propertyName] ?? null;
        }

        // Read directly from entity
        $reflectionClass = new \ReflectionClass($entity);
        $property = $reflectionClass->getProperty($propertyName);
        $property->setAccessible(true);

        return $property->getValue($entity);
    }

    private function normalizeValue(mixed $value, IndexedField $attribute): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if ($value instanceof Collection) {
            $items = [];
            foreach ($value as $item) {
                $items[] = $this->normalizeCollectionItem($item);
            }

            return $items;
        }

        if (\is_object($value) && $attribute->type === 'object') {
            return $this->normalizeObjectField($value, $attribute);
        }

        if (\is_array($value) && $attribute->type === 'geo_point') {
            return $this->normalizeGeoPoint($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeObjectField(object $value, IndexedField $attribute): array
    {
        if ($attribute->properties !== null) {
            $data = [];
            foreach ($attribute->properties as $propName => $propConfig) {
                $getter = 'get' . ucfirst($propName);
                if (method_exists($value, $getter)) {
                    $data[$propName] = $this->normalizeScalar($value->{$getter}());
                }
            }

            return $data;
        }

        return $this->normalizeCollectionItem($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCollectionItem(object $item): array
    {
        $data = ['id' => null];

        if (method_exists($item, 'getId')) {
            $data['id'] = $item->getId();
        }

        // Try common display field methods
        foreach (['getName', 'getTitle', 'getLabel', '__toString'] as $method) {
            if (method_exists($item, $method)) {
                $data['name'] = $item->{$method}();
                break;
            }
        }

        return $data;
    }

    /**
     * @return array<string, float>|null
     */
    private function normalizeGeoPoint(array $value): ?array
    {
        $lat = $value['lat'] ?? $value['latitude'] ?? null;
        $lon = $value['lng'] ?? $value['lon'] ?? $value['longitude'] ?? null;

        if (!\is_numeric($lat) || !\is_numeric($lon)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lon' => (float) $lon,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRelationData(object $entity, string $propertyName, IndexedRelation $relation): array
    {
        $reflectionClass = new \ReflectionClass($entity);
        $property = $reflectionClass->getProperty($propertyName);
        $property->setAccessible(true);
        $collection = $property->getValue($entity);

        if (!$collection instanceof \Traversable) {
            return [];
        }

        $items = [];
        foreach ($collection as $child) {
            $item = [];
            foreach ($relation->fields as $fieldName => $fieldConfig) {
                /** @var string $resolve */
                $resolve = $fieldConfig['resolve'] ?? $fieldName;
                $value = $this->resolvePropertyPath($child, $resolve);

                /** @var string $fieldType */
                $fieldType = $fieldConfig['type'] ?? 'text';

                if ($fieldType === 'object' && \is_object($value)) {
                    $subDoc = [];
                    /** @var array<string, array<string, mixed>> $subProperties */
                    $subProperties = $fieldConfig['properties'] ?? [];
                    foreach ($subProperties as $subName => $subConfig) {
                        /** @var string $subResolve */
                        $subResolve = $subConfig['resolve'] ?? $subName;
                        $subValue = $this->resolvePropertyPath($value, $subResolve);
                        $subDoc[$subName] = $this->normalizeScalar($subValue);
                    }
                    $item[$fieldName] = $subDoc;
                } else {
                    $item[$fieldName] = $this->normalizeScalar($value);
                }
            }
            $items[] = $item;
        }

        return $items;
    }

    private function resolvePropertyPath(object $object, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $object;

        foreach ($segments as $segment) {
            if (!\is_object($current)) {
                return null;
            }

            $getter = 'get' . ucfirst($segment);
            if (method_exists($current, $getter)) {
                $current = $current->{$getter}();
                continue;
            }

            // Try direct property access
            try {
                $ref = new \ReflectionClass($current);
                $prop = $ref->getProperty($segment);
                $prop->setAccessible(true);
                $current = $prop->getValue($current);
            } catch (\ReflectionException) {
                return null;
            }
        }

        return $current;
    }

    private function normalizeScalar(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        return $value;
    }

    private function isTranslatable(object $entity, string $propertyName): bool
    {
        $reflectionClass = new \ReflectionClass($entity);
        $property = $reflectionClass->getProperty($propertyName);

        return $property->getAttributes(\Gedmo\Mapping\Annotation\Translatable::class) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function getTranslationsForLocale(object $entity, string $locale): array
    {
        // Try personal translations first
        $reflectionClass = new \ReflectionClass($entity);
        foreach ($reflectionClass->getProperties() as $property) {
            $ormAttributes = $property->getAttributes(\Doctrine\ORM\Mapping\OneToMany::class);
            if ($ormAttributes === []) {
                continue;
            }

            $ormAttr = $ormAttributes[0]->newInstance();
            $targetEntity = $ormAttr->targetEntity ?? '';

            if (str_contains($targetEntity, 'Translation')) {
                $property->setAccessible(true);
                $translations = $property->getValue($entity);

                if ($translations instanceof \Traversable) {
                    $result = [];
                    foreach ($translations as $translation) {
                        if (method_exists($translation, 'getLocale') && $translation->getLocale() === $locale) {
                            if (method_exists($translation, 'getField') && method_exists($translation, 'getContent')) {
                                $result[$translation->getField()] = $translation->getContent();
                            }
                        }
                    }
                    if ($result !== []) {
                        return $result;
                    }
                }
            }
        }

        return [];
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return end($parts);
    }
}
