<?php
/**
 * Typesense Integration config.
 *
 * Copy this file to `config/typesense-integration.php` and adjust it.
 * Any setting defined here overrides the value saved in the control panel.
 */

use craft\elements\Entry;
use craft\helpers\App;

return [
    // Connection
    'host' => App::env('TYPESENSE_HOST') ?: 'localhost',
    'port' => App::env('TYPESENSE_PORT') ?: '8108',
    'protocol' => App::env('TYPESENSE_PROTOCOL') ?: 'http',
    'apiKey' => App::env('TYPESENSE_API_KEY') ?? '',

    // Request timeout (seconds), retries and pause between retries (seconds)
    'timeout' => 5,
    'numRetries' => 3,
    'retryInterval' => 1,

    // Prefix every collection name, e.g. per environment
    'collectionPrefix' => App::env('TYPESENSE_PREFIX') ?: '',

    // Sync on create/update/delete, and whether to use the queue for it
    'syncOnSave' => true,
    'useQueue' => true,
    'batchSize' => 100,

    // debug | info | warning | error | none
    'logLevel' => App::devMode() ? 'debug' : 'warning',

    'collections' => [
        // Collection handle (also the Typesense name, unless `name` is set)
        'site' => [
            // Native Typesense schema, without `name`.
            // `elementId`, `siteId` and `source` are added automatically.
            // Every indexed field can be used in `filter_by`; set `facet` to use it in `facet_by`.
            'schema' => [
                'fields' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'url', 'type' => 'string', 'index' => false, 'optional' => true],
                    ['name' => 'summary', 'type' => 'string', 'optional' => true],
                    ['name' => 'section', 'type' => 'string', 'facet' => true],
                    ['name' => 'categories', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                    ['name' => 'postDate', 'type' => 'int64', 'sort' => true],
                ],
                'default_sorting_field' => 'postDate',
            ],

            // One collection can have many sources, each with its own element type, criteria and transformer.
            'sources' => [
                'news' => [
                    'elementType' => Entry::class,
                    // Element query params (array) or a closure that receives the query.
                    'criteria' => ['section' => 'news'],
                    // Field map: Typesense field => attribute, custom field handle, dot path, or closure.
                    'transformer' => [
                        'title' => 'title',
                        'url' => 'url',
                        'summary' => 'summary',
                        'section' => fn(Entry $entry) => $entry->getSection()?->name,
                        'categories' => 'categories',
                        'postDate' => 'postDate',
                    ],
                ],
                'pages' => [
                    'elementType' => Entry::class,
                    'criteria' => fn($query) => $query->section('pages')->level('<= 2'),
                    // A closure that returns the whole document (or null to skip the element).
                    'transformer' => fn(Entry $entry) => [
                        'title' => $entry->title,
                        'url' => $entry->url,
                        'section' => 'Pages',
                        'postDate' => $entry->postDate,
                    ],
                ],

                // Craft Commerce products in the same collection:
                // 'products' => [
                //     'elementType' => \craft\commerce\elements\Product::class,
                //     'criteria' => ['type' => 'clothing'],
                //     'transformer' => \modules\search\ProductTransformer::class,
                // ],
            ],
        ],
    ],
];
