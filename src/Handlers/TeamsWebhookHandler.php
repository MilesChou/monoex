<?php

declare(strict_types=1);

namespace MilesChou\Monoex\Handlers;

use MilesChou\Monoex\Teams\LoggerColour;
use MilesChou\Monoex\Teams\LoggerMessage;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

class TeamsWebhookHandler extends AbstractProcessingHandler
{
    private ClientInterface $httpClient;

    private RequestFactoryInterface $requestFactory;

    private StreamFactoryInterface $streamFactory;

    private bool $silent = true;

    private array $loggerColour = [
        'EMERGENCY' => '721C24',
        'ALERT' => 'AF2432',
        'CRITICAL' => 'FF0000',
        'ERROR' => 'FF8000',
        'WARNING' => 'FFEEBA',
        'NOTICE' => 'B8DAFF',
        'INFO' => 'BEE5EB',
        'DEBUG' => 'C3E6CB',
    ];

    public function __construct(
        private readonly string $webhookUrl,
        $level = MonologLogger::DEBUG,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
    }

    public function setDriver(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
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


    protected function getMessage(LogRecord $record)
    {
        $facts = [];
        foreach ($record['context'] as $name => $value) {
            $facts[] = ['name' => $name, 'value' => $value];
        }
        foreach ($record['extra'] as $name => $value) {
            $facts[] = ['name' => $name, 'value' => $value];
        }
        $facts = array_merge($facts, [[
            'name' => 'Sent Date',
            'value' => $record['datetime'] !== null ?
                $record['datetime']->format('Y-m-d H:i:s') :
                date('Y-m-d H:i:s'),
        ]]);

        $loggerMessage = new LoggerMessage([
            'summary' => $record['level_name'],
            'themeColor' => $this->loggerColour[$record['level_name']],
            'sections' => [[
                'activityTitle' => 'Message',
                'activitySubtitle' => $record['message'],
                'facts' => $facts,
                'markdown' => true
            ]]
        ]);

        return $loggerMessage->jsonSerialize();
    }

    /**
     * @throws Throwable
     * @throws ClientExceptionInterface
     */
    protected function write(LogRecord $record): void
    {
        $postString = (string)json_encode($this->getMessage($record));

        $request = $this->requestFactory->createRequest('POST', $this->webhookUrl)
            ->withHeader('Content-type', 'application/json')
            ->withHeader('Content-Length', strlen($postString))
            ->withBody($this->streamFactory->createStream($postString));

        try {
            // TODO: https://learn.microsoft.com/zh-tw/microsoftteams/platform/webhooks-and-connectors/how-to/connectors-using?tabs=cURL#rate-limiting-for-connectors
            $this->httpClient->sendRequest($request);
        } catch (Throwable $e) {
            if ($this->silent) {
                return;
            }
            throw $e;
        }
    }
}
