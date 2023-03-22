<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Illuminate\Log\LogServiceProvider;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use MilesChou\Monoex\Handlers\Psr18SlackWebhookHandler;
use MilesChou\Monoex\ServiceProvider;
use MilesChou\Psr\Http\Client\Testing\MockClient;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    private Application $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Application();
        $this->container->instance('config', new Repository());
        $this->container->instance(RequestFactoryInterface::class, new RequestFactory());
        $this->container->instance(StreamFactoryInterface::class, new StreamFactory());

        (new LogServiceProvider($this->container))->register();
        (new EventServiceProvider($this->container))->register();

        $this->container->alias('log', LogManager::class);
    }

    /**
     * @test
     */
    public function shouldRegisterNewDriverByServiceProvider(): void
    {
        $this->container->instance(ClientInterface::class, MockClient::createAlwaysReturnEmptyResponse());

        /** @var Repository $config */
        $config = $this->container->make('config');
        $config->set('logging.channels.some', [
            'driver' => 'psr18slack',
            'url' => 'somewhere',
        ]);

        $target = new ServiceProvider($this->container);
        $target->register();

        /** @var LogManager $actual */
        $actual = $this->container->make(LogManager::class);

        /** @var Logger $driver */
        $driver = $actual->driver('some');

        $this->assertInstanceOf(Psr18SlackWebhookHandler::class, $driver->getLogger()->popHandler());
    }
}
