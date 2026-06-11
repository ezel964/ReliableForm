<?php

declare(strict_types=1);

$publicId = (string) $params['public_id'];

$form = load_public_form($publicId);
if ($form === null || (int) $form['is_published'] !== 1) {
    not_found('This form is not available.');
}

$fields = json_decode((string) $form['fields'], true);

render_page('public_form', [
    'title' => (string) $form['title'],
    'form' => $form,
    'fields' => is_array($fields) ? $fields : [],
    'errors' => [],
    'old' => [],
    'publicId' => $publicId,
]);
