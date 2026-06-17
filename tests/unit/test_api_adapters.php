<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';
require RF_ROOT . '/apps/web/src/api/support.php';
require RF_ROOT . '/apps/web/src/validation.php';

t('builder body → $_POST shape: fields become a JSON string', function (): void {
    $post = api_builder_post_from_body([
        'title' => 'My form',
        'description' => 'hi',
        'webhook_url' => 'https://x.test/h',
        'fields' => [['id' => 'f_abc123', 'type' => 'text', 'label' => 'Name', 'required' => true]],
    ]);
    assert_eq('My form', $post['title']);
    assert_eq('hi', $post['description']);
    assert_eq('https://x.test/h', $post['webhook_url']);
    assert_true(is_string($post['fields_json']));
    $decoded = json_decode($post['fields_json'], true);
    assert_eq('f_abc123', $decoded[0]['id']);
});

t('builder adapter output validates through validate_builder_request', function (): void {
    $post = api_builder_post_from_body([
        'title' => 'Feedback',
        'fields' => [
            ['id' => 'f_name01', 'type' => 'text', 'label' => 'Name', 'required' => true],
            ['id' => 'f_rate01', 'type' => 'rating', 'label' => 'Score', 'required' => true, 'max' => 5],
        ],
    ]);
    $check = validate_builder_request($post);
    assert_true($check['ok'], 'valid builder body passes: ' . (string) $check['error']);
    assert_eq('Feedback', $check['title']);
    assert_eq(2, count($check['fields']));
});

t('missing fields key → empty fields_json (validator then rejects)', function (): void {
    $post = api_builder_post_from_body(['title' => 'x']);
    assert_eq('', $post['fields_json']);
    assert_true(!validate_builder_request($post)['ok'], 'no fields must fail validation');
});

t('settings body → $_POST shape: ints stringified, bools→checkbox truthiness', function (): void {
    $post = api_settings_post_from_body([
        'is_published' => true,
        'submission_limit' => 100,
        'thankyou_message' => 'Thanks!',
        'autoresponder_enabled' => false,
    ]);
    assert_eq('1', $post['is_published']);
    assert_eq('100', $post['submission_limit']);
    assert_eq('Thanks!', $post['thankyou_message']);
    assert_eq('', $post['autoresponder_enabled']);
    $check = validate_settings_request($post);
    assert_true($check['ok']);
    assert_eq(1, $check['is_published']);
    assert_eq(100, $check['submission_limit']);
    assert_eq(0, $check['autoresponder_enabled']);
});

t('settings: null/absent submission_limit means unlimited', function (): void {
    $post = api_settings_post_from_body(['is_published' => false, 'submission_limit' => null]);
    assert_eq('', $post['submission_limit']);
    $check = validate_settings_request($post);
    assert_true($check['ok']);
    assert_true($check['submission_limit'] === null);
    assert_eq(0, $check['is_published']);
});
