<?php

namespace craftyfm\typesenseintegration\models;

use craftyfm\typesenseintegration\helpers\Log;
use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings. Every property can be set from `config/typesense-integration.php`;
 * `collections` and `nodes` can only be set there.
 */
class Settings extends Model
{
    /** Typesense host, e.g. `localhost` or `xxx.a1.typesense.net`. Supports env vars. */
    public string $host = 'localhost';

    /** Supports env vars. */
    public string $port = '8108';

    /** `http` or `https`. Supports env vars. */
    public string $protocol = 'http';

    /** Optional path prefix when Typesense sits behind a proxy. Supports env vars. */
    public string $path = '';

    /** Admin API key (needs write access). Supports env vars. */
    public string $apiKey = '';

    /**
     * Search-only key, used to sign scoped keys handed to the browser (`searchConfig()`/
     * `scopedKey()`). Never the admin key: a scoped key signed with it would carry admin
     * rights into page HTML. Supports env vars.
     */
    public string $searchApiKey = '';

    /**
     * Optional list of nodes for a Typesense cluster. Overrides host/port/protocol/path.
     * Each item: ['host' => '', 'port' => '', 'protocol' => '', 'path' => ''].
     */
    public array $nodes = [];

    /** Request timeout in seconds (applies to both connecting and the full request). */
    public float $timeout = 5.0;

    /** Number of retries on network/5xx errors. */
    public int $numRetries = 3;

    /** Seconds to wait between retries. */
    public float $retryInterval = 1.0;

    /** Prefix added to every Typesense collection name, e.g. `dev_`. Supports env vars. */
    public string $collectionPrefix = '';

    /** Sync documents when elements are created, updated or deleted. */
    public bool $syncOnSave = true;

    /** Run syncing through the Craft queue instead of during the request. */
    public bool $useQueue = true;

    /** Number of elements per import batch. */
    public int $batchSize = 100;

    /** One of debug, info, warning, error, none. */
    public string $logLevel = 'warning';

    /**
     * Collection definitions, keyed by collection handle. See the README.
     */
    public array $collections = [];

    public function getNodes(): array
    {
        $nodes = $this->nodes ?: [[
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'path' => $this->path,
        ]];

        return array_map(fn(array $node) => [
            'host' => (string)App::parseEnv($node['host'] ?? ''),
            'port' => (string)App::parseEnv((string)($node['port'] ?? '8108')),
            'protocol' => (string)App::parseEnv($node['protocol'] ?? 'http'),
            'path' => (string)App::parseEnv($node['path'] ?? ''),
        ], $nodes);
    }

    public function getApiKey(): string
    {
        return (string)App::parseEnv($this->apiKey);
    }

    public function getSearchApiKey(): string
    {
        return (string)App::parseEnv($this->searchApiKey);
    }

    public function getCollectionPrefix(): string
    {
        return (string)App::parseEnv($this->collectionPrefix);
    }

    public function isConfigured(): bool
    {
        return $this->getApiKey() !== '' && $this->getNodes()[0]['host'] !== '';
    }

    protected function defineRules(): array
    {
        return [
            [['host', 'port', 'protocol', 'apiKey'], 'required'],
            [['timeout', 'retryInterval'], 'number', 'min' => 0],
            [['numRetries'], 'integer', 'min' => 0],
            [['batchSize'], 'integer', 'min' => 1, 'max' => 10000],
            [['syncOnSave', 'useQueue'], 'boolean'],
            [['logLevel'], 'in', 'range' => array_keys(Log::LEVELS)],
        ];
    }
}
