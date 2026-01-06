<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 在生产环境强制使用 HTTPS（解决反向代理后 URL 协议问题）
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
