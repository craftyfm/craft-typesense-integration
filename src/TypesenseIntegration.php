<?php

namespace x2network\typesenseintegration;

use x2network\typesenseintegration\controllers\CollectionsController;
use x2network\typesenseintegration\helpers\Log;
use x2network\typesenseintegration\models\Settings;
use x2network\typesenseintegration\services\Client;
use x2network\typesenseintegration\services\Collections;
use x2network\typesenseintegration\services\Indexer;
use x2network\typesenseintegration\services\ScopedKeys;
use x2network\typesenseintegration\variables\TypesenseVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use Monolog\Formatter\LineFormatter;
use yii\base\Event;

/**
 * Typesense Integration plugin.
 *
 * @property-read Client $client
 * @property-read Collections $collections
 * @property-read Indexer $indexer
 * @property-read ScopedKeys $scopedKeys
 * @method Settings getSettings()
 */
class TypesenseIntegration extends Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'client' => Client::class,
                'collections' => Collections::class,
                'indexer' => Indexer::class,
                'scopedKeys' => ScopedKeys::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();
        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerVariable();

        // Wait until all plugins are loaded so element types from other plugins (e.g. Commerce) are available.
        Craft::$app->onInit(function() {
            $this->registerElementEvents();
        });
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('typesense-integration', 'Typesense');
        $item['subnav'] = [
            'collections' => [
                'label' => Craft::t('typesense-integration', 'Collections'),
                'url' => 'typesense-integration/collections',
            ],
        ];

        $user = Craft::$app->getUser()->getIdentity();
        if ($user?->admin && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('app', 'Settings'),
                'url' => 'typesense-integration/settings',
            ];
        }

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('typesense-integration/settings'));
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function registerLogTarget(): void
    {
        $level = Log::psrLevel($this->getSettings()->logLevel);

        if ($level === null) {
            return;
        }

        Craft::getLogger()->dispatcher->targets[Log::CATEGORY] = new MonologTarget([
            'name' => Log::CATEGORY,
            'categories' => [Log::CATEGORY],
            'level' => $level,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% [%level_name%] %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules += [
                    'typesense-integration' => 'typesense-integration/collections/index',
                    'typesense-integration/collections' => 'typesense-integration/collections/index',
                    'typesense-integration/collections/<handle:[^\/]+>' => 'typesense-integration/collections/view',
                    'typesense-integration/collections/<handle:[^\/]+>/<source:[^\/]+>' => 'typesense-integration/collections/elements',
                    'typesense-integration/settings' => 'typesense-integration/settings/index',
                ];
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('typesense-integration', 'Typesense Integration'),
                    'permissions' => [
                        CollectionsController::PERMISSION_MANAGE => [
                            'label' => Craft::t('typesense-integration', 'Index, rebuild and drop collections'),
                        ],
                    ],
                ];
            }
        );
    }

    private function registerVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('typesense', TypesenseVariable::class);
            }
        );
    }

    private function registerElementEvents(): void
    {
        if (!$this->getSettings()->syncOnSave) {
            return;
        }

        $handler = function(ElementEvent $event) {
            $this->indexer->queueSync($event->element);
        };

        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, $handler);
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, $handler);
    }
}
