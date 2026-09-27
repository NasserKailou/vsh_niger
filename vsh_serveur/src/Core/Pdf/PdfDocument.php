<?php

declare(strict_types=1);

namespace Vsh\Core\Pdf;

/**
 * Générateur PDF minimal, sans dépendance (la production n'installe pas Composer) : pages A4, polices
 * standard Helvetica (normale, grasse, oblique) en codage WinAnsi (accents français), texte, retour à la
 * ligne mesuré, traits, rectangles et couleurs. Coordonnées en points, origine EN HAUT à gauche
 * (converties à l'écriture), ce qui simplifie la mise en page des documents.
 */
final class PdfDocument
{
    public const WIDTH = 595.28;
    public const HEIGHT = 841.89;

    private const FONTS = ['regular' => 'F1', 'bold' => 'F2', 'italic' => 'F3'];
    private const BASE_FONTS = ['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold', 'F3' => 'Helvetica-Oblique'];

    /** Chasses Adobe (AFM) des caractères 32 à 126, en millièmes de corps. */
    private const WIDTHS_REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556,
        556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];
    private const WIDTHS_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611,
        611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];
    /** Caractères WinAnsi hors ASCII : chasse explicite (sinon celle de la lettre de base). */
    private const WIDTHS_SPECIAL = [
        "\x80" => 556, "\x85" => 1000, "\x91" => 222, "\x92" => 222, "\x93" => 333, "\x94" => 333, "\x95" => 350,
        "\x96" => 556, "\x97" => 1000, "\xA0" => 278, "\xAB" => 556, "\xB0" => 400, "\xB7" => 278, "\xBB" => 556,
    ];

    /** @var string[] Flux de contenu, une entrée par page */
    private $pages = [];

    /** @var int */
    private $current = -1;

    /** @var string */
    private $title;

    /** @var string */
    private $author;

    public function __construct(string $title = '', string $author = '')
    {
        $this->title = $title;
        $this->author = $author;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Écrit sur une page déjà créée (numérotation « page x / n » en fin de document). */
    public function onPage(int $index): void
    {
        $this->current = $index;
    }

    /**
     * @param float[]|null $rgb Couleur de 0 à 1, noir par défaut
     */
    public function text(float $x, float $y, string $text, float $size = 10.0, string $font = 'regular', ?array $rgb = null): void
    {
        $encoded = self::encode($text);
        if ($encoded === '') {
            return;
        }
        $this->write(sprintf(
            "BT %s /%s %s Tf %s %s Td (%s) Tj ET\n",
            self::fill($rgb ?? [0, 0, 0]),
            self::FONTS[$font] ?? 'F1',
            self::num($size),
            self::num($x),
            self::num(self::HEIGHT - $y),
            self::escape($encoded)
        ));
    }

    public function textRight(float $right, float $y, string $text, float $size = 10.0, string $font = 'regular', ?array $rgb = null): void
    {
        $this->text($right - $this->width($text, $size, $font), $y, $text, $size, $font, $rgb);
    }

    public function textCenter(float $center, float $y, string $text, float $size = 10.0, string $font = 'regular', ?array $rgb = null): void
    {
        $this->text($center - $this->width($text, $size, $font) / 2, $y, $text, $size, $font, $rgb);
    }

    /**
     * Texte sur plusieurs lignes dans une largeur donnée.
     *
     * @return float Ordonnée sous la dernière ligne
     */
    public function paragraph(float $x, float $y, float $width, string $text, float $size = 10.0, string $font = 'regular', ?array $rgb = null, float $leading = 1.35): float
    {
        foreach ($this->wrap($text, $width, $size, $font) as $line) {
            $this->text($x, $y, $line, $size, $font, $rgb);
            $y += $size * $leading;
        }
        return $y;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, ?array $rgb = null): void
    {
        $this->write(sprintf(
            "%s %s w %s %s m %s %s l S\n",
            self::stroke($rgb ?? [0, 0, 0]),
            self::num($width),
            self::num($x1),
            self::num(self::HEIGHT - $y1),
            self::num($x2),
            self::num(self::HEIGHT - $y2)
        ));
    }

    /**
     * @param float[]|null $fill   Couleur de fond, null pour aucune
     * @param float[]|null $stroke Couleur du contour, null pour aucun
     */
    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lineWidth = 0.5): void
    {
        $operator = $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->write(sprintf(
            "%s%s%s w %s %s %s %s re %s\n",
            $fill !== null ? self::fill($fill) . ' ' : '',
            $stroke !== null ? self::stroke($stroke) . ' ' : '',
            self::num($lineWidth),
            self::num($x),
            self::num(self::HEIGHT - $y - $h),
            self::num($w),
            self::num($h),
            $operator
        ));
    }

    /** Largeur d'un texte en points. */
    public function width(string $text, float $size = 10.0, string $font = 'regular'): float
    {
        $table = $font === 'bold' ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR;
        $encoded = self::encode($text);
        $total = 0;
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $char = $encoded[$i];
            $code = ord($char);
            if ($code >= 32 && $code <= 126) {
                $total += $table[$code - 32];
            } elseif (isset(self::WIDTHS_SPECIAL[$char])) {
                $total += self::WIDTHS_SPECIAL[$char];
            } else {
                // Lettre accentuée : chasse de la lettre de base (é → e, Ç → C).
                $base = self::baseLetter($char);
                $total += $base !== null ? $table[ord($base) - 32] : 556;
            }
        }
        return $total * $size / 1000;
    }

    /**
     * Découpe un texte en lignes tenant dans la largeur (retours à la ligne du texte respectés,
     * mots trop longs coupés).
     *
     * @return string[]
     */
    public function wrap(string $text, float $width, float $size = 10.0, string $font = 'regular'): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $paragraph) {
            $current = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) ?: [] as $word) {
                if ($word === '') {
                    continue;
                }
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($this->width($candidate, $size, $font) <= $width) {
                    $current = $candidate;
                    continue;
                }
                if ($current !== '') {
                    $lines[] = $current;
                }
                // Mot plus long que la ligne : coupé caractère par caractère.
                while ($this->width($word, $size, $font) > $width && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word);
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $size, $font) > $width) {
                        $cut--;
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $current = $word;
            }
            $lines[] = $current;
        }
        return $lines;
    }

    /** Document PDF complet (octets). */
    public function output(): string
    {
        if ($this->pages === []) {
            $this->addPage();
        }
        $objects = [];
        // 1 : catalogue, 2 : arbre des pages, 3-5 : polices, 6 : informations, puis page + contenu par page.
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $fontIds = [];
        $id = 3;
        foreach (self::BASE_FONTS as $name => $base) {
            $objects[$id] = sprintf('<< /Type /Font /Subtype /Type1 /BaseFont /%s /Encoding /WinAnsiEncoding >>', $base);
            $fontIds[$name] = $id++;
        }
        $objects[$id] = sprintf(
            '<< /Title (%s) /Author (%s) /Producer (Vision Homecare) /CreationDate (D:%s) >>',
            self::escape(self::encode($this->title)),
            self::escape(self::encode($this->author)),
            gmdate('YmdHis') . 'Z'
        );
        $infoId = $id++;
        $fontResources = implode(' ', array_map(function (string $name, int $objectId): string {
            return '/' . $name . ' ' . $objectId . ' 0 R';
        }, array_keys($fontIds), $fontIds));
        $kids = [];
        foreach ($this->pages as $content) {
            $pageId = $id++;
            $contentId = $id++;
            $kids[] = $pageId . ' 0 R';
            $stream = $content;
            $filter = '';
            if (function_exists('gzcompress')) {
                $stream = (string) gzcompress($content, 6);
                $filter = ' /Filter /FlateDecode';
            }
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << %s >> >> /Contents %d 0 R >>',
                self::num(self::WIDTH),
                self::num(self::HEIGHT),
                $fontResources,
                $contentId
            );
            $objects[$contentId] = sprintf("<< /Length %d%s >>\nstream\n%s\nendstream", strlen($stream), $filter, $stream);
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= sprintf("trailer\n<< /Size %d /Root 1 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", $count, $infoId, $xref);
        return $pdf;
    }

    // ------------------------------------------------------------------ Outils

    private function write(string $operators): void
    {
        if ($this->current < 0) {
            $this->addPage();
        }
        $this->pages[$this->current] .= $operators;
    }

    /**
     * UTF-8 → Windows-1252 (WinAnsi). Espaces insécables fines et caractères absents remplacés.
     */
    public static function encode(string $text): string
    {
        $text = str_replace(["\u{202F}", "\u{2009}", "\u{00A0}"], ' ', $text);
        $text = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text);
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted === false) {
            $converted = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        }
        return (string) $converted;
    }

    /**
     * Lettre de base d'une lettre accentuée WinAnsi (é → e). Table explicite : la translittération
     * d'iconv varie selon le système (« 'e » sous Windows).
     */
    private static function baseLetter(string $char): ?string
    {
        $utf8 = @iconv('Windows-1252', 'UTF-8', $char);
        if ($utf8 === false || $utf8 === '') {
            return null;
        }
        $base = strtr($utf8, [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A', 'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E',
            'Ë' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N', 'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ö' => 'O', 'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y', 'Œ' => 'O', 'à' => 'a', 'á' => 'a', 'â' => 'a',
            'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
            'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'o',
        ]);
        return strlen($base) === 1 && ord($base) >= 32 && ord($base) <= 126 ? $base : null;
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
    }

    private static function num(float $value): string
    {
        $text = number_format($value, 2, '.', '');
        if (strpos($text, '.') !== false) {
            $text = rtrim(rtrim($text, '0'), '.');
        }
        return $text === '' || $text === '-0' || $text === '-' ? '0' : $text;
    }

    private static function fill(array $rgb): string
    {
        return sprintf('%s %s %s rg', self::num((float) $rgb[0]), self::num((float) $rgb[1]), self::num((float) $rgb[2]));
    }

    private static function stroke(array $rgb): string
    {
        return sprintf('%s %s %s RG', self::num((float) $rgb[0]), self::num((float) $rgb[1]), self::num((float) $rgb[2]));
    }

    /** Couleur hexadécimale « #E8572A » → composantes de 0 à 1. */
    public static function hex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }
}
