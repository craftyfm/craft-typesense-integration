<?php

namespace x2network\typesenseintegration\controllers;

use x2network\typesenseintegration\TypesenseIntegration;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The Settings page of the Typesense control panel section.
 */
class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireAdmin();

        $plugin = TypesenseIntegration::getInstance();

        return $this->renderTemplate('typesense-integration/settings/_index.twig', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
            'overrides' => array_keys(Craft::$app->getConfig()->getConfigFromFile($plugin->handle)),
        ]);
    }
}
