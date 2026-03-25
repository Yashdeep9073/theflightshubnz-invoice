<?php
session_start();

require './vendor/autoload.php';
require './database/config.php';
require './utility/env.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION["admin_id"])) {
    header("Location: index.php");
    exit();
}


try {

    $stmtFetchLocalizationSettings = $db->prepare("SELECT * FROM localization_settings INNER JOIN currency ON localization_settings.currency_id = currency.currency_id;");
    $stmtFetchLocalizationSettings->execute();
    $localizationSettings = $stmtFetchLocalizationSettings->get_result()->fetch_array(MYSQLI_ASSOC);

    $stmtFetchCompanySettings = $db->prepare("SELECT * FROM company_settings");
    $stmtFetchCompanySettings->execute();
    $companySettings = $stmtFetchCompanySettings->get_result()->fetch_array(MYSQLI_ASSOC);

    $stmtFetch = $db->prepare("SELECT * FROM email_settings WHERE is_active = 1 LIMIT 1");
    $stmtFetch->execute();
    $emailSettingData = $stmtFetch->get_result()->fetch_assoc();

    // === Email Settings Fallbacks ===
    $host = $emailSettingData['email_host'] ?? getenv("SMTP_HOST");
    $userName = $emailSettingData['email_address'] ?? getenv('SMTP_USER_NAME');
    $password = $emailSettingData['email_password'] ?? getenv('SMTP_PASSCODE');
    $port = $emailSettingData['email_port'] ?? getenv('SMTP_PORT');
    $fromTitle = $emailSettingData['email_from_title'] ?? "Vibrantick InfoTech Solution";
    $logoUrl = getenv("BASE_URL") . $emailSettingData['logo_url'] ?? 'https://vibrantick.in/assets/images/logo/footer.png ';

    $supportEmail = $emailSettingData['support_email'] ?? 'support@vibrantick.org';
    $phone = $emailSettingData['phone'] ?? '+919870443528';
    $address1 = $emailSettingData['address_line1'] ?? 'Vibrantick InfoTech Solution | D-185, Phase 8B, Sector 74, SAS Nagar';
    $linkedin = $emailSettingData['linkedin_url'] ?? 'https://www.linkedin.com/company/vibrantick-infotech-solutions/posts/?feedView=all';
    $instagram = $emailSettingData['ig_url'] ?? ' https://www.instagram.com/vibrantickinfotech/ ';
    $facebook = $emailSettingData['fb_url'] ?? 'https://www.facebook.com/vibranticksolutions/ ';
    $googleUrl = $emailSettingData['google_url'] ?? 'https://share.google/TV95iiaQa4aniAgHV';
    $whatsappUrl = $emailSettingData['whatsapp_url'] ?? 'https://api.whatsapp.com/send?phone=64224226675';
    $currentYear = date("Y");


    if ($_SERVER['REQUEST_METHOD'] == 'GET') {
        // Define the expected query parameters
        $params = [
            'customer' => isset($_GET['customer']) ? $_GET['customer'] : '',
            'from' => isset($_GET['from']) ? $_GET['from'] : '',
            'to' => isset($_GET['to']) ? $_GET['to'] : '',
        ];

        // Check if at least one parameter is present (non-empty)
        $hasParams = false;
        foreach ($params as $value) {

            if ($value !== '') {
                $hasParams = true;
                break;
            }
        }

        if ($hasParams) {

            $customerId = $params['customer'] ?? null;
            $startDate = $params['from'] ?? null;
            $endDate = $params['to'] ?? null;

            // Clean and format dates
            $startDate = $startDate ? date('Y-m-d', strtotime($startDate)) : null;
            $endDate = $endDate ? date('Y-m-d', strtotime($endDate)) : null;

            // Prepare SQL with conditions
            $query = "SELECT 
            invoice.*,
            invoice.status as invoiceStatus,
            customer.customer_id,
            customer.customer_name,
            admin.admin_username
            FROM invoice 
            INNER JOIN customer ON customer.customer_id = invoice.customer_id
            LEFT JOIN admin ON admin.admin_id = invoice.created_by 
            WHERE invoice.is_active = 1";

            $conditions = [];
            $paramsToBind = [];

            if ($customerId) {
                $conditions[] = "invoice.customer_id = ?";
                $paramsToBind[] = $customerId;
            }

            if ($startDate) {
                $conditions[] = "DATE(invoice.created_at) >= ?";
                $paramsToBind[] = $startDate;
            }

            if ($endDate) {
                $conditions[] = "DATE(invoice.created_at) <= ?";
                $paramsToBind[] = $endDate;
            }

            if (!empty($conditions)) {
                $query .= " AND " . implode(" AND ", $conditions);
            }

            $stmtFetchInvoices = $db->prepare($query);

            if ($stmtFetchInvoices === false) {
                $_SESSION['error'] = 'Query preparation failed';
            } else {
                // Bind parameters dynamically
                if (!empty($paramsToBind)) {
                    $types = str_repeat("s", count($paramsToBind)); // all are strings
                    $stmtFetchInvoices->bind_param($types, ...$paramsToBind);
                }

                if ($stmtFetchInvoices->execute()) {
                    $invoices = $stmtFetchInvoices->get_result();
                } else {
                    $_SESSION['error'] = 'Error fetching filtered invoices';
                }

                $stmtFetchInvoices->close();
            }

        } else {
            $stmtFetchInvoices = $db->prepare("SELECT 
                invoice.*,
                invoice.status as invoiceStatus,
                admin.admin_username
                FROM invoice 
                LEFT JOIN admin
                ON admin.admin_id = invoice.created_by 
                WHERE invoice.is_active = 1
                ORDER BY invoice.invoice_id ASC
                ");
            if ($stmtFetchInvoices->execute()) {
                $invoices = $stmtFetchInvoices->get_result();
            } else {
                $_SESSION['error'] = 'Error for fetching customers';
            }

            // Fetch customers
            $stmtFetchCustomers = $db->prepare("SELECT * FROM customer WHERE isActive = 1");
            $stmtFetchCustomers->execute();
            $customers = $stmtFetchCustomers->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtFetchCustomers->close();
        }
    }


} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invoiceId'])) {

    $invoiceId = intval($_POST['invoiceId']);

    if ($invoiceId <= 0) {
        echo json_encode([
            'status' => 400,
            'message' => 'Invalid invoice ID.'
        ]);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE invoice SET is_active = 0 WHERE invoice_id = ?");
        $stmt->bind_param("i", $invoiceId);

        if ($stmt->execute()) {
            echo json_encode([
                'status' => 200,
                'message' => 'Selected invoice deleted successfully.'
            ]);
        } else {
            echo json_encode([
                'status' => 400,
                'message' => $stmt->error
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'status' => 500,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }

    exit;
}


// send reminder
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['invoiceIdForReminder'])) {

    try {

        $invoiceId = intval($_POST['invoiceIdForReminder']);
        $stmtFetchCustomer = $db->prepare("SELECT * FROM invoice 
        WHERE is_active = 1 AND invoice_id = ? AND status IN ('PENDING')");

        $stmtFetchCustomer->bind_param('i', $invoiceId);

        if ($stmtFetchCustomer->execute()) {
            $invoices = $stmtFetchCustomer->get_result()->fetch_assoc();

            // Check if the invoice exists
            if (empty($invoices)) {
                echo json_encode([
                    'status' => 404,
                    'message' => "Invoice Status is Not Pending"
                ]);
                exit; // Stop further execution
            }

        } else {
            echo json_encode([
                'status' => 500,
                'message' => "Database Query Execution Failed"
            ]);
            exit;
        }

        $stmtFetchEmailTemplates = $db->prepare("SELECT * FROM email_template WHERE is_active = 1 AND type = 'REMINDER' ");
        $stmtFetchEmailTemplates->execute();
        $emailTemplate = $stmtFetchEmailTemplates->get_result()->fetch_array(MYSQLI_ASSOC);

        // === Email Template Fallbacks ===
        $templateTitle = $emailTemplate['email_template_title'] ?? 'Payment Reminder';
        $emailSubject = $emailTemplate['email_template_subject'] ?? 'Payment Reminder: Overdue Invoices';

        $content1 = !empty($emailTemplate['content_1'])
            ? nl2br(trim($emailTemplate['content_1']))
            : '<p>We hope this message finds you well. The following invoice(s) are overdue. Kindly make the payment at your earliest convenience to avoid any service interruptions.</p>';

        $content2 = !empty($emailTemplate['content_2'])
            ? nl2br(trim($emailTemplate['content_2']))
            : '
        <p>Please settle the outstanding amount at your earliest convenience. For any questions or assistance, contact our support team at <a href="mailto:support@vibrantick.org">support@vibrantick.org</a> or call <a href="tel:+919870443528">+91-9870443528</a>.</p>
        <p>Thank you for your prompt attention to this matter.</p>
        <p>Best regards,<br>Vibrantick InfoTech Solution Team</p>';




        $customerName = htmlspecialchars($invoices['customer_name']);
        $customerEmail = htmlspecialchars($invoices['customer_email']);
        $invoiceNumber = htmlspecialchars($invoices['invoice_number']);
        $dueDate = htmlspecialchars($invoices['due_date']);
        $totalAmount = number_format((float) $invoices['total_amount'], 2);

        // Initialize PHPMailer
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0; // Set to 2 for debugging
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $userName;
        $mail->Password = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // 'ssl'
        $mail->Port = $port;
        $mail->setFrom($userName, $fromTitle);
        $mail->addCC($userName);
        $mail->isHTML(true);

        // Prepare statement for updating reminder_count
        $stmtUpdate = $db->prepare('UPDATE invoice SET reminder_count = reminder_count + 1 WHERE invoice_id = ?');

        $emailBody = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$templateTitle}</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 0;
                        padding: 0;
                        background-color: #f4f4f4;
                    }
                    .container {
                        max-width: 600px;
                        margin: 20px auto;
                        background-color: #ffffff;
                        border-radius: 8px;
                        overflow: hidden;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                    }
                    .header {
                        background-color: #f4f4f4;
                        padding: 20px;
                        text-align: center;
                        color: #333333;
                    }
                    .header img {
                        max-width: 150px;
                        height: auto;
                        /* background-color: #fff; */
                        border-radius: 4px;
                    }
                    .header h1 {
                        margin: 10px 0;
                        font-size: 24px;
                        font-weight: bolder;
                    }
                    .content {
                        padding: 20px;
                    }
                    .content p {
                        line-height: 1.6;
                        color: #333333;
                    }
                    .invoice-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin: 20px 0;
                    }
                    .invoice-table th,
                    .invoice-table td {
                        border: 1px solid #dddddd;
                        padding: 12px;
                        text-align: left;
                    }
                    .invoice-table th {
                        background-color: #f9522b;
                        color: #ffffff;
                        font-weight: bold;
                    }
                    .invoice-table tr:nth-child(even) {
                        background-color: #f9f9f9;
                    }
                    .invoice-table tr:hover {
                        background-color: #f1f1f1;
                    }
                    .footer {
                        background-color: #f4f4f4;
                        padding: 15px;
                        text-align: center;
                        font-size: 12px;
                        color: #666666;
                    }
                    .footer a {
                        color: #f9522b;
                        text-decoration: none;
                        margin: 0 10px;
                    }
                    .footer img {
                        width: 24px;
                        height: 24px;
                        vertical-align: middle;
                    }
                    .button {
                        display: inline-block;
                        padding: 10px 20px;
                        background-color: #f9522b;
                        color: #ffffff;
                        text-decoration: none;
                        border-radius: 5px;
                        margin-top: 20px;
                    }
                    @media only screen and (max-width: 600px) {
                        .container {
                            width: 100%;
                            margin: 10px;
                        }
                        .header img {
                            max-width: 120px;
                        }
                        .header h1 {
                            font-size: 20px;
                        }
                        .invoice-table th,
                        .invoice-table td {
                            font-size: 14px;
                            padding: 8px;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <!-- Header -->
                    <div class="header">
                        <img src="{$logoUrl}" alt="Logo" />
                        <h1>{$templateTitle}</h1>
                    </div>

                    <!-- Content -->
                    <div class="content">
                        <p>Dear {$customerName},</p>
                        {$content1}

                        <!-- Invoice Table -->
                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th>Invoice Number</th>
                                    <th>Due Date</th>
                                    <th>Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{$invoiceNumber}</td>
                                    <td>{$dueDate}</td>
                                    <td><strong>{$localizationSettings["currency_code"]} {$totalAmount}</strong></td>
                                </tr>
                            </tbody>
                        </table>

                        {$content2}
                    </div>

                    <!-- Footer -->
                    <div class="footer">
                        <p>&copy; {$currentYear} {$fromTitle}. All rights reserved.</p>
                        <p>{$address1} <a href='mailto:{$supportEmail}'>{$supportEmail}</a></p>
                        <p>NZBN No: {$companySettings['bz_number']}</p>
                        <p>
                            <a href='{$googleUrl}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/google.png ' alt='Google'></a>
                            <a href='{$facebook}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/facebook.png ' alt='Facebook'></a>
                            <a href='{$instagram}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/instagram.png ' alt='Instagram'></a>
                            <a href='{$whatsappUrl}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/whatsapp.png ' alt='Whatsapp'></a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
            HTML;

        $mail->clearAddresses();
        $mail->addAddress($customerEmail, $customerName);
        $mail->Subject = $emailSubject;
        $mail->Body = $emailBody;

        if ($mail->send()) {

            $stmtUpdate->bind_param('i', $invoiceId);
            if (!$stmtUpdate->execute()) {
                echo "Failed to update reminder_count for invoice {$invoiceId}\n";
            }
            echo json_encode([
                'status' => 200,
                'message' => 'The Mail has been send to ' . $customerName,
                'data' => $logoUrl
            ]);
            exit;
        } else {
            echo json_encode([
                'status' => 403,
                'message' => 'Unable to Send Mail to ' . $customerName,
            ]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode([
            'status' => 500,
            'error' => $e->getMessage(),
        ]);
        exit;
    }
}

// send invoice
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['invoiceIdForSend'])) {

    try {

        $invoiceId = intval($_POST['invoiceIdForSend']);
        $stmtFetchCustomer = $db->prepare("SELECT * FROM invoice 
        WHERE invoice.is_active = 1 AND invoice.invoice_id = ? AND status IN ('PAID')");

        $stmtFetchCustomer->bind_param('i', $invoiceId);

        if ($stmtFetchCustomer->execute()) {
            $invoices = $stmtFetchCustomer->get_result()->fetch_assoc();

            // Check if the invoice exists
            if (empty($invoices)) {
                echo json_encode([
                    'status' => 404,
                    'message' => "Invoice Status is Not Paid"
                ]);
                exit; // Stop further execution
            }

        } else {
            echo json_encode([
                'status' => 500,
                'message' => "Database Query Execution Failed"
            ]);
            exit;
        }

        //GENERATE PDF CONTENT FROM download-invoice.php
        ob_start(); // Capture any output

        // Simulate GET request to download-invoice.php
        $_GET['id'] = base64_encode($invoiceId); // Match how download-invoice.php expects it

        // Include the file – it will generate PDF in memory
        require './download-invoice.php'; // This calls $pdf->Output() internally

        $pdfContent = ob_get_clean(); // Capture raw PDF output

        if (empty($pdfContent)) {
            echo json_encode(['status' => 500, 'message' => 'Failed to generate PDF']);
            exit;
        }

        $stmtFetchEmailTemplates = $db->prepare("SELECT * FROM email_template WHERE is_active = 1 AND type = 'ISSUED' ");
        $stmtFetchEmailTemplates->execute();
        $emailTemplate = $stmtFetchEmailTemplates->get_result()->fetch_array(MYSQLI_ASSOC);

        // === Email Template Fallbacks ===
        $templateTitle = $emailTemplate['email_template_title'] ?? 'Invoice Issued';
        $emailSubject = $emailTemplate['email_template_subject'] ?? 'Payment Request: Invoice from Vibrantick InfoTech';

        $content1 = !empty($emailTemplate['content_1'])
            ? nl2br(trim($emailTemplate['content_1']))
            : '<p>We are pleased to inform you that your invoice has been successfully generated. Please review the details below and make the payment before the due date to ensure uninterrupted service.</p>';

        $content2 = !empty($emailTemplate['content_2'])
            ? nl2br(trim($emailTemplate['content_2']))
            : '
            <p>If you have already made this payment, thank you! Please disregard this email or contact us if you need a receipt. For any questions or assistance, contact our support team at <a href="mailto:support@vibrantick.org">support@vibrantick.org</a> or call <a href="tel:+919870443528">+91-9870443528</a>.</p>
            <p>Thank you for your prompt attention to this matter.</p>
            <p>Best regards,<br>Vibrantick InfoTech Solution Team</p>';


        $customerName = htmlspecialchars($invoices['customer_name']);
        $customerEmail = htmlspecialchars($invoices['customer_email']);
        $invoiceNumber = htmlspecialchars($invoices['invoice_number']);
        $travel_date = htmlspecialchars($invoices['travel_date']);
        $totalAmount = number_format((float) $invoices['total_amount'], 2);

        // Initialize PHPMailer
        $mail = new PHPMailer(true);
        $mail->SMTPDebug = 0; // Set to 2 for debugging
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $userName;
        $mail->Password = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // 'ssl'
        $mail->Port = $port;
        $mail->setFrom($userName, $fromTitle);
        $mail->addCC($userName);
        $mail->isHTML(true);

        // Prepare statement for updating reminder_count
        $stmtUpdate = $db->prepare('UPDATE invoice SET reminder_count = reminder_count + 1 WHERE invoice_id = ?');

        $emailBody = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$templateTitle}</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 0;
                        padding: 0;
                        background-color: #f4f4f4;
                    }
                    .container {
                        max-width: 600px;
                        margin: 20px auto;
                        background-color: #ffffff;
                        border-radius: 8px;
                        overflow: hidden;
                        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                    }
                    .header {
                        background-color: #f4f4f4;
                        padding: 20px;
                        text-align: center;
                        color: #333333;
                    }
                    .header img {
                        max-width: 150px;
                        height: auto;
                        /* background-color: #fff; */
                        border-radius: 4px;
                    }
                    .header h1 {
                        margin: 10px 0;
                        font-size: 24px;                        
                        font-weight: bolder;
                    }
                    .content {
                        padding: 20px;
                    }
                    .content p {
                        line-height: 1.6;
                        color: #333333;
                    }
                    .invoice-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin: 20px 0;
                    }
                    .invoice-table th,
                    .invoice-table td {
                        border: 1px solid #dddddd;
                        padding: 12px;
                        text-align: left;
                    }
                    .invoice-table th {
                        background-color: #f9522b;
                        color: #ffffff;
                        font-weight: bold;
                    }
                    .invoice-table tr:nth-child(even) {
                        background-color: #f9f9f9;
                    }
                    .invoice-table tr:hover {
                        background-color: #f1f1f1;
                    }
                    .footer {
                        background-color: #f4f4f4;
                        padding: 15px;
                        text-align: center;
                        font-size: 12px;
                        color: #666666;
                    }
                    .footer a {
                        color: #f9522b;
                        text-decoration: none;
                        margin: 0 10px;
                    }
                    .footer img {
                        width: 24px;
                        height: 24px;
                        vertical-align: middle;
                    }
                    .button {
                        display: inline-block;
                        padding: 10px 20px;
                        background-color: #f9522b;
                        color: #ffffff;
                        text-decoration: none;
                        border-radius: 5px;
                        margin-top: 20px;
                    }
                    @media only screen and (max-width: 600px) {
                        .container {
                            width: 100%;
                            margin: 10px;
                        }
                        .header img {
                            max-width: 120px;
                        }
                        .header h1 {
                            font-size: 20px;
                        }
                        .invoice-table th,
                        .invoice-table td {
                            font-size: 14px;
                            padding: 8px;
                        }
                    }
                </style>
            </head>
            <body>
                <div class="container">
                    <!-- Header -->
                    <div class="header">
                        <img src="{$logoUrl}" alt="Logo" />
                        <h1>{$templateTitle}</h1>
                    </div>

                    <!-- Content -->
                    <div class="content">
                        <p>Dear {$customerName},</p>
                        {$content1}

                        <!-- Invoice Table -->
                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th>Invoice Number</th>
                                    <th>Travel Date</th>
                                    <th>Total Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{$invoiceNumber}</td>
                                    <td>{$travel_date}</td>
                                    <td><strong>{$localizationSettings["currency_code"]} {$totalAmount}</strong></td>
                                </tr>
                            </tbody>
                        </table>

                        {$content2}
                    </div>

                    <!-- Footer -->
                    <div class="footer">
                        <p>&copy; {$currentYear} {$fromTitle}. All rights reserved.</p>
                        <p>{$address1} <a href='mailto:{$supportEmail}'>{$supportEmail}</a></p>
                        <p>NZBN No: {$companySettings['bz_number']}</p>
                        <p>
                            <a href='{$googleUrl}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/google.png ' alt='Google'></a>
                            <a href='{$facebook}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/facebook.png ' alt='Facebook'></a>
                            <a href='{$instagram}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/instagram.png ' alt='Instagram'></a>
                            <a href='{$whatsappUrl}' target='_blank'><img src='https://vault.theflightshub.co.nz/assets/img/icons/whatsapp.png ' alt='Whatsapp'></a>
                        </p>
                    </div>
                </div>
            </body>
            </html>
            HTML;

        $mail->clearAddresses();
        $mail->addAddress($customerEmail, $customerName);
        $mail->Subject = $emailSubject;
        $mail->Body = $emailBody;

        // ✅ Attach the generated PDF as file
        $mail->addStringAttachment($pdfContent, "Invoice-$invoiceNumber.pdf", 'base64', 'application/pdf');

        if ($mail->send()) {

            $stmtUpdate->bind_param('i', $invoiceId);
            if (!$stmtUpdate->execute()) {
                echo "Failed to update reminder_count for invoice {$invoiceId}\n";
            }
            echo json_encode([
                'status' => 200,
                'message' => 'The Mail has been send to ' . $customerName,
                'data' => $logoUrl
            ]);
            exit;
        } else {
            echo json_encode([
                'status' => 403,
                'message' => 'Unable to Send Mail to ' . $customerName,
            ]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode([
            'status' => 500,
            'error' => $e->getMessage(),
            'message' => $e->getMessage(),
        ]);
        exit;
    }
}

// Send Receipt
// Send Receipt
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['invoiceIdForSendReceipt'])) {

    try {

        $invoiceId = intval($_POST['invoiceIdForSendReceipt']);

        // =========================
        // FETCH INVOICE + CUSTOMER
        // =========================
        $stmt = $db->prepare("
        SELECT i.*, c.gst_number, c.customer_address 
        FROM invoice i
        LEFT JOIN customer c ON c.customer_email = i.customer_email
        WHERE i.is_active = 1 AND i.invoice_id = ? AND i.status = 'PAID'
    ");

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        $stmt->bind_param('i', $invoiceId);

        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }

        $invoice = $stmt->get_result()->fetch_assoc();

        if (empty($invoice)) {
            echo json_encode([
                'status' => 404,
                'message' => "Invoice not found or not PAID"
            ]);
            exit;
        }

        // =========================
        // FETCH COMPANY SETTINGS
        // =========================
        $companyQuery = $db->prepare("SELECT * FROM company_settings LIMIT 1");

        if (!$companyQuery) {
            throw new Exception("Company prepare failed: " . $db->error);
        }

        if (!$companyQuery->execute()) {
            throw new Exception("Company execute failed: " . $companyQuery->error);
        }

        $company = $companyQuery->get_result()->fetch_assoc();

        // =========================
        // FORMAT DATA
        // =========================
        $customerName = htmlspecialchars($invoice['customer_name'] ?? '');
        $customerEmail = htmlspecialchars($invoice['customer_email'] ?? '');
        $invoiceNumber = htmlspecialchars($invoice['invoice_number'] ?? '');
        $travelDate = htmlspecialchars($invoice['travel_date'] ?? '');
        $invoiceDate = date('d-m-Y', strtotime($invoice['created_at']));
        $totalAmount = number_format((float) $invoice['total_amount'], 2);
        $organization = htmlspecialchars($invoice['organization'] ?? '');
        $address = nl2br(htmlspecialchars($invoice['customer_address'] ?? ''));

        // Company
        $companyName = htmlspecialchars($company['company_name'] ?? '');
        $companyEmail = htmlspecialchars($company['company_email'] ?? '');
        $companyPhone = htmlspecialchars($company['company_phone'] ?? '');
        $companyWeb = htmlspecialchars($company['company_website'] ?? '');
        $companyGST = htmlspecialchars($company['gst_number'] ?? '-');
        $companyBZ = htmlspecialchars($company['bz_number'] ?? '-');
        $companyAddr = nl2br(htmlspecialchars($company['address'] ?? ''));

        // =========================
        // PASSENGER + TICKET LOGIC
        // =========================
        $passengers = json_decode($invoice['passenger_details'], true);

        $ticketNumbersRaw = $invoice['ticket_number'] ?? '';
        $decodedTickets = json_decode($ticketNumbersRaw, true);

        if (is_array($decodedTickets)) {
            $ticketNumbers = $decodedTickets;
        } else {
            $ticketNumbers = array_map('trim', explode(',', $ticketNumbersRaw));
        }

        $passengerRows = '';

        if (!empty($passengers) && is_array($passengers)) {
            foreach ($passengers as $index => $p) {

                $ticketNo = htmlspecialchars($ticketNumbers[$index] ?? '');
                $type = htmlspecialchars($p['type'] ?? '');
                $qty = htmlspecialchars($p['quantity'] ?? '0');

                $passengerRows .= "
                <tr>
                    <td>{$ticketNo}</td>
                    <td>{$type} ({$qty})</td>
                    <td>{$invoice['from_location']} - {$invoice['to_location']}</td>
                    <td>{$invoice['airline_name']}</td>
                    <td>{$travelDate}</td>
                </tr>
            ";
            }
        } else {
            $passengerRows = "
            <tr>
                <td colspan='5' align='center'>No passenger data</td>
            </tr>
        ";
        }

        // =========================
        // EMAIL TEMPLATE
        // =========================
        $emailBody = "
            <html>
            <body style='margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;'>

            <table width='100%' cellpadding='0' cellspacing='0'>
            <tr>
            <td align='center'>

            <!-- MAIN CONTAINER -->
            <table width='800' cellpadding='10' cellspacing='0' style='background:#ffffff;border:1px solid #000;'>

            <!-- HEADER -->
            <tr>
            <td colspan='2' style='border-bottom:2px solid #000;'>
                <table width='100%'>
                    <tr>

                        <!-- COMPANY -->
                        <td style='vertical-align:top;'>
                            <strong style='font-size:18px;'>{$companyName}</strong><br>
                            {$companyAddr}<br>
                            Email: {$companyEmail}<br>
                            Phone: {$companyPhone}<br>
                            Website: {$companyWeb}
                        </td>

                        <!-- INVOICE INFO -->
                        <td align='right' style='vertical-align:top;'>
                            <strong style='font-size:16px;'>TAX INVOICE</strong><br><br>
                            Invoice No: {$invoiceNumber}<br>
                            Invoice Date: {$invoiceDate}<br>
                            Travel Date: {$travelDate}<br>
                            GST No: {$companyGST}<br>
                            BZ No: {$companyBZ}
                        </td>

                    </tr>
                </table>
            </td>
            </tr>

            <!-- CUSTOMER -->
            <tr>
            <td colspan='2'>
                <table width='100%'>
                    <tr>

                        <td style='vertical-align:top;'>
                            <strong>Customer Name:</strong> {$customerName}<br>
                            <strong>Business:</strong> {$organization}<br>
                            <strong>Address:</strong><br>
                            {$address}
                        </td>

                        <td style='text-align:right;vertical-align:top;'>
                            <strong>From:</strong> {$invoice['from_location']}<br>
                            <strong>To:</strong> {$invoice['to_location']}
                        </td>

                    </tr>
                </table>
            </td>
            </tr>

            <!-- PASSENGER TABLE -->
            <tr>
            <td colspan='2'>

            <table width='100%' border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;'>

            <tr style='background:#000;color:#fff;text-align:center;'>
                <th>Ticket No</th>
                <th>Name</th>
                <th>Sector</th>
                <th>Carrier</th>
                <th>Travel Date</th>
            </tr>

            {$passengerRows}

            </table>

            </td>
            </tr>

            <!-- TOTAL -->
            <tr>
            <td colspan='2' align='right' style='padding-top:15px;'>
                <strong style='font-size:16px;'>Total: $ {$totalAmount}</strong>
            </td>
            </tr>

            <!-- FOOTER -->
            <tr>
            <td colspan='2' style='border-top:1px solid #000;padding-top:10px;font-size:12px;color:#555;'>

            <table width='100%' cellpadding='5' cellspacing='0'>
            <tr>

            <!-- TERMS -->
            <td width='70%' style='vertical-align:top;'>
            <b>Terms & Conditions:</b><br>
            • This is a computer generated invoice and does not require signature.<br>
            • All disputes are subject to jurisdiction.<br>
            • Interest may be charged on overdue payments.<br>
            • Please contact support for any discrepancies within 7 days.<br>
            </td>

            <!-- SIGNATURE -->
            <td width='30%' style='text-align:right;vertical-align:top;'>
            <b>For {$companyName}</b><br><br><br>
            Authorised Signatory
            </td>

            </tr>
            </table>

            </td>
            </tr>

            </table>
            <!-- END MAIN CONTAINER -->

            </td>
            </tr>
            </table>

            </body>
            </html>
            ";

        // =========================
        // MAIL
        // =========================
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $userName;
        $mail->Password = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $port;

        $mail->setFrom($userName, $companyName);
        $mail->addAddress($customerEmail, $customerName);
        $mail->isHTML(true);

        $mail->Subject = "Payment Receipt - {$invoiceNumber}";
        $mail->Body = $emailBody;

        if (!$mail->send()) {
            throw new Exception("Mail sending failed");
        }

        echo json_encode([
            'status' => 200,
            'message' => "Receipt sent successfully to {$customerName}"
        ]);
        exit;

    } catch (Exception $e) {

        echo json_encode([
            'status' => 500,
            'message' => $e->getMessage()
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'status' => 500,
            'message' => $e->getMessage()
        ]);
    }
}

// delete multiple invoice
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['invoiceIds'])) {


    $invoiceIds = $_POST['invoiceIds'];

    // Validate: Must be an array of integers
    if (!is_array($invoiceIds)) {
        echo json_encode([
            'status' => 400,
            'message' => 'Invalid data format.'
        ]);
        exit;
    }

    try {
        // Prepare the SQL dynamically
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $types = str_repeat('i', count($invoiceIds)); // All integers

        $stmt = $db->prepare("UPDATE invoice SET is_active = 0 WHERE invoice_id IN ($placeholders)");
        $stmt->bind_param($types, ...$invoiceIds);

        if ($stmt->execute()) {
            echo json_encode([
                'status' => 200,
                'message' => 'Selected invoices deleted successfully.',
                'deleted_ids' => $invoiceIds
            ]);
        } else {
            echo json_encode([
                'status' => 400,
                'message' => $stmt->error
            ]);
        }

        exit;
    } catch (Exception $e) {
        echo json_encode([
            'status' => 500,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <meta name="description" content="POS - Bootstrap Admin Template">
    <meta name="keywords"
        content="admin, estimates, bootstrap, business, corporate, creative, invoice, html5, responsive, Projects">
    <meta name="author" content="Dreamguys - Bootstrap Admin Template">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Invoice</title>

    <link rel="shortcut icon" type="image/x-icon"
        href="<?= isset($companySettings['favicon']) ? $companySettings['favicon'] : "assets/img/fav/vis-favicon.png" ?>">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">

    <link rel="stylesheet" href="assets/css/animate.css">

    <link rel="stylesheet" href="assets/plugins/select2/css/select2.min.css">

    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">

    <link rel="stylesheet" href="assets/css/bootstrap-datetimepicker.min.css">

    <link rel="stylesheet" href="assets/plugins/daterangepicker/daterangepicker.css">

    <link rel="stylesheet" href="assets/plugins/summernote/summernote-bs4.min.css">

    <link rel="stylesheet" href="assets/plugins/fontawesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/plugins/fontawesome/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">

    <!-- toast  -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>

    <!-- html to pdf -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

    <!-- html to excel -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

</head>

<body>
    <div id="global-loader">
        <div class="whirly-loader"> </div>
    </div>

    <?php if (isset($_SESSION['success'])) { ?>
        <script>
            const notyf = new Notyf({
                position: {
                    x: 'center',
                    y: 'top'
                },
                types: [
                    {
                        type: 'success',
                        background: '#4dc76f', // Change background color
                        textColor: '#FFFFFF',  // Change text color
                        dismissible: false
                    }
                ]
            });
            notyf.success("<?php echo $_SESSION['success']; ?>");
        </script>
        <?php
        unset($_SESSION['success']);
        ?>
    <?php } ?>

    <?php if (isset($_SESSION['error'])) { ?>
        <script>
            const notyf = new Notyf({
                position: {
                    x: 'center',
                    y: 'top'
                },
                types: [
                    {
                        type: 'error',
                        background: '#ff1916',
                        textColor: '#FFFFFF',
                        dismissible: false
                    }
                ]
            });
            notyf.error("<?php echo $_SESSION['error']; ?>");
        </script>
        <?php
        unset($_SESSION['error']);
        ?>
    <?php } ?>

    <div class="main-wrapper">
        <!-- Header Start -->
        <div class="header">
            <?php require_once("header.php"); ?>
        </div>
        <!-- Header End -->


        <!-- Sidebar Start -->
        <div class="sidebar" id="sidebar">
            <?php require_once("sidebar.php"); ?>
        </div>

        <div class="sidebar collapsed-sidebar" id="collapsed-sidebar">
            <?php require_once("sidebar-collapsed.php"); ?>
        </div>

        <div class="sidebar horizontal-sidebar">
            <?php require_once("sidebar-horizontal.php"); ?>
        </div>
        <!-- Sidebar End -->

        <div class="page-wrapper">
            <div class="content">
                <div class="page-header">
                    <div class="add-item d-flex">
                        <div class="page-title">
                            <h4>Invoice Report </h4>
                            <h6>Manage Your Invoice Report</h6>
                        </div>
                    </div>
                    <ul class="table-top-head">
                        <?php if ($isAdmin || hasPermission('Delete Invoice', $privileges, $roleData['0']['role_name'])): ?>
                            <li>
                                <a data-bs-toggle="tooltip" class="multi-delete-button" data-bs-placement="top"
                                    title="Delete"><img src="assets/img/icons/delete.png" alt="img" /></a>
                            </li>
                        <?php endif; ?>
                        <li>
                            <a data-bs-toggle="tooltip" onclick="exportToPDF()" data-bs-placement="top" title="Pdf"><img
                                    src="assets/img/icons/pdf.svg" alt="img" /></a>
                        </li>
                        <li>
                            <a data-bs-toggle="tooltip" onclick="exportToExcel()" data-bs-placement="top"
                                title="Excel"><img src="assets/img/icons/excel.svg" alt="img" /></a>
                        </li>

                        <li>
                            <a href="manage-invoice.php" data-bs-toggle="tooltip" data-bs-placement="top"
                                title="Refresh"><i data-feather="rotate-ccw" class="feather-rotate-ccw"></i></a>
                        </li>
                        <li>
                            <a data-bs-toggle="tooltip" data-bs-placement="top" title="Collapse" id="collapse-header"><i
                                    data-feather="chevron-up" class="feather-chevron-up"></i></a>
                        </li>
                    </ul>
                    <div class="page-btn">
                        <?php if ($isAdmin || hasPermission('Add Invoice', $privileges, $roleData['0']['role_name'])): ?>

                            <a href="add-invoice.php" class="btn btn-added"><i data-feather="plus-circle"
                                    class="me-2"></i>Add Invoice
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card table-list-card">
                    <div class="card-body">
                        <div class="table-top">
                            <div class="search-set">
                                <div class="search-input">
                                    <a href="" class="btn btn-searchset"><i data-feather="search"
                                            class="feather-search"></i></a>
                                </div>
                            </div>
                            <div class="search-path">
                                <div class="d-flex align-items-center">
                                    <!-- <a class="btn btn-filter" id="filter_search">
                                        <i data-feather="filter" class="filter-icon"></i>
                                        <span><img src="assets/img/icons/closes.svg" alt="img" /></span>
                                    </a> -->
                                </div>
                            </div>
                        </div>

                        <div class="card" id="filter_inputs">
                            <div class="card-body pb-0">
                                <div class="row">
                                    <div class="col-lg-3 col-sm-6 col-12">
                                        <div class="input-blocks">
                                            <i data-feather="user" class="info-img"></i>
                                            <input class="form-control" placeholder="Enter Customer Name" type="text"
                                                name="customerId" required>
                                        </div>
                                    </div>

                                    <div class="col-lg-3 col-sm-6 col-12">
                                        <div class="input-blocks">
                                            <div class="position-relative daterange-wraper">
                                                <input type="date" class="form-control" name="from">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-3 col-sm-6 col-12">
                                        <div class="input-blocks">
                                            <div class="position-relative daterange-wraper">
                                                <input type="date" class="form-control" name="to">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-3 col-sm-6 col-12">
                                        <div class="input-blocks">
                                            <a class="btn btn-filters ms-auto">
                                                <i data-feather="search" class="feather-search"></i>
                                                Search
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <div class="table-responsive">
                            <table id="myTable" class="table  datanew">
                                <thead>
                                    <tr>
                                        <th class="no-sort">
                                            <label class="checkboxs">
                                                <input type="checkbox" id="select-all">
                                                <span class="checkmarks"></span>
                                            </label>
                                        </th>
                                        <th>Invoice No</th>
                                        <th>Customer</th>
                                        <th>Created Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Created By</th>
                                        <th>Status</th>
                                        <th class="no-sort text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $totalTaxAmount = 0;
                                    foreach ($invoices->fetch_all(MYSQLI_ASSOC) as $invoice) { ?>
                                        <tr>
                                            <td>
                                                <label class="checkboxs">
                                                    <input type="checkbox" name="invoiceIds"
                                                        value="<?php echo $invoice['invoice_id'] ?>">
                                                    <span class="checkmarks"></span>
                                                </label>
                                            </td>
                                            <td class="ref-number"><?php echo $invoice['invoice_number'] ?></td>
                                            <td><?php echo $invoice['customer_name'] ?></td>
                                            <td><?php $date = new DateTime($invoice['created_at']);
                                            echo $date->format(isset($localizationSettings["date_format"]) ? $localizationSettings["date_format"] : "d M Y") ?>
                                            </td>
                                            <td><?php $date = new DateTime($invoice['due_date']);
                                            echo $date->format(isset($localizationSettings["date_format"]) ? $localizationSettings["date_format"] : "d M Y") ?>
                                            </td>

                                            <td><?php echo (isset($localizationSettings["currency_symbol"]) ? $localizationSettings["currency_symbol"] : "$") . " " . $invoice['total_amount'] ?>
                                            </td>
                                            <td><?php echo $invoice['admin_username'] ?></td>
                                            <td>
                                                <?php if ($invoice['invoiceStatus'] == 'PAID') { ?>
                                                    <span class="badge badge-lg bg-success">Paid</span>
                                                <?php } elseif ($invoice['invoiceStatus'] == 'CANCELLED') { ?>
                                                    <span class="badge badge-lg bg-danger">Cancelled</span>
                                                <?php } elseif ($invoice['invoiceStatus'] == 'PENDING') { ?>
                                                    <span class="badge badge-lg bg-warning">Pending</span>
                                                <?php } elseif ($invoice['invoiceStatus'] == 'REFUNDED') { ?>
                                                    <span class="badge badge-lg bg-primary">Refunded</span>
                                                <?php } ?>
                                            </td>
                                            <td class="text-center">
                                                <a class="action-set" href="javascript:void(0);" data-bs-toggle="dropdown"
                                                    aria-expanded="true">
                                                    <i class="fa fa-ellipsis-v" aria-hidden="true"></i>
                                                </a>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <a target="_blank"
                                                            href="view-invoice.php?id=<?php echo base64_encode($invoice['invoice_id']) ?>"
                                                            class="editStatus dropdown-item" data-admin-id=""><i
                                                                data-feather="eye" class="info-img"></i>Show
                                                            Detail</a>
                                                    </li>
                                                    <?php if ($isAdmin || hasPermission('Edit Invoice', $privileges, $roleData['0']['role_name'])): ?>

                                                        <li>
                                                            <a href="edit-invoice.php?id=<?php echo base64_encode($invoice['invoice_id']) ?>"
                                                                class="editButton dropdown-item"><i data-feather="edit"
                                                                    class="info-img"></i>Edit
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>
                                                    <li>
                                                        <a target="_blank"
                                                            href="download-invoice.php?id=<?php echo base64_encode($invoice['invoice_id']) ?>"
                                                            class="qrCode dropdown-item"><i data-feather="download"
                                                                class="info-img"></i>Download
                                                        </a>
                                                    </li>
                                                    <?php if ($isAdmin || hasPermission('Delete Invoice', $privileges, $roleData['0']['role_name'])): ?>
                                                        <li>
                                                            <a href="javascript:void(0);"
                                                                data-invoice-id="<?php echo $invoice['invoice_id'] ?>"
                                                                class="dropdown-item deleteButton mb-0"><i
                                                                    data-feather="trash-2" class="info-img"></i>Delete </a>
                                                        </li>
                                                    <?php endif; ?>

                                                    <?php if ($isAdmin || hasPermission('Send Reminder', $privileges, $roleData['0']['role_name'])): ?>

                                                        <li>
                                                            <a href="javascript:void(0);"
                                                                data-invoice-id="<?php echo $invoice['invoice_id'] ?>"
                                                                class="dropdown-item sendReminder mb-0"><i data-feather="bell"
                                                                    class="info-img"></i>Send Reminder </a>
                                                        </li>
                                                    <?php endif; ?>

                                                    <?php if ($isAdmin || hasPermission('Send Receipt', $privileges, $roleData['0']['role_name'])): ?>

                                                        <li>
                                                            <a href="javascript:void(0);"
                                                                data-invoice-id="<?php echo $invoice['invoice_id'] ?>"
                                                                class="dropdown-item sendReceipt mb-0"><i data-feather="mail"
                                                                    class="info-img"></i>Send Receipt </a>
                                                        </li>
                                                    <?php endif; ?>

                                                    <?php if ($isAdmin || hasPermission('Send Invoice', $privileges, $roleData['0']['role_name'])): ?>

                                                        <li>
                                                            <a href="javascript:void(0);"
                                                                data-invoice-id="<?php echo $invoice['invoice_id'] ?>"
                                                                class="dropdown-item sendInvoice mb-0"><i data-feather="send"
                                                                    class="info-img"></i>Send Invoice </a>
                                                        </li>
                                                    <?php endif; ?>

                                                </ul>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                                <!-- <tfoot>
                                    <tr>
                                        <td colspan="6"></td>
                                        <td><strong><span class="text-danger">Total:
                                                    <?php echo (isset($localizationSettings["currency_symbol"]) ? $localizationSettings["currency_symbol"] : "$") . " " . number_format($totalTaxAmount, 2); ?></span></strong>
                                        </td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot> -->
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>



    <script src="assets/js/jquery-3.7.1.min.js"></script>

    <script src="assets/js/feather.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/js/jquery.slimscroll.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/js/jquery.dataTables.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/js/moment.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>
    <script src="assets/js/bootstrap-datetimepicker.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/js/moment.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>
    <script src="assets/plugins/daterangepicker/daterangepicker.js"
        type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/js/bootstrap.bundle.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/plugins/summernote/summernote-bs4.min.js"
        type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/plugins/select2/js/select2.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>

    <script src="assets/plugins/sweetalert/sweetalert2.all.min.js"
        type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>
    <script src="assets/plugins/sweetalert/sweetalerts.min.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>
    <script src="assets/js/script.js" type="36113e2a9ce2b6f627c18ab9-text/javascript"></script>
    <script src="assets/js/rocket-loader-min.js" data-cf-settings="36113e2a9ce2b6f627c18ab9-|49" defer=""></script>

    <script src="assets/js/custom.js"></script>


    <script>
        $(document).ready(function () {

            function showLoader() {
                $('#global-loader').fadeIn(200); // smooth show
            }

            function hideLoader() {
                $('#global-loader').fadeOut(200); // smooth hide
            }

            // Initialize Notyf
            const notyf = new Notyf({
                duration: 3000,
                position: { x: "center", y: "top" },
                types: [
                    {
                        type: "success",
                        background: "#4dc76f",
                        textColor: "#FFFFFF",
                        dismissible: false,
                    },
                    {
                        type: "error",
                        background: "#ff1916",
                        textColor: "#FFFFFF",
                        dismissible: false,
                        duration: 3000,
                    },
                ],
            });

            // Handle the click event on the delete button
            $(document).on('click', '.deleteButton', function (event) {
                let invoiceId = $(this).data('invoice-id');

                Swal.fire({
                    title: "Are you sure?",
                    text: "You won't be able to revert this!",
                    showCancelButton: true,
                    confirmButtonColor: "#ff9f43",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "Yes, delete it!"
                }).then((result) => {
                    if (result.isConfirmed) {
                        showLoader(); // ✅ START LOADER
                        // Send AJAX request to delete the record from the database
                        $.ajax({
                            url: 'manage-invoice.php',
                            type: 'POST',
                            data: { invoiceId: invoiceId },
                            success: function (response) {
                                let result;
                                hideLoader(); // ✅ STOP LOADER

                                try {
                                    result = JSON.parse(response);
                                } catch (e) {
                                    Swal.fire('Error!', 'Invalid server response.', 'error');
                                    return;
                                }

                                if (result.status === 200) {
                                    Swal.fire(
                                        'Deleted!',
                                        'The invoice has been deleted.',
                                        'success'
                                    ).then(() => {
                                        location.reload(); // Reload page after confirmation
                                    });
                                } else {
                                    Swal.fire('Error!', result.message || 'Deletion failed.', 'error');
                                }
                            },
                            error: function () {
                                hideLoader(); // ✅ STOP LOADER
                                Swal.fire(
                                    'Error!',
                                    'There was an error contacting the server.',
                                    'error'
                                );
                            }
                        });
                    }
                });
            });

            $(document).on('click', '.sendReminder', function (e) {
                e.preventDefault();

                let invoiceId = $(this).data('invoice-id');
                Swal.fire({
                    title: "Are you sure?",
                    text: "You won't be able to revert this!",
                    showCancelButton: true,
                    confirmButtonColor: "#ff9f43",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "Yes, Send Mail!"
                }).then((result) => {
                    if (result.isConfirmed) {
                        showLoader(); // ✅ START LOADER

                        // Send AJAX request to delete the record from the database
                        $.ajax({
                            url: 'manage-invoice.php', // The PHP file that will handle the deletion
                            type: 'POST',
                            data: { invoiceIdForReminder: invoiceId },
                            success: function (response) {
                                let result = JSON.parse(response);
                                console.log(result);
                                hideLoader(); // ✅ STOP LOADER

                                if (result.status == 200) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Send!',
                                        result.message,
                                        'success' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 403) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.message,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 404) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.message,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 500) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.error,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }

                            },
                            error: function (xhr, status, error) {
                                // Show error message if the AJAX request fails
                                Swal.fire(
                                    'Error!',
                                    'There was an error sending the mail.',
                                    'error'
                                );
                            }
                        });
                    }
                });
            });

            $(document).on('click', '.sendInvoice', function (e) {
                e.preventDefault();

                let invoiceId = $(this).data('invoice-id');


                Swal.fire({
                    title: "Are you sure?",
                    text: "You won't be able to revert this!",
                    showCancelButton: true,
                    confirmButtonColor: "#ff9f43",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "Yes, Send Mail!"
                }).then((result) => {
                    if (result.isConfirmed) {
                        showLoader(); // ✅ START LOADER
                        // Send AJAX request to delete the record from the database
                        $.ajax({
                            url: 'manage-invoice.php', // The PHP file that will handle the deletion
                            type: 'POST',
                            data: { invoiceIdForSend: invoiceId },
                            success: function (response) {
                                console.log(response);
                                let result = JSON.parse(response);
                                console.log(result);
                                hideLoader(); // ✅ STOP LOADER

                                if (result.status == 200) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Send!',
                                        result.message,
                                        'success' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 403) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.message,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 404) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.message,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 500) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.error,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }

                            },
                            error: function (xhr, status, error) {
                                hideLoader(); // ✅ STOP LOADER
                                // Show error message if the AJAX request fails
                                Swal.fire(
                                    'Error!',
                                    'There was an error sending the mail.',
                                    'error'
                                );
                            }
                        });
                    }
                });
            });


            $(document).on('click', '.sendReceipt', function (e) {
                e.preventDefault();

                let invoiceId = $(this).data('invoice-id');


                Swal.fire({
                    title: "Are you sure?",
                    text: "You won't be able to revert this!",
                    showCancelButton: true,
                    confirmButtonColor: "#ff9f43",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "Yes, Send Mail!"
                }).then((result) => {
                    if (result.isConfirmed) {
                        showLoader(); // ✅ START LOADER

                        // Send AJAX request to send the receipt
                        $.ajax({
                            url: 'manage-invoice.php', // The PHP file that will handle the request
                            type: 'POST',
                            data: { invoiceIdForSendReceipt: invoiceId },
                            success: function (response) {

                                hideLoader(); // ✅ STOP LOADER
                                console.log(response);
                                let result = JSON.parse(response);
                                console.log(result);

                                if (result.status == 200) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Send!',
                                        result.message,
                                        'success' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 403) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.message,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 404) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.message,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }
                                if (result.status == 500) {
                                    // Show success message and reload the page
                                    Swal.fire(
                                        'Error!',
                                        result.error,
                                        'error' // Added 'success' to show the success icon
                                    ).then(() => {
                                        // Reload the page
                                        location.reload();
                                    });
                                }

                            },
                            error: function (xhr, status, error) {

                                hideLoader(); // ✅ STOP LOADER

                                // Show error message if the AJAX request fails
                                Swal.fire(
                                    'Error!',
                                    'There was an error sending the mail.',
                                    'error'
                                );
                            }
                        });
                    }
                });
            });

            $(document).on('click', '.multi-delete-button', function (e) {
                e.preventDefault();

                let invoiceIds = [];
                $('input[name="invoiceIds"]:checked').each(function () {
                    invoiceIds.push(parseInt($(this).val()));
                });

                if (invoiceIds.length == 0) {
                    Swal.fire({
                        icon: "error",
                        title: "Oops...",
                        text: "Please select invoice!",
                    });
                    return;
                }

                Swal.fire({
                    title: "Are you sure?",
                    text: "You won't be able to revert this!",
                    showCancelButton: true,
                    confirmButtonColor: "#ff9f43",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "Yes, delete it!"
                }).then((result) => {
                    if (result.isConfirmed) {

                        $.ajax({
                            url: "manage-invoice.php",
                            type: "post",
                            data: { invoiceIds: invoiceIds },
                            beforeSend: function () {
                                showLoader(); // ✅ START LOADER
                            },
                            success: function (response) {
                                hideLoader(); // ✅ STOP LOADER

                                Swal.fire(
                                    'Deleted!',
                                    'The Invoice has been deleted.',
                                    'success'
                                ).then(() => {
                                    // Reload the page
                                    location.reload();
                                });

                            },
                            error: function (error) {
                                console.log(error);
                                hideLoader(); // ✅ STOP LOADER
                            },
                        });

                    }
                })


            });

            $(document).on("click", ".row .col-lg-3 .input-blocks .btn-filters", function (e) {
                e.preventDefault();
                let customerId = $(".input-blocks select[name='customerId']").val();
                let fromDate = $(".row .col-lg-3 .input-blocks .daterange-wraper input[name='from']").val();
                let toDate = $(".row .col-lg-3 .input-blocks .daterange-wraper input[name='to']").val();

                // Check if customerId is missing or not a number
                // if (!customerId || isNaN(customerId) || !Number.isInteger(Number(customerId))) {
                //     notyf.error("Please select a valid customer");
                //     return;
                // }
                if (!fromDate) {
                    notyf.error("Please select from date");
                    return;
                }
                if (!toDate) {
                    notyf.error("Please select to date");
                    return;
                }

                // Output
                console.log("Customer ID -", customerId);
                console.log("From Date -", fromDate);
                console.log("To Date -", toDate);
                window.location.href = `manage-invoice.php?customer=${customerId}&from=${fromDate}&to=${toDate}`;
            });
        });
    </script>

</body>

</html>