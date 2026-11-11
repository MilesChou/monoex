<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use MilesChou\Monoex\Handlers\GoogleChatWebhookHandler;
use MilesChou\Psr\Http\Client\Testing\MockClient;
use MilesChou\Psr\Http\Message\RequestFactory;
use MilesChou\Psr\Http\Message\StreamFactory;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GoogleChatWebhookHandlerTest extends TestCase
{
    private const WEBHOOK_URL = 'https://chat.googleapis.com/v1/spaces/AAAA/messages?key=SECRET_KEY&token=SECRET_TOKEN';

    private string $errorLogFile;

    private string|false $originalErrorLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->errorLogFile = (string)tempnam(sys_get_temp_dir(), 'monoex-test');
        $this->originalErrorLog = ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', (string)$this->originalErrorLog);
        @unlink($this->errorLogFile);

        parent::tearDown();
    }

    #[Test]
    public function shouldPostCardMessageToWebhook(): void
    {
        $mockClient = MockClient::createAlwaysReturnEmptyResponse();
        $target = $this->createHandler($mockClient);

        $target->handle($this->createRecord('hello', ['user_id' => 123], ['request_id' => 'abc']));

        $this->assertCount(1, $mockClient->requests);
        $request = $mockClient->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::WEBHOOK_URL, (string)$request->getUri());

        $payload = json_decode((string)$request->getBody(), true);
        $this->assertSame("\u{1F525} ERROR: hello", $payload['fallbackText']);

        $card = $payload['cardsV2'][0]['card'];
        $this->assertArrayNotHasKey('header', $card);
        $this->assertSame(
            [
                [
                    'widgets' => [
                        [
                            'decoratedText' => [
                                'text' => '<b>hello</b>',
                                'wrapText' => true,
                                'bottomLabel' => "\u{1F525} ERROR · test_channel · 2026-11-11 07:01:24",
                            ],
                        ],
                    ],
                ],
                [
                    'header' => 'Context',
                    'widgets' => [['textParagraph' => ['text' => '<b>user_id</b>: 123']]],
                ],
                [
                    'header' => 'Extra',
                    'widgets' => [['textParagraph' => ['text' => '<b>request_id</b>: abc']]],
                ],
            ],
            $card['sections'],
        );
    }

    #[Test]
    public function shouldShowFiveOtherLinesInSeparateSectionWithoutCollapse(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());
        $otherLines = implode("\n", array_map(static fn (int $i) => 'line' . $i, range(2, 6)));

        $payload = $target->getMessage($this->createRecord("first <line>\n" . $otherLines, ['a' => 1]));

        [$title, $other, $context] = $payload['cardsV2'][0]['card']['sections'];
        $this->assertSame('<b>first &lt;line&gt;</b>', $title['widgets'][0]['decoratedText']['text']);
        $this->assertCount(1, $title['widgets']);
        $this->assertSame(
            [['textParagraph' => ['text' => 'line2<br>line3<br>line4<br>line5<br>line6']]],
            $other['widgets'],
        );
        $this->assertArrayNotHasKey('collapsible', $other);
        $this->assertSame('Context', $context['header']);
    }

    #[Test]
    public function shouldCollapseOtherLinesAfterFiveLines(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());
        $lines = array_map(static fn (int $i) => 'line' . $i, range(1, 8));

        $payload = $target->getMessage($this->createRecord(implode("\n", $lines)));

        $other = $payload['cardsV2'][0]['card']['sections'][1];
        $this->assertTrue($other['collapsible']);
        $this->assertSame(1, $other['uncollapsibleWidgetsCount']);
        $this->assertSame(
            'line2<br>line3<br>line4<br>line5<br>line6',
            $other['widgets'][0]['textParagraph']['text'],
        );
        $this->assertSame('line7<br>line8', $other['widgets'][1]['textParagraph']['text']);
    }

    #[Test]
    #[DataProvider('provideSectionLimits')]
    public function shouldCollapseEachSectionOnlyWhenOverItsLimit(int $otherLines, int $fields, bool $collapsed): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());
        $context = [];
        $extra = [];
        for ($i = 1; $i <= $fields; $i++) {
            $context['context_' . $i] = $i;
            $extra['extra_' . $i] = $i;
        }
        $message = "title\n" . implode("\n", array_map(static fn (int $i) => 'line' . $i, range(1, $otherLines)));

        $payload = $target->getMessage($this->createRecord($message, $context, $extra));

        $sections = $payload['cardsV2'][0]['card']['sections'];
        $this->assertCount(4, $sections);
        foreach ([1 => null, 2 => 'Context', 3 => 'Extra'] as $index => $header) {
            $this->assertSame($header, $sections[$index]['header'] ?? null);
            $this->assertSame($collapsed, $sections[$index]['collapsible'] ?? false);
        }
        $this->assertSame($collapsed ? 1 : null, $sections[1]['uncollapsibleWidgetsCount'] ?? null);
        $this->assertSame($collapsed ? 3 : null, $sections[2]['uncollapsibleWidgetsCount'] ?? null);
        $this->assertSame($collapsed ? 3 : null, $sections[3]['uncollapsibleWidgetsCount'] ?? null);
    }

    public static function provideSectionLimits(): iterable
    {
        yield 'at limit: 5 other lines, 3 context, 3 extra' => [5, 3, false];
        yield 'over limit: 6 other lines, 4 context, 4 extra' => [6, 4, true];
    }

    #[Test]
    public function shouldCollapseByConfiguredLimits(): void
    {
        $target = new GoogleChatWebhookHandler(self::WEBHOOK_URL, Level::Debug, true, 2, 1);

        $payload = $target->getMessage($this->createRecord("title\nline1\nline2\nline3", ['a' => 1, 'b' => 2]));

        [, $other, $context] = $payload['cardsV2'][0]['card']['sections'];
        $this->assertSame('line1<br>line2', $other['widgets'][0]['textParagraph']['text']);
        $this->assertSame('line3', $other['widgets'][1]['textParagraph']['text']);
        $this->assertTrue($other['collapsible']);
        $this->assertTrue($context['collapsible']);
        $this->assertSame(1, $context['uncollapsibleWidgetsCount']);
    }

    #[Test]
    #[DataProvider('provideInvalidCollapseLimits')]
    public function shouldRejectCollapseLimitsBelowOne(int $messageLines, int $fields): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GoogleChatWebhookHandler(self::WEBHOOK_URL, Level::Debug, true, $messageLines, $fields);
    }

    public static function provideInvalidCollapseLimits(): iterable
    {
        yield 'message lines is 0' => [0, 3];
        yield 'fields is 0' => [5, 0];
    }

    #[Test]
    public function shouldShowTitleOnlyWhenMessageHasOneLine(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord(str_repeat('長', 600)));

        $widgets = $payload['cardsV2'][0]['card']['sections'][0]['widgets'];
        $this->assertCount(1, $widgets);
        $this->assertSame('<b>' . str_repeat('長', 600) . '</b>', $widgets[0]['decoratedText']['text']);
    }

    #[Test]
    public function shouldIgnoreLeadingAndTrailingLineBreaksAndSupportAllLineBreakStyles(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord("\n\ntitle\r\nline1\rline2\nline3\n\n"));

        $this->assertSame("\u{1F525} ERROR: title", $payload['fallbackText']);
        $sections = $payload['cardsV2'][0]['card']['sections'];
        $this->assertSame('<b>title</b>', $sections[0]['widgets'][0]['decoratedText']['text']);
        $this->assertSame('line1<br>line2<br>line3', $sections[1]['widgets'][0]['textParagraph']['text']);
    }

    #[Test]
    public function shouldUsePlaceholderWhenMessageIsEmpty(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord(''));

        $this->assertSame('<b>(empty message)</b>', $this->titleWidget($payload)['text']);
    }

    #[Test]
    public function shouldCollapseContextAndExtraSeparatelyAfterThreeFields(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord(
            'hello',
            ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4],
            ['e' => 5, 'f' => 6, 'g' => 7],
        ));

        [, $context, $extra] = $payload['cardsV2'][0]['card']['sections'];
        $this->assertSame('Context', $context['header']);
        $this->assertTrue($context['collapsible']);
        $this->assertSame(3, $context['uncollapsibleWidgetsCount']);
        $this->assertCount(4, $context['widgets']);

        $this->assertSame('Extra', $extra['header']);
        $this->assertArrayNotHasKey('collapsible', $extra);
        $this->assertCount(3, $extra['widgets']);
    }

    #[Test]
    public function shouldOmitEmptyFieldSections(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord('hello', ['a' => 1]));

        $sections = $payload['cardsV2'][0]['card']['sections'];
        $this->assertCount(2, $sections);
        $this->assertSame('Context', $sections[1]['header']);
    }

    #[Test]
    #[DataProvider('provideLevelLabel')]
    public function shouldPrefixLevelNameWithIcon(Level $level, string $expected): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage(new LogRecord(new DateTimeImmutable(), 'test_channel', $level, 'hello'));

        $this->assertStringStartsWith($expected . ' · ', $this->titleWidget($payload)['bottomLabel']);
    }

    public static function provideLevelLabel(): iterable
    {
        yield 'emergency' => [Level::Emergency, "\u{1F4A5} EMERGENCY"];
        yield 'alert' => [Level::Alert, "\u{26D4} ALERT"];
        yield 'critical' => [Level::Critical, "\u{1F6A8} CRITICAL"];
        yield 'error' => [Level::Error, "\u{1F525} ERROR"];
        yield 'warning' => [Level::Warning, "\u{26A0}\u{FE0F} WARNING"];
        yield 'notice' => [Level::Notice, "\u{1F514} NOTICE"];
        yield 'info' => [Level::Info, "\u{2139}\u{FE0F} INFO"];
        yield 'debug' => [Level::Debug, "\u{1F41B} DEBUG"];
    }

    #[Test]
    #[DataProvider('provideFieldValue')]
    public function shouldNormalizeFieldValue(mixed $value, string $expected): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord('hello', ['field' => $value]));

        $this->assertSame('<b>field</b>: ' . $expected, $this->firstFieldText($payload));
    }

    public static function provideFieldValue(): iterable
    {
        yield 'int' => [123, '123'];
        yield 'float' => [-1.5, '-1.5'];
        yield 'bool true' => [true, 'true'];
        yield 'bool false' => [false, 'false'];
        yield 'null' => [null, 'null'];
        yield 'array' => [['a' => '中文', 'b' => [1, 2]], '{&quot;a&quot;:&quot;中文&quot;,&quot;b&quot;:[1,2]}'];
        yield 'html is escaped' => ['<b>x</b>', '&lt;b&gt;x&lt;/b&gt;'];
        yield 'exception' => [new RuntimeException('boom'), 'RuntimeException: boom at ' . __FILE__ . ':' . __LINE__];
    }

    #[Test]
    public function shouldTruncateFieldValueTo255Characters(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord('hello', ['field' => str_repeat('中', 300)]));

        $this->assertSame(
            '<b>field</b>: ' . str_repeat('中', 255) . '…(truncated)',
            $this->firstFieldText($payload),
        );
    }

    #[Test]
    public function shouldKeepShortFieldValueUntouched(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());

        $payload = $target->getMessage($this->createRecord('hello', ['field' => str_repeat('a', 255)]));

        $this->assertSame('<b>field</b>: ' . str_repeat('a', 255), $this->firstFieldText($payload));
    }

    #[Test]
    public function shouldTruncateMessageToFitMaxMessageBytes(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());
        $message = str_repeat('錯誤訊息', 5000);

        $payload = $target->getMessage($this->createRecord($message, ['field' => 'value']));

        $this->assertLessThanOrEqual(GoogleChatWebhookHandler::MAX_MESSAGE_BYTES, $this->encodedSize($payload));
        $text = $this->titleWidget($payload)['text'];
        $this->assertStringEndsWith('…(truncated)</b>', $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertGreaterThan(GoogleChatWebhookHandler::MAX_MESSAGE_BYTES - 2000, $this->encodedSize($payload));
    }

    #[Test]
    public function shouldOmitFieldsWhenFieldsExceedMaxMessageBytes(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());
        $context = [];
        for ($i = 0; $i < 200; $i++) {
            $context['field' . $i] = str_repeat('x', 255);
        }

        $payload = $target->getMessage($this->createRecord('hello', $context));

        $this->assertLessThanOrEqual(GoogleChatWebhookHandler::MAX_MESSAGE_BYTES, $this->encodedSize($payload));
        $widgets = $payload['cardsV2'][0]['card']['sections'][1]['widgets'];
        $omittedNotice = $widgets[count($widgets) - 1]['textParagraph']['text'];
        $this->assertMatchesRegularExpression('/^<i>\d+ field\(s\) omitted/', $omittedNotice);
    }

    #[Test]
    public function shouldKeepCardWithin100WidgetsWhenFieldsAreMany(): void
    {
        $target = $this->createHandler(MockClient::createAlwaysReturnEmptyResponse());
        $context = [];
        for ($i = 0; $i < 150; $i++) {
            $context['f' . $i] = $i;
        }

        $payload = $target->getMessage($this->createRecord(str_repeat("line\n", 20), $context));

        $sections = $payload['cardsV2'][0]['card']['sections'];
        $widgetCount = array_sum(array_map(static fn (array $section) => count($section['widgets']), $sections));
        $this->assertSame(100, $widgetCount);
        $fields = $sections[count($sections) - 1]['widgets'];
        $omittedNotice = $fields[count($fields) - 1]['textParagraph']['text'];
        $this->assertStringStartsWith('<i>54 field(s) omitted', $omittedNotice);
    }

    #[Test]
    public function shouldWriteMaskedErrorLogWhenResponseIsNotSuccessful(): void
    {
        $mockClient = new MockClient();
        $mockClient->appendEmptyResponse(429);
        $target = $this->createHandler($mockClient);

        $target->handle($this->createRecord('hello'));

        $log = (string)file_get_contents($this->errorLogFile);
        $this->assertStringContainsString('[monoex] Google Chat webhook responded HTTP 429 (level=ERROR)', $log);
    }

    #[Test]
    public function shouldThrowWhenResponseIsNotSuccessfulAndNotSilent(): void
    {
        $mockClient = new MockClient();
        $mockClient->appendEmptyResponse(500);
        $target = $this->createHandler($mockClient);
        $target->setSilent(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Chat webhook responded HTTP 500 (level=ERROR)');

        $target->handle($this->createRecord('hello'));
    }

    #[Test]
    public function shouldMaskSecretsInErrorLogWhenClientThrows(): void
    {
        $mockClient = new MockClient(new RuntimeException('Could not resolve host for "' . self::WEBHOOK_URL . '"'));
        $target = $this->createHandler($mockClient);

        $target->handle($this->createRecord('hello'));

        $log = (string)file_get_contents($this->errorLogFile);
        $this->assertStringContainsString('spaces/AAAA/messages?key=***&token=***', $log);
        $this->assertStringNotContainsString('SECRET_KEY', $log);
        $this->assertStringNotContainsString('SECRET_TOKEN', $log);
    }

    #[Test]
    public function shouldThrowMaskedExceptionWithoutPreviousWhenClientThrowsAndNotSilent(): void
    {
        $mockClient = new MockClient(new RuntimeException('Could not resolve host for "' . self::WEBHOOK_URL . '"'));
        $target = $this->createHandler($mockClient);
        $target->setSilent(false);

        try {
            $target->handle($this->createRecord('hello'));
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('key=***&token=***', $e->getMessage());
            $this->assertStringNotContainsString('SECRET_KEY', $e->getMessage());
            $this->assertStringNotContainsString('SECRET_TOKEN', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    private function createHandler(MockClient $client): GoogleChatWebhookHandler
    {
        $handler = new GoogleChatWebhookHandler(self::WEBHOOK_URL, Level::Debug);
        $handler->setDriver($client, new RequestFactory(), new StreamFactory());

        return $handler;
    }

    private function createRecord(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            new DateTimeImmutable('2026-11-11 07:01:24'),
            'test_channel',
            Level::Error,
            $message,
            $context,
            $extra,
        );
    }

    private function titleWidget(array $payload): array
    {
        return $payload['cardsV2'][0]['card']['sections'][0]['widgets'][0]['decoratedText'];
    }

    private function firstFieldText(array $payload): string
    {
        return $payload['cardsV2'][0]['card']['sections'][1]['widgets'][0]['textParagraph']['text'];
    }

    private function encodedSize(array $payload): int
    {
        return strlen((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
