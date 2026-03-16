<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use PsychedCms\Core\Attribute\ContentType;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;
use PsychedCms\Elasticsearch\Message\IndexContentMessage;
use PsychedCms\Elasticsearch\Message\RemoveContentMessage;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
final readonly class IndexingListener
{
    public function __construct(
        private EntityMetadataReader $metadataReader,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $this->handleIndexing($args->getObject());
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $this->handleIndexing($args->getObject());
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($this->metadataReader->isIndexed($entity::class) && method_exists($entity, 'getId')) {
            $id = $entity->getId();
            $this->messageBus->dispatch(new RemoveContentMessage(
                $entity::class,
                $id instanceof \Stringable ? (string) $id : $id,
            ));

            return;
        }

        // For non-indexed entities with an aggregate root, reindex the parent
        $this->dispatchParentReindex($entity);
    }

    private function handleIndexing(object $entity): void
    {
        if ($this->metadataReader->isIndexed($entity::class) && method_exists($entity, 'getId')) {
            $id = $entity->getId();
            $this->messageBus->dispatch(new IndexContentMessage(
                $entity::class,
                $id instanceof \Stringable ? (string) $id : $id,
            ));

            return;
        }

        // For non-indexed entities with an aggregate root, reindex the parent
        $this->dispatchParentReindex($entity);
    }

    private function dispatchParentReindex(object $entity): void
    {
        $aggregateRoot = $this->getAggregateRoot($entity::class);
        if ($aggregateRoot === null) {
            return;
        }

        // Find the parent entity via ManyToOne relations
        $parent = $this->findParentEntity($entity, $aggregateRoot);
        if ($parent === null || !method_exists($parent, 'getId')) {
            return;
        }

        $parentId = $parent->getId();
        $this->messageBus->dispatch(new IndexContentMessage(
            $parent::class,
            $parentId instanceof \Stringable ? (string) $parentId : $parentId,
        ));
    }

    /**
     * @param class-string $entityClass
     */
    private function getAggregateRoot(string $entityClass): ?string
    {
        $reflectionClass = new \ReflectionClass($entityClass);
        $attributes = $reflectionClass->getAttributes(ContentType::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance()->aggregateRoot;
    }

    /**
     * Find the parent entity by scanning ManyToOne relations for an #[Indexed] entity
     * whose content type matches the aggregate root slug.
     */
    private function findParentEntity(object $entity, string $aggregateRootSlug): ?object
    {
        $reflectionClass = new \ReflectionClass($entity);

        foreach ($reflectionClass->getProperties() as $property) {
            $ormAttributes = $property->getAttributes(\Doctrine\ORM\Mapping\ManyToOne::class);
            if ($ormAttributes === []) {
                continue;
            }

            $property->setAccessible(true);
            $related = $property->getValue($entity);

            if ($related === null || !\is_object($related)) {
                continue;
            }

            // Check if the related entity is indexed and its content type matches
            if (!$this->metadataReader->isIndexed($related::class)) {
                continue;
            }

            if (method_exists($related, 'getContentType') && $related->getContentType() === $aggregateRootSlug) {
                return $related;
            }
        }

        return null;
    }
}
