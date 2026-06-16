<?php

declare(strict_types=1);

/** @var array{email: string} $old */
?>
<div class="auth-card card">
  <h1>Welcome back</h1>
  <p class="muted">Log in to manage your forms and submissions.</p>
  <form method="post" action="/login" class="stack">
    <?= Csrf::field() ?>
    <div class="form-row">
      <label for="login-email">Email</label>
      <input class="input" id="login-email" type="email" name="email" maxlength="255" required
             value="<?= e($old['email']) ?>" autocomplete="email" autofocus>
    </div>
    <div class="form-row">
      <label for="login-password">Password</label>
      <input class="input" id="login-password" type="password" name="password" required
             autocomplete="current-password">
    </div>
    <button type="submit" class="btn btn-primary btn-large btn-block">Log in</button>
  </form>
  <p class="demo-hint">
    Just exploring? Demo account:<br>
    <code>demo@reliableform.dev</code> / <code>demo1234</code>
  </p>
  <p class="auth-alt">New here? <a href="/register">Create a free account</a></p>
</div>
