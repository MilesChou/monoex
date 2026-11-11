<?php

declare(strict_types=1);

namespace MilesChou\Monoex\Handlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use Throwable;

/**
 * Send log records to a Google Chat incoming webhook as a cardsV2 message.
 *
 * Card layout, one section per item:
 *   1. The first line of the log message in bold, with the level, channel and date below it in small text.
 *      The card header is not used, because its title cannot be bold and drops text in angle brackets.
 *   2. The other lines of the log message.
 *   3. Context fields, one "key: value" line per field.
 *   4. Extra fields, in the same format as context.
 * Sections 2 to 4 are omitted when empty, and collapse their content after a limit.
 *
 * @see https://developers.google.com/workspace/chat/quickstart/webhooks
 */
class GoogleChatWebhookHandler extends AbstractProcessingHandler
{
    /**
     * Maximum message size (including any text or cards) defined by Google Chat.
     *
     * @see https://developers.google.com/workspace/chat/api/guides/v1/messages/create
     */
    public const MAX_MESSAGE_BYTES = 32000;

    /**
     * Maximum characters of each context / extra field value, and of the notification preview.
     */
    public const MAX_FIELD_LENGTH = 255;

    /**
     * Google Chat skips the sections after a card exceeds 100 widgets. The card uses at most
     * 4 widgets besides fields: message title, other message lines, collapsed message lines
     * and omitted notice.
     */
    private const MAX_FIELD_WIDGETS = 100 - 4;

    /**
     * Other message lines after this count are collapsed behind the "Show more" control.
     */
    private const COLLAPSE_MESSAGE_LINES = 5;

    /**
     * Context and extra fields after this count are collapsed, counted per section.
     */
    private const COLLAPSE_FIELDS = 3;

    private const EMPTY_MESSAGE = '(empty message)';

    private const TRUNCATED_SUFFIX = '…(truncated)';

    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Icons shown before the level name. Each level gets an icon with a distinct shape,
     * so the level can be told apart without relying on colour.
     */
    private const LEVEL_ICONS = [
        'EMERGENCY' => "\u{1F4A5}", // collision
        'ALERT' => "\u{26D4}", // no entry
        'CRITICAL' => "\u{1F6A8}", // police car light
        'ERROR' => "\u{1F525}", // fire
        'WARNING' => "\u{26A0}\u{FE0F}", // warning sign
        'NOTICE' => "\u{1F514}", // bell
        'INFO' => "\u{2139}\u{FE0F}", // information
        'DEBUG' => "\u{1F41B}", // bug
    ];

    private ClientInterface $httpClient;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    /**
     * Don't throw exception when sending request failed
     */
    private bool $silent = true;

    public function __construct(
        private readonly string $webhookUrl,
        int|string|Level $level = Level::Debug,
        bool $bubble = true,
        private readonly ?string $threadKey = null,
    ) {
        parent::__construct($level, $bubble);
    }

    public function setDriver(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
    ): static {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;

        return $this;
    }

    public function setSilent(bool $silent): void
    {
        $this->silent = $silent;
    }

    /**
     * Build the Google Chat message payload. The encoded payload never exceeds MAX_MESSAGE_BYTES.
     */
    public function getMessage(LogRecord $record): array
    {
        $fields = $this->collectFields($record);

        // Keep the widget count within the card limit
        $omitted = max(count($fields) - self::MAX_FIELD_WIDGETS, 0);
        $fields = array_slice($fields, 0, self::MAX_FIELD_WIDGETS);

        // Drop fields from the end until the payload fits with at least the truncated suffix as the message
        while (
            $fields !== []
            && $this->payloadSize($this->buildPayload($record, self::TRUNCATED_SUFFIX, $fields, $omitted))
                > self::MAX_MESSAGE_BYTES
        ) {
            array_pop($fields);
            $omitted++;
        }

        return $this->buildPayload($record, $this->fitMessage($record, $fields, $omitted), $fields, $omitted);
    }

    /**
     * Mask `key` and `token` query parameters of the webhook URL.
     */
    public function maskSecrets(string $text): string
    {
        return (string)preg_replace('/([?&](?:key|token)=)[^&\s"\'<>]+/i', '$1***', $text);
    }

    protected function write(LogRecord $record): void
    {
        $postString = (string)json_encode($this->getMessage($record), self::JSON_FLAGS);

        $request = $this->requestFactory->createRequest('POST', $this->buildRequestUrl())
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withBody($this->streamFactory->createStream($postString));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (Throwable $e) {
            // The original exception message may contain the webhook URL with key and token,
            // so it is never rethrown or chained as previous.
            $this->fail(sprintf(
                'Google Chat webhook request failed: %s: %s (level=%s)',
                $e::class,
                $this->maskSecrets($e->getMessage()),
                $record->level->getName(),
            ));

            return;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->fail(sprintf(
                'Google Chat webhook responded HTTP %d (level=%s)',
                $status,
                $record->level->getName(),
            ));
        }
    }

    private function fail(string $message): void
    {
        if ($this->silent) {
            error_log('[monoex] ' . $message);

            return;
        }

        throw new RuntimeException($message);
    }

    private function buildRequestUrl(): string
    {
        if ($this->threadKey === null) {
            return $this->webhookUrl;
        }

        $separator = str_contains($this->webhookUrl, '?') ? '&' : '?';

        return $this->webhookUrl . $separator . 'messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD';
    }

    /**
     * Context and extra as [section header, key, value] in display order. Values are normalized to
     * strings and truncated to MAX_FIELD_LENGTH characters.
     *
     * @return array<array{string, string, string}>
     */
    private function collectFields(LogRecord $record): array
    {
        // A JSON formatter puts the normalized context and extra into the formatted field
        $formatted = is_string($record->formatted) ? json_decode($record->formatted, true) : null;

        $groups = [
            'Context' => $formatted['context'] ?? $record->context,
            'Extra' => $formatted['extra'] ?? $record->extra,
        ];

        $fields = [];
        foreach ($groups as $header => $items) {
            foreach ((array)$items as $key => $value) {
                $fields[] = [
                    $header,
                    (string)$key,
                    $this->truncate($this->normalizeValue($value), self::MAX_FIELD_LENGTH),
                ];
            }
        }

        return $fields;
    }

    /**
     * @param array<array{string, string, string}> $fields
     */
    private function buildPayload(LogRecord $record, string $message, array $fields, int $omitted): array
    {
        $payload = [
            // Shown in notifications instead of the card, without adding a text line above the card
            'fallbackText' => $this->buildPreviewText($record),
            'cardsV2' => [
                [
                    'cardId' => 'monoex-log',
                    'card' => [
                        'sections' => [
                            ...$this->buildMessageSections($record, $message),
                            ...$this->buildFieldSections($fields, $omitted),
                        ],
                    ],
                ],
            ],
        ];

        if ($this->threadKey !== null) {
            $payload['thread'] = ['threadKey' => $this->threadKey];
        }

        return $payload;
    }

    /**
     * The title section, and the section of the other lines when the message has more than one line.
     */
    private function buildMessageSections(LogRecord $record, string $message): array
    {
        [$title, $otherLines] = $this->splitMessage($message);

        $sections = [
            [
                'widgets' => [
                    [
                        'decoratedText' => [
                            'text' => '<b>' . $this->escape($title) . '</b>',
                            'wrapText' => true,
                            'bottomLabel' => implode(' · ', [
                                $this->levelLabel($record->level),
                                $record->channel,
                                $record->datetime->format('Y-m-d H:i:s'),
                            ]),
                        ],
                    ],
                ],
            ],
        ];

        if ($otherLines === []) {
            return $sections;
        }

        $visible = array_slice($otherLines, 0, self::COLLAPSE_MESSAGE_LINES);
        $collapsed = array_slice($otherLines, self::COLLAPSE_MESSAGE_LINES);

        $sections[] = $this->buildSection(
            null,
            array_map(
                fn (array $lines) => ['textParagraph' => ['text' => $this->toHtmlLines($lines)]],
                array_filter([$visible, $collapsed]),
            ),
            1,
        );

        return $sections;
    }

    /**
     * One section per field header. The omitted notice is appended to the last section.
     *
     * @param array<array{string, string, string}> $fields
     */
    private function buildFieldSections(array $fields, int $omitted): array
    {
        $groups = [];
        foreach ($fields as [$header, $key, $value]) {
            $groups[$header][] = [
                'textParagraph' => [
                    'text' => sprintf('<b>%s</b>: %s', $this->escape($key), $this->escape($value)),
                ],
            ];
        }

        $sections = [];
        foreach ($groups as $header => $widgets) {
            $sections[] = $this->buildSection($header, $widgets, self::COLLAPSE_FIELDS);
        }

        if ($omitted > 0) {
            $notice = [
                'textParagraph' => [
                    'text' => sprintf('<i>%d field(s) omitted: card size limit exceeded</i>', $omitted),
                ],
            ];
            if ($sections === []) {
                $sections[] = ['widgets' => [$notice]];
            } else {
                $sections[count($sections) - 1]['widgets'][] = $notice;
            }
        }

        return $sections;
    }

    /**
     * A section that shows the first $visibleWidgets widgets and collapses the rest.
     */
    private function buildSection(?string $header, array $widgets, int $visibleWidgets): array
    {
        $section = $header === null ? [] : ['header' => $header];
        $section['widgets'] = array_values($widgets);

        if (count($widgets) > $visibleWidgets) {
            $section['collapsible'] = true;
            $section['uncollapsibleWidgetsCount'] = $visibleWidgets;
        }

        return $section;
    }

    /**
     * Plain text shown in notifications: the level and the first line of the message.
     */
    private function buildPreviewText(LogRecord $record): string
    {
        [$title] = $this->splitMessage($record->message);

        return sprintf(
            '%s: %s',
            $this->levelLabel($record->level),
            $this->truncate($title, self::MAX_FIELD_LENGTH),
        );
    }

    /**
     * Split the message into the title (the first line) and the other lines.
     *
     * @return array{string, array<string>}
     */
    private function splitMessage(string $message): array
    {
        // Leading and trailing line breaks would only add an empty title or empty lines
        $message = trim($message, "\r\n");

        $lines = preg_split('/\r\n|\r|\n/', $message) ?: [$message];
        $title = array_shift($lines);

        return [$title === '' ? self::EMPTY_MESSAGE : $title, $lines];
    }

    private function levelLabel(Level $level): string
    {
        $name = $level->getName();

        return isset(self::LEVEL_ICONS[$name]) ? self::LEVEL_ICONS[$name] . ' ' . $name : $name;
    }

    /**
     * Truncate the message so the whole payload fits MAX_MESSAGE_BYTES.
     *
     * @param array<array{string, string, string}> $fields
     */
    private function fitMessage(LogRecord $record, array $fields, int $omitted): string
    {
        $message = $record->message;
        if ($this->payloadSize($this->buildPayload($record, $message, $fields, $omitted)) <= self::MAX_MESSAGE_BYTES) {
            return $message;
        }

        $length = strlen($message);
        do {
            $candidate = $this->cutBytes($message, $length) . self::TRUNCATED_SUFFIX;
            $overflow = $this->payloadSize($this->buildPayload($record, $candidate, $fields, $omitted))
                - self::MAX_MESSAGE_BYTES;
            if ($overflow <= 0) {
                return $candidate;
            }
            $length -= max($overflow, 1);
        } while ($length > 0);

        return self::TRUNCATED_SUFFIX;
    }

    private function payloadSize(array $payload): int
    {
        return strlen((string)json_encode($payload, self::JSON_FLAGS));
    }

    private function normalizeValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_null($value) => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string)$value,
            $value instanceof Throwable => sprintf(
                '%s: %s at %s:%d',
                $value::class,
                $value->getMessage(),
                $value->getFile(),
                $value->getLine(),
            ),
            default => (string)json_encode($value, self::JSON_FLAGS),
        };
    }

    /**
     * Truncate to the given number of characters (UTF-8 aware).
     */
    private function truncate(string $value, int $maxLength): string
    {
        if (preg_match('/^.{0,' . $maxLength . '}/su', $value, $matches) !== 1) {
            // Invalid UTF-8, fall back to bytes
            return strlen($value) > $maxLength ? substr($value, 0, $maxLength) . self::TRUNCATED_SUFFIX : $value;
        }

        return $matches[0] === $value ? $value : $matches[0] . self::TRUNCATED_SUFFIX;
    }

    /**
     * Cut to at most the given number of bytes without breaking a UTF-8 character.
     */
    private function cutBytes(string $value, int $bytes): string
    {
        $cut = substr($value, 0, max($bytes, 0));

        for ($i = 0; $i < 3 && $cut !== '' && preg_match('//u', $cut) !== 1; $i++) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    /**
     * Escape each line and join them with <br>. Google Chat renders both <br> and "\n" as line breaks,
     * so the lines must not keep "\n" (as nl2br() does), or a blank line shows between lines.
     *
     * @param array<string> $lines
     */
    private function toHtmlLines(array $lines): string
    {
        return implode('<br>', array_map(fn (string $line) => $this->escape($line), $lines));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
