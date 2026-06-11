<?php

declare(strict_types=1);

$publicId = (string) $params['public_id'];

$form = load_public_form($publicId);
if ($form === null) {
    not_found('This form is not available.');
}

// Custom copy from the settings page; '' ⇒ default copy. Cached rows may
// predate the column (≤60s) — coalesce to the default.
$thankyouMessage = trim((string) ($form['thankyou_message'] ?? ''));

render_page('thanks', [
    'title' => 'Thank you',
    'form' => $form,
    'publicId' => $publicId,
    'thankyouMessage' => $thankyouMessage,
]);
