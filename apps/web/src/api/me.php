<?php

declare(strict_types=1);

/**
 * GET /v1/me — the authenticated key's owner.
 * @var array{id: int, email: string, name: string, created_at: string} $apiUser
 */

api_json([
    'ok' => true,
    'user' => [
        'id' => $apiUser['id'],
        'email' => $apiUser['email'],
        'name' => $apiUser['name'],
        'created_at' => $apiUser['created_at'],
    ],
]);
