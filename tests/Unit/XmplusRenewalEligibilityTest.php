<?php

namespace Tests\Unit;

use App\Support\XmplusRenewalEligibility;
use App\Support\XmplusServicePanelState;
use Tests\TestCase;

class XmplusRenewalEligibilityTest extends TestCase
{
    public function test_expired_status_minus_one(): void
    {
        $r = XmplusRenewalEligibility::evaluateFromServiceRow(['status' => -1, 'traffic' => '100 GB', 'used_traffic' => '10 GB']);
        $this->assertTrue($r['allowed']);
        $this->assertTrue($r['expired']);
    }

    public function test_low_remaining_traffic_allows_renew(): void
    {
        $r = XmplusRenewalEligibility::evaluateFromServiceRow([
            'status' => 'Active',
            'traffic' => '100 GB',
            'used_traffic' => '95 GB',
        ]);
        $this->assertTrue($r['allowed']);
        $this->assertTrue($r['low_traffic']);
    }

    public function test_active_with_plenty_traffic_denied(): void
    {
        $r = XmplusRenewalEligibility::evaluateFromServiceRow([
            'status' => 'Active',
            'traffic' => '100 GB',
            'used_traffic' => '10 GB',
        ]);
        $this->assertFalse($r['allowed']);
    }

    public function test_parse_data_sizes(): void
    {
        $this->assertSame(100 * 1024 ** 3, XmplusRenewalEligibility::parseDataSizeToBytes('100 GB'));
        $this->assertSame(95 * 1024 ** 3, XmplusRenewalEligibility::parseDataSizeToBytes('95 GB'));
    }

    public function test_active_depleted_traffic_is_not_renewal_applied(): void
    {
        $row = [
            'status' => 'Active',
            'sid' => 19029,
            'traffic' => '1 GB',
            'used_traffic' => '1.02 GB',
        ];
        $this->assertFalse(XmplusServicePanelState::renewalAppliedOnPanel($row));
    }

    public function test_active_with_traffic_left_is_renewal_applied(): void
    {
        $row = [
            'status' => 'Active',
            'sid' => 1,
            'traffic' => '100 GB',
            'used_traffic' => '10 GB',
        ];
        $this->assertTrue(XmplusServicePanelState::renewalAppliedOnPanel($row));
    }
}
