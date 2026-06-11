<?php

declare(strict_types=1);

require __DIR__ . '/_harness.php';

// One document for every assertion: 2+ pages, parens/backslash/emoji content.
$pdf = new Pdf();
$pdf->addPage();
$pdf->heading('Unit test (heading) with \\ backslash');
$pdf->keyValue('Key (with parens)', 'Value with \\ backslash and (nested (parens))');
$pdf->text('Emoji content: 🙂 and accents: café');
for ($i = 0; $i < 60; $i++) { // force an automatic page break (A4 fits ~48 body lines)
    $pdf->text("Filler line $i to overflow the first page onto a second one.");
}
$doc = $pdf->render();

t('starts with the %PDF-1.4 header + binary marker line', function () use ($doc): void {
    assert_true(str_starts_with($doc, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"));
});

t('ends with %%EOF', function () use ($doc): void {
    assert_match('/%%EOF\n$/', $doc);
});

t('document has 2+ pages and a matching /Count', function () use ($doc): void {
    $pages = preg_match_all('/<< \/Type \/Page \/Parent/', $doc);
    assert_true($pages >= 2, "expected >=2 pages, got $pages");
    assert_match('/\/Count ' . $pages . ' >>/', $doc);
});

t('xref offsets are byte-exact onto "N 0 obj"', function () use ($doc): void {
    assert_true(preg_match('/\nxref\n0 (\d+)\n/', $doc, $m, PREG_OFFSET_CAPTURE) === 1, 'xref section present');
    $count = (int) $m[1][0];
    $entries = $m[0][1] + strlen($m[0][0]); // first byte of the entry table
    assert_eq('0000000000 65535 f ', substr($doc, $entries, 19), 'free entry first');
    for ($n = 1; $n < $count; $n++) {
        $entry = substr($doc, $entries + 20 * $n, 20); // each entry exactly 20 bytes
        assert_match('/^\d{10} 00000 n \n$/', $entry, "entry $n shape");
        $off = (int) substr($entry, 0, 10);
        assert_eq("$n 0 obj", substr($doc, $off, strlen("$n 0 obj")), "offset of object $n");
    }
});

t('startxref points at the xref keyword', function () use ($doc): void {
    assert_true(preg_match('/startxref\n(\d+)\n%%EOF/', $doc, $m) === 1);
    assert_eq('xref', substr($doc, (int) $m[1], 4));
});

t('every stream /Length matches the exact stream byte count', function () use ($doc): void {
    $n = preg_match_all('/<< \/Length (\d+) >>\nstream\n(.*?)endstream/s', $doc, $m, PREG_SET_ORDER);
    assert_true($n >= 2, 'one content stream per page');
    foreach ($m as $i => $hit) {
        assert_eq((int) $hit[1], strlen($hit[2]), "stream $i length");
    }
});

t('parens and backslashes are escaped inside literal strings', function () use ($doc): void {
    assert_true(str_contains($doc, '\\(heading\\)'), 'escaped parens');
    assert_true(str_contains($doc, '\\(nested \\(parens\\)\\)'), 'nested parens escaped');
    assert_true(str_contains($doc, 'with \\\\ backslash'), 'escaped backslash');
});

t('no raw multibyte leaks into the output (WinAnsi only)', function () use ($doc): void {
    assert_true(!str_contains($doc, "\xF0\x9F\x99\x82"), 'UTF-8 emoji bytes must not appear');
    assert_true(!str_contains($doc, "caf\xC3\xA9"), 'UTF-8 é must be transliterated to CP1252');
    assert_true(str_contains($doc, 'Emoji content: ?'), 'emoji replaced with ?');
    // Every content stream is pure single-byte text: BT/Tj operators + WinAnsi.
    preg_match_all('/\nstream\n(.*?)endstream/s', $doc, $m);
    foreach ($m[1] as $i => $stream) {
        assert_true(
            preg_match('/[^\x09\x0A\x0D\x20-\xFF]/', $stream) !== 1,
            "stream $i contains control bytes"
        );
        assert_true(!str_contains($stream, "\xC3"), "stream $i leaks UTF-8 lead bytes");
    }
});

t('empty document still renders one valid page', function (): void {
    $empty = (new Pdf())->render();
    assert_true(str_starts_with($empty, '%PDF-1.4'));
    assert_match('/\/Count 1 >>/', $empty);
    assert_match('/%%EOF\n$/', $empty);
});
