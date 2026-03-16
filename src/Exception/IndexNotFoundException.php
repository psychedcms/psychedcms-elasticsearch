<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Exception;

final class IndexNotFoundException extends \RuntimeException implements SearchExceptionInterface
{
    public function __construct(
        private readonly string $indexName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Elasticsearch index "%s" not found.', $indexName),
            404,
            $previous
        );
    }

    public function getIndexName(): string
    {
        return $this->indexName;
    }
}
