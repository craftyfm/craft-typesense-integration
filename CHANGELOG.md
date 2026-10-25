# Release Notes for Typesense Integration

## 1.0.0 - 2026-10-24

- Initial release.
- Connection settings (host, port, protocol, path, API key, cluster nodes) with environment variable support.
- Collections, schemas and sources defined in `config/typesense-integration.php`; one collection can index several element types.
- Field map, closure and class-based transformers, with automatic casting to the schema field types.
- Automatic syncing when elements are created, updated, disabled, deleted or restored (queued by default).
- Typesense control panel section:
  - Collections list with status and document counts.
  - Collection pages with overview, schema, sources, field mappings and sample documents.
  - Per-source element browser showing which elements are indexed, with a sync button.
  - Index, rebuild and drop actions, protected by a dedicated user permission.
  - Settings page.
- `typesense-integration/index` and `typesense-integration/collections` console commands.
- Request timeout, retries and level-based logging to `storage/logs/typesense-integration-*.log`.
- `craft.typesense.search()` Twig helper.
