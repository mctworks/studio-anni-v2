<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AuditLog;
use App\Auth\ClientIp;
use App\Customers\AddressBook;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AddressBookController
{
    public function __construct(
        private readonly AddressBook $addresses,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];

        return Json::write($response, ['addresses' => $this->addresses->list($userId)]);
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->save($request, $response, null);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        return $this->save($request, $response, (int) $args['id']);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        $addressId = (int) $args['id'];
        if (! $this->addresses->delete($userId, $addressId)) {
            return Json::write($response, ['error' => 'not_found'], 404);
        }
        $this->audit->record('address_deleted', $userId, 'address', (string) $addressId, [], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['ok' => true]);
    }

    private function save(Request $request, Response $response, ?int $addressId): Response
    {
        $userId = (int) $request->getAttribute('user')['id'];
        $input = $request->getParsedBody();
        if (! is_array($input)) {
            return Json::write($response, ['error' => 'invalid_request'], 400);
        }
        $result = $this->addresses->save($userId, $addressId, $input);
        if (! $result['ok']) {
            return Json::write($response, ['error' => $result['error']] + (isset($result['fields']) ? ['fields' => $result['fields']] : []), $result['error'] === 'not_found' ? 404 : 422);
        }
        $this->audit->record($addressId === null ? 'address_created' : 'address_updated', $userId, 'address', (string) $result['address']['id'], ['fields' => array_keys($input)], ClientIp::from($request), $request->getHeaderLine('User-Agent'));

        return Json::write($response, ['address' => $result['address']], $addressId === null ? 201 : 200);
    }
}