<?php

declare(strict_types=1);

/**
 * Server-side validation for the builder payload (strict, per ARCHITECTURE.md
 * "Ownership checks") and for public form submissions.
 */

const RF_FIELD_TYPES = ['text', 'textarea', 'email', 'number', 'select', 'radio', 'checkbox', 'date', 'rating', 'file'];
const RF_OPTION_FIELD_TYPES = ['select', 'radio', 'checkbox'];
const RF_PLACEHOLDER_FIELD_TYPES = ['text', 'textarea', 'email', 'number'];

// `file` widget contract (ARCHITECTURE.md "forms.fields JSON"): ≤2 MB,
// extension whitelist (from the CLIENT name, lowercased), write-only storage.
const RF_UPLOAD_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'csv', 'zip'];
const RF_UPLOAD_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Validate the whole builder POST (title, description, fields_json, webhook_url).
 * Rejects on any violation — never silently "fixes" the payload.
 *
 * @return array{ok: bool, error: ?string, title: string, description: ?string, fields: ?array, webhook_url: ?string}
 */
function validate_builder_request(array $post): array
{
    $fail = static fn (string $error): array => [
        'ok' => false, 'error' => $error, 'title' => '', 'description' => null, 'fields' => null, 'webhook_url' => null,
    ];

    $title = $post['title'] ?? '';
    if (!is_string($title)) {
        return $fail('Form title is invalid.');
    }
    $title = trim($title);
    if ($title === '' || mb_strlen($title) > 200) {
        return $fail('Give your form a title (1–200 characters).');
    }

    $description = $post['description'] ?? '';
    if (!is_string($description)) {
        return $fail('Form description is invalid.');
    }
    $description = trim($description);
    if (mb_strlen($description) > 2000) {
        return $fail('Description is too long (2000 characters max).');
    }

    // Integrations: webhook_url is optional; empty ⇒ NULL (webhook disabled).
    $webhookUrl = $post['webhook_url'] ?? '';
    if (!is_string($webhookUrl)) {
        return $fail('Webhook URL is invalid.');
    }
    $webhookUrl = trim($webhookUrl);
    if ($webhookUrl === '') {
        $webhookUrl = null;
    } elseif (preg_match('#^https?://#', $webhookUrl) !== 1 || strlen($webhookUrl) > 500) {
        return $fail('Webhook URL must start with http:// or https:// and be at most 500 characters.');
    }

    $raw = $post['fields_json'] ?? '';
    if (!is_string($raw) || $raw === '') {
        return $fail('No fields were submitted — add at least one field.');
    }
    if (strlen($raw) > 65536) {
        return $fail('The form definition is too large (64 KB max).');
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return $fail('The form definition is not a valid field list.');
    }
    if (count($decoded) < 1 || count($decoded) > 50) {
        return $fail('A form must have between 1 and 50 fields.');
    }

    $fields = [];
    $seenIds = [];
    foreach ($decoded as $i => $rawField) {
        $n = $i + 1;
        if (!is_array($rawField)) {
            return $fail("Field #{$n} is malformed.");
        }

        $id = $rawField['id'] ?? null;
        if (!is_string($id) || preg_match('/^f_[a-z0-9]{6}$/', $id) !== 1) {
            return $fail("Field #{$n} has an invalid id.");
        }
        if (isset($seenIds[$id])) {
            return $fail("Field id \"{$id}\" is used more than once.");
        }
        $seenIds[$id] = true;

        $type = $rawField['type'] ?? null;
        if (!is_string($type) || !in_array($type, RF_FIELD_TYPES, true)) {
            return $fail("Field #{$n} has an unsupported type.");
        }

        $label = $rawField['label'] ?? null;
        if (!is_string($label)) {
            return $fail("Field #{$n} is missing a label.");
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 200) {
            return $fail("Field #{$n} needs a label between 1 and 200 characters.");
        }

        $required = $rawField['required'] ?? false;
        if (!is_bool($required)) {
            return $fail("Field #{$n} has an invalid required flag.");
        }

        $field = ['id' => $id, 'type' => $type, 'label' => $label, 'required' => $required];

        $placeholder = $rawField['placeholder'] ?? null;
        if ($placeholder !== null && $placeholder !== '') {
            if (!in_array($type, RF_PLACEHOLDER_FIELD_TYPES, true)) {
                return $fail("Field #{$n} ({$type}) cannot have a placeholder.");
            }
            if (!is_string($placeholder) || mb_strlen($placeholder) > 200) {
                return $fail("Field #{$n} has an invalid placeholder (200 characters max).");
            }
            $field['placeholder'] = $placeholder;
        }

        if (in_array($type, RF_OPTION_FIELD_TYPES, true)) {
            $options = $rawField['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || count($options) < 1 || count($options) > 20) {
                return $fail("Field #{$n} needs between 1 and 20 options.");
            }
            $cleanOptions = [];
            foreach ($options as $option) {
                if (!is_string($option)) {
                    return $fail("Field #{$n} has a malformed option.");
                }
                $option = trim($option);
                if ($option === '' || mb_strlen($option) > 100) {
                    return $fail("Field #{$n}: every option needs 1–100 characters.");
                }
                $cleanOptions[] = $option;
            }
            $field['options'] = $cleanOptions;
        } elseif (!empty($rawField['options'])) {
            return $fail("Field #{$n} ({$type}) cannot have options.");
        }

        if ($type === 'rating') {
            $max = $rawField['max'] ?? 5;
            if (!is_int($max) || $max < 1 || $max > 10) {
                return $fail("Field #{$n}: rating max must be a whole number from 1 to 10.");
            }
            $field['max'] = $max;
        } elseif (isset($rawField['max'])) {
            return $fail("Field #{$n} ({$type}) cannot have a rating max.");
        }

        $fields[] = $field;
    }

    return [
        'ok' => true,
        'error' => null,
        'title' => $title,
        'description' => $description === '' ? null : $description,
        'fields' => $fields,
        'webhook_url' => $webhookUrl,
    ];
}

/** Best-effort decode of a (possibly invalid) fields_json for redisplaying the builder. */
function decode_fields_for_redisplay(string $raw): array
{
    $decoded = json_decode($raw, true);
    return (is_array($decoded) && array_is_list($decoded)) ? $decoded : [];
}

/**
 * Validate a public submission against the form's field definitions.
 * Returns normalized data (string per field; string[] for checkbox) plus
 * per-field error messages.
 *
 * $files is the raw $_FILES['files'] group (name/type/tmp_name/error/size maps
 * keyed by field id) for `file` widgets. Validation NEVER moves an upload —
 * `uploads` lists the tmp files that passed so the caller can store them only
 * once the WHOLE submission is valid (PHP cleans untouched tmp files itself).
 *
 * @return array{ok: bool, errors: array<string, string>, data: array<string, mixed>, uploads: array<string, array{tmp_name: string, ext: string}>}
 */
function validate_submission(array $fields, array $input, array $files = []): array
{
    $errors = [];
    $data = [];
    $uploads = [];

    foreach ($fields as $field) {
        $id = (string) ($field['id'] ?? '');
        $type = (string) ($field['type'] ?? 'text');
        $required = !empty($field['required']);

        if ($type === 'file') {
            $data[$id] = ''; // becomes the stored relative path after move_uploaded_file
            $error = $files['error'][$id] ?? UPLOAD_ERR_NO_FILE;
            $error = is_int($error) ? $error : UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                if ($required) {
                    $errors[$id] = 'Please attach a file.';
                }
                continue;
            }
            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                $errors[$id] = 'File too large (2 MB max).';
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors[$id] = 'The upload did not complete — please try again.';
                continue;
            }
            $size = $files['size'][$id] ?? 0;
            if (!is_int($size) || $size > RF_UPLOAD_MAX_BYTES) {
                $errors[$id] = 'File too large (2 MB max).';
                continue;
            }
            $clientName = $files['name'][$id] ?? '';
            $ext = is_string($clientName) ? strtolower(pathinfo($clientName, PATHINFO_EXTENSION)) : '';
            if (!in_array($ext, RF_UPLOAD_EXTENSIONS, true)) {
                $errors[$id] = 'File type not allowed (' . implode(', ', RF_UPLOAD_EXTENSIONS) . ').';
                continue;
            }
            $tmpName = $files['tmp_name'][$id] ?? '';
            if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
                $errors[$id] = 'The upload did not complete — please try again.';
                continue;
            }
            $uploads[$id] = ['tmp_name' => $tmpName, 'ext' => $ext];
            continue;
        }

        if ($type === 'checkbox') {
            $raw = $input[$id] ?? [];
            if (!is_array($raw)) {
                $raw = [$raw];
            }
            $allowed = is_array($field['options'] ?? null) ? $field['options'] : [];
            $values = [];
            foreach ($raw as $v) {
                if (!is_string($v) || !in_array($v, $allowed, true)) {
                    $errors[$id] = 'Please pick only from the listed options.';
                    continue 2;
                }
                $values[] = $v;
            }
            $values = array_values(array_unique($values));
            $data[$id] = $values;
            if ($required && $values === []) {
                $errors[$id] = 'Please select at least one option.';
            }
            continue;
        }

        $raw = $input[$id] ?? '';
        if (!is_string($raw)) {
            $errors[$id] = 'Invalid value.';
            $data[$id] = '';
            continue;
        }
        $value = trim($raw);
        $data[$id] = $value;

        if ($value === '') {
            if ($required) {
                $errors[$id] = 'This field is required.';
            }
            continue;
        }

        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$id] = 'Enter a valid email address.';
                }
                break;
            case 'number':
                if (!is_numeric($value)) {
                    $errors[$id] = 'Enter a number.';
                }
                break;
            case 'date':
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1
                    || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    $errors[$id] = 'Enter a real date in YYYY-MM-DD format.';
                }
                break;
            case 'select':
            case 'radio':
                $allowed = is_array($field['options'] ?? null) ? $field['options'] : [];
                if (!in_array($value, $allowed, true)) {
                    $errors[$id] = 'Pick one of the listed options.';
                }
                break;
            case 'rating':
                $max = (int) ($field['max'] ?? 5);
                if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
                    $errors[$id] = 'Pick a rating between 1 and ' . $max . '.';
                }
                break;
            default: // text, textarea — sanity cap so a single answer can't balloon the row
                if (mb_strlen($value) > 5000) {
                    $errors[$id] = 'Answer is too long (5000 characters max).';
                }
        }
    }

    return ['ok' => $errors === [], 'errors' => $errors, 'data' => $data, 'uploads' => $uploads];
}
