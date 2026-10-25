<?php

namespace craftyfm\typesenseintegration\jobs;

use craftyfm\typesenseintegration\TypesenseIntegration;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

/**
 * Resyncs the elements of one source currently related to a trigger element that was saved,
 * restored or deleted (e.g. products related to a Category that was renamed).
 */
class SyncRelated extends BaseJob
{
    public string $collection;
    public string $source;

    /** @var class-string<ElementInterface> */
    public string $triggerElementType;
    public int $triggerElementId;
    public int $triggerSiteId;

    /** Relation field handle the matched trigger was scoped to, or null for "any field". */
    public ?string $field = null;

    public function execute($queue): void
    {
        $plugin = TypesenseIntegration::getInstance();
        $collection = $plugin->collections->requireCollection($this->collection);
        $source = $collection->sources[$this->source] ?? null;

        if ($source === null) {
            // The config changed since this job was queued; nothing to do.
            return;
        }

        // Re-fetched fresh, including a soft-deleted element (EVENT_AFTER_DELETE_ELEMENT still
        // needs something to run relatedTo() against).
        $triggerElement = $this->triggerElementType::find()
            ->id($this->triggerElementId)
            ->siteId($this->triggerSiteId)
            ->status(null)
            ->trashed(null)
            ->one();

        if ($triggerElement === null) {
            return;
        }

        $trigger = null;
        foreach ($source->getTriggersFor($triggerElement) as $candidate) {
            if ($candidate->field === $this->field) {
                $trigger = $candidate;
                break;
            }
        }

        if ($trigger === null) {
            return;
        }

        $plugin->indexer->syncRelated($collection, $source, $trigger, $triggerElement);
    }

    public function getTtr(): int
    {
        // Same as IndexCollection - a trigger can fan out to many related elements.
        return 3600;
    }

    protected function defaultDescription(): ?string
    {
        return "Resyncing “{$this->source}” elements related to {$this->triggerElementType} {$this->triggerElementId}";
    }
}
