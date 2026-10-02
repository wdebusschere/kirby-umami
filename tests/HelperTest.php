<?php

namespace Akibeo\Umami\Tests;

use Akibeo\Umami\Umami;

class HelperTest extends TestCase
{
    public function testUmamiHelperIsRegistered(): void
    {
        $this->assertTrue(function_exists('umami'));
    }

    public function testUmamiHelperReturnsTheSharedInstance(): void
    {
        $this->kirby();

        $this->assertInstanceOf(Umami::class, umami());
        $this->assertSame(umami(), umami());
        $this->assertSame(umami(), Umami::instance());
    }
}
