<?php

namespace x2network\typesenseintegration\services;

use x2network\typesenseintegration\helpers\Log;
use x2network\typesenseintegration\models\CollectionConfig;
use x2network\typesenseintegration\models\SourceConfig;
use x2network\typesenseintegration\TypesenseIntegration;
use craft\base\ElementInterface;
use Typesense\Documents;
use Typesense\Exceptions\ObjectNotFound;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Reads collection definitions from the config and manages them in Typesense.
 */
class Collections extends Component
{
    /** @var CollectionConfig[]|null */
    private ?array $collections = null;

    /**
     * @return CollectionConfig[] keyed by handle
     */
    public function getAllCollections(): array
    {
        if ($this->collections !== null) {
            return $this->collections;
        }

        $settings = TypesenseIntegration::getInstance()->getSettings();
        $prefix = $settings->getCollectionPrefix();
        $this->collections = [];

        foreach ($settings->collections as $handle => $config) {
            $this->collections[$handle] = new CollectionConfig((string)$handle, $config, $prefix);
        }

        return $this->collections;
    }

    public function getCollection(string $handle): ?CollectionConfig
    {
        return $this->getAllCollections()[$handle] ?? null;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function requireCollection(string $handle): CollectionConfig
    {
        return $this->getCollection($handle)
            ?? throw new InvalidArgumentException("Unknown Typesense collection “{$handle}”.");
    }

    /**
     * Whether any collection has a source for the given element type.
     *
     * @param class-string<ElementInterface> $elementType
     */
    public function hasSourceFor(string $elementType): bool
    {
        foreach ($this->getAllCollections() as $collection) {
            if ($collection->getSourcesFor($elementType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the collection info from Typesense, or null if it doesn't exist.
     */
    public function getRemoteInfo(CollectionConfig $collection): ?array
    {
        try {
            return $this->client()->getCollections()[$collection->name]->retrieve();
        } catch (ObjectNotFound) {
            return null;
        }
    }

    /**
     * Number of documents per source handle in Typesense.
     *
     * @return array<string, int>
     */
    public function getDocumentCountsBySource(CollectionConfig $collection): array
    {
        $result = $this->documents($collection)->search([
            'q' => '*',
            'facet_by' => CollectionConfig::FIELD_SOURCE,
            'max_facet_values' => max(10, count($collection->sources)),
            'per_page' => 0,
        ]);

        $counts = [];
        foreach ($result['facet_counts'][0]['counts'] ?? [] as $count) {
            $counts[$count['value']] = (int)$count['count'];
        }

        return $counts;
    }

    /**
     * Returns which of the given elements have a document, keyed by `elementId-siteId`.
     *
     * @param int[] $elementIds
     * @return array<string, true>
     */
    public function getIndexedKeys(CollectionConfig $collection, SourceConfig $source, array $elementIds): array
    {
        if (!$elementIds) {
            return [];
        }

        $result = $this->documents($collection)->search([
            'q' => '*',
            'filter_by' => sprintf(
                '%s:=`%s` && %s:[%s]',
                CollectionConfig::FIELD_SOURCE,
                $source->handle,
                CollectionConfig::FIELD_ELEMENT_ID,
                implode(',', array_map('intval', $elementIds)),
            ),
            'include_fields' => CollectionConfig::FIELD_ELEMENT_ID . ',' . CollectionConfig::FIELD_SITE_ID,
            'per_page' => 250,
        ]);

        $keys = [];
        foreach ($result['hits'] ?? [] as $hit) {
            $document = $hit['document'];
            $keys[$document[CollectionConfig::FIELD_ELEMENT_ID] . '-' . $document[CollectionConfig::FIELD_SITE_ID]] = true;
        }

        return $keys;
    }

    /**
     * Compares the configured schema with the one in Typesense.
     *
     * @return string[] one message per difference; empty when they match
     */
    public function getSchemaDifferences(CollectionConfig $collection, array $remoteInfo): array
    {
        $normalize = fn(array $field) => [
            'type' => $field['type'] ?? null,
            'facet' => (bool)($field['facet'] ?? false),
            'optional' => (bool)($field['optional'] ?? false),
            'index' => (bool)($field['index'] ?? true),
        ];

        $local = [];
        foreach ($collection->getSchema()['fields'] as $field) {
            $local[$field['name']] = $normalize($field);
        }

        $remote = [];
        foreach ($remoteInfo['fields'] ?? [] as $field) {
            $remote[$field['name']] = $normalize($field);
        }

        $differences = [];
        foreach ($local as $name => $field) {
            if (!isset($remote[$name])) {
                $differences[] = "“{$name}” is not in Typesense yet.";
            } elseif ($remote[$name] != $field) {
                $changed = array_keys(array_diff_assoc(array_map('json_encode', $field), array_map('json_encode', $remote[$name])));
                $differences[] = "“{$name}” has a different " . implode(', ', $changed) . '.';
            }
        }

        foreach (array_diff_key($remote, $local) as $name => $field) {
            $differences[] = "“{$name}” is in Typesense but no longer in the config.";
        }

        return $differences;
    }

    public function exists(CollectionConfig $collection): bool
    {
        return $this->getRemoteInfo($collection) !== null;
    }

    /**
     * Creates the collection in Typesense if it doesn't exist yet.
     *
     * @return bool whether it was created
     */
    public function ensure(CollectionConfig $collection): bool
    {
        if ($this->exists($collection)) {
            return false;
        }

        $this->create($collection);

        return true;
    }

    public function create(CollectionConfig $collection): void
    {
        $this->client()->getCollections()->create($collection->getSchema());
        Log::info("Created collection “{$collection->name}”.");
    }

    /**
     * @return bool whether the collection existed
     */
    public function drop(CollectionConfig $collection): bool
    {
        try {
            $this->client()->getCollections()[$collection->name]->delete();
        } catch (ObjectNotFound) {
            return false;
        }

        Log::info("Dropped collection “{$collection->name}”.");

        return true;
    }

    /**
     * Drops and recreates the collection with the current schema.
     */
    public function recreate(CollectionConfig $collection): void
    {
        $this->drop($collection);
        $this->create($collection);
    }

    private function documents(CollectionConfig $collection): Documents
    {
        return $this->client()->getCollections()[$collection->name]->getDocuments();
    }

    private function client(): \Typesense\Client
    {
        return TypesenseIntegration::getInstance()->client->getClient();
    }
}
