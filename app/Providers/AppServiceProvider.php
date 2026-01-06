<?php

namespace App\Providers;

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
        // 强制设置时区 (解决 Docker/Coolify 部署时区问题)
        date_default_timezone_set('Asia/Shanghai');
        config(['app.timezone' => 'Asia/Shanghai']);
        config(['app.locale' => 'zh_CN']);
        
        // 设置 Carbon 语言
        \Carbon\Carbon::setLocale('zh');
        
        // 强制 Carbon 序列化为带时区的 ISO 字符串，或者直接给前端 Y-m-d H:i:s
        // 这里使用 Y-m-d H:i:s 确保前端收到的就是"本地"时间字符串 (15:22)
        // 这样即使前端解析有误，看到的也是正确的时间数字
        \Carbon\Carbon::serializeUsing(function ($carbon) {
            return $carbon->format('Y-m-d H:i:s');
        });
    }
}
