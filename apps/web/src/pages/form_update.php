<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$form = owned_form($user, (int) $params['id']);

$check = validate_builder_request($_POST);
if (!$check['ok']) {
    flash('error', (string) $check['error']);
    render_page('builder', [
        'title' => 'Edit form',
        'currentUser' => $user,
        'mode' => 'edit',
        'action' => '/forms/' . (int) $form['id'],
        'titleValue' => post_str('title'),
        'descriptionValue' => post_str('description'),
        'webhookValue' => post_str('webhook_url'),
        'fields' => decode_fields_for_redisplay(post_str('fields_json')),
        'scripts' => ['/assets/js/builder.js'],
    ]);
    return;
}

DB::run(
    'UPDATE forms SET title = ?, description = ?, fields = ?, webhook_url = ? WHERE id = ?',
    [
        $check['title'],
        $check['description'],
        json_encode($check['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $check['webhook_url'],
        (int) $form['id'],
    ]
);

invalidate_form_cache((string) $form['public_id']);
Metrics::increment('web.form.update');
flash('success', 'Form updated.');
redirect('/dashboard');
