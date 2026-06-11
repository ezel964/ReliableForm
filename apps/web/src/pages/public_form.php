<?php

declare(strict_types=1);

$publicId = (string) $params['public_id'];

$form = load_public_form($publicId);
if ($form === null) {
    not_found('This form is not available.');
}

// Unpublished or at its submission limit ⇒ friendly closed page (200 on GET).
$closedReason = form_closed_reason($form);
if ($closedReason !== null) {
    render_page('form_closed', [
        'title' => (string) $form['title'],
        'form' => $form,
        'reason' => $closedReason,
    ]);
    return;
}

// A view only counts when the actual form renders (never the closed page).
record_form_view($publicId);

$fields = json_decode((string) $form['fields'], true);

render_page('public_form', [
    'title' => (string) $form['title'],
    'form' => $form,
    'fields' => is_array($fields) ? $fields : [],
    'errors' => [],
    'old' => [],
    'publicId' => $publicId,
]);
