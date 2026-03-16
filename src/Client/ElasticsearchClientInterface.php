<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Client;

interface ElasticsearchClientInterface
{
    /**
     * @param array<string, mixed> $document
     */
    public function index(string $indexName, string $id, array $document): void;

    public function delete(string $indexName, string $id): void;

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function search(string|array $indexName, array $query): array;

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $mappings
     */
    public function createIndex(string $indexName, array $settings = [], array $mappings = []): void;

    public function deleteIndex(string $indexName): void;

    public function indexExists(string $indexName): bool;

    /**
     * @param array<mixed> $operations
     * @return array<string, mixed>
     */
    public function bulk(array $operations): array;

    public function refresh(string $indexName): void;

    public function isAvailable(): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function getIndexInfo(string $indexName): ?array;
}
