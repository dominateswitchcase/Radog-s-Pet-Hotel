<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// ════════════════════════════════════════════════════════════════
// SESSION GUARD
// ════════════════════════════════════════════════════════════════
requireLogin();
$role = $_SESSION['role']; // 'Admin' or 'Staff'

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

        try {
            // STRICT ALIGNMENT: Columns must exactly match the Oracle BOOKING Table DDL
            $update_stmt = $pdo->prepare(
                "UPDATE BOOKING SET 
                    BOOKING_STATUS = :status
                 WHERE BOOKING_ID = :id"
            );
            
            $update_stmt->execute([
                'status' => $status,
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

$role = $_SESSION['role'] ?? 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Verification — Radog's Kennel</title>
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
        body { font-family: 'DM Sans', sans-serif; background: var(--beige); color: var(--black); min-height: 100vh; display: flex; }
        h1, h2, h3, h4, h5 { font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.05em; }

        /* ── SIDEBAR ── */
        .sidebar {
            width: 272px; flex-shrink: 0;
            background-color: var(--black);
            background-image: repeating-linear-gradient(
                -55deg, transparent, transparent 18px,
                rgba(250,129,18,0.04) 18px, rgba(250,129,18,0.04) 19px
            );
            display: flex; flex-direction: column;
            padding: 28px 20px;
            position: sticky; top: 0; height: 100vh; overflow-y: auto;
        }

        .sidebar-brand {
            display: flex; align-items: center; gap: 14px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 20px;
            animation: fadeUp 0.6s cubic-bezier(0.16,1,0.3,1) both;
        }
        .sidebar-logo img {
            width: 52px; height: 52px; object-fit: contain;
            filter: drop-shadow(0 0 12px rgba(250,129,18,0.5));
        }
        .sidebar-wordmark-top {
            font-family: 'Bebas Neue', sans-serif; font-size: 1.5rem;
            color: var(--orange);
            text-shadow: 0 0 18px rgba(250,129,18,0.45);
            line-height: 1;
        }
        .sidebar-wordmark-sub {
            font-size: 0.68rem; letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--gold); opacity: 0.8; margin-top: 3px;
        }

        .sidebar-user {
            display: flex; align-items: center; gap: 12px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: 12px; padding: 12px 14px;
            margin-bottom: 28px;
            animation: fadeUp 0.65s cubic-bezier(0.16,1,0.3,1) 0.05s both;
        }
        .sidebar-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--orange); display: flex; align-items: center; justify-content: center;
            font-family: 'Bebas Neue', sans-serif; font-size: 1rem; color: var(--white);
            flex-shrink: 0;
        }
        .sidebar-user-name { font-size: 0.88rem; font-weight: 600; color: var(--white); }
        .sidebar-user-role { font-size: 0.72rem; color: var(--orange); letter-spacing: 0.06em; text-transform: uppercase; }

        .nav-section-label {
            font-size: 0.68rem; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase;
            color: rgba(245,231,198,0.4); padding: 0 4px; margin-bottom: 8px;
        }
        .nav-list {
            display: flex; flex-direction: column; gap: 4px; flex-grow: 1;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }
        .nav-link {
            display: flex; align-items: center; gap: 10px; padding: 11px 14px;
            border-radius: 12px; color: rgba(245,231,198,0.7); font-size: 0.92rem;
            text-decoration: none; transition: background 0.18s, color 0.18s;
        }
        .nav-link svg { width: 17px; height: 17px; opacity: 0.8; flex-shrink: 0; }
        .nav-link:hover { background: rgba(250,129,18,0.1); color: var(--white); }
        .nav-link.active { background: var(--orange); color: var(--white); font-weight: 600; }
        .nav-link.active svg { opacity: 1; }

        .sidebar-footer { margin-top: auto; padding-top: 20px; }
        .logout-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 16px; border-radius: 12px;
            background: transparent; border: 1.5px solid rgba(245,231,198,0.15);
            color: rgba(245,231,198,0.7); font-size: 0.9rem; text-decoration: none;
            transition: background 0.18s, color 0.18s, border-color 0.18s;
        }
        .logout-btn:hover { background: rgba(250,129,18,0.12); border-color: var(--orange); color: var(--white); }
        .logout-btn svg { width: 16px; height: 16px; }

        /* ── MAIN CONTENT ── */
        .main-content {
            flex-grow: 1; padding: 40px 44px; overflow-y: auto;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        /* Back link */
        .back-link {
            display: inline-flex; align-items: center; gap: 7px;
            color: rgba(34,34,34,0.5); font-size: 0.88rem; text-decoration: none;
            margin-bottom: 24px; transition: color 0.18s;
        }
        .back-link:hover { color: var(--orange); }
        .back-link svg { width: 15px; height: 15px; }

        /* Page header */
        .page-eyebrow {
            font-size: 0.75rem; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--orange); display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
        }
        .page-eyebrow::before {
            content: ''; display: block; width: 20px; height: 2px;
            background: var(--orange); border-radius: 99px;
        }
        .page-title { font-size: 2.4rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); margin-bottom: 32px; }

        /* Booking ID chip */
        .booking-chip {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(250,129,18,0.08); border: 1px solid rgba(250,129,18,0.2);
            border-radius: 99px; padding: 5px 14px;
            font-family: 'Bebas Neue', sans-serif; font-size: 1rem;
            color: var(--orange); letter-spacing: 0.1em;
            margin-bottom: 28px;
        }
        .booking-chip svg { width: 14px; height: 14px; }

        /* ── ALERT ── */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 14px 18px; border-radius: 12px; font-size: 0.9rem; margin-bottom: 28px;
        }
        .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

        /* ── LAYOUT GRID ── */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }

        /* ── PANELS ── */
        .panel {
            background: var(--white); border: var(--border-soft); border-radius: 20px;
            padding: 28px; box-shadow: var(--shadow-card); margin-bottom: 0;
        }
        .panel-header {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 24px; padding-bottom: 16px;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }
        .panel-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: rgba(250,129,18,0.1); display: flex; align-items: center; justify-content: center;
        }
        .panel-icon svg { width: 18px; height: 18px; color: var(--orange); }
        .panel-heading { font-family: 'Bebas Neue', sans-serif; font-size: 1.3rem; color: var(--black); letter-spacing: 0.05em; }

        /* Booking info rows */
        .info-row { margin-bottom: 20px; }
        .info-row:last-child { margin-bottom: 0; }
        .info-label {
            font-size: 0.72rem; font-weight: 600; letter-spacing: 0.14em; text-transform: uppercase;
            color: rgba(34,34,34,0.4); margin-bottom: 5px;
        }
        .info-value { font-size: 1rem; color: var(--black); font-weight: 500; }

        /* ── FORM FIELDS ── */
        .field-label {
            display: block; font-size: 0.75rem; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase; color: #4a3f33; margin-bottom: 8px;
        }
        .field-group { margin-bottom: 20px; }

        input, select, textarea {
            width: 100%; padding: 13px 16px; border: 1.5px solid #e2d9ce;
            border-radius: 12px; background: var(--beige); color: var(--black);
            font-family: 'DM Sans', sans-serif; font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s; appearance: none;
        }
        select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23888' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }
        input:focus, select:focus, textarea:focus {
            border-color: var(--orange); box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            outline: none; background: var(--white);
        }
        input:disabled, select:disabled { opacity: 0.5; cursor: not-allowed; background: rgba(34,34,34,0.04); }
        textarea { resize: vertical; min-height: 90px; }

        /* ── BUTTONS ── */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px; border: none; border-radius: 12px;
            font-family: 'Bebas Neue', sans-serif; font-size: 0.95rem;
            letter-spacing: 0.12em; cursor: pointer; text-decoration: none;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
            width: 100%; justify-content: center;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 16px; height: 16px; }
        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }
        .btn-danger  { background: var(--orange); color: var(--white); }
        .btn-danger:hover  { background: var(--orange-dk); box-shadow: 0 6px 20px rgba(250,129,18,0.35); }
        .btn-ghost   { background: transparent; color: var(--black); border: 1.5px solid rgba(34,34,34,0.18); }
        .btn-ghost:hover   { background: rgba(34,34,34,0.04); border-color: var(--orange); }

        /* Status badges */
        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 99px; font-size: 0.78rem; font-weight: 600;
        }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; }
        .status-pending   { background: #fffbeb; color: #92400e; }
        .status-pending .status-dot   { background: #f59e0b; }
        .status-confirmed { background: #f0fdf4; color: #166534; }
        .status-confirmed .status-dot { background: #22c55e; }
        .status-cancelled { background: #fef2f2; color: #991b1b; }
        .status-cancelled .status-dot { background: #ef4444; }
        .status-completed { background: #eff6ff; color: #1e40af; }
        .status-completed .status-dot { background: #3b82f6; }

        /* ── ANIMATIONS ── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .content-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
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
        <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>
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

        <a href="encode_reservation.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/></svg>
            Schedule
        </a>

        <a href="calendar.php" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Calendar
        </a>

        <a href="owner.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Owners
        </a>

        <a href="pets.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5.172C10 3.782 8.423 2.679 6.5 3c-2.823.47-4.113 6.006-4 7 .08.703 1.725 1.722 3.656 1 1.261-.472 1.96-1.45 2.344-2.5"/><path d="M14.267 5.172c0-1.39 1.577-2.493 3.5-2.172 2.823.47 4.113 6.006 4 7-.08.703-1.725 1.722-3.656 1-1.261-.472-1.855-1.45-2.239-2.5"/><path d="M8 14v.5"/><path d="M16 14v.5"/><path d="M11.25 16.25h1.5L12 17l-.75-.75z"/><path d="M4.42 11.247A13.152 13.152 0 0 0 4 14.556C4 18.728 7.582 21 12 21s8-2.272 8-6.444c0-1.061-.162-2.2-.493-3.309m-9.243-6.082A8.801 8.801 0 0 1 12 5c.78 0 1.5.108 2.161.306"/></svg>
            Pets
        </a>

        <!-- <a href="checkout.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            Checkout / Payments
        </a> -->

        <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>
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

<!-- ══════════════════════════════════════════════════════════
     MAIN CONTENT
════════════════════════════════════════════════════════════ -->
<main class="main-content">

    <a href="calendar-unified.php" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Back to Calendar
    </a>

    <div class="page-eyebrow">Reservations</div>
    <h1 class="page-title">Manage Booking</h1>
    <p class="page-subtitle">Review and update the status for this reservation.</p>

    <div class="booking-chip">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        BK-<?php echo str_pad($booking['BOOKING_ID'], 3, '0', STR_PAD_LEFT); ?>
    </div>

    <?php if (!empty($message)): ?>
    <div class="alert alert-success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <form action="booking_verification.php?booking_id=<?php echo $booking_id; ?>" method="POST">
        <input type="hidden" name="booking_id" value="<?php echo $booking_id; ?>">

        <div class="content-grid">

            <!-- LEFT: Booking Information -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <span class="panel-heading">Booking Information</span>
                </div>

                <div class="info-row">
                    <div class="info-label">Owner</div>
                    <div class="info-value"><?php echo htmlspecialchars($booking['OWNER_FIRST'] . ' ' . $booking['OWNER_LAST']); ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">Pet</div>
                    <div class="info-value"><?php echo htmlspecialchars($booking['PET_NAME']); ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">Check-In</div>
                    <div class="info-value"><?php echo htmlspecialchars($booking['CHECKIN_LABEL']); ?></div>
                </div>

                <div class="info-row">
                    <div class="info-label">Check-Out</div>
                    <div class="info-value"><?php echo htmlspecialchars($booking['CHECKOUT_LABEL']); ?></div>
                </div>
            </div>

            <!-- RIGHT: Booking Status -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <span class="panel-heading">Booking Status</span>
                </div>

                <div class="field-group">
                    <label class="field-label" for="statusSelect">Current Status</label>
                    <select name="status" id="statusSelect">
                        <option value="Pending"   <?php echo $booking['BOOKING_STATUS'] === 'Pending'   ? 'selected' : ''; ?>>Pending</option>
                        <option value="Confirmed" <?php echo $booking['BOOKING_STATUS'] === 'Confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                        <option value="Cancelled" <?php echo $booking['BOOKING_STATUS'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="Completed" <?php echo $booking['BOOKING_STATUS'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Changes
                </button>
            </div>

        </div><!-- /.content-grid -->
    </form>

</main>

<script src="../assets/js/validation.js"></script>
</body>
</html>
