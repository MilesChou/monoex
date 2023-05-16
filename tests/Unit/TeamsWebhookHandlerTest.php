<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use MilesChou\Monoex\Handlers\TeamsWebhookHandler;
use MilesChou\Psr\Http\Client\Testing\MockClient;
use MilesChou\Psr\Http\Message\RequestFactory;
use MilesChou\Psr\Http\Message\StreamFactory;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class TeamsWebhookHandlerTest extends TestCase
{
    private ?TeamsWebhookHandler $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = new TeamsWebhookHandler('whatever');
        $this->target->setFormatter(new JsonFormatter());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->target = null;
    }

    /**
     * @test
     */
    public function shouldBeOkay(): void
    {
        $mockClient = MockClient::createAlwaysReturnEmptyResponse();

        $this->target->setDriver($mockClient, new RequestFactory(), new StreamFactory());

        $this->target->handle($this->createRecord());

        $this->assertTrue($mockClient->hasRequests());
    }

    private function createRecord(): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable(),
            'test_channel',
            Level::Emergency,
            'hello',
        );
    }
}
