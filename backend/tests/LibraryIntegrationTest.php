<?php
// backend/tests/LibraryIntegrationTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPMailer\PHPMailer\PHPMailer;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class LibraryIntegrationTest extends TestCase
{
    public function testPhpSpreadsheetGeneration(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'Patrol360 ANPR Log');
        $sheet->setCellValue('B1', 'MH-01-AB-1234');

        $this->assertEquals('Patrol360 ANPR Log', $sheet->getCell('A1')->getValue());
        $this->assertEquals('MH-01-AB-1234', $sheet->getCell('B1')->getValue());

        // Test writing to memory buffer
        ob_start();
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        $excelData = ob_get_clean();

        $this->assertNotEmpty($excelData);
        $this->assertGreaterThan(1000, strlen($excelData));
    }

    public function testDompdfPdfGeneration(): void
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $dompdf = new Dompdf($options);

        $html = "<html><body><h1>POLICE INCIDENT DOSSIER</h1><p>Event: EVT-TEST-123</p></body></html>";
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();
        $this->assertNotEmpty($pdfOutput);
        $this->assertStringStartsWith('%PDF-', $pdfOutput);
    }

    public function testPHPMailerInstance(): void
    {
        $mailer = new PHPMailer(true);
        $this->assertInstanceOf(PHPMailer::class, $mailer);
        $mailer->Subject = 'Test Subject';
        $this->assertEquals('Test Subject', $mailer->Subject);
    }

    public function testFirebaseJwtLibrary(): void
    {
        $secret = 'test_secret_key_12345678901234567890';
        $payload = ['iss' => 'test', 'sub' => 99, 'role' => 'admin'];
        $token = JWT::encode($payload, $secret, 'HS256');

        $this->assertIsString($token);
        $decoded = JWT::decode($token, new Key($secret, 'HS256'));
        $this->assertEquals(99, $decoded->sub);
        $this->assertEquals('admin', $decoded->role);
    }
}
