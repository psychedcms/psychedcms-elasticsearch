<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Indexing;

interface ContentIndexerInterface
{
    public function indexEntity(object $entity): void;

    public function removeEntity(object $entity): void;

    public function reindexAll(string $entityClass, int $batchSize = 100): int;
}
