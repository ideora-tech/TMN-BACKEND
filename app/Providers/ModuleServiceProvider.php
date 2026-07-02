<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;

class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (!File::isDirectory(app_path('Modules'))) {
            return;
        }
        $modules = File::directories(app_path('Modules'));
        foreach ($modules as $module) {
            $moduleName = basename($module);
            $providerClass = "App\\Modules\\{$moduleName}\\{$moduleName}ServiceProvider";
            if (class_exists($providerClass)) {
                $this->app->register($providerClass);
            }
        }
    }
}
