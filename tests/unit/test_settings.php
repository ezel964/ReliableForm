<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';
require RF_ROOT . '/apps/web/src/validation.php';

/** Settings POST around defaults (checkboxes absent = off, like a browser). */
function settings_post(array $extra = []): array
{
    return array_merge(['submission_limit' => '', 'thankyou_message' => ''], $extra);
}

// ------------------------------------------------- validate_settings_request ---

t('settings: empty limit means unlimited (NULL)', function (): void {
    $r = validate_settings_request(settings_post());
    assert_true($r['ok'], (string) $r['error']);
    assert_eq(null, $r['submission_limit']);
    $r = validate_settings_request(settings_post(['submission_limit' => '   ']));
    assert_true($r['ok'], 'whitespace-only limit is unlimited');
    assert_eq(null, $r['submission_limit']);
});

t('settings: limit parses digits within 1..65535', function (): void {
    assert_eq(1, validate_settings_request(settings_post(['submission_limit' => '1']))['submission_limit']);
    assert_eq(65535, validate_settings_request(settings_post(['submission_limit' => '65535']))['submission_limit']);
    assert_eq(10, validate_settings_request(settings_post(['submission_limit' => ' 10 ']))['submission_limit'], 'trimmed');
});

t('settings: limit bounds and garbage rejected', function (): void {
    foreach (['0', '65536', '-1', '+5', '1.5', 'abc', '1e3', '10 forms'] as $bad) {
        $r = validate_settings_request(settings_post(['submission_limit' => $bad]));
        assert_true(!$r['ok'], "limit '{$bad}' must be rejected");
        assert_match('/65535/', (string) $r['error']);
    }
    assert_true(!validate_settings_request(settings_post(['submission_limit' => ['5']]))['ok'], 'array rejected');
});

t('settings: thank-you message trimmed, empty becomes NULL', function (): void {
    $r = validate_settings_request(settings_post(['thankyou_message' => '  thanks a lot  ']));
    assert_true($r['ok'], (string) $r['error']);
    assert_eq('thanks a lot', $r['thankyou_message']);
    assert_eq(null, validate_settings_request(settings_post(['thankyou_message' => '   ']))['thankyou_message']);
    assert_eq(null, validate_settings_request(settings_post())['thankyou_message']);
});

t('settings: thank-you message capped at 500 chars (multibyte-aware)', function (): void {
    $ok = validate_settings_request(settings_post(['thankyou_message' => str_repeat('é', 500)]));
    assert_true($ok['ok'], '500 multibyte chars pass');
    $r = validate_settings_request(settings_post(['thankyou_message' => str_repeat('x', 501)]));
    assert_true(!$r['ok'], '501 chars rejected');
    assert_match('/500/', (string) $r['error']);
    assert_true(!validate_settings_request(settings_post(['thankyou_message' => ['x']]))['ok'], 'array rejected');
});

t('settings: checkboxes — absent off, "1" on', function (): void {
    $r = validate_settings_request(settings_post());
    assert_eq(0, $r['is_published']);
    assert_eq(0, $r['autoresponder_enabled']);
    $r = validate_settings_request(settings_post(['is_published' => '1', 'autoresponder_enabled' => '1']));
    assert_eq(1, $r['is_published']);
    assert_eq(1, $r['autoresponder_enabled']);
});

// --------------------------------------------------- autoresponder_recipient ---

function email_field(string $id, string $type = 'email'): array
{
    return ['id' => $id, 'type' => $type, 'label' => 'Label', 'required' => false];
}

t('autoresponder: first email field answer wins', function (): void {
    $fields = [email_field('f_aaa111', 'text'), email_field('f_bbb222'), email_field('f_ccc333')];
    $data = ['f_aaa111' => 'not relevant', 'f_bbb222' => 'first@example.com', 'f_ccc333' => 'second@example.com'];
    assert_eq('first@example.com', autoresponder_recipient($fields, $data));
});

t('autoresponder: null when the form has no email field', function (): void {
    assert_eq(null, autoresponder_recipient([email_field('f_aaa111', 'text')], ['f_aaa111' => 'a@b.com']));
    assert_eq(null, autoresponder_recipient([], []));
});

t('autoresponder: null when the first email answer is missing or empty', function (): void {
    $fields = [email_field('f_bbb222')];
    assert_eq(null, autoresponder_recipient($fields, []));
    assert_eq(null, autoresponder_recipient($fields, ['f_bbb222' => '']));
});

t('autoresponder: null when the answer is not a valid address', function (): void {
    $fields = [email_field('f_bbb222')];
    foreach (['nope', 'a@', '@b.com', 'a b@c.com', ['x@y.com']] as $bad) {
        assert_eq(null, autoresponder_recipient($fields, ['f_bbb222' => $bad]));
    }
});

t('autoresponder: first email field decides alone — no fallback to a later one', function (): void {
    $fields = [email_field('f_bbb222'), email_field('f_ccc333')];
    $data = ['f_bbb222' => '', 'f_ccc333' => 'valid@example.com'];
    assert_eq(null, autoresponder_recipient($fields, $data));
    $data = ['f_bbb222' => 'broken@', 'f_ccc333' => 'valid@example.com'];
    assert_eq(null, autoresponder_recipient($fields, $data));
});
