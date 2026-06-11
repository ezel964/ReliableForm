<?php

declare(strict_types=1);

$user = Auth::requireLogin();

$check = validate_builder_request($_POST);
if (!$check['ok']) {
    flash('error', (string) $check['error']);
    render_page('builder', [
        'title' => 'New form',
        'currentUser' => $user,
        'mode' => 'new',
        'action' => '/forms',
        'titleValue' => post_str('title'),
        'descriptionValue' => post_str('description'),
        'webhookValue' => post_str('webhook_url'),
        'fields' => decode_fields_for_redisplay(post_str('fields_json')),
        'scripts' => ['/assets/js/builder.js'],
    ]);
    return;
}

$fieldsJson = json_encode($check['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// public_id() collisions are ~impossible (36^10) but the column is UNIQUE, so retry.
$publicId = '';
for ($try = 0; $try < 3; $try++) {
    $publicId = public_id();
    try {
        DB::insert(
            'INSERT INTO forms (user_id, public_id, title, description, fields, webhook_url) VALUES (?, ?, ?, ?, ?, ?)',
            [(int) $user['id'], $publicId, $check['title'], $check['description'], $fieldsJson, $check['webhook_url']]
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
flash('success', 'Form created. Share it: ' . public_form_url($publicId));
redirect('/dashboard');
