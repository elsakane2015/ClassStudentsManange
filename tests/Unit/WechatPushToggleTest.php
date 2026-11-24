<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\WechatController;
use App\Models\WechatConfig;
use App\Services\WechatPushService;
use PHPUnit\Framework\TestCase;

class WechatPushToggleTest extends TestCase
{
    public function test_should_skip_push_reason_respects_push_enabled_flag(): void
    {
        $this->assertSame('not_configured', WechatPushService::shouldSkipPushReason(null));

        $unverified = new WechatConfig([
            'is_verified' => false,
            'push_enabled' => true,
        ]);
        $this->assertSame('not_verified', WechatPushService::shouldSkipPushReason($unverified));

        $disabled = new WechatConfig([
            'is_verified' => true,
            'push_enabled' => false,
        ]);
        $this->assertSame('push_disabled', WechatPushService::shouldSkipPushReason($disabled));

        $enabled = new WechatConfig([
            'is_verified' => true,
            'push_enabled' => true,
        ]);
        $this->assertNull(WechatPushService::shouldSkipPushReason($enabled));

        // Legacy records without push_enabled column default to enabled
        $legacy = new WechatConfig([
            'is_verified' => true,
        ]);
        $this->assertTrue($legacy->isPushEnabled());
        $this->assertNull(WechatPushService::shouldSkipPushReason($legacy));
    }

    public function test_save_attributes_preserve_masked_secret_and_verification_when_credentials_unchanged(): void
    {
        $existing = new WechatConfig([
            'use_system' => false,
            'appid' => 'wx123456',
            'token' => 'my_token',
            'template_id' => 'tpl_old',
            'is_verified' => true,
            'push_enabled' => false,
        ]);

        // Only updating template_id while appsecret is masked should keep secret untouched and stay verified
        $attrs = WechatController::buildTeacherConfigSaveAttributes($existing, [
            'use_system' => false,
            'appid' => 'wx123456',
            'appsecret' => '******',
            'token' => 'my_token',
            'template_id' => 'tpl_new',
        ], true);

        $this->assertArrayNotHasKey('appsecret', $attrs);
        $this->assertTrue($attrs['is_verified']);
        $this->assertFalse($attrs['push_enabled']);
        $this->assertSame('tpl_new', $attrs['template_id']);

        // Explicit push_enabled update should apply immediately without breaking verification
        $enabledAttrs = WechatController::buildTeacherConfigSaveAttributes($existing, [
            'use_system' => false,
            'appid' => 'wx123456',
            'appsecret' => '******',
            'token' => 'my_token',
            'template_id' => 'tpl_new',
            'push_enabled' => true,
        ], true);

        $this->assertTrue($enabledAttrs['is_verified']);
        $this->assertTrue($enabledAttrs['push_enabled']);

        // Changing appid or providing new secret must reset verification
        $changedCreds = WechatController::buildTeacherConfigSaveAttributes($existing, [
            'use_system' => false,
            'appid' => 'wx999999',
            'appsecret' => 'new_secret_value',
            'token' => 'my_token',
            'template_id' => 'tpl_new',
        ], true);

        $this->assertFalse($changedCreds['is_verified']);
        $this->assertSame('new_secret_value', $changedCreds['appsecret']);
    }

    public function test_save_attributes_default_push_enabled_true_for_new_config(): void
    {
        $attrs = WechatController::buildTeacherConfigSaveAttributes(null, [
            'use_system' => true,
        ], true);

        $this->assertTrue($attrs['push_enabled']);
        $this->assertFalse($attrs['is_verified']);
        $this->assertNull($attrs['appsecret']);
    }
}
