<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Message;

final readonly class IndexContentMessage
{
    public function __construct(
        public string $entityClass,
        public int|string $entityId,
    ) {
    }
}
