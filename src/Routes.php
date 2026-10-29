<?php

declare(strict_types=1);

namespace App;

use App\Http\HealthController;
use App\Http\BlueskyController;
use App\Http\PieceController;
use App\Http\SpaController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\MimosaAuthController;
use App\Http\MimosaPieceController;
use Slim\Routing\RouteCollectorProxy;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
        $app->group('/api/mimosa', function (RouteCollectorProxy $g): void {
            $g->get('/csrf', MimosaAuthController::class . ':csrf');
            $g->post('/login', MimosaAuthController::class . ':login');
            $g->post('/login/totp', MimosaAuthController::class . ':totp');
            $g->post('/logout', MimosaAuthController::class . ':logout');
            $g->get('/me', MimosaAuthController::class . ':me')->add(AuthMiddleware::class);
            $g->group('', function (RouteCollectorProxy $p): void {
            $p->get('/meta', MimosaPieceController::class . ':meta');
            $p->get('/pieces', MimosaPieceController::class . ':index');
            $p->post('/pieces', MimosaPieceController::class . ':create');
            $p->get('/pieces/{id:[0-9]+}', MimosaPieceController::class . ':show');
            $p->patch('/pieces/{id:[0-9]+}', MimosaPieceController::class . ':update');
            $p->delete('/pieces/{id:[0-9]+}', MimosaPieceController::class . ':delete');
            $p->post('/pieces/{id:[0-9]+}/restore', MimosaPieceController::class . ':restore');
        })->add(AuthMiddleware::class);
        })->add(CsrfMiddleware::class)->add(SessionMiddleware::class);
        $app->get('/api/health', HealthController::class);
        $app->get('/api/bsky/feed', [BlueskyController::class, 'feed']);
        $app->get('/api/pieces', [PieceController::class, 'index']);
        $app->get('/api/pieces/{id:[0-9]+}-{slug:[a-z0-9-]+}', [PieceController::class, 'show']);
        $app->get('/api/pieces/{id:[0-9]+}', [PieceController::class, 'show']);

        // Everything else is the React app. This needs to be kept LAST; unknown /api/* paths 404 inside the controller.
        $app->get('/{path:.*}', SpaController::class);
    }
}