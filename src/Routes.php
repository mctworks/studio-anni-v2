<?php

declare(strict_types=1);

namespace App;

use App\Http\HealthController;
use App\Http\SpaController;
use Slim\App;

final class Routes
{
    public static function register(App $app): void
    {
        $app->get('/api/health', HealthController::class);

        // Everything else is the React app. This needs to be kept LAST; unknown /api/* paths 404 inside the controller.
        $app->get('/{path:.*}', SpaController::class);
    }
}
