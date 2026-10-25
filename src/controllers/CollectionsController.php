<?php

namespace craftyfm\typesenseintegration\controllers;

use craftyfm\typesenseintegration\jobs\IndexCollection;
use craftyfm\typesenseintegration\models\CollectionConfig;
use craftyfm\typesenseintegration\models\SourceConfig;
use craftyfm\typesenseintegration\TypesenseIntegration;
use Craft;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Collections pages of the Typesense control panel section.
 */
class CollectionsController extends Controller
{
    public const PERMISSION_MANAGE = 'typesense-integration:manage';

    private const ELEMENTS_PER_PAGE = 50;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('accessPlugin-typesense-integration');

        return true;
    }

    /**
     * Lists all collections with their status.
     */
    public function actionIndex(): Response
    {
        $plugin = TypesenseIntegration::getInstance();
        [$healthy, $error] = $this->connectionStatus();
        $rows = [];
        $configError = null;

        try {
            foreach ($plugin->collections->getAllCollections() as $collection) {
                $info = $healthy ? $this->remoteInfo($collection, $error) : null;
                $rows[] = [
                    'collection' => $collection,
                    'info' => $info,
                    'outdated' => $info && $plugin->collections->getSchemaDifferences($collection, $info),
                ];
            }
        } catch (Throwable $e) {
            $configError = $e->getMessage();
        }

        $settings = $plugin->getSettings();

        return $this->renderTemplate('typesense-integration/collections/_index.twig', [
            'healthy' => $healthy,
            'error' => $error,
            'configError' => $configError,
            'rows' => $rows,
            'canManage' => $this->canManage(),
            'configured' => $settings->isConfigured(),
            'nodes' => $settings->getNodes(),
            'syncOnSave' => $settings->syncOnSave,
            'useQueue' => $settings->useQueue,
            'settingsUrl' => UrlHelper::cpUrl('typesense-integration/settings'),
        ]);
    }

    /**
     * Shows one collection: overview, schema and sources.
     */
    public function actionView(string $handle): Response
    {
        $plugin = TypesenseIntegration::getInstance();
        $collection = $this->findCollection($handle);
        [$healthy, $error] = $this->connectionStatus();
        $info = $healthy ? $this->remoteInfo($collection, $error) : null;
        $sourceCounts = [];

        if ($info) {
            try {
                $sourceCounts = $plugin->collections->getDocumentCountsBySource($collection);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $sources = [];
        foreach ($collection->sources as $source) {
            $sources[] = [
                'source' => $source,
                'elementCount' => $this->countElements($source),
                'documentCount' => $sourceCounts[$source->handle] ?? 0,
                'criteria' => $source->describeCriteria(),
                'transformer' => $this->describeTransformer($source),
                'sample' => $this->sampleDocument($collection, $source),
            ];
        }

        $schema = $collection->getSchema();

        return $this->renderTemplate('typesense-integration/collections/_view.twig', [
            'collection' => $collection,
            'healthy' => $healthy,
            'error' => $error,
            'info' => $info,
            'differences' => $info ? $plugin->collections->getSchemaDifferences($collection, $info) : [],
            'fields' => $schema['fields'],
            'baseFields' => [
                CollectionConfig::FIELD_ELEMENT_ID,
                CollectionConfig::FIELD_SITE_ID,
                CollectionConfig::FIELD_SOURCE,
            ],
            'schemaOptions' => array_diff_key($schema, ['name' => true, 'fields' => true]),
            'sources' => $sources,
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Lists the elements of one source, with whether each one is in Typesense.
     */
    public function actionElements(string $handle, string $source): Response
    {
        $plugin = TypesenseIntegration::getInstance();
        $collection = $this->findCollection($handle);
        $sourceConfig = $collection->sources[$source] ?? throw new NotFoundHttpException('Source not found');
        [$healthy, $error] = $this->connectionStatus();

        $search = trim((string)$this->request->getQueryParam('search', ''));
        $page = max(1, (int)$this->request->getQueryParam('page', 1));

        $query = $sourceConfig->createQuery();
        if ($search !== '') {
            $query->search($search);
        }

        $total = (int)$query->count();
        $totalPages = max(1, (int)ceil($total / self::ELEMENTS_PER_PAGE));
        $page = min($page, $totalPages);
        $elements = $query
            ->offset(($page - 1) * self::ELEMENTS_PER_PAGE)
            ->limit(self::ELEMENTS_PER_PAGE)
            ->all();

        $indexed = null;
        if ($healthy && $elements) {
            try {
                $indexed = $plugin->collections->getIndexedKeys(
                    $collection,
                    $sourceConfig,
                    array_unique(array_map(fn($element) => $element->id, $elements)),
                );
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->renderTemplate('typesense-integration/collections/_elements.twig', [
            'collection' => $collection,
            'source' => $sourceConfig,
            'elements' => $elements,
            'indexed' => $indexed,
            'healthy' => $healthy,
            'error' => $error,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'offset' => ($page - 1) * self::ELEMENTS_PER_PAGE,
            'multiSite' => Craft::$app->getIsMultiSite(),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Queues an index (or rebuild) job for one collection, or all of them when none is given.
     */
    public function actionQueueIndex(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE);

        $plugin = TypesenseIntegration::getInstance();
        $handle = $this->request->getBodyParam('collection');
        $rebuild = (bool)$this->request->getBodyParam('rebuild');

        $handles = $handle
            ? [$this->findCollection($handle)->handle]
            : array_keys($plugin->collections->getAllCollections());

        foreach ($handles as $h) {
            Queue::push(new IndexCollection(['collection' => $h, 'rebuild' => $rebuild]));
        }

        return $this->asSuccess(Craft::t('typesense-integration', '{count, plural, =1{Indexing job} other{# indexing jobs}} queued.', [
            'count' => count($handles),
        ]));
    }

    /**
     * Drops a collection from Typesense.
     */
    public function actionDrop(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE);

        $collection = $this->findCollection($this->request->getRequiredBodyParam('collection'));

        try {
            TypesenseIntegration::getInstance()->collections->drop($collection);
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('typesense-integration', 'Collection “{name}” dropped.', [
            'name' => $collection->name,
        ]));
    }

    /**
     * Re-syncs one element right away.
     */
    public function actionSyncElement(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE);

        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $elementType = Craft::$app->getElements()->getElementTypeById($elementId)
            ?? throw new NotFoundHttpException('Element not found');

        try {
            TypesenseIntegration::getInstance()->indexer->syncElement($elementType, $elementId);
        } catch (Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('typesense-integration', 'Element synced.'));
    }

    private function findCollection(string $handle): CollectionConfig
    {
        return TypesenseIntegration::getInstance()->collections->getCollection($handle)
            ?? throw new NotFoundHttpException('Collection not found');
    }

    private function canManage(): bool
    {
        return Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE);
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function connectionStatus(): array
    {
        $plugin = TypesenseIntegration::getInstance();
        $error = null;
        $healthy = $plugin->getSettings()->isConfigured() && $plugin->client->isHealthy($error);

        return [$healthy, $error];
    }

    private function remoteInfo(CollectionConfig $collection, ?string &$error): ?array
    {
        try {
            return TypesenseIntegration::getInstance()->collections->getRemoteInfo($collection);
        } catch (Throwable $e) {
            $error = $e->getMessage();

            return null;
        }
    }

    private function countElements(SourceConfig $source): ?int
    {
        try {
            return (int)$source->createQuery()->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Builds the document for the first matching element, so editors can see what gets indexed.
     *
     * @return array{element: \craft\base\ElementInterface|null, document: array|null, error: string|null}
     */
    private function sampleDocument(CollectionConfig $collection, SourceConfig $source): array
    {
        try {
            $element = $source->createQuery()->one();

            return [
                'element' => $element,
                'document' => $element
                    ? TypesenseIntegration::getInstance()->indexer->buildDocument($collection, $source, $element)
                    : null,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['element' => null, 'document' => null, 'error' => $e->getMessage()];
        }
    }

    private function describeTransformer(SourceConfig $source): array
    {
        try {
            return $source->describeTransformer();
        } catch (Throwable $e) {
            return ['type' => 'error', 'class' => null, 'map' => null, 'error' => $e->getMessage()];
        }
    }
}
