<?php

declare(strict_types=1);

namespace Vsh\Tests\Api;

use Vsh\Core\Pdf\PdfDocument;

/**
 * Documents PDF : moteur (structure, codage, mesure du texte), factures et ordonnances (droits, états,
 * contenu, audit, en-tête paramétrable).
 */
final class DocumentsPdfTest extends ApiTestCase
{
    /** @var string */
    private $admin;

    /** @var string */
    private $reception;

    /** @var string */
    private $doctor;

    /** @var string */
    private $nurse;

    /** @var array */
    private $patient;

    /** @var int */
    private static $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $n = ++self::$sequence;
        $this->admin = $this->login($this->createUser(['ADMIN']))['access_token'];
        $this->reception = $this->login($this->createUser(['ACCUEIL']))['access_token'];
        $this->doctor = $this->login($this->createUser(['MEDECIN']))['access_token'];
        $this->nurse = $this->login($this->createUser(['INFIRMIER']))['access_token'];
        $this->patient = $this->payload($this->send('POST', '/patients', [
            'first_name' => 'Haoua', 'last_name' => 'Pdf' . getmypid() . 'D' . $n, 'sex' => 'F',
            'birth_date' => sprintf('19%02d-0%d-1%d', 60 + $n % 30, 1 + $n % 9, $n % 10),
        ], $this->admin))['data'];
    }

    // ------------------------------------------------------------------ Moteur

    public function testEngineProducesAValidPdfStructure(): void
    {
        $pdf = new PdfDocument('Essai', 'Tests');
        $pdf->addPage();
        $pdf->text(0, 20, 'Réf. (1) \\ éàç — « ok »', 10);
        $pdf->rect(0, 0, 10, 10, [1, 0, 0]);
        $pdf->addPage();
        $pdf->line(0, 0, 100, 0);
        $bytes = $pdf->output();

        $this->assertStringStartsWith('%PDF-1.4', $bytes);
        $this->assertStringEndsWith("%%EOF\n", $bytes);
        $this->assertStringContainsString('/Count 2', $bytes);
        // Table des références croisées : chaque décalage pointe sur le début de l'objet annoncé.
        preg_match('/startxref\n(\d+)/', $bytes, $start);
        $xref = substr($bytes, (int) $start[1]);
        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $offsets);
        $this->assertNotEmpty($offsets[1]);
        foreach ($offsets[1] as $index => $offset) {
            $expected = ($index + 1) . ' 0 obj';
            $this->assertSame($expected, substr($bytes, (int) $offset, strlen($expected)));
        }
        $content = $this->text($bytes);
        // Accents en WinAnsi, parenthèses et barre oblique inverse échappées, zéro écrit « 0 ».
        $this->assertStringContainsString("(R\xE9f. \\(1\\) \\\\ \xE9\xE0\xE7 \x97 \xAB ok \xBB) Tj", $content);
        $this->assertStringContainsString(' 0 821.89 Td', $content);
    }

    public function testTextMeasurementAndWrapping(): void
    {
        $pdf = new PdfDocument();
        $this->assertEqualsWithDelta(6.67, $pdf->width('A', 10), 0.01);
        $this->assertEqualsWithDelta($pdf->width('e', 10), $pdf->width('é', 10), 0.001);
        $this->assertGreaterThan($pdf->width('Total', 10), $pdf->width('Total', 10, 'bold'));
        $lines = $pdf->wrap("Première ligne assez longue pour être coupée en plusieurs morceaux\nSeconde", 120, 10);
        $this->assertGreaterThan(2, count($lines));
        $this->assertSame('Seconde', end($lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(120, $pdf->width($line, 10));
        }
    }

    // ------------------------------------------------------------------ Factures

    public function testInvoicePdfFollowsStatusRightsAndLetterhead(): void
    {
        $invoice = $this->payload($this->send('POST', '/invoices', [
            'patient_id' => $this->patient['id'],
            'items' => [['item_type' => 'OTHER', 'description' => 'Certificat médical', 'unit_price' => 12500, 'quantity' => 2]],
        ], $this->reception))['data'];

        $draft = $this->send('GET', '/invoices/' . $invoice['id'] . '/pdf', null, $this->reception);
        $this->assertStatus(200, $draft);
        $this->assertSame('application/pdf', $draft->header('Content-Type'));
        $this->assertStringContainsString('attachment; filename="facture-brouillon-', (string) $draft->header('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $draft->header('Cache-Control'));
        $text = $this->text($draft->body());
        $this->assertStringContainsString('Document provisoire', $text);
        $this->assertStringContainsString("Certificat m\xE9dical", $text);
        $this->assertStringContainsString('25 000 FCFA', $text);
        $this->assertStatus(403, $this->send('GET', '/invoices/' . $invoice['id'] . '/pdf', null, $this->nurse));

        // En-tête paramétrable : l'adresse saisie par l'administrateur apparaît, rien n'est écrit en dur.
        $letterhead = ['address' => 'Quartier Plateau, Niamey', 'phone' => '', 'email' => '', 'invoice_footer' => 'Mention de test', 'prescription_footer' => ''];
        $this->assertStatus(200, $this->send('PUT', '/settings', ['values' => ['documents.letterhead' => $letterhead]], $this->admin));
        try {
            $issued = $this->payload($this->send('POST', '/invoices/' . $invoice['id'] . '/issue', null, $this->reception))['data'];
            $response = $this->send('GET', '/invoices/' . $invoice['id'] . '/pdf', null, $this->reception);
            $this->assertStringContainsString('filename="' . $issued['number'] . '.pdf"', (string) $response->header('Content-Disposition'));
            $text = $this->text($response->body());
            $this->assertStringContainsString($issued['number'], $text);
            $this->assertStringContainsString('Quartier Plateau, Niamey', $text);
            $this->assertStringContainsString('Mention de test', $text);
            $this->assertStringContainsString("Non r\xE9gl\xE9e", $text);
            $this->assertStringNotContainsString('Document provisoire', $text);
        } finally {
            $this->send('PUT', '/settings', ['values' => ['documents.letterhead' => [
                'address' => '', 'phone' => '', 'email' => '', 'invoice_footer' => '', 'prescription_footer' => '',
            ]]], $this->admin);
        }
        $this->assertSame(2, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'INVOICE_EXPORTED' AND entity_uuid = ?",
            [$invoice['id']]
        ));
    }

    public function testPatientDownloadsOnlyIssuedInvoicesOfOwnFile(): void
    {
        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $this->patient['id']]);
        $token = $this->login($account)['access_token'];
        $invoice = $this->payload($this->send('POST', '/invoices', [
            'patient_id' => $this->patient['id'],
            'items' => [['item_type' => 'OTHER', 'description' => 'Pansement', 'unit_price' => 3000]],
        ], $this->reception))['data'];
        $path = '/me/patients/' . $this->patient['id'] . '/invoices/' . $invoice['id'] . '/pdf';

        $this->assertStatus(404, $this->send('GET', $path, null, $token));
        $this->send('POST', '/invoices/' . $invoice['id'] . '/issue', null, $this->reception);
        $this->assertStatus(200, $this->send('GET', $path, null, $token));
        $other = $this->login($this->createUser(['PATIENT'], ['account_type' => 'PATIENT']))['access_token'];
        $this->assertStatus(404, $this->send('GET', $path, null, $other));
    }

    // ------------------------------------------------------------------ Ordonnances

    public function testPrescriptionPdfOnlyOnceSignedWithMedicalRights(): void
    {
        $draft = $this->payload($this->send('POST', '/prescriptions', [
            'patient_id' => $this->patient['id'],
            'notes' => 'Revenir si la fièvre persiste',
            'items' => [[
                'medication_label' => 'Paracétamol', 'dosage' => '500 mg', 'posology' => '1 comprimé',
                'frequency' => '3 fois par jour', 'duration' => '5 jours',
            ]],
        ], $this->doctor))['data'];
        $this->assertSame('NOT_SIGNED', $this->payload($this->send('GET', '/prescriptions/' . $draft['id'] . '/pdf', null, $this->doctor))['code']);

        $this->send('POST', '/prescriptions/' . $draft['id'] . '/sign', null, $this->doctor);
        $response = $this->send('GET', '/prescriptions/' . $draft['id'] . '/pdf', null, $this->doctor);
        $this->assertStatus(200, $response);
        $this->assertSame('application/pdf', $response->header('Content-Type'));
        $text = $this->text($response->body());
        $this->assertStringContainsString('ORDONNANCE', $text);
        $this->assertStringContainsString("1. Parac\xE9tamol 500 mg", $text);
        $this->assertStringContainsString("1 comprim\xE9 \xB7 3 fois par jour \xB7 pendant 5 jours", $text);
        $this->assertStringContainsString("Sign\xE9e \xE9lectroniquement", $text);
        $this->assertStringContainsString("fi\xE8vre persiste", $text);

        $this->assertStatus(403, $this->send('GET', '/prescriptions/' . $draft['id'] . '/pdf', null, $this->reception));
        $this->assertSame(1, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'PRESCRIPTION_EXPORTED' AND entity_uuid = ?",
            [$draft['id']]
        ));

        $account = $this->createUser(['PATIENT'], ['account_type' => 'PATIENT']);
        $this->db()->execute('UPDATE patients SET user_id = ? WHERE uuid = ?', [$account['id'], $this->patient['id']]);
        $token = $this->login($account)['access_token'];
        $this->assertStatus(200, $this->send('GET', '/me/patients/' . $this->patient['id'] . '/prescriptions/' . $draft['id'] . '/pdf', null, $token));
    }

    /**
     * Contenu textuel des flux de pages (décompressés).
     */
    private function text(string $pdf): string
    {
        preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $streams);
        $text = '';
        foreach ($streams[1] as $stream) {
            $plain = @gzuncompress($stream);
            $text .= $plain !== false ? $plain : $stream;
        }
        return $text;
    }
}
