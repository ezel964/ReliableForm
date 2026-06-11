<?php

declare(strict_types=1);

$publicId = (string) $params['public_id'];

$form = load_public_form($publicId);
if ($form === null) {
    not_found('This form is not available.');
}

render_page('thanks', [
    'title' => 'Thank you',
    'form' => $form,
    'publicId' => $publicId,
]);
