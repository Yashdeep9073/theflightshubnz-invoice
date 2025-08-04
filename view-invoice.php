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
        SELECT invoice.*,  invoice.status AS paymentStatus
        FROM invoice 
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
    // print_r($invoice);
    // // print_r($currencySymbol);
    // exit;

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
    $pdf->SetFont('FuturaBT-Medium', '', 22); // Set font to FuturaBT-Medium
    $pdf->SetXY(20, 45); // Set position for text

    // Render "THE" in rgba(14, 139, 206, 1)
    $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
    $pdf->Cell(15, 10, 'THE', 0, 0); // Increased width to 25 to prevent clipping

    // Render "FLIGHTSHUB" in #f9522b (RGB: 249, 82, 43)
    $pdf->SetTextColor(249, 82, 43); // Set text color to #f9522b
    $pdf->Cell(30, 10, 'FLIGHTS', 0, 0); // Increased width to 65 for longer text

    $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
    $pdf->Cell(18, 10, 'HUB', 0, 0); // Increased width to 65 for longer text

    // Render "PVTLTD." in rgba(14, 139, 206, 1)
    $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
    $pdf->Cell(50, 10, 'PVT. LIMITED.', 0, 0); // Adjusted width to 50 for "PVTLTD."

    // Reset text color to black for subsequent text
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('FuturaBT-Medium', '', 8); // Set font to bold
    $pdf->SetXY(20, 50);
    $pdf->Cell(22, 10, $companySettings['address'] . " " . $companySettings['state'] . "," . $companySettings['country'], 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal
    $pdf->SetXY(140, 43);
    $labelWidth = $pdf->GetStringWidth('NZBN No: ') + 1; // Calculate width of "Date:" with small padding
    $pdf->Cell($labelWidth, 10, 'NZBN No: ', 0, 0); // Render "Date:" in black, normal font with exact width
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->Cell(0, 10, $companySettings['bz_number'], 0, 1); // Render date in bold blue, no gap
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal
    $pdf->SetXY(140, 49);
    $labelWidth = $pdf->GetStringWidth('GST No: ') + 1; // Calculate width of "Date:" with small padding
    $pdf->Cell($labelWidth, 10, 'GST No: ', 0, 0); // Render "Date:" in black, normal font with exact width
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->Cell(0, 10, $companySettings['gst_number'], 0, 1); // Render date in bold blue, no gap
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal

    // Line under headers
    $pdf->SetLineWidth(0.1);
    $pdf->SetDrawColor(158, 158, 158); // Set line color to rgba(158, 158, 158, 1)
    $pdf->Line(20, 63, 190, 63);
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black

    // Invoice Info (top-right, adjust based on template)
    $pdf->SetFont('FuturaBT-Medium', '', 22); // Set font to normal Times
    $pdf->SetTextColor(0, 0, 0); // Set text color to black
    $pdf->SetXY(65, 68);
    // $pdf->SetTextColor(249, 82, 43); // Set text color to rgba(249, 82, 43, 1)
    $pdf->Cell(22, 10, 'PAYMENT INVOICE', 0, 0); // Render "Invoice No:" in black, normal font
    // $pdf->SetTextColor(0, 0, 0); // Reset text color to black


    // Line under headers
    $pdf->SetLineWidth(0.1);
    $pdf->SetDrawColor(158, 158, 158); // Set line color to rgba(158, 158, 158, 1)
    $pdf->Line(20, 80, 190, 80);
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black

    // Invoice Info (top-right, adjust based on template)
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to normal Times
    $pdf->SetTextColor(0, 0, 0); // Set text color to black
    $pdf->SetXY(20, 85);
    $pdf->Cell(22, 10, 'Invoice No: ', 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
    $pdf->Cell(0, 10, $invoice['invoice_number'], 0, 0); // Render invoice number in bold blue
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black

    // Determine which label and date to show based on status
    if ($invoice['status'] == "PAID") {
        $dateLabel = 'Date: ';
        $displayDate = date('d M Y', strtotime($invoice['created_at']));
    } else {
        $dateLabel = 'Invoice Due: ';
        $displayDate = date('d M Y', strtotime($invoice['due_date']));
    }

    $pdf->SetXY(140, 85);
    $labelWidth = $pdf->GetStringWidth($dateLabel) + 1;
    $pdf->Cell($labelWidth, 10, $dateLabel, 0, 0);
    $pdf->SetFont('FuturaBT-Medium', '', 12);
    $pdf->SetTextColor(14, 139, 206);
    $pdf->Cell(0, 10, $displayDate, 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('FuturaBT-Medium', '', 12);

    $pdf->SetXY(20, 95);
    $pdf->Cell(22, 10, 'Bill To:', 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetXY(140, 95);
    $labelWidth = $pdf->GetStringWidth('Travel Date: ') + 1; // Calculate width of "Date:" with small padding
    $pdf->Cell($labelWidth, 10, 'Travel Date: ', 0, 0); // Render "Date:" in black, normal font with exact width
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Set font to bold
    $pdf->Cell(0, 10, date('d M Y', strtotime($invoice['travel_date'])), 0, 1); // Render date in bold blue, no gap
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal

    $pdf->SetFont('FuturaBT-Medium', '', 14); // Reset font to norma
    $pdf->SetXY(20, 101);
    $pdf->MultiCell(90, 8, $invoice['customer_name'] . "," ?? 'N/A', 0, 'L');
    $pdf->SetXY(140, 106);

    $address = $invoice['customer_address'] ?? 'N/A';

    // Split the address into words
    $words = explode(' ', $address);
    $word_count = count($words);

    // Create the first line (first 4 words) and the rest
    $first_line = implode(' ', array_slice($words, 0, 4));
    $second_line = implode(' ', array_slice($words, 4));
    $formatted_address = $first_line . ($second_line ? "\n" . $second_line : '');

    // Set font and calculate width
    $pdf->SetFont('FuturaBT-Medium', '', 14);
    $width = max($pdf->GetStringWidth($first_line), $pdf->GetStringWidth($second_line)) + 5; // Use widest line for width
    $width = max($width, 90); // Minimum width of 90

    // Render the address with reduced line height
    $pdf->SetXY(20, 107);
    $pdf->MultiCell($width, 6, $formatted_address, 0, 'L'); // Reduced line height from 8 to 6

    // Calculate the Y position for the organization name based on the address height
    $address_lines = substr_count($formatted_address, "\n") + 1; // Number of lines in address
    $y_position = 107 + ($address_lines * 6); // Adjust Y based on number of lines and line height

    // Render the organization name
    $organizationName = $invoice['organization'] ?? '';
    $pdf->SetXY(20, $y_position);
    $pdf->MultiCell($width, 6, $organizationName, 0, 'L'); // Reduced line height from 8 to 6

    // Table headers (just labels, not a formal table)
    $pdf->SetFont('FuturaBT-Medium', '', 10);
    $pdf->SetXY(20, 125);
    $pdf->Cell(0, 5, 'Description', 0, 0);

    $pdf->SetXY(90, 125);
    $pdf->Cell(0, 5, 'Quantity', 0, 0);

    $pdf->SetXY(130, 125);
    $pdf->Cell(0, 5, 'Rate', 0, 0);

    $pdf->SetXY(170, 125);
    $pdf->Cell(0, 5, 'Amount', 0, 0);

    // Line under headers
    $pdf->SetLineWidth(0.1);
    $pdf->SetDrawColor(158, 158, 158); // Set line color to rgba(158, 158, 158, 1)
    $pdf->Line(20, 130, 190, 130);
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black


    $y = 135;
    $lineHeight = 7;

    $passenger_details = json_decode($invoice['passenger_details'], true);
    $total_quantity = 0; // Initialize as integer
    $output = []; // Array to store type-quantity strings

    foreach ($passenger_details as $passenger) {
        $total_quantity += (int) $passenger['quantity']; // Sum quantities
        $output[] = $passenger['type'] . '-' . $passenger['quantity']; // Build type-quantity string
    }
    $result = implode(', ', $output); // Join strings with comma and space




    foreach ($services as $item) {
        $pdf->SetFont('FuturaBT-Medium', '', 10);
        $pdf->SetXY(20, 130);
        $pdf->Cell(0, $lineHeight, $invoice['airline_name'], 0, 1); // First line: Airline name
        $pdf->SetFont('FuturaBT-Medium', '', 9.5);
        $pdf->SetXY(20, 133);
        $pdf->Cell(0, $lineHeight, "(" . $invoice['from_location'] . ") -", 0, 1); // Second line: Route
        $pdf->SetXY(20, 136);
        $pdf->Cell(0, $lineHeight, "(" . $invoice['to_location'] . ")", 0, 1); // Second line: Route

        $pdf->SetFont('FuturaBT-Medium', '', 14);
        $pdf->SetXY(20, 140);
        $pdf->Cell(0, $lineHeight, $item['name'], 0, 0);

        $pdf->SetFont('FuturaBT-Medium', '', 8);
        $pdf->SetXY(90, $y);
        $pdf->Cell(0, $lineHeight, $result, 0, 0);

        $pdf->SetXY(130, $y);
        $pdf->Cell(0, $lineHeight, $currencySymbol . "." . $invoice['total_amount'], 0, 0);

        $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
        $pdf->SetXY(170, $y);
        $pdf->Cell(0, $lineHeight, $currencySymbol . "." . $invoice['total_amount'], 0, 0);
        $pdf->SetTextColor(0, 0, 0);

        $y += $lineHeight;
    }

    // Line after items
    $pdf->SetLineWidth(0.1);
    $pdf->SetDrawColor(158, 158, 158); // Set line color to rgba(158, 158, 158, 1)
    $pdf->Line(20, $y + 5, 190, $y + 5);
    $pdf->SetTextColor(0, 0, 0); // Reset text color to black


    // Totals section
    $pdf->SetFont('FuturaBT-Medium', '', 10);
    $y += 10;

    $pdf->SetXY(140, $y);
    $pdf->Cell(0, 5, 'Sub Total:', 0, 0);
    $pdf->SetXY(160, $y);
    $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
    $pdf->Cell(0, 5, $currencySymbol . "." . $invoice['total_amount'], 0, 0);
    $pdf->SetTextColor(0, 0, 0);

    $y += 7;
    $pdf->SetXY(140, $y);
    $pdf->Cell(0, 5, 'Total:', 0, 0);
    $pdf->SetXY(160, $y);
    $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
    $pdf->Cell(0, 5, $currencySymbol . "." . $invoice['total_amount'], 0, 0);
    $pdf->SetTextColor(0, 0, 0);

    $status = $invoice['status'] == "PAID" ? date('d M Y', strtotime($invoice['created_at'])) : "";
    $y += 7;
    $pdf->SetXY(140, $y);
    $pdf->Cell(0, 5, 'Paid to Date:', 0, 0);
    $pdf->SetXY(165, $y); // Increased X-coordinate from 160 to 165 for a larger gap
    $pdf->SetTextColor(14, 139, 206); // Set text color to rgba(14, 139, 206, 1)
    $pdf->Cell(0, 5, $status, 0, 0);
    $pdf->SetTextColor(0, 0, 0);

    // Line under totals
    $pdf->SetLineWidth(0.1);
    $pdf->Line(140, $y + 5, 190, $y + 5);


    // Invoice Note
    $pdf->SetFont('FuturaBT-Medium', '', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(20, 185);
    $pdf->Cell(0, 10, 'Invoice Note:', 0, 1);

    // Start bullet points
    $yPos = 195;
    $pdf->SetFont('FuturaBT-Medium', '', 10);
    $pdf->SetXY(25, $yPos);
    $pdf->Cell(0, 10, 'All the payments must be paid in NZD by the due date unless mentioned or credit limit offered.', 0, 1);
    $yPos += 5;

    $pdf->SetXY(25, $yPos);
    $pdf->Cell(0, 10, 'Any payments made by bank credit card will attract 2.5% CC fee.', 0, 1);
    $yPos += 10;

    $pdf->SetFont('FuturaBT-Medium', '', 10);
    $pdf->SetXY(25, $yPos);
    $pdf->Cell(0, 10, 'All account transfers shall be made into below account details', 0, 1);
    $yPos += 7;

    $pdf->SetXY(25, $yPos);
    $pdf->Cell(0, 10, 'ACCOUNT NAME: THE FLIGHTSHUB PVT. LTD.', 0, 1);
    $yPos += 5;

    $pdf->SetTextColor(14, 139, 206);
    $pdf->SetXY(25, $yPos);
    $pdf->Cell(0, 10, 'ANZ :: 01-1842-0636659-00', 0, 1);
    $yPos += 5;

    $pdf->SetXY(25, $yPos);
    $pdf->Cell(0, 10, 'ASB :: 12-3142-0494687-00', 0, 1);
    $yPos += 5;

    $pdf->SetXY(25, $yPos);
    $pdf->Cell(0, 10, 'BNZ :: 02-0528-0567582-000', 0, 1);
    $pdf->SetFont('FuturaBT-Medium', '', 12); // Reset font to normal
    $pdf->SetXY(70, 240);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(22, 10, 'Thank you for your business', 0, 0); // Render "Invoice No:" in black, normal font
    $pdf->SetXY(55, 245);
    $pdf->Cell(22, 10, 'We wish you a safe and pleasant journey', 0, 0); // Render "Invoice No:" in black, normal font


    // Add paid stamp if invoice is paid
    if ($invoice['status'] == "PAID") {
        $stampPath = 'public/assets/stamp/paid_stamp.png';
        if (file_exists($stampPath)) {
            $pdf->Image($stampPath, 155, 173, 35, 25);
        }
    }

    // Add paid stamp if invoice is PENDING 
    if ($invoice['status'] == "PENDING") {
        $stampPath = 'public/assets/stamp/pending_stamp.png';
        if (file_exists($stampPath)) {
            // Medium stamp (30x30 pixels) - recommended
            $pdf->Image($stampPath, 140, 170, 50, 20);
        }
    }

    // Add paid stamp if invoice is paid
    if ($invoice['status'] == "CANCELLED") {
        $stampPath = 'public/assets/stamp/cancel_stamp.png';
        if (file_exists($stampPath)) {
            // Medium stamp (30x30 pixels) - recommended
            $pdf->Image($stampPath, 60, 145, 80, 35);
        }
    }

    // Output final PDF
    $pdf->Output("I", "Final_Invoice_{$invoice['invoice_number']}.pdf");





} catch (Exception $e) {
    echo "Error: " . htmlspecialchars($e->getMessage());
}