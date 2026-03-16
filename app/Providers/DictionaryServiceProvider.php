<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Dictionary\Dictionaries\BasicDictionary;
use App\Services\Dictionary\Dictionaries\DiagnoseGroupDictionary;
use App\Services\Dictionary\Dictionaries\DrugDictionary;
use App\Services\Dictionary\Dictionaries\ForbiddenGroupDictionary;
use App\Services\Dictionary\Dictionaries\MedicalProgramDictionary;
use App\Services\Dictionary\Dictionaries\ServiceDictionary;
use App\Services\Dictionary\DictionaryManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class DictionaryServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register DictionaryManager.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(DictionaryManager::class, function (Application $app) {
            $manager = new DictionaryManager();

            $manager->register($app->make(MedicalProgramDictionary::class));
            $manager->register($app->make(ServiceDictionary::class));
            $manager->register($app->make(BasicDictionary::class));
            $manager->register($app->make(DrugDictionary::class));
            $manager->register($app->make(DiagnoseGroupDictionary::class));
            $manager->register($app->make(ForbiddenGroupDictionary::class));

            return $manager;
        });
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [DictionaryManager::class];
    }
}
