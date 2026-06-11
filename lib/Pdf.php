<?php

declare(strict_types=1);

/**
 * Minimal dependency-free PDF 1.4 writer.
 *
 * A4 portrait, built-in Helvetica / Helvetica-Bold (base-14, WinAnsi encoding,
 * no font embedding), uncompressed content streams, single-section xref table
 * with correct byte offsets. Good enough for Preview/Chrome/Acrobat.
 */
final class Pdf
{
    private const PAGE_WIDTH = 595.0;  // A4 in points
    private const PAGE_HEIGHT = 842.0;
    private const MARGIN = 56.0;

    private const FONT_REGULAR = 'F1'; // Helvetica
    private const FONT_BOLD = 'F2';    // Helvetica-Bold

    private const BODY_SIZE = 11.0;
    private const BODY_LEADING = 15.0;
    private const HEADING_SIZE = 18.0;
    private const HEADING_LEADING = 24.0;

    // Rough average glyph width as a fraction of the font size; Helvetica
    // mixed text averages ~0.5em. Used for the simple word-wrap estimate.
    private const AVG_CHAR_WIDTH = 0.5;

    /** @var list<string> one content stream per page */
    private array $pages = [];

    /** Cursor: distance from the page bottom to the next baseline area. */
    private float $y = 0.0;

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->y = self::PAGE_HEIGHT - self::MARGIN;
    }

    public function heading(string $t): void
    {
        if ($this->pages !== [] && $this->y < self::PAGE_HEIGHT - self::MARGIN) {
            $this->y -= 10.0; // breathing room above a mid-page heading
        }
        $this->writeWrapped($t, self::FONT_BOLD, self::HEADING_SIZE, self::HEADING_LEADING);
        $this->y -= 8.0; // extra space below headings
    }

    public function text(string $t): void
    {
        $this->writeWrapped($t, self::FONT_REGULAR, self::BODY_SIZE, self::BODY_LEADING);
    }

    public function keyValue(string $k, string $v): void
    {
        $this->writeWrapped($k, self::FONT_BOLD, self::BODY_SIZE, self::BODY_LEADING);
        $this->writeWrapped($v, self::FONT_REGULAR, self::BODY_SIZE, self::BODY_LEADING);
        $this->y -= 6.0; // gap between key/value pairs
    }

    public function render(): string
    {
        $pages = $this->pages === [] ? [''] : $this->pages;
        $pageCount = count($pages);

        // Object layout: 1 catalog, 2 page tree, 3 + 4 fonts, then for page i
        // (0-based): 5+2i = page dict, 6+2i = its content stream. Numbers are
        // contiguous, so a single xref section covers everything.
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($pages as $i => $content) {
            $pageNum = 5 + 2 * $i;
            $streamNum = $pageNum + 1;
            $kids[] = $pageNum . ' 0 R';
            $objects[$pageNum] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                (int) self::PAGE_WIDTH,
                (int) self::PAGE_HEIGHT,
                $streamNum
            );
            $objects[$streamNum] = sprintf(
                "<< /Length %d >>\nstream\n%sendstream",
                strlen($content),
                $content
            );
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), $pageCount);
        ksort($objects);

        // Second line is a high-bit binary marker so transports treat the file as binary.
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out); // byte offset of "<num> 0 obj" for the xref table
            $out .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $maxObj = array_key_last($offsets);
        $xrefPos = strlen($out);
        $out .= "xref\n0 " . ($maxObj + 1) . "\n";
        $out .= "0000000000 65535 f \n"; // each xref entry is exactly 20 bytes
        for ($n = 1; $n <= $maxObj; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $out .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\n";
        $out .= "startxref\n" . $xrefPos . "\n%%EOF\n";

        return $out;
    }

    private function writeWrapped(string $text, string $font, float $size, float $leading): void
    {
        $ansi = self::toWinAnsi($text);
        $contentWidth = self::PAGE_WIDTH - 2 * self::MARGIN;
        $maxChars = max(8, (int) floor($contentWidth / ($size * self::AVG_CHAR_WIDTH)));
        foreach (explode("\n", wordwrap($ansi, $maxChars, "\n", true)) as $line) {
            $this->writeLine($line, $font, $size, $leading);
        }
    }

    private function writeLine(string $line, string $font, float $size, float $leading): void
    {
        if ($this->pages === [] || $this->y - $leading < self::MARGIN) {
            $this->addPage(); // automatic page break
        }
        $this->y -= $leading;
        $this->pages[array_key_last($this->pages)] .= sprintf(
            "BT /%s %s Tf 1 0 0 1 %s %s Tm (%s) Tj ET\n",
            $font,
            self::num($size),
            self::num(self::MARGIN),
            self::num($this->y),
            self::escape($line)
        );
    }

    /** Locale-independent decimal formatting (sprintf %f honours LC_NUMERIC). */
    private static function num(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    /** Escape the three characters with meaning inside PDF literal strings. */
    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }

    /**
     * Base-14 fonts only cover WinAnsi (CP1252): transliterate what we can,
     * replace the rest with '?'. iconv //TRANSLIT aborts whole-string on some
     * builds, hence the per-character fallback.
     */
    private static function toWinAnsi(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
        if ($out !== false) {
            return $out;
        }
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            // Not valid UTF-8 at all: keep printable ASCII, mask the rest.
            return (string) preg_replace('/[^\x20-\x7E\n]/', '?', $text);
        }
        $out = '';
        foreach ($chars as $ch) {
            $c = @iconv('UTF-8', 'CP1252//TRANSLIT', $ch);
            $out .= ($c === false || $c === '') ? '?' : $c;
        }
        return $out;
    }
}
