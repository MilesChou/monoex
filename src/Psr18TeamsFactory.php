<?php

declare(strict_types=1);

namespace MilesChou\Monoex;

use Illuminate\Container\Container;
use Illuminate\Log\ParsesLogConfiguration;
use MilesChou\Monoex\Handlers\TeamsWebhookHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as Monolog;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Psr18TeamsFactory
{
    use ParsesLogConfiguration;
    use LogManagerTrait;

    public function __construct(private readonly Container $app)
    {
    }

    public function __invoke(array $config): Monolog
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler($this->createHandler($config), $config),
        ]);
    }

    private function createHandler(array $config): HandlerInterface
    {
        $handler = new TeamsWebhookHandler(
            $config['url'],
            $this->level($config),
            $config['bubble'] ?? true,
        );

        $handler->setDriver(
            $this->app->make(ClientInterface::class),
            $this->app->make(RequestFactoryInterface::class),
            $this->app->make(StreamFactoryInterface::class)
        );

        return $handler;
    }
}
