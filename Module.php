<?php

namespace modules\mymodule;

use Craft;
use yii\base\Module as BaseModule;
use craft\web\View;
use craft\events\RegisterCpNavItemsEvent;
use craft\web\twig\variables\Cp;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

class Module extends BaseModule
{
    public function init(): void
    {
        parent::init();

        Craft::info('Initializing MyModule', __METHOD__);

        $this->setBasePath(__DIR__);
        $this->controllerNamespace = 'modules\\mymodule\\controllers';
        Craft::info('Set base path and controller namespace', __METHOD__);

        // CP menu
        Event::on(Cp::class, Cp::EVENT_REGISTER_CP_NAV_ITEMS, function(RegisterCpNavItemsEvent $event) {
            Craft::info('Entered CP nav items event', __METHOD__);
            $event->navItems[] = [
                'url' => 'mymodule',
                'label' => 'My Module',
                'icon' => '@app/icons/bolt.svg'
            ];
            Craft::info('Added CP nav item', __METHOD__);
        });

        // Routes
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            Craft::info('Entered CP URL rules event', __METHOD__);
            $event->rules['mymodule'] = 'mymodule/default/index';
            $event->rules['mymodule/match-thumbnails'] = 'mymodule/default/match-thumbnails';
        //    $event->rules['mymodule/clean-thumbnails'] = 'mymodule/default/clean-thumbnails';  ← ДОДАЙ ЦЕЙ РЯДОК
            Craft::info('Registered CP URL rules', __METHOD__);
        });

        // Templates root
        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function($event) {
            Craft::info('Entered template roots event', __METHOD__);
            $event->roots['mymodule'] = __DIR__ . '/templates';
            Craft::info('Registered template root for mymodule', __METHOD__);
        });



        Craft::info('MyModule loaded successfully', __METHOD__);
    }
}
