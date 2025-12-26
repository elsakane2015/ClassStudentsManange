<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 班主任的微信公众号配置
        Schema::create('wechat_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // 班主任用户ID
            $table->boolean('use_system')->default(true); // 是否使用系统公众号
            $table->string('appid')->nullable(); // 自定义AppID
            $table->text('appsecret')->nullable(); // 自定义AppSecret (加密存储)
            $table->string('token')->nullable(); // 自定义Token
            $table->string('template_id')->nullable(); // 模板消息ID
            $table->boolean('is_verified')->default(false); // 是否验证通过
            $table->timestamp('verified_at')->nullable(); // 验证时间
            $table->timestamps();
            
            $table->unique('user_id');
        });

        // 微信绑定记录
        Schema::create('wechat_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // 绑定的用户
            $table->unsignedBigInteger('config_id')->nullable(); // 绑定的公众号配置ID (0=系统, null=未绑定)
            $table->string('openid'); // 微信OpenID
            $table->string('nickname')->nullable(); // 微信昵称
            $table->string('headimgurl')->nullable(); // 微信头像
            $table->timestamps();
            
            $table->unique(['user_id', 'config_id']);
            $table->index('openid');
        });

        // 推送日志
        Schema::create('wechat_push_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('config_id')->nullable(); // 使用的配置ID
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // 接收者
            $table->string('openid'); // 接收的OpenID
            $table->string('template_id'); // 模板ID
            $table->json('content')->nullable(); // 发送内容
            $table->string('status'); // success/failed
            $table->text('error_msg')->nullable(); // 失败原因
            $table->string('related_type')->nullable(); // 关联类型 (leave_request等)
            $table->unsignedBigInteger('related_id')->nullable(); // 关联ID
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wechat_push_logs');
        Schema::dropIfExists('wechat_bindings');
        Schema::dropIfExists('wechat_configs');
    }
};
