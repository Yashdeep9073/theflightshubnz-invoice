<?php
session_start();

require "./database/config.php";
require "./utility/env.php";
if (!isset($_SESSION["admin_id"])) {
    header("Location: login.php");
    exit();
}


try {

    $stmtFetchCompanySettings = $db->prepare("SELECT * FROM company_settings");
    $stmtFetchCompanySettings->execute();
    $companySettings = $stmtFetchCompanySettings->get_result()->fetch_array(MYSQLI_ASSOC);

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

    $stmtFetchLocalizationSettings = $db->prepare("SELECT * FROM localization_settings INNER JOIN currency ON localization_settings.currency_id = currency.currency_id;");
    $stmtFetchLocalizationSettings->execute();
    $localizationSettings = $stmtFetchLocalizationSettings->get_result()->fetch_array(MYSQLI_ASSOC);
    $timezone = $localizationSettings["timezone"] ?? "UTC";

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

    // get airlines data
    $stmtFetchAirlines = $db->prepare("SELECT * FROM airlines WHERE is_active = 1");
    $stmtFetchAirlines->execute();
    $airlines = $stmtFetchAirlines->get_result()->fetch_all(MYSQLI_ASSOC);


} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}



if (isset($_POST['invoiceNumber']) && $_SERVER['REQUEST_METHOD'] == "POST") {
    // Get current date in YYYYMMDD format
    $date = date('Ymd'); // e.g., '20250530'

    // Use a transaction to ensure atomicity
    try {
        $db->begin_transaction();

        $stmtFetchInvoiceSettings = $db->prepare("SELECT * FROM invoice_settings");
        $stmtFetchInvoiceSettings->execute();
        $invoiceSettings = $stmtFetchInvoiceSettings->get_result()->fetch_array(MYSQLI_ASSOC);
        $prefix = isset($invoiceSettings['invoice_prefix']) ? $invoiceSettings['invoice_prefix'] : "VIS";

        // Lock the row for the current date
        $stmt = $db->prepare("SELECT last_sequence FROM invoice_sequence WHERE date = ? FOR UPDATE");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            // No sequence for today, create one
            $stmt = $db->prepare("INSERT INTO invoice_sequence (date, last_sequence) VALUES (?, 0)");
            $stmt->bind_param("s", $date);
            $stmt->execute();
            $lastSequence = 0;
        } else {
            $row = $result->fetch_assoc();
            $lastSequence = $row['last_sequence'];
        }

        // Increment sequence
        $newSequence = $lastSequence + 1;

        // Update sequence
        $stmt = $db->prepare("UPDATE invoice_sequence SET last_sequence = ? WHERE date = ?");
        $stmt->bind_param("is", $newSequence, $date);
        $stmt->execute();

        $db->commit();

        // Format invoice number
        $invoiceNumber = sprintf("$prefix-%s-%05d", $date, $newSequence); // e.g., VIS-20250530-00001
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


if ($_SERVER['REQUEST_METHOD'] == "POST" && isset($_POST['submit'])) {
    try {

        // echo "<pre>";
        // print_r($_POST);
        // exit();

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

        // Handle serviceName array
        $serviceIds = isset($_POST['serviceName']) && is_array($_POST['serviceName'])
            ? array_map('intval', $_POST['serviceName']) // Sanitize to integers
            : [];
        $serviceIdsJson = json_encode($serviceIds); // Convert to JSON string, e.g., "[2, 3, 5]"


        // Validate payment_method and status enums
        $validPaymentMethods = ['CREDIT_CARD', 'DEBIT_CARD', 'CASH', 'NET_BANKING', 'PAYPAL', 'OTHER','CASH_DEPOSIT'];  
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

        // Prepare and execute the SQL query
        $sql = "INSERT INTO invoice (
            invoice_number, invoice_title, payment_method, transaction_id, status, 
            airline_name, travel_date, due_date, customer_name, service_id, 
            from_location, to_location, description, total_amount, created_by, 
            passenger_details,customer_address,organization
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?,?)";

        $stmt = $db->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . $db->error);
        }

        // $toLocation = 1;
        // Bind parameters
        $stmt->bind_param(
            'sssssssssssssdisss',
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
            $organizationName
        );

        // Execute the query
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        $stmt->close();



        $_SESSION['success'] = 'Invoice created successfully!';
        header("Location: manage-invoice.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
}

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
    <title>Create Invoice</title>

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
                            <h4>New Invoice</h4>
                            <h6>Create New Invoice</h6>
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
                                                        <input type="text" id="invoice_title" name="invoice_title"
                                                            placeholder="Enter Invoice Title" class="form-control">
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="input-blocks add-product list">
                                                        <label class="form-label">Invoice Number <span> *</span></label>
                                                        <input type="text" id="invoice_number" name="invoice_number"
                                                            placeholder="Enter Invoice Number" class="form-control"
                                                            required>
                                                        <button type="button"
                                                            class="btn-2 btn-primaryadd invoiceNumber">
                                                            Generate
                                                        </button>
                                                    </div>
                                                </div>


                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Payment Method: <span>
                                                                *</span></label>
                                                        <select id="payment_method" name="payment_method"
                                                            class="form-control" required>
                                                            <option value="">Select Method</option>
                                                            <option value="CASH">Cash</option>
                                                            <option value="CASH_DEPOSIT">Cash Deposit</option>
                                                            <option value="CREDIT_CARD">Credit Card</option>
                                                            <option value="DEBIT_CARD">Debit Card</option>
                                                            <option value="NET_BANKING">Net Banking</option>
                                                            <option value="OTHER">Other</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="input-blocks add-product list">
                                                        <label>Transaction ID: <span> *</span></label>
                                                        <input type="text" id="transaction_id" name="transaction_id"
                                                            placeholder="Enter Transaction ID" class="form-control">
                                                        <button type="button"
                                                            class="btn-2 btn-primaryadd transactionNumber">
                                                            Generate
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Payment Status <span> *</span></label>
                                                        <select id="payment_status" name="status" class="form-control"
                                                            required>
                                                            <option>Select Status</option>
                                                            <option value="PAID">Paid</option>
                                                            <option value="PENDING">Pending</option>
                                                            <option value="CANCELLED">Cancelled</option>
                                                            <option value="REFUNDED">Refunded</option>
                                                        </select>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12 due-date">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Due Date: <span> *</span></label>
                                                        <input type="date" id="due_date" name="due_date"
                                                            placeholder="Enter Due Date" class="form-control"
                                                            autocomplete="off">
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Customer <span> *</span></label>
                                                        <input class="form-control" type="text" name="customerName"
                                                            placeholder="Enter Customer Name" required>
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Services <span> *</span></label>

                                                        <select class="select2 form-select" name="serviceName[]"
                                                            multiple="multiple">
                                                            <option value="">Select Service</option>
                                                            <?php foreach ($services->fetch_all(MYSQLI_ASSOC) as $service) { ?>
                                                                <option value="<?php echo $service['service_id']; ?>">
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
                                                                <option value="<?php echo $airport['airport']; ?>">
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
                                                                <option value="<?php echo $airport['airport']; ?>">
                                                                    <?php echo $airport['airport']; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>

                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Airline <span> *</span></label>

                                                        <select class="select2 form-select" name="airlineName">
                                                            <option value="">Select</option>
                                                            <?php foreach ($airlines as $airline) { ?>
                                                                <option value="<?php echo $airline['airline_name']; ?>">
                                                                    <?php echo $airline['airline_name']; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>

                                                    </div>
                                                </div>


                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Travel Date: <span> *</span></label>
                                                        <input type="date" id="travel_date" name="travel_date"
                                                            placeholder="Enter Due Date" class="form-control"
                                                            autocomplete="off" required>
                                                    </div>
                                                </div>
                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Customer Address</label>
                                                        <input class="form-control" type="text" name="customerAddress"
                                                            placeholder="Enter Address">
                                                    </div>
                                                </div>

                                                <div class="col-lg-4 col-sm-6 col-12">
                                                    <div class="mb-3 add-product">
                                                        <label class="form-label">Company/Organization</label>
                                                        <input class="form-control" type="text" name="organizationName"
                                                            placeholder="Enter Organization Name">
                                                    </div>
                                                </div>

                                                <div class="col-lg-12">
                                                    <div class="input-blocks summer-description-box transfer mb-3">
                                                        <label>Note</label>
                                                        <textarea class="form-control h-100" name="description"
                                                            rows="5"></textarea>
                                                        <p class="mt-1">Maximum 60 Characters</p>
                                                    </div>
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
                                                        <i data-feather="life-buoy" class="add-info"></i><span>Amount
                                                            Details </span>
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
                                                <div class="tab-pane fade show active" id="pills-home" role="tabpanel"
                                                    aria-labelledby="pills-home-tab">
                                                    <div class="row">

                                                        <div class="col-12">
                                                            <label class="form-label">Passengers <span>*</span></label>
                                                            <div id="passengerContainer">
                                                                <!-- Initial passenger row -->
                                                                <div
                                                                    class="row passenger-row mb-2 d-flex align-items-center">
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
                                                                            name="quantity[]" id="quantity"
                                                                            placeholder="Quantity" min="1" required>
                                                                    </div>
                                                                    <div class="col-4">
                                                                        <input type="number" id="invoiceAmount"
                                                                            class="form-control" name="amount[]"
                                                                            placeholder="Amount" step="0.01" min="1"
                                                                            required>
                                                                    </div>
                                                                    <div class="col-2">
                                                                        <button type="button"
                                                                            class="btn btn-danger btn-remove">–</button>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <button type="button" id="addPassenger"
                                                                class="btn btn-primary mt-1 mb-1">+ Add
                                                                Passenger</button>
                                                        </div>

                                                        <div class="col-lg-3 col-sm-6 col-12 m-2">
                                                            <div class="mb-3 add-product">
                                                                <label class="form-label">Total Amount <span>
                                                                        *</span></label>
                                                                <input type="text" id="total_amount" name="total_amount"
                                                                    class="form-control"
                                                                    placeholder="Enter Total Amount">
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
                            <button type="submit" name="submit" class="btn btn-submit">
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
            })



            $('#addPassenger').click(function () {
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