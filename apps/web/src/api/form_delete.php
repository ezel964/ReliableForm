<?php

declare(strict_types=1);

/**
 * DELETE /v1/forms/{public_id} — delete an owned form. Submissions and job
 * rows cascade per the schema's foreign keys.
 *
 * @var array{id: int} $apiUser
 * @var array<string, string> $params
 */

$form = api_owned_form($apiUser, (string) $params['public_id']);

DB::run('DELETE FROM forms WHERE id = ?', [(int) $form['id']]);

invalidate_form_cache((string) $form['public_id']);
Metrics::increment('web.form.delete');

api_json(['ok' => true, 'deleted' => true]);
