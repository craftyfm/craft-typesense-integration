<?php

namespace x2network\typesenseintegration\services;

use x2network\typesenseintegration\helpers\Log;
use x2network\typesenseintegration\models\CollectionConfig;
use x2network\typesenseintegration\TypesenseIntegration;
use Throwable;
use yii\base\Component;

/**
 * Scoped search keys: what a page gets instead of the real search-only key.
 *
 * A scoped key is the search-only key plus embedded parameters, signed with it. Typesense
 * applies the embedded `filter_by` on top of whatever the browser asks for, and the browser
 * cannot override the embedded `exclude_fields` or `expires_at` - so the key, not the page,
 * decides what can be fetched. The admin key never reaches here or the browser.
 */
class ScopedKeys extends Component
{
    public const DEFAULT_TTL = 3600;

    /**
     * Caller parameters read rather than embedded as they are.
     */
    private const OWN_PARAMS = ['filter', 'ttl'];

    /**
     * Embedded parameters a caller cannot set directly.
     */
    private const RESERVED_PARAMS = ['filter_by', 'exclude_fields', 'expires_at'];

    /**
     * `{host, port, protocol, collection, apiKey, expiresAt}` for one collection, the key
     * scoped to it, or null when the plugin is unconfigured, the collection is unknown, or no
     * usable search-only key is set.
     *
     * @param array<string, mixed> $params `filter` (a Typesense filter ANDed in), `ttl`
     *        (seconds, default 3600), or any other search parameter to embed as it is.
     */
    public function searchConfig(string $handle, array $params = []): ?array
    {
        $collection = TypesenseIntegration::getInstance()->collections->getCollection($handle);
        $key = $collection === null ? null : $this->scopedKey($handle, $params);

        if ($collection === null || $key === null) {
            return null;
        }

        $settings = TypesenseIntegration::getInstance()->getSettings();
        $node = $settings->getNodes()[0];

        return [
            'host' => $node['host'],
            'port' => (int)$node['port'],
            'protocol' => $node['protocol'],
            'collection' => $collection->name,
            'apiKey' => $key,
            'expiresAt' => time() + $this->ttl($params),
        ];
    }

    /**
     * A scoped key alone, signed with the search-only key, or null.
     *
     * @param array<string, mixed> $params As for searchConfig().
     */
    public function scopedKey(string $handle, array $params = []): ?string
    {
        $collection = TypesenseIntegration::getInstance()->collections->getCollection($handle);
        $searchKey = $this->searchKey();

        if ($collection === null || $searchKey === null) {
            return null;
        }

        try {
            $client = TypesenseIntegration::getInstance()->client->getClient();

            return $client->keys->generateScopedSearchKey($searchKey, $this->embedded($collection, $params));
        } catch (Throwable $e) {
            Log::error("Could not make a scoped search key for “{$handle}”: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function embedded(CollectionConfig $collection, array $params): array
    {
        $embedded = [];

        foreach ($params as $name => $value) {
            if (in_array($name, self::OWN_PARAMS, true)) {
                continue;
            }

            if (in_array($name, self::RESERVED_PARAMS, true)) {
                Log::warning("A scoped key for “{$collection->handle}” tried to set {$name}, which the plugin sets itself; it was ignored.");

                continue;
            }

            $embedded[$name] = $value;
        }

        $now = time();

        $filters = array_filter([
            $collection->publicationWindow ? "postDate:<=$now && expiryDate:>$now" : null,
            isset($params['filter']) && is_string($params['filter']) ? $params['filter'] : null,
        ]);

        $filter = $this->combine(array_values($filters));
        if ($filter !== '') {
            $embedded['filter_by'] = $filter;
        }

        if ($collection->excludeFields !== []) {
            $embedded['exclude_fields'] = implode(',', $collection->excludeFields);
        }

        $embedded['expires_at'] = $now + $this->ttl($params);

        return $embedded;
    }

    /**
     * @param string[] $clauses
     */
    private function combine(array $clauses): string
    {
        $clauses = array_values(array_filter(array_map('trim', $clauses), fn(string $c) => $c !== ''));

        if (count($clauses) < 2) {
            return $clauses[0] ?? '';
        }

        return implode(' && ', array_map(fn(string $c) => "($c)", $clauses));
    }

    private function ttl(array $params): int
    {
        $ttl = $params['ttl'] ?? null;

        return is_numeric($ttl) && (int)$ttl > 0 ? (int)$ttl : self::DEFAULT_TTL;
    }

    /**
     * The search-only key, or null when none is set, or when it is the admin key (a key signed
     * with the admin key would carry admin rights into page HTML).
     */
    private function searchKey(): ?string
    {
        $settings = TypesenseIntegration::getInstance()->getSettings();
        $key = trim($settings->getSearchApiKey());

        if ($key === '') {
            Log::warning('No search-only API key is set, so no scoped search key can be made.');

            return null;
        }

        if ($key === trim($settings->getApiKey())) {
            Log::error('The search-only API key is the admin key; no scoped key is made from it.');

            return null;
        }

        return $key;
    }
}
