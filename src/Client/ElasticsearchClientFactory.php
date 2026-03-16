<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Client;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;

final class ElasticsearchClientFactory
{
    public function __construct(
        private readonly string $elasticsearchUrl,
    ) {
    }

    public function create(): Client
    {
        return ClientBuilder::create()
            ->setHosts([$this->elasticsearchUrl])
            ->build();
    }
}
