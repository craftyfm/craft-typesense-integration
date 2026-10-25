<?php

namespace x2network\typesenseintegration\jobs;

use x2network\typesenseintegration\TypesenseIntegration;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

/**
 * Syncs one element's documents after it was saved, deleted or restored.
 */
class SyncElement extends BaseJob
{
    /** @var class-string<ElementInterface> */
    public string $elementType;
    public int $elementId;

    public function execute($queue): void
    {
        TypesenseIntegration::getInstance()->indexer->syncElement($this->elementType, $this->elementId);
    }

    protected function defaultDescription(): ?string
    {
        return "Syncing element {$this->elementId} with Typesense";
    }
}
