<?php

declare(strict_types=1);

namespace Vsh\Modules\Prescriptions;

use Vsh\Core\Pdf\Letterhead;
use Vsh\Core\Pdf\PdfDocument;
use Vsh\Modules\Settings\DocumentBranding;

/**
 * Mise en page PDF d'une ordonnance SIGNÉE : prescripteur, patient (âge à la date de signature),
 * médicaments numérotés avec posologie, mentions, et bloc de signature électronique avec la référence
 * de l'ordonnance (vérifiable dans le dossier).
 */
final class PrescriptionPdf
{
    private const PROFESSIONS = [
        'MEDECIN' => 'Médecin',
        'INFIRMIER' => 'Infirmier(ère)',
        'SAGE_FEMME' => 'Sage-femme',
        'TECHNICIEN' => 'Technicien(ne)',
    ];

    /** @var DocumentBranding */
    private $branding;

    public function __construct(DocumentBranding $branding)
    {
        $this->branding = $branding;
    }

    /**
     * @param array      $prescription Ligne `prescriptions` (signée)
     * @param array      $items        Lignes `prescription_items` actives
     * @param array      $patient      Ligne `patients`
     * @param array      $prescriber   Ligne `users`
     * @param array|null $profile      Ligne `staff_profiles` du prescripteur
     */
    public function render(array $prescription, array $items, array $patient, array $prescriber, ?array $profile): string
    {
        $title = 'ORDONNANCE';
        $pdf = new PdfDocument('Ordonnance', $this->branding->clinicName());
        $letterhead = $this->branding->letterhead();
        $pdf->addPage();
        $signedAt = (string) $prescription['signed_at'];
        $y = $letterhead->header($pdf, $title, [
            ['Date', $this->branding->localDateTime($signedAt, false)],
            ['Réf.', strtoupper(substr((string) $prescription['uuid'], 0, 8))],
        ]);
        $left = Letterhead::MARGIN;
        $right = Letterhead::right();
        $width = Letterhead::contentWidth();
        $half = ($width - 16) / 2;

        // Prescripteur et patient côte à côte
        $prescriberName = trim($prescriber['first_name'] . ' ' . $prescriber['last_name']);
        $profession = $profile !== null ? (self::PROFESSIONS[(string) $profile['profession']] ?? '') : '';
        if ($profile !== null && (string) $profile['profession'] === 'MEDECIN') {
            $prescriberName = 'Dr ' . $prescriberName;
        }
        $prescriberLines = array_values(array_filter([
            trim($profession . ($profile !== null && !empty($profile['speciality']) ? ' — ' . $profile['speciality'] : '')),
            $profile !== null && !empty($profile['license_number']) ? 'N° d’inscription : ' . $profile['license_number'] : '',
        ]));
        $patientLines = array_values(array_filter([
            trim(self::sex((string) $patient['sex']) . ($patient['birth_date'] !== null ? ', ' . $this->age((string) $patient['birth_date'], $signedAt) : '')),
            $patient['file_number'] !== null ? 'Dossier ' . $patient['file_number'] : '',
        ]));
        $boxHeight = 30 + max(count($prescriberLines), count($patientLines)) * 12 + 8;
        $this->box($pdf, $left, $y, $half, $boxHeight, 'Prescripteur', $prescriberName, $prescriberLines);
        $this->box($pdf, $left + $half + 16, $y, $half, $boxHeight, 'Patient', mb_strtoupper((string) $patient['last_name']) . ' ' . $patient['first_name'], $patientLines);
        $y += $boxHeight + 26;

        // Médicaments
        foreach (array_values($items) as $index => $item) {
            $heading = trim(implode(' ', array_filter([
                (string) $item['medication_label'],
                (string) ($item['dosage'] ?? ''),
                (string) ($item['form'] ?? ''),
            ])));
            $posology = implode(' · ', array_filter([
                (string) ($item['posology'] ?? ''),
                (string) ($item['frequency'] ?? ''),
                !empty($item['duration']) ? 'pendant ' . $item['duration'] : '',
                !empty($item['route']) ? 'voie ' . $item['route'] : '',
            ]));
            $headingLines = $pdf->wrap(($index + 1) . '. ' . $heading, $width - 20, 11, 'bold');
            $posologyLines = $posology !== '' ? $pdf->wrap($posology, $width - 40, 10) : [];
            $instructionLines = !empty($item['instructions']) ? $pdf->wrap((string) $item['instructions'], $width - 40, 9.5, 'italic') : [];
            $needed = count($headingLines) * 14 + count($posologyLines) * 13 + count($instructionLines) * 12.5 + (!empty($item['quantity']) ? 13 : 0) + 18;
            if ($y + $needed > Letterhead::BOTTOM_LIMIT - 90) {
                $pdf->addPage();
                $y = $letterhead->continuation($pdf, $title);
            }
            foreach ($headingLines as $line) {
                $pdf->text($left + 4, $y, $line, 11, 'bold', Letterhead::ink());
                $y += 14;
            }
            foreach ($posologyLines as $line) {
                $pdf->text($left + 24, $y, $line, 10, 'regular', Letterhead::ink());
                $y += 13;
            }
            if (!empty($item['quantity'])) {
                $pdf->text($left + 24, $y, 'Quantité : ' . $item['quantity'], 9.5, 'regular', Letterhead::muted());
                $y += 13;
            }
            foreach ($instructionLines as $line) {
                $pdf->text($left + 24, $y, $line, 9.5, 'italic', Letterhead::muted());
                $y += 12.5;
            }
            $y += 10;
        }

        $notes = trim((string) $prescription['notes']);
        if ($notes !== '') {
            $lines = $pdf->wrap($notes, $width, 9.5);
            if ($y + 20 + count($lines) * 13 > Letterhead::BOTTOM_LIMIT - 90) {
                $pdf->addPage();
                $y = $letterhead->continuation($pdf, $title);
            }
            $pdf->text($left, $y + 4, 'Recommandations', 9.5, 'bold', Letterhead::ink());
            $y = $pdf->paragraph($left, $y + 18, $width, $notes, 9.5, 'regular', Letterhead::ink());
        }

        // Signature électronique
        if ($y + 80 > Letterhead::BOTTOM_LIMIT) {
            $pdf->addPage();
            $y = $letterhead->continuation($pdf, $title);
        }
        $signY = max($y + 24, Letterhead::BOTTOM_LIMIT - 70);
        $signX = $right - 230;
        $pdf->rect($signX, $signY, 230, 58, null, Letterhead::green(), 1);
        $pdf->text($signX + 12, $signY + 17, 'Signée électroniquement', 9, 'bold', Letterhead::green());
        $pdf->text($signX + 12, $signY + 31, 'le ' . $this->branding->localDateTime($signedAt), 9, 'regular', Letterhead::ink());
        $pdf->text($signX + 12, $signY + 45, 'par ' . $prescriberName, 9, 'regular', Letterhead::ink());

        $letterhead->footers($pdf, $this->branding->footer('prescription'), 'Réf. ' . $prescription['uuid']);
        return $pdf->output();
    }

    /**
     * @param string[] $lines
     */
    private function box(PdfDocument $pdf, float $x, float $y, float $w, float $h, string $label, string $name, array $lines): void
    {
        $pdf->rect($x, $y, $w, $h, Letterhead::tint());
        $pdf->text($x + 12, $y + 15, $label, 8.5, 'regular', Letterhead::muted());
        $pdf->text($x + 12, $y + 30, $name, 11, 'bold', Letterhead::ink());
        foreach ($lines as $index => $line) {
            $pdf->text($x + 12, $y + 44 + $index * 12, $line, 9, 'regular', Letterhead::muted());
        }
    }

    private static function sex(string $sex): string
    {
        return $sex === 'F' ? 'Femme' : ($sex === 'M' ? 'Homme' : '');
    }

    /** Âge à la date de signature (années, ou mois avant 2 ans). */
    private function age(string $birthDate, string $signedAt): string
    {
        $birth = new \DateTimeImmutable($birthDate, $this->branding->timezone());
        $at = (new \DateTimeImmutable($signedAt, new \DateTimeZone('UTC')))->setTimezone($this->branding->timezone());
        $diff = $birth->diff($at);
        if ($diff->y < 2) {
            return ($diff->y * 12 + $diff->m) . ' mois';
        }
        return $diff->y . ' ans';
    }
}
