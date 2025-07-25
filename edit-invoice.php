<?php
ob_start();
session_start();

require "./database/config.php";
require "./utility/env.php";
if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit();
}

try {

    // fetch invoice data
    $invoiceId = intval(base64_decode($_GET['id']));

    $stmtInvoice = $db->prepare('SELECT * FROM invoice WHERE invoice_id = ?');
    $stmtInvoice->bind_param('i', $invoiceId);
    if ($stmtInvoice->execute()) {
        $result = $stmtInvoice->get_result();
        $invoices = $result->fetch_all(MYSQLI_ASSOC);

        $passengers = json_decode($invoices[0]['passenger_details'], true);
    }


    $stmtFetchCustomers = $db->prepare("SELECT * FROM customer WHERE isActive = 1");
    if ($stmtFetchCustomers->execute()) {
        $customers = $stmtFetchCustomers->get_result();
    } else {
        $_SESSION['error'] = 'Error for fetching customers';
    }

    $stmtFetchService = $db->prepare("SELECT * FROM services WHERE isActive = 1");
    if ($stmtFetchService->execute()) {
        $services = $stmtFetchService->get_result();
    } else {
        $_SESSION['error'] = 'Error for fetching customers';
    }

    $stmtFetchTax = $db->prepare("SELECT tax_id, tax_name, tax_rate FROM tax WHERE status = 1");
    if ($stmtFetchTax->execute()) {
        $taxOptions = $stmtFetchTax->get_result();
    }
    $serviceIdJson = $invoices[0]['service_id'];

    $serviceIds = json_decode($serviceIdJson, true); // true returns array, not object
    if (json_last_error() !== JSON_ERROR_NONE) {
        die('JSON decode error: ' . json_last_error_msg());
    }

    $stmtFetchCompanySettings = $db->prepare("SELECT * FROM company_settings");
    $stmtFetchCompanySettings->execute();
    $companySettings = $stmtFetchCompanySettings->get_result()->fetch_array(MYSQLI_ASSOC);


    $token = "TheFlighshubnz@123";
    $secretKey = getenv("SECRET_KEY");
    $timestamp = time();
    $signature = hash_hmac('sha256', $token . $timestamp, $secretKey);
    $apiUrl = "https://www.theflightshub.co.nz/airport-data-api.php?token=" . urlencode($token) . "&ts=" . $timestamp . "&sig=" . $signature;


    // Initialize cURL
    $ch = curl_init();

    // Set cURL options
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return response instead of printing
    curl_setopt($ch, CURLOPT_HTTPGET, true); // HTTP GET request

    // Execute cURL request
    $response = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        echo "cURL Error: " . curl_error($ch);
    } else {
        // Convert JSON response to PHP array
        $airports = json_decode($response, true);
    }



} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}


if (isset($_POST['invoiceNumber']) && $_SERVER['REQUEST_METHOD'] == "POST") {
    // Get current timestamp in YYYYMMDDHHMMSS format
    $timestamp = date('YmdHis'); // e.g., '20250725152030' for July 25, 2025, 15:20:30

    // Use a transaction to ensure atomicity
    try {
        $db->begin_transaction();

        // Fetch invoice settings
        $stmtFetchInvoiceSettings = $db->prepare("SELECT * FROM invoice_settings");
        $stmtFetchInvoiceSettings->execute();
        $invoiceSettings = $stmtFetchInvoiceSettings->get_result()->fetch_array(MYSQLI_ASSOC);
        $prefix = isset($invoiceSettings['invoice_prefix']) ? $invoiceSettings['invoice_prefix'] : "VIS";

        // Lock the sequence row
        $stmt = $db->prepare("SELECT last_sequence FROM invoice_sequence WHERE id = 1 FOR UPDATE");
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            // No sequence row, create one
            $stmt = $db->prepare("INSERT INTO invoice_sequence (id, last_sequence) VALUES (1, 0)");
            $stmt->execute();
            $lastSequence = 0;
        } else {
            $row = $result->fetch_assoc();
            $lastSequence = $row['last_sequence'];
        }

        // Increment sequence
        $newSequence = $lastSequence + 1;

        // Update sequence
        $stmt = $db->prepare("UPDATE invoice_sequence SET last_sequence = ? WHERE id = 1");
        $stmt->bind_param("i", $newSequence);
        $stmt->execute();

        $db->commit();

        // Format invoice number with prefix, timestamp, and sequence
        $invoiceNumber = sprintf("%s-%s-%05d", $prefix, $timestamp, $newSequence); // e.g., VIS-20250725152030-00001
        echo json_encode([
            "status" => 201,
            "data" => $invoiceNumber
        ]);
        exit;
    } catch (Exception $e) {
        $db->rollback();
        echo json_encode([
            "status" => 500,
            "error" => $e->getMessage()
        ]);
        exit;
    }
}



if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['edit'])) {
    try {


        $invoiceTitle = htmlspecialchars($_POST["invoice_title"], ENT_QUOTES, 'UTF-8');
        $invoiceNumber = htmlspecialchars($_POST['invoice_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $paymentMethod = htmlspecialchars($_POST['payment_method'] ?? '', ENT_QUOTES, 'UTF-8');
        $transactionId = htmlspecialchars($_POST['transaction_id'] ?? '', ENT_QUOTES, 'UTF-8');
        $status = htmlspecialchars($_POST['status'] ?? '', ENT_QUOTES, 'UTF-8');
        $dueDate = htmlspecialchars($_POST['due_date'] ?? '', ENT_QUOTES, 'UTF-8');
        $customerName = htmlspecialchars($_POST['customerName'] ?? '', ENT_QUOTES, 'UTF-8');
        $fromLocation = htmlspecialchars($_POST['fromLocation'] ?? '', ENT_QUOTES, 'UTF-8');
        $toLocation = htmlspecialchars($_POST['toLocation'] ?? '', ENT_QUOTES, 'UTF-8');
        $airlineName = htmlspecialchars($_POST['airlineName'] ?? '', ENT_QUOTES, 'UTF-8');
        $travelDate = htmlspecialchars($_POST["travel_date"], ENT_QUOTES, 'UTF-8');
        $customerAddress = htmlspecialchars($_POST["customerAddress"], ENT_QUOTES, 'UTF-8');
        $organizationName = htmlspecialchars($_POST['organizationName'] ?? '', ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8');
        $totalAmount = filter_input(INPUT_POST, 'total_amount', FILTER_VALIDATE_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $createdBy = base64_decode($_SESSION['admin_id']);
        $customerEmail = $_POST['customerEmail'];


        // Handle serviceName array
        $serviceIds = isset($_POST['serviceName']) && is_array($_POST['serviceName'])
            ? array_map('intval', $_POST['serviceName']) // Sanitize to integers
            : [];
        $serviceIdsJson = json_encode($serviceIds); // Convert to JSON string, e.g., "[2, 3, 5]"


        // Validate payment_method and status enums
        $validPaymentMethods = ['CREDIT_CARD', 'DEBIT_CARD', 'CASH', 'NET_BANKING', 'PAYPAL', 'OTHER', 'CASH_DEPOSIT'];
        $validStatuses = ['PAID', 'PENDING', 'CANCELLED', 'REFUNDED'];
        $validTypes = ['FIXED', 'RECURSIVE'];

        if (!in_array($paymentMethod, $validPaymentMethods)) {
            throw new Exception('Invalid payment method.');
        }
        if (!in_array($status, $validStatuses)) {
            throw new Exception('Invalid status.');
        }

        $passengerDetails = [];
        foreach ($_POST['passenger_type'] as $index => $type) {
            $passengerDetails[] = [
                'type' => $type,
                'quantity' => $_POST['quantity'][$index],
                'amount' => $_POST['amount'][$index],
            ];
        }

        // Convert to JSON for DB insertion
        $passengerDetailsJson = json_encode($passengerDetails);


        // echo "<pre>";
        // print_r($organizationName);
        // exit();

        // Prepare and execute the SQL UPDATE query 
        $sql = "UPDATE `invoice` SET
            `invoice_number` = ?, 
            `invoice_title` = ?, 
            `payment_method` = ?, 
            `transaction_id` = ?, 
            `status` = ?, 
            `airline_name` = ?, 
            `travel_date` = ?, 
            `due_date` = ?, 
            `customer_name` = ?, 
            `service_id` = ?, 
            `from_location` = ?, 
            `to_location` = ?, 
            `description` = ?, 
            `total_amount` = ?, 
            `created_by` = ?, 
            `passenger_details` = ?,
            `customer_address` = ?,
            `organization` = ?,
            `customer_email` = ?
        WHERE `invoice_id` = ?";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . $db->error);
        }

        $stmt->bind_param(
            'sssssssssssssdissssi',
            $invoiceNumber,
            $invoiceTitle,
            $paymentMethod,
            $transactionId,
            $status,
            $airlineName,
            $travelDate,
            $dueDate,
            $customerName,
            $serviceIdsJson,
            $fromLocation,
            $toLocation,
            $description,
            $totalAmount,
            $createdBy,
            $passengerDetailsJson,
            $customerAddress,
            $organizationName,
            $customerEmail,
            $invoiceId
        );

        // Execute the query
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        $stmt->close();



        $_SESSION['success'] = 'Invoice Updated successfully!';
        header('Location: edit-invoice.php?id=' . base64_encode($invoiceId));
        exit();
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0">
    <meta name="description" content="">
    <meta name="keywords" content="">
    <meta name="author" content="">
    <meta name="robots" content="noindex, nofollow">
    <title>Edit Invoice</title>

    <link rel="shortcut icon" type="image/x-icon"
        href="<?= isset($companySettings['favicon']) ? $companySettings['favicon'] : "assets/img/fav/vis-favicon.png" ?>">

    <link rel="stylesheet" href="assets/css/bootstrap.min.css">

    <link rel="stylesheet" href="assets/css/bootstrap-datetimepicker.min.css">

    <link rel="stylesheet" href="assets/css/animate.css">

    <link rel="stylesheet" href="assets/css/feather.css">

    <link rel="stylesheet" href="assets/plugins/select2/css/select2.min.css">

    <link rel="stylesheet" href="assets/plugins/bootstrap-tagsinput/bootstrap-tagsinput.css">

    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">

    <link rel="stylesheet" href="assets/plugins/fontawesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/plugins/fontawesome/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">

    <!-- toast  -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>

</head>

<body>
    <div id="global-loader">
        <div class="whirly-loader"> </div>
    </div>

    <div class="main-wrapper">

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
                            <h4>Edit Invoice</h4>
                            <h6>Edit Existing Invoice</h6>
                        </div>
                    </div>

                </div>
                <div class="page-header">
                    <ul class="table-top-head">
                        <li>
                            <div class="page-btn">
                                <a href="manage-invoice.php" class="btn btn-secondary"><i data-feather="arrow-left"
                                        class="me-2"></i>Back to
                                    Invoices</a>
                            </div>
                        </li>
                        <li>
                            <a data-bs-toggle="tooltip" data-bs-placement="top" title="Collapse" id="collapse-header"><i
                                    data-feather="chevron-up" class="feather-chevron-up"></i></a>
                        </li>
                    </ul>
                </div>

                <form action="" method="post">
                    <div class="card">
                        <div class="card-body add-product pb-0">
                            <div class="accordion-card-one accordion" id="accordionExample">
                                <div class="accordion-item">
                                    <div class="accordion-header" id="headingOne">
                                        <div class="accordion-button" data-bs-toggle="collapse"
                                            data-bs-target="#collapseOne" aria-controls="collapseOne">
                                            <div class="addproduct-icon">
                                                <h5><i data-feather="info" class="add-info"></i><span>Invoice
                                                        Details</span></h5>
                                                <a href="javascript:void(0);"><i data-feather="chevron-down"
                                                        class="chevron-down-add"></i></a>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="collapseOne" class="accordion-collapse collapse show"
                                        aria-labelledby="headingOne" data-bs-parent="#accordionExample">
                                        <div class="accordion-body">
                                            <div class="row">

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="input-blocks add-product list">
                                                        <label class="form-label">Invoice Title </label>
                                                        <input type="text" id="invoice_title"
                                                            value="<?= isset($invoices['0']['invoice_title']) ? $invoices['0']['invoice_title'] : "" ?>"
                                                            name="invoice_title" placeholder="Enter Invoice Title"
                                                            class="form-control">
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="input-blocks add-product list">
                                                        <label class="form-label">Invoice Number</label>
                                                        <input type="text" id="invoice_number"
                                                            value="<?php echo $invoices['0']['invoice_number'] ?>"
                                                            name="invoice_number" placeholder="Enter Invoice Number"
                                                            class="form-control" required>
                                                        <button type="button"
                                                            class="btn-2 btn-primaryadd invoiceNumber">
                                                            Generate
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Payment Method:</label>
                                                        <select id="payment_method" name="payment_method"
                                                            class="form-control" required>
                                                            <option value="">Select Method</option>
                                                            <option value="CASH" <?php if ($invoices['0']['payment_method'] == 'CASH')
                                                                echo 'selected'; ?>>Cash</option>
                                                            <option value="CREDIT_CARD" <?php if ($invoices['0']['payment_method'] == 'CREDIT_CARD')
                                                                echo 'selected'; ?>>Credit Card</option>
                                                            <option value="CASH_DEPOSIT" <?php if ($invoices['0']['payment_method'] == 'CASH_DEPOSIT')
                                                                echo 'selected'; ?>>Cash Deposit</option>
                                                            <option value="DEBIT_CARD" <?php if ($invoices['0']['payment_method'] == 'DEBIT_CARD')
                                                                echo 'selected'; ?>>Debit Card</option>
                                                            <option value="NET_BANKING" <?php if ($invoices['0']['payment_method'] == 'NET_BANKING')
                                                                echo 'selected'; ?>>Net Banking</option>
                                                            <option value="PAYPAL" <?php if ($invoices['0']['payment_method'] == 'PAYPAL')
                                                                echo 'selected'; ?>>Paypal</option>
                                                            <option value="OTHER" <?php if ($invoices['0']['payment_method'] == 'OTHER')
                                                                echo 'selected'; ?>>Other</option>
                                                        </select>

                                                    </div>
                                                </div>


                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="input-blocks add-product list">
                                                        <label>Transaction ID:</label>
                                                        <input type="text" id="transaction_id"
                                                            value="<?php echo $invoices['0']['transaction_id'] ?>"
                                                            name="transaction_id" placeholder="Enter Transaction ID"
                                                            class="form-control" required>
                                                        <button type="button"
                                                            class="btn-2 btn-primaryadd transactionNumber">
                                                            Generate
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Payment Status</label>
                                                        <select id="payment_status" name="status" class="form-control"
                                                            required>
                                                            <option>Select Status</option>
                                                            <option value="PAID" <?php if ($invoices['0']['status'] == 'PAID')
                                                                echo 'selected'; ?>>Paid</option>
                                                            <option value="PENDING" <?php if ($invoices['0']['status'] == 'PENDING')
                                                                echo 'selected'; ?>>Pending</option>
                                                            <option value="CANCELLED" <?php if ($invoices['0']['status'] == 'CANCELLED')
                                                                echo 'selected'; ?>>Cancelled</option>
                                                            <option value="REFUNDED" <?php if ($invoices['0']['status'] == 'REFUNDED')
                                                                echo 'selected'; ?>>Refunded</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12 due-date">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Due Date:</label>
                                                        <input type="date"
                                                            value="<?php echo $invoices[0]['due_date'] ?>" id="due_date"
                                                            name="due_date" placeholder="Enter Due Date"
                                                            class="form-control" autocomplete="off">
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Customer <span> *</span></label>
                                                        <input class="form-control"
                                                            value="<?php echo $invoices[0]['customer_name'] ?>"
                                                            type="text" name="customerName"
                                                            placeholder="Enter Customer Name" required>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Customer Email <span> *</span></label>
                                                        <input class="form-control" type="email" name="customerEmail"
                                                            value="<?php echo $invoices[0]['customer_email'] ?>"
                                                            placeholder="Enter Customer Email" required>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Services</label>

                                                        <select class="select2 form-select" name="serviceName[]"
                                                            multiple="multiple">
                                                            <option value="">Select Service</option>
                                                            <?php foreach ($services->fetch_all(MYSQLI_ASSOC) as $service) { ?>
                                                                <option value="<?php echo $service['service_id']; ?>" <?php if (in_array($service['service_id'], $serviceIds))
                                                                       echo "selected" ?>>
                                                                    <?php echo $service['service_name']; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>

                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">From <span> *</span></label>

                                                        <select class="select2 form-select" name="fromLocation">
                                                            <option value="">Select</option>
                                                            <?php foreach ($airports['data'] as $airport) { ?>
                                                                <option value="<?php echo $airport['airport']; ?>" <?php if ($invoices['0']['from_location'] == $airport['airport'])
                                                                       echo 'selected'; ?>>
                                                                    <?php echo $airport['airport']; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>

                                                    </div>
                                                </div>
                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">To <span> *</span></label>

                                                        <select class="select2 form-select" name="toLocation">
                                                            <option value="">Select</option>
                                                            <?php foreach ($airports['data'] as $airport) { ?>
                                                                <option value="<?php echo $airport['airport']; ?>" <?php if ($invoices['0']['to_location'] == $airport['airport'])
                                                                       echo 'selected'; ?>>
                                                                    <?php echo $airport['airport']; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>

                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Airline <span> *</span></label>
                                                        <input class="form-control" type="text"
                                                            value="<?= $invoices[0]['airline_name'] ?>"
                                                            name="airlineName" placeholder="Enter Airline Name"
                                                            required>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Travel Date: <span> *</span></label>
                                                        <input type="date" id="travel_date" name="travel_date"
                                                            value="<?= $invoices[0]['travel_date'] ?>"
                                                            placeholder="Enter Due Date" class="form-control"
                                                            autocomplete="off" required>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Customer Address</label>
                                                        <input class="form-control" type="text" name="customerAddress"
                                                            value="<?= $invoices[0]['customer_address'] ?>"
                                                            placeholder="Enter Address">
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Company/Organization</label>
                                                        <input class="form-control" type="text"
                                                            value="<?= $invoices[0]['organization'] ?>"
                                                            name="organizationName"
                                                            placeholder="Enter Organization Name">
                                                    </div>
                                                </div>

                                                <div class="col-lg-12">
                                                    <div class="input-blocks summer-description-box transfer mb-3">
                                                        <label>Note</label>
                                                        <textarea class="form-control h-100" name="description"
                                                            rows="5"><?php echo htmlspecialchars($invoices[0]['description']); ?></textarea>
                                                        <p class="mt-1">Maximum 60 Characters</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion-card-one accordion" id="accordionExample2">
                                    <div class="accordion-item">
                                        <div class="accordion-header" id="headingTwo">
                                            <div class="accordion-button" data-bs-toggle="collapse"
                                                data-bs-target="#collapseTwo" aria-controls="collapseTwo">
                                                <div class="text-editor add-list">
                                                    <div class="addproduct-icon list icon">
                                                        <h5>
                                                            <i data-feather="life-buoy"
                                                                class="add-info"></i><span>Amount
                                                                Details</span>
                                                        </h5>
                                                        <a href="javascript:void(0);"><i data-feather="chevron-down"
                                                                class="chevron-down-add"></i></a>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="collapseTwo" class="accordion-collapse collapse show"
                                            aria-labelledby="headingTwo" data-bs-parent="#accordionExample2">
                                            <div class="accordion-body">

                                                <div class="tab-content" id="pills-tabContent">
                                                    <div class="tab-pane fade show active" id="pills-home"
                                                        role="tabpanel" aria-labelledby="pills-home-tab">
                                                        <div class="row">

                                                            <div class="col-12">
                                                                <label class="form-label">Passengers
                                                                    <span>*</span></label>
                                                                <div id="passengerContainer">
                                                                    <?php foreach ($passengers as $passenger): ?>
                                                                        <div
                                                                            class="row passenger-row mb-2 d-flex align-items-center">
                                                                            <div class="col-3">
                                                                                <select class="form-control"
                                                                                    name="passenger_type[]" required>
                                                                                    <option value="">Select Type</option>
                                                                                    <option value="ADULT" <?php echo $passenger['type'] === 'ADULT' ? 'selected' : ''; ?>>Adult (12
                                                                                        years+)</option>
                                                                                    <option value="CHILDREN" <?php echo $passenger['type'] === 'CHILDREN' ? 'selected' : ''; ?>>Children (2-12
                                                                                        years)</option>
                                                                                    <option value="INFANT" <?php echo $passenger['type'] === 'INFANT' ? 'selected' : ''; ?>>Infant (Below 2
                                                                                        years)</option>
                                                                                </select>
                                                                            </div>
                                                                            <div class="col-3">
                                                                                <input type="number" class="form-control"
                                                                                    name="quantity[]" placeholder="Quantity"
                                                                                    min="1"
                                                                                    value="<?php echo htmlspecialchars($passenger['quantity']); ?>"
                                                                                    required>
                                                                            </div>
                                                                            <div class="col-4">
                                                                                <input type="number" class="form-control"
                                                                                    name="amount[]" placeholder="Amount"
                                                                                    step="0.01" min="1"
                                                                                    value="<?php echo htmlspecialchars($passenger['amount']); ?>"
                                                                                    required>
                                                                            </div>
                                                                            <div class="col-2">
                                                                                <button type="button"
                                                                                    class="btn btn-danger btn-remove">–</button>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                                <button type="button" id="addPassenger"
                                                                    class="btn btn-primary mt-1 mb-1">+ Add
                                                                    Passenger</button>
                                                            </div>

                                                            <div class="col-lg-3 col-sm-6 col-12 m-2">
                                                                <div class="mb-3 add-product">
                                                                    <label class="form-label">Total Amount</label>
                                                                    <input type="text" id="total_amount"
                                                                        name="total_amount" class="form-control"
                                                                        value='<?php echo htmlspecialchars($invoices[0]['total_amount']); ?>'
                                                                        placeholder="Enter Total Amount" readonly>
                                                                </div>
                                                            </div>

                                                        </div>

                                                    </div>

                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-12">
                            <div class="btn-addproduct mb-4">
                                <button type="button" class="btn btn-cancel me-2">
                                    Cancel
                                </button>
                                <button type="submit" name="edit" class="btn btn-submit">
                                    Save
                                </button>
                            </div>
                        </div>
                </form>
            </div>

        </div>
    </div>
    </div>





    <script src="assets/js/jquery-3.7.1.min.js"></script>

    <script src="assets/js/feather.min.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>

    <script src="assets/js/jquery.slimscroll.min.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>

    <script src="assets/plugins/select2/js/select2.min.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>

    <script src="assets/js/jquery.dataTables.min.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>

    <script src="assets/js/bootstrap.bundle.min.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>

    <script src="assets/js/script.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>
    <script src="assets/js/custom-select2.js" type="85b95337cd86ef30623c36b5-text/javascript"></script>
    <script src="assets/js/rocket-loader-min.js" data-cf-settings="85b95337cd86ef30623c36b5-|49" defer=""></script>
    <script src="assets/js/custom.js"></script>


    <script type="text/javascript">
        $(document).ready(function () {
            $(document).on('click', '.transactionNumber', function (event) {
                event.preventDefault();

                // Generate transaction ID in format TX-YYYYMMDD-RRRRR-XXXX
                function generateTransactionId() {
                    // Get current date in YYYYMMDD format
                    const now = new Date();
                    const year = now.getFullYear();
                    const month = String(now.getMonth() + 1).padStart(2, '0'); // Months are 0-based
                    const day = String(now.getDate()).padStart(2, '0');
                    const dateStr = `${year}${month}${day}`;

                    // Generate 5-digit random number (10000–99999)
                    const randomNumber = Math.floor(Math.random() * 90000) + 10000;

                    // Generate 4-character random alphanumeric suffix
                    const chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                    let suffix = '';
                    for (let i = 0; i < 4; i++) {
                        suffix += chars.charAt(Math.floor(Math.random() * chars.length));
                    }

                    // Combine parts
                    return `TX-${dateStr}-${randomNumber}-${suffix}`;
                }

                // Set transaction ID in the input field
                $('#transaction_id').val(generateTransactionId());
            });

            $(document).on('click', '.invoiceNumber', async function (event) {

                try {
                    event.preventDefault();
                    let invoiceNumber = 1;

                    const response = await $.ajax({
                        url: window.location.href,
                        method: 'POST',
                        data: { invoiceNumber: invoiceNumber },
                    });

                    let result = JSON.parse(response);

                    // Set transaction ID in the input field
                    $('#invoice_number').val(result.data);
                } catch (error) {
                    console.error('Error fetching invoice data:', error);
                }

            });


            $(document).on('change', "#payment_status", function (e) {
                const selectedType = $(this).val();

                if (selectedType === 'PAID') {
                    $('.due-date').addClass('apexcharts-toolbar');
                } else {
                    $('.due-date').removeClass('apexcharts-toolbar');

                }
            });
            const selectedType = $("#payment_status").val();

            if (selectedType === 'PAID') {
                $('.due-date').addClass('apexcharts-toolbar');
            } else {
                $('.due-date').removeClass('apexcharts-toolbar');

            }



            $(document).on('click', '#addPassenger', function () {
                const newRow = `
        <div class="row passenger-row mb-2 d-flex align-items-center">
            <div class="col-3">
               <select class="form-control"
                   name="passenger_type[]" required>
                   <option value="">Select Type</option>
                   <option value="ADULT">Adult (12 years+)
                   </option>
                   <option value="CHILDREN">Children (2-12
                       years)</option>
                   <option value="INFANT">Infant (Below 2
                       years)</option>
               </select>
           </div>
           <div class="col-3">
               <input type="number" class="form-control"
                   name="quantity[]" placeholder="Quantity"
                   min="1" required>
           </div>
           <div class="col-4">
               <input type="number" class="form-control"
                   name="amount[]" placeholder="Amount" step="0.01" min="1"
                   required>
           </div>
           <div class="col-2">
               <button type="button"
                   class="btn btn-danger btn-remove">–</button>
           </div>
        </div>
    `;
                $('#passengerContainer').append(newRow);
            });

            // Use event delegation for dynamically created elements
            $('#passengerContainer').on('click', '.btn-remove', function () {
                $(this).closest('.passenger-row').remove();
            });


        });
    </script>


</body>

</html>