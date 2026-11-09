<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionManager;
use App\Auth\Users;
use App\Http\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/** Any signed-in, active account (customer, admin or owner) may use its own profile. */
final class CustomerAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Users $users,
        private readonly ResponseFactoryInterface $responses,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $id = $this->sessions->userId();
        $user = $id !== null ? $this->users->findById($id) : null;

        if ($user === null || $user['status'] !== 'active' || ! in_array($user['role'], ['customer', 'admin', 'owner'], true)) {
            if ($id !== null) {
                $this->sessions->logout();
            }

            return Json::write($this->responses->createResponse(), ['error' => 'unauthenticated'], 401);
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
