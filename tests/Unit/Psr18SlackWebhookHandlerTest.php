<?php

declare(strict_types=1);

namespace Tests\Unit;

use MilesChou\Monoex\Handlers\Psr18SlackWebhookHandler;
use MilesChou\Psr\Http\Client\Testing\MockClient;
use MilesChou\Psr\Http\Message\RequestFactory;
use MilesChou\Psr\Http\Message\StreamFactory;
use Monolog\Formatter\JsonFormatter;
use Tests\TestCase;

class Psr18SlackWebhookHandlerTest extends TestCase
{
    /**
     * @var Psr18SlackWebhookHandler
     */
    private $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->target = new Psr18SlackWebhookHandler('whatever');
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

    /**
     * @return array
     */
    private function createRecord(): array
    {
        return [
            'level' => PHP_INT_MAX,
            'level_name' => 'max',
            'message' => 'hello',
            'datetime' => new \DateTime(),
        ];
    }
}
