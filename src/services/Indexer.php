<?php

namespace x2network\typesenseintegration\services;

use x2network\typesenseintegration\events\DocumentEvent;
use x2network\typesenseintegration\helpers\DocumentHelper;
use x2network\typesenseintegration\helpers\Log;
use x2network\typesenseintegration\jobs\SyncElement;
use x2network\typesenseintegration\models\CollectionConfig;
use x2network\typesenseintegration\models\SourceConfig;
use x2network\typesenseintegration\TypesenseIntegration;
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
