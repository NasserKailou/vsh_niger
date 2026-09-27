<?php

declare(strict_types=1);

namespace Vsh\Core\Pdf;

/**
 * En-tête et pied de page communs aux documents imprimés (factures, ordonnances), aux couleurs de la
 * charte. Les informations de l'établissement viennent des paramètres : rien n'est écrit en dur.
 */
final class Letterhead
{
    public const MARGIN = 42.0;
    public const BOTTOM_LIMIT = 770.0;

    private const ORANGE = '#E8572A';
    private const GREEN = '#1E9A3C';
    private const INK = '#1F2A24';
    private const MUTED = '#5B6660';
    private const RULE = '#D5DBD7';

    /** @var string */
    private $name;

    /** @var string[] */
    private $contactLines;

    /**
     * @param string   $name         Nom de l'établissement
     * @param string[] $contactLines Adresse, téléphone, e-mail (les lignes vides sont ignorées)
     */
    public function __construct(string $name, array $contactLines)
    {
        $this->name = $name;
        $this->contactLines = array_values(array_filter(array_map('trim', $contactLines), function (string $line): bool {
            return $line !== '';
        }));
    }

    public static function contentWidth(): float
    {
        return PdfDocument::WIDTH - 2 * self::MARGIN;
    }

    public static function right(): float
    {
        return PdfDocument::WIDTH - self::MARGIN;
    }

    /**
     * En-tête complet (première page) : bandeau, établissement à gauche, titre et références à droite.
     *
     * @param array<int, array{0: string, 1: string}> $meta Paires libellé / valeur sous le titre
     * @return float Ordonnée disponible sous l'en-tête
     */
    public function header(PdfDocument $pdf, string $title, array $meta): float
    {
        $pdf->rect(0, 0, PdfDocument::WIDTH, 6, PdfDocument::hex(self::ORANGE));
        $pdf->rect(0, 6, PdfDocument::WIDTH, 2, PdfDocument::hex(self::GREEN));

        $y = 44.0;
        $pdf->text(self::MARGIN, $y, $this->name, 16, 'bold', PdfDocument::hex(self::INK));
        $left = $y + 16;
        foreach ($this->contactLines as $line) {
            foreach ($pdf->wrap($line, 250, 8.5) as $part) {
                $pdf->text(self::MARGIN, $left, $part, 8.5, 'regular', PdfDocument::hex(self::MUTED));
                $left += 11.5;
            }
        }

        $pdf->textRight(self::right(), $y, $title, 18, 'bold', PdfDocument::hex(self::ORANGE));
        $right = $y + 18;
        foreach ($meta as $pair) {
            $label = $pair[0] . ' : ';
            $valueWidth = $pdf->width($pair[1], 9, 'bold');
            $pdf->textRight(self::right() - $valueWidth, $right, $label, 9, 'regular', PdfDocument::hex(self::MUTED));
            $pdf->textRight(self::right(), $right, $pair[1], 9, 'bold', PdfDocument::hex(self::INK));
            $right += 13;
        }

        $bottom = max($left, $right) + 6;
        $pdf->line(self::MARGIN, $bottom, self::right(), $bottom, 0.8, PdfDocument::hex(self::RULE));
        return $bottom + 20;
    }

    /** En-tête réduit des pages suivantes. */
    public function continuation(PdfDocument $pdf, string $title): float
    {
        $pdf->rect(0, 0, PdfDocument::WIDTH, 4, PdfDocument::hex(self::ORANGE));
        $pdf->text(self::MARGIN, 30, $this->name, 10, 'bold', PdfDocument::hex(self::INK));
        $pdf->textRight(self::right(), 30, $title . ' (suite)', 10, 'bold', PdfDocument::hex(self::ORANGE));
        $pdf->line(self::MARGIN, 40, self::right(), 40, 0.8, PdfDocument::hex(self::RULE));
        return 60.0;
    }

    /**
     * Pieds de page de toutes les pages : texte libre (paramètre), référence et « Page x / n ».
     */
    public function footers(PdfDocument $pdf, string $footer, string $reference): void
    {
        $count = $pdf->pageCount();
        for ($page = 0; $page < $count; $page++) {
            $pdf->onPage($page);
            $pdf->line(self::MARGIN, 792, self::right(), 792, 0.5, PdfDocument::hex(self::RULE));
            $y = 804.0;
            foreach (array_slice($pdf->wrap($footer, self::contentWidth() - 90, 7.5), 0, 2) as $line) {
                if ($line === '') {
                    continue;
                }
                $pdf->text(self::MARGIN, $y, $line, 7.5, 'regular', PdfDocument::hex(self::MUTED));
                $y += 9.5;
            }
            $pdf->text(self::MARGIN, max($y, 813.5), $reference, 7, 'regular', PdfDocument::hex(self::MUTED));
            $pdf->textRight(self::right(), 804, sprintf('Page %d / %d', $page + 1, $count), 7.5, 'regular', PdfDocument::hex(self::MUTED));
        }
    }

    public static function ink(): array
    {
        return PdfDocument::hex(self::INK);
    }

    public static function muted(): array
    {
        return PdfDocument::hex(self::MUTED);
    }

    public static function rule(): array
    {
        return PdfDocument::hex(self::RULE);
    }

    public static function accent(): array
    {
        return PdfDocument::hex(self::ORANGE);
    }

    public static function green(): array
    {
        return PdfDocument::hex(self::GREEN);
    }

    /** Fond clair des en-têtes de tableau et encadrés. */
    public static function tint(): array
    {
        return PdfDocument::hex('#F3F5F4');
    }
}
