<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Log\Logger;
use Laminas\Diactoros\RequestFactory;
use Laminas\Diactoros\StreamFactory;
use MilesChou\Monoex\Handlers\GoogleChatWebhookHandler;
use MilesChou\Monoex\Handlers\Psr18SlackWebhookHandler;
use MilesChou\Monoex\Handlers\TeamsWebhookHandler;
use MilesChou\Monoex\ServiceProvider;
use MilesChou\Psr\Http\Client\Testing\MockClient;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
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
        $target->boot();

        /** @var LogManager $actual */
        $actual = $this->container->make(LogManager::class);

        /** @var Logger $driver */
        $driver = $actual->driver('some');

        $this->assertInstanceOf(Psr18SlackWebhookHandler::class, $driver->getLogger()->popHandler());
    }


    #[Test]
    public function testRegisterTeamsDriverByServiceProvider(): void
    {
        $this->container->instance(ClientInterface::class, MockClient::createAlwaysReturnEmptyResponse());

        /** @var Repository $config */
        $config = $this->container->make('config');
        $config->set('logging.channels.some', [
            'driver' => 'psr18teams',
            'url' => 'somewhere',
        ]);

        $target = new ServiceProvider($this->container);
        $target->boot();

        /** @var LogManager $actual */
        $actual = $this->container->make(LogManager::class);

        /** @var Logger $driver */
        $driver = $actual->driver('some');

        $this->assertInstanceOf(TeamsWebhookHandler::class, $driver->getLogger()->popHandler());
    }

    #[Test]
    public function shouldRegisterGoogleChatDriverByServiceProvider(): void
    {
        $this->container->instance(ClientInterface::class, MockClient::createAlwaysReturnEmptyResponse());

        /** @var Repository $config */
        $config = $this->container->make('config');
        $config->set('logging.channels.some', [
            'driver' => 'psr18googlechat',
            'url' => 'somewhere',
        ]);

        $target = new ServiceProvider($this->container);
        $target->boot();

        /** @var LogManager $actual */
        $actual = $this->container->make(LogManager::class);

        /** @var Logger $driver */
        $driver = $actual->driver('some');

        $this->assertInstanceOf(GoogleChatWebhookHandler::class, $driver->getLogger()->popHandler());
    }

    #[Test]
    public function shouldPassCollapseLimitsToGoogleChatHandler(): void
    {
        $this->container->instance(ClientInterface::class, MockClient::createAlwaysReturnEmptyResponse());

        /** @var Repository $config */
        $config = $this->container->make('config');
        $config->set('logging.channels.some', [
            'driver' => 'psr18googlechat',
            'url' => 'somewhere',
            'collapse_message_lines' => 1,
            'collapse_fields' => 1,
        ]);

        $target = new ServiceProvider($this->container);
        $target->boot();

        /** @var LogManager $actual */
        $actual = $this->container->make(LogManager::class);

        /** @var Logger $driver */
        $driver = $actual->driver('some');

        /** @var GoogleChatWebhookHandler $handler */
        $handler = $driver->getLogger()->popHandler();
        $payload = $handler->getMessage(new LogRecord(
            new DateTimeImmutable(),
            'some',
            Level::Error,
            "title\nline1\nline2",
            ['a' => 1, 'b' => 2],
        ));

        [, $other, $context] = $payload['cardsV2'][0]['card']['sections'];
        $this->assertSame(1, $other['uncollapsibleWidgetsCount']);
        $this->assertSame('line1', $other['widgets'][0]['textParagraph']['text']);
        $this->assertSame(1, $context['uncollapsibleWidgetsCount']);
    }
}
