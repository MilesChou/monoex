<?php

declare(strict_types=1);

namespace MilesChou\Monoex;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Copy from Laravel Log for workaround
 */
trait LogManagerTrait
{
    /**
     * @see https://github.com/laravel/framework/blob/v8.73.2/src/Illuminate/Log/LogManager.php#L449-L452
     */
    protected function getFallbackChannelName()
    {
        return $this->app->bound('env') ? $this->app->environment() : 'production';
    }

    /**
     * @see https://github.com/laravel/framework/blob/v8.73.2/src/Illuminate/Log/LogManager.php#L417-L430
     */
    protected function prepareHandler(HandlerInterface $handler, array $config = [])
    {
        $isHandlerFormattable = false;

        if (Monolog::API === 1) {
            $isHandlerFormattable = true;
        } elseif (Monolog::API === 2 && $handler instanceof FormattableHandlerInterface) {
            $isHandlerFormattable = true;
        }

        if ($isHandlerFormattable && ! isset($config['formatter'])) {
            $handler->setFormatter($this->formatter());
        } elseif ($isHandlerFormattable && $config['formatter'] !== 'default') {
            $handler->setFormatter($this->app->make($config['formatter'], $config['formatter_with'] ?? []));
        }

        return $handler;
    }

    /**
     * @see https://github.com/laravel/framework/blob/v7.6.2/src/Illuminate/Log/LogManager.php#L414
     */
    protected function formatter()
    {
        return tap(new LineFormatter(null, 'Y-m-d H:i:s', true, true), function ($formatter) {
            $formatter->includeStacktraces();
        });
    }
}
