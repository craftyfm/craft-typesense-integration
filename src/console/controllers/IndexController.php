<?php

namespace craftyfm\typesenseintegration\console\controllers;

use craftyfm\typesenseintegration\jobs\IndexCollection;
use craftyfm\typesenseintegration\TypesenseIntegration;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\Queue;
use Throwable;
use yii\console\ExitCode;

/**
 * Indexes collections in Typesense.
 */
class IndexController extends Controller
{
    public $defaultAction = 'index';

    /**
     * @var bool Drop and recreate the collection before indexing (applies schema changes, removes stale documents).
     */
    public bool $rebuild = false;

    /**
     * @var bool Push a queue job instead of indexing right away.
     */
    public bool $queue = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'index') {
            $options[] = 'rebuild';
            $options[] = 'queue';
        }

        return $options;
    }

    /**
     * Indexes one collection, or all of them when no handle is given.
     *
     * Examples:
     *   php craft typesense-integration/index
     *   php craft typesense-integration/index site --rebuild
     *   php craft typesense-integration/index --queue
     */
    public function actionIndex(?string $collection = null): int
    {
        $plugin = TypesenseIntegration::getInstance();

        try {
            $collections = $collection
                ? [$plugin->collections->requireCollection($collection)]
                : $plugin->collections->getAllCollections();
        } catch (Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);

            return ExitCode::CONFIG;
        }

        if (!$collections) {
            $this->stdout('No collections are defined in config/typesense-integration.php.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $exitCode = ExitCode::OK;

        foreach ($collections as $config) {
            if ($this->queue) {
                Queue::push(new IndexCollection(['collection' => $config->handle, 'rebuild' => $this->rebuild]));
                $this->stdout("Queued “{$config->handle}”." . PHP_EOL, Console::FG_GREEN);
                continue;
            }

            $this->stdout(($this->rebuild ? 'Rebuilding' : 'Indexing') . " “{$config->handle}” → {$config->name}" . PHP_EOL);

            try {
                $started = false;
                $stats = $plugin->indexer->indexCollection($config, $this->rebuild, function(int $done, int $total) use (&$started) {
                    if (!$started) {
                        Console::startProgress(0, $total);
                        $started = true;
                    }
                    Console::updateProgress($done, $total);
                });
                Console::endProgress();

                $this->stdout(sprintf(
                    'Done: %d indexed, %d failed, %d skipped.' . PHP_EOL,
                    $stats['indexed'],
                    $stats['failed'],
                    $stats['skipped'],
                ), $stats['failed'] ? Console::FG_YELLOW : Console::FG_GREEN);

                if ($stats['failed']) {
                    $exitCode = ExitCode::UNSPECIFIED_ERROR;
                }
            } catch (Throwable $e) {
                Console::endProgress();
                $this->stderr("Failed: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);
                $exitCode = ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return $exitCode;
    }

    /**
     * Re-syncs a single element by ID in every collection it belongs to.
     *
     * Example:
     *   php craft typesense-integration/index/element 123
     */
    public function actionElement(int $id): int
    {
        $plugin = TypesenseIntegration::getInstance();
        $elementType = \Craft::$app->getElements()->getElementTypeById($id);

        if ($elementType === null) {
            $this->stderr("No element exists with the ID {$id}." . PHP_EOL, Console::FG_RED);

            return ExitCode::DATAERR;
        }

        try {
            $plugin->indexer->syncElement($elementType, $id);
        } catch (Throwable $e) {
            $this->stderr("Failed: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Synced element {$id}." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
