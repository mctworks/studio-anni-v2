<?php

declare(strict_types=1);

namespace App;

use App\Auth\BreachedPasswords;
use App\Auth\DbSessionHandler;
use App\Auth\PasswordService;
use App\Http\Middleware\KillSwitchMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Mail\Mailer;
use App\Mail\SmtpMailer;
use DI\Container;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class Kernel
{
    public static function create(string $root, string $publicDir): App
    {
        Dotenv::createImmutable($root)->safeLoad();

        $config = new Config($root, $publicDir, $_ENV);

        $container = new Container([
            Config::class => $config,
            \PDO::class => static fn (ContainerInterface $c): \PDO => Database::connect($c->get(Config::class)),
            Mailer::class => \DI\autowire(SmtpMailer::class),
            ResponseFactoryInterface::class => static fn (): ResponseFactoryInterface => new ResponseFactory(),
            PasswordService::class => static fn (ContainerInterface $c): PasswordService => new PasswordService(
                $c->get(Config::class),
                self::breachedList($c->get(Config::class)),
            ),
            DbSessionHandler::class => static fn (ContainerInterface $c): DbSessionHandler => new DbSessionHandler(
                $c->get(\PDO::class),
                (int) $c->get(Config::class)->get('SESSION_IDLE_SECONDS', '1200'),
                (int) $c->get(Config::class)->get('SESSION_ABSOLUTE_SECONDS', '28800'),
            ),
        ]);

        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new KillSwitchMiddleware($config));
        // Error details are shown only when APP_ENV is not production AND APP_DEBUG=true.
        $errors = $app->addErrorMiddleware(! $config->isProduction() && $config->get('APP_DEBUG') === 'true', true, true);
        $defaultErrorHandler = $errors->getDefaultErrorHandler();
        $errors->setDefaultErrorHandler(static function ($request, \Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) use ($config, $defaultErrorHandler) {
            self::logException($config, $request, $exception);

            return $defaultErrorHandler($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
        });
        $app->add(new SecurityHeadersMiddleware($config));

        Routes::register($app);

        return $app;
    }

    private static function breachedList(Config $config): ?BreachedPasswords
    {
        $explicit = $config->get('BREACHED_PASSWORDS_FILE');
        if ($explicit === null) {
            $default = $config->root . '/storage/data/breached-sha1.txt';

            return is_file($default) ? new BreachedPasswords($default) : null;
        }

        return new BreachedPasswords(str_starts_with($explicit, '/') ? $explicit : $config->root . '/' . $explicit);
    }

    private static function logException(Config $config, \Psr\Http\Message\ServerRequestInterface $request, \Throwable $exception): void
    {
        $entry = sprintf(
            "[%s] %s: %s in %s:%d (%s %s)\n%s\n",
            gmdate('Y-m-d H:i:s'),
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $request->getMethod(),
            $request->getUri()->getPath(),
            $exception->getTraceAsString(),
        );
        $path = $config->root . '/storage/logs/application.log';
        $directory = dirname($path);

        try {
            if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Unable to create application log directory');
            }
            if (@file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException('Unable to write application log');
            }
            @chmod($path, 0640);
        } catch (\Throwable) {
            error_log('Application exception: ' . $exception::class . ' in ' . $exception->getFile() . ':' . $exception->getLine());
        }
    }
}
