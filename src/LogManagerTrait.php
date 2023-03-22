<?php

declare(strict_types=1);

namespace MilesChou\Monoex;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Copy from Laravel Log for workaround
 */
trait LogManagerTrait
{
    /**
     * @see https://github.com/laravel/framework/blob/v10.4.1/src/Illuminate/Log/LogManager.php#L64
     *
     * @var string
     */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * @see https://github.com/laravel/framework/blob/v10.4.1/src/Illuminate/Log/LogManager.php#L523
     */
    protected function getFallbackChannelName()
    {
        return $this->app->bound('env') ? $this->app->environment() : 'production';
    }

    /**
     * @see https://github.com/laravel/framework/blob/v10.4.1/src/Illuminate/Log/LogManager.php#L442
     */
    protected function prepareHandler(HandlerInterface $handler, array $config = []): HandlerInterface
    {
        if (isset($config['action_level'])) {
            $handler = new FingersCrossedHandler(
                $handler,
                $this->actionLevel($config),
                0,
                true,
                $config['stop_buffering'] ?? true
            );
        }

        if (! $handler instanceof FormattableHandlerInterface) {
            return $handler;
        }

        if (! isset($config['formatter'])) {
            $handler->setFormatter($this->formatter());
        } elseif ($config['formatter'] !== 'default') {
            $handler->setFormatter($this->app->make($config['formatter'], $config['formatter_with'] ?? []));
        }

        return $handler;
    }

    /**
     * @see https://github.com/laravel/framework/blob/v10.4.1/src/Illuminate/Log/LogManager.php#L472
     */
    protected function formatter()
    {
        return tap(new LineFormatter(null, $this->dateFormat, true, true), function ($formatter) {
            $formatter->includeStacktraces();
        });
    }
}
