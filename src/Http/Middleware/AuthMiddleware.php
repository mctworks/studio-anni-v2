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

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly Users $users,
        private readonly ResponseFactoryInterface $responses,
        private readonly array $roles = ['owner', 'admin'],
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $id = $this->sessions->userId();
        $user = $id !== null ? $this->users->findById($id) : null;

        if ($user === null || $user['status'] !== 'active' || ! in_array($user['role'], $this->roles, true)) {
            if ($id !== null) {
                $this->sessions->logout();
            }

            return Json::write($this->responses->createResponse(), ['error' => 'unauthenticated'], 401);
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
