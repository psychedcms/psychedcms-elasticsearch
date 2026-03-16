<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Indexing;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use PsychedCms\Elasticsearch\Attribute\IndexedField;

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

        if (\is_array($value) && $attribute->type === 'geo_point') {
            return $this->normalizeGeoPoint($value);
        }

        return $value;
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
