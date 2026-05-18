<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// ════════════════════════════════════════════════════════════════
// SESSION GUARD
// ════════════════════════════════════════════════════════════════
requireLogin();
$role = $_SESSION['role'];

// ════════════════════════════════════════════════════════════════
// BUSINESS LOGIC: FETCH BOOKING & CALCULATE CHARGES
// ════════════════════════════════════════════════════════════════
$booking_id = filter_input(INPUT_GET, 'booking_id', FILTER_VALIDATE_INT);
if (!$booking_id) {
    header('Location: calendar.php');
    exit;
}

$query = "
    SELECT 
        b.BOOKING_ID, b.CHECK_IN_DATE, b.CHECK_OUT_DATE, b.BOOKING_STATUS,
        b.SPECIAL_INSTRUCTIONS,
        o.OWNER_ID, o.FIRST_NAME, o.LAST_NAME, o.CONTACT_NUMBER,
        p.PET_ID, p.PET_NAME,
        a.ACCOMMODATION_ID, a.UNIT_NAME, a.DAILY_COST,
        COUNT(s.SERVICE_ID) as SERVICE_COUNT
    FROM BOOKING b
    JOIN OWNER o ON b.OWNER_ID = o.OWNER_ID
    JOIN PET p ON b.PET_ID = p.PET_ID
    JOIN ACCOMMODATION a ON b.ACCOMMODATION_ID = a.ACCOMMODATION_ID
    LEFT JOIN BOOKING_SERVICE bs ON b.BOOKING_ID = bs.BOOKING_ID
    LEFT JOIN SERVICE s ON bs.SERVICE_ID = s.SERVICE_ID
    WHERE b.BOOKING_ID = :booking_id
    GROUP BY b.BOOKING_ID, b.CHECK_IN_DATE, b.CHECK_OUT_DATE, b.BOOKING_STATUS,
             b.SPECIAL_INSTRUCTIONS, o.OWNER_ID, o.FIRST_NAME, o.LAST_NAME,
             o.CONTACT_NUMBER, p.PET_ID, p.PET_NAME, a.ACCOMMODATION_ID,
             a.UNIT_NAME, a.DAILY_COST
";
$stmt = $pdo->prepare($query);
$stmt->execute([':booking_id' => $booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header('Location: calendar.php');
    exit;
}

$check_in  = new DateTime($booking['CHECK_IN_DATE']);
$check_out = new DateTime($booking['CHECK_OUT_DATE']);
$days      = $check_in->diff($check_out)->days;
$accommodation_cost = $days * $booking['DAILY_COST'];

$services_query = "
    SELECT s.SERVICE_ID, s.SERVICE_NAME, s.PRICE
    FROM BOOKING_SERVICE bs
    JOIN SERVICE s ON bs.SERVICE_ID = s.SERVICE_ID
    WHERE bs.BOOKING_ID = :booking_id
";
$services_stmt = $pdo->prepare($services_query);
$services_stmt->execute([':booking_id' => $booking_id]);
$services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);

$services_cost = array_sum(array_column($services, 'PRICE'));
$subtotal = $accommodation_cost + $services_cost;
$tax      = $subtotal * 0.08;
$total    = $subtotal + $tax;

$payment_message = '';
$payment_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;

    if ($action === 'process_payment') {
        $payment_method = filter_var($_POST['payment_method'] ?? '', FILTER_SANITIZE_STRING);
        if (in_array($payment_method, ['cash', 'check', 'card', 'online'], true)) {
            $upd = $pdo->prepare("UPDATE BOOKING SET BOOKING_STATUS = 'Completed' WHERE BOOKING_ID = :id");
            $upd->execute([':id' => $booking_id]);
            $payment_message = 'Payment received via ' . ucfirst($payment_method) . '. Booking marked as completed.';
            $payment_success = true;
            $booking['BOOKING_STATUS'] = 'Completed';
        }
    }

    if ($action === 'apply_discount' && isAdmin()) {
        $pct = filter_var($_POST['discount_percent'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($pct > 0 && $pct <= 50) {
            $discount_amount = $subtotal * ($pct / 100);
            $payment_message = 'Discount of ' . $pct . '% applied (−₱' . number_format($discount_amount, 2) . ')';
            $payment_success = true;
        }
    }

    if ($action === 'process_refund' && isAdmin()) {
        $reason = filter_var($_POST['refund_reason'] ?? '', FILTER_SANITIZE_STRING);
        if (!empty($reason)) {
            $upd = $pdo->prepare("UPDATE BOOKING SET BOOKING_STATUS = 'Cancelled' WHERE BOOKING_ID = :id");
            $upd->execute([':id' => $booking_id]);
            $payment_message = 'Refund issued. Reason: ' . htmlspecialchars($reason);
            $payment_success = false;
            $booking['BOOKING_STATUS'] = 'Cancelled';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — Radog's Kennel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">

    <style>
        /* ══════════════════════════════════════════════════════
           DESIGN TOKENS — identical to index.php & calendar.php
        ══════════════════════════════════════════════════════ */
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

            --shadow-card: 0 24px 70px rgba(15, 23, 42, 0.08);
            --border-soft: 1px solid rgba(34, 34, 34, 0.08);
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

        /* ══════════════════════════════════════════════════════
           SIDEBAR
        ══════════════════════════════════════════════════════ */
        .sidebar {
            width: 272px;
            flex-shrink: 0;
            background-color: var(--black);
            background-image: repeating-linear-gradient(
                -55deg,
                transparent, transparent 18px,
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

        /* Brand */
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 28px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) both;
        }

        .sidebar-logo {
            width: 52px; height: 52px; flex-shrink: 0;
            filter: drop-shadow(0 0 12px rgba(250,129,18,0.5));
        }

        .sidebar-logo img { width: 100%; height: 100%; object-fit: contain; }

        .sidebar-wordmark-top {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.5rem; color: var(--orange);
            letter-spacing: 0.04em; line-height: 1;
            text-shadow: 0 0 20px rgba(250,129,18,0.35);
        }

        .sidebar-wordmark-sub {
            font-size: 0.68rem; font-weight: 500;
            letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--gold); opacity: 0.8;
        }

        /* User badge */
        .sidebar-user {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 14px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: var(--radius-btn);
            margin-bottom: 28px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.05s both;
        }

        .sidebar-avatar {
            width: 34px; height: 34px; border-radius: 50%;
            background: var(--orange);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1rem; color: var(--white); flex-shrink: 0;
        }

        .sidebar-user-name {
            font-size: 0.88rem; font-weight: 600; color: var(--white);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .sidebar-user-role {
            font-size: 0.72rem; color: var(--orange);
            letter-spacing: 0.06em; text-transform: uppercase;
        }

        /* Nav */
        .nav-section-label {
            font-size: 0.68rem; font-weight: 600;
            letter-spacing: 0.2em; text-transform: uppercase;
            color: rgba(245,231,198,0.4); padding: 0 4px; margin-bottom: 8px;
        }

        .nav-list {
            list-style: none; display: flex; flex-direction: column; gap: 3px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        .nav-link {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 14px; border-radius: var(--radius-btn);
            color: rgba(245,231,198,0.7);
            font-size: 0.92rem; text-decoration: none;
            transition: background 0.18s, color 0.18s;
        }

        .nav-link svg { width: 17px; height: 17px; flex-shrink: 0; opacity: 0.8; }

        .nav-link:hover { background: rgba(250,129,18,0.1); color: var(--white); }
        .nav-link:hover svg { opacity: 1; }
        .nav-link.active { background: var(--orange); color: var(--white); font-weight: 600; }
        .nav-link.active svg { opacity: 1; }

        .sidebar-spacer { flex-grow: 1; }

        .sidebar-divider {
            height: 1px; background: rgba(250,129,18,0.12); margin: 20px 0;
        }

        .logout-btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 16px; border-radius: var(--radius-btn);
            background: transparent;
            border: 1.5px solid rgba(245,231,198,0.15);
            color: rgba(245,231,198,0.7);
            font-family: 'DM Sans', sans-serif; font-size: 0.9rem;
            cursor: pointer; text-decoration: none;
            transition: background 0.18s, color 0.18s, border-color 0.18s;
        }

        .logout-btn svg { width: 16px; height: 16px; }
        .logout-btn:hover { background: rgba(250,129,18,0.12); border-color: var(--orange); color: var(--white); }

        /* ══════════════════════════════════════════════════════
           MAIN CONTENT
        ══════════════════════════════════════════════════════ */
        .main-content {
            flex-grow: 1;
            padding: 40px 44px;
            overflow-y: auto;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        /* Page header */
        .page-header { margin-bottom: 32px; }

        .page-eyebrow {
            font-size: 0.75rem; font-weight: 600;
            letter-spacing: 0.18em; text-transform: uppercase;
            color: var(--orange);
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 10px;
        }

        .page-eyebrow::before {
            content: ''; display: block;
            width: 20px; height: 2px;
            background: var(--orange); border-radius: 99px;
        }

        .page-title { font-size: 2.4rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            margin-top: 16px; padding: 14px 18px;
            border-radius: var(--radius-input);
            font-size: 0.9rem;
        }

        .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }

        .alert-success {
            background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534;
        }

        .alert-warning {
            background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412;
        }

        /* Grid */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }

        /* Panel / Card */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-card);
            margin-bottom: 20px;
        }

        .panel:last-child { margin-bottom: 0; }

        /* Panel header */
        .panel-header {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px; padding-bottom: 16px;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }

        .panel-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: rgba(250,129,18,0.1);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }

        .panel-icon svg { width: 18px; height: 18px; color: var(--orange); }

        .panel-heading {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.3rem; color: var(--black); letter-spacing: 0.05em;
        }

        /* Detail rows */
        .detail-row { margin-bottom: 18px; }
        .detail-row:last-child { margin-bottom: 0; }

        .detail-label {
            font-size: 0.78rem; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: rgba(34,34,34,0.45); margin-bottom: 4px;
        }

        .detail-value { font-size: 1rem; font-weight: 600; color: var(--black); }

        /* Status badge */
        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 14px; border-radius: 99px;
            font-size: 0.8rem; font-weight: 600; letter-spacing: 0.05em;
        }

        .status-dot { width: 7px; height: 7px; border-radius: 50%; }

        .status-confirmed  { background: rgba(250,129,18,0.12); color: var(--orange); }
        .status-confirmed .status-dot  { background: var(--orange); box-shadow: 0 0 6px var(--orange); }

        .status-completed  { background: rgba(22,163,74,0.1); color: #166534; }
        .status-completed .status-dot  { background: #22c55e; }

        .status-cancelled  { background: rgba(239,68,68,0.1); color: #991b1b; }
        .status-cancelled .status-dot  { background: #ef4444; }

        /* Invoice rows */
        .invoice-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(34,34,34,0.06);
            font-size: 0.93rem;
        }

        .invoice-row:last-child { border-bottom: none; }

        .invoice-label { color: rgba(34,34,34,0.65); }
        .invoice-amount { font-weight: 500; color: var(--black); }

        .invoice-total {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 0 4px;
            border-top: 2px solid var(--orange);
            margin-top: 8px;
        }

        .invoice-total-label {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.1rem; letter-spacing: 0.08em; color: var(--black);
        }

        .invoice-total-amount {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.5rem; color: var(--orange); letter-spacing: 0.04em;
        }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 22px; border: none; border-radius: var(--radius-btn);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem; letter-spacing: 0.12em;
            cursor: pointer;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
            text-decoration: none; width: 100%; justify-content: center;
        }

        .btn svg { width: 16px; height: 16px; }
        .btn:hover { transform: translateY(-1px); }

        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }

        .btn-danger { background: var(--orange); color: var(--white); }
        .btn-danger:hover { background: var(--orange-dk); box-shadow: 0 6px 20px rgba(250,129,18,0.35); }

        .btn-ghost {
            background: transparent; color: var(--black);
            border: 1.5px solid rgba(34,34,34,0.18);
        }

        .btn-ghost:hover { background: rgba(34,34,34,0.04); border-color: var(--orange); }

        /* Form fields */
        .field-wrap { margin-bottom: 18px; }
        .field-wrap:last-child { margin-bottom: 0; }

        .field-label {
            display: block; font-size: 0.78rem; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: #4a3f33; margin-bottom: 7px;
        }

        .field-wrap select,
        .field-wrap input[type="number"],
        .field-wrap textarea {
            width: 100%; padding: 13px 16px;
            border: 1.5px solid #e2d9ce; border-radius: var(--radius-input);
            background: var(--white); color: var(--black);
            font-family: 'DM Sans', sans-serif; font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
        }

        .field-wrap select:focus,
        .field-wrap input[type="number"]:focus,
        .field-wrap textarea:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            outline: none;
        }

        .field-wrap textarea { resize: vertical; min-height: 80px; }

        /* Divider inside panel */
        .panel-inner-divider {
            height: 1px; background: rgba(34,34,34,0.07); margin: 20px 0;
        }

        /* Animations */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════════════════ -->
<aside class="sidebar">

    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <img src="../img/radog_logo.png" alt="Radog's Kennel">
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
    <ul class="nav-list">

        <?php if (isAdmin()): ?>
            <li><a href="admin_dashboard.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/></svg>
                Dashboard
            </a></li>
            <li><a href="encode_reservation.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Schedule
            </a></li>
            <li><a href="calendar.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10h18"/></svg>
                Calendar
            </a></li>
            <li><a href="owner.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Owners
            </a></li>
            <li><a href="pets.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14 10h.01M10 10h.01M9 16s1 1 3 1 3-1 3-1M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                Pets
            </a></li>
            <li><a href="checkout.php" class="nav-link active">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                Checkout / Payments
            </a></li>
            <li><a href="user_management.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                User Management
            </a></li>

        <?php elseif (isStaff()): ?>
            <li><a href="staff_dashboard.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/></svg>
                Dashboard
            </a></li>
            <li><a href="encode_reservation.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Schedule
            </a></li>
            <li><a href="calendar.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10h18"/></svg>
                Calendar
            </a></li>
            <li><a href="owner.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Owners
            </a></li>
            <li><a href="pets.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M14 10h.01M10 10h.01M9 16s1 1 3 1 3-1 3-1M21 12c0 4.97-4.03 9-9 9S3 16.97 3 12 7.03 3 12 3s9 4.03 9 9z"/></svg>
                Pets
            </a></li>
            <li><a href="checkout.php" class="nav-link active">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                Checkout / Payments
            </a></li>
        <?php endif; ?>

    </ul>

    <div class="sidebar-spacer"></div>
    <div class="sidebar-divider"></div>

    <a href="../logout.php" class="logout-btn">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        Logout
    </a>

</aside>


<main class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-eyebrow">Transactions</div>
        <h1 class="page-title">Checkout & Payments</h1>
        <p class="page-subtitle">Process payment and finalize booking #<?php echo htmlspecialchars($booking_id); ?></p>

        <?php if (!empty($payment_message)): ?>
            <div class="alert <?php echo $payment_success ? 'alert-success' : 'alert-warning'; ?>">
                <?php if ($payment_success): ?>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                <?php else: ?>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                <?php endif; ?>
                <?php echo htmlspecialchars($payment_message); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid-2">

        <!-- ── LEFT: Booking Details ── -->
        <div>
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-icon">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <span class="panel-heading">Booking Details</span>
                </div>

                <div class="detail-row">
                    <div class="detail-label">Pet Name</div>
                    <div class="detail-value"><?php echo htmlspecialchars($booking['PET_NAME']); ?></div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">Owner</div>
                    <div class="detail-value"><?php echo htmlspecialchars($booking['FIRST_NAME'] . ' ' . $booking['LAST_NAME']); ?></div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">Contact</div>
                    <div class="detail-value"><?php echo htmlspecialchars($booking['CONTACT_NUMBER']); ?></div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">Check-In → Check-Out</div>
                    <div class="detail-value">
                        <?php echo date('M d, Y', strtotime($booking['CHECK_IN_DATE'])); ?>
                        &nbsp;→&nbsp;
                        <?php echo date('M d, Y', strtotime($booking['CHECK_OUT_DATE'])); ?>
                    </div>
                </div>

                <div class="detail-row">
                    <div class="detail-label">Accommodation</div>
                    <div class="detail-value">
                        <?php echo htmlspecialchars($booking['UNIT_NAME']); ?>
                        <span style="font-weight:400; color:rgba(34,34,34,0.5); font-size:0.88rem;">(<?php echo $days; ?> night<?php echo $days !== 1 ? 's' : ''; ?>)</span>
                    </div>
                </div>

                <?php if (!empty($booking['SPECIAL_INSTRUCTIONS'])): ?>
                    <div class="detail-row">
                        <div class="detail-label">Special Instructions</div>
                        <div class="detail-value" style="font-weight:400; font-size:0.93rem; color:rgba(34,34,34,0.7);">
                            <?php echo htmlspecialchars($booking['SPECIAL_INSTRUCTIONS']); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="detail-row">
                    <div class="detail-label">Status</div>
                    <?php
                        $status_lower = strtolower($booking['BOOKING_STATUS']);
                    ?>
                    <span class="status-badge status-<?php echo $status_lower; ?>">
                        <span class="status-dot"></span>
                        <?php echo htmlspecialchars($booking['BOOKING_STATUS']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── RIGHT: Invoice + Payment Actions ── -->
        <div>

            <!-- Invoice -->
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-icon">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>
                        </svg>
                    </div>
                    <span class="panel-heading">Invoice</span>
                </div>

                <div class="invoice-row">
                    <span class="invoice-label">
                        Accommodation
                        <span style="font-size:0.8rem; opacity:0.7;">(<?php echo $days; ?> nights × ₱<?php echo number_format($booking['DAILY_COST'], 2); ?>)</span>
                    </span>
                    <span class="invoice-amount">₱<?php echo number_format($accommodation_cost, 2); ?></span>
                </div>

                <?php foreach ($services as $svc): ?>
                    <div class="invoice-row">
                        <span class="invoice-label"><?php echo htmlspecialchars($svc['SERVICE_NAME']); ?></span>
                        <span class="invoice-amount">₱<?php echo number_format($svc['PRICE'], 2); ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="invoice-row">
                    <span class="invoice-label">Subtotal</span>
                    <span class="invoice-amount">₱<?php echo number_format($subtotal, 2); ?></span>
                </div>

                <div class="invoice-row">
                    <span class="invoice-label">Tax (8%)</span>
                    <span class="invoice-amount">₱<?php echo number_format($tax, 2); ?></span>
                </div>

                <div class="invoice-total">
                    <span class="invoice-total-label">Total Due</span>
                    <span class="invoice-total-amount">₱<?php echo number_format($total, 2); ?></span>
                </div>
            </div>

            <!-- Process Payment (Both roles) -->
            <?php if ($booking['BOOKING_STATUS'] !== 'Completed' && $booking['BOOKING_STATUS'] !== 'Cancelled'): ?>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                            </svg>
                        </div>
                        <span class="panel-heading">Process Payment</span>
                    </div>

                    <form method="POST">
                        <div class="field-wrap">
                            <label class="field-label">Payment Method</label>
                            <select name="payment_method" required>
                                <option value="">Select method…</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="card">Credit / Debit Card</option>
                                <option value="online">Online Transfer</option>
                            </select>
                        </div>
                        <input type="hidden" name="action" value="process_payment">
                        <button type="submit" class="btn btn-primary">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Confirm Payment
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Admin-Only Actions -->
            <?php if (isAdmin()): ?>
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                        </div>
                        <span class="panel-heading">Admin Actions</span>
                    </div>

                    <?php if ($booking['BOOKING_STATUS'] !== 'Completed' && $booking['BOOKING_STATUS'] !== 'Cancelled'): ?>
                        <form method="POST">
                            <div class="field-wrap">
                                <label class="field-label">Apply Discount (%)</label>
                                <input type="number" name="discount_percent" min="0" max="50" step="0.1" placeholder="e.g. 10">
                            </div>
                            <input type="hidden" name="action" value="apply_discount">
                            <button type="submit" class="btn btn-ghost">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="width:16px;height:16px;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M17 17h.01M7 7L17 17M7 17L17 7"/>
                                </svg>
                                Apply Discount
                            </button>
                        </form>
                        <div class="panel-inner-divider"></div>
                    <?php endif; ?>

                    <?php if ($booking['BOOKING_STATUS'] !== 'Cancelled'): ?>
                        <form method="POST">
                            <div class="field-wrap">
                                <label class="field-label">Refund Reason</label>
                                <textarea name="refund_reason" placeholder="Enter reason for refund…"></textarea>
                            </div>
                            <input type="hidden" name="action" value="process_refund">
                            <button type="submit" class="btn btn-danger">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="width:16px;height:16px;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                </svg>
                                Process Refund
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>

</main>
</body>
</html>
