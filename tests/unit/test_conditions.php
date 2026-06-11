<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';
require RF_ROOT . '/apps/web/src/conditions.php';

/** A realistic fields array: text + radio + email + textarea + checkbox. */
function cond_fields(): array
{
    return [
        ['id' => 'f_name01', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'f_radio1', 'type' => 'radio', 'label' => 'Contact?', 'required' => true, 'options' => ['Yes', 'No']],
        ['id' => 'f_email1', 'type' => 'email', 'label' => 'Email', 'required' => true],
        ['id' => 'f_whyno1', 'type' => 'textarea', 'label' => 'Why not', 'required' => false],
        ['id' => 'f_check1', 'type' => 'checkbox', 'label' => 'Topics', 'required' => false, 'options' => ['SRE', 'Dev', 'Ops']],
    ];
}

function rule(string $field, string $op, string $value, string $action, string $target): array
{
    return ['if' => ['field' => $field, 'op' => $op, 'value' => $value], 'then' => ['action' => $action, 'target' => $target]];
}

// ----------------------------------------------------------- visibility ---

t('visibility: no rules — everything visible', function (): void {
    $v = conditions_visible_fields(cond_fields(), [], []);
    assert_eq(['f_name01' => true, 'f_radio1' => true, 'f_email1' => true, 'f_whyno1' => true, 'f_check1' => true], $v);
});

t('visibility: show-rule target hidden without an answer, shown on match', function (): void {
    $rules = [rule('f_radio1', 'equals', 'Yes', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $rules, []);
    assert_eq(false, $v['f_email1'], 'no answer => show target hidden');
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_radio1' => 'Yes']);
    assert_eq(true, $v['f_email1']);
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_radio1' => 'No']);
    assert_eq(false, $v['f_email1']);
});

t('visibility: multiple show rules OR together', function (): void {
    $rules = [
        rule('f_radio1', 'equals', 'Yes', 'show', 'f_email1'),
        rule('f_name01', 'equals', 'boss', 'show', 'f_email1'),
    ];
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_radio1' => 'No', 'f_name01' => 'boss']);
    assert_eq(true, $v['f_email1'], 'one matching show rule is enough');
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_radio1' => 'No', 'f_name01' => 'other']);
    assert_eq(false, $v['f_email1'], 'no matching show rule => hidden');
});

t('visibility: hide wins over show', function (): void {
    $rules = [
        rule('f_radio1', 'equals', 'Yes', 'show', 'f_email1'),
        rule('f_name01', 'equals', 'spam', 'hide', 'f_email1'),
    ];
    $answers = ['f_radio1' => 'Yes', 'f_name01' => 'spam'];
    $v = conditions_visible_fields(cond_fields(), $rules, $answers);
    assert_eq(false, $v['f_email1']);
});

t('visibility: non-matching hide rule leaves the default', function (): void {
    $rules = [rule('f_radio1', 'equals', 'No', 'hide', 'f_whyno1')];
    $v = conditions_visible_fields(cond_fields(), $rules, []);
    assert_eq(true, $v['f_whyno1'], 'absent answer: equals never matches => stays visible');
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_radio1' => 'No']);
    assert_eq(false, $v['f_whyno1']);
});

t('visibility: rules never chain — a hidden IF field still drives its rules', function (): void {
    $rules = [
        rule('f_name01', 'equals', 'x', 'hide', 'f_radio1'),
        rule('f_radio1', 'equals', 'Yes', 'show', 'f_email1'),
    ];
    $answers = ['f_name01' => 'x', 'f_radio1' => 'Yes'];
    $v = conditions_visible_fields(cond_fields(), $rules, $answers);
    assert_eq(false, $v['f_radio1']);
    assert_eq(true, $v['f_email1'], 'raw answer of the hidden field still evaluated');
});

t('matching: equals trims the answer, contains is case-insensitive substring', function (): void {
    $rules = [rule('f_name01', 'equals', 'Jane', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_name01' => '  Jane  ']);
    assert_eq(true, $v['f_email1']);

    $rules = [rule('f_name01', 'contains', 'JAN', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_name01' => 'lady jane grey']);
    assert_eq(true, $v['f_email1']);
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_name01' => 'joan']);
    assert_eq(false, $v['f_email1']);
});

t('matching: checkbox arrays — equals/contains match ANY selected option', function (): void {
    $rules = [rule('f_check1', 'equals', 'Dev', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_check1' => ['SRE', 'Dev']]);
    assert_eq(true, $v['f_email1']);
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_check1' => ['SRE', 'Ops']]);
    assert_eq(false, $v['f_email1']);

    $rules = [rule('f_check1', 'contains', 'op', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_check1' => ['Ops']]);
    assert_eq(true, $v['f_email1'], 'case-insensitive substring on any option');
});

t('matching: not_equals on arrays means NO selected option equals the value', function (): void {
    $rules = [rule('f_check1', 'not_equals', 'Dev', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_check1' => ['SRE', 'Ops']]);
    assert_eq(true, $v['f_email1']);
    $v = conditions_visible_fields(cond_fields(), $rules, ['f_check1' => ['SRE', 'Dev']]);
    assert_eq(false, $v['f_email1'], 'one match is enough to defeat not_equals');
});

t('matching: absent/empty answers — equals/contains never match, not_equals matches', function (): void {
    $eq = [rule('f_name01', 'equals', '', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $eq, []);
    assert_eq(false, $v['f_email1'], 'equals never matches an absent answer, even with an empty value');

    $ne = [rule('f_name01', 'not_equals', 'x', 'show', 'f_email1')];
    foreach ([[], ['f_name01' => ''], ['f_name01' => '   '], ['f_check1' => []]] as $answers) {
        $v = conditions_visible_fields(cond_fields(), $ne, $answers);
        assert_eq(true, $v['f_email1'], 'not_equals matches when the answer is absent/empty');
    }

    $contains = [rule('f_check1', 'contains', 'SRE', 'show', 'f_email1')];
    $v = conditions_visible_fields(cond_fields(), $contains, ['f_check1' => []]);
    assert_eq(false, $v['f_email1'], 'empty checkbox array never matches contains');
});

// ----------------------------------------------------------- validation ---

t('validate: NULL / empty / [] are all "no rules"', function (): void {
    foreach ([null, '', '   ', '[]', 'null'] as $json) {
        $r = conditions_validate($json, cond_fields());
        assert_true($r['ok'], var_export($json, true));
        assert_eq([], $r['conditions']);
        assert_eq(null, $r['error']);
    }
});

t('validate: a clean payload round-trips into visibility evaluation', function (): void {
    $rules = [
        rule('f_radio1', 'equals', 'Yes', 'show', 'f_email1'),
        rule('f_radio1', 'equals', 'No', 'show', 'f_whyno1'),
    ];
    $r = conditions_validate(json_encode($rules), cond_fields());
    assert_true($r['ok'], (string) $r['error']);
    assert_eq($rules, $r['conditions'], 'payload survives validation byte-identical');

    $v = conditions_visible_fields(cond_fields(), $r['conditions'], ['f_radio1' => 'No']);
    assert_eq(false, $v['f_email1']);
    assert_eq(true, $v['f_whyno1']);
});

t('validate: bad op / bad action rejected', function (): void {
    $r = conditions_validate(json_encode([rule('f_radio1', 'regex', 'x', 'show', 'f_email1')]), cond_fields());
    assert_true(!$r['ok']);
    assert_match('/unsupported condition/', (string) $r['error']);

    $r = conditions_validate(json_encode([rule('f_radio1', 'equals', 'x', 'disable', 'f_email1')]), cond_fields());
    assert_true(!$r['ok']);
    assert_match('/unsupported action/', (string) $r['error']);
});

t('validate: unknown field / unknown target rejected', function (): void {
    $r = conditions_validate(json_encode([rule('f_zzzzzz', 'equals', 'x', 'show', 'f_email1')]), cond_fields());
    assert_true(!$r['ok']);
    $r = conditions_validate(json_encode([rule('f_radio1', 'equals', 'x', 'show', 'f_zzzzzz')]), cond_fields());
    assert_true(!$r['ok']);
});

t('validate: self-reference rejected', function (): void {
    $r = conditions_validate(json_encode([rule('f_email1', 'equals', 'x', 'hide', 'f_email1')]), cond_fields());
    assert_true(!$r['ok']);
    assert_match('/cannot show\/hide/', (string) $r['error']);
});

t('validate: rule cap at 20', function (): void {
    $many = [];
    for ($i = 0; $i < 21; $i++) {
        $many[] = rule('f_radio1', 'equals', "v$i", 'show', 'f_email1');
    }
    $r = conditions_validate(json_encode($many), cond_fields());
    assert_true(!$r['ok']);
    assert_match('/at most 20/', (string) $r['error']);
    $r = conditions_validate(json_encode(array_slice($many, 0, 20)), cond_fields());
    assert_true($r['ok'], (string) $r['error']);
});

t('validate: value must be a string of at most 200 chars', function (): void {
    $bad = rule('f_radio1', 'equals', '', 'show', 'f_email1');
    $bad['if']['value'] = 42;
    assert_true(!conditions_validate(json_encode([$bad]), cond_fields())['ok'], 'int value rejected');
    $bad['if']['value'] = null;
    assert_true(!conditions_validate(json_encode([$bad]), cond_fields())['ok'], 'null value rejected');

    $long = rule('f_radio1', 'equals', str_repeat('v', 201), 'show', 'f_email1');
    assert_true(!conditions_validate(json_encode([$long]), cond_fields())['ok'], '201-char value rejected');
    $max = rule('f_radio1', 'equals', str_repeat('v', 200), 'show', 'f_email1');
    assert_true(conditions_validate(json_encode([$max]), cond_fields())['ok'], '200-char value allowed');
});

t('validate: shape violations rejected (not a list, extra/missing keys, garbage)', function (): void {
    assert_true(!conditions_validate('{"if":{}}', cond_fields())['ok'], 'object instead of list');
    assert_true(!conditions_validate('not json at all', cond_fields())['ok'], 'garbage');
    assert_true(!conditions_validate('[1,2]', cond_fields())['ok'], 'scalar rules');

    $extra = rule('f_radio1', 'equals', 'x', 'show', 'f_email1');
    $extra['note'] = 'hi';
    assert_true(!conditions_validate(json_encode([$extra]), cond_fields())['ok'], 'extra top-level key');

    $extra = rule('f_radio1', 'equals', 'x', 'show', 'f_email1');
    $extra['if']['case'] = true;
    assert_true(!conditions_validate(json_encode([$extra]), cond_fields())['ok'], 'extra if key');

    $missing = ['if' => ['field' => 'f_radio1', 'op' => 'equals'], 'then' => ['action' => 'show', 'target' => 'f_email1']];
    assert_true(!conditions_validate(json_encode([$missing]), cond_fields())['ok'], 'missing value key');
});

t('validate: oversized raw payload rejected', function (): void {
    // inner padding — the validator trims only the payload's edges
    $raw = '[' . str_repeat(' ', 16385) . ']';
    $r = conditions_validate($raw, cond_fields());
    assert_true(!$r['ok']);
    assert_match('/16 KB/', (string) $r['error']);
});
