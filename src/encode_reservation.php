<!-- being use by schedule page -->
<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// Session guard
requireLogin();
$role = $_SESSION['role'];

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_VALIDATE_INT);
    $pet_id = filter_input(INPUT_POST, 'pet_id',     FILTER_VALIDATE_INT);
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
                    // Allow Admin override if explicitly requested
                    $allow_override = isAdmin() && !empty($_POST['override']);
                    if (!$allow_override) {
                        $message = 'The selected accommodation unit is no longer available.';
                    }
                }

                if (empty($message)) {
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --orange:      #FA8112;
            --orange-dk:   #d96a08;
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

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--beige);
            color: var(--black);
            min-height: 100vh;
            display: flex;
        }

        h1, h2, h3, h4, h5 {
            font-family: 'Bebas Neue', sans-serif;
            letter-spacing: 0.05em;
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: 272px;
            flex-shrink: 0;
            background-color: var(--black);
            background-image: repeating-linear-gradient(
                -55deg, transparent, transparent 18px,
                rgba(250,129,18,0.04) 18px, rgba(250,129,18,0.04) 19px
            );
            display: flex;
            flex-direction: column;
            padding: 28px 20px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 20px;
            animation: fadeUp 0.6s cubic-bezier(0.16,1,0.3,1) both;
        }

        .sidebar-logo img {
            width: 52px;
            height: 52px;
            object-fit: contain;
            filter: drop-shadow(0 0 12px rgba(250,129,18,0.5));
        }

        .sidebar-wordmark-top {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.5rem;
            color: var(--orange);
            text-shadow: 0 0 20px rgba(250,129,18,0.4);
            line-height: 1;
        }

        .sidebar-wordmark-sub {
            font-size: 0.68rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold);
            opacity: 0.8;
            margin-top: 3px;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 28px;
            animation: fadeUp 0.65s cubic-bezier(0.16,1,0.3,1) 0.05s both;
        }

        .sidebar-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--orange);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1rem;
            color: var(--white);
            flex-shrink: 0;
        }

        .sidebar-user-name {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--white);
            line-height: 1.2;
        }

        .sidebar-user-role {
            font-size: 0.72rem;
            color: var(--orange);
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .nav-section-label {
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(245,231,198,0.4);
            padding: 0 4px;
            margin-bottom: 8px;
        }

        .nav-list {
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-grow: 1;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: 12px;
            color: rgba(245,231,198,0.7);
            font-size: 0.92rem;
            text-decoration: none;
            transition: background 0.18s, color 0.18s;
        }

        .nav-link svg {
            width: 17px;
            height: 17px;
            opacity: 0.8;
            flex-shrink: 0;
        }

        .nav-link:hover {
            background: rgba(250,129,18,0.1);
            color: var(--white);
        }

        .nav-link.active {
            background: var(--orange);
            color: var(--white);
            font-weight: 600;
        }

        .nav-link.active svg { opacity: 1; }

        .sidebar-footer {
            margin-top: auto;
            padding-top: 20px;
            border-top: 1px solid rgba(250,129,18,0.1);
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: 12px;
            background: transparent;
            border: 1.5px solid rgba(245,231,198,0.15);
            color: rgba(245,231,198,0.7);
            font-size: 0.9rem;
            text-decoration: none;
            transition: background 0.18s, color 0.18s, border-color 0.18s;
            width: 100%;
        }

        .logout-btn:hover {
            background: rgba(250,129,18,0.12);
            border-color: var(--orange);
            color: var(--white);
        }

        .logout-btn svg { width: 16px; height: 16px; }

        /* ── MAIN CONTENT ── */
        .main-content {
            flex-grow: 1;
            padding: 40px 44px;
            overflow-y: auto;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        .page-eyebrow {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--orange);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .page-eyebrow::before {
            content: '';
            display: block;
            width: 20px;
            height: 2px;
            background: var(--orange);
            border-radius: 99px;
        }

        .page-title {
            font-size: 2.4rem;
            color: var(--black);
            line-height: 1;
            margin-bottom: 6px;
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: rgba(34,34,34,0.55);
            margin-bottom: 32px;
        }

        /* ── ALERTS ── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-bottom: 24px;
        }

        .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ── PANELS ── */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: 20px;
            padding: 28px;
            box-shadow: var(--shadow-card);
        }

        .panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }

        .panel-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(250,129,18,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .panel-icon svg { width: 18px; height: 18px; color: var(--orange); }

        .panel-heading {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.3rem;
            color: var(--black);
            letter-spacing: 0.05em;
        }

        /* ── FORM ── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .field-group {
            margin-bottom: 20px;
        }

        .field-group:last-child { margin-bottom: 0; }

        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #4a3f33;
            margin-bottom: 8px;
        }

        input, select, textarea {
            width: 100%;
            padding: 13px 16px;
            border: 1.5px solid #e2d9ce;
            border-radius: 12px;
            background: var(--beige);
            color: var(--black);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            outline: none;
            background: var(--white);
        }

        input:disabled, select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: rgba(34,34,34,0.04);
        }

        textarea { resize: vertical; min-height: 90px; }

        /* ── BUTTONS ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            border: none;
            border-radius: 12px;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem;
            letter-spacing: 0.12em;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 16px; height: 16px; }

        .btn-primary {
            background: var(--black);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--orange);
            box-shadow: 0 6px 20px rgba(250,129,18,0.3);
        }

        .btn-primary:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none;
        }

        /* ── OVERRIDE TOGGLE ── */
        .override-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding: 12px 16px;
            background: rgba(250,129,18,0.05);
            border: 1px dashed rgba(250,129,18,0.3);
            border-radius: 10px;
        }

        .override-wrap input[type="checkbox"] {
            width: 18px;
            height: 18px;
            padding: 0;
            border-radius: 4px;
            cursor: pointer;
            accent-color: var(--orange);
        }

        .override-label {
            font-size: 0.82rem;
            color: rgba(34,34,34,0.65);
            font-weight: 500;
        }

        /* ── ANIMATION ── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- ══════════════ SIDEBAR ══════════════ -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-logo">
                <img src="../img/radog_logocutie.png" alt="Radog's Kennel">
            </div>
            <div>
                <div class="sidebar-wordmark-top">Radog's Kennel</div>
                <div class="sidebar-wordmark-sub">Pet Hotel Management</div>
            </div>
        </div>

        <div class="sidebar-user">
            <div class="sidebar-avatar">
                <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
            </div>
            <div>
                <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
                <div class="sidebar-user-role"><?php echo htmlspecialchars($role); ?></div>
            </div>
        </div>

        <div class="nav-section-label">Navigation</div>

        <nav class="nav-list">
            <?php if (isAdmin()): ?>
                <a href="admin_dashboard.php" class="nav-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Dashboard
                </a>
            <?php else: ?>
                <a href="staff_dashboard.php" class="nav-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Dashboard
                </a>
            <?php endif; ?>

            <a href="encode_reservation.php" class="nav-link active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
                Schedule
            </a>

            <a href="calendar-unified.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Calendar
            </a>

            <a href="owner.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Owners
            </a>

            <a href="pets.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5.172C10 3.782 8.423 2.679 6.5 3c-2.823.47-4.113 6.006-4 7 .08.703 1.725 1.722 3.656 1 1.261-.472 1.96-1.45 2.344-2.5"/><path d="M14.267 5.172c0-1.39 1.577-2.493 3.5-2.172 2.823.47 4.113 6.006 4 7-.08.703-1.725 1.722-3.656 1-1.261-.472-1.96-1.45-2.344-2.5"/><path d="M8 14v.5"/><path d="M16 14v.5"/><path d="M11.25 16.25h1.5L12 17l-.75-.75z"/><path d="M4.42 11.247A13.152 13.152 0 0 0 4 14.556C4 18.728 7.582 21 12 21s8-2.272 8-6.444c0-1.061-.162-2.2-.493-3.309m-9.243-6.082A8.801 8.801 0 0 1 12 5c.78 0 1.5.108 2.161.306"/></svg>
                Pets
            </a>

            <!-- <a href="checkout.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                Checkout / Payments
            </a> -->

            <?php if (isAdmin()): ?>
                <a href="user_management.php" class="nav-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    User Management
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="../logout.php" class="logout-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- ══════════════ MAIN CONTENT ══════════════ -->
    <main class="main-content">

        <div class="page-eyebrow">Bookings</div>
        <h1 class="page-title">New Reservation</h1>
        <p class="page-subtitle">Encode a booking request received via Messenger</p>

        <?php if (!empty($message)): ?>
            <div class="alert <?php echo strpos($message, 'successfully') !== false ? 'alert-success' : 'alert-error'; ?>">
                <?php if (strpos($message, 'successfully') !== false): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php endif; ?>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <form action="encode_reservation.php" method="POST" id="reservationForm">
            <div class="form-grid">

                <!-- Left Panel: Customer & Accommodation -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        </div>
                        <span class="panel-heading">Customer &amp; Accommodation</span>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="ownerSelect">Select Owner</label>
                        <select name="owner_id" id="ownerSelect" required onchange="filterPets()">
                            <option value="">— Choose Owner —</option>
                            <?php foreach ($owners as $owner): ?>
                                <option value="<?php echo $owner['OWNER_ID']; ?>">
                                    <?php echo htmlspecialchars($owner['LAST_NAME'] . ', ' . $owner['FIRST_NAME'] . ' (' . $owner['CONTACT_NUMBER'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="petSelect">Select Pet</label>
                        <select name="pet_id" id="petSelect" required disabled onchange="filterAccommodations()">
                            <option value="">— Select an owner first —</option>
                        </select>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="accommodationSelect">Available Unit <span style="opacity:0.5;font-size:0.7rem;">(Filtered by Size)</span></label>
                        <select name="accommodation_id" id="accommodationSelect" required disabled onchange="validateForm()">
                            <option value="">— Select a pet first —</option>
                        </select>
                    </div>
                </div>

                <!-- Right Panel: Booking Details -->
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                        <span class="panel-heading">Booking Details</span>
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="checkIn">Check-In Date</label>
                        <input type="date" id="checkIn" name="check_in"
                               min="<?php echo date('Y-m-d'); ?>" required onchange="validateForm()">
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="checkOut">Check-Out Date</label>
                        <input type="date" id="checkOut" name="check_out" required onchange="validateForm()">
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="instructions">Special Instructions <span style="opacity:0.5;">(Optional)</span></label>
                        <textarea name="instructions" id="instructions" placeholder="Allergies, behavior, or feeding notes..."></textarea>
                    </div>

                    <button type="submit" id="submitBtn" class="btn btn-primary" style="width:100%; justify-content:center;" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        Confirm Reservation
                    </button>

                    <?php if (isAdmin()): ?>
                        <div class="override-wrap">
                            <input type="checkbox" id="overrideSwitch" name="override" value="1">
                            <label class="override-label" for="overrideSwitch">Force Book — Admin override availability</label>
                        </div>
                    <?php endif; ?>
                </div>

            </div><!-- /.form-grid -->
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
            petSelect.innerHTML = '<option value="">— Select a pet —</option>';

            // Reset Accommodation Dropdown
            accSelect.innerHTML = '<option value="">— Select a pet first —</option>';
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
                petSelect.innerHTML = '<option value="">— No pets found for this owner —</option>';
                petSelect.disabled = true;
            }
            validateForm();
        }

        // JELLYACE: New Tier/Weight Constraint Filtering
        function filterAccommodations() {
            accSelect.innerHTML = '<option value="">— Select an Accommodation Unit —</option>';
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
                    option.textContent = `${acc.UNIT_NAME} — ${acc.TIER_NAME} Tier`;
                    accSelect.appendChild(option);
                });
                accSelect.disabled = false;
            } else {
                accSelect.innerHTML = '<option value="">— No available units for this pet size —</option>';
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
