<?php

declare(strict_types=1);

// Landing page; logged-in users go straight to their dashboard.
// safe_user() so the landing still renders when Redis/MySQL are down.
if (safe_user() !== null) {
    redirect('/dashboard');
}

render_page('landing', ['title' => 'Build reliable forms', 'currentUser' => null]);
