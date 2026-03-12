<?php

namespace VisitMarche\ThemeWp\Lib\Search;

use Meilisearch\Contracts\DeleteTasksQuery;
use Meilisearch\Endpoints\Keys;

class MeiliServer
{
    use MeiliTrait;

    public function __construct()
    {
        $this->indexName = $_ENV['MEILI_INDEX_NAME'] ?? null;
        $this->masterKey = $_ENV['MEILI_MASTER_KEY'] ?? null;
    }

    /**
     *
     * @return array<'taskUid','indexUid','status','enqueuedAt'>
     */
    public function createIndex(): array
    {
        $this->client->deleteTasks((new DeleteTasksQuery())->setStatuses(['failed', 'canceled', 'succeeded']));
        $this->client->deleteIndex($this->indexName);

        return $this->client->createIndex($this->indexName, ['primaryKey' => $this->primaryKey]);
    }

    /**
     * https://raw.githubusercontent.com/meilisearch/meilisearch/latest/config.toml
     * curl -X PATCH 'http://localhost:7700/experimental-features/' -H 'Content-Type: application/json' -H 'Authorization: Bearer xxxxxx' --data-binary '{"containsFilter": true}'
     * @return array
     */
    public function settings(): array
    {
        $index = $this->client->index($this->indexName);

        $index->updateSearchableAttributes([
            'name',
            'name_en',
            'name_nl',
            'name_de',
            'excerpt',
            'excerpt_en',
            'excerpt_nl',
            'excerpt_de',
            'content',
            'tags.name',
        ]);

        $index->updateFilterableAttributes($this->filterableAttributes);

        return $index->updateSortableAttributes($this->sortableAttributes);
    }

    public function createApiKey(): Keys
    {
        return $this->client->createKey([
            'description' => 'indicateur ville API key',
            'actions' => ['*'],
            'indexes' => [$this->indexName],
            'expiresAt' => '2042-04-02T00:42:42Z',
        ]);
    }

    public function dump(): array
    {
        return $this->client->createDump();
    }
}
