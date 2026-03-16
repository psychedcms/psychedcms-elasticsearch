<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Exception;

final class ElasticsearchUnavailableException extends \RuntimeException implements SearchExceptionInterface
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Elasticsearch cluster is not available.', 503, $previous);
    }
}
