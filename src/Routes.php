<?php

declare(strict_types=1);

namespace App;

use App\Http\CartController;
use App\Http\CheckoutController;
use App\Http\CommissionController;
use App\Http\CommissionPaymentController;
use App\Http\StripeWebhookController;
use App\Http\CommissionRequestController;
use App\Http\ContactController;
use App\Http\CustomerAuthController;
use App\Http\HealthController;
use App\Http\BlueskyController;
use App\Http\PieceController;
use App\Http\SpaController;
use App\Http\PublicProfileController;
use App\Http\Middleware\AccountsSwitchMiddleware;
use App\Http\Middleware\CustomerAuthMiddleware;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SessionMiddleware;
use App\Http\MimosaAuthController;
use App\Http\MimosaPieceController;
use App\Http\MimosaCommissionController;
use App\Http\MimosaHealthController;
use App\Http\MimosaImageController;
use App\Http\MimosaUserController;
use App\Http\MimosaContactController;
use App\Http\PasswordResetController;
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
            $p->get('/health', MimosaHealthController::class);
            $p->get('/commissions', MimosaCommissionController::class . ':overview');
            $p->get('/commissions/pricing', MimosaCommissionController::class . ':pricing');
            $p->get('/commissions/{id:[0-9]+}', MimosaCommissionController::class . ':show');
            $p->get('/commissions/{id:[0-9]+}/files/{fid:[0-9]+}', MimosaCommissionController::class . ':file');
            $p->post('/commissions/{id:[0-9]+}/status', MimosaCommissionController::class . ':setStatus');
            $p->post('/commissions/{id:[0-9]+}/external-payment', MimosaCommissionController::class . ':recordExternalPayment');
            $p->get('/orders', MimosaCommissionController::class . ':orders');
            $p->get('/contact', MimosaContactController::class . ':index');
            $p->get('/contact/blocks', MimosaContactController::class . ':blocks');
            $p->post('/contact/blocks', MimosaContactController::class . ':add');
            $p->delete('/contact/blocks/{id:[0-9]+}', MimosaContactController::class . ':remove');
            $p->post('/contact/{id:[0-9]+}/block', MimosaContactController::class . ':blockFromMessage');
            $p->put('/commissions/pricing', MimosaCommissionController::class . ':savePricing');
            $p->put('/commissions/availability', MimosaCommissionController::class . ':saveAvailability');
            $p->get('/pieces', MimosaPieceController::class . ':index');
            $p->post('/pieces', MimosaPieceController::class . ':create');
            $p->get('/pieces/{id:[0-9]+}', MimosaPieceController::class . ':show');
            $p->patch('/pieces/{id:[0-9]+}', MimosaPieceController::class . ':update');
            $p->delete('/pieces/{id:[0-9]+}', MimosaPieceController::class . ':delete');
            $p->post('/pieces/{id:[0-9]+}/restore', MimosaPieceController::class . ':restore');
            $p->get('/images/config', MimosaImageController::class . ':config');
            $p->get('/watermarks/{variant:dark|light}', MimosaImageController::class . ':logo');
            $p->get('/pieces/{id:[0-9]+}/images', MimosaImageController::class . ':index');
            $p->post('/pieces/{id:[0-9]+}/images', MimosaImageController::class . ':upload');
            $p->get('/images/{id:[0-9]+}/base', MimosaImageController::class . ':base');
            $p->patch('/images/{id:[0-9]+}', MimosaImageController::class . ':updateAltText');
            $p->post('/images/{id:[0-9]+}/watermark', MimosaImageController::class . ':watermark');
            $p->post('/images/{id:[0-9]+}/watermark/review', MimosaImageController::class . ':reviewWatermark');
            $p->delete('/images/{id:[0-9]+}/watermark', MimosaImageController::class . ':unwatermark');
            $p->get('/users', MimosaUserController::class . ':index');
            $p->get('/users/{id:[0-9]+}', MimosaUserController::class . ':show');
            $p->patch('/users/{id:[0-9]+}', MimosaUserController::class . ':update');
            $p->post('/users/{id:[0-9]+}/pieces', MimosaUserController::class . ':assignPiece');
            $p->delete('/users/{id:[0-9]+}/pieces/{pieceId:[0-9]+}', MimosaUserController::class . ':removePiece');
            $p->delete('/users/{id:[0-9]+}/avatar', MimosaUserController::class . ':removeAvatar');
            $p->post('/users/{id:[0-9]+}/reset-password', MimosaUserController::class . ':resetPassword');
        })->add(AuthMiddleware::class);
        })->add(CsrfMiddleware::class)->add(SessionMiddleware::class);
        $app->group('/api/account', function (RouteCollectorProxy $g): void {
            $g->get('/csrf', CustomerAuthController::class . ':csrf');
            $g->get('/me', CustomerAuthController::class . ':me');
            $g->post('/register', CustomerAuthController::class . ':register');
            $g->post('/verify', CustomerAuthController::class . ':verify');
            $g->post('/verify/resend', CustomerAuthController::class . ':resend');
            $g->post('/login', CustomerAuthController::class . ':login');
            $g->post('/login/code', CustomerAuthController::class . ':loginCode');
            $g->post('/login/totp', MimosaAuthController::class . ':totp');
            $g->post('/logout', CustomerAuthController::class . ':logout');
            $g->post('/password/forgot', PasswordResetController::class . ':forgot');
            $g->post('/password/reset', PasswordResetController::class . ':reset');
            $g->group('', function (RouteCollectorProxy $p): void {
                $p->get('/profile', CustomerAuthController::class . ':profile');
                $p->post('/profile', CustomerAuthController::class . ':createProfile');
                $p->patch('/profile', CustomerAuthController::class . ':updateProfile');
                $p->post('/profile/avatar', CustomerAuthController::class . ':uploadAvatar');
                $p->delete('/profile/avatar', CustomerAuthController::class . ':removeAvatar');
                $p->get('/commissions', CommissionRequestController::class . ':list');
                $p->post('/commissions', CommissionRequestController::class . ':create');
                $p->get('/commissions/{id:[0-9]+}', CommissionRequestController::class . ':show');
                $p->post('/commissions/{id:[0-9]+}/reply', CommissionRequestController::class . ':reply');
                $p->get('/commissions/{id:[0-9]+}/payment', CommissionPaymentController::class . ':show');
                $p->post('/commissions/{id:[0-9]+}/payment/quote', CommissionPaymentController::class . ':quote');
                $p->post('/commissions/{id:[0-9]+}/payment/pay', CommissionPaymentController::class . ':pay');
                $p->get('/cart', CartController::class . ':show');
                $p->post('/cart', CartController::class . ':add');
                $p->delete('/cart/{pieceId:[0-9]+}', CartController::class . ':remove');
                $p->post('/checkout/quote', CheckoutController::class . ':quote');
                $p->post('/checkout/pay', CheckoutController::class . ':pay');
                $p->post('/checkout/finalize', CheckoutController::class . ':finalize');
            })->add(CustomerAuthMiddleware::class);
        })->add(CsrfMiddleware::class)->add(SessionMiddleware::class)->add(AccountsSwitchMiddleware::class);
        $app->get('/api/users/{username:[a-z0-9_]{3,24}}', [PublicProfileController::class, 'show'])->add(AccountsSwitchMiddleware::class);
        $app->post('/api/stripe/webhook', [StripeWebhookController::class, 'handle']);
        $app->get('/api/health', HealthController::class);
        $app->get('/api/contact/token', [ContactController::class, 'token']);
        $app->post('/api/contact', [ContactController::class, 'send']);
        $app->get('/api/commissions/info', [CommissionController::class, 'info']);
        $app->get('/api/bsky/feed', [BlueskyController::class, 'feed']);
        $app->get('/api/pieces', [PieceController::class, 'index']);
        $app->get('/api/pieces/{id:[0-9]+}-{slug:[a-z0-9-]+}', [PieceController::class, 'show']);
        $app->get('/api/pieces/{id:[0-9]+}', [PieceController::class, 'show']);

        // Everything else is the React app. This needs to be kept LAST; unknown /api/* paths 404 inside the controller.
        $app->get('/{path:.*}', SpaController::class);
    }
}