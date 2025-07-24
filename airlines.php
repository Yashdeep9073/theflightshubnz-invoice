<?php

session_start();
if (!isset($_SESSION["admin_id"])) {
    header("location: index.php");
}
require "./database/config.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {

    try {
        $airlineName = $_POST['airlineName'];
        $stmtInsert = $db->prepare('INSERT INTO airlines (airline_name) VALUES(?)');
        $stmtInsert->bind_param('s', $airlineName);

        if ($stmtInsert->execute()) {
            $_SESSION['success'] = 'Airline Added Successfully';
            header("Location: airlines.php");
            exit;
        } else {
            $_SESSION['error'] = 'Error While adding airline';
            header("Location: airlines.php");
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e;
        header("Location: airlines.php");
        exit;
    }

}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editAirlineId'])) {
    try {

        // echo "<pre>";
        // print_r($_POST);
        // exit;

        $editAirlineId = filter_input(INPUT_POST, 'editAirlineId', FILTER_SANITIZE_NUMBER_INT);
        $editAirlineName = filter_input(INPUT_POST, 'editAirlineName', FILTER_SANITIZE_STRING);
        $isActive = filter_input(INPUT_POST, 'isActive', FILTER_SANITIZE_NUMBER_INT);

        $stmtUpdate = $db->prepare('UPDATE airlines SET 
        airline_name = ? , 
        is_active = ? 
        WHERE airline_id = ?
        ');

        $stmtUpdate->bind_param(
            'sii',
            $editAirlineName,
            $isActive,
            $editAirlineId
        );

        if ($stmtUpdate->execute()) {
            $_SESSION['success'] = 'Airline Updated Successfully';
            header("Location: airlines.php");
            exit;
        } else {
            $_SESSION['error'] = 'Error While updating airline';
            header("Location: airlines.php");
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e;
        header("Location: airlines.php");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['airlineId'])) {
    try {
        $airlineId = filter_input(INPUT_POST, 'airlineId', FILTER_SANITIZE_NUMBER_INT);

        $stmtDelete = $db->prepare('DELETE FROM airlines WHERE airline_id = ?');
        $stmtDelete->bind_param('i', $airlineId);

        if ($stmtDelete->execute()) {
            echo json_encode([
                'status' => 200,
                'message' => 'Airline deleted successfully.'
            ]);
            exit;
        } else {
            echo json_encode([
                'status' => 400,
                'error' => 'Failed to delete airline.'
            ]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode([
            'status' => 500,
            'error' => $e->getMessage()
        ]);
        exit;
    }
}


try {
    $stmtFetch = $db->prepare("SELECT * FROM airlines");
    $stmtFetch->execute();
    $airlines = $stmtFetch->get_result();

    $stmtFetchCompanySettings = $db->prepare("SELECT * FROM company_settings");
    $stmtFetchCompanySettings->execute();
    $companySettings = $stmtFetchCompanySettings->get_result()->fetch_array(MYSQLI_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = $e;
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
    <title>Airlines</title>

    <link rel="shortcut icon" type="image/x-icon"
        href="<?= isset($companySettings['favicon']) ? $companySettings['favicon'] : "assets/img/fav/vis-favicon.png" ?>">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">

    <link rel="stylesheet" href="assets/css/animate.css">

    <link rel="stylesheet" href="assets/plugins/select2/css/select2.min.css">

    <link rel="stylesheet" href="assets/css/dataTables.bootstrap5.min.css">

    <link rel="stylesheet" href="assets/css/bootstrap-datetimepicker.min.css">

    <link rel="stylesheet" href="assets/plugins/summernote/summernote-bs4.min.css">

    <link rel="stylesheet" href="assets/plugins/fontawesome/css/fontawesome.min.css">
    <link rel="stylesheet" href="assets/plugins/fontawesome/css/all.min.css">

    <link rel="stylesheet" href="assets/css/style.css">

    <!-- toast  -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/notyf@3/notyf.min.js"></script>
</head>

<body>


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
                            <h4>Airlines List</h4>
                            <h6>Manage Airlines Data</h6>
                        </div>
                    </div>

                    <?php if ($isAdmin || hasPermission('Add Service', $privileges, $roleData['0']['role_name'])): ?>
                        <div class="page-btn">
                            <a href="#" class="btn btn-added" data-bs-toggle="modal" data-bs-target="#add-units"><i
                                    data-feather="plus-circle" class="me-2"></i>Add New Airline</a>
                        </div>
                    <?php endif; ?>

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


                        </div>


                        <div class="table-responsive">
                            <table class="table datanew">
                                <thead>
                                    <tr>
                                        <th class="no-sort">
                                            <label class="checkboxs">
                                                <input type="checkbox" id="select-all">
                                                <span class="checkmarks"></span>
                                            </label>
                                        </th>
                                        <th>Airline</th>
                                        <th>Status</th>
                                        <th class="no-sort text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($airlines->fetch_all(MYSQLI_ASSOC) as $airline) { ?>
                                        <tr>
                                            <td>
                                                <label class="checkboxs">
                                                    <input type="checkbox">
                                                    <span class="checkmarks"></span>
                                                </label>
                                            </td>
                                            <td><?php echo $airline['airline_name'] ?></td>
                                            <td>
                                                <?php if ($airline['is_active'] == 1) { ?>
                                                    <span class="badge badge-lg bg-success">Active</span>
                                                <?php } else { ?>
                                                    <span class="badge badge-lg bg-danger">Inactive</span>
                                                <?php } ?>
                                            </td>


                                            <td class="text-center">
                                                <a class="action-set" href="javascript:void(0);" data-bs-toggle="dropdown"
                                                    aria-expanded="true">
                                                    <i class="fa fa-ellipsis-v" aria-hidden="true"></i>
                                                </a>
                                                <ul class="dropdown-menu">
                                                    <?php if ($isAdmin || hasPermission('Edit Service', $privileges, $roleData['0']['role_name'])): ?>

                                                        <li>
                                                            <a data-bs-toggle="modal" data-bs-target="#edit-units"
                                                                data-airline-id="<?php echo $airline['airline_id'] ?>"
                                                                data-airline-name="<?php echo $airline['airline_name'] ?>"
                                                                data-airline-status="<?php echo $airline['is_active'] ?>"
                                                                class="editButton dropdown-item"><i data-feather="edit"
                                                                    class="info-img"></i>Edit
                                                            </a>
                                                        </li>
                                                    <?php endif; ?>

                                                    <?php if ($isAdmin || hasPermission('Delete Service', $privileges, $roleData['0']['role_name'])): ?>
                                                        <li>
                                                            <a href="javascript:void(0);"
                                                                data-airline-id="<?php echo $airline['airline_id'] ?>"
                                                                class="dropdown-item deleteButton mb-0"><i
                                                                    data-feather="trash-2" class="info-img"></i>Delete </a>
                                                        </li>
                                                    <?php endif; ?>
                                                </ul>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>


    <div class="modal fade" id="add-units">
        <div class="modal-dialog modal-dialog-centered custom-modal-two">
            <div class="modal-content">
                <div class="page-wrapper-new p-0">
                    <div class="content">
                        <div class="modal-header border-0 custom-modal-header">
                            <div class="page-title">
                                <h4>Add Airline</h4>
                            </div>
                            <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body custom-modal-body">
                            <form action="" method="post">
                                <div class="row">
                                    <div class="col-lg-12">
                                        <div class="input-blocks">
                                            <label>Airline Name</label>
                                            <input type="text" class="form-control" placeholder="Enter Airline Name"
                                                name="airlineName" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer-btn">
                                    <button type="button" class="btn btn-cancel me-2"
                                        data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="submit" class="btn btn-submit">Submit</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="modal fade" id="edit-units">
        <div class="modal-dialog modal-dialog-centered custom-modal-two">
            <div class="modal-content">
                <div class="page-wrapper-new p-0">
                    <div class="content">
                        <div class="modal-header border-0 custom-modal-header">
                            <div class="page-title">
                                <h4>Edit Airlines</h4>
                            </div>
                            <button type="button" class="close" data-bs-dismiss="modal" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                        <div class="modal-body custom-modal-body">
                            <form action="" method="post">
                                <input type="hidden" id="editAirlineId" name="editAirlineId">
                                <div class="row">
                                    <div class="col-lg-12">
                                        <div class="input-blocks">
                                            <label>Airline Name</label>
                                            <input type="text" placeholder="" id="editAirlineName"
                                                name="editAirlineName" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-lg-12">
                                        <div class="input-blocks">
                                            <label>Status</label>
                                            <select class="select" name="isActive" id="isActive" required>
                                                <option value="">Select</option>
                                                <option value="1">Enabled</option>
                                                <option value="0">Disabled</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer-btn">
                                    <button type="button" class="btn btn-cancel me-2"
                                        data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" name="edit" class="btn btn-submit">Submit</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <script src="assets/js/jquery-3.7.1.min.js"></script>

    <script src="assets/js/feather.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>

    <script src="assets/js/jquery.slimscroll.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>

    <script src="assets/js/jquery.dataTables.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>
    <script src="assets/js/dataTables.bootstrap5.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>

    <script src="assets/js/moment.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>
    <script src="assets/js/bootstrap-datetimepicker.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>

    <script src="assets/js/bootstrap.bundle.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>

    <script src="assets/plugins/summernote/summernote-bs4.min.js"
        type="e63b4464ba49b78295f8df4d-text/javascript"></script>

    <script src="assets/plugins/select2/js/select2.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>

    <script src="assets/plugins/sweetalert/sweetalert2.all.min.js"
        type="e63b4464ba49b78295f8df4d-text/javascript"></script>
    <script src="assets/plugins/sweetalert/sweetalerts.min.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>
    <script src="assets/js/script.js" type="e63b4464ba49b78295f8df4d-text/javascript"></script>
    <script src="assets/js/rocket-loader-min.js" data-cf-settings="e63b4464ba49b78295f8df4d-|49" defer=""></script>

    <script src="assets/js/custom.js"></script>

    <script>
        $(document).ready(function (e) {

            $(document).on('click', '.editButton', function () {

                let airlineId = $(this).data("airline-id");
                let airlineName = $(this).data("airline-name");
                let airlineStatus = $(this).data("airline-status");


                $('#editAirlineId').val(airlineId);
                $('#editAirlineName').val(airlineName);
                $('#isActive').val(airlineStatus);

            });


            // Handle the click event on the delete button
            $(document).on('click', '.deleteButton', function (event) {
                let airlineId = $(this).data("airline-id");

                Swal.fire({
                    title: "Are you sure?",
                    text: "You won't be able to revert this!",
                    showCancelButton: true,
                    confirmButtonColor: "#ff9f43",
                    cancelButtonColor: "#d33",
                    confirmButtonText: "Yes, delete it!"
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Send AJAX request to delete the record from the database
                        $.ajax({
                            url: window.location.href,
                            type: 'POST',
                            data: { airlineId: airlineId },
                            success: function (response) {

                                let result = JSON.parse(response);
                                console.log(result);

                                // Show success message and reload the page
                                Swal.fire(
                                    'Deleted!',
                                    'The Airline has been deleted.',
                                ).then(() => {
                                    // Reload the page or remove the deleted row from the UI
                                    location.reload();
                                });
                            },
                            error: function (xhr, status, error) {
                                // Show error message if the AJAX request fails
                                Swal.fire(
                                    'Error!',
                                    'There was an error deleting the airline.',
                                    'error'
                                );
                            }
                        });
                    }
                });
            });

        })
    </script>
</body>

</html>