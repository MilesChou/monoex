<?php

declare(strict_types=1);

namespace MilesChou\Monoex;

use Illuminate\Container\Container;
use Illuminate\Log\ParsesLogConfiguration;
use MilesChou\Monoex\Handlers\Psr18SlackWebhookHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as Monolog;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Par18SlackFactory
{
    use ParsesLogConfiguration;
    use LogManagerTrait;

    /**
     * @var Container
     */
    private $app;

    /**
     * @param Container $app
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    public function __invoke(array $config)
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler($this->createHandler($config), $config),
        ]);
    }

    /**
     * @see https://github.com/laravel/framework/blob/v7.6.2/src/Illuminate/Log/LogManager.php#L291
     */
    private function createHandler(array $config): HandlerInterface
    {
        $handler = new Psr18SlackWebhookHandler(
            $config['url'],
            $config['channel'] ?? null,
            $config['username'] ?? 'Laravel',
            $config['attachment'] ?? true,
            $config['emoji'] ?? ':boom:',
            $config['short'] ?? false,
            $config['context'] ?? true,
            $this->level($config),
            $config['bubble'] ?? true,
            $config['exclude_fields'] ?? []
        );

        $handler->setDriver(
            $this->app->make(ClientInterface::class),
            $this->app->make(RequestFactoryInterface::class),
            $this->app->make(StreamFactoryInterface::class)
        );

        return $handler;
    }
}
