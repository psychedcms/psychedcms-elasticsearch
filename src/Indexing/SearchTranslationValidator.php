<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Indexing;

use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Repository\TranslationRepository;
use PsychedCms\Core\Attribute\ContentType;
use Symfony\Component\Validator\Constraints as Assert;

final class SearchTranslationValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Check if all translatable fields have content for the given locale.
     */
    public function isLocaleComplete(object $entity, string $locale): bool
    {
        $translatableFields = $this->getTranslatableFields($entity);

        if ($translatableFields === []) {
            return true; // No translatable fields = always complete
        }

        $defaultLocale = $this->getDefaultLocale($entity);

        if ($locale === $defaultLocale) {
            return true; // Default locale values are on the entity itself
        }

        $translations = $this->getTranslationsForLocale($entity, $locale);

        foreach ($translatableFields as $fieldName) {
            $value = $translations[$fieldName] ?? null;
            if ($value === null || $value === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string>
     */
    public function getLocales(object $entity): array
    {
        $reflectionClass = new \ReflectionClass($entity);
        $attributes = $reflectionClass->getAttributes(ContentType::class);

        if ($attributes !== []) {
            $contentType = $attributes[0]->newInstance();
            if ($contentType->locales !== null) {
                return $contentType->locales;
            }
        }

        return ['en']; // fallback
    }

    public function getDefaultLocale(object $entity): string
    {
        $locales = $this->getLocales($entity);

        return $locales[0] ?? 'en';
    }

    /**
     * Get translatable fields that are required for publication.
     * Only returns fields that have both #[Gedmo\Translatable] AND
     * #[Assert\NotBlank(groups: ['published'])].
     *
     * @return array<string>
     */
    private function getTranslatableFields(object $entity): array
    {
        $fields = [];
        $reflectionClass = new \ReflectionClass($entity);

        foreach ($reflectionClass->getProperties() as $property) {
            $translatableAttrs = $property->getAttributes(\Gedmo\Mapping\Annotation\Translatable::class);
            if ($translatableAttrs === []) {
                continue;
            }

            if (!$this->hasPublishedValidation($property)) {
                continue;
            }

            $fields[] = $property->getName();
        }

        return $fields;
    }

    private function hasPublishedValidation(\ReflectionProperty $property): bool
    {
        $notBlankAttrs = $property->getAttributes(Assert\NotBlank::class);
        foreach ($notBlankAttrs as $attr) {
            $instance = $attr->newInstance();
            if (\in_array('published', $instance->groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTranslationsForLocale(object $entity, string $locale): array
    {
        // Try personal translations first (OneToMany on entity)
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
}
