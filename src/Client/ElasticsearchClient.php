<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Client;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use PsychedCms\Elasticsearch\Exception\ElasticsearchUnavailableException;
use PsychedCms\Elasticsearch\Exception\IndexNotFoundException;
use PsychedCms\Elasticsearch\Exception\IndexingFailedException;
use Psr\Log\LoggerInterface;

final class ElasticsearchClient implements ElasticsearchClientInterface
{
    private Client $client;

    public function __construct(
        ElasticsearchClientFactory $clientFactory,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->client = $clientFactory->create();
    }

    public function index(string $indexName, string $id, array $document): void
    {
        try {
            $this->client->index([
                'index' => $indexName,
                'id' => $id,
                'body' => $document,
            ]);
        } catch (ClientResponseException|ServerResponseException $e) {
            $this->logger?->error('Failed to index document', [
                'index' => $indexName,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw new IndexingFailedException($indexName, $id, $e);
        }
    }

    public function delete(string $indexName, string $id): void
    {
        try {
            $this->client->delete([
                'index' => $indexName,
                'id' => $id,
            ]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return; // Idempotent: already deleted
            }
            $this->logger?->error('Failed to delete document', [
                'index' => $indexName,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (ServerResponseException $e) {
            $this->logger?->error('Server error deleting document', [
                'index' => $indexName,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function search(string|array $indexName, array $query): array
    {
        try {
            $response = $this->client->search([
                'index' => is_array($indexName) ? implode(',', $indexName) : $indexName,
                'body' => $query,
            ]);

            return $response->asArray();
        } catch (ClientResponseException|ServerResponseException $e) {
            $this->logger?->error('Search query failed', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function createIndex(string $indexName, array $settings = [], array $mappings = []): void
    {
        try {
            $params = ['index' => $indexName];
            $body = [];

            if ($settings !== []) {
                $body['settings'] = $settings;
            }
            if ($mappings !== []) {
                $body['mappings'] = $mappings;
            }
            if ($body !== []) {
                $params['body'] = $body;
            }

            $this->client->indices()->create($params);
        } catch (ClientResponseException|ServerResponseException $e) {
            $this->logger?->error('Failed to create index', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function deleteIndex(string $indexName): void
    {
        try {
            $this->client->indices()->delete(['index' => $indexName]);
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return; // Idempotent
            }
            $this->logger?->error('Failed to delete index', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } catch (ServerResponseException $e) {
            $this->logger?->error('Server error deleting index', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function indexExists(string $indexName): bool
    {
        try {
            $response = $this->client->indices()->exists(['index' => $indexName]);

            return $response->getStatusCode() === 200;
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        } catch (ServerResponseException $e) {
            $this->logger?->error('Server error checking index existence', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function bulk(array $operations): array
    {
        try {
            $response = $this->client->bulk(['body' => $operations]);

            return $response->asArray();
        } catch (ClientResponseException|ServerResponseException $e) {
            $this->logger?->error('Bulk operation failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function refresh(string $indexName): void
    {
        try {
            $this->client->indices()->refresh(['index' => $indexName]);
        } catch (ClientResponseException|ServerResponseException $e) {
            $this->logger?->error('Failed to refresh index', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function isAvailable(): bool
    {
        try {
            $response = $this->client->ping();

            return $response->getStatusCode() === 200;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getIndexInfo(string $indexName): ?array
    {
        try {
            if (!$this->indexExists($indexName)) {
                return null;
            }

            $stats = $this->client->indices()->stats(['index' => $indexName]);
            $mapping = $this->client->indices()->getMapping(['index' => $indexName]);

            return [
                'stats' => $stats->asArray(),
                'mapping' => $mapping->asArray(),
            ];
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to get index info', [
                'index' => $indexName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
