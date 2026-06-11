<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';
require RF_ROOT . '/apps/web/src/validation.php';

/** Build a builder POST around a fields array (JSON-encoded like the UI does). */
function builder_post(array $fields, array $extra = []): array
{
    return array_merge([
        'title' => 'Unit form',
        'description' => '',
        'webhook_url' => '',
        'fields_json' => json_encode($fields),
    ], $extra);
}

function field(string $id = 'f_abc123', string $type = 'text', array $extra = []): array
{
    return array_merge(['id' => $id, 'type' => $type, 'label' => 'Label', 'required' => false], $extra);
}

// ---------------------------------------------------------------- builder ---

t('builder: minimal valid payload passes and normalizes', function (): void {
    $r = validate_builder_request(builder_post([field()]));
    assert_true($r['ok'], (string) $r['error']);
    assert_eq('Unit form', $r['title']);
    assert_eq(null, $r['description'], 'empty description becomes null');
    assert_eq(null, $r['webhook_url']);
    assert_eq('f_abc123', $r['fields'][0]['id']);
});

t('builder: title required, max 200 chars', function (): void {
    assert_true(!validate_builder_request(builder_post([field()], ['title' => '  ']))['ok']);
    assert_true(!validate_builder_request(builder_post([field()], ['title' => str_repeat('x', 201)]))['ok']);
});

t('builder: fields_json size cap 64 KB', function (): void {
    $fields = [field('f_abc123', 'text', ['placeholder' => str_repeat('p', 200)])];
    $raw = json_encode($fields) . str_repeat(' ', 65537); // > 64 KB raw payload
    $r = validate_builder_request(builder_post([], ['fields_json' => $raw]));
    assert_true(!$r['ok']);
    assert_match('/64 KB/', (string) $r['error']);
});

t('builder: field count 1–50 enforced', function (): void {
    assert_true(!validate_builder_request(builder_post([]))['ok'], 'zero fields rejected');
    $many = [];
    for ($i = 0; $i < 51; $i++) {
        $many[] = field(sprintf('f_%06d', $i));
    }
    assert_true(!validate_builder_request(builder_post($many))['ok'], '51 fields rejected');
});

t('builder: id must match ^f_[a-z0-9]{6}$', function (): void {
    foreach (['x_abc123', 'f_ABC123', 'f_abc12', 'f_abc1234', '', 'f_ab 12'] as $bad) {
        assert_true(!validate_builder_request(builder_post([field($bad)]))['ok'], "id '$bad' must be rejected");
    }
});

t('builder: duplicate ids rejected', function (): void {
    $r = validate_builder_request(builder_post([field('f_abc123'), field('f_abc123')]));
    assert_true(!$r['ok']);
    assert_match('/more than once/', (string) $r['error']);
});

t('builder: type whitelist enforced, file is allowed', function (): void {
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'password')]))['ok']);
    $r = validate_builder_request(builder_post([field('f_abc123', 'file')]));
    assert_true($r['ok'], (string) $r['error']);
    assert_eq('file', $r['fields'][0]['type']);
});

t('builder: label non-empty, ≤200, trimmed', function (): void {
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'text', ['label' => '  '])]))['ok']);
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'text', ['label' => str_repeat('l', 201)])]))['ok']);
    $r = validate_builder_request(builder_post([field('f_abc123', 'text', ['label' => '  ok  '])]));
    assert_eq('ok', $r['fields'][0]['label']);
});

t('builder: required must be a real bool', function (): void {
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'text', ['required' => 'yes'])]))['ok']);
});

t('builder: select/radio/checkbox need 1–20 non-empty options ≤100 chars', function (): void {
    foreach (['select', 'radio', 'checkbox'] as $type) {
        assert_true(!validate_builder_request(builder_post([field('f_abc123', $type)]))['ok'], "$type without options");
        assert_true(!validate_builder_request(builder_post([field('f_abc123', $type, ['options' => []])]))['ok'], "$type empty options");
    }
    $tooMany = array_map(static fn ($i) => "o$i", range(1, 21));
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'select', ['options' => $tooMany])]))['ok']);
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'select', ['options' => ['ok', '  ']])]))['ok']);
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'select', ['options' => [str_repeat('o', 101)]])]))['ok']);
    $r = validate_builder_request(builder_post([field('f_abc123', 'select', ['options' => [' A ', 'B']])]));
    assert_eq(['A', 'B'], $r['fields'][0]['options'], 'options are trimmed');
});

t('builder: options on a non-option type rejected (incl. file)', function (): void {
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'text', ['options' => ['A']])]))['ok']);
    $r = validate_builder_request(builder_post([field('f_abc123', 'file', ['options' => ['A']])]));
    assert_true(!$r['ok'], 'file cannot carry options');
    assert_match('/cannot have options/', (string) $r['error']);
});

t('builder: rating max int 1–10, defaults to 5, rejected elsewhere', function (): void {
    $r = validate_builder_request(builder_post([field('f_abc123', 'rating')]));
    assert_eq(5, $r['fields'][0]['max'], 'default max');
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'rating', ['max' => 0])]))['ok']);
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'rating', ['max' => 11])]))['ok']);
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'rating', ['max' => '5'])]))['ok'], 'string max rejected');
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'text', ['max' => 5])]))['ok'], 'max on text rejected');
    $ten = validate_builder_request(builder_post([field('f_abc123', 'rating', ['max' => 10])]));
    assert_eq(10, $ten['fields'][0]['max']);
});

t('builder: placeholder only on text-ish fields', function (): void {
    assert_true(!validate_builder_request(builder_post([field('f_abc123', 'select', ['options' => ['A'], 'placeholder' => 'x'])]))['ok']);
    $r = validate_builder_request(builder_post([field('f_abc123', 'email', ['placeholder' => 'you@x.dev'])]));
    assert_eq('you@x.dev', $r['fields'][0]['placeholder']);
});

t('builder: webhook_url must be http(s) and ≤500 chars; empty ⇒ null', function (): void {
    assert_eq(null, validate_builder_request(builder_post([field()], ['webhook_url' => '  ']))['webhook_url']);
    assert_true(!validate_builder_request(builder_post([field()], ['webhook_url' => 'ftp://x']))['ok']);
    assert_true(!validate_builder_request(builder_post([field()], ['webhook_url' => 'http://' . str_repeat('a', 500)]))['ok']);
    $r = validate_builder_request(builder_post([field()], ['webhook_url' => 'https://hooks.example/x']));
    assert_eq('https://hooks.example/x', $r['webhook_url']);
});

t('builder: non-list / invalid JSON rejected', function (): void {
    assert_true(!validate_builder_request(builder_post([], ['fields_json' => '{"id":"f_abc123"}']))['ok']);
    assert_true(!validate_builder_request(builder_post([], ['fields_json' => 'not json']))['ok']);
    assert_true(!validate_builder_request(builder_post([], ['fields_json' => '']))['ok']);
});

// ------------------------------------------------------------- submission ---

t('submit: required text empty ⇒ error; provided ⇒ trimmed into data', function (): void {
    $fields = [field('f_abc123', 'text', ['required' => true])];
    $r = validate_submission($fields, []);
    assert_true(!$r['ok']);
    assert_eq('This field is required.', $r['errors']['f_abc123']);
    $r = validate_submission($fields, ['f_abc123' => '  hi  ']);
    assert_true($r['ok']);
    assert_eq('hi', $r['data']['f_abc123']);
});

t('submit: text answers capped at 5000 chars', function (): void {
    $r = validate_submission([field()], ['f_abc123' => str_repeat('a', 5001)]);
    assert_true(!$r['ok']);
});

t('submit: email format', function (): void {
    $f = [field('f_abc123', 'email')];
    assert_true(!validate_submission($f, ['f_abc123' => 'not-an-email'])['ok']);
    assert_true(validate_submission($f, ['f_abc123' => 'a@b.co'])['ok']);
    assert_true(validate_submission($f, ['f_abc123' => ''])['ok'], 'optional empty email is fine');
});

t('submit: number must be numeric', function (): void {
    $f = [field('f_abc123', 'number')];
    assert_true(!validate_submission($f, ['f_abc123' => 'abc'])['ok']);
    assert_true(validate_submission($f, ['f_abc123' => '4.5'])['ok']);
    assert_true(validate_submission($f, ['f_abc123' => '-3'])['ok']);
});

t('submit: date must be a real YYYY-MM-DD', function (): void {
    $f = [field('f_abc123', 'date')];
    assert_true(!validate_submission($f, ['f_abc123' => '2024-02-30'])['ok'], 'Feb 30 rejected');
    assert_true(!validate_submission($f, ['f_abc123' => '30-02-2024'])['ok']);
    assert_true(validate_submission($f, ['f_abc123' => '2024-02-29'])['ok'], 'leap day accepted');
});

t('submit: select/radio membership in options', function (): void {
    foreach (['select', 'radio'] as $type) {
        $f = [field('f_abc123', $type, ['options' => ['A', 'B']])];
        assert_true(!validate_submission($f, ['f_abc123' => 'C'])['ok'], "$type: C not allowed");
        assert_true(validate_submission($f, ['f_abc123' => 'B'])['ok']);
    }
});

t('submit: checkbox subset of options, dedup, required ⇒ at least one', function (): void {
    $f = [field('f_abc123', 'checkbox', ['options' => ['A', 'B'], 'required' => true])];
    assert_true(!validate_submission($f, ['f_abc123' => ['A', 'C']])['ok'], 'outside option rejected');
    assert_true(!validate_submission($f, ['f_abc123' => []])['ok'], 'required checkbox needs a pick');
    $r = validate_submission($f, ['f_abc123' => ['A', 'A', 'B']]);
    assert_true($r['ok']);
    assert_eq(['A', 'B'], $r['data']['f_abc123'], 'deduped string[] stored');
});

t('submit: rating must be an integer within 1..max', function (): void {
    $f = [field('f_abc123', 'rating', ['max' => 5])];
    assert_true(!validate_submission($f, ['f_abc123' => '0'])['ok']);
    assert_true(!validate_submission($f, ['f_abc123' => '6'])['ok']);
    assert_true(!validate_submission($f, ['f_abc123' => '3.5'])['ok']);
    assert_true(validate_submission($f, ['f_abc123' => '5'])['ok']);
});

t('submit: non-string scalar input is rejected, not coerced', function (): void {
    $r = validate_submission([field()], ['f_abc123' => 42]);
    assert_true(!$r['ok']);
    assert_eq('Invalid value.', $r['errors']['f_abc123']);
});

// --- file widget, driven by synthetic $_FILES['files']-shaped arrays --------
// NOTE: validation hard-requires is_uploaded_file() (validation.php:230),
// which is always false for synthetic CLI files — so the upload HAPPY path
// is e2e-only (tests/e2e.sh stage 3). Every error path is unit-testable.

function files_group(string $id, array $entry): array
{
    $g = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($entry as $k => $v) {
        $g[$k][$id] = $v;
    }
    return $g;
}

t('file: required + UPLOAD_ERR_NO_FILE ⇒ "attach a file" error', function (): void {
    $f = [field('f_abc123', 'file', ['required' => true])];
    $r = validate_submission($f, [], files_group('f_abc123', ['error' => UPLOAD_ERR_NO_FILE]));
    assert_eq('Please attach a file.', $r['errors']['f_abc123']);
});

t('file: optional + no file ⇒ ok, stored value is empty string', function (): void {
    $f = [field('f_abc123', 'file')];
    $r = validate_submission($f, [], []); // no files group at all
    assert_true($r['ok']);
    assert_eq('', $r['data']['f_abc123']);
    assert_eq([], $r['uploads']);
});

t('file: UPLOAD_ERR_INI_SIZE / FORM_SIZE ⇒ too-large error', function (): void {
    $f = [field('f_abc123', 'file')];
    foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $err) {
        $r = validate_submission($f, [], files_group('f_abc123', ['error' => $err]));
        assert_eq('File too large (2 MB max).', $r['errors']['f_abc123']);
    }
});

t('file: size field over 2 MB ⇒ too-large error even with UPLOAD_ERR_OK', function (): void {
    $f = [field('f_abc123', 'file')];
    $r = validate_submission($f, [], files_group('f_abc123', [
        'error' => UPLOAD_ERR_OK, 'size' => RF_UPLOAD_MAX_BYTES + 1, 'name' => 'big.pdf', 'tmp_name' => '/tmp/x',
    ]));
    assert_eq('File too large (2 MB max).', $r['errors']['f_abc123']);
});

t('file: extension whitelist from the client name, case-insensitive', function (): void {
    $f = [field('f_abc123', 'file')];
    $r = validate_submission($f, [], files_group('f_abc123', [
        'error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'evil.exe', 'tmp_name' => '/tmp/x',
    ]));
    assert_match('/File type not allowed/', $r['errors']['f_abc123'] ?? '');
    // An UPPERCASE whitelisted extension must clear the extension gate; it
    // then fails only at the is_uploaded_file() gate (CLI), not the whitelist.
    $r = validate_submission($f, [], files_group('f_abc123', [
        'error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'scan.PDF', 'tmp_name' => '/tmp/does-not-matter',
    ]));
    assert_eq('The upload did not complete — please try again.', $r['errors']['f_abc123']);
});

t('file: other PHP upload errors ⇒ generic retry error', function (): void {
    $f = [field('f_abc123', 'file')];
    $r = validate_submission($f, [], files_group('f_abc123', ['error' => UPLOAD_ERR_PARTIAL]));
    assert_eq('The upload did not complete — please try again.', $r['errors']['f_abc123']);
});

t('file: is_uploaded_file() false (synthetic CLI file) ⇒ error path, never uploads[]', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'rf-upl-');
    file_put_contents($tmp, 'hello');
    $f = [field('f_abc123', 'file', ['required' => true])];
    $r = validate_submission($f, [], files_group('f_abc123', [
        'error' => UPLOAD_ERR_OK, 'size' => 5, 'name' => 'note.txt', 'tmp_name' => $tmp,
    ]));
    unlink($tmp);
    assert_eq('The upload did not complete — please try again.', $r['errors']['f_abc123']);
    assert_eq([], $r['uploads'], 'nothing may reach uploads[] without is_uploaded_file');
});
