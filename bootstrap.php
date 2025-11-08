<?php

declare(strict_types=1);

use csabourin\craftS3SpacesMigration\MigrationModule;

// Charger le fichier module.php qui contient MigrationModule
require_once __DIR__ . '/modules/module.php';

// Fonction pour enregistrer le module
if (!function_exists('craft_s3_spaces_migration_register_module')) {
    function craft_s3_spaces_migration_register_module(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;

        // Attendre que Craft soit disponible
        if (!class_exists('Craft')) {
            return;
        }

        // Enregistrer le module immédiatement dès que Craft est chargé
        $app = \Craft::$app;
        
        if ($app !== null && !$app->hasModule('s3-spaces-migration')) {
            $app->setModule('s3-spaces-migration', [
                'class' => MigrationModule::class,
            ]);
            
            // Charger le module immédiatement
            $app->getModule('s3-spaces-migration');
            
            if (defined('CRAFT_ENVIRONMENT') && CRAFT_ENVIRONMENT === 'dev') {
                \Craft::info('[S3 Migration] Module registered via bootstrap.php', __METHOD__);
            }
        }
    }
}

// Essayer de charger immédiatement si Craft est déjà initialisé
if (class_exists('Craft') && \Craft::$app !== null) {
    craft_s3_spaces_migration_register_module();
} else {
    // Sinon, s'enregistrer sur l'event d'initialisation de l'app
    if (class_exists('craft\base\ApplicationTrait')) {
        \yii\base\Event::on(
            \craft\base\ApplicationTrait::class,
            \craft\base\ApplicationTrait::EVENT_INIT,
            function() {
                craft_s3_spaces_migration_register_module();
            }
        );
    }
}