<?php

declare(strict_types=1);

/**
 * POST /v1/forms — create a form owned by the authenticated key. Body:
 * {title, description?, webhook_url?, fields:[...], conditions?:[...]}. Reuses
 * the shared builder + conditions validators (validation.php / conditions.php),
 * so the rules are identical to the web builder. 201 with the full form.
 *
 * @var array{id: int} $apiUser
 */

$body = api_json_body();

$check = validate_builder_request(api_builder_post_from_body($body));
if (!$check['ok']) {
    api_error('validation_failed', (string) $check['error'], 422);
}

$conditionsJson = json_encode($body['conditions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$conditionsCheck = conditions_validate($conditionsJson, $check['fields']);
if (!$conditionsCheck['ok']) {
    api_error('validation_failed', (string) $conditionsCheck['error'], 422);
}

$fieldsJson = json_encode($check['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$storedConditions = $conditionsCheck['conditions'] === []
    ? null
    : json_encode($conditionsCheck['conditions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// public_id() collisions are ~impossible (36^10) but the column is UNIQUE, so retry.
$publicId = '';
$newId = 0;
for ($try = 0; $try < 3; $try++) {
    $publicId = public_id();
    try {
        $newId = DB::insert(
            'INSERT INTO forms (user_id, public_id, title, description, fields, conditions, webhook_url) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [(int) $apiUser['id'], $publicId, $check['title'], $check['description'], $fieldsJson, $storedConditions, $check['webhook_url']]
        );
        break;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062 && $try < 2) {
            continue;
        }
        throw $e;
    }
}

Metrics::increment('web.form.create');

$form = DB::one('SELECT * FROM forms WHERE id = ?', [$newId]);
api_json(['ok' => true, 'form' => api_form_payload($form ?? [])], 201);
