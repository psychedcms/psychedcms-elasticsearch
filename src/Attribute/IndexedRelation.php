<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class IndexedRelation
{
    /**
     * @param 'nested'|'object' $type      ES mapping type
     * @param array<string, array<string, mixed>> $fields  Sub-field definitions
     *        Each key is the ES field name, each value may contain:
     *          - type: ES type (text, keyword, date, integer, object…)
     *          - resolve: dot-path to traverse from the child entity (e.g. 'set.band')
     *          - properties: sub-properties for object/nested sub-fields
     *          - autocomplete: bool — add autocomplete sub-field
     */
    public function __construct(
        public readonly string $type = 'nested',
        public readonly array $fields = [],
    ) {
    }
}
