<?php

namespace x2network\typesenseintegration\models;

use craft\base\ElementInterface;
use yii\base\InvalidConfigException;

/**
 * A Typesense collection defined in `config/typesense-integration.php`.
 */
class CollectionConfig
{
    /** Fields the plugin adds to every document so documents can be traced back to their element. */
    public const FIELD_ELEMENT_ID = 'elementId';
    public const FIELD_SITE_ID = 'siteId';
    public const FIELD_SOURCE = 'source';

    public readonly string $handle;

    /** The collection name in Typesense, including the prefix. */
    public readonly string $name;

    /** @var SourceConfig[] keyed by source handle */
    public readonly array $sources;

    /** Whether a scoped key limits this collection to its publication window (postDate/expiryDate). */
    public readonly bool $publicationWindow;

    /** Fields a scoped key never returns, on top of whatever the caller excludes. */
    public readonly array $excludeFields;

    private array $schema;

    public function __construct(string $handle, array $config, string $prefix = '')
    {
        $schema = $config['schema'] ?? null;

        if (!is_array($schema) || empty($schema['fields'])) {
            throw new InvalidConfigException("Typesense collection “{$handle}” must define `schema.fields`.");
        }

        if (empty($config['sources']) || !is_array($config['sources'])) {
            throw new InvalidConfigException("Typesense collection “{$handle}” must define at least one source.");
        }

        $sources = [];
        foreach ($config['sources'] as $sourceHandle => $sourceConfig) {
            $sources[$sourceHandle] = new SourceConfig((string)$sourceHandle, $sourceConfig);
        }

        $search = $config['search'] ?? [];

        $this->handle = $handle;
        $this->name = $prefix . ($config['name'] ?? $handle);
        $this->sources = $sources;
        $this->schema = $schema;
        $this->publicationWindow = (bool)($search['publicationWindow'] ?? true);
        $this->excludeFields = $search['excludeFields'] ?? [];
    }

    /**
     * The full schema sent to Typesense when the collection is created.
     */
    public function getSchema(): array
    {
        $fields = $this->schema['fields'];
        $defined = array_column($fields, 'name');

        $baseFields = [
            ['name' => self::FIELD_ELEMENT_ID, 'type' => 'int64'],
            ['name' => self::FIELD_SITE_ID, 'type' => 'int32', 'facet' => true],
            ['name' => self::FIELD_SOURCE, 'type' => 'string', 'facet' => true],
        ];

        foreach ($baseFields as $field) {
            if (!in_array($field['name'], $defined, true)) {
                $fields[] = $field;
            }
        }

        return array_merge($this->schema, [
            'name' => $this->name,
            'fields' => $fields,
        ]);
    }

    /**
     * Field types keyed by field name, used to cast document values.
     *
     * @return array<string, string>
     */
    public function getFieldTypes(): array
    {
        $types = [];
        foreach ($this->getSchema()['fields'] as $field) {
            if (isset($field['name'], $field['type'])) {
                $types[$field['name']] = $field['type'];
            }
        }

        return $types;
    }

    /**
     * @param class-string<ElementInterface> $elementType
     * @return SourceConfig[]
     */
    public function getSourcesFor(string $elementType): array
    {
        return array_filter($this->sources, fn(SourceConfig $source) => $source->supports($elementType));
    }

    public static function documentId(string $source, int $elementId, int $siteId): string
    {
        return "{$source}-{$elementId}-{$siteId}";
    }
}
