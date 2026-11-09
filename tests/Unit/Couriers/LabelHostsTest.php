<?php

namespace Tests\Unit\Couriers;

use App\Couriers\Support\LabelHosts;
use PHPUnit\Framework\TestCase;

class LabelHostsTest extends TestCase
{
    public function test_quadx_path_style_s3_bucket_is_allowed(): void
    {
        $this->assertTrue(LabelHosts::allows('quadx', 'https://s3.ap-southeast-1.amazonaws.com/awbpod.staging.quadx.xyz/awb/QX123.pdf'));
        $this->assertTrue(LabelHosts::allows('quadx', 'https://s3.ap-southeast-1.amazonaws.com/awbpod.quadx.xyz/awb/QX123.pdf'));
        $this->assertTrue(LabelHosts::allows('quadx', 'https://quadx-awb.s3.ap-southeast-1.amazonaws.com/x.pdf'));
    }

    public function test_other_buckets_on_the_shared_s3_host_are_refused(): void
    {
        $this->assertFalse(LabelHosts::allows('quadx', 'https://s3.ap-southeast-1.amazonaws.com/evil-bucket/x.pdf'));
        $this->assertFalse(LabelHosts::allows('quadx', 'https://s3.ap-southeast-1.amazonaws.com/quadx.xyz.evil.com/x.pdf'));
        $this->assertFalse(LabelHosts::allows('quadx', 'https://s3.ap-southeast-1.amazonaws.com/'));
        $this->assertFalse(LabelHosts::allows('quadx', 'http://s3.ap-southeast-1.amazonaws.com/awbpod.quadx.xyz/x.pdf'));
        $this->assertFalse(LabelHosts::allows('spx', 'https://s3.ap-southeast-1.amazonaws.com/awbpod.quadx.xyz/x.pdf'));
        $this->assertFalse(LabelHosts::allows('quadx', 'https://s3.ap-southeast-1.amazonaws.com.evil.com/awbpod.quadx.xyz/x.pdf'));
    }
}
