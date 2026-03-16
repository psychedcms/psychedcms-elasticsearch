<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Indexed
{
    public function __construct(
        public readonly ?string $indexName = null,
    ) {
    }
}
