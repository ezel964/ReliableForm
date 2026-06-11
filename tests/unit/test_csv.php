<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';
require RF_ROOT . '/apps/web/src/csv.php';

t('csv_cell: plain values pass through unchanged', function (): void {
    assert_eq('hello', csv_cell('hello'));
    assert_eq('', csv_cell(''));
    assert_eq('a b c', csv_cell('a b c'));
});

t('csv_cell: comma triggers RFC-4180 quoting', function (): void {
    assert_eq('"a,b"', csv_cell('a,b'));
});

t('csv_cell: inner quotes are doubled and the cell wrapped', function (): void {
    assert_eq('"say ""hi"""', csv_cell('say "hi"'));
});

t('csv_cell: newlines and CR force quoting', function (): void {
    assert_eq("\"line1\nline2\"", csv_cell("line1\nline2"));
    assert_eq("\"a\r\nb\"", csv_cell("a\r\nb"));
});

t('csv_cell: formula-injection guard on = + - @', function (): void {
    assert_eq("'=SUM(A1:A9)", csv_cell('=SUM(A1:A9)'));
    assert_eq("'+1234", csv_cell('+1234'));
    assert_eq("'-42", csv_cell('-42'));
    assert_eq("'@cmd", csv_cell('@cmd'));
});

t('csv_cell: guard chars mid-string are untouched', function (): void {
    assert_eq('a=b', csv_cell('a=b'));
    assert_eq('x @home', csv_cell('x @home'));
});

t('csv_cell: guarded AND quoted when both apply', function (): void {
    assert_eq("\"'=a,b\"", csv_cell('=a,b'));
});

t('csv_answer: checkbox arrays joined with "; "', function (): void {
    assert_eq('A; B; C', csv_answer(['A', 'B', 'C']));
    assert_eq('only', csv_answer(['only']));
    assert_eq('', csv_answer([]));
});

t('csv_answer: non-scalar array members flatten to empty', function (): void {
    assert_eq('A; ; B', csv_answer(['A', ['nested'], 'B']));
});

t('csv_answer: scalars stringified, missing/null ⇒ empty string', function (): void {
    assert_eq('x', csv_answer('x'));
    assert_eq('42', csv_answer(42));
    assert_eq('1', csv_answer(true));
    assert_eq('', csv_answer(null));
    assert_eq('', csv_answer(new stdClass()));
});
