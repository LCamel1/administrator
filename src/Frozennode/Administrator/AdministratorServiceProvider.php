<?php

namespace Frozennode\Administrator;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Frozennode\Administrator\DataTable\DataTable;
use Illuminate\Support\Facades\Validator as LValidator;
use Frozennode\Administrator\Fields\Factory as FieldFactory;
use Frozennode\Administrator\Config\Factory as ConfigFactory;
use Frozennode\Administrator\Actions\Factory as ActionFactory;
use Frozennode\Administrator\DataTable\Columns\Factory as ColumnFactory;

class AdministratorServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;
    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../../views', 'administrator');

        $this->fixConfigAndRouteCacheIfNeeded();

        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'administrator');
        $this->publishes([
            __DIR__.'/../../config/administrator.php' => config_path('administrator.php'),
        ]);
        $this->publishes([
            __DIR__.'/../../../public' => public_path('packages/frozennode/administrator'),
        ], 'public');
        //set the locale
        $this->setLocale();
        $this->app['events']->dispatch('administrator.ready');
    }
    /**
     * Register the service provider.
     */
    public function register()
    {
        //include our view composers, and routes to avoid issues with catch-all routes defined by users
        include __DIR__.'/../../viewComposers.php';
        include __DIR__.'/../../helpers.php';

        $this->fixConfigAndRouteCacheIfNeeded();

        // Load route with web middleware
        Route::group([
            'middleware' => 'web',
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../../routes.php');
        });
        //the admin validator
        $this->app->singleton('admin_validator', function ($app) {
            //get the original validator class so we can set it back after creating our own
            $originalValidator = LValidator::make(array(), array());
            $originalValidatorClass = get_class($originalValidator);
            //temporarily override the core resolver
            LValidator::resolver(function ($translator, $data, $rules, $messages) use ($app) {
                $validator = new Validator($translator, $data, $rules, $messages);
                $validator->setUrlInstance($app->make('url'));
                return $validator;
            });
            //grab our validator instance
            $validator = LValidator::make(array(), array());
            //set the validator resolver back to the original validator
            LValidator::resolver(function ($translator, $data, $rules, $messages) use ($originalValidatorClass) {
                return new $originalValidatorClass($translator, $data, $rules, $messages);
            });
            //return our validator instance
            return $validator;
        });
        //set up the shared instances
        $this->app->singleton('admin_config_factory', function ($app) {
            return new ConfigFactory($app->make('admin_validator'), LValidator::make(array(), array()), config('administrator'));
        });
        $this->app->singleton('admin_field_factory', function ($app) {
            return new FieldFactory($app->make('admin_validator'), $app->make('itemconfig'), $app->make('db'));
        });
        $this->app->singleton('admin_datatable', function ($app) {
            $dataTable = new DataTable($app->make('itemconfig'), $app->make('admin_column_factory'), $app->make('admin_field_factory'));
            $dataTable->setRowsPerPage($app->make('session.store'), config('administrator.global_rows_per_page'));
            return $dataTable;
        });
        $this->app->singleton('admin_column_factory', function ($app) {
            return new ColumnFactory($app->make('admin_validator'), $app->make('itemconfig'), $app->make('db'));
        });
        $this->app->singleton('admin_action_factory', function ($app) {
            return new ActionFactory($app->make('admin_validator'), $app->make('itemconfig'), $app->make('db'));
        });
        $this->app->singleton('admin_menu', function ($app) {
            return new Menu($app->make('config'), $app->make('admin_config_factory'));
        });
    }
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array('admin_validator', 'admin_config_factory', 'admin_field_factory', 'admin_datatable', 'admin_column_factory',
            'admin_action_factory', 'admin_menu', );
    }
    /**
     * Sets the locale if it exists in the session and also exists in the locales option.
     */
    public function setLocale()
    {
        if ($locale = $this->app->session->get('administrator_locale')) {
            $this->app->setLocale($locale);
        }
    }

    public function fixConfigAndRouteCacheIfNeeded()
    {
        // Hack for laravel config cache
        $file = config_path('administrator.php');
        if (file_exists($file)) {

            if ($this->app->configurationIsCached()) {
                $this->app['config']->set('administrator', array_merge(
                    require $file, $this->app['config']->get('administrator', [])
                ));
            }

            if (app()->runningInConsole()) {
                $configs = \Config::get('administrator');
                $configs = $this->filter_recursive($configs);
                \Config::set('administrator', $configs);
            }
        }
    }

    public function filter_recursive(&$array)
    {
        foreach ( $array as $key => $item) {
            is_array($item) && $array[$key] = $this->filter_recursive($item);
            if (is_callable($array[$key])) {
                unset($array[$key]);
            }
        }
        return $array;
    }
}
