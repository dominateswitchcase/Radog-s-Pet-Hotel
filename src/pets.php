<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// Session guard
requireLogin();
$role = $_SESSION['role'];

// 1. Strict Variable Initialization
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';

// Fetch all categories dynamically for the dropdown
$cat_stmt = $pdo->query("SELECT CATEGORY_ID, CATEGORY_NAME FROM PET_CATEGORY ORDER BY CATEGORY_ID ASC");
$all_categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Base Query
$queryStr = "SELECT P.PET_ID, P.PET_NAME, P.SEX, P.WEIGHT, P.FEEDING_TIME, P.FEEDING_PORTION, P.BEHAVIORAL_NOTES,
             C.CATEGORY_NAME, O.FIRST_NAME, O.LAST_NAME
             FROM PET P
             JOIN OWNER O ON P.OWNER_ID = O.OWNER_ID
             JOIN PET_CATEGORY C ON P.CATEGORY_ID = C.CATEGORY_ID
             WHERE 1=1";

$params = [];

// 2. Safe Search Binding
if ($search !== '') {
    $queryStr .= " AND LOWER(P.PET_NAME) LIKE LOWER(:search)";
    $params['search'] = '%' . $search . '%';
}

// 3. Strict Numeric Validation
if ($category_filter !== 'all' && is_numeric($category_filter)) {
    $queryStr .= " AND P.CATEGORY_ID = :category";
    $params['category'] = (int)$category_filter;
}

$queryStr .= " ORDER BY P.PET_NAME ASC";
$stmt = $pdo->prepare($queryStr);

try {
    $stmt->execute($params);
} catch (PDOException $e) {
    die("Database Query Error: " . $e->getMessage());
}

$pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pet Profiles – Radog's Pet Hotel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        /* ── CSS Variables ───────────────────────────────────── */
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

        /* ── Reset & Base ────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--beige);
            display: flex;
            min-height: 100vh;
            color: var(--black);
        }
        h1, h2, h3, h4, h5 { font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.05em; }
        a { text-decoration: none; }

        /* ── Animation ───────────────────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ══════════════════════════════════════════════════════
           SIDEBAR
        ══════════════════════════════════════════════════════ */
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

        /* Brand */
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 24px;
            animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both;
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
            line-height: 1;
            letter-spacing: 0.04em;
            text-shadow: 0 0 18px rgba(250,129,18,0.4);
        }
        .sidebar-wordmark-sub {
            font-size: 0.68rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--gold);
            opacity: 0.8;
            margin-top: 3px;
        }

        /* User badge */
        .sidebar-user {
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
            animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.2s both;
        }
        .sidebar-avatar {
            width: 34px;
            height: 34px;
            background: var(--orange);
            border-radius: 50%;
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
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-user-role {
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--orange);
            margin-top: 2px;
        }

        /* Nav */
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
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex-grow: 1;
            animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.3s both;
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
        .nav-link svg { width: 17px; height: 17px; opacity: 0.8; flex-shrink: 0; }
        .nav-link:hover { background: rgba(250,129,18,0.1); color: var(--white); }
        .nav-link.active { background: var(--orange); color: var(--white); font-weight: 600; }
        .nav-link.active svg { opacity: 1; }

        /* Logout */
        .sidebar-footer { margin-top: auto; padding-top: 20px; }
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
        }
        .logout-btn:hover { background: rgba(250,129,18,0.12); border-color: var(--orange); color: var(--white); }
        .logout-btn svg { width: 16px; height: 16px; }

        /* ══════════════════════════════════════════════════════
           MAIN CONTENT
        ══════════════════════════════════════════════════════ */
        .main-content {
            flex-grow: 1;
            padding: 40px 44px;
            overflow-y: auto;
            animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both;
        }

        /* Page header */
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

        /* ── Alerts ──────────────────────────────────────────── */
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

        /* ── Filter Bar ──────────────────────────────────────── */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .search-wrap {
            position: relative;
            flex-grow: 1;
            max-width: 460px;
        }
        .search-wrap svg {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 16px;
            height: 16px;
            color: rgba(34,34,34,0.35);
            pointer-events: none;
        }
        .search-wrap input {
            width: 100%;
            padding: 13px 16px 13px 42px;
            border: 1.5px solid #e2d9ce;
            border-radius: var(--radius-input);
            background: var(--beige);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            color: var(--black);
            transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
            appearance: none;
        }
        .search-wrap input:focus {
            outline: none;
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            background: var(--white);
        }
        .search-wrap input::placeholder { color: rgba(34,34,34,0.35); }

        select.filter-select {
            padding: 13px 16px;
            border: 1.5px solid #e2d9ce;
            border-radius: var(--radius-input);
            background: var(--beige);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            color: var(--black);
            cursor: pointer;
            transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
            min-width: 160px;
            appearance: none;
        }
        select.filter-select:focus {
            outline: none;
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            background: var(--white);
        }

        /* ── Buttons ─────────────────────────────────────────── */
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
            text-decoration: none;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
            white-space: nowrap;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 16px; height: 16px; }
        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }
        .btn-danger  { background: var(--orange); color: var(--white); }
        .btn-danger:hover  { background: var(--orange-dk); box-shadow: 0 6px 20px rgba(250,129,18,0.35); }
        .btn-ghost {
            background: transparent;
            color: var(--black);
            border: 1.5px solid rgba(34,34,34,0.18);
        }
        .btn-ghost:hover { background: rgba(34,34,34,0.04); border-color: var(--orange); }
        .btn-sm { padding: 8px 14px; font-size: 0.82rem; }

        /* ── Panel / Table ───────────────────────────────────── */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 22px 28px 18px;
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

        /* Table */
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
        .data-table th.center { text-align: center; }
        .data-table tbody tr { border-bottom: 1px solid rgba(34,34,34,0.05); transition: background 0.15s; }
        .data-table tbody tr:hover { background: rgba(250,129,18,0.03); }
        .data-table td { padding: 16px 20px; font-size: 0.92rem; vertical-align: middle; }
        .td-center { text-align: center; }
        .pet-name { font-weight: 600; color: var(--black); }

        /* Species badge */
        .badge-species {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .badge-dog  { background: rgba(250,129,18,0.1); color: #c05f00; }
        .badge-dog  .badge-dot { background: #FA8112; }
        .badge-cat  { background: rgba(99,102,241,0.1); color: #4338ca; }
        .badge-cat  .badge-dot { background: #6366f1; }
        .badge-other { background: rgba(34,34,34,0.07); color: #4a3f33; }
        .badge-other .badge-dot { background: #999; }

        /* Actions */
        .actions { display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; }

        /* Empty state */
        .empty-state { padding: 64px 24px; text-align: center; color: rgba(34,34,34,0.35); }
        .empty-state svg { width: 48px; height: 48px; margin-bottom: 14px; opacity: 0.3; }
        .empty-state p { font-size: 0.92rem; }

        /* ══════════════════════════════════════════════════════
           MODAL
        ══════════════════════════════════════════════════════ */
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
            border-radius: 20px;
            width: min(100%, 520px);
            box-shadow: 0 32px 80px rgba(15,23,42,0.18);
            overflow: hidden;
            animation: fadeUp 0.35s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
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
        .modal-body { padding: 28px; }
        .modal-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            padding: 20px 28px;
            border-top: 1px solid rgba(34,34,34,0.07);
        }

        /* Form fields inside modal */
        .field-group { margin-bottom: 18px; }
        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #4a3f33;
            margin-bottom: 8px;
        }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .modal-body input,
        .modal-body select,
        .modal-body textarea {
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
        .modal-body input:focus,
        .modal-body select:focus,
        .modal-body textarea:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            outline: none;
            background: var(--white);
        }
        .modal-body textarea { resize: vertical; min-height: 80px; }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .search-wrap { max-width: 100%; }
            .field-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

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

    <p class="nav-section-label">Main Menu</p>
    <ul class="nav-list">
        <li>
            <?php if (isAdmin()): ?>
            <a href="admin_dashboard.php" class="nav-link">
            <?php else: ?>
            <a href="staff_dashboard.php" class="nav-link">
            <?php endif; ?>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
        </li>
        <li>
            <a href="encode_reservation.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
                Schedule
            </a>
        </li>
        <li>
            <a href="calendar-unified.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Calendar
            </a>
        </li>
        <li>
            <a href="owner.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Owners
            </a>
        </li>
        <li>
            <a href="pets.php" class="nav-link active">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>
                Pets
            </a>
        </li>
        <?php if (isAdmin()): ?>
        <li>
            <a href="user_management.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                User Management
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<main class="main-content">

    <div class="page-eyebrow">Pet Registry</div>
    <h1 class="page-title">Pet Profiles</h1>
    <p class="page-subtitle">Centralized pet registry and historical data</p>

    <form action="pets.php" method="GET">
        <div class="filter-bar">
            <div class="search-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by pet name…">
            </div>

            <select name="category" class="filter-select" onchange="this.form.submit()">
                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                <?php foreach ($all_categories as $cat): ?>
                    <option value="<?php echo $cat['CATEGORY_ID']; ?>" <?php echo $category_filter == $cat['CATEGORY_ID'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['CATEGORY_NAME']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                Search
            </button>
        </div>
    </form>

    <div class="panel">
        <div class="panel-header">
            <div class="panel-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>
            </div>
            <span class="panel-heading">Registered Pets</span>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Pet Name</th>
                    <th>Species</th>
                    <th>Owner</th>
                    <th class="center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pets)): ?>
                <tr>
                    <td colspan="4">
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <p>No pet records found matching your criteria.</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($pets as $row): ?>
                <tr>
                    <td class="pet-name"><?php echo htmlspecialchars($row['PET_NAME']); ?></td>
                    <td>
                        <?php
                            $cat = strtolower($row['CATEGORY_NAME']);
                            $badgeClass = 'badge-other';
                            if ($cat === 'dog') $badgeClass = 'badge-dog';
                            elseif ($cat === 'cat') $badgeClass = 'badge-cat';
                        ?>
                        <span class="badge-species <?php echo $badgeClass; ?>">
                            <span class="badge-dot"></span>
                            <?php echo htmlspecialchars($row['CATEGORY_NAME']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($row['FIRST_NAME'] . ' ' . $row['LAST_NAME']); ?></td>
                    <td class="td-center">
                        <div class="actions">
                            <a href="pet_profile.php?id=<?php echo $row['PET_ID']; ?>" class="btn btn-sm btn-ghost">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                Details
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>


</body>
</html>