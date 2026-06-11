<?php

declare(strict_types=1);

$user = Auth::requireLogin();

render_page('builder', [
    'title' => 'New form',
    'currentUser' => $user,
    'mode' => 'new',
    'action' => '/forms',
    'titleValue' => '',
    'descriptionValue' => '',
    'fields' => [],
    'conditions' => [],
    'scripts' => ['/assets/js/builder.js'],
]);
