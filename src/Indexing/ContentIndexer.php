<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Indexing;

use Doctrine\ORM\EntityManagerInterface;
use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Elasticsearch\Index\IndexNameResolver;
use Psr\Log\LoggerInterface;

final class ContentIndexer implements ContentIndexerInterface
{
    public function __construct(
        private readonly ElasticsearchClientInterface $client,
        private readonly DocumentBuilder $documentBuilder,
        private readonly SearchTranslationValidator $translationValidator,
        private readonly IndexNameResolver $nameResolver,
        private readonly EntityMetadataReader $metadataReader,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function indexEntity(object $entity): void
    {
        $entityClass = $entity::class;

        if (!$this->metadataReader->isIndexed($entityClass)) {
            return;
        }

        $indexName = $this->nameResolver->resolve($entityClass);
        $entityId = $this->getEntityId($entity);
        $locales = $this->translationValidator->getLocales($entity);
        $defaultLocale = $this->translationValidator->getDefaultLocale($entity);

        foreach ($locales as $locale) {
            $documentId = $this->generateDocumentId($entityClass, $entityId, $locale);

            if ($locale === $defaultLocale || $this->translationValidator->isLocaleComplete($entity, $locale)) {
                $document = $this->documentBuilder->build($entity, $locale, $defaultLocale);
                $this->client->index($indexName, $documentId, $document);

                $this->logger?->info('Indexed entity', [
                    'entity' => $entityClass,
                    'id' => $entityId,
                    'locale' => $locale,
                ]);
            } else {
                // Remove incomplete locale document
                $this->client->delete($indexName, $documentId);

                $this->logger?->debug('Removed incomplete locale document', [
                    'entity' => $entityClass,
                    'id' => $entityId,
                    'locale' => $locale,
                ]);
            }
        }
    }

    public function removeEntity(object $entity): void
    {
        $entityClass = $entity::class;

        if (!$this->metadataReader->isIndexed($entityClass)) {
            return;
        }

        $indexName = $this->nameResolver->resolve($entityClass);
        $entityId = $this->getEntityId($entity);
        $locales = $this->translationValidator->getLocales($entity);

        foreach ($locales as $locale) {
            $documentId = $this->generateDocumentId($entityClass, $entityId, $locale);
            $this->client->delete($indexName, $documentId);
        }

        $this->logger?->info('Removed entity from index', [
            'entity' => $entityClass,
            'id' => $entityId,
        ]);
    }

    public function reindexAll(string $entityClass, int $batchSize = 100): int
    {
        $indexName = $this->nameResolver->resolve($entityClass);

        $query = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($entityClass, 'e')
            ->getQuery();

        $count = 0;
        $bulkOperations = [];

        foreach ($query->toIterable() as $entity) {
            $entityId = $this->getEntityId($entity);
            $locales = $this->translationValidator->getLocales($entity);
            $defaultLocale = $this->translationValidator->getDefaultLocale($entity);

            foreach ($locales as $locale) {
                if ($locale !== $defaultLocale && !$this->translationValidator->isLocaleComplete($entity, $locale)) {
                    continue;
                }

                $documentId = $this->generateDocumentId($entityClass, $entityId, $locale);
                $document = $this->documentBuilder->build($entity, $locale, $defaultLocale);

                $bulkOperations[] = ['index' => ['_index' => $indexName, '_id' => $documentId]];
                $bulkOperations[] = $document;
                $count++;

                if (\count($bulkOperations) >= $batchSize * 2) {
                    $this->client->bulk($bulkOperations);
                    $bulkOperations = [];
                    $this->entityManager->clear();
                }
            }
        }

        if ($bulkOperations !== []) {
            $this->client->bulk($bulkOperations);
        }

        $this->client->refresh($indexName);

        $this->logger?->info('Reindexed all entities', [
            'entity' => $entityClass,
            'count' => $count,
        ]);

        return $count;
    }

    private function generateDocumentId(string $entityClass, int|string $entityId, string $locale): string
    {
        $shortName = strtolower($this->getShortName($entityClass));

        return sprintf('%s_%s_%s', $shortName, $entityId, $locale);
    }

    private function getEntityId(object $entity): int|string
    {
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }

        throw new \RuntimeException(sprintf('Entity %s does not have a getId() method.', $entity::class));
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return end($parts);
    }
}
