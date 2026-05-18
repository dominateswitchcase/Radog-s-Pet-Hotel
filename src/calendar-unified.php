<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// ════════════════════════════════════════════════════════════════
// SESSION GUARD
// ════════════════════════════════════════════════════════════════
requireLogin();
$role = $_SESSION['role']; // 'Admin' or 'Staff'

// ════════════════════════════════════════════════════════════════
// FETCH CALENDAR DATA
// ════════════════════════════════════════════════════════════════
$query = "SELECT B.BOOKING_ID, P.PET_NAME, B.CHECK_IN_DATE, B.CHECK_OUT_DATE, B.BOOKING_STATUS 
          FROM BOOKING B 
          JOIN PET P ON B.PET_ID = P.PET_ID 
          WHERE B.BOOKING_STATUS = 'Confirmed'";
$stmt  = $pdo->query($query);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
if (isset($_GET['booking_added']) && $_GET['booking_added'] === '1') {
    $success_message = 'Booking was added successfully and is now visible on the calendar.';
}

$events = [];
foreach ($bookings as $row) {
    $visual_end = date('Y-m-d', strtotime($row['CHECK_OUT_DATE'] . ' +1 day'));
    $events[] = [
        'id'    => $row['BOOKING_ID'],
        'title' => htmlspecialchars($row['PET_NAME']),
        'start' => date('Y-m-d', strtotime($row['CHECK_IN_DATE'])),
        'end'   => $visual_end,
        'color' => '#FA8112',
        'url'   => 'booking_verification.php?booking_id=' . $row['BOOKING_ID'],
        'allDay' => true,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar — Radog's Kennel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">

    <!-- FullCalendar -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

    <style>
        /* ══════════════════════════════════════════════════════
           DESIGN TOKENS — mirrors index.php exactly
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

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

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
           SIDEBAR — dark panel, mirrors index.php left panel feel
        ══════════════════════════════════════════════════════ */
        .sidebar {
            width: 272px;
            flex-shrink: 0;
            background: var(--black);
            display: flex;
            flex-direction: column;
            padding: 28px 20px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;

            /* diagonal stripe — same as index.php left panel */
            background-image:
                repeating-linear-gradient(
                    -55deg,
                    transparent,
                    transparent 18px,
                    rgba(250,129,18,0.04) 18px,
                    rgba(250,129,18,0.04) 19px
                );
            background-color: var(--black);
        }

        /* ── Brand block ── */
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
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            filter: drop-shadow(0 0 12px rgba(250,129,18,0.5));
        }

        .sidebar-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .sidebar-wordmark {
            display: flex;
            flex-direction: column;
            gap: 1px;
        }

        .sidebar-wordmark-top {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.5rem;
            color: var(--orange);
            letter-spacing: 0.04em;
            line-height: 1;
            text-shadow: 0 0 20px rgba(250,129,18,0.35);
        }

        .sidebar-wordmark-sub {
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold);
            opacity: 0.8;
        }

        /* ── User badge ── */
        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: var(--radius-btn);
            margin-bottom: 28px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.05s both;
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

        .sidebar-user-info {
            overflow: hidden;
        }

        .sidebar-user-name {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--white);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sidebar-user-role {
            font-size: 0.72rem;
            color: var(--orange);
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        /* ── Nav section label ── */
        .nav-section-label {
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(245,231,198,0.4);
            padding: 0 4px;
            margin-bottom: 8px;
        }

        /* ── Nav links ── */
        .nav-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 3px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: var(--radius-btn);
            color: rgba(245,231,198,0.7);
            font-size: 0.92rem;
            font-weight: 400;
            text-decoration: none;
            transition: background 0.18s, color 0.18s;
            position: relative;
        }

        .nav-link svg {
            width: 17px;
            height: 17px;
            flex-shrink: 0;
            opacity: 0.8;
        }

        .nav-link:hover {
            background: rgba(250,129,18,0.1);
            color: var(--white);
        }

        .nav-link:hover svg { opacity: 1; }

        .nav-link.active {
            background: var(--orange);
            color: var(--white);
            font-weight: 600;
        }

        .nav-link.active svg { opacity: 1; }

        /* ── Spacer + logout ── */
        .sidebar-spacer { flex-grow: 1; }

        .sidebar-divider {
            height: 1px;
            background: rgba(250,129,18,0.12);
            margin: 20px 0;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            border-radius: var(--radius-btn);
            background: transparent;
            border: 1.5px solid rgba(245,231,198,0.15);
            color: rgba(245,231,198,0.7);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.18s, color 0.18s, border-color 0.18s;
        }

        .logout-btn svg { width: 16px; height: 16px; }

        .logout-btn:hover {
            background: rgba(250,129,18,0.12);
            border-color: var(--orange);
            color: var(--white);
        }

        /* ══════════════════════════════════════════════════════
           MAIN CONTENT
        ══════════════════════════════════════════════════════ */
        .main-content {
            flex-grow: 1;
            padding: 40px 44px;
            overflow-y: auto;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        /* ── Page header ── */
        .page-header {
            margin-bottom: 32px;
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
            font-weight: 400;
        }

        /* ── Success alert ── */
        .alert-success {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
            padding: 14px 18px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: var(--radius-input);
            color: #166534;
            font-size: 0.9rem;
        }

        .alert-success svg { width: 18px; height: 18px; flex-shrink: 0; }

        /* ── Panel / Card ── */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-card);
        }

        .panel + .panel,
        .panel + .role-panel {
            margin-top: 24px;
        }

        /* ── Panel header row ── */
        .panel-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
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

        .panel-icon svg {
            width: 18px;
            height: 18px;
            color: var(--orange);
        }

        .panel-heading {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.3rem;
            color: var(--black);
            letter-spacing: 0.05em;
        }

        .panel-desc {
            font-size: 0.88rem;
            color: rgba(34,34,34,0.55);
            margin-bottom: 20px;
            line-height: 1.6;
        }

        /* ══════════════════════════════════════════════════════
           BUTTONS — exact index.php style
        ══════════════════════════════════════════════════════ */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            border: none;
            border-radius: var(--radius-btn);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem;
            letter-spacing: 0.12em;
            cursor: pointer;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
            text-decoration: none;
        }

        .btn svg { width: 16px; height: 16px; }

        .btn:hover { transform: translateY(-1px); }

        /* Primary — black base, orange hover */
        .btn-primary {
            background: var(--black);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--orange);
            box-shadow: 0 6px 20px rgba(250,129,18,0.3);
        }

        /* Danger — orange base, dark orange hover */
        .btn-danger {
            background: var(--orange);
            color: var(--white);
        }

        .btn-danger:hover {
            background: var(--orange-dk);
            box-shadow: 0 6px 20px rgba(250,129,18,0.35);
        }

        /* Ghost / secondary */
        .btn-ghost {
            background: transparent;
            color: var(--black);
            border: 1.5px solid rgba(34,34,34,0.18);
        }

        .btn-ghost:hover {
            background: rgba(34,34,34,0.04);
            border-color: var(--orange);
        }

        .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        /* ══════════════════════════════════════════════════════
           FULLCALENDAR OVERRIDES — on-brand
        ══════════════════════════════════════════════════════ */
        .fc {
            font-family: 'DM Sans', sans-serif !important;
        }

        .fc .fc-toolbar-title {
            font-family: 'Bebas Neue', sans-serif !important;
            font-size: 1.6rem !important;
            color: var(--black) !important;
            letter-spacing: 0.06em;
        }

        .fc .fc-button {
            font-family: 'Bebas Neue', sans-serif !important;
            letter-spacing: 0.1em !important;
            font-size: 0.85rem !important;
            border-radius: var(--radius-btn) !important;
            padding: 8px 18px !important;
            border: none !important;
            transition: background 0.18s !important;
        }

        .fc .fc-button-primary {
            background-color: var(--black) !important;
            border-color: var(--black) !important;
            color: var(--white) !important;
        }

        .fc .fc-button-primary:hover,
        .fc .fc-button-primary:not(:disabled):active,
        .fc .fc-button-primary:not(:disabled).fc-button-active {
            background-color: var(--orange) !important;
            border-color: var(--orange) !important;
        }

        .fc .fc-today-button {
            background-color: var(--orange) !important;
            border-color: var(--orange) !important;
        }

        .fc .fc-today-button:hover {
            background-color: var(--orange-dk) !important;
        }

        .fc .fc-daygrid-day.fc-day-today {
            background: rgba(250,129,18,0.07) !important;
        }

        .fc .fc-event {
            border: none !important;
            border-radius: 6px !important;
            background: var(--orange) !important;
            font-size: 0.8rem !important;
            font-weight: 500 !important;
            cursor: pointer !important;
            transition: background 0.18s !important;
        }

        .fc .fc-event:hover {
            background: var(--orange-dk) !important;
        }

        .fc .fc-col-header-cell-cushion {
            font-weight: 600;
            color: var(--black);
        }

        .fc .fc-daygrid-day-number {
            color: var(--black);
            font-size: 0.85rem;
        }

        /* ══════════════════════════════════════════════════════
           ANIMATIONS
        ══════════════════════════════════════════════════════ */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ══════════════════════════════════════════════════════
           RESPONSIVE
        ══════════════════════════════════════════════════════ */
        @media (max-width: 900px) {
            body { flex-direction: column; }

            .sidebar {
                width: 100%;
                height: auto;
                position: static;
                flex-direction: row;
                flex-wrap: wrap;
                gap: 12px;
                padding: 16px 20px;
            }

            .sidebar-brand   { padding-bottom: 0; border-bottom: none; margin-bottom: 0; }
            .sidebar-user    { margin-bottom: 0; }
            .sidebar-spacer,
            .sidebar-divider { display: none; }
            .nav-list        { flex-direction: row; flex-wrap: wrap; }
            .logout-btn      { padding: 10px 16px; }

            .main-content { padding: 24px 20px; }

            .btn-group { flex-direction: column; }
            .btn       { justify-content: center; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════════════════ -->
<aside class="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <img src="../img/radog_logocutie.png" alt="Radog's Kennel">
        </div>
        <div class="sidebar-wordmark">
            <div class="sidebar-wordmark-top">Radog's Kennel</div>
            <div class="sidebar-wordmark-sub">Pet Hotel Management</div>
        </div>
    </div>

    <!-- Logged-in user -->
    <div class="sidebar-user">
        <div class="sidebar-avatar">
            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
        </div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
            <div class="sidebar-user-role"><?php echo htmlspecialchars($role); ?></div>
        </div>
    </div>

    <!-- Navigation -->
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
            <li><a href="calendar-unified.php" class="nav-link active">
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
            <!-- <li><a href="checkout-unified.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                Checkout / Payments
            </a></li> -->
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
            <li><a href="calendar.php" class="nav-link active">
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
            <li><a href="checkout.php" class="nav-link">
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

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════════ -->
<main class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-eyebrow">Scheduling</div>
        <h1 class="page-title">Time-Blocking Calendar</h1>
        <p class="page-subtitle">Real-time monitoring of kennel availability and booking schedule</p>

        <?php if (!empty($success_message)): ?>
            <div class="alert-success">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Shared Calendar (Both Roles) ── -->
    <div class="panel">
        <div id="calendar"></div>
    </div>

    <!-- ── Admin-Only Controls ── -->
    <?php if (isAdmin()): ?>
        <div class="panel" style="margin-top: 24px;">
            <div class="panel-header">
                <div class="panel-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <span class="panel-heading">Admin Controls</span>
            </div>
            <p class="panel-desc">Manage calendar availability, block dates, and control the full booking lifecycle.</p>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="showBlockDatesModal()">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    Block Dates
                </button>
                <button class="btn btn-primary" onclick="alert('Edit Booking functionality')">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Edit Booking
                </button>
                <button class="btn btn-danger" onclick="alert('Delete Booking functionality')">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Delete Booking
                </button>
                <button class="btn btn-ghost" onclick="alert('Manage Holidays functionality')">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    Manage Holidays
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Staff-Only Controls ── -->
    <?php if (isStaff()): ?>
        <div class="panel" style="margin-top: 24px;">
            <div class="panel-header">
                <div class="panel-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                    </svg>
                </div>
                <span class="panel-heading">Daily Care Operations</span>
            </div>
            <p class="panel-desc">Log pet check-ins, check-outs, and daily care activities for boarded pets.</p>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="alert('Check-In functionality')">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Mark as Checked-In
                </button>
                <button class="btn btn-primary" onclick="alert('Check-Out functionality')">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    Mark as Checked-Out
                </button>
                <button class="btn btn-ghost" onclick="alert('Care Log functionality')">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Log Daily Care
                </button>
            </div>
        </div>
    <?php endif; ?>

</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const calendarEl = document.getElementById('calendar');
        const calendar   = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,timeGridWeek'
            },
            events: <?php echo json_encode($events); ?>,
            eventClick: function (info) {
                if (info.event.url) {
                    info.jsEvent.preventDefault();
                    window.location.href = info.event.url;
                }
            }
        });
        calendar.render();
    });

    function showBlockDatesModal() {
        alert('Block Dates modal would appear here');
        // TODO: implement modal
    }
</script>
</body>
</html>
