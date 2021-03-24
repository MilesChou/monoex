# Monoex

[![tests](https://github.com/MilesChou/monoex/actions/workflows/tests.yml/badge.svg)](https://github.com/MilesChou/monoex/actions/workflows/tests.yml)
[![codecov](https://codecov.io/gh/MilesChou/monoex/branch/master/graph/badge.svg)](https://codecov.io/gh/MilesChou/monoex)
[![Codacy Badge](https://api.codacy.com/project/badge/Grade/84689fff058c4666ba901071f59714c1)](https://www.codacy.com/manual/MilesChou/monoex)
[![Latest Stable Version](https://poser.pugx.org/MilesChou/monoex/v/stable)](https://packagist.org/packages/mileschou/monoex)
[![Total Downloads](https://poser.pugx.org/MilesChou/monoex/d/total.svg)](https://packagist.org/packages/mileschou/monoex)
[![License](https://poser.pugx.org/MilesChou/monoex/license)](https://packagist.org/packages/mileschou/monoex)

Monolog extensions.

Support Laravel 5.7, 5.8, 6.x, 7.x.

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

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
