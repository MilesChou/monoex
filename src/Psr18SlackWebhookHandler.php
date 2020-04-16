<?php

declare(strict_types=1);

namespace MilesChou\Monoex;

use Monolog\Handler\SlackWebhookHandler as BaseSlackWebhookHandler;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Psr18SlackWebhookHandler extends BaseSlackWebhookHandler
{
    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var RequestFactoryInterface
     */
    private $requestFactory;

    /**
     * @var StreamFactoryInterface
     */
    private $streamFactory;

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
     * Overload for custom option of sending request
     *
     * @param array<mixed> $record
     */
    protected function write(array $record): void
    {
        $postData = $this->getSlackRecord()->getSlackData($record);
        $postString = (string)json_encode($postData);

        $request = $this->requestFactory->createRequest('POST', $this->getWebhookUrl())
            ->withHeader('Content-type', 'application/json')
            ->withBody($this->streamFactory->createStream($postString));

        $this->httpClient->sendRequest($request);
    }
}
