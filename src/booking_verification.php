<?php
session_start();
require_once '../config/db.php';

// RBAC: Ensure authorized access
if (!isset($_SESSION['account_id'])) {
    header('Location: employee_login.php');
    exit();
}

$booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
$message = '';
$booking = null;

if ($booking_id) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Data Validation & Constraints
        $status = in_array($_POST['status'] ?? '', ['Pending', 'Confirmed', 'Cancelled', 'Completed']) ? $_POST['status'] : 'Pending';
        
        // Form Verification Checkboxes
        $reg_form_verified = isset($_POST['reg_form_verified']) ? 'Y' : 'N';
        $waiver_verified = isset($_POST['waiver_verified']) ? 'Y' : 'N';
        $vacc_card_verified = isset($_POST['vacc_card_verified']) ? 'Y' : 'N';

        try {
            // STRICT ALIGNMENT: Columns must exactly match the Oracle BOOKING Table DDL
            $update_stmt = $pdo->prepare(
                "UPDATE BOOKING SET 
                    BOOKING_STATUS = :status,
                    REG_FORM_VERIFIED = :reg_form,
                    WAIVER_VERIFIED = :waiver,
                    VACC_CARD_VERIFIED = :vacc_card
                 WHERE BOOKING_ID = :id"
            );
            
            $update_stmt->execute([
                'status' => $status,
                'reg_form' => $reg_form_verified,
                'waiver' => $waiver_verified,
                'vacc_card' => $vacc_card_verified,
                'id' => $booking_id
            ]);

            $message = 'Booking details updated successfully.';
        } catch (PDOException $e) {
            die("Database Update Failed: " . $e->getMessage());
        }
    }

    $detail_stmt = $pdo->prepare(
        "SELECT B.*, P.PET_NAME, O.FIRST_NAME AS OWNER_FIRST, O.LAST_NAME AS OWNER_LAST,
                TO_CHAR(B.CHECK_IN_DATE, 'FMMonth DD, YYYY') AS CHECKIN_LABEL,
                TO_CHAR(B.CHECK_OUT_DATE, 'FMMonth DD, YYYY') AS CHECKOUT_LABEL
         FROM BOOKING B
         JOIN PET P ON B.PET_ID = P.PET_ID
         JOIN OWNER O ON B.OWNER_ID = O.OWNER_ID
         WHERE B.BOOKING_ID = :id"
    );
    $detail_stmt->execute(['id' => $booking_id]);
    $booking = $detail_stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$booking) {
    die('Booking not found.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Verification - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
<div class="dashboard-shell d-flex min-vh-100">
    
    <main class="main-content flex-grow-1 p-4 p-md-5">
<div class="container p-4 p-md-5">
    <a href="calendar.php" class="btn btn-link text-muted-custom text-decoration-none px-0 mb-4"><i class="bi bi-arrow-left"></i> Back</a>
    <div class="mb-4">
        <h1 class="h3 mb-1">Manage Booking Details</h1>
        <p class="text-muted-custom">Booking ID: BK-<?php echo str_pad($booking['BOOKING_ID'], 3, '0', STR_PAD_LEFT); ?></p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success small py-2"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <form action="booking_verification.php?xabooking_id=<?php echo $booking_id; ?>" method="POST">
        <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">
        <div class="row g-4">
            <div class="col-md-6">
                <div class="bg-panel p-4">
                    <h2 class="h5 mb-4">Booking Information</h2>
                    <div class="mb-3"><small class="text-muted-custom d-block">Owner</small><span><?php echo htmlspecialchars($booking['OWNER_FIRST'] . ' ' . $booking['OWNER_LAST']); ?></span></div>
                    <div class="mb-3"><small class="text-muted-custom d-block">Pet</small><span><?php echo htmlspecialchars($booking['PET_NAME']); ?></span></div>
                    <div class="mb-3"><small class="text-muted-custom d-block">Stay Period</small><span><?php echo htmlspecialchars($booking['CHECKIN_LABEL'] . ' - ' . $booking['CHECKOUT_LABEL']); ?></span></div>
                </div>
            </div>
            <div class="col-md-6 d-flex flex-column gap-4">
                <div class="bg-panel p-4">
                    <h2 class="h5 mb-4">Compliance Verification</h2>
                  <div class="form-check mb-3 bg-white p-3 rounded border d-flex justify-content-between align-items-center">
                        <label class="form-check-label ms-2" for="checkRegForm">Pet Physical Registration Form</label>
                        <input class="form-check-input" type="checkbox" id="checkRegForm" name="reg_form_verified"
                            <?php echo ($booking['REG_FORM_VERIFIED'] ?? 'N') === 'Y' ? 'checked' : ''; ?>>
                    </div>

                    <div class="form-check mb-3 bg-white p-3 rounded border d-flex justify-content-between align-items-center">
                        <label class="form-check-label ms-2" for="checkWaiver">Waiver and Consent Form</label>
                        <input class="form-check-input" type="checkbox" id="checkWaiver" name="waiver_verified"
                            <?php echo ($booking['CONSENT_FORM_SIGNED'] ?? 'N') === 'Y' ? 'checked' : ''; ?>>
                    </div>

                    <div class="form-check mb-3 bg-white p-3 rounded border d-flex justify-content-between align-items-center">
                        <label class="form-check-label ms-2" for="checkVaccCard">Vaccination Card</label>
                        <input class="form-check-input" type="checkbox" id="checkVaccCard" name="vacc_card_verified"
                            <?php echo ($booking['VACC_CARD_VERIFIED'] ?? 'N') === 'Y' ? 'checked' : ''; ?>>
                    </div>
                </div>
                <div class="bg-panel p-4">
                    <h2 class="h5 mb-3">Booking Status</h2>
                    <select name="status" class="form-select mb-3">
                        <option value="Pending" <?php echo $booking['BOOKING_STATUS'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Confirmed" <?php echo $booking['BOOKING_STATUS'] === 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="Cancelled" <?php echo $booking['BOOKING_STATUS'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="Completed" <?php echo $booking['BOOKING_STATUS'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                    <button type="submit" class="btn btn-brand w-100 py-2">Save Status</button>
                </div>
            </div>
        </div>
    </form>
</div>
    </main>
</div>
<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/validation.js"></script>
</body>
</html>
