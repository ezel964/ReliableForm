<?php

declare(strict_types=1);

/**
 * Conditional logic — the single source of truth for forms.conditions
 * (ARCHITECTURE.md "Stage 2 product surface"). The public page's JS in
 * assets/js/app.js mirrors conditions_visible_fields() exactly; change the
 * semantics in BOTH places or not at all.
 *
 * Rule shape: {"if":{"field","op","value"},"then":{"action","target"}} —
 * max 20 rules, NULL/[] means none.
 */

const RF_CONDITION_OPS = ['equals', 'not_equals', 'contains'];
const RF_CONDITION_ACTIONS = ['show', 'hide'];
const RF_CONDITION_MAX_RULES = 20;

/**
 * Parse + validate a conditions_json payload against the form's (already
 * validated) fields. Rejects the whole payload on any violation — never
 * silently "fixes" it, same stance as validate_builder_request.
 *
 * @param list<array<string, mixed>> $fields
 * @return array{ok: bool, conditions: array, error: ?string}
 */
function conditions_validate(?string $json, array $fields): array
{
    $fail = static fn (string $error): array => ['ok' => false, 'conditions' => [], 'error' => $error];

    $json = $json === null ? '' : trim($json);
    if ($json === '' || $json === '[]' || $json === 'null') {
        return ['ok' => true, 'conditions' => [], 'error' => null];
    }
    if (strlen($json) > 16384) {
        return $fail('The logic rules are too large (16 KB max).');
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return $fail('The logic rules are not a valid rule list.');
    }
    if (count($decoded) > RF_CONDITION_MAX_RULES) {
        return $fail('A form can have at most ' . RF_CONDITION_MAX_RULES . ' logic rules.');
    }

    $fieldIds = [];
    foreach ($fields as $field) {
        if (is_array($field) && is_string($field['id'] ?? null)) {
            $fieldIds[$field['id']] = true;
        }
    }

    $conditions = [];
    foreach ($decoded as $i => $rule) {
        $n = $i + 1;
        // Exact key sets only — unknown keys mean a payload we did not design.
        if (!is_array($rule) || array_keys($rule) !== ['if', 'then']
            || !is_array($rule['if']) || array_keys($rule['if']) !== ['field', 'op', 'value']
            || !is_array($rule['then']) || array_keys($rule['then']) !== ['action', 'target']) {
            return $fail("Logic rule #{$n} is malformed.");
        }

        $field = $rule['if']['field'];
        $target = $rule['then']['target'];
        if (!is_string($field) || !isset($fieldIds[$field])) {
            return $fail("Logic rule #{$n} refers to a field that is not on this form.");
        }
        if (!is_string($target) || !isset($fieldIds[$target])) {
            return $fail("Logic rule #{$n} targets a field that is not on this form.");
        }
        if ($field === $target) {
            return $fail("Logic rule #{$n} cannot show/hide the field it watches.");
        }

        $op = $rule['if']['op'];
        if (!is_string($op) || !in_array($op, RF_CONDITION_OPS, true)) {
            return $fail("Logic rule #{$n} has an unsupported condition.");
        }
        $action = $rule['then']['action'];
        if (!is_string($action) || !in_array($action, RF_CONDITION_ACTIONS, true)) {
            return $fail("Logic rule #{$n} has an unsupported action.");
        }

        $value = $rule['if']['value'];
        if (!is_string($value) || mb_strlen($value) > 200) {
            return $fail("Logic rule #{$n} needs a text value of at most 200 characters.");
        }

        $conditions[] = [
            'if' => ['field' => $field, 'op' => $op, 'value' => $value],
            'then' => ['action' => $action, 'target' => $target],
        ];
    }

    return ['ok' => true, 'conditions' => $conditions, 'error' => null];
}

/**
 * Which fields are visible given the RAW submitted answers?
 *
 * Semantics (deterministic, single pass — mirrored in assets/js/app.js):
 * - every field defaults to visible;
 * - a target with ≥1 `show` rule is visible only if at least one of its
 *   show-rules matches (no answer ⇒ hidden);
 * - any matching `hide` rule hides its target — hide wins over show;
 * - rules never chain: visibility of the IF field does not gate rule
 *   evaluation (answers of hidden fields are dropped before storage anyway).
 *
 * @param list<array<string, mixed>> $fields
 * @param array<int, array{if: array{field: string, op: string, value: string}, then: array{action: string, target: string}}> $conditions
 * @param array<string, mixed> $answers raw submitted answers (string, or string[] for checkbox)
 * @return array<string, bool> field id => visible
 */
function conditions_visible_fields(array $fields, array $conditions, array $answers): array
{
    $visible = [];
    foreach ($fields as $field) {
        if (is_array($field) && is_string($field['id'] ?? null)) {
            $visible[$field['id']] = true;
        }
    }

    $hasShowRule = [];
    $shown = [];
    $hidden = [];
    foreach ($conditions as $rule) {
        if (!is_array($rule) || !is_array($rule['if'] ?? null) || !is_array($rule['then'] ?? null)) {
            continue; // stored JSON is validated; tolerate hand-edited rows
        }
        $target = (string) ($rule['then']['target'] ?? '');
        $action = (string) ($rule['then']['action'] ?? '');
        $matches = conditions_rule_matches($rule['if'], $answers);
        if ($action === 'show') {
            $hasShowRule[$target] = true;
            if ($matches) {
                $shown[$target] = true;
            }
        } elseif ($action === 'hide' && $matches) {
            $hidden[$target] = true;
        }
    }

    foreach ($visible as $id => $_) {
        if ((isset($hasShowRule[$id]) && !isset($shown[$id])) || isset($hidden[$id])) {
            $visible[$id] = false;
        }
    }
    return $visible;
}

/**
 * Does one rule's IF clause match the raw answers? Checkbox answers (arrays):
 * equals/contains match ANY selected option; not_equals means NO selected
 * option equals the value. Absent/empty answer: equals/contains never match,
 * not_equals matches. equals compares after trimming the answer; contains is
 * a case-insensitive substring test.
 *
 * @param array<string, mixed> $if
 * @param array<string, mixed> $answers
 */
function conditions_rule_matches(array $if, array $answers): bool
{
    $op = (string) ($if['op'] ?? '');
    $value = (string) ($if['value'] ?? '');
    $answer = $answers[(string) ($if['field'] ?? '')] ?? null;

    $selected = [];
    foreach (is_array($answer) ? $answer : [$answer] as $v) {
        if (is_scalar($v)) {
            $v = trim((string) $v);
            if ($v !== '') {
                $selected[] = $v;
            }
        }
    }

    if ($selected === []) {
        return $op === 'not_equals';
    }
    switch ($op) {
        case 'equals':
            return in_array($value, $selected, true);
        case 'not_equals':
            return !in_array($value, $selected, true);
        case 'contains':
            foreach ($selected as $v) {
                if (mb_stripos($v, $value) !== false) {
                    return true;
                }
            }
            return false;
    }
    return false; // unknown op never matches (validated away at save time)
}

/** Best-effort decode of a (possibly invalid) conditions_json for redisplaying the builder. */
function decode_conditions_for_redisplay(string $raw): array
{
    $decoded = json_decode($raw, true);
    return (is_array($decoded) && array_is_list($decoded)) ? $decoded : [];
}
