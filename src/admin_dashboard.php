<?php
session_start();
require_once '../config/db.php';

// ════════════════════════════════════════════════════════════════
// RBAC: Admin only
// ════════════════════════════════════════════════════════════════
if (!isset($_SESSION['account_id'])) {
    header('Location: ../index.php');
    exit;
}

$display_name = htmlspecialchars($_SESSION['username'] ?? 'Admin');

// ════════════════════════════════════════════════════════════════
// SAFE DEFAULTS — prevents undefined-variable warnings if any
// query below throws before the variable is assigned
// ════════════════════════════════════════════════════════════════
$total_bookings      = 0;
$monthly_sales       = 0;
$total_rooms         = 0;
$booked_rooms        = 0;
$occupancy_rate      = 0;
$booked_this_month   = 0;
$revenueLabels       = [];
$revenueAmounts      = [];
$schedule_today      = [];

// ════════════════════════════════════════════════════════════════
// FETCH METRICS
// ════════════════════════════════════════════════════════════════
try {
    // Total bookings (all time)
    $stmt = $pdo->query("SELECT COUNT(*) FROM BOOKING");
    $total_bookings = (int) $stmt->fetchColumn();

    // Total paid sales (all time) - FIXED: Querying TOTAL_AMOUNT from BOOKING instead of missing PAYMENT table
    $stmt = $pdo->query("SELECT NVL(SUM(TOTAL_AMOUNT), 0) FROM BOOKING WHERE TOTAL_AMOUNT > 0");
    $monthly_sales = (float) ($stmt->fetchColumn() ?: 0);

    // Accommodation totals
    $stmtTotal  = $pdo->query("SELECT COUNT(*) FROM ACCOMMODATION");
    $total_rooms  = (int) $stmtTotal->fetchColumn();

    $stmtBooked = $pdo->query("SELECT COUNT(*) FROM ACCOMMODATION WHERE Occupancy_Status = 'Booked'");
    $booked_rooms = (int) $stmtBooked->fetchColumn();

    $occupancy_rate = ($total_rooms > 0) ? round(($booked_rooms / $total_rooms) * 100, 1) : 0;

    // Bookings confirmed/started this calendar month
    $stmtMonth = $pdo->query(
        "SELECT COUNT(*) FROM BOOKING
         WHERE BOOKING_STATUS = 'Confirmed'
           AND TRUNC(CHECK_IN_DATE, 'MM') = TRUNC(SYSDATE, 'MM')"
    );
    $booked_this_month = (int) $stmtMonth->fetchColumn();

    // Revenue trend (line chart) - FIXED: Removed JOIN to missing PAYMENT table
    $revenueQuery = "SELECT TO_CHAR(Check_Out_Date, 'MON YYYY') AS Sale_Month,
                            SUM(TOTAL_AMOUNT) AS Monthly_Revenue
                     FROM BOOKING
                     WHERE TOTAL_AMOUNT > 0
                     GROUP BY TO_CHAR(Check_Out_Date, 'MON YYYY'),
                              TO_CHAR(Check_Out_Date, 'YYYY-MM')
                     ORDER BY TO_CHAR(Check_Out_Date, 'YYYY-MM')";
    $stmtRevenue  = $pdo->query($revenueQuery);
    $revenueData  = $stmtRevenue->fetchAll(PDO::FETCH_ASSOC);
    foreach ($revenueData as $row) {
        $revenueLabels[]  = $row['SALE_MONTH'];
        $revenueAmounts[] = (float) $row['MONTHLY_REVENUE'];
    }

    // Today's check-in & check-out schedule (Unchanged, this part was correct)
    $scheduleQuery = "SELECT B.BOOKING_ID,
                             P.PET_NAME,
                             O.FIRST_NAME || ' ' || O.LAST_NAME AS OWNER_NAME,
                             A.UNIT_NAME,
                             B.CHECK_IN_DATE,
                             B.CHECK_OUT_DATE,
                             B.BOOKING_STATUS,
                             B.SPECIAL_INSTRUCTIONS,
                             CASE
                               WHEN TRUNC(B.CHECK_IN_DATE)  = TRUNC(SYSDATE) THEN 'Check-In'
                               WHEN TRUNC(B.CHECK_OUT_DATE) = TRUNC(SYSDATE) THEN 'Check-Out'
                             END AS SCHEDULE_TYPE
                      FROM BOOKING B
                      JOIN PET           P ON B.PET_ID           = P.PET_ID
                      JOIN OWNER         O ON B.OWNER_ID         = O.OWNER_ID
                      JOIN ACCOMMODATION A ON B.ACCOMMODATION_ID = A.ACCOMMODATION_ID
                      WHERE TRUNC(B.CHECK_IN_DATE)  = TRUNC(SYSDATE)
                         OR TRUNC(B.CHECK_OUT_DATE) = TRUNC(SYSDATE)
                      ORDER BY B.CHECK_IN_DATE ASC";
    $stmtSchedule   = $pdo->query($scheduleQuery);
    $schedule_today = $stmtSchedule->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $db_error = 'Error loading data: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — Radog's Kennel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* ══════════════════════════════════════════════════════
           DESIGN TOKENS
        ══════════════════════════════════════════════════════ */
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

        .sidebar-brand {
            display: flex; align-items: center; gap: 14px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 24px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) both;
        }

        .sidebar-logo { width: 52px; height: 52px; flex-shrink: 0; filter: drop-shadow(0 0 12px rgba(250,129,18,0.5)); }
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

        .sidebar-user-name { font-size: 0.88rem; font-weight: 600; color: var(--white); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-user-role { font-size: 0.72rem; color: var(--orange); letter-spacing: 0.06em; text-transform: uppercase; }

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

        /* TODO: Remove .nav-link-checkout when Checkout is merged into Schedule page */
        /* .nav-link-checkout { display: none; } */

        .sidebar-spacer { flex-grow: 1; }
        .sidebar-divider { height: 1px; background: rgba(250,129,18,0.12); margin: 20px 0; }

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

        .page-title   { font-size: 2.4rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); }

        /* DB error */
        .alert-warning {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 14px 18px; border-radius: var(--radius-input);
            background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
            font-size: 0.88rem; margin-bottom: 24px;
        }

        .alert-warning svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

        /* ══════════════════════════════════════════════════════
           STAT CARDS
        ══════════════════════════════════════════════════════ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 24px 28px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s;
        }

        .stat-card:hover { transform: translateY(-2px); }

        .stat-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            border-radius: 4px 0 0 4px;
        }

        .stat-card.green::before  { background: #22c55e; }
        .stat-card.blue::before   { background: #3b82f6; }
        .stat-card.orange::before { background: var(--orange); }

        .stat-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px;
        }

        .stat-icon svg { width: 20px; height: 20px; }
        .stat-icon.green  { background: rgba(34,197,94,0.12);  color: #16a34a; }
        .stat-icon.blue   { background: rgba(59,130,246,0.12); color: #2563eb; }
        .stat-icon.orange { background: rgba(250,129,18,0.12); color: var(--orange); }

        .stat-label {
            font-size: 0.72rem; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
            color: rgba(34,34,34,0.45); margin-bottom: 8px;
        }

        .stat-value {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 2.6rem; color: var(--black); line-height: 1;
        }

        .stat-sub {
            font-size: 0.8rem; color: rgba(34,34,34,0.4);
            margin-top: 4px;
        }

        /* ══════════════════════════════════════════════════════
           CHARTS ROW
        ══════════════════════════════════════════════════════ */
        .charts-grid {
            display: grid;
            grid-template-columns: 5fr 7fr;
            gap: 20px;
            margin-bottom: 28px;
        }

        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-card);
        }

        .panel-header {
            display: flex; align-items: center; gap: 12px;
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
            font-size: 1.2rem; color: var(--black); letter-spacing: 0.05em;
        }

        .chart-wrap {
            position: relative;
            height: 240px;
            width: 100%;
        }

        /* ══════════════════════════════════════════════════════
           QUICK ACTIONS
        ══════════════════════════════════════════════════════ */
        .actions-panel { margin-bottom: 0; }

        .actions-grid {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 13px 24px; border: none; border-radius: var(--radius-btn);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem; letter-spacing: 0.12em;
            cursor: pointer; text-decoration: none;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }

        .btn svg { width: 16px; height: 16px; }
        .btn:hover { transform: translateY(-1px); }

        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }

        .btn-ghost {
            background: transparent; color: var(--black);
            border: 1.5px solid rgba(34,34,34,0.18);
        }

        .btn-ghost:hover { background: rgba(34,34,34,0.04); border-color: var(--orange); }

        /* ══════════════════════════════════════════════════════
           TABLE
        ══════════════════════════════════════════════════════ */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table thead tr { background: rgba(250,129,18,0.04); }
        .data-table th {
            padding: 12px 20px;
            font-size: 0.72rem; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase;
            color: rgba(34,34,34,0.45); text-align: left;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }
        .data-table tbody tr { border-bottom: 1px solid rgba(34,34,34,0.05); transition: background 0.15s; }
        .data-table tbody tr:last-child { border-bottom: none; }
        .data-table tbody tr:hover { background: rgba(250,129,18,0.03); }
        .data-table td { padding: 14px 20px; font-size: 0.92rem; vertical-align: middle; }

        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 99px;
            font-size: 0.78rem; font-weight: 600;
        }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }

        .status-confirmed  { background: rgba(250,129,18,0.12); color: #d96a08; }
        .status-confirmed .status-dot { background: var(--orange); }
        .status-completed  { background: rgba(34,197,94,0.1);  color: #16a34a; }
        .status-completed .status-dot { background: #22c55e; }
        .status-cancelled  { background: rgba(239,68,68,0.1);  color: #991b1b; }
        .status-cancelled .status-dot { background: #ef4444; }
        .status-pending    { background: rgba(245,158,11,0.1); color: #92400e; }
        .status-pending .status-dot   { background: #f59e0b; }

        .schedule-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 99px;
            font-size: 0.78rem; font-weight: 600;
        }
        .badge-checkin  { background: rgba(34,197,94,0.12); color: #16a34a; }
        .badge-checkin .status-dot  { background: #22c55e; box-shadow: 0 0 5px #22c55e; }
        .badge-checkout { background: rgba(250,129,18,0.12); color: #d96a08; }
        .badge-checkout .status-dot { background: var(--orange); box-shadow: 0 0 5px var(--orange); }

        /* charts grid full-width when only one chart */
        .charts-grid { grid-template-columns: 1fr; }


        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1100px) {
            .charts-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .stats-grid { grid-template-columns: 1fr; }
            .actions-grid { flex-direction: column; }
            .btn { justify-content: center; }
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
            <img src="../img/radog_logocutie.png" alt="Radog's Kennel">
        </div>
        <div>
            <div class="sidebar-wordmark-top">Radog's Kennel</div>
            <div class="sidebar-wordmark-sub">Pet Hotel Management</div>
        </div>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-avatar">
            <?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)); ?>
        </div>
        <div>
            <div class="sidebar-user-name"><?php echo $display_name; ?></div>
            <div class="sidebar-user-role">Administrator</div>
        </div>
    </div>

    <div class="nav-section-label">Navigation</div>
    <ul class="nav-list">

        <li><a href="admin_dashboard.php" class="nav-link active">
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

        <!--
        TODO: Remove this nav item once Checkout is merged into the Schedule page.
        At that point, delete the <li> below entirely.
        -->
        <!-- <li><a href="checkout.php" class="nav-link nav-link-checkout">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Checkout / Payments
        </a></li> -->

        <li><a href="user_management.php" class="nav-link">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            User Management
        </a></li>

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
        <div class="page-eyebrow">Overview</div>
        <h1 class="page-title">Admin Dashboard</h1>
        <p class="page-subtitle">Real-time kennel performance overview — welcome back, <?php echo $display_name; ?>.</p>
    </div>

    <!-- DB error -->
    <?php if (isset($db_error)): ?>
        <div class="alert-warning">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
            </svg>
            <?php echo htmlspecialchars($db_error); ?>
        </div>
    <?php endif; ?>

    <!-- ── Stat Cards ── -->
    <div class="stats-grid">

        <div class="stat-card green">
            <div class="stat-icon green">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="stat-label">Total Sales (Paid)</div>
            <div class="stat-value" style="font-size:2rem;">₱<?php echo number_format($monthly_sales, 2); ?></div>
            <div class="stat-sub">All-time paid payments</div>
        </div>

        <div class="stat-card blue">
            <div class="stat-icon blue">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/>
                </svg>
            </div>
            <div class="stat-label">Occupancy Rate</div>
            <div class="stat-value"><?php echo number_format($occupancy_rate, 1); ?>%</div>
            <div class="stat-sub"><?php echo $booked_rooms; ?> of <?php echo $total_rooms; ?> units booked</div>
        </div>

        <div class="stat-card orange">
            <div class="stat-icon orange">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div class="stat-label">Total Bookings</div>
            <div class="stat-value"><?php echo $total_bookings; ?></div>
            <div class="stat-sub">All-time reservations</div>
        </div>

    </div>

    <!-- ── Secondary Stat Cards ── -->
    <div class="stats-grid" style="margin-bottom: 28px; grid-template-columns: 1fr 2fr;">

        <div class="stat-card orange">
            <div class="stat-icon orange">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div class="stat-label">Booked This Month</div>
            <div class="stat-value"><?php echo $booked_this_month; ?></div>
            <div class="stat-sub">Confirmed bookings in <?php echo date('F Y'); ?></div>
        </div>

        <div class="stat-card blue">
            <div class="stat-icon blue">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/>
                </svg>
            </div>
            <div class="stat-label">Total Active Occupancy</div>
            <div class="stat-value"><?php echo $booked_rooms; ?> <span style="font-size:1rem;color:rgba(34,34,34,0.35);letter-spacing:0.06em;">/ <?php echo $total_rooms; ?></span></div>
            <div class="stat-sub">Units physically occupied right now — <?php echo $occupancy_rate; ?>% occupancy rate</div>
        </div>

    </div>

    <!-- ── Charts ── -->
    <div class="charts-grid">

        <div class="panel" style="grid-column: span 2;">
            <div class="panel-header">
                <div class="panel-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
                    </svg>
                </div>
                <span class="panel-heading">Revenue Trend</span>
            </div>
            <div class="chart-wrap">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

    </div>

    <!-- ── Today's Schedule ── -->
    <div class="panel" style="margin-bottom: 28px;">
        <div class="panel-header">
            <div class="panel-icon">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <span class="panel-heading">Today's Check-In &amp; Check-Out Schedule</span>
            <span style="margin-left:auto; font-size:0.78rem; font-weight:600; background:rgba(250,129,18,0.1); color:var(--orange); padding:4px 12px; border-radius:99px; letter-spacing:0.04em;">
                <?php echo date('F j, Y'); ?>
            </span>
        </div>

        <?php if (empty($schedule_today)): ?>
            <div style="text-align:center; padding:40px 20px; color:rgba(34,34,34,0.35);">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"
                     style="width:40px;height:40px;margin:0 auto 12px;display:block;opacity:0.3;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <p style="font-size:0.92rem;">No check-ins or check-outs scheduled for today.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Pet</th>
                            <th>Owner</th>
                            <th>Unit</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Special Instructions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedule_today as $s): ?>
                            <?php
                                $type      = htmlspecialchars($s['SCHEDULE_TYPE']);
                                $isCheckIn = $s['SCHEDULE_TYPE'] === 'Check-In';
                                $dateVal   = $isCheckIn
                                    ? date('M j, Y', strtotime($s['CHECK_IN_DATE']))
                                    : date('M j, Y', strtotime($s['CHECK_OUT_DATE']));
                                $bStatus   = strtolower(htmlspecialchars($s['BOOKING_STATUS']));
                            ?>
                            <tr>
                                <td>
                                    <span class="schedule-badge <?php echo $isCheckIn ? 'badge-checkin' : 'badge-checkout'; ?>">
                                        <span class="status-dot"></span>
                                        <?php echo $type; ?>
                                    </span>
                                </td>
                                <td style="font-weight:600;"><?php echo htmlspecialchars($s['PET_NAME']); ?></td>
                                <td><?php echo htmlspecialchars($s['OWNER_NAME']); ?></td>
                                <td>
                                    <span style="font-size:0.8rem;background:rgba(34,34,34,0.06);padding:3px 10px;border-radius:99px;font-weight:500;">
                                        <?php echo htmlspecialchars($s['UNIT_NAME']); ?>
                                    </span>
                                </td>
                                <td style="font-size:0.88rem;color:rgba(34,34,34,0.65);"><?php echo $dateVal; ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $bStatus; ?>">
                                        <span class="status-dot"></span>
                                        <?php echo htmlspecialchars($s['BOOKING_STATUS']); ?>
                                    </span>
                                </td>
                                <td style="font-size:0.85rem;color:rgba(34,34,34,0.6);max-width:220px;">
                                    <?php echo $s['SPECIAL_INSTRUCTIONS']
                                        ? htmlspecialchars($s['SPECIAL_INSTRUCTIONS'])
                                        : '<span style="font-style:italic;opacity:0.4;">None</span>'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Quick Actions ── -->
    <div class="panel actions-panel">
        <div class="panel-header">
            <div class="panel-icon">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <span class="panel-heading">Quick Actions</span>
        </div>
        <div class="actions-grid">
            <a href="encode_reservation.php" class="btn btn-primary">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Schedule New Appointment
            </a>
            <a href="pets.php" class="btn btn-ghost">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                Search Pet Profile
            </a>
            <!--
            TODO: Remove the Checkout button below once Checkout is merged into Schedule page.
            At that point, delete the entire <a> tag below.
            -->
            <a href="checkout.php" class="btn btn-ghost">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                Checkout / Payments
            </a>
        </div>
    </div>

</main>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ── Line: Revenue Trend ──────────────────────── */
    const revenueCtx     = document.getElementById('revenueChart').getContext('2d');
    const revenueLabels  = <?php echo json_encode($revenueLabels); ?>;
    const revenueAmounts = <?php echo json_encode($revenueAmounts); ?>;

    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: revenueLabels,
            datasets: [{
                label: 'Monthly Revenue (₱)',
                data: revenueAmounts,
                borderColor: '#FA8112',
                backgroundColor: 'rgba(250,129,18,0.08)',
                borderWidth: 3,
                tension: 0.35,
                fill: true,
                pointBackgroundColor: '#FA8112',
                pointRadius: 5,
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { family: 'DM Sans', size: 11 }, color: 'rgba(34,34,34,0.5)' }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(34,34,34,0.06)' },
                    ticks: {
                        font: { family: 'DM Sans', size: 11 },
                        color: 'rgba(34,34,34,0.5)',
                        callback: v => '₱' + Number(v).toLocaleString()
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ₱' + Number(ctx.raw).toLocaleString()
                    }
                }
            }
        }
    });
});
</script>
</body>
</html>