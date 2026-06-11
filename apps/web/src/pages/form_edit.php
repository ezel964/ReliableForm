<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$form = owned_form($user, (int) $params['id']);

$fields = json_decode((string) $form['fields'], true);

render_page('builder', [
    'title' => 'Edit form',
    'currentUser' => $user,
    'mode' => 'edit',
    'action' => '/forms/' . (int) $form['id'],
    'titleValue' => (string) $form['title'],
    'descriptionValue' => (string) ($form['description'] ?? ''),
    'webhookValue' => (string) ($form['webhook_url'] ?? ''),
    'fields' => is_array($fields) ? $fields : [],
    'scripts' => ['/assets/js/builder.js'],
]);
