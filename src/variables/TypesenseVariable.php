<?php

namespace craftyfm\typesenseintegration\variables;

use craftyfm\typesenseintegration\helpers\Log;
use craftyfm\typesenseintegration\TypesenseIntegration;
use Throwable;

/**
 * `craft.typesense` in Twig.
 */
class TypesenseVariable
{
    /**
     * Searches a collection by its config handle.
     *
     * ```twig
     * {% set results = craft.typesense.search('site', { q: 'shoes', query_by: 'title,body', filter_by: 'source:=products' }) %}
     * ```
     *
     * Returns null when the search fails; the error is logged.
     */
    public function search(string $collection, array $params): ?array
    {
        $plugin = TypesenseIntegration::getInstance();

        try {
            $config = $plugin->collections->requireCollection($collection);

            return $plugin->client->getClient()
                ->getCollections()[$config->name]
                ->getDocuments()
                ->search($params);
        } catch (Throwable $e) {
            Log::error("Search on “{$collection}” failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Returns the real Typesense collection name (with prefix) for a config handle.
     */
    public function collectionName(string $collection): ?string
    {
        return TypesenseIntegration::getInstance()->collections->getCollection($collection)?->name;
    }

    /**
     * `{host, port, protocol, collection, apiKey, expiresAt}` for one collection, the key
     * scoped to it - what a browser-side search page is handed instead of a real key.
     *
     * ```twig
     * {% set cfg = craft.typesense.searchConfig('books', { filter: 'bookCategories:=`Fiction`', ttl: 3600 }) %}
     * ```
     *
     * @param array<string, mixed> $params `filter`, `ttl`, or any other search parameter to embed.
     */
    public function searchConfig(string $collection, array $params = []): ?array
    {
        return TypesenseIntegration::getInstance()->scopedKeys->searchConfig($collection, $params);
    }

    /**
     * A scoped key alone, for a page that already has the rest.
     *
     * @param array<string, mixed> $params As for searchConfig().
     */
    public function scopedKey(string $collection, array $params = []): ?string
    {
        return TypesenseIntegration::getInstance()->scopedKeys->scopedKey($collection, $params);
    }
}
