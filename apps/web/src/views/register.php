<?php

declare(strict_types=1);

/** @var array{name: string, email: string} $old */
?>
<div class="auth-card card">
  <h1>Create your account</h1>
  <p class="muted">Free while ReliableForm is in beta. No credit card, ever.</p>
  <form method="post" action="/register" class="stack">
    <?= Csrf::field() ?>
    <div class="form-row">
      <label for="reg-name">Your name</label>
      <input class="input" id="reg-name" type="text" name="name" maxlength="100" required
             value="<?= e($old['name']) ?>" autocomplete="name">
    </div>
    <div class="form-row">
      <label for="reg-email">Email</label>
      <input class="input" id="reg-email" type="email" name="email" maxlength="255" required
             value="<?= e($old['email']) ?>" autocomplete="email">
    </div>
    <div class="form-row">
      <label for="reg-password">Password</label>
      <input class="input" id="reg-password" type="password" name="password" minlength="8" required
             autocomplete="new-password">
      <p class="hint">At least 8 characters.</p>
    </div>
    <button type="submit" class="btn btn-primary">Sign up</button>
  </form>
  <p class="auth-alt">Already have an account? <a href="/login">Log in</a></p>
</div>
