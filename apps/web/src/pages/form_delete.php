<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$form = owned_form($user, (int) $params['id']);

// FKs cascade: submissions, pdf_jobs and emails rows go with the form.
DB::run('DELETE FROM forms WHERE id = ?', [(int) $form['id']]);

invalidate_form_cache((string) $form['public_id']);
Metrics::increment('web.form.delete');
flash('success', 'Form "' . (string) $form['title'] . '" was deleted.');
redirect('/dashboard');
