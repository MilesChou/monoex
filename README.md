# Monoex

[![tests](https://github.com/MilesChou/monoex/actions/workflows/tests.yml/badge.svg)](https://github.com/MilesChou/monoex/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/MilesChou/monoex/branch/master/graph/badge.svg)](https://codecov.io/gh/MilesChou/monoex)
[![Codacy Badge](https://app.codacy.com/project/badge/Grade/84689fff058c4666ba901071f59714c1)](https://app.codacy.com/gh/MilesChou/monoex/dashboard)
[![Latest Stable Version](https://poser.pugx.org/MilesChou/monoex/v/stable)](https://packagist.org/packages/mileschou/monoex)
[![Total Downloads](https://poser.pugx.org/MilesChou/monoex/d/total.svg)](https://packagist.org/packages/mileschou/monoex)
[![License](https://poser.pugx.org/MilesChou/monoex/license)](https://packagist.org/packages/mileschou/monoex)

Monolog extensions.

* Version 0.x Support PHP 7.1 ~ 8.0 and Laravel 5.7 ~ 9.0.
* Version 2.x Support PHP 8.1 and Laravel 10.

## Use on Laravel

This package implements [Package Discovery](https://laravel.com/docs/7.x/packages#package-discovery), and the following [PSR-17](https://www.php-fig.org/psr/psr-17/) / [PSR-18](https://www.php-fig.org/psr/psr-18/) driver must be register:

* `Psr\Http\Client\ClientInterface`
* `Psr\Http\Message\RequestFactoryInterface`
* `Psr\Http\Message\StreamFactoryInterface`

Example, use [`laminas/laminas-diactoros`](https://packagist.org/packages/laminas/laminas-diactoros) and [`symfony/http-client`](https://packagist.org/packages/symfony/http-client):

```php
$app->singleton(RequestFactoryInterface::class, new \Laminas\Diactoros\RequestFactory());
$app->singleton(ResponseFactoryInterface::class, new \Laminas\Diactoros\ResponseFactory());
$app->singleton(StreamFactoryInterface::class, new \Laminas\Diactoros\StreamFactory());

$app->singleton(ClientInterface::class, function($app) {
    return new \Symfony\Component\HttpClient\Psr18Client(
        null,
        $app->make(ResponseFactoryInterface::class),
        $app->make(StreamFactoryInterface::class)
    );
});
```

Finally, the `logging.php` config can use by new driver `psr18slack`:

```php
return [
    'channels' => [
        'stack' => [
            'driver' => 'psr18slack',
            // same as slack driver
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => 'critical',
        ],
    ],
];
```

### Google Chat

The `psr18googlechat` driver sends each log record to a [Google Chat incoming webhook](https://developers.google.com/workspace/chat/quickstart/webhooks) as a card message.

```php
return [
    'channels' => [
        'google_chat' => [
            'driver' => 'psr18googlechat',
            // The webhook URL contains the key and token, keep it in env
            'url' => env('LOG_GOOGLE_CHAT_WEBHOOK_URL'),
            'level' => 'error',
            // Optional. When false, a failed request throws an exception. Default: true
            'silent' => true,
        ],
    ],
];
```

Card layout, from top to bottom (Google Chat draws a divider between sections):

| Section | Content | Collapse rule |
|---|---|---|
| Title | The first line of the log message in bold. Small text below it shows the level with an icon, the channel name and the log date, for example `🔥 ERROR · production · 2026-11-11 07:01:24` | Never collapsed |
| Other lines | The other lines of the log message | Lines after the 5th are collapsed behind "Show more" |
| Context | One `key: value` line per context field, with a bold key | Fields after the 3rd are collapsed behind "Show more" |
| Extra | One `key: value` line per extra field, with a bold key | Fields after the 3rd are collapsed behind "Show more" |

* A section without content is not shown.
* Leading and trailing line breaks of the log message are ignored. An empty log message is shown as `(empty message)`.
* Notifications show the level and the first line of the log message, for example `🔥 ERROR: Something failed`.
* The card header is not used, because Google Chat cannot make the header title bold and removes text in angle brackets from it.

Message size rules:

* Each context / extra value is truncated to 255 characters.
* The whole message is limited to 32,000 bytes, the maximum size defined by Google Chat. The log message uses the remaining space and is truncated when it does not fit. When the fields alone exceed the limit, fields are omitted from the end.
* A card shows at most 100 widgets, so at most 96 fields are shown. The other fields are omitted.

Failure handling:

* A failed request (network error or non-2xx response, such as `429 Too Many Requests`) is not retried.
* When `silent` is true, the failure is written to the PHP error log (`error_log()`) with the HTTP status and the log level.
* When `silent` is false, a `RuntimeException` is thrown.
* The `key` and `token` of the webhook URL are masked as `***` in both cases.

Google Chat accepts 1 request per second per space. Requests over this limit are rejected with HTTP 429.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
