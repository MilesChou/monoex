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

    /**
     * @test
     * @dataProvider provideNumericValue
     */
    public function shouldBeOkayWhenValueIsNumeric(mixed $value): void
    {
        $actual = $this->target->formFactsValue('test', $value);
        $this->assertSame(['name' => 'test', 'value' => $value], $actual);
    }

    public static function provideNumericValue(): iterable
    {
        yield 'int 整數' => [0];
        yield 'int 正整數' => [123456];
        yield 'int 負整數' => [-123456];
        yield 'float 正數' => [123456.654321];
        yield 'float 負數' => [-123456.654321];
        yield 'string 整數' => ['0'];
        yield 'string 正整數' => ['123456'];
        yield 'string 負整數' => ['-123456'];
        yield 'string 正數' => ['123456.654321'];
        yield 'string 負數' => ['-123456.654321'];
    }

    /**
     * @test
     */
    public function shouldBeOkayWhenValueIsAString(): void
    {
        $actual = $this->target->formFactsValue('test', 'hello');
        $this->assertSame(['name' => 'test', 'value' => 'hello'], $actual);
    }

    /**
     * @test
     * @dataProvider provideArrayValue
     */
    public function shouldBeOkayWhenValueIsAArray(array $value): void
    {
        $value = ['whatever' => 'test', 123];
        $actual = $this->target->formFactsValue('test', $value);
        $this->assertSame(['name' => 'test', 'value' => json_encode($value)], $actual);
    }

    public function provideArrayValue(): iterable
    {
        yield 'array' => [[1,2,3,'hi']];
        yield 'key-value pair array' => [['whatever' => 'test']];
        yield 'mixed' => [['whatever' => 'test', 123, 'hi']];
    }

    /**
     * @test
     */
    public function shouldBeOkayWhenValueIsAnObject(): void
    {
        $value = (object) ['whatever' => 'test'];
        $actual = $this->target->formFactsValue('test', $value);
        $this->assertSame(['name' => 'test', 'value' => json_encode($value)], $actual);
    }

    /**
     * @test
     */
    public function shouldShowMessageWhenValueIsTrue(): void
    {
        $actual = $this->target->formFactsValue('test', true);
        $this->assertSame(['name' => 'test', 'value' => 'Invalid value: value must be a string or numeric, but get boolean'], $actual);
    }

    /**
     * @test
     */
    public function shouldShowMessageWhenValueIsFalse(): void
    {
        $actual = $this->target->formFactsValue('test', false);
        $this->assertSame(['name' => 'test', 'value' => 'Invalid value: value must be a string or numeric, but get boolean'], $actual);
    }

    /**
     * @test
     */
    public function shouldShowMessageWhenValueIsNull(): void
    {
        $actual = $this->target->formFactsValue('test', null);
        $this->assertSame(['name' => 'test', 'value' => 'Invalid value: value must be a string or numeric, but get NULL'], $actual);
    }
}
