<?php
session_start();
require_once '../config/db.php';

// RBAC: Verify session
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

// Get Owner ID from URL
$owner_id = $_GET['id'] ?? null;
if (!$owner_id) {
    header("Location: owner.php");
    exit();
}

// 1. Fetch Owner Information
$owner_stmt = $pdo->prepare("SELECT FIRST_NAME, LAST_NAME, CONTACT_NUMBER FROM OWNER WHERE OWNER_ID = :id");
$owner_stmt->execute(['id' => $owner_id]);
$owner = $owner_stmt->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    die("Owner not found.");
}

// 2. Fetch Registered Pets for this Owner
$pets_stmt = $pdo->prepare("
    SELECT P.PET_ID, P.PET_NAME, P.SEX, P.WEIGHT, C.CATEGORY_NAME 
    FROM PET P 
    JOIN PET_CATEGORY C ON P.CATEGORY_ID = C.CATEGORY_ID 
    WHERE P.OWNER_ID = :id
");
$pets_stmt->execute(['id' => $owner_id]);
$pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Booking History
$booking_stmt = $pdo->prepare("
    SELECT B.BOOKING_ID, P.PET_NAME, B.CHECK_IN_DATE, B.BOOKING_STATUS 
    FROM BOOKING B 
    JOIN PET P ON B.PET_ID = P.PET_ID 
    WHERE B.OWNER_ID = :id 
    ORDER BY B.CHECK_IN_DATE DESC
");
$booking_stmt->execute(['id' => $owner_id]);
$bookings = $booking_stmt->fetchAll(PDO::FETCH_ASSOC);

// Role helper (preserve RBAC)
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'Administrator';
}
function isStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'Kennel Staff';
}
$role = $_SESSION['role'] ?? 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Profile — <?php echo htmlspecialchars($owner['LAST_NAME']); ?> | Radog's Kennel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">

    <style>
        /* ─── RESET & TOKENS ─── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

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

        /* ─── SIDEBAR ─── */
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
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 24px;
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
            text-shadow: 0 0 18px rgba(250,129,18,0.45);
            letter-spacing: 0.05em;
        }

        .sidebar-wordmark-sub {
            font-size: 0.68rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold);
            opacity: 0.8;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 28px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.05s both;
        }

        .sidebar-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--orange);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1rem;
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .sidebar-user-name {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--white);
        }

        .sidebar-user-role {
            font-size: 0.72rem;
            color: var(--orange);
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .nav-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-grow: 1;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        .nav-section-label {
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(245,231,198,0.4);
            padding: 0 4px;
            margin-bottom: 8px;
            margin-top: 16px;
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

        .sidebar-footer {
            margin-top: 20px;
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

        /* ─── MAIN CONTENT ─── */
        .main-content {
            flex-grow: 1;
            padding: 40px 44px;
            overflow-y: auto;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        /* ─── PAGE HEADER ─── */
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

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: rgba(34,34,34,0.5);
            text-decoration: none;
            margin-bottom: 24px;
            transition: color 0.18s;
        }

        .back-link:hover { color: var(--orange); }
        .back-link svg { width: 15px; height: 15px; }

        /* ─── PANELS ─── */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }

        .panel-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
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

        /* ─── GRID ─── */
        .profile-grid {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }

        /* ─── CONTACT PANEL ─── */
        .contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid rgba(34,34,34,0.05);
        }

        .contact-item:last-child { border-bottom: none; }

        .contact-icon-wrap {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(250,129,18,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .contact-icon-wrap svg { width: 16px; height: 16px; color: var(--orange); }

        .contact-label {
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(34,34,34,0.4);
            margin-bottom: 2px;
        }

        .contact-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--black);
        }

        .verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.72rem;
            font-weight: 600;
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 3px 10px;
            border-radius: 99px;
        }

        .verified-badge svg { width: 12px; height: 12px; }

        /* ─── PET CARDS ─── */
        .pets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px;
        }

        .pet-card {
            display: block;
            background: var(--beige);
            border: 1px solid rgba(34,34,34,0.07);
            border-radius: 16px;
            padding: 18px 16px;
            text-decoration: none;
            color: var(--black);
            transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
            position: relative;
            overflow: hidden;
        }

        .pet-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--orange);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.25s;
        }

        .pet-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(250,129,18,0.12);
            border-color: rgba(250,129,18,0.25);
        }

        .pet-card:hover::before { transform: scaleX(1); }

        .pet-category-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--orange);
            background: rgba(250,129,18,0.1);
            padding: 3px 8px;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .pet-name {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.2rem;
            letter-spacing: 0.05em;
            color: var(--black);
            margin-bottom: 6px;
        }

        .pet-meta {
            font-size: 0.78rem;
            color: rgba(34,34,34,0.5);
        }

        .pet-meta span + span::before {
            content: ' · ';
            color: rgba(34,34,34,0.3);
        }

        /* ─── BUTTONS ─── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 20px;
            border: none;
            border-radius: var(--radius-btn);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem;
            letter-spacing: 0.12em;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 15px; height: 15px; }

        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }

        .btn-orange { background: var(--orange); color: var(--white); }
        .btn-orange:hover { background: var(--orange-dk); box-shadow: 0 6px 20px rgba(250,129,18,0.35); }

        .btn-ghost { background: transparent; color: var(--black); border: 1.5px solid rgba(34,34,34,0.18); }
        .btn-ghost:hover { background: rgba(34,34,34,0.04); border-color: var(--orange); }

        .btn-sm { padding: 8px 14px; font-size: 0.82rem; }

        /* ─── TABLE ─── */
        .data-table { width: 100%; border-collapse: collapse; }

        .data-table thead tr { background: rgba(250,129,18,0.04); }

        .data-table th {
            padding: 12px 20px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(34,34,34,0.45);
            text-align: left;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }

        .data-table tbody tr {
            border-bottom: 1px solid rgba(34,34,34,0.05);
            transition: background 0.15s;
        }

        .data-table tbody tr:hover { background: rgba(250,129,18,0.03); }

        .data-table td {
            padding: 16px 20px;
            font-size: 0.92rem;
            vertical-align: middle;
        }

        .booking-id-cell {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1rem;
            letter-spacing: 0.06em;
            color: var(--orange);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }

        .status-confirmed  { background: rgba(250,129,18,0.1); color: #c45e00; }
        .status-confirmed .status-dot  { background: var(--orange); }
        .status-completed  { background: #f0fdf4; color: #166534; }
        .status-completed .status-dot  { background: #22c55e; }
        .status-pending    { background: #fffbeb; color: #92400e; }
        .status-pending .status-dot    { background: #f59e0b; }
        .status-cancelled  { background: rgba(34,34,34,0.06); color: rgba(34,34,34,0.5); }
        .status-cancelled .status-dot  { background: #9ca3af; }

        .empty-state {
            text-align: center;
            padding: 56px 24px;
            color: rgba(34,34,34,0.35);
        }

        .empty-state svg { width: 40px; height: 40px; margin-bottom: 12px; opacity: 0.3; }
        .empty-state p { font-size: 0.9rem; }

        /* ─── FORM FIELDS ─── */
        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #4a3f33;
            margin-bottom: 8px;
        }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .field-group { margin-bottom: 18px; }

        input, select, textarea {
            width: 100%;
            padding: 13px 16px;
            border: 1.5px solid #e2d9ce;
            border-radius: var(--radius-input);
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

        textarea { resize: vertical; min-height: 90px; }

        /* ─── MODALS ─── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(34,34,34,0.55);
            backdrop-filter: blur(3px);
            z-index: 100;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open { display: flex; }

        .modal-box {
            background: var(--white);
            border-radius: var(--radius-card);
            width: min(100%, 500px);
            box-shadow: 0 32px 80px rgba(15,23,42,0.18);
            overflow: hidden;
            animation: fadeUp 0.35s cubic-bezier(0.16,1,0.3,1) both;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .modal-box.modal-wide { width: min(100%, 640px); }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 28px;
            background-color: var(--black);
            background-image: repeating-linear-gradient(
                -55deg, transparent, transparent 18px,
                rgba(250,129,18,0.05) 18px, rgba(250,129,18,0.05) 19px
            );
            flex-shrink: 0;
        }

        .modal-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.3rem;
            color: var(--white);
            letter-spacing: 0.06em;
        }

        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            color: rgba(245,231,198,0.6);
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.18s, color 0.18s;
        }

        .modal-close:hover { background: rgba(250,129,18,0.2); color: var(--white); }
        .modal-close svg { width: 18px; height: 18px; }

        .modal-body {
            padding: 28px;
            overflow-y: auto;
        }

        .modal-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            padding: 20px 28px;
            border-top: 1px solid rgba(34,34,34,0.07);
            flex-shrink: 0;
        }

        /* ─── ANIMATIONS ─── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 1100px) {
            .profile-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .form-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .pets-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<!-- ─────────────────── SIDEBAR ─────────────────── -->
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

    <div class="nav-section-label">Main Menu</div>
    <ul class="nav-list">
        <li>
            <?php if (isAdmin()): ?>
                <a href="admin_dashboard.php" class="nav-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Dashboard
                </a>
            <?php else: ?>
                <a href="staff_dashboard.php" class="nav-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Dashboard
                </a>
            <?php endif; ?>
        </li>
        <li>
            <a href="encode_reservation.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                Schedule
            </a>
        </li>
        <li>
            <a href="calendar.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Calendar
            </a>
        </li>
        <li>
            <a href="owner.php" class="nav-link active">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Owners
            </a>
        </li>
        <li>
            <a href="pets.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>
                Pets
            </a>
        </li>
        <li>
            <a href="checkout.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                Checkout / Payments
            </a>
        </li>
        <?php if (isAdmin()): ?>
        <li>
            <div class="nav-section-label" style="margin-top:16px;">Administration</div>
            <a href="user_management.php" class="nav-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/><path d="M16 11l2 2 4-4"/></svg>
                User Management
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- ─────────────────── MAIN CONTENT ─────────────────── -->
<main class="main-content">

    <a href="owner.php" class="back-link">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
        Back to Owners
    </a>

    <div class="page-eyebrow">Owner Management</div>
    <h1 class="page-title"><?php echo htmlspecialchars($owner['LAST_NAME'] . ", " . $owner['FIRST_NAME']); ?></h1>
    <p class="page-subtitle">Owner profile, registered pets &amp; booking history</p>

    <!-- Profile Grid: Contact + Pets -->
    <div class="profile-grid">

        <!-- Contact Panel -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-header-left">
                    <div class="panel-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    </div>
                    <span class="panel-heading">Contact Information</span>
                </div>
                <button class="btn btn-ghost btn-sm" onclick="openModal('editOwnerModal')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Edit
                </button>
            </div>

            <div class="contact-item">
                <div class="contact-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.6 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L7.91 8.6a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <div>
                    <div class="contact-label">Contact Number</div>
                    <div class="contact-value"><?php echo htmlspecialchars($owner['CONTACT_NUMBER']); ?></div>
                </div>
            </div>

            <div class="contact-item">
                <div class="contact-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div>
                    <div class="contact-label">Verification Status</div>
                    <div style="margin-top:4px;">
                        <span class="verified-badge">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Verified
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Registered Pets Panel -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-header-left">
                    <div class="panel-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>
                    </div>
                    <span class="panel-heading">Registered Pets</span>
                </div>
                <button class="btn btn-orange btn-sm" onclick="openModal('registerPetModal')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Register Pet
                </button>
            </div>

            <?php if (!empty($pets)): ?>
            <div class="pets-grid">
                <?php foreach ($pets as $p): ?>
                <a href="pet_profile.php?id=<?php echo $p['PET_ID']; ?>" class="pet-card">
                    <div class="pet-category-chip">
                        <?php echo htmlspecialchars($p['CATEGORY_NAME']); ?>
                    </div>
                    <div class="pet-name"><?php echo htmlspecialchars($p['PET_NAME']); ?></div>
                    <div class="pet-meta">
                        <span><?php echo htmlspecialchars($p['SEX']); ?></span>
                        <span><?php echo htmlspecialchars($p['WEIGHT']); ?> kg</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>
                <p>No pets registered yet.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Booking History Panel -->
    <div class="panel" style="padding:0; overflow:hidden;">
        <div class="panel-header" style="padding: 22px 28px 18px; border-radius: 0;">
            <div class="panel-header-left">
                <div class="panel-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
                <span class="panel-heading">Booking History</span>
            </div>
            <span style="font-size:0.8rem; color:rgba(34,34,34,0.4);"><?php echo count($bookings); ?> record<?php echo count($bookings) !== 1 ? 's' : ''; ?></span>
        </div>

        <div style="overflow-x:auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Pet Name</th>
                        <th>Check-In Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b): ?>
                    <?php
                        $status = $b['BOOKING_STATUS'];
                        $status_class = 'status-' . strtolower($status);
                    ?>
                    <tr>
                        <td class="booking-id-cell">BK-<?php echo str_pad($b['BOOKING_ID'], 5, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo htmlspecialchars($b['PET_NAME']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($b['CHECK_IN_DATE'])); ?></td>
                        <td>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <span class="status-dot"></span>
                                <?php echo htmlspecialchars($status); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="4">
                            <div class="empty-state">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                <p>No booking history found for this owner.</p>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ════════════════════════════════════════════
     MODAL 1 — EDIT OWNER
════════════════════════════════════════════ -->
<div class="modal-overlay" id="editOwnerModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Edit Owner Information</span>
            <button class="modal-close" onclick="closeModal('editOwnerModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="editOwnerForm" method="POST" action="update_owner.php">
                <input type="hidden" name="owner_id" value="<?php echo htmlspecialchars($owner_id); ?>">

                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="edit_first_name">First Name</label>
                        <input type="text" id="edit_first_name" name="first_name"
                               value="<?php echo htmlspecialchars($owner['FIRST_NAME']); ?>" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="edit_last_name">Last Name</label>
                        <input type="text" id="edit_last_name" name="last_name"
                               value="<?php echo htmlspecialchars($owner['LAST_NAME']); ?>" required>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="edit_contact">Contact Number</label>
                    <input type="text" id="edit_contact" name="contact_number"
                           value="<?php echo htmlspecialchars($owner['CONTACT_NUMBER']); ?>" required>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('editOwnerModal')">Cancel</button>
            <button class="btn btn-primary" onclick="document.getElementById('editOwnerForm').submit()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════
     MODAL 2 — REGISTER NEW OWNER
════════════════════════════════════════════ -->
<div class="modal-overlay" id="registerOwnerModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Register New Owner</span>
            <button class="modal-close" onclick="closeModal('registerOwnerModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="registerOwnerForm" method="POST" action="register_owner.php">
                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="reg_first_name">First Name</label>
                        <input type="text" id="reg_first_name" name="first_name" placeholder="e.g. Maria" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="reg_last_name">Last Name</label>
                        <input type="text" id="reg_last_name" name="last_name" placeholder="e.g. Santos" required>
                    </div>
                </div>
                <div class="field-group">
                    <label class="field-label" for="reg_contact">Contact Number</label>
                    <input type="text" id="reg_contact" name="contact_number" placeholder="e.g. 09XXXXXXXXX" required>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('registerOwnerModal')">Cancel</button>
            <button class="btn btn-orange" onclick="document.getElementById('registerOwnerForm').submit()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Register Owner
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════
     MODAL 3 — REGISTER NEW PET
════════════════════════════════════════════ -->
<div class="modal-overlay" id="registerPetModal">
    <div class="modal-box modal-wide">
        <div class="modal-head">
            <span class="modal-title">Register New Pet</span>
            <button class="modal-close" onclick="closeModal('registerPetModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="registerPetForm" method="POST" action="register_pet.php">
                <input type="hidden" name="owner_id" value="<?php echo htmlspecialchars($owner_id); ?>">

                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="pet_name">Pet Name</label>
                        <input type="text" id="pet_name" name="pet_name" placeholder="e.g. Buddy" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="pet_sex">Sex</label>
                        <select id="pet_sex" name="sex" required>
                            <option value="" disabled selected>Select sex</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="pet_weight">Weight (kg)</label>
                        <input type="number" id="pet_weight" name="weight" step="0.01" min="0.01" placeholder="e.g. 12.50" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="pet_category">Category</label>
                        <select id="pet_category" name="category_id" required>
                            <option value="" disabled selected>Select category</option>
                            <option value="1">Dog</option>
                            <option value="2">Cat</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="pet_feeding_time">Feeding Time</label>
                        <input type="text" id="pet_feeding_time" name="feeding_time" placeholder="e.g. BID, TID">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="pet_feeding_portion">Feeding Portion</label>
                        <input type="text" id="pet_feeding_portion" name="feeding_portion" placeholder="e.g. 2 cups">
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="pet_notes">Behavioral Notes</label>
                    <textarea id="pet_notes" name="behavioral_notes" placeholder="Any notes on temperament, behavior, or special needs..."></textarea>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('registerPetModal')">Cancel</button>
            <button class="btn btn-orange" onclick="document.getElementById('registerPetForm').submit()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Register Pet
            </button>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════
     MODAL 4 — EDIT PET (generic; populate via JS for whichever pet)
════════════════════════════════════════════ -->
<div class="modal-overlay" id="editPetModal">
    <div class="modal-box modal-wide">
        <div class="modal-head">
            <span class="modal-title">Edit Pet Information</span>
            <button class="modal-close" onclick="closeModal('editPetModal')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="editPetForm" method="POST" action="update_pet.php">
                <input type="hidden" name="pet_id" id="edit_pet_id">
                <input type="hidden" name="owner_id" value="<?php echo htmlspecialchars($owner_id); ?>">

                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="edit_pet_name">Pet Name</label>
                        <input type="text" id="edit_pet_name" name="pet_name" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="edit_pet_sex">Sex</label>
                        <select id="edit_pet_sex" name="sex" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="edit_pet_weight">Weight (kg)</label>
                        <input type="number" id="edit_pet_weight" name="weight" step="0.01" min="0.01" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="edit_pet_category">Category</label>
                        <select id="edit_pet_category" name="category_id" required>
                            <option value="1">Dog</option>
                            <option value="2">Cat</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="field-group">
                        <label class="field-label" for="edit_pet_feeding_time">Feeding Time</label>
                        <input type="text" id="edit_pet_feeding_time" name="feeding_time">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="edit_pet_feeding_portion">Feeding Portion</label>
                        <input type="text" id="edit_pet_feeding_portion" name="feeding_portion">
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="edit_pet_notes">Behavioral Notes</label>
                    <textarea id="edit_pet_notes" name="behavioral_notes"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" onclick="closeModal('editPetModal')">Cancel</button>
            <button class="btn btn-primary" onclick="document.getElementById('editPetForm').submit()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ─── JAVASCRIPT ─── -->
<script>
    // Modal helpers
    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }

    // Close on overlay click
    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(function(m) {
                closeModal(m.id);
            });
        }
    });

    // Populate Edit Pet modal (call this from pet profile page or via a button with data attributes)
    function openEditPet(petId, petName, sex, weight, categoryId, feedingTime, feedingPortion, behavioralNotes) {
        document.getElementById('edit_pet_id').value           = petId;
        document.getElementById('edit_pet_name').value         = petName;
        document.getElementById('edit_pet_sex').value          = sex;
        document.getElementById('edit_pet_weight').value       = weight;
        document.getElementById('edit_pet_category').value     = categoryId;
        document.getElementById('edit_pet_feeding_time').value   = feedingTime   || '';
        document.getElementById('edit_pet_feeding_portion').value = feedingPortion || '';
        document.getElementById('edit_pet_notes').value        = behavioralNotes || '';
        openModal('editPetModal');
    }
</script>

</body>
</html>
