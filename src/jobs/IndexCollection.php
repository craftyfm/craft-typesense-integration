<?php

namespace x2network\typesenseintegration\jobs;

use x2network\typesenseintegration\TypesenseIntegration;
use craft\queue\BaseJob;

/**
 * Indexes (or rebuilds) a whole collection.
 */
class IndexCollection extends BaseJob
{
    public string $collection;
    public bool $rebuild = false;

    public function execute($queue): void
    {
        $plugin = TypesenseIntegration::getInstance();
        $collection = $plugin->collections->requireCollection($this->collection);

        $plugin->indexer->indexCollection($collection, $this->rebuild, function(int $done, int $total) use ($queue) {
            $this->setProgress($queue, $total ? $done / $total : 1, "{$done} / {$total}");
        });
    }

    public function getTtr(): int
    {
        return 3600;
    }

    protected function defaultDescription(): ?string
    {
        return ($this->rebuild ? 'Rebuilding' : 'Indexing') . " Typesense collection “{$this->collection}”";
    }
}
