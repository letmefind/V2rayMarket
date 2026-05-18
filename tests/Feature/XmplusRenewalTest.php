<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\XmplusService;
use App\Services\XmplusProvisioningService;
use App\Support\XmplusServicePanelState;
use App\Models\Setting;
use App\Models\User;
use App\Models\Order;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * تست‌های تمدید سرویس و خرید ترافیک XMPlus
 * 
 * برای اجرا:
 * php artisan test --filter XmplusRenewalTest
 */
class XmplusRenewalTest extends TestCase
{
    /**
     * تست بررسی وجود متد serviceRenew در XmplusService
     */
    public function test_service_renew_method_exists(): void
    {
        $this->assertTrue(
            method_exists(XmplusService::class, 'serviceRenew'),
            'متد serviceRenew در XmplusService وجود ندارد!'
        );
    }

    /**
     * تست بررسی وجود متد serviceAddTraffic در XmplusService
     */
    public function test_service_add_traffic_method_exists(): void
    {
        $this->assertTrue(
            method_exists(XmplusService::class, 'serviceAddTraffic'),
            'متد serviceAddTraffic در XmplusService وجود ندارد!'
        );
    }

    /**
     * تست بررسی امضای متد serviceRenew
     */
    public function test_service_renew_method_signature(): void
    {
        $reflection = new \ReflectionMethod(XmplusService::class, 'serviceRenew');
        $parameters = $reflection->getParameters();

        $this->assertCount(3, $parameters, 'serviceRenew باید 3 پارامتر داشته باشد');
        $this->assertEquals('email', $parameters[0]->getName());
        $this->assertEquals('passwd', $parameters[1]->getName());
        $this->assertEquals('sid', $parameters[2]->getName());
    }

    /**
     * تست بررسی امضای متد serviceAddTraffic
     */
    public function test_service_add_traffic_method_signature(): void
    {
        $reflection = new \ReflectionMethod(XmplusService::class, 'serviceAddTraffic');
        $parameters = $reflection->getParameters();

        $this->assertCount(4, $parameters, 'serviceAddTraffic باید 4 پارامتر داشته باشد');
        $this->assertEquals('email', $parameters[0]->getName());
        $this->assertEquals('passwd', $parameters[1]->getName());
        $this->assertEquals('sid', $parameters[2]->getName());
        $this->assertEquals('pid', $parameters[3]->getName());
    }

    /**
     * تست بررسی endpoint های API
     */
    public function test_api_endpoints(): void
    {
        // این تست فقط ساختار را بررسی می‌کند، نه اتصال واقعی
        // XmplusService نیاز به آدرس پنل و API key دارد، نه Settings
        $this->assertTrue(
            method_exists(XmplusService::class, 'serviceRenew'),
            'متد serviceRenew در XmplusService وجود دارد'
        );
    }

    /**
     * تست منطق تمدید در XmplusProvisioningService
     */
    public function test_do_renewal_method_exists(): void
    {
        $this->assertTrue(
            method_exists(XmplusProvisioningService::class, 'doRenewal'),
            'متد doRenewal در XmplusProvisioningService وجود ندارد!'
        );
    }

    public function test_service_panel_state_detects_expired_service(): void
    {
        $row = ['code' => 100, 'sid' => 17858, 'status' => 'Expired', 'expire_date' => ''];
        $this->assertTrue(XmplusServicePanelState::responseIndicatesExistingService($row));
        $this->assertFalse(XmplusServicePanelState::renewalAppliedOnPanel($row));
    }

    public function test_service_panel_state_detects_active_renewal(): void
    {
        $row = ['code' => 100, 'sid' => 4, 'status' => 'Active', 'expire_date' => '2027-01-11 00:47'];
        $this->assertTrue(XmplusServicePanelState::renewalAppliedOnPanel($row));
    }

    public function test_expired_with_future_date_is_not_renewal_applied(): void
    {
        $row = ['code' => 100, 'sid' => 17858, 'status' => 'Expired', 'expire_date' => '2027-07-01 00:59', 'traffic' => '0 B'];
        $this->assertFalse(XmplusServicePanelState::renewalAppliedOnPanel($row));
    }
}
