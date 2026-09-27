<?php

declare(strict_types=1);

namespace Vsh\Modules\Billing;

use Vsh\Core\Pdf\Letterhead;
use Vsh\Core\Pdf\PdfDocument;
use Vsh\Modules\Settings\DocumentBranding;

/**
 * Mise en page PDF d'une facture. Un brouillon est marqué « document provisoire », une facture annulée
 * porte la mention et le motif d'annulation. Le règlement est déclaratif (D-004) : le document le dit.
 */
final class InvoicePdf
{
    private const SETTLEMENT = [
        'NON_REGLEE' => 'Non réglée',
        'PARTIELLEMENT_REGLEE' => 'Partiellement réglée',
        'REGLEE' => 'Réglée',
    ];

    /** @var DocumentBranding */
    private $branding;

    public function __construct(DocumentBranding $branding)
    {
        $this->branding = $branding;
    }

    /**
     * @param array $invoice Ligne `invoices`
     * @param array $items   Lignes `invoice_items` actives, dans l'ordre
     * @param array $patient Ligne `patients`
     */
    public function render(array $invoice, array $items, array $patient): string
    {
        $currency = (string) $invoice['currency'];
        $money = function (int $amount) use ($currency): string {
            return DocumentBranding::money($amount, $currency);
        };
        $status = (string) $invoice['status'];
        $number = $invoice['number'] !== null ? (string) $invoice['number'] : 'Brouillon (non numérotée)';
        $title = 'FACTURE';

        $pdf = new PdfDocument($invoice['number'] !== null ? 'Facture ' . $invoice['number'] : 'Facture (brouillon)', $this->branding->clinicName());
        $letterhead = $this->branding->letterhead();
        $pdf->addPage();
        $meta = [['N°', $number]];
        if ($invoice['issued_at'] !== null) {
            $meta[] = ['Émise le', $this->branding->localDateTime((string) $invoice['issued_at'], false)];
        } else {
            $meta[] = ['Établie le', $this->branding->localDateTime((string) $invoice['created_at'], false)];
        }
        $y = $letterhead->header($pdf, $title, $meta);
        $left = Letterhead::MARGIN;
        $right = Letterhead::right();
        $width = Letterhead::contentWidth();

        // Mentions d'état : brouillon (provisoire), annulée (motif).
        if ($status === 'BROUILLON') {
            $y = $this->banner($pdf, $y, 'Document provisoire : facture non émise, sans valeur comptable.', Letterhead::accent());
        } elseif ($status === 'ANNULEE') {
            $reason = trim((string) $invoice['cancel_reason']);
            $text = 'Facture annulée le ' . $this->branding->localDateTime((string) $invoice['cancelled_at'], false) . ($reason !== '' ? ' — motif : ' . $reason : '') . '.';
            $y = $this->banner($pdf, $y, $text, [0.78, 0.16, 0.16]);
        }

        // Patient
        $pdf->rect($left, $y, $width, 50, Letterhead::tint());
        $pdf->text($left + 12, $y + 16, 'Patient', 8.5, 'regular', Letterhead::muted());
        $pdf->text($left + 12, $y + 32, mb_strtoupper((string) $patient['last_name']) . ' ' . $patient['first_name'], 12, 'bold', Letterhead::ink());
        $details = array_filter([
            $patient['file_number'] !== null ? 'Dossier ' . $patient['file_number'] : null,
            !empty($patient['phone']) ? 'Tél. ' . DocumentBranding::phone((string) $patient['phone']) : null,
        ]);
        $pdf->text($left + 12, $y + 44, implode('   ·   ', $details), 8.5, 'regular', Letterhead::muted());
        $y += 70;

        // Tableau des prestations
        $columns = ['qty' => $right - 190, 'unit' => $right - 90, 'total' => $right - 8];
        $designationWidth = $columns['qty'] - 40 - ($left + 10);
        $tableHeader = function (float $y) use ($pdf, $left, $width, $columns): float {
            $pdf->rect($left, $y, $width, 22, Letterhead::tint());
            $pdf->text($left + 10, $y + 14.5, 'Désignation', 8.5, 'bold', Letterhead::ink());
            $pdf->textRight($columns['qty'], $y + 14.5, 'Qté', 8.5, 'bold', Letterhead::ink());
            $pdf->textRight($columns['unit'], $y + 14.5, 'Prix unitaire', 8.5, 'bold', Letterhead::ink());
            $pdf->textRight($columns['total'], $y + 14.5, 'Montant', 8.5, 'bold', Letterhead::ink());
            return $y + 22;
        };
        $y = $tableHeader($y);
        foreach ($items as $item) {
            $lines = $pdf->wrap((string) $item['description'], $designationWidth, 9.5);
            $height = 10 + count($lines) * 12.5;
            if ($y + $height > Letterhead::BOTTOM_LIMIT - 20) {
                $pdf->addPage();
                $y = $tableHeader($letterhead->continuation($pdf, $title));
            }
            $lineY = $y + 16;
            foreach ($lines as $index => $line) {
                $pdf->text($left + 10, $lineY + $index * 12.5, $line, 9.5, 'regular', Letterhead::ink());
            }
            $pdf->textRight($columns['qty'], $lineY, (string) (int) $item['quantity'], 9.5, 'regular', Letterhead::ink());
            $pdf->textRight($columns['unit'], $lineY, $money((int) $item['unit_price']), 9.5, 'regular', Letterhead::ink());
            $pdf->textRight($columns['total'], $lineY, $money((int) $item['total_price']), 9.5, 'bold', Letterhead::ink());
            $y += $height;
            $pdf->line($left, $y, $right, $y, 0.4, Letterhead::rule());
        }
        if ($items === []) {
            $pdf->text($left + 10, $y + 18, 'Aucune prestation.', 9.5, 'italic', Letterhead::muted());
            $y += 28;
        }

        // Totaux
        if ($y + 150 > Letterhead::BOTTOM_LIMIT) {
            $pdf->addPage();
            $y = $letterhead->continuation($pdf, $title);
        }
        $y += 18;
        $labelX = $right - 230;
        $row = function (string $label, string $value, bool $strong = false) use ($pdf, &$y, $labelX, $right): void {
            $pdf->text($labelX, $y, $label, $strong ? 11 : 9.5, $strong ? 'bold' : 'regular', $strong ? Letterhead::ink() : Letterhead::muted());
            $pdf->textRight($right - 8, $y, $value, $strong ? 11 : 9.5, 'bold', Letterhead::ink());
            $y += $strong ? 20 : 16;
        };
        $row('Total', $money((int) $invoice['total_amount']));
        if ((int) $invoice['discount_amount'] > 0) {
            $row('Remise', '- ' . $money((int) $invoice['discount_amount']));
        }
        $pdf->line($labelX, $y - 8, $right, $y - 8, 0.8, Letterhead::accent());
        $y += 6;
        $row('Net à payer', $money((int) $invoice['net_amount']), true);

        if ($status === 'EMISE') {
            $y += 6;
            $settlement = self::SETTLEMENT[(string) $invoice['settlement_status']] ?? (string) $invoice['settlement_status'];
            $row('État du règlement', $settlement);
            if ((int) $invoice['declared_paid_amount'] > 0) {
                $row('Montant déclaré réglé', $money((int) $invoice['declared_paid_amount']));
            }
            $row('Reste dû', $money((int) $invoice['net_amount'] - (int) $invoice['declared_paid_amount']));
        }

        $y += 14;
        $pdf->paragraph($left, $y, $width, 'Le paiement est effectué en dehors de la plateforme. L’état de règlement indiqué est celui déclaré par la clinique.', 8, 'italic', Letterhead::muted());
        $notes = trim((string) $invoice['notes']);
        if ($notes !== '') {
            $y += 24;
            if ($y + 40 > Letterhead::BOTTOM_LIMIT) {
                $pdf->addPage();
                $y = $letterhead->continuation($pdf, $title);
            }
            $pdf->text($left, $y, 'Observations', 9, 'bold', Letterhead::ink());
            $pdf->paragraph($left, $y + 14, $width, $notes, 9, 'regular', Letterhead::ink());
        }

        $letterhead->footers($pdf, $this->branding->footer('invoice'), 'Réf. ' . $invoice['uuid']);
        return $pdf->output();
    }

    /**
     * @return float Ordonnée sous le bandeau
     */
    private function banner(PdfDocument $pdf, float $y, string $text, array $rgb): float
    {
        $lines = $pdf->wrap($text, Letterhead::contentWidth() - 24, 9.5, 'bold');
        $height = 14 + count($lines) * 12.5;
        $pdf->rect(Letterhead::MARGIN, $y, Letterhead::contentWidth(), $height, null, $rgb, 1.2);
        foreach ($lines as $index => $line) {
            $pdf->text(Letterhead::MARGIN + 12, $y + 17 + $index * 12.5, $line, 9.5, 'bold', $rgb);
        }
        return $y + $height + 16;
    }
}
