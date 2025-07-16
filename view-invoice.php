<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

require "./database/config.php";
require 'vendor/autoload.php';

use setasign\Fpdi\Fpdi;
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

try {

    function numberToWords($number)
    {
        $ones = array(
            0 => 'Zero',
            1 => 'One',
            2 => 'Two',
            3 => 'Three',
            4 => 'Four',
            5 => 'Five',
            6 => 'Six',
            7 => 'Seven',
            8 => 'Eight',
            9 => 'Nine',
            10 => 'Ten',
            11 => 'Eleven',
            12 => 'Twelve',
            13 => 'Thirteen',
            14 => 'Fourteen',
            15 => 'Fifteen',
            16 => 'Sixteen',
            17 => 'Seventeen',
            18 => 'Eighteen',
            19 => 'Nineteen'
        );
        $tens = array(
            2 => 'Twenty',
            3 => 'Thirty',
            4 => 'Forty',
            5 => 'Fifty',
            6 => 'Sixty',
            7 => 'Seventy',
            8 => 'Eighty',
            9 => 'Ninety'
        );
        $units = array('Hundred', 'Thousand', 'Lakh', 'Crore');

        // Format number to two decimal places
        $number = number_format($number, 2, '.', '');
        list($integerPart, $decimalPart) = explode('.', $number);

        // Convert integer part (rupees)
        $integerWords = '';
        if ($integerPart == 0) {
            $integerWords = 'Zero';
        } else {
            $integerPart = (int) $integerPart;
            $parts = array();

            // Crores
            if ($integerPart >= 10000000) {
                $crores = floor($integerPart / 10000000);
                $parts[] = numberToWords($crores) . ' Crore';
                $integerPart %= 10000000;
            }
            // Lakhs
            if ($integerPart >= 100000) {
                $lakhs = floor($integerPart / 100000);
                $parts[] = numberToWords($lakhs) . ' Lakh';
                $integerPart %= 100000;
            }
            // Thousands
            if ($integerPart >= 1000) {
                $thousands = floor($integerPart / 1000);
                $parts[] = numberToWords($thousands) . ' Thousand';
                $integerPart %= 1000;
            }
            // Hundreds
            if ($integerPart >= 100) {
                $hundreds = floor($integerPart / 100);
                $parts[] = $ones[$hundreds] . ' Hundred';
                $integerPart %= 100;
            }
            // Tens and Ones
            if ($integerPart > 0) {
                if ($integerPart < 20) {
                    $parts[] = $ones[$integerPart];
                } else {
                    $tensVal = floor($integerPart / 10);
                    $onesVal = $integerPart % 10;
                    $parts[] = $tens[$tensVal] . ($onesVal > 0 ? ' ' . $ones[$onesVal] : '');
                }
            }

            $integerWords = implode(' ', array_filter($parts));
        }

        // Convert decimal part (paise)
        $decimalWords = '';
        if ($decimalPart > 0) {
            $decimalPart = (int) $decimalPart;
            if ($decimalPart < 20) {
                $decimalWords = $ones[$decimalPart];
            } else {
                $tensVal = floor($decimalPart / 10);
                $onesVal = $decimalPart % 10;
                $decimalWords = $tens[$tensVal] . ($onesVal > 0 ? ' ' . $ones[$onesVal] : '');
            }
            $decimalWords .= ' Paise';
        }

        // Combine rupees and paise
        $result = $integerWords;
        if ($decimalWords) {
            $result .= ($result ? ' and ' : '') . $decimalWords;
        }
        $result = trim($result) . ' Only';
        return $result;
    }

    // Decode Invoice ID
    $invoiceId = intval(base64_decode($_GET['id']));
    if ($invoiceId <= 0)
        throw new Exception("Invalid invoice ID.");

    // Fetch invoice data
    $stmtFetch = $db->prepare('
        SELECT invoice.*, tax.tax_rate, invoice.status AS paymentStatus,
               customer.customer_name, customer.customer_address, customer.customer_phone, customer.customer_email,customer.gst_number,
               COALESCE(customer.ship_name, customer.customer_name) AS ship_name,
               COALESCE(customer.ship_address, customer.customer_address) AS ship_address,
               COALESCE(customer.ship_phone, customer.customer_phone) AS ship_phone,
               COALESCE(customer.ship_email, customer.customer_email) AS ship_email
        FROM invoice 
        INNER JOIN customer ON customer.customer_id = invoice.customer_id
        INNER JOIN tax ON tax.tax_id = invoice.tax
        WHERE invoice_id = ?
    ');
    $stmtFetch->bind_param('i', $invoiceId);
    $stmtFetch->execute();
    $result = $stmtFetch->get_result();
    $invoice = $result->fetch_assoc();


    if (!$invoice)
        throw new Exception("Invoice not found.");

    // Decode service IDs
    $serviceIds = json_decode($invoice['service_id'] ?? '[]', true) ?? [];
    $serviceIds = array_map('intval', $serviceIds);

    // Fetch service names
    $services = [];
    $hsnCode;
    if (!empty($serviceIds)) {
        $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
        $types = str_repeat('i', count($serviceIds));
        $stmtServices = $db->prepare("SELECT service_id, service_name,sac_code FROM services WHERE service_id IN ($placeholders)");
        $stmtServices->bind_param($types, ...$serviceIds);
        $stmtServices->execute();
        $res = $stmtServices->get_result();
        while ($row = $res->fetch_assoc()) {
            $services[$row['service_id']] = [
                'name' => $row['service_name'],
                'sac_code' => $row['sac_code']
            ];
        }
    }

    $stmtFetch = $db->prepare("SELECT * FROM invoice_settings");
    $stmtFetch->execute();
    $invoiceSettings = $stmtFetch->get_result()->fetch_array(MYSQLI_ASSOC);

    $stmtFetchCompanySettings = $db->prepare("SELECT * FROM company_settings");
    $stmtFetchCompanySettings->execute();
    $companySettings = $stmtFetchCompanySettings->get_result()->fetch_array(MYSQLI_ASSOC);


    $stmtFetchLocalizationSettings = $db->prepare("SELECT * FROM localization_settings INNER JOIN currency ON localization_settings.currency_id = currency.currency_id;");
    $stmtFetchLocalizationSettings->execute();
    $localizationSettings = $stmtFetchLocalizationSettings->get_result()->fetch_array(MYSQLI_ASSOC);
    $currencySymbol = $localizationSettings["currency_code"] ?? "$";



    // echo "<pre>";
    // print_r($companySettings);
    // exit;

    // Step 1: Generate stylized Service Table using DomPDF
    ob_start();
    ?>


    <?php
    // Build the list of service names
    $serviceList = '';
    foreach ($serviceIds as $id) {
        $service = $services[$id] ?? ['name' => 'Unknown Service', 'sac_code' => 'N/A'];
        $serviceList .= '<li>' . htmlspecialchars($service['name']) . '</li>';
        $hsnCode = $service['sac_code'];
    }

    // Given values
    $pricePerService = $invoice['quantity'] > 0 ? $invoice['total_amount'] / $invoice['quantity'] : 0;
    $taxRateStr = $invoice['tax_rate']; // "18%"

    // Convert tax rate string to integer (remove % and convert to int)
    $taxRate = intval(str_replace('%', '', $taxRateStr)); // Converts "18%" to 18

    // Calculate price without tax
    $priceWithoutTax = $taxRate > 0 ? $pricePerService / (1 + $taxRate / 100) : $pricePerService;

    // Calculate tax amount per unit
    $taxAmount = $pricePerService - $priceWithoutTax;
    ?>

    <?php
    // Step 2: Overlay on main invoice PDF using FPDI
    $pdf = new Fpdi();
    $templatePath = isset($invoiceSettings['template']) ? $invoiceSettings['template'] : 'public/assets/invoice-temp/invoice-temp-1.pdf';

    if (!file_exists($templatePath)) {
        throw new Exception("Template not found.");
    }

    // Import main template
    $pageCount = $pdf->setSourceFile($templatePath);
    $templateId = $pdf->importPage(1);
    $pdf->AddPage();
    // Register the font
    $pdf->AddFont('montserratb', '', 'Montserrat-Bold.php');
    $pdf->AddFont('FuturaMdBT-Bold', '', 'futuramdbt_bold.php');
    $pdf->AddFont('FuturaBT-Medium', '', 'Futura Md Bt Medium.php');
    $pdf->useTemplate($templateId, 0, 0, 210);


    // Invoice Info (top-right, adjust based on template)
    $pdf->SetFont('FuturaBT-Medium', '', 25); // Set font to normal Times
    $pdf->SetTextColor(0, 0, 0); // Set text color to black
    $pdf->SetXY(50, 45);
    $pdf->Cell(22, 10, 'THE FLIGHTSHUB PVT LTD.', 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetFont('FuturaBT-Medium', '', 8); // Set font to bold
    $pdf->SetXY(60, 50);
    $pdf->Cell(22, 10, $companySettings['address'] . " " . $companySettings['state'] . "," . $companySettings['country'], 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal
    $pdf->SetXY(140, 25);
    $labelWidth = $pdf->GetStringWidth('NZBN No: ') + 1; // Calculate width of "Date:" with small padding
    $pdf->Cell($labelWidth, 10, 'NZBN No: ', 0, 0); // Render "Date:" in black, normal font with exact width
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->Cell(0, 10, $companySettings['bz_number'], 0, 1); // Render date in bold blue, no gap
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal
    $pdf->SetXY(140, 30);
    $labelWidth = $pdf->GetStringWidth('GST No: ') + 1; // Calculate width of "Date:" with small padding
    $pdf->Cell($labelWidth, 10, 'GST No: ', 0, 0); // Render "Date:" in black, normal font with exact width
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->Cell(0, 10, $companySettings['gst_number'], 0, 1); // Render date in bold blue, no gap
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal



    // Invoice Info (top-right, adjust based on template)
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to normal Times
    $pdf->SetTextColor(0, 0, 0); // Set text color to black
    $pdf->SetXY(20, 65);
    $pdf->Cell(22, 10, 'Invoice No: ', 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->Cell(0, 10, $invoice['invoice_number'], 0, 0); // Render invoice number in bold blue
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal
    $pdf->SetXY(140, 65);
    $labelWidth = $pdf->GetStringWidth('Invoice Due: ') + 1; // Calculate width of "Date:" with small padding
    $pdf->Cell($labelWidth, 10, 'Invoice Due: ', 0, 0); // Render "Date:" in black, normal font with exact width
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->Cell(0, 10, date('d-M-Y', strtotime($invoice['due_date'])), 0, 1); // Render date in bold blue, no gap
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal

    $pdf->SetXY(20, 75);
    $pdf->Cell(22, 10, 'Bill To:', 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetXY(140, 75);
    $pdf->Cell(22, 10, 'Travel Date:', 0, 0); // Render "Invoice No:" in black, normal font

    // Output final PDF
    $pdf->Output("I", "Final_Invoice_{$invoiceId}.pdf");


} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}