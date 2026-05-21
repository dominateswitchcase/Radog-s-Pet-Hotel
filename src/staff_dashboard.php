<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// ════════════════════════════════════════════════════════════════
// RBAC: Staff only
// ════════════════════════════════════════════════════════════════
requireStaff();

$display_name = htmlspecialchars($_SESSION['username'] ?? 'Staff');

// ════════════════════════════════════════════════════════════════
// AUTO-SYNC ACCOMMODATION STATUS
// Runs on every page load before anything is displayed.
// Sets status to 'Booked' if a confirmed booking exists for today
// or ongoing, and resets to 'Available' if no active booking exists.
// 'Under Maintenance' is never auto-changed — only staff can set/clear it.
// ════════════════════════════════════════════════════════════════
try {
    // Step 1: Mark units as 'Booked' if they have a confirmed booking
    // that is currently active (check-in has passed, check-out has not)
    $pdo->exec(
        "UPDATE ACCOMMODATION
         SET OCCUPANCY_STATUS = 'Booked'
         WHERE OCCUPANCY_STATUS != 'Under Maintenance'
           AND ACCOMMODATION_ID IN (
               SELECT ACCOMMODATION_ID FROM BOOKING
               WHERE BOOKING_STATUS = 'Confirmed'
                 AND TRUNC(CHECK_IN_DATE)  <= TRUNC(SYSDATE)
                 AND TRUNC(CHECK_OUT_DATE) >  TRUNC(SYSDATE)
           )"
    );

    // Step 2: Reset to 'Available' any unit that is marked 'Booked'
    // but has no active confirmed booking anymore (pet checked out, 
    // booking cancelled, or no current booking covers today)
    $pdo->exec(
        "UPDATE ACCOMMODATION
         SET OCCUPANCY_STATUS = 'Available'
         WHERE OCCUPANCY_STATUS = 'Booked'
           AND ACCOMMODATION_ID NOT IN (
               SELECT ACCOMMODATION_ID FROM BOOKING
               WHERE BOOKING_STATUS = 'Confirmed'
                 AND TRUNC(CHECK_IN_DATE)  <= TRUNC(SYSDATE)
                 AND TRUNC(CHECK_OUT_DATE) >  TRUNC(SYSDATE)
           )"
    );
} catch (PDOException $e) {
    // Non-fatal — log silently, don't break the page
    error_log('Auto-sync accommodation status failed: ' . $e->getMessage());
}

// ════════════════════════════════════════════════════════════════
// HANDLE ACCOMMODATION STATUS UPDATE (manual by staff)
// ════════════════════════════════════════════════════════════════
$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_unit_status') {
    $unit_id    = filter_input(INPUT_POST, 'accommodation_id', FILTER_VALIDATE_INT);
    $new_status = $_POST['occupancy_status'] ?? '';

    if ($unit_id && in_array($new_status, ['Available', 'Booked', 'Under Maintenance'], true)) {
        try {
            // ── CONSTRAINT: Block update if unit has an active confirmed booking ──
            // Staff cannot manually change status of a unit that currently has a
            // pet booked in it. The auto-sync above handles Booked/Available
            // transitions automatically. Manual updates are only for setting
            // 'Under Maintenance' or clearing it back to 'Available'.
            $conflict_check = $pdo->prepare(
                "SELECT COUNT(*) FROM BOOKING
                 WHERE ACCOMMODATION_ID = :id
                   AND BOOKING_STATUS   = 'Confirmed'
                   AND TRUNC(CHECK_IN_DATE)  <= TRUNC(SYSDATE)
                   AND TRUNC(CHECK_OUT_DATE) >  TRUNC(SYSDATE)"
            );
            $conflict_check->execute(['id' => $unit_id]);
            $has_active_booking = (int) $conflict_check->fetchColumn() > 0;

            if ($has_active_booking) {
                // Unit currently has a pet — block any manual status change.
                // The only exception would be admin override, but staff
                // cannot do this.
                $error_message = 'Cannot update: this unit currently has an active guest. 
                                  Status is managed automatically while a pet is booked in.';
            } else {
                // No active booking — staff may set it to 'Under Maintenance'
                // or back to 'Available' freely.
                $upd = $pdo->prepare(
                    "UPDATE ACCOMMODATION SET OCCUPANCY_STATUS = :status WHERE ACCOMMODATION_ID = :id"
                );
                $upd->execute(['status' => $new_status, 'id' => $unit_id]);
                $success_message = 'Unit status updated to "' . $new_status . '" successfully.';
            }
        } catch (PDOException $e) {
            $error_message = 'Database error: ' . $e->getMessage();
        }
    } else {
        $error_message = 'Invalid status selected.';
    }
}
// ════════════════════════════════════════════════════════════════
// FETCH METRICS
// ════════════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════════════
// FETCH METRICS
// ════════════════════════════════════════════════════════════════
$checkin_stmt = $pdo->prepare("SELECT COUNT(*) AS TOTAL FROM BOOKING WHERE TRUNC(CHECK_IN_DATE) = TRUNC(SYSDATE)");
$checkin_stmt->execute();
$checkins_today = $checkin_stmt->fetch(PDO::FETCH_ASSOC)['TOTAL'];

$checkout_stmt = $pdo->prepare("SELECT COUNT(*) AS TOTAL FROM BOOKING WHERE TRUNC(CHECK_OUT_DATE) = TRUNC(SYSDATE)");
$checkout_stmt->execute();
$checkouts_today = $checkout_stmt->fetch(PDO::FETCH_ASSOC)['TOTAL'];

$status_stmt = $pdo->query("SELECT OCCUPANCY_STATUS, COUNT(*) as COUNT FROM ACCOMMODATION GROUP BY OCCUPANCY_STATUS");
$acc_status  = ['Available' => 0, 'Booked' => 0, 'Under Maintenance' => 0];
while ($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
    $acc_status[$row['OCCUPANCY_STATUS']] = $row['COUNT'];
}

$total_units = array_sum($acc_status);

// ── FIXED JOIN: was incorrectly joining B.ACCOMMODATION_ID = B.BOOKING_ID ──
// Now correctly joins on B.ACCOMMODATION_ID = A.ACCOMMODATION_ID
// and limits to active bookings only (today falls within check-in/check-out)
$units_stmt = $pdo->query("
    SELECT A.ACCOMMODATION_ID,
           A.UNIT_NAME,
           T.TIER_NAME,
           A.OCCUPANCY_STATUS,
           P.PET_NAME
    FROM ACCOMMODATION A
    JOIN TIER T ON A.TIER_ID = T.TIER_ID
    LEFT JOIN BOOKING B
           ON A.ACCOMMODATION_ID    = B.ACCOMMODATION_ID
          AND B.BOOKING_STATUS      = 'Confirmed'
          AND TRUNC(B.CHECK_IN_DATE)  <= TRUNC(SYSDATE)
          AND TRUNC(B.CHECK_OUT_DATE) >  TRUNC(SYSDATE)
    LEFT JOIN PET P ON B.PET_ID = P.PET_ID
    ORDER BY A.UNIT_NAME ASC
");
$units = $units_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard — Radog's Kennel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">

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
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 24px;
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

        .page-title { font-size: 2.4rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            margin-bottom: 24px; padding: 14px 18px;
            border-radius: var(--radius-input); font-size: 0.9rem;
        }

        .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

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

        /* orange left accent bar */
        .stat-card::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 4px;
            border-radius: 4px 0 0 4px;
            background: var(--orange);
        }

        .stat-card.green::before  { background: #22c55e; }
        .stat-card.amber::before  { background: #f59e0b; }
        .stat-card.orange::before { background: var(--orange); }

        .stat-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 16px;
        }

        .stat-icon svg { width: 20px; height: 20px; }

        .stat-icon.green  { background: rgba(34,197,94,0.12);  color: #16a34a; }
        .stat-icon.amber  { background: rgba(245,158,11,0.12); color: #d97706; }
        .stat-icon.orange { background: rgba(250,129,18,0.12); color: var(--orange); }

        .stat-label {
            font-size: 0.72rem; font-weight: 600;
            letter-spacing: 0.14em; text-transform: uppercase;
            color: rgba(34,34,34,0.45); margin-bottom: 8px;
        }

        .stat-value {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 3rem; color: var(--black); line-height: 1;
        }

        /* accommodation mini breakdown */
        .acc-breakdown {
            display: flex; flex-direction: column; gap: 8px; margin-top: 4px;
        }

        .acc-row {
            display: flex; align-items: center; justify-content: space-between;
            font-size: 0.85rem;
        }

        .acc-row-label {
            display: flex; align-items: center; gap: 7px;
            color: rgba(34,34,34,0.6);
        }

        .acc-dot {
            width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
        }

        .dot-available   { background: #22c55e; }
        .dot-booked      { background: var(--orange); }
        .dot-maintenance { background: #94a3b8; }

        .acc-count {
            font-weight: 600; color: var(--black);
            font-size: 0.9rem;
        }

        /* ══════════════════════════════════════════════════════
           PANEL / TABLE
        ══════════════════════════════════════════════════════ */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-card);
            overflow: hidden;
        }

        .panel-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 24px 28px 20px;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }

        .panel-header-left {
            display: flex; align-items: center; gap: 12px;
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

        .panel-count {
            font-size: 0.8rem; font-weight: 600;
            background: rgba(250,129,18,0.1);
            color: var(--orange);
            padding: 3px 10px; border-radius: 99px;
            letter-spacing: 0.04em;
        }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; }

        .data-table thead tr {
            background: rgba(250,129,18,0.04);
        }

        .data-table th {
            padding: 12px 20px;
            font-size: 0.72rem; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase;
            color: rgba(34,34,34,0.45);
            text-align: left;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }

        .data-table th:last-child { text-align: right; }

        .data-table tbody tr {
            border-bottom: 1px solid rgba(34,34,34,0.05);
            transition: background 0.15s;
        }

        .data-table tbody tr:last-child { border-bottom: none; }
        .data-table tbody tr:hover { background: rgba(250,129,18,0.03); }

        .data-table td {
            padding: 16px 20px;
            font-size: 0.92rem;
            color: var(--black);
            vertical-align: middle;
        }

        .data-table td:last-child { text-align: right; }

        .unit-name { font-weight: 600; }

        .tier-badge {
            display: inline-block;
            padding: 3px 10px; border-radius: 99px;
            font-size: 0.75rem; font-weight: 500;
            background: rgba(34,34,34,0.06);
            color: rgba(34,34,34,0.7);
            letter-spacing: 0.04em;
        }

        /* Status badges */
        .status-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 99px;
            font-size: 0.78rem; font-weight: 600; letter-spacing: 0.04em;
        }

        .status-dot { width: 6px; height: 6px; border-radius: 50%; }

        .status-available    { background: rgba(34,197,94,0.12);   color: #16a34a; }
        .status-available .status-dot    { background: #22c55e; }

        .status-booked       { background: rgba(250,129,18,0.12);  color: var(--orange-dk); }
        .status-booked .status-dot       { background: var(--orange); box-shadow: 0 0 5px var(--orange); }

        .status-maintenance  { background: rgba(148,163,184,0.15); color: #475569; }
        .status-maintenance .status-dot  { background: #94a3b8; }

        .guest-empty { color: rgba(34,34,34,0.35); font-style: italic; font-size: 0.88rem; }

        /* Update button */
        .btn-update {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border-radius: var(--radius-btn);
            background: transparent;
            border: 1.5px solid rgba(34,34,34,0.15);
            color: var(--black);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.82rem; letter-spacing: 0.1em;
            cursor: pointer;
            transition: background 0.18s, border-color 0.18s, color 0.18s;
        }

        .btn-update svg { width: 14px; height: 14px; }
        .btn-update:hover { background: var(--orange); border-color: var(--orange); color: var(--white); }

        /* ══════════════════════════════════════════════════════
           MODAL
        ══════════════════════════════════════════════════════ */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(34,34,34,0.55);
            backdrop-filter: blur(3px);
            z-index: 100;
            align-items: center; justify-content: center;
        }

        .modal-overlay.open { display: flex; }

        .modal-box {
            background: var(--white);
            border-radius: var(--radius-card);
            width: min(100%, 440px);
            box-shadow: 0 32px 80px rgba(15,23,42,0.18);
            overflow: hidden;
            animation: fadeUp 0.35s cubic-bezier(0.16,1,0.3,1) both;
        }

        .modal-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 22px 28px;
            background: var(--black);
            background-image: repeating-linear-gradient(
                -55deg, transparent, transparent 18px,
                rgba(250,129,18,0.05) 18px, rgba(250,129,18,0.05) 19px
            );
        }

        .modal-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.3rem; color: var(--white); letter-spacing: 0.06em;
        }

        .modal-close {
            background: none; border: none; cursor: pointer;
            color: rgba(245,231,198,0.6);
            display: flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 8px;
            transition: background 0.18s, color 0.18s;
        }

        .modal-close svg { width: 18px; height: 18px; }
        .modal-close:hover { background: rgba(250,129,18,0.2); color: var(--white); }

        .modal-body { padding: 28px; }

        .field-wrap { margin-bottom: 20px; }
        .field-wrap:last-child { margin-bottom: 0; }

        .field-label {
            display: block; font-size: 0.75rem; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase;
            color: #4a3f33; margin-bottom: 8px;
        }

        .field-wrap input[type="text"],
        .field-wrap select {
            width: 100%; padding: 13px 16px;
            border: 1.5px solid #e2d9ce; border-radius: var(--radius-input);
            background: var(--beige); color: var(--black);
            font-family: 'DM Sans', sans-serif; font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
        }

        .field-wrap input[readonly] { opacity: 0.65; cursor: not-allowed; }

        .field-wrap select:focus,
        .field-wrap input:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            outline: none;
        }

        .modal-notice {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 16px; margin-top: 20px;
            background: rgba(245,158,11,0.08);
            border: 1px solid rgba(245,158,11,0.25);
            border-radius: var(--radius-input);
            font-size: 0.83rem; color: #92400e; line-height: 1.5;
        }

        .modal-notice svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }

        .modal-foot {
            display: flex; align-items: center; justify-content: flex-end; gap: 12px;
            padding: 20px 28px;
            border-top: 1px solid rgba(34,34,34,0.07);
        }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px; border: none; border-radius: var(--radius-btn);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem; letter-spacing: 0.12em;
            cursor: pointer;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }

        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 15px; height: 15px; }

        .btn-cancel {
            background: transparent; color: var(--black);
            border: 1.5px solid rgba(34,34,34,0.18);
        }

        .btn-cancel:hover { background: rgba(34,34,34,0.05); border-color: rgba(34,34,34,0.3); }

        .btn-save { background: var(--black); color: var(--white); }
        .btn-save:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }

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
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .stats-grid { grid-template-columns: 1fr; }
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
            <?php echo strtoupper(substr($_SESSION['username'] ?? 'S', 0, 1)); ?>
        </div>
        <div>
            <div class="sidebar-user-name"><?php echo $display_name; ?></div>
            <div class="sidebar-user-role">Staff</div>
        </div>
    </div>

    <div class="nav-section-label">Navigation</div>
    <ul class="nav-list">
        <li><a href="staff_dashboard.php" class="nav-link active">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/></svg>
            Dashboard
        </a></li>
        <li><a href="encode_reservation.php" class="nav-link">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            Schedule
        </a></li>
        <li><a href="calendar-unified.php" class="nav-link">
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
        <!-- <li><a href="checkout.php" class="nav-link">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Checkout / Payments
        </a></li> -->
    
    <div class="sidebar-spacer"></div>
    <div class="sidebar-divider"></div>

    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════════ -->
<main class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-eyebrow">Operations</div>
        <h1 class="page-title">Staff Dashboard</h1>
        <p class="page-subtitle">Welcome back, <?php echo $display_name; ?>. Here's today's kennel overview.</p>
    </div>

    <!-- Alerts -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
            </svg>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- ── Stat Cards ── -->
    <div class="stats-grid">

        <!-- Check-ins today -->
        <div class="stat-card green">
            <div class="stat-icon green">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="stat-label">Today's Check-Ins</div>
            <div class="stat-value"><?php echo $checkins_today; ?></div>
        </div>

        <!-- Check-outs today -->
        <div class="stat-card amber">
            <div class="stat-icon amber">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
            </div>
            <div class="stat-label">Today's Check-Outs</div>
            <div class="stat-value"><?php echo $checkouts_today; ?></div>
        </div>

        <!-- Accommodation status -->
        <div class="stat-card orange">
            <div class="stat-icon orange">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/>
                </svg>
            </div>
            <div class="stat-label">Accommodation Units</div>
            <div class="stat-value" style="font-size:2.2rem; margin-bottom:10px;"><?php echo $total_units; ?> <span style="font-size:1rem; color:rgba(34,34,34,0.4); letter-spacing:0.08em;">total</span></div>
            <div class="acc-breakdown">
                <div class="acc-row">
                    <span class="acc-row-label"><span class="acc-dot dot-available"></span> Available</span>
                    <span class="acc-count"><?php echo $acc_status['Available']; ?></span>
                </div>
                <div class="acc-row">
                    <span class="acc-row-label"><span class="acc-dot dot-booked"></span> Booked</span>
                    <span class="acc-count"><?php echo $acc_status['Booked']; ?></span>
                </div>
                <div class="acc-row">
                    <span class="acc-row-label"><span class="acc-dot dot-maintenance"></span> Maintenance</span>
                    <span class="acc-count"><?php echo $acc_status['Under Maintenance']; ?></span>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Accommodation Units Table ── -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-header-left">
                <div class="panel-icon">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/>
                    </svg>
                </div>
                <span class="panel-heading">Accommodation Units</span>
                <span class="panel-count"><?php echo count($units); ?> units</span>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Unit Name</th>
                        <th>Tier</th>
                        <th>Status</th>
                        <th>Current Guest</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($units as $unit): ?>
                        <?php
                            $s = strtolower(str_replace(' ', '-', $unit['OCCUPANCY_STATUS']));
                            // normalise "under-maintenance" → "maintenance"
                            if ($s === 'under-maintenance') $s = 'maintenance';
                        ?>
                        <tr>
                            <td><span class="unit-name"><?php echo htmlspecialchars($unit['UNIT_NAME']); ?></span></td>
                            <td><span class="tier-badge"><?php echo htmlspecialchars($unit['TIER_NAME']); ?></span></td>
                            <td>
                                <span class="status-badge status-<?php echo $s; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo htmlspecialchars($unit['OCCUPANCY_STATUS']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($unit['PET_NAME']): ?>
                                    <?php echo htmlspecialchars($unit['PET_NAME']); ?>
                                <?php else: ?>
                                    <span class="guest-empty">Empty</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn-update"
                                        data-unitid="<?php echo $unit['ACCOMMODATION_ID']; ?>"
                                        data-unitname="<?php echo htmlspecialchars($unit['UNIT_NAME']); ?>"
                                        data-unitstatus="<?php echo htmlspecialchars($unit['OCCUPANCY_STATUS']); ?>"
                                        onclick="openModal(this)">
                                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    Update
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- ══════════════════════════════════════════════════════
     MODAL — Update Unit Status
══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="updateModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Update Unit Status</span>
            <button class="modal-close" onclick="closeModal()">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <form action="staff_dashboard.php" method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="update_unit_status">
                <input type="hidden" name="accommodation_id" id="modalUnitId">

                <div class="field-wrap">
                    <label class="field-label">Unit Name</label>
                    <input type="text" id="modalUnitName" readonly>
                </div>

                <div class="field-wrap">
                    <label class="field-label">Occupancy Status</label>
                    <select name="occupancy_status" id="modalUnitStatus" required>
                        <option value="Available">Available</option>
                        <option value="Booked">Booked</option>
                        <option value="Under Maintenance">Under Maintenance</option>
                    </select>
                </div>

                <div class="modal-notice">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                    </svg>
                    Ensure the physical unit matches this status before saving.
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-save">
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:15px;height:15px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(btn) {
        document.getElementById('modalUnitId').value     = btn.dataset.unitid;
        document.getElementById('modalUnitName').value   = btn.dataset.unitname;
        document.getElementById('modalUnitStatus').value = btn.dataset.unitstatus;
        document.getElementById('updateModal').classList.add('open');
    }

    function closeModal() {
        document.getElementById('updateModal').classList.remove('open');
    }

    // Close on overlay click
    document.getElementById('updateModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });
</script>
</body>
</html>