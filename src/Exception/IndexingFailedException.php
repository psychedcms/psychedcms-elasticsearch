<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Exception;

final class IndexingFailedException extends \RuntimeException implements SearchExceptionInterface
{
    public function __construct(
        private readonly string $entityClass,
        private readonly int|string $entityId,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Failed to index entity %s#%s.', $entityClass, $entityId),
            500,
            $previous
        );
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): int|string
    {
        return $this->entityId;
    }
}
