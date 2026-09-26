<?php

namespace FabricatorForms\Tests\Support;

/**
 * Builders for hand-made PDFs and real mPDF output. Everything is generated in code, so the repository holds no
 * binary fixtures and every hostile shape is visible where it is used.
 */
final class PdfFixtures
{
    /**
     * A structurally valid PDF with a correct xref table.
     *
     * @param array<int, string> $objects Object number => body (between "N 0 obj" and "endobj").
     */
    public static function build(array $objects): string
    {
        $out     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out          .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $size = max(array_keys($objects)) + 1;
        $xref = strlen($out);
        $out .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        return $out . "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /**
     * A stream object body with a correct /Length.
     */
    public static function stream(string $dict, string $data): string
    {
        return "<< $dict /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    /**
     * Objects 1–5 of a one-page document whose text is "Hello Guard"; object 3's resources list $xobjects.
     *
     * @return array<int, string>
     */
    public static function page(string $xobjects = ''): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >>'
                . ' /XObject << ' . $xobjects . ' >> >> >>',
            4 => self::stream('/Filter /FlateDecode', gzcompress('BT /F1 12 Tf 72 712 Td (Hello Guard) Tj ET')),
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
    }

    /**
     * A throwaway directory under the system temp dir, removed again by removeTree().
     */
    public static function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return $dir;
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /**
     * An mPDF instance writing its temp files into $temp_dir (never into vendor/).
     */
    public static function mpdf(string $temp_dir): \Mpdf\Mpdf
    {
        return new \Mpdf\Mpdf(['tempDir' => $temp_dir]);
    }
}
