<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Message;

final readonly class ReindexAllMessage
{
    public function __construct(
        public string $entityClass,
    ) {
    }
}
