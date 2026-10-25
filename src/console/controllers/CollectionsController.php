<?php

namespace x2network\typesenseintegration\console\controllers;

use x2network\typesenseintegration\models\CollectionConfig;
use x2network\typesenseintegration\TypesenseIntegration;
use craft\console\Controller;
use craft\helpers\Console;
use Throwable;
use yii\console\ExitCode;

/**
 * Manages Typesense collections.
 */
class CollectionsController extends Controller
{
    public $defaultAction = 'list';

    /**
     * Checks the connection and lists the configured collections with their document counts.
     */
    public function actionList(): int
    {
        $plugin = TypesenseIntegration::getInstance();

        if (!$plugin->client->isHealthy($error)) {
            $this->stderr("Typesense is not reachable: {$error}" . PHP_EOL, Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $this->stdout('Typesense is healthy.' . PHP_EOL . PHP_EOL, Console::FG_GREEN);

        $rows = [];
        foreach ($this->collections() as $collection) {
            $info = $plugin->collections->getRemoteInfo($collection);
            $rows[] = [
                $collection->handle,
                $collection->name,
                implode(', ', array_keys($collection->sources)),
                $info ? (string)$info['num_documents'] : 'not created',
            ];
        }

        if (!$rows) {
            $this->stdout('No collections are defined in config/typesense-integration.php.' . PHP_EOL, Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->table(['Handle', 'Typesense name', 'Sources', 'Documents'], $rows);

        return ExitCode::OK;
    }

    /**
     * Creates collections that don't exist yet in Typesense (all, or the given one).
     */
    public function actionCreate(?string $collection = null): int
    {
        $plugin = TypesenseIntegration::getInstance();

        foreach ($this->collections($collection) as $config) {
            try {
                $created = $plugin->collections->ensure($config);
                $this->stdout(
                    ($created ? "Created “{$config->name}”." : "“{$config->name}” already exists.") . PHP_EOL,
                    $created ? Console::FG_GREEN : Console::FG_GREY,
                );
            } catch (Throwable $e) {
                $this->stderr("Could not create “{$config->name}”: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }
        }

        return ExitCode::OK;
    }

    /**
     * Deletes a collection and all its documents from Typesense.
     */
    public function actionDrop(string $collection): int
    {
        $plugin = TypesenseIntegration::getInstance();
        $config = $this->collections($collection)[0];

        if ($this->interactive && !$this->confirm("Delete “{$config->name}” and all its documents from Typesense?")) {
            return ExitCode::OK;
        }

        try {
            $dropped = $plugin->collections->drop($config);
        } catch (Throwable $e) {
            $this->stderr("Could not drop “{$config->name}”: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(($dropped ? "Dropped “{$config->name}”." : "“{$config->name}” does not exist.") . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * @return CollectionConfig[]
     */
    private function collections(?string $handle = null): array
    {
        $collections = TypesenseIntegration::getInstance()->collections;

        return $handle
            ? [$collections->requireCollection($handle)]
            : array_values($collections->getAllCollections());
    }
}
