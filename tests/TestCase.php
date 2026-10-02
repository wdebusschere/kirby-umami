<?php

namespace Akibeo\Umami\Tests;

use Kirby\Cms\App;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        // Keep Kirby from installing its Whoops error handlers per App,
        // which PHPUnit would otherwise flag on every test.
        App::$enableWhoops = false;

        $this->tmp = sys_get_temp_dir() . '/kirby-umami-' . uniqid();
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        App::destroy();
        rmdir($this->tmp);
    }

    protected function kirby(array $options = []): App
    {
        return new App([
            'roots' => ['index' => $this->tmp],
            'options' => $options,
        ]);
    }
}
