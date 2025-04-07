<?php

namespace strawberrydev\Siftify;

use Illuminate\Support\ServiceProvider;

class SiftifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/siftify.php', 'siftify'
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/siftify.php' => config_path('siftify.php'),
        ], 'siftify-config');
    }
}
