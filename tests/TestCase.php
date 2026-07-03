<?php

namespace Zarbinco\PersianSearch\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Zarbinco\PersianCore\PersianCoreServiceProvider;
use Zarbinco\PersianSearch\PersianSearchServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app)
    {
        return [
            PersianCoreServiceProvider::class,
            PersianSearchServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
