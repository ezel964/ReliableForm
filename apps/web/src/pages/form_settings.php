<?php

declare(strict_types=1);

$user = Auth::requireLogin();
$form = owned_form($user, (int) $params['id']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $check = validate_settings_request($_POST);
    if (!$check['ok']) {
        flash('error', (string) $check['error']);
        render_page('form_settings', [
            'title' => 'Settings — ' . (string) $form['title'],
            'currentUser' => $user,
            'form' => $form,
            'values' => [
                'is_published' => !empty($_POST['is_published']) ? 1 : 0,
                'submission_limit' => post_str('submission_limit'),
                'thankyou_message' => post_str('thankyou_message'),
                'autoresponder_enabled' => !empty($_POST['autoresponder_enabled']) ? 1 : 0,
            ],
        ]);
        return;
    }

    DB::run(
        'UPDATE forms SET is_published = ?, submission_limit = ?, thankyou_message = ?, autoresponder_enabled = ? WHERE id = ?',
        [
            $check['is_published'],
            $check['submission_limit'],
            $check['thankyou_message'],
            $check['autoresponder_enabled'],
            (int) $form['id'],
        ]
    );

    // The public page reads through cache:form:<public_id> — stale settings
    // would keep accepting (or refusing) submissions for up to 60s otherwise.
    invalidate_form_cache((string) $form['public_id']);
    Metrics::increment('web.form.update');
    flash('success', 'Settings saved.');
    redirect('/forms/' . (int) $form['id'] . '/settings');
}

render_page('form_settings', [
    'title' => 'Settings — ' . (string) $form['title'],
    'currentUser' => $user,
    'form' => $form,
    'values' => [
        'is_published' => (int) $form['is_published'],
        'submission_limit' => $form['submission_limit'] === null ? '' : (string) $form['submission_limit'],
        'thankyou_message' => (string) ($form['thankyou_message'] ?? ''),
        'autoresponder_enabled' => (int) $form['autoresponder_enabled'],
    ],
]);
