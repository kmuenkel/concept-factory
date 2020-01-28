<?php

namespace Concept\Providers;

use Concept\Generators\Concept;
use Faker\Generator as FakerGenerator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Factory as EloquentFactory;
use Concept\Overrides\Illuminate\Database\Eloquent\Factory as OverrideFactory;

/**
 * Class ConceptServiceProvider
 * @package Concept\Providers
 */
class ConceptServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @void
     */
    public function boot()
    {
        $source = realpath($raw = __DIR__ . '/../../config/concepts.php') ?: $raw;
        $this->publishes([$source => config_path('concepts.php')]);
        $this->mergeConfigFrom($source, 'concepts');
    }

    /**
     * @void
     */
    public function register()
    {
        $this->registerConceptNames();
        $this->registerModelFactoryOverrides();
    }

    /**
     * @void
     */
    protected function registerConceptNames()
    {
        $path = config('concepts.path');
        $path && load($path, Concept::class, function (Concept $concept) {
            Concept::register($concept);
        });

        $classNames = config('concepts.registry', []);
        foreach ($classNames as $className) {
            Concept::register(app($className));
        }
    }

    /**
     * @void
     */
    protected function registerModelFactoryOverrides()
    {
        $this->app->singleton(EloquentFactory::class, function (Application $app) {
            return OverrideFactory::construct(
                $app->make(FakerGenerator::class), $this->app->databasePath('factories')
            );
        });
    }
}
