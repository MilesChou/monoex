<?php

declare(strict_types=1);

namespace MilesChou\Monoex;

use Illuminate\Container\Container;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register()
    {
        $this->app->afterResolving(LogManager::class, function (LogManager $logManager) {
            $this->registerLoggerDriver($logManager);
        });
    }

    /**
     * Register custom logger driver
     *
     * Avoid use the default driver name:
     * stack, single, daily, slack, syslog, errorlog, monolog
     *
     * @param LogManager $logManager
     */
    private function registerLoggerDriver(LogManager $logManager): void
    {
        $logManager->extend('psr18slack', function (Container $app, array $config) {
            return (new Par18SlackFactory($app))($config);
        });
    }
}
