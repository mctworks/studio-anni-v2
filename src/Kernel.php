<?php

declare(strict_types=1);

namespace App;

use DI\Container;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory;

final class Kernel
{
    public static function create(string $root, string $publicDir): App
    {
        Dotenv::createImmutable($root)->safeLoad();

        $config = new Config($root, $publicDir, $_ENV);

        $container = new Container([
            Config::class => $config,
            \PDO::class => static fn (ContainerInterface $c): \PDO => Database::connect($c->get(Config::class)),
        ]);

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        // Error details are shown only when APP_ENV is not production AND APP_DEBUG=true.
        $app->addErrorMiddleware(! $config->isProduction() && $config->get('APP_DEBUG') === 'true', true, true);

        Routes::register($app);

        return $app;
    }
}
