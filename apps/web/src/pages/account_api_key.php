<?php

declare(strict_types=1);

/**
 * POST /account/api-key — regenerate the logged-in user's API key.
 * CSRF is validated by the front controller (every web POST), auth here.
 * The old key stops working immediately (api_key is the lookup column).
 */

$user = Auth::requireLogin();

DB::run('UPDATE users SET api_key = ? WHERE id = ?', [generate_api_key(), (int) $user['id']]);

Metrics::increment('web.apikey.regenerate');
flash('success', 'API key regenerated. The old key no longer works.');
redirect('/dashboard');
