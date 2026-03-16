<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
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
        $entity = $args->getObject();

        if (!$this->metadataReader->isIndexed($entity::class)) {
            return;
        }

        $this->messageBus->dispatch(new IndexContentMessage(
            $entity::class,
            $entity->getId(),
        ));
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->metadataReader->isIndexed($entity::class)) {
            return;
        }

        $this->messageBus->dispatch(new IndexContentMessage(
            $entity::class,
            $entity->getId(),
        ));
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$this->metadataReader->isIndexed($entity::class)) {
            return;
        }

        $this->messageBus->dispatch(new RemoveContentMessage(
            $entity::class,
            $entity->getId(),
        ));
    }
}
