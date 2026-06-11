<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = strtolower(trim(post_str('email')));
    $password = post_str('password');

    if ($email !== '' && $password !== '' && Auth::attempt($email, $password)) {
        flash('success', 'Welcome back!');
        redirect('/dashboard');
    }

    flash('error', 'Login failed — wrong email/password or too many attempts. Wait a minute and try again.');
    render_page('login', [
        'title' => 'Log in',
        'old' => ['email' => $email],
    ]);
    return;
}

render_page('login', [
    'title' => 'Log in',
    'old' => ['email' => ''],
]);
