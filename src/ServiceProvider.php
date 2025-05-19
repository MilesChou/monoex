<?php

declare(strict_types=1);

namespace MilesChou\Monoex;

use Illuminate\Container\Container;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register custom logger driver
     *
     * Avoid use the default driver name:
     * stack, single, daily, slack, syslog, errorlog, monolog
     */
    public function boot(): void
    {
        /** @var LogManager $logManager */
        $logManager = $this->app->make(LogManager::class);
        $logManager->extend('psr18slack', function (Container $app, array $config) {
            return (new Par18SlackFactory($app))($config);
        });

        $logManager->extend('psr18teams', function (Container $app, array $config) {
            return (new Psr18TeamsFactory($app))($config);
        });
    }
}
