<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name = post_str('name');
    $email = post_str('email');
    $result = Auth::register($name, $email, post_str('password'));
    if ($result['ok']) {
        flash('success', 'Welcome to ReliableForm! Your account is ready.');
        redirect('/dashboard');
    }
    flash('error', (string) $result['error']);
    render_page('register', [
        'title' => 'Sign up',
        'old' => ['name' => $name, 'email' => $email],
    ]);
    return;
}

render_page('register', [
    'title' => 'Sign up',
    'old' => ['name' => '', 'email' => ''],
]);
