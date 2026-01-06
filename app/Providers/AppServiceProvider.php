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
        // 获取应用配置的时区 (默认为 config/app.php 中的 'Asia/Shanghai')
        // 这样既解决了 Docker 时区问题，又保留了通过环境变量 APP_TIMEZONE 修改时区的能力
        $timezone = config('app.timezone', 'Asia/Shanghai');
        
        // 同步 PHP 进程的系统时区
        date_default_timezone_set($timezone);
        
        // 设置 Carbon 语言
        \Carbon\Carbon::setLocale('zh');
        
        // 强制 Carbon 序列化为 "服务器当前时间" 的字符串格式 (Y-m-d H:i:s)
        // 这样前端会直接显示服务器时间 (如 08:00)，而不会被浏览器根据本地时区自动转换
        // 适用于 "学校在东八区，管理员也在东八区" 或 "希望看到学校当地时间" 的场景
        \Carbon\Carbon::serializeUsing(function ($carbon) {
            return $carbon->format('Y-m-d H:i:s');
        });
    }
}
