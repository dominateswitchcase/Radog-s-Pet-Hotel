<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// Session guard
requireLogin();
$role = $_SESSION['role']; // 'Admin' or 'Staff'

$success_message = '';
$error_message = '';

// =======================================================================
// JELLYACE FUNCTION: Process the Checkout and Settle Payment
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_payment') {
    $booking_id = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    $payment_method_id = filter_input(INPUT_POST, 'payment_method_id', FILTER_VALIDATE_INT);
    $total_amount = filter_input(INPUT_POST, 'total_amount', FILTER_VALIDATE_FLOAT);
    $payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_VALIDATE_INT);

    if ($booking_id && $payment_method_id && $total_amount !== false) {
        try {
            $pdo->beginTransaction();

            // 1. Update Booking Status to Completed
            $update_booking = $pdo->prepare("UPDATE BOOKING SET BOOKING_STATUS = 'Completed' WHERE BOOKING_ID = :bid");
            $update_booking->execute(['bid' => $booking_id]);

            // 2. Handle Payment Record (Update if exists, Insert if new)
            if ($payment_id) {
                $update_payment = $pdo->prepare("UPDATE PAYMENT SET PAYMENT_STATUS = 'Paid', PAYMENT_METHOD_ID = :pmid, TOTAL_AMOUNT = :amt WHERE PAYMENT_ID = :pid");
                $update_payment->execute(['pmid' => $payment_method_id, 'amt' => $total_amount, 'pid' => $payment_id]);
            } else {
                // Generate next Payment ID
                $id_stmt = $pdo->query("SELECT NVL(MAX(PAYMENT_ID), 0) + 1 FROM PAYMENT");
                $new_pay_id = $id_stmt->fetchColumn();

                $insert_payment = $pdo->prepare("INSERT INTO PAYMENT (PAYMENT_ID, PAYMENT_STATUS, TOTAL_AMOUNT, BOOKING_ID, PAYMENT_METHOD_ID) VALUES (:pid, 'Paid', :amt, :bid, :pmid)");
                $insert_payment->execute([
                    'pid' => $new_pay_id,
                    'amt' => $total_amount,
                    'bid' => $booking_id,
                    'pmid' => $payment_method_id
                ]);
            }

            $pdo->commit();
            $success_message = "Payment successfully settled and booking completed.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_message = "Transaction failed: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid form data submitted.";
    }
}
// =======================================================================

$pendingPayments = [];
$paymentMethods = [];

try {
    // JELLYACE: Dynamic Pricing SQL
    // Calculates stay duration in days and multiplies by the minimum daily cost for the pet's tier
    $sql = "SELECT b.BOOKING_ID, o.FIRST_NAME, o.LAST_NAME, pet.PET_NAME, pet.WEIGHT,
                   t.TIER_NAME,
                   GREATEST(TRUNC(b.CHECK_OUT_DATE) - TRUNC(b.CHECK_IN_DATE), 1) AS STAY_DURATION,
                   (SELECT MIN(DAILY_COST) FROM ACCOMMODATION WHERE TIER_ID = t.TIER_ID) AS DAILY_RATE,
                   p.PAYMENT_ID
            FROM BOOKING b
            JOIN OWNER o ON b.OWNER_ID = o.OWNER_ID
            JOIN PET pet ON b.PET_ID = pet.PET_ID
            JOIN TIER t ON pet.WEIGHT >= t.WEIGHT_MIN AND pet.WEIGHT <= t.WEIGHT_MAX
            LEFT JOIN PAYMENT p ON b.BOOKING_ID = p.BOOKING_ID
            WHERE b.BOOKING_STATUS = 'Confirmed' 
              AND b.CHECK_IN_DATE <= SYSDATE
              AND (p.PAYMENT_STATUS IS NULL OR p.PAYMENT_STATUS = 'Pending')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $pendingPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch active payment methods
    $sql_methods = "SELECT PAYMENT_METHOD_ID, METHOD_NAME FROM PAYMENT_METHOD WHERE METHOD_STATUS = 'Active'";
    $stmt_methods = $pdo->prepare($sql_methods);
    $stmt_methods->execute();
    $paymentMethods = $stmt_methods->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Database Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --orange:      #FA8112;
            --orange-dk:   #d96a08;
            --orange-lt:   #fca04a;
            --black:       #222222;
            --beige:       #FAF3E1;
            --gold:        #F5E7C6;
            --white:       #ffffff;
            --radius-card:  20px;
            --radius-input: 12px;
            --radius-btn:   12px;
            --shadow-card:  0 24px 70px rgba(15, 23, 42, 0.08);
            --border-soft:  1px solid rgba(34, 34, 34, 0.08);
        }
        .sidebar{background:var(--black);color:var(--gold);position:relative}
        .sidebar::before{content:'';position:absolute;inset:0;background-image:repeating-linear-gradient(-55deg,transparent,transparent 18px,rgba(250,129,18,0.04) 18px,rgba(250,129,18,0.04) 19px);opacity:0.08}
        .brand h1{font-family:'Bebas Neue',cursive;color:var(--orange);}
        .user-pill{background:rgba(250,129,18,0.08);padding:8px 12px;border-radius:999px;color:var(--white);display:inline-flex;align-items:center;gap:8px}
    </style>
</head>
<body style="background-color: #FAF3E1;">
<div class="dashboard-shell d-flex min-vh-100">
    
    <aside class="sidebar d-flex flex-column p-4">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo htmlentities($_SESSION['username'] ?? 'Staff'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="admin_dashboard.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="calendar.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="checkout.php" class="nav-link d-flex align-items-center mb-2 active" style="background-color: #FA8112; color: white;"><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
            <?php if (isAdmin()): ?>
                <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
                    <i class="bi bi-gear-fill me-3"></i> User Management
                </a>
            <?php endif; ?>
            <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
        <i class="bi bi-gear-fill me-3"></i> User Management
    </a>
        </nav>
        <div class="mt-auto">
            <a href="../logout.php" class="btn btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content flex-grow-1 p-4 p-md-5">
        <div class="container" style="max-width: 700px;">
            <div class="mb-4">
                <h1 class="h3 mb-1">Process Checkout & Payment</h1>
                <p class="text-muted-custom">Finalize bookings and settle pending transactions.</p>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i> <?php echo htmlentities($success_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i> <?php echo htmlentities($error_message); ?></div>
            <?php endif; ?>

            <form action="checkout.php" method="POST" id="checkoutForm">
                <input type="hidden" name="action" value="settle_payment">
                <input type="hidden" name="payment_id" id="paymentIdInput" value="">
                <input type="hidden" name="total_amount" id="totalAmountInput" value="">
                
                <div class="bg-panel p-4 mb-4 border rounded shadow-sm" style="background-color: #ffffff;">
                    <h2 class="h5 mb-3">Select Checked-In Booking</h2>
                    <select name="booking_id" id="bookingSelect" class="form-select" required>
                        <option value="" data-amount="0" disabled selected>-- Choose a Booking --</option>
                        <?php foreach ($pendingPayments as $pay): ?>
                            <?php 
                                // Calculate dynamic amount: Duration * Daily Rate
                                $calculated_total = $pay['STAY_DURATION'] * $pay['DAILY_RATE'];
                            ?>
                            <option value="<?php echo htmlentities($pay['BOOKING_ID']); ?>" 
                                    data-amount="<?php echo htmlentities($calculated_total); ?>"
                                    data-duration="<?php echo htmlentities($pay['STAY_DURATION']); ?>"
                                    data-rate="<?php echo htmlentities($pay['DAILY_RATE']); ?>"
                                    data-booking-id="<?php echo htmlentities($pay['BOOKING_ID']); ?>"
                                    data-owner="<?php echo htmlentities($pay['FIRST_NAME'] . ' ' . $pay['LAST_NAME']); ?>"
                                    data-pet="<?php echo htmlentities($pay['PET_NAME']); ?>"
                                    data-tier="<?php echo htmlentities($pay['TIER_NAME']); ?>"
                                    data-payment-id="<?php echo htmlentities($pay['PAYMENT_ID'] ?? ''); ?>">
                                BK<?php echo str_pad($pay['BOOKING_ID'], 3, '0', STR_PAD_LEFT); ?> - 
                                <?php echo htmlentities($pay['FIRST_NAME'] . ' ' . $pay['LAST_NAME']); ?> 
                                (Pet: <?php echo htmlentities($pay['PET_NAME']); ?> | Tier: <?php echo htmlentities($pay['TIER_NAME']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="bookingSummary" class="bg-panel p-4 mb-4 border rounded shadow-sm" style="background-color: #ffffff; display: none;">
                    <h2 class="h5 mb-3">Booking Summary</h2>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Booking ID:</strong> <span id="summaryBookingId"></span></p>
                            <p><strong>Owner:</strong> <span id="summaryOwner"></span></p>
                            <p><strong>Pet:</strong> <span id="summaryPet"></span> (<span id="summaryTier"></span>)</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Stay Duration:</strong> <span id="summaryDuration"></span> Days</p>
                            <p><strong>Daily Rate:</strong> <span id="summaryRate"></span></p>
                            <p><strong>Total Amount:</strong> <span id="summaryAmount"></span></p>
                        </div>
                    </div>
                </div>

                <div class="bg-panel p-4 mb-4 border rounded shadow-sm text-center" style="background-color: #ffffff;">
                    <h5 class="text-muted mb-2">Total Amount Due</h5>
                    <div class="display-4 fw-bold mb-2" id="totalDisplay" style="color: #FA8112;">₱0.00</div>
                    <span class="badge bg-secondary" id="petDisplay">No Pet Selected</span>
                </div>

                <div class="bg-panel p-4 mb-4 border rounded shadow-sm" style="background-color: #ffffff;">
                    <label class="form-label small fw-bold">Select Payment Method</label>
                    <select name="payment_method_id" id="methodSelect" class="form-select" required disabled>
                        <option value="" disabled selected>-- Choose Method --</option>
                        <?php foreach ($paymentMethods as $method): ?>
                            <option value="<?php echo htmlentities($method['PAYMENT_METHOD_ID']); ?>">
                                <?php echo htmlentities($method['METHOD_NAME']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="d-flex gap-3">
                    <a href="checkout.php" class="btn btn-light border px-4 py-2" style="background-color: #F5E7C6;">Reset</a>
                    <button type="submit" id="submitBtn" class="btn btn-brand flex-grow-1 py-2 text-white" style="background-color: #FA8112; border: none;" disabled>
                        Settle Payment
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const bookingSelect = document.getElementById('bookingSelect');
    const totalDisplay  = document.getElementById('totalDisplay');
    const petDisplay    = document.getElementById('petDisplay');
    const methodSelect  = document.getElementById('methodSelect');
    const submitBtn     = document.getElementById('submitBtn');

    bookingSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        
        // Extract Data Attributes dynamically calculated by the DB
        const amount = parseFloat(selectedOption.getAttribute('data-amount')) || 0;
        const rate = parseFloat(selectedOption.getAttribute('data-rate')) || 0;
        const duration = selectedOption.getAttribute('data-duration') || '0';
        const petName = selectedOption.getAttribute('data-pet') || 'Unknown';
        const tier = selectedOption.getAttribute('data-tier') || 'Unknown';
        const bookingId = selectedOption.getAttribute('data-booking-id') || '';
        const owner = selectedOption.getAttribute('data-owner') || 'Unknown';
        const paymentId = selectedOption.getAttribute('data-payment-id') || '';
        
        // Populate Hidden Inputs for PHP Processing
        document.getElementById('paymentIdInput').value = paymentId;
        document.getElementById('totalAmountInput').value = amount;
        
        // Format Currency 
        const formattedAmount = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amount);
        const formattedRate = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(rate);
        
        // Update the display
        totalDisplay.textContent = formattedAmount;
        petDisplay.textContent = "Checking out: " + petName;
        petDisplay.classList.replace('bg-secondary', 'bg-success');
        
        // Show and populate booking summary
        const summaryDiv = document.getElementById('bookingSummary');
        document.getElementById('summaryBookingId').textContent = 'BK' + bookingId.padStart(3, '0');
        document.getElementById('summaryOwner').textContent = owner;
        document.getElementById('summaryPet').textContent = petName;
        document.getElementById('summaryTier').textContent = tier;
        document.getElementById('summaryDuration').textContent = duration;
        document.getElementById('summaryRate').textContent = formattedRate + ' / day';
        document.getElementById('summaryAmount').textContent = formattedAmount;
        summaryDiv.style.display = 'block';
        
        // Enable the rest of the form
        methodSelect.removeAttribute('disabled');
        submitBtn.removeAttribute('disabled');
    });
});
</script>
</body>
</html>
