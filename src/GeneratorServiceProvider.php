<?php

namespace Radish\LaravelGenerator;

use Radish\AMapDistrict\Console\DistrictCommand;
use Radish\AMapDistrict\Console\DistrictTableCommand;
use Illuminate\Support\ServiceProvider;

class GeneratorServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $configPath = __DIR__ . '/config/generator.php';

        $this->mergeConfigFrom($configPath, 'generator');

        if (function_exists('config_path')) {
            $publishPath = config_path('generator.php');
        } else {
            $publishPath = base_path('config/generator.php');
        }

        $this->publishes([$configPath => $publishPath], 'config');

        $this->app->singleton('command.radish.models', function ($app) {
            return $app['Radish\LaravelGenerator\Commands\MakeModelsCommand'];
        });

        $this->app->singleton('command.radish.api', function ($app) {
            return $app['Radish\LaravelGenerator\Commands\MakeAPICommand'];
        });

        $this->commands(['command.radish.models', 'command.radish.api']);

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
