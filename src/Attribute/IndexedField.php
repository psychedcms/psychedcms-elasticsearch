<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class IndexedField
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?string $analyzer = null,
        public readonly ?float $boost = null,
        public readonly bool $autocomplete = false,
        public readonly bool $filterable = false,
        public readonly bool $sortable = false,
        public readonly bool $facetable = false,
        public readonly ?array $properties = null,
        public readonly bool $enabled = true,
    ) {
    }
}
