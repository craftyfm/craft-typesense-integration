<?php

namespace craftyfm\typesenseintegration\services;

use craftyfm\typesenseintegration\events\DocumentEvent;
use craftyfm\typesenseintegration\helpers\DocumentHelper;
use craftyfm\typesenseintegration\helpers\Log;
use craftyfm\typesenseintegration\jobs\SyncElement;
use craftyfm\typesenseintegration\jobs\SyncRelated;
use craftyfm\typesenseintegration\models\CollectionConfig;
use craftyfm\typesenseintegration\models\RelatedTrigger;
use craftyfm\typesenseintegration\models\SourceConfig;
use craftyfm\typesenseintegration\TypesenseIntegration;
use Craft;
use craft\base\ElementInterface;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;
use yii\base\Component;

/**
 * Builds documents from elements and pushes them to Typesense.
 */
class Indexer extends Component
{
    /**
     * Fired before a document is sent to Typesense. Modify `$event->document`,
     * or set `$event->isValid = false` to skip the element.
     */
    public const EVENT_BEFORE_INDEX_DOCUMENT = 'beforeIndexDocument';

    /**
     * Indexes every element of every source of a collection.
     *
     * @param bool $rebuild drop and recreate the collection first (removes stale documents and applies schema changes)
     * @param callable|null $onProgress `function(int $done, int $total): void`
     * @return array{indexed: int, failed: int, skipped: int}
     */
    public function indexCollection(CollectionConfig $collection, bool $rebuild = false, ?callable $onProgress = null): array
    {
        $plugin = TypesenseIntegration::getInstance();
        $batchSize = $plugin->getSettings()->batchSize;
        $stats = ['indexed' => 0, 'failed' => 0, 'skipped' => 0];

        if ($rebuild) {
            $plugin->collections->recreate($collection);
        } else {
            $plugin->collections->ensure($collection);
        }

        $total = 0;
        foreach ($collection->sources as $source) {
            $total += (int)$source->createQuery()->count();
        }

        Log::info("Indexing “{$collection->name}”: {$total} elements" . ($rebuild ? ' (rebuild)' : '') . '.');

        $done = 0;
        foreach ($collection->sources as $source) {
            foreach (Db::batch($source->createQuery(), $batchSize) as $elements) {
                $documents = [];

                foreach ($elements as $element) {
                    $document = $this->buildDocument($collection, $source, $element);
                    if ($document === null) {
                        $stats['skipped']++;
                    } else {
                        $documents[] = $document;
                    }
                }

                [$indexed, $failed] = $this->import($collection, $documents);
                $stats['indexed'] += $indexed;
                $stats['failed'] += $failed;

                $done += count($elements);
                if ($onProgress) {
                    $onProgress($done, $total);
                }
            }
        }

        Log::info(sprintf(
            'Finished indexing “%s”: %d indexed, %d failed, %d skipped.',
            $collection->name,
            $stats['indexed'],
            $stats['failed'],
            $stats['skipped'],
        ));

        return $stats;
    }

    /**
     * Brings an element's documents up to date in every collection it belongs to:
     * documents for sites that match the source criteria are upserted, all others are removed.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public function syncElement(string $elementType, int $elementId): void
    {
        $collections = TypesenseIntegration::getInstance()->collections;

        foreach ($collections->getAllCollections() as $collection) {
            foreach ($collection->getSourcesFor($elementType) as $source) {
                $elements = $source->createQuery()->id($elementId)->all();
                $documents = [];
                $indexedSiteIds = [];

                foreach ($elements as $element) {
                    $document = $this->buildDocument($collection, $source, $element);
                    if ($document !== null) {
                        $documents[] = $document;
                        $indexedSiteIds[] = $element->siteId;
                    }
                }

                if ($documents) {
                    $collections->ensure($collection);
                    $this->import($collection, $documents);
                }

                $this->deleteStaleDocuments($collection, $source, $elementId, $indexedSiteIds);

                Log::debug(sprintf(
                    'Synced element %d (%s) in “%s”: %d upserted.',
                    $elementId,
                    $source->handle,
                    $collection->name,
                    count($documents),
                ));
            }
        }
    }

    /**
     * Schedules a sync for an element that was saved, deleted or restored.
     */
    public function queueSync(ElementInterface $element): void
    {
        if (
            !$element->id ||
            $element->propagating ||
            ElementHelper::isDraftOrRevision($element)
        ) {
            return;
        }

        try {
            if (!TypesenseIntegration::getInstance()->collections->hasSourceFor($element::class)) {
                return;
            }

            if (TypesenseIntegration::getInstance()->getSettings()->useQueue) {
                Queue::push(new SyncElement([
                    'elementType' => $element::class,
                    'elementId' => $element->id,
                ]));
            } else {
                $this->syncElement($element::class, $element->id);
            }
        } catch (Throwable $e) {
            // Never break saving content because the search index is unavailable.
            Log::error("Could not sync element {$element->id}: {$e->getMessage()}");
            Craft::$app->getErrorHandler()->logException($e);
        }
    }

    /**
     * Schedules a resync of every source element currently related to $element, for every
     * relatedTrigger it matches (e.g. a Category being renamed resyncs the products that list
     * it). One hop only: this re-transforms and re-imports the related elements, but never
     * saves them, so it can never cascade back into this same handler.
     */
    public function queueRelatedSync(ElementInterface $element): void
    {
        if (
            !$element->id ||
            $element->propagating ||
            ElementHelper::isDraftOrRevision($element)
        ) {
            return;
        }

        try {
            $collections = TypesenseIntegration::getInstance()->collections;

            if (!$collections->hasRelatedTriggerFor($element::class)) {
                return;
            }

            foreach ($collections->getAllCollections() as $collection) {
                foreach ($collection->sources as $source) {
                    foreach ($source->getTriggersFor($element) as $trigger) {
                        if (!$trigger->matches($element)) {
                            continue;
                        }

                        if (TypesenseIntegration::getInstance()->getSettings()->useQueue) {
                            Queue::push(new SyncRelated([
                                'collection' => $collection->handle,
                                'source' => $source->handle,
                                'triggerElementType' => $element::class,
                                'triggerElementId' => $element->id,
                                'triggerSiteId' => $element->siteId,
                                'field' => $trigger->field,
                            ]));
                        } else {
                            $this->syncRelated($collection, $source, $trigger, $element);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::error("Could not queue related sync for element {$element->id}: {$e->getMessage()}");
            Craft::$app->getErrorHandler()->logException($e);
        }
    }

    /**
     * Resyncs every element of $source currently related to $triggerElement via $trigger,
     * batched like indexCollection() so a trigger with many related elements (e.g. a popular
     * category) doesn't load them all into memory at once.
     *
     * Deliberately has no equivalent of syncElement()'s stale-document cleanup: it only
     * republishes elements that are currently related. An element that stops being related
     * (unlinked from the trigger) is handled by its own direct save, which already re-transforms
     * it through the normal queueSync() path.
     */
    public function syncRelated(CollectionConfig $collection, SourceConfig $source, RelatedTrigger $trigger, ElementInterface $triggerElement): void
    {
        $plugin = TypesenseIntegration::getInstance();
        $batchSize = $plugin->getSettings()->batchSize;
        $query = $trigger->buildRelatedQuery($source, $triggerElement);

        $indexed = 0;
        $failed = 0;

        foreach (Db::batch($query, $batchSize) as $elements) {
            $documents = [];

            foreach ($elements as $element) {
                $document = $this->buildDocument($collection, $source, $element);
                if ($document !== null) {
                    $documents[] = $document;
                }
            }

            if ($documents) {
                $plugin->collections->ensure($collection);
                [$batchIndexed, $batchFailed] = $this->import($collection, $documents);
                $indexed += $batchIndexed;
                $failed += $batchFailed;
            }
        }

        Log::debug(sprintf(
            'Resynced %d element(s) in “%s” (%s) related to %s %d: %d indexed, %d failed.',
            $indexed + $failed,
            $collection->name,
            $source->handle,
            $triggerElement::class,
            $triggerElement->id,
            $indexed,
            $failed,
        ));
    }

    /**
     * Transforms an element into a Typesense document, or returns null if it should be skipped.
     */
    public function buildDocument(CollectionConfig $collection, SourceConfig $source, ElementInterface $element): ?array
    {
        try {
            $document = $source->getTransformer()->transform($element);
        } catch (Throwable $e) {
            Log::error("Transformer “{$source->handle}” failed for element {$element->id}: {$e->getMessage()}");

            return null;
        }

        if ($document === null) {
            return null;
        }

        $document = array_merge($document, [
            'id' => CollectionConfig::documentId($source->handle, $element->id, $element->siteId),
            CollectionConfig::FIELD_ELEMENT_ID => $element->id,
            CollectionConfig::FIELD_SITE_ID => $element->siteId,
            CollectionConfig::FIELD_SOURCE => $source->handle,
        ]);

        $document = DocumentHelper::prepare($document, $collection->getFieldTypes());

        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_DOCUMENT)) {
            $event = new DocumentEvent([
                'collection' => $collection,
                'source' => $source,
                'element' => $element,
                'document' => $document,
            ]);
            $this->trigger(self::EVENT_BEFORE_INDEX_DOCUMENT, $event);

            if (!$event->isValid) {
                return null;
            }

            $document = $event->document;
        }

        return $document;
    }

    /**
     * Upserts documents in one request.
     *
     * @return array{0: int, 1: int} [indexed, failed]
     */
    public function import(CollectionConfig $collection, array $documents): array
    {
        if (!$documents) {
            return [0, 0];
        }

        $results = TypesenseIntegration::getInstance()->client->getClient()
            ->getCollections()[$collection->name]
            ->getDocuments()
            ->import($documents, ['action' => 'upsert']);

        $failed = 0;
        foreach ($results as $i => $result) {
            if (empty($result['success'])) {
                $failed++;
                $id = $documents[$i]['id'] ?? '?';
                Log::error("Failed to index document “{$id}” in “{$collection->name}”: " . ($result['error'] ?? 'unknown error'));
            }
        }

        return [count($documents) - $failed, $failed];
    }

    /**
     * Removes an element's documents for every site except the given ones.
     *
     * @param int[] $keepSiteIds
     */
    private function deleteStaleDocuments(CollectionConfig $collection, SourceConfig $source, int $elementId, array $keepSiteIds): void
    {
        $staleSiteIds = array_diff(Craft::$app->getSites()->getAllSiteIds(), $keepSiteIds);

        if (!$staleSiteIds) {
            return;
        }

        $filter = sprintf(
            '%s:=%d && %s:=`%s` && %s:[%s]',
            CollectionConfig::FIELD_ELEMENT_ID,
            $elementId,
            CollectionConfig::FIELD_SOURCE,
            $source->handle,
            CollectionConfig::FIELD_SITE_ID,
            implode(',', $staleSiteIds),
        );

        try {
            $result = TypesenseIntegration::getInstance()->client->getClient()
                ->getCollections()[$collection->name]
                ->getDocuments()
                ->delete(['filter_by' => $filter]);

            if (!empty($result['num_deleted'])) {
                Log::debug("Deleted {$result['num_deleted']} document(s) for element {$elementId} from “{$collection->name}”.");
            }
        } catch (ObjectNotFound) {
            // The collection doesn't exist yet, so there is nothing to delete.
        }
    }
}
