<?php

declare(strict_types=1);

namespace MilesChou\Monoex\Handlers;

use Monolog\Handler\SlackWebhookHandler as BaseSlackWebhookHandler;
use Monolog\LogRecord;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

class Psr18SlackWebhookHandler extends BaseSlackWebhookHandler
{
    /**
     * @var ClientInterface
     */
    private ClientInterface $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    private RequestFactoryInterface $requestFactory;

    /**
     * @var StreamFactoryInterface
     */
    private StreamFactoryInterface $streamFactory;

    /**
     * Don't throw exception when call API
     *
     * @var bool
     */
    private bool $silent = true;

    /**
     * @param ClientInterface $httpClient
     * @param RequestFactoryInterface $requestFactory
     * @param StreamFactoryInterface $streamFactory
     * @return Psr18SlackWebhookHandler
     */
    public function setDriver(
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ): Psr18SlackWebhookHandler {
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;

        return $this;
    }

    /**
     * @param bool $silent
     */
    public function setSilent(bool $silent): void
    {
        $this->silent = $silent;
    }

    /**
     * Overload for custom option of sending request
     *
     * @param LogRecord $record
     * @throws ClientExceptionInterface
     * @throws Throwable
     */
    protected function write(LogRecord $record): void
    {
        $postData = $this->getSlackRecord()->getSlackData($record);
        $postString = (string)json_encode($postData);

        $request = $this->requestFactory->createRequest('POST', $this->getWebhookUrl())
            ->withHeader('Content-type', 'application/json')
            ->withBody($this->streamFactory->createStream($postString));

        try {
            // TODO: https://api.slack.com/docs/rate-limits
            $this->httpClient->sendRequest($request);
        } catch (Throwable $e) {
            if ($this->silent) {
                return;
            }

            throw $e;
        }
    }
}
