<?php

declare(strict_types=1);

namespace PsychedCms\Elasticsearch\Controller;

use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Elasticsearch\Index\IndexNameResolver;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class PublicSearchController
{
    private const DATE_FIELDS = ['publishDate', 'startDate', 'releaseDate', 'formedDate', '_created_at'];

    public function __construct(
        private ElasticsearchClientInterface $client,
        private EntityMetadataReader $metadataReader,
        private IndexNameResolver $nameResolver,
    ) {
    }

    #[Route('/api/search', name: 'psychedcms_public_search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $locale = $request->getLocale() ?: $request->query->get('locale', 'fr');
        $page = max(1, (int) $request->query->get('page', '1'));
        $itemsPerPage = min(50, max(1, (int) $request->query->get('itemsPerPage', '10')));

        /** @var string[] $contentTypes */
        $contentTypes = $request->query->all('contentTypes');

        $indexNames = $this->resolveIndexNames($contentTypes, $locale);

        if ($indexNames === []) {
            return $this->emptyResponse();
        }

        $query = $this->buildQuery($page, $itemsPerPage, $contentTypes, $request);

        try {
            $response = $this->client->search($indexNames, $query);
        } catch (\Throwable) {
            return $this->emptyResponse();
        }

        return new JsonResponse($this->formatHydraResponse($response, $page, $itemsPerPage));
    }

    /**
     * @param string[] $contentTypes
     * @return string[]
     */
    private function resolveIndexNames(array $contentTypes, string $locale = 'fr'): array
    {
        $contentTypeMap = $this->buildContentTypeMap();

        if ($contentTypes === []) {
            $indexNames = [];
            foreach ($this->metadataReader->getIndexedEntities() as $entityClass) {
                $indexNames[] = $this->nameResolver->resolveForLocale($entityClass, $locale);
            }

            return $indexNames;
        }

        $indexNames = [];
        foreach ($contentTypes as $ct) {
            $entityClass = $contentTypeMap[$ct] ?? null;
            if ($entityClass !== null && $this->metadataReader->isIndexed($entityClass)) {
                $indexNames[] = $this->nameResolver->resolveForLocale($entityClass, $locale);
            }
        }

        return $indexNames;
    }

    /**
     * Build a mapping from content type slug (e.g. 'event-reports') to entity class name.
     * Uses the Indexed attribute's indexName to derive the slug.
     *
     * @return array<string, string>
     */
    private function buildContentTypeMap(): array
    {
        $map = [];

        foreach ($this->metadataReader->getIndexedEntities() as $entityClass) {
            $indexed = $this->metadataReader->getIndexedAttribute($entityClass);
            if ($indexed === null) {
                continue;
            }

            // indexName is the content type slug (e.g. 'event-reports', 'bands')
            $slug = $indexed->indexName ?? strtolower($this->getShortName($entityClass)) . 's';
            $map[$slug] = $entityClass;
        }

        return $map;
    }

    /**
     * @param string[] $contentTypes
     * @return array<string, mixed>
     */
    private function buildQuery(int $page, int $itemsPerPage, array $contentTypes, Request $request): array
    {
        $from = ($page - 1) * $itemsPerPage;

        $filters = [
            ['term' => ['_status' => 'published']],
        ];

        if ($contentTypes !== []) {
            $contentTypeMap = $this->buildContentTypeMap();
            $esContentTypes = [];

            foreach ($contentTypes as $ct) {
                $entityClass = $contentTypeMap[$ct] ?? null;
                if ($entityClass !== null) {
                    $esContentTypes[] = strtolower($this->getShortName($entityClass));
                }
            }

            if ($esContentTypes !== []) {
                $filters[] = ['terms' => ['_content_type' => $esContentTypes]];
            }
        }

        $sort = $this->resolveSort($request);

        return [
            'from' => $from,
            'size' => $itemsPerPage,
            'query' => [
                'bool' => [
                    'filter' => $filters,
                ],
            ],
            'sort' => $sort,
        ];
    }

    /**
     * @return array<array<string, mixed>>
     */
    private function resolveSort(Request $request): array
    {
        /** @var array<string, string> $order */
        $order = $request->query->all('order');

        if (isset($order['date'])) {
            $direction = strtolower($order['date']) === 'asc' ? 'asc' : 'desc';

            $sorts = [];
            foreach (self::DATE_FIELDS as $field) {
                $sorts[] = [$field => ['order' => $direction, 'missing' => '_last', 'unmapped_type' => 'date']];
            }

            return $sorts;
        }

        return [['_created_at' => ['order' => 'desc', 'unmapped_type' => 'date']]];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatHydraResponse(array $response, int $page, int $itemsPerPage): array
    {
        $total = $response['hits']['total']['value'] ?? 0;
        $hits = $response['hits']['hits'] ?? [];

        $members = [];
        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            $docId = $hit['_id'] ?? '';
            $entityId = $this->extractEntityId($docId);
            $contentType = strtolower($source['_content_type'] ?? '');
            $slug = $source['_slug'] ?? '';

            $member = [
                '@id' => $this->buildIri($contentType, $entityId),
                '@type' => ucfirst($contentType),
                'id' => $entityId,
                'contentType' => $this->resolveContentTypeSlug($contentType),
                'locale' => $source['_locale'] ?? 'fr',
                'slug' => $slug,
            ];

            $this->addOptionalFields($member, $source);

            $members[] = $member;
        }

        return [
            '@context' => '/api/contexts/Collection',
            '@id' => '/api/search',
            '@type' => 'hydra:Collection',
            'hydra:totalItems' => $total,
            'hydra:member' => $members,
            'hydra:view' => [
                '@id' => '/api/search?page=' . $page,
                '@type' => 'hydra:PartialCollectionView',
                'hydra:first' => '/api/search?page=1',
                'hydra:last' => '/api/search?page=' . max(1, (int) ceil($total / $itemsPerPage)),
                'hydra:next' => ($page * $itemsPerPage < $total) ? '/api/search?page=' . ($page + 1) : null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $member
     * @param array<string, mixed> $source
     */
    private function addOptionalFields(array &$member, array $source): void
    {
        // Forward all source fields except internal metadata
        $skipFields = ['_content_type', '_locale', '_slug', '_status', '_created_at', '_updated_at', '_author'];

        foreach ($source as $key => $value) {
            if (\in_array($key, $skipFields, true)) {
                continue;
            }

            $member[$key] = $value;
        }
    }

    private function buildIri(string $contentType, int $id): string
    {
        $slug = $this->resolveContentTypeSlug($contentType);

        return '/api/' . $slug . '/' . $id;
    }

    private function resolveContentTypeSlug(string $contentType): string
    {
        // Convert class short name back to API slug
        return match ($contentType) {
            'eventreport' => 'event-reports',
            'dayreport' => 'day-reports',
            'setreport' => 'set-reports',
            default => $contentType . 's',
        };
    }

    private function extractEntityId(string $documentId): int
    {
        $parts = explode('_', $documentId);
        if (\count($parts) >= 3) {
            return (int) $parts[\count($parts) - 2];
        }

        return 0;
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return end($parts);
    }

    private function emptyResponse(): JsonResponse
    {
        return new JsonResponse([
            '@context' => '/api/contexts/Collection',
            '@id' => '/api/search',
            '@type' => 'hydra:Collection',
            'hydra:totalItems' => 0,
            'hydra:member' => [],
        ]);
    }
}
