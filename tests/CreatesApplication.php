<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // Répertoire de storage dédié aux tests : évite de polluer
        // storage/framework/views/livewire/classes/ (écrit par Livewire avec
        // l'UID de qui lance la suite, ce qui peut casser le serveur web prod).
        $testStorage = dirname(__DIR__).'/storage/testing';

        $subdirs = [
            'framework/views/livewire/classes',
            'framework/views/livewire/views',
            'framework/cache/data',
            'framework/sessions',
            'framework/testing',
            'logs',
            'app',
        ];
        foreach ($subdirs as $sub) {
            $dir = $testStorage.'/'.$sub;
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->useStoragePath($testStorage);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
