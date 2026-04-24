<?php
session_start();
require_once '../config/db.php';

// RBAC: Ensure authorized access for staff/admin
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_VALIDATE_INT);
    $pet_id = filter_input(INPUT_POST, 'pet_id', FILTER_VALIDATE_INT);
    $accommodation_id = filter_input(INPUT_POST, 'accommodation_id', FILTER_VALIDATE_INT);
    $check_in = filter_input(INPUT_POST, 'check_in', FILTER_SANITIZE_STRING);
    $check_out = filter_input(INPUT_POST, 'check_out', FILTER_SANITIZE_STRING);
    $instructions = trim(filter_input(INPUT_POST, 'instructions', FILTER_SANITIZE_FULL_SPECIAL_CHARS));

    $today = date('Y-m-d');

    if (!$owner_id || !$pet_id || !$accommodation_id || !$check_in || !$check_out || $check_in < $today || $check_out <= $check_in) {
        $message = 'Please complete all required fields with valid dates.';
    } else {
        // Validate the pet belongs to the selected owner
        $validate_stmt = $pdo->prepare("SELECT COUNT(*) AS CNT FROM PET WHERE PET_ID = :pet AND OWNER_ID = :owner");
        $validate_stmt->execute(['pet' => $pet_id, 'owner' => $owner_id]);
        $pet_matches_owner = (int) $validate_stmt->fetchColumn();

        if ($pet_matches_owner !== 1) {
            $message = 'Selected pet does not belong to the chosen owner.';
        } else {
            // Check existing confirmed bookings for the same pet
            $availability_stmt = $pdo->prepare(
                "SELECT COUNT(*) AS CNT FROM BOOKING 
                 WHERE PET_ID = :pet 
                   AND BOOKING_STATUS = 'Confirmed' 
                   AND (CHECK_IN_DATE <= TO_DATE(:check_out,'YYYY-MM-DD') 
                        AND CHECK_OUT_DATE >= TO_DATE(:check_in,'YYYY-MM-DD'))"
            );
            $availability_stmt->execute([
                'pet' => $pet_id,
                'check_in' => $check_in,
                'check_out' => $check_out
            ]);

            $conflicts = (int) $availability_stmt->fetchColumn();
            if ($conflicts > 0) {
                $message = 'This pet already has a confirmed booking during the selected dates.';
            } else {
                // Check if unit is still available (Concurrency Control)
                $unit_check = $pdo->prepare("SELECT OCCUPANCY_STATUS FROM ACCOMMODATION WHERE ACCOMMODATION_ID = :acc_id FOR UPDATE");
                $unit_check->execute(['acc_id' => $accommodation_id]);
                $unit_status = $unit_check->fetchColumn();

                if ($unit_status !== 'Available') {
                    $message = 'The selected accommodation unit is no longer available.';
                } else {
                    try {
                       // Enterprise Transaction Processing
                        $pdo->beginTransaction();

                        // 1. Determine a new booking ID
                        $id_stmt = $pdo->query("SELECT NVL(MAX(BOOKING_ID), 0) + 1 AS NEXT_ID FROM BOOKING");
                        $booking_id = (int) $id_stmt->fetchColumn();

                        $employee_id = $_SESSION['employee_id'] ?? null;
                        if (!$employee_id) {
                            $emp_stmt = $pdo->prepare("SELECT EMPLOYEE_ID FROM USER_ACCOUNT WHERE ACCOUNT_ID = :account_id");
                            $emp_stmt->execute(['account_id' => $_SESSION['account_id']]);
                            $employee_id = $emp_stmt->fetchColumn() ?: 0;
                        }

                        // 2. Insert Booking Record
                        $insert_stmt = $pdo->prepare(
                            "INSERT INTO BOOKING (
                                BOOKING_ID, BOOKING_STATUS, CHECK_IN_DATE, CHECK_OUT_DATE,
                                SPECIAL_INSTRUCTIONS, OWNER_ID, PET_ID, EMPLOYEE_ID, ACCOMMODATION_ID,
                                CONSENT_FORM_SIGNED, REG_FORM_VERIFIED, WAIVER_VERIFIED, VACC_CARD_VERIFIED
                            ) VALUES (
                                :id, 'Confirmed', TO_DATE(:cin,'YYYY-MM-DD'), TO_DATE(:cout,'YYYY-MM-DD'),
                                :instr, :owner, :pet, :emp, :acc, 'N', 'N', 'N', 'N'
                            )"
                        );

                        $insert_stmt->execute([
                            'id' => $booking_id,
                            'cin' => $check_in,
                            'cout' => $check_out,
                            'instr' => $instructions,
                            'owner' => $owner_id,
                            'pet' => $pet_id,
                            'emp' => $employee_id,
                            'acc' => $accommodation_id
                        ]);

                        // 3. Update Accommodation Status
                        $update_acc = $pdo->prepare("UPDATE ACCOMMODATION SET OCCUPANCY_STATUS = 'Booked' WHERE ACCOMMODATION_ID = :acc_id");
                        $update_acc->execute(['acc_id' => $accommodation_id]);

                        $pdo->commit();
                        header('Location: calendar.php?booking_added=1');
                        exit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $message = "Transaction Failed: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Data Fetching for Dropdowns
$owners_stmt = $pdo->query("SELECT OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER FROM OWNER ORDER BY LAST_NAME ASC");
$owners = $owners_stmt->fetchAll(PDO::FETCH_ASSOC);

$pets_stmt = $pdo->query("SELECT PET_ID, OWNER_ID, PET_NAME, WEIGHT FROM PET ORDER BY PET_NAME ASC");
$all_pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Accommodations alongside their Tier weight constraints
$acc_stmt = $pdo->query("
    SELECT A.ACCOMMODATION_ID, A.UNIT_NAME, A.OCCUPANCY_STATUS, 
           T.WEIGHT_MIN, T.WEIGHT_MAX, T.TIER_NAME 
    FROM ACCOMMODATION A 
    JOIN TIER T ON A.TIER_ID = T.TIER_ID 
    ORDER BY T.WEIGHT_MIN ASC, A.UNIT_NAME ASC
");
$all_accommodations = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encode Reservation - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <style>
        body { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: #fff; border-right: 1px solid rgba(0,0,0,.1); position: sticky; top: 0; height: 100vh; }
        .main-content { flex-grow: 1; background: #f8f9fa; }
    </style>
</head>
<body>
    <aside class="sidebar d-flex flex-column p-4" style="background-color: #F5E7C6;">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo htmlentities($_SESSION['username'] ?? 'Staff'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
                <a href="staff_dashboard.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="staff_encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="staff_calendar.php" class="nav-link d-flex align-items-center mb-2 " ><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="staff_owner.php" class="nav-link d-flex align-items-center mb-2 active style="background-color: #FA8112; color: white;""><i class="bi bi-people me-3"></i> Owners</a>
            <a href="staff_pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="staff_checkout.php" class="nav-link d-flex align-items-center mb-2 " ><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
            <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>
                <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
                    <i class="bi bi-gear-fill me-3"></i> User Management
                </a>
            <?php endif; ?>
        </nav>
        <div class="mt-auto">
            <a href="../index.php" class="btn btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content p-4 p-md-5">
        <div class="mb-4">
            <h1 class="h3 mb-1">New Reservation Request</h1>
            <p class="text-muted-custom">Encode booking from Messenger chat</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo strpos($message, 'successfully') !== false ? 'success' : 'danger'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form action="encode_reservation.php" method="POST" id="reservationForm">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="bg-panel p-4 h-100 shadow-sm">
                        <h2 class="h5 mb-4">Customer & Accommodation</h2>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Select Owner</label>
                            <select name="owner_id" id="ownerSelect" class="form-select px-3 py-2" required onchange="filterPets()">
                                <option value="">-- Choose Owner --</option>
                                <?php foreach ($owners as $owner): ?>
                                    <option value="<?php echo $owner['OWNER_ID']; ?>">
                                        <?php echo htmlspecialchars($owner['LAST_NAME'] . ", " . $owner['FIRST_NAME'] . " (" . $owner['CONTACT_NUMBER'] . ")"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Select Pet</label>
                            <select name="pet_id" id="petSelect" class="form-select px-3 py-2" required disabled onchange="filterAccommodations()">
                                <option value="">-- Select an owner first --</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Available Unit (Filtered by Size)</label>
                            <select name="accommodation_id" id="accommodationSelect" class="form-select px-3 py-2" required disabled onchange="validateForm()">
                                <option value="">-- Select a pet first --</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="bg-panel p-4 h-100 shadow-sm">
                        <h2 class="h5 mb-4">Booking Details </h2>
                        <div class="mb-3">
                            <label class="form-label small">Check-In Date</label>
                            <input type="date" id="checkIn" name="check_in" class="form-control" 
                                   min="<?php echo date('Y-m-d'); ?>" required onchange="validateForm()">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small">Check-Out Date</label>
                            <input type="date" id="checkOut" name="check_out" class="form-control" required onchange="validateForm()">
                        </div>
                        <div class="mb-4">
                            <label class="form-label small">Special Instructions </label>
                            <textarea name="instructions" rows="3" class="form-control" placeholder="Allergies, behavior, or feeding notes..."></textarea>
                        </div>
                        <button type="submit" id="submitBtn" class="btn btn-brand w-100 py-2" disabled>
                            <i class="bi bi-calendar-check"></i> Confirm Reservation
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </main>

    <script>
        const petsData = <?php echo json_encode($all_pets); ?>;
        const accommodationsData = <?php echo json_encode($all_accommodations); ?>;
        
        const form = document.getElementById('reservationForm');
        const submitBtn = document.getElementById('submitBtn');
        const petSelect = document.getElementById('petSelect');
        const accSelect = document.getElementById('accommodationSelect');

        function filterPets() {
            const ownerId = document.getElementById('ownerSelect').value;
            
            // Reset Pet Dropdown
            petSelect.innerHTML = '<option value="">-- Select a pet --</option>';
            
            // Reset Accommodation Dropdown
            accSelect.innerHTML = '<option value="">-- Select a pet first --</option>';
            accSelect.disabled = true;
            
            if (!ownerId) {
                petSelect.disabled = true;
                validateForm();
                return;
            }

            const filteredPets = petsData.filter(pet => pet.OWNER_ID == ownerId);
            if (filteredPets.length > 0) {
                filteredPets.forEach(pet => {
                    const option = document.createElement('option');
                    option.value = pet.PET_ID;
                    option.textContent = `${pet.PET_NAME} (${pet.WEIGHT}kg)`;
                    petSelect.appendChild(option);
                });
                petSelect.disabled = false;
            } else {
                petSelect.innerHTML = '<option value="">-- No pets found for this owner --</option>';
                petSelect.disabled = true;
            }
            validateForm();
        }

        // JELLYACE: New Tier/Weight Constraint Filtering
        function filterAccommodations() {
            accSelect.innerHTML = '<option value="">-- Select an Accommodation Unit --</option>';
            const petId = petSelect.value;

            if (!petId) {
                accSelect.disabled = true;
                validateForm();
                return;
            }

            const selectedPet = petsData.find(p => p.PET_ID == petId);
            const petWeight = parseFloat(selectedPet.WEIGHT);

            // Filter units by matching weight constraints AND 'Available' status
            const validUnits = accommodationsData.filter(acc => {
                return acc.OCCUPANCY_STATUS === 'Available' &&
                       petWeight >= parseFloat(acc.WEIGHT_MIN) &&
                       petWeight <= parseFloat(acc.WEIGHT_MAX);
            });

            if (validUnits.length > 0) {
                validUnits.forEach(acc => {
                    const option = document.createElement('option');
                    option.value = acc.ACCOMMODATION_ID;
                    option.textContent = `${acc.UNIT_NAME} - ${acc.TIER_NAME} Tier`;
                    accSelect.appendChild(option);
                });
                accSelect.disabled = false;
            } else {
                accSelect.innerHTML = '<option value="">-- No available units for this pet size --</option>';
                accSelect.disabled = true;
            }
            validateForm();
        }

        function validateForm() {
            const ownerId = document.getElementById('ownerSelect').value;
            const checkIn = document.getElementById('checkIn').value;
            const checkOut = document.getElementById('checkOut').value;
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const selectedIn = new Date(checkIn);
            const selectedOut = new Date(checkOut);

            const isDateValid = checkIn && selectedIn >= today && checkOut && selectedOut > selectedIn;
            
            // Validate all dropdowns and dates
            if (ownerId && petSelect.value && accSelect.value && isDateValid) {
                submitBtn.disabled = false;
            } else {
                submitBtn.disabled = true;
            }
        }
    </script>
</body>
</html>