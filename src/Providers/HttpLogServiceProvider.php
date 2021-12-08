<?php

namespace Dub2000\HttpLog\Providers;

use Illuminate\Support\ServiceProvider;

class HttpLogServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadConfig();
        $this->loadRoutes();
        $this->loadViews();
//        $this->loadCommand();
        $this->loadMigrations();
    }

    public function loadConfig(){
        $this->publishes([
            __DIR__.'/../../config/http-log.php' => config_path('http-log.php')
        ], 'config');
    }

    public function loadRoutes(){
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
    }

    public function loadViews(){
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'http-log');
    }

    public function loadMigrations(){
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
