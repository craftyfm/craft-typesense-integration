# Typesense Integration for Craft CMS

Index Craft elements into [Typesense](https://typesense.org) using the official
[Typesense PHP SDK](https://github.com/typesense/typesense-php).

- Collections and schemas defined in one config file
- A collection can have **multiple sources**, e.g. entries, categories and Commerce products in one index
- Transformers: field map, closure or class, with values cast to the schema types automatically
- Facet and filter on any field
- Automatic sync on create, update, disable, delete and restore (via the queue)
- A **Typesense** section in the control panel to browse collections, schemas, sources and elements, and to index, rebuild or drop them
- Console commands for the same tasks
- Request timeout, retries and level-based logging

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- Typesense server (self-hosted or Typesense Cloud)

## Installation

```bash
composer require craftyfm/craft-typesense-integration
php craft plugin/install typesense-integration
```

## Configuration

Settings can be edited in **Typesense → Settings** (admins only, when `allowAdminChanges` is on), but the
recommended setup is a config file. Copy `vendor/craftyfm/craft-typesense-integration/src/config.php`
to `config/typesense-integration.php`. Anything set there overrides the control panel.

```php
use craft\helpers\App;

return [
    'host' => App::env('TYPESENSE_HOST') ?: 'localhost',
    'port' => App::env('TYPESENSE_PORT') ?: '8108',
    'protocol' => App::env('TYPESENSE_PROTOCOL') ?: 'http',
    'apiKey' => App::env('TYPESENSE_API_KEY') ?? '',
    'collections' => [/* see below */],
];
```

| Setting | Default | Description |
| --- | --- | --- |
| `host`, `port`, `protocol`, `path` | `localhost`, `8108`, `http`, `''` | Typesense node. Supports `$ENV_VARS`. |
| `nodes` | `[]` | Cluster nodes (`[['host' => …, 'port' => …, 'protocol' => …]]`). Overrides the single-node settings. |
| `apiKey` | `''` | Admin API key (write access). Supports `$ENV_VARS`. |
| `timeout` | `5` | Seconds before a request fails (connect and total). |
| `numRetries` | `3` | Retries on network or 5xx errors. |
| `retryInterval` | `1` | Seconds between retries. |
| `collectionPrefix` | `''` | Prefix for every collection name, e.g. `dev_`. |
| `syncOnSave` | `true` | Sync when elements are created, updated, deleted or restored. |
| `useQueue` | `true` | Sync in a queue job instead of during the save request. |
| `batchSize` | `100` | Elements per import request when indexing a collection. |
| `logLevel` | `warning` | `debug`, `info`, `warning`, `error` or `none`. |
| `collections` | `[]` | Collection definitions (config file only). |

> Keep `useQueue` on in production. With it off, a slow or unavailable Typesense server
> delays the save request by up to `timeout × (numRetries + 1)` seconds. Saves never fail
> because of Typesense; errors are logged.

## Collections

Each collection has a native Typesense `schema` and one or more `sources`.

```php
use craft\elements\Entry;
use craft\commerce\elements\Product;

'collections' => [
    'site' => [
        // Optional. Defaults to the handle. The collection prefix is added to it.
        'name' => 'site',

        // Native Typesense schema (without `name`).
        'schema' => [
            'fields' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'summary', 'type' => 'string', 'optional' => true],
                ['name' => 'type', 'type' => 'string', 'facet' => true],
                ['name' => 'categories', 'type' => 'string[]', 'facet' => true, 'optional' => true],
                ['name' => 'price', 'type' => 'float', 'facet' => true, 'optional' => true],
                ['name' => 'postDate', 'type' => 'int64', 'sort' => true],
                ['name' => 'url', 'type' => 'string', 'index' => false, 'optional' => true],
            ],
            'default_sorting_field' => 'postDate',
        ],

        'sources' => [
            'articles' => [
                'elementType' => Entry::class,
                'criteria' => ['section' => 'news'],
                'transformer' => [
                    'title' => 'title',
                    'summary' => 'summary',
                    'type' => fn() => 'Article',
                    'categories' => 'categories',
                    'postDate' => 'postDate',
                    'url' => 'url',
                ],
            ],
            'products' => [
                'elementType' => Product::class,
                'criteria' => fn($query) => $query->type('clothing')->availableForPurchase(true),
                'transformer' => fn(Product $product) => [
                    'title' => $product->title,
                    'type' => 'Product',
                    'price' => $product->defaultPrice,
                    'postDate' => $product->postDate,
                    'url' => $product->url,
                ],
            ],
        ],
    ],
],
```

### Filtering and faceting

Every indexed field can be used in `filter_by`. Add `'facet' => true` to use it in `facet_by`,
and `'sort' => true` to sort on a string field. Use `'optional' => true` for fields that may be
empty and `'index' => false` for fields that are only stored (like a URL).

These fields are added to every document automatically, so you don't need to define them:

| Field | Type | Description |
| --- | --- | --- |
| `id` | string | `{source}-{elementId}-{siteId}` |
| `elementId` | int64 | Craft element ID |
| `siteId` | int32, facet | Craft site ID |
| `source` | string, facet | Source handle, e.g. `filter_by: 'source:=products'` |

### Sources

| Key | Description |
| --- | --- |
| `elementType` | Element class: `Entry::class`, `Category::class`, `Asset::class`, `User::class`, `Product::class`, … |
| `criteria` | Element query params as an array, or a closure that receives (and may return) the query. Default element query rules apply, so only live elements are indexed unless you change `status`. Use `'site' => '*'` to index every site. |
| `transformer` | See below. |

When an element is saved, its documents are updated in every collection whose source matches:
documents for sites that still match the criteria are upserted, all others (disabled, moved out of
the section, deleted…) are removed.

### Transformers

A transformer returns an array keyed by Typesense field. Values are cast to the field type in the
schema: dates become timestamps, relation fields become lists of titles, Money becomes a float,
option fields become their values, and `null` values are dropped. Return `null` to skip an element.

**Field map** — Typesense field → attribute, custom field handle, dot path, or closure:

```php
'transformer' => [
    'title' => 'title',
    'author' => 'author.fullName',
    'image' => fn($entry) => $entry->featuredImage->one()?->getUrl(),
],
```

**Closure:**

```php
'transformer' => fn(Entry $entry) => ['title' => $entry->title, 'postDate' => $entry->postDate],
```

**Class** (class name, config array with `class`, or instance):

```php
namespace modules\search;

use craftyfm\typesenseintegration\transformers\TransformerInterface;
use craft\base\ElementInterface;

class ProductTransformer implements TransformerInterface
{
    public function transform(ElementInterface $element): ?array
    {
        return [
            'title' => $element->title,
            'price' => $element->defaultPrice,
            'sizes' => array_map(fn($v) => $v->size->value, $element->getVariants()),
        ];
    }
}
```

### Modifying documents with an event

```php
use craftyfm\typesenseintegration\events\DocumentEvent;
use craftyfm\typesenseintegration\services\Indexer;
use yii\base\Event;

Event::on(Indexer::class, Indexer::EVENT_BEFORE_INDEX_DOCUMENT, function(DocumentEvent $event) {
    $event->document['boost'] = $event->element->featured ? 10 : 0;
    // $event->isValid = false; // skip this element
});
```

### Resyncing on a related element's change

A document often denormalizes data from a *related* element - a category's title, an author's
name. Saving the related element doesn't match any source's own `elementType`, so nothing resyncs
it on its own. A source's `relatedTriggers` closes that gap: when an element matching a trigger is
saved, restored or deleted, every element of that source currently related to it is resynced.

```php
'sources' => [
    'site' => [
        'elementType' => Entry::class,
        'criteria' => ['section' => 'news'],
        'transformer' => [/* ... */],

        'relatedTriggers' => [
            [
                // When a Category in the "topics" group changes, resync every entry
                // related to it through the "topics" field.
                'elementType' => Category::class,
                'criteria' => fn(CategoryQuery $query) => $query->group('topics'),
                'field' => 'topics',
            ],
        ],
    ],
],
```

| Key | Description |
| --- | --- |
| `elementType` | The related element's class, e.g. `Category::class`. |
| `criteria` | Optional. Element query params (array) or a closure that receives the query, narrowing which elements of `elementType` trigger a resync - e.g. one category group among several. |
| `field` | Optional. The relation field handle to scope `relatedTo()` to. Omit it to match any field that relates the two. |

This is one hop only: resyncing a related element re-transforms and re-imports it, but never saves
it, so a trigger can't cascade into itself or another trigger.

**Known limits:**

- **Ancestors aren't walked.** If a transformer also denormalizes a related category's
  *ancestors* (e.g. breadcrumb titles), renaming an ancestor that isn't itself directly related to
  an element isn't covered - only elements directly related to the saved/deleted element are
  resynced.
- **No stale-document cleanup here.** A related resync only republishes elements currently
  related to the trigger; it has no notion of "used to be related, now isn't." Unlinking a
  relation is an edit to the element itself, which already resyncs it through the normal
  save-triggered sync.
- **A hard-deleted trigger element is a no-op.** If the related element is hard-deleted (not just
  trashed) before a queued resync runs, it can no longer be found and the job does nothing - the
  same gap a direct sync has for an element that's gone by the time its own job runs.

## Indexing

### Control panel

The plugin adds a **Typesense** section with two pages:

- **Collections** lists every collection with its sources, document count and status
  (*Ready*, *Not created*, or *Schema changed* when the config no longer matches Typesense).
  Open a collection to see:
  - **Overview**: document and element counts, schema options, schema differences, and a summary of each source.
  - **Schema**: every field with its type and whether it's facetable, indexed (filterable), sortable or optional.
  - **Sources**: each source's criteria, transformer, field mapping, and a sample document generated from a real element.
  - **View elements** (per source): the matching elements, paginated and searchable, with whether each one is in
    Typesense and a **Sync** button.
- **Settings**: the plugin settings (admins only).

The **Index all**, **Rebuild all**, **Index**, **Rebuild** and **Drop** buttons are on the Collections pages.
Index and Rebuild run as queue jobs.

- **Index** creates the collection if needed and upserts every element.
- **Rebuild** drops and recreates the collection first. Use it after changing the schema, or to remove stale documents.

#### Permissions

- **Access Typesense Integration** (Craft's standard plugin permission) lets a user view the section.
- **Index, rebuild and drop collections** lets them use the action buttons and the Sync button.
- The Settings page requires an admin.

### Console

```bash
php craft typesense-integration/collections            # health check + collections and document counts
php craft typesense-integration/collections/create     # create missing collections
php craft typesense-integration/collections/drop site  # drop a collection

php craft typesense-integration/index                  # index all collections
php craft typesense-integration/index site             # index one collection
php craft typesense-integration/index site --rebuild   # drop, recreate and index
php craft typesense-integration/index --queue          # push queue jobs instead
php craft typesense-integration/index/element 123      # re-sync one element
```

Entries with a future post date aren't live yet, so they're not indexed when saved. Run
`php craft typesense-integration/index` on a schedule (e.g. hourly cron) if you publish ahead of time.
The same applies when related content changes (e.g. a category is renamed): the entries that
reference it are updated on the next full index.

## Searching from Twig

```twig
{% set results = craft.typesense.search('site', {
    q: craft.app.request.getQueryParam('q'),
    query_by: 'title,summary',
    filter_by: 'source:=articles',
    facet_by: 'categories',
}) %}

{% for hit in results.hits ?? [] %}
    <a href="{{ hit.document.url }}">{{ hit.document.title }}</a>
{% endfor %}
```

The search runs server-side with the admin key. For browser search (e.g. InstantSearch), create a
search-only API key in Typesense and use `craft.typesense.collectionName('site')` to get the
prefixed collection name.

## Logging

Messages at or above `logLevel` are written to `storage/logs/typesense-integration-YYYY-MM-DD.log`.
`debug` logs every synced element; `info` logs collection-level operations; `error` logs failed
documents and connection problems.
