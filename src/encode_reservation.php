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
// FORM PROCESSING
// ════════════════════════════════════════════════════════════════
$message      = '';
$msg_type     = 'error'; // 'success' | 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $owner_id         = filter_input(INPUT_POST, 'owner_id',         FILTER_VALIDATE_INT);
    $pet_id           = filter_input(INPUT_POST, 'pet_id',           FILTER_VALIDATE_INT);
    $accommodation_id = filter_input(INPUT_POST, 'accommodation_id', FILTER_VALIDATE_INT);
    $check_in         = filter_input(INPUT_POST, 'check_in',         FILTER_SANITIZE_STRING);
    $check_out        = filter_input(INPUT_POST, 'check_out',        FILTER_SANITIZE_STRING);
    $instructions     = trim(filter_input(INPUT_POST, 'instructions', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
    $today            = date('Y-m-d');

    // Collect selected services
    $selected_services = $_POST['services'] ?? [];
    $selected_services = array_filter(array_map('intval', $selected_services));

    // Payment fields
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    if (!in_array($payment_method, ['Cash', 'GCash', 'Bank'], true)) {
        $payment_method = 'Cash';
    }
    $total_amount = filter_var($_POST['total_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
    $total_amount = max(0, (float)$total_amount);

    if (!$owner_id || !$pet_id || !$accommodation_id || !$check_in || !$check_out
        || $check_in < $today || $check_out <= $check_in) {
        $message = 'Please complete all required fields with valid dates.';
    } else {
        $val = $pdo->prepare("SELECT COUNT(*) FROM PET WHERE PET_ID = :pet AND OWNER_ID = :owner");
        $val->execute(['pet' => $pet_id, 'owner' => $owner_id]);
        if ((int) $val->fetchColumn() !== 1) {
            $message = 'Selected pet does not belong to the chosen owner.';
        } else {
            $avail = $pdo->prepare(
                "SELECT COUNT(*) FROM BOOKING
                 WHERE PET_ID = :pet AND BOOKING_STATUS = 'Confirmed'
                   AND (CHECK_IN_DATE  <= TO_DATE(:cout,'YYYY-MM-DD')
                    AND CHECK_OUT_DATE >= TO_DATE(:cin,'YYYY-MM-DD'))"
            );
            $avail->execute(['pet' => $pet_id, 'cin' => $check_in, 'cout' => $check_out]);
            if ((int) $avail->fetchColumn() > 0) {
                $message = 'This pet already has a confirmed booking during the selected dates.';
            } else {
                $unit_q = $pdo->prepare("SELECT OCCUPANCY_STATUS FROM ACCOMMODATION WHERE ACCOMMODATION_ID = :id FOR UPDATE");
                $unit_q->execute(['id' => $accommodation_id]);
                $unit_status = $unit_q->fetchColumn();

                if ($unit_status !== 'Available') {
                    $allow = isAdmin() && !empty($_POST['override']);
                    if (!$allow) $message = 'The selected accommodation unit is no longer available.';
                }

                if (empty($message)) {
                    try {
                        $pdo->beginTransaction();
                        $id_q      = $pdo->query("SELECT NVL(MAX(BOOKING_ID),0)+1 FROM BOOKING");
                        $booking_id = (int) $id_q->fetchColumn();
                        $emp_id    = $_SESSION['employee_id'] ?? null;
                        if (!$emp_id) {
                            $e_q = $pdo->prepare("SELECT EMPLOYEE_ID FROM USER_ACCOUNT WHERE ACCOUNT_ID = :id");
                            $e_q->execute(['id' => $_SESSION['account_id']]);
                            $emp_id = $e_q->fetchColumn() ?: 0;
                        }
                        $ins = $pdo->prepare(
                            "INSERT INTO BOOKING (
                                BOOKING_ID, BOOKING_STATUS, CHECK_IN_DATE, CHECK_OUT_DATE,
                                SPECIAL_INSTRUCTIONS, OWNER_ID, PET_ID, EMPLOYEE_ID, ACCOMMODATION_ID,
                                CONSENT_FORM_SIGNED, NEXGARD_VERIFIED, OCULAR_EXAM_PASSED, VETCARD_VERIFIED,
                                TOTAL_AMOUNT, PAYMENT_METHOD, DATE_OF_PAYMENT
                             ) VALUES (
                                :id, 'Confirmed', TO_DATE(:cin,'YYYY-MM-DD'), TO_DATE(:cout,'YYYY-MM-DD'),
                                :instr, :owner, :pet, :emp, :acc,
                                'No', 'No', 'No', 'No',
                                :total_amount, :payment_method, SYSDATE
                             )"
                        );
                        $ins->execute([
                            'id'             => $booking_id,
                            'cin'            => $check_in,
                            'cout'           => $check_out,
                            'instr'          => $instructions ?: null,
                            'owner'          => $owner_id,
                            'pet'            => $pet_id,
                            'emp'            => $emp_id,
                            'acc'            => $accommodation_id,
                            'total_amount'   => $total_amount,
                            'payment_method' => $payment_method
                        ]);
                        $upd = $pdo->prepare("UPDATE ACCOMMODATION SET OCCUPANCY_STATUS = 'Booked' WHERE ACCOMMODATION_ID = :id");
                        $upd->execute(['id' => $accommodation_id]);

                        if (!empty($selected_services)) {
                            // Fetch prices for selected service IDs
                            $placeholders = implode(',', array_fill(0, count($selected_services), '?'));
                            $svc_price_stmt = $pdo->prepare(
                                "SELECT SERVICE_ID, PRICE FROM SERVICE WHERE SERVICE_ID IN ($placeholders)"
                            );
                            $svc_price_stmt->execute($selected_services);
                            $svc_prices = $svc_price_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

                            $svc_ins = $pdo->prepare(
                                "INSERT INTO SERVICE_DETAILS (BOOKING_ID, SERVICE_ID, QUANTITY, SERVICE_CHARGE)
                                 VALUES (:booking_id, :service_id, 1, :charge)"
                            );

                            foreach ($selected_services as $svc_id) {
                                $svc_ins->execute([
                                    'booking_id' => $booking_id,
                                    'service_id' => $svc_id,
                                    'charge'     => $svc_prices[$svc_id] ?? 0,
                                ]);
                            }
                        }

                        $pdo->commit();
                        header('Location: encode_reservation.php?success=1');
                        exit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $message = 'Transaction failed: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// ════════════════════════════════════════════════════════════════
// DATA FOR DROPDOWNS & SERVICES
// ════════════════════════════════════════════════════════════════
$owners_stmt = $pdo->query("SELECT OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER FROM OWNER ORDER BY LAST_NAME ASC");
$owners      = $owners_stmt->fetchAll(PDO::FETCH_ASSOC);

$pets_stmt   = $pdo->query("SELECT PET_ID, OWNER_ID, PET_NAME, WEIGHT FROM PET ORDER BY PET_NAME ASC");
$all_pets    = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

$acc_stmt    = $pdo->query("
    SELECT A.ACCOMMODATION_ID, A.UNIT_NAME, A.OCCUPANCY_STATUS,
           T.WEIGHT_MIN, T.WEIGHT_MAX, T.TIER_NAME, T.DAILY_RATE
    FROM ACCOMMODATION A
    JOIN TIER T ON A.TIER_ID = T.TIER_ID
    ORDER BY T.WEIGHT_MIN ASC, A.UNIT_NAME ASC
");
$all_accommodations = $acc_stmt->fetchAll(PDO::FETCH_ASSOC);

$svc_stmt = $pdo->query(
    "SELECT SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE
     FROM SERVICE
     ORDER BY SERVICE_NAME ASC"
);
$all_services = $svc_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Encode Reservation — Radog's Kennel</title>

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

        .nav-list { list-style: none; display: flex; flex-direction: column; gap: 3px; animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.1s both; }

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

        .page-title   { font-size: 2.4rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            margin-bottom: 28px; padding: 14px 18px;
            border-radius: var(--radius-input); font-size: 0.9rem;
        }

        .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

        /* ── Structural Layout Restructure ── */
        .form-grid {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .form-grid-top {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        @media (max-width: 900px) {
            .form-grid-top { grid-template-columns: 1fr; }
        }

        /* Panel / Card */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-card);
        }

        .panel-header {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 24px; padding-bottom: 16px;
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

        .panel-subtext {
            font-size: 0.88rem;
            color: rgba(34,34,34,0.55);
            margin-top: -16px;
            margin-bottom: 24px;
        }

        /* Form fields */
        .field-wrap { margin-bottom: 20px; }
        .field-wrap:last-child { margin-bottom: 0; }

        .field-label {
            display: block; font-size: 0.75rem; font-weight: 600;
            letter-spacing: 0.12em; text-transform: uppercase;
            color: #4a3f33; margin-bottom: 8px;
        }

        .field-wrap select,
        .field-wrap input[type="date"],
        .field-wrap textarea {
            width: 100%; padding: 13px 16px;
            border: 1.5px solid #e2d9ce; border-radius: var(--radius-input);
            background: var(--beige); color: var(--black);
            font-family: 'DM Sans', sans-serif; font-size: 0.95rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            appearance: none;
        }

        .field-wrap select:focus,
        .field-wrap input[type="date"]:focus,
        .field-wrap textarea:focus {
            border-color: var(--orange);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
            outline: none;
            background: var(--white);
        }

        .field-wrap select:disabled,
        .field-wrap input:disabled {
            opacity: 0.5; cursor: not-allowed;
            background: rgba(34,34,34,0.04);
        }

        .field-wrap textarea { resize: vertical; min-height: 100px; }

        /* Field hint */
        .field-hint {
            font-size: 0.78rem; color: rgba(34,34,34,0.45);
            margin-top: 5px; display: flex; align-items: center; gap: 5px;
        }

        .field-hint svg { width: 13px; height: 13px; flex-shrink: 0; }

        /* Override toggle (admin only) */
        .override-row {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 16px;
            background: rgba(250,129,18,0.06);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: var(--radius-input);
        }

        .toggle-wrap { position: relative; flex-shrink: 0; }

        .toggle-wrap input[type="checkbox"] {
            width: 40px; height: 22px; appearance: none;
            background: rgba(34,34,34,0.15); border-radius: 99px;
            cursor: pointer; transition: background 0.2s;
            position: relative;
        }

        .toggle-wrap input[type="checkbox"]::after {
            content: ''; position: absolute;
            left: 3px; top: 3px;
            width: 16px; height: 16px;
            background: var(--white); border-radius: 50%;
            transition: transform 0.2s;
        }

        .toggle-wrap input[type="checkbox"]:checked { background: var(--orange); }
        .toggle-wrap input[type="checkbox"]:checked::after { transform: translateX(18px); }

        .override-label { font-size: 0.85rem; color: rgba(34,34,34,0.7); line-height: 1.4; }
        .override-label strong { color: var(--orange); }

        /* Submit button */
        .btn-submit {
            width: 100%; padding: 16px;
            border: none; border-radius: var(--radius-btn);
            background: var(--black); color: var(--white);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.1rem; letter-spacing: 0.12em;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 10px;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }

        .btn-submit svg { width: 18px; height: 18px; }

        .btn-submit:not(:disabled):hover {
            background: var(--orange);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(250,129,18,0.3);
        }

        .btn-submit:disabled {
            opacity: 0.45; cursor: not-allowed;
        }

        .submit-row {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        /* ── Add-On Services Visual Design ── */
        .service-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }

        .service-card {
            display: flex; align-items: center; gap: 14px;
            padding: 16px 18px;
            border: 1.5px solid #e2d9ce; border-radius: 14px;
            background: var(--beige); cursor: pointer;
            transition: border-color 0.18s, background 0.18s, box-shadow 0.18s;
            user-select: none;
        }

        .service-card:hover { border-color: var(--orange); background: #fff8f0; }

        .service-card input[type="checkbox"] {
            width: 18px; height: 18px; flex-shrink: 0;
            accent-color: var(--orange);
            cursor: pointer;
        }

        .service-card:has(input:checked) {
            border-color: var(--orange);
            background: rgba(250,129,18,0.06);
            box-shadow: 0 0 0 3px rgba(250,129,18,0.12);
        }

        .service-card-body { flex-grow: 1; }
        .service-card-name { font-weight: 600; font-size: 0.92rem; color: var(--black); margin-bottom: 2px; }
        .service-card-desc { font-size: 0.78rem; color: rgba(34,34,34,0.5); line-height: 1.4; }
        .service-card-price { font-weight: 700; font-size: 0.95rem; color: var(--orange); white-space: nowrap; flex-shrink: 0; }

        .free-badge {
            font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.08em;
            font-size: 0.8rem; padding: 2px 8px; border-radius: 99px;
            background: rgba(34,197,94,0.12); color: #16a34a;
        }

        /* ── Payment Visual Design ── */
        .payment-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; align-items: start; }
        @media (max-width: 700px) { .payment-grid { grid-template-columns: 1fr; } }

        .payment-method-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }

        .method-card {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 18px; border-radius: var(--radius-btn);
            border: 1.5px solid #e2d9ce; background: var(--beige);
            cursor: pointer; font-size: 0.9rem; font-weight: 500;
            transition: border-color 0.18s, background 0.18s;
        }

        .method-card:has(input:checked) {
            border-color: var(--orange); background: rgba(250,129,18,0.07);
            font-weight: 600;
        }

        .method-card input[type="radio"] { accent-color: var(--orange); }

        .cost-breakdown {
            background: var(--beige); border: var(--border-soft);
            border-radius: 14px; padding: 20px 22px;
        }

        .cost-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; font-size: 0.92rem; }
        .cost-label { color: rgba(34,34,34,0.6); }
        .cost-val { font-weight: 500; color: var(--black); }
        .cost-divider { height: 1px; background: rgba(34,34,34,0.08); margin: 8px 0; }

        .cost-total {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.2rem; letter-spacing: 0.06em;
            color: var(--orange); padding-top: 12px;
        }

        /* Step indicator dots */
        .step-row {
            display: flex; align-items: center; gap: 0;
            margin-bottom: 28px;
        }

        .step {
            display: flex; flex-direction: column; align-items: center; gap: 6px;
            flex: 1;
        }

        .step-dot {
            width: 28px; height: 28px; border-radius: 50%;
            background: rgba(34,34,34,0.1);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Bebas Neue', sans-serif; font-size: 0.85rem; color: rgba(34,34,34,0.4);
            transition: background 0.3s, color 0.3s;
        }

        .step.done .step-dot  { background: var(--orange); color: var(--white); }
        .step.active .step-dot { background: var(--black); color: var(--white); }

        .step-label {
            font-size: 0.68rem; font-weight: 600;
            letter-spacing: 0.1em; text-transform: uppercase;
            color: rgba(34,34,34,0.4);
            transition: color 0.3s;
        }

        .step.done .step-label  { color: var(--orange); }
        .step.active .step-label { color: var(--black); }

        .step-line {
            flex: 1; height: 2px;
            background: rgba(34,34,34,0.1);
            margin-bottom: 20px;
            transition: background 0.3s;
        }

        .step-line.done { background: var(--orange); }

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

    <div class="nav-section-label">Navigation</div>
    <ul class="nav-list">

        <?php if (isAdmin()): ?>
            <li><a href="admin_dashboard.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/></svg>
                Dashboard
            </a></li>
        <?php else: ?>
            <li><a href="staff_dashboard.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0h6"/></svg>
                Dashboard
            </a></li>
        <?php endif; ?>

        <li><a href="encode_reservation.php" class="nav-link active">
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
        <?php if (isAdmin()): ?>
            <li><a href="user_management.php" class="nav-link">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                User Management
            </a></li>
        <?php endif; ?>

    </ul>

    <div class="sidebar-spacer"></div>
    <div class="sidebar-divider"></div>

    <a href="../index.php" class="logout-btn">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
        </svg>
        Logout
    </a>

</aside>

<main class="main-content">

    <div class="page-header">
        <div class="page-eyebrow">Reservations</div>
        <h1 class="page-title">New Reservation</h1>
        <p class="page-subtitle">Encode a booking from Messenger chat or walk-in inquiry</p>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
        <div class="alert alert-success" style="margin-bottom: 24px;">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Reservation confirmed successfully. The accommodation has been marked as Booked.
        </div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div class="alert alert-error">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
            </svg>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="step-row" id="stepRow">
    <div class="step active" id="step1">
        <div class="step-dot">1</div>
        <span class="step-label">Owner</span>
    </div>
    <div class="step-line" id="line1"></div>
    <div class="step" id="step2">
        <div class="step-dot">2</div>
        <span class="step-label">Pet</span>
    </div>
    <div class="step-line" id="line2"></div>
    <div class="step" id="step3">
        <div class="step-dot">3</div>
        <span class="step-label">Unit</span>
    </div>
    <div class="step-line" id="line3"></div>
    <div class="step" id="step4">
        <div class="step-dot">4</div>
        <span class="step-label">Dates</span>
    </div>
    <div class="step-line" id="line4"></div>
    <div class="step" id="step5">
        <div class="step-dot">5</div>
        <span class="step-label">Services</span>
    </div>
    <div class="step-line" id="line5"></div>
    <div class="step" id="step6">
        <div class="step-dot">6</div>
        <span class="step-label">Payment</span>
    </div>
</div>

    <form action="encode_reservation.php" method="POST" id="reservationForm">
        <div class="form-grid">

            <div class="form-grid-top">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                        </div>
                        <span class="panel-heading">Customer & Accommodation</span>
                    </div>

                    <div class="field-wrap">
                        <label class="field-label" for="ownerSelect">Owner</label>
                        <select id="ownerSelect" name="owner_id" required onchange="filterPets()">
                            <option value="">— Choose an owner —</option>
                            <?php foreach ($owners as $o): ?>
                                <option value="<?php echo $o['OWNER_ID']; ?>">
                                    <?php echo htmlspecialchars($o['LAST_NAME'] . ', ' . $o['FIRST_NAME'] . ' (' . $o['CONTACT_NUMBER'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-wrap">
                        <label class="field-label" for="petSelect">Pet</label>
                        <select id="petSelect" name="pet_id" required disabled onchange="filterAccommodations()">
                            <option value="">— Select an owner first —</option>
                        </select>
                        <div class="field-hint">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                            Filtered by selected owner
                        </div>
                    </div>

                    <div class="field-wrap">
                        <label class="field-label" for="accommodationSelect">Available Unit</label>
                        <select id="accommodationSelect" name="accommodation_id" required disabled>
                            <option value="">— Select a pet first —</option>
                        </select>
                        <div class="field-hint">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                            Auto-filtered by pet weight and tier
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-icon">
                            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <span class="panel-heading">Booking Details</span>
                    </div>

                    <div class="field-wrap">
                        <label class="field-label" for="checkIn">Check-In Date</label>
                        <input type="date" id="checkIn" name="check_in" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>

                    <div class="field-wrap">
                        <label class="field-label" for="checkOut">Check-Out Date</label>
                        <input type="date" id="checkOut" name="check_out" required>
                    </div>

                    <div class="field-wrap">
                        <label class="field-label" for="instructions">Special Instructions <span style="font-weight:400;opacity:0.5;">(optional)</span></label>
                        <textarea id="instructions" name="instructions" placeholder="Allergies, feeding notes, behavioral flags…"></textarea>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-icon">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <span class="panel-heading">Add-On Services</span>
                </div>
                <p class="panel-subtext">Optional. Select any services to include with this booking. Prices will be added to the total.</p>

                <div class="service-grid">
                    <?php foreach ($all_services as $svc): ?>
                        <label class="service-card" for="svc_<?php echo $svc['SERVICE_ID']; ?>">
                            <input type="checkbox"
                                   id="svc_<?php echo $svc['SERVICE_ID']; ?>"
                                   name="services[]"
                                   value="<?php echo $svc['SERVICE_ID']; ?>"
                                   data-price="<?php echo $svc['PRICE']; ?>"
                                   data-name="<?php echo htmlspecialchars($svc['SERVICE_NAME']); ?>"
                                   onchange="recalcTotal()">
                            <div class="service-card-body">
                                <div class="service-card-name"><?php echo htmlspecialchars($svc['SERVICE_NAME']); ?></div>
                                <div class="service-card-desc"><?php echo htmlspecialchars($svc['SERVICE_DESCRIPTION']); ?></div>
                            </div>
                            <div class="service-card-price">
                                <?php echo $svc['PRICE'] > 0 ? '₱' . number_format($svc['PRICE'], 2) : '<span class="free-badge">FREE</span>'; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div class="panel-icon">
                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                        </svg>
                    </div>
                    <span class="panel-heading">Payment</span>
                </div>

                <div class="payment-grid">
                    <div class="payment-left">
                        <label class="field-label">Payment Method</label>
                        <div class="payment-method-row">
                            <label class="method-card" for="pay_cash">
                                <input type="radio" id="pay_cash" name="payment_method" value="Cash" checked onchange="recalcTotal()">
                                <span>Cash</span>
                            </label>
                            <label class="method-card" for="pay_gcash">
                                <input type="radio" id="pay_gcash" name="payment_method" value="GCash" onchange="recalcTotal()">
                                <span>GCash</span>
                            </label>
                            <label class="method-card" for="pay_bank">
                                <input type="radio" id="pay_bank" name="payment_method" value="Bank" onchange="recalcTotal()">
                                <span>Bank Transfer</span>
                            </label>
                        </div>
                    </div>

                    <div class="payment-right">
                        <div class="cost-breakdown">
                            <div class="cost-row">
                                <span class="cost-label">Accommodation</span>
                                <span class="cost-val" id="costAccommodation">₱0.00</span>
                            </div>
                            <div class="cost-row" id="costServicesRow" style="display:none;">
                                <span class="cost-label">Services</span>
                                <span class="cost-val" id="costServices">₱0.00</span>
                            </div>
                            <div class="cost-divider"></div>
                            <div class="cost-row cost-total">
                                <span>Total Amount</span>
                                <span id="costTotal">₱0.00</span>
                            </div>
                        </div>
                        <input type="hidden" name="total_amount" id="totalAmountInput" value="0">
                    </div>
                </div>
            </div>

            <div class="submit-row">
                <button type="submit" id="submitBtn" class="btn btn-primary btn-submit" disabled>
                    <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Confirm &amp; Pay Reservation
                </button>
               
            </div>

        </div>
    </form>

</main>

<script>
    const petsData          = <?php echo json_encode($all_pets); ?>;
    const accommodationsData = <?php echo json_encode($all_accommodations); ?>;

    // Accommodation rate data passed from PHP
    const accommodationRates = <?php
        $rates = [];
        foreach ($all_accommodations as $a) {
            $rates[$a['ACCOMMODATION_ID']] = [
                'daily_rate' => (float)($a['DAILY_RATE'] ?? 0),
                'tier'       => $a['TIER_NAME'],
                'unit'       => $a['UNIT_NAME'],
            ];
        }
        echo json_encode($rates);
    ?>;

    const petSelect = document.getElementById('petSelect');
    const accSelect = document.getElementById('accommodationSelect');
    const submitBtn = document.getElementById('submitBtn');

    /* ── Step indicator ─────────────────────────────── */
    if(document.getElementById('stepRow')) {
        document.getElementById('ownerSelect').addEventListener('change', validateForm);
        petSelect.addEventListener('change', validateForm);
        accSelect.addEventListener('change', validateForm);
    }

   function updateSteps() {
    if (!document.getElementById('stepRow')) return;
    
    // 1. Define conditions for each step
    const hasOwner  = !!document.getElementById('ownerSelect').value;
    const hasPet    = !!petSelect.value;
    const hasUnit   = !!accSelect.value;
    const hasDates  = !!(document.getElementById('checkIn').value && document.getElementById('checkOut').value);
    
    // Services are optional, so we'll mark them as 'done' if Dates are valid
    const hasServices = hasDates; 
    const hasPayment  = hasDates; // Payment method is defaulted to Cash, so it's always 'set' once dates are in

    // 2. Apply step logic
    // We only light up the next step if the previous one is finished
    setStep('step1', 'line1', hasOwner, true);
    setStep('step2', 'line2', hasPet,   hasOwner);
    setStep('step3', 'line3', hasUnit,  hasPet);
    setStep('step4', 'line4', hasDates, hasUnit);
    setStep('step5', 'line5', hasServices, hasDates);
    setStep('step6', null,    hasPayment, hasServices);
}

    function setStep(stepId, lineId, isDone, isActive) {
        const s = document.getElementById(stepId);
        if(!s) return;
        s.classList.toggle('done',   isDone);
        s.classList.toggle('active', !isDone && isActive);
        const l = document.getElementById(lineId);
        if (l) l.classList.toggle('done', isDone);
    }

    /* ── Filter pets by owner ───────────────────────── */
    function filterPets() {
        const ownerId = document.getElementById('ownerSelect').value;

        petSelect.innerHTML = '<option value="">— Select a pet —</option>';
        accSelect.innerHTML = '<option value="">— Select a pet first —</option>';
        accSelect.disabled  = true;

        if (!ownerId) { petSelect.disabled = true; validateForm(); recalcTotal(); return; }

        const filtered = petsData.filter(p => p.OWNER_ID == ownerId);
        if (filtered.length > 0) {
            filtered.forEach(p => {
                const opt = document.createElement('option');
                opt.value       = p.PET_ID;
                opt.textContent = `${p.PET_NAME} (${p.WEIGHT} kg)`;
                petSelect.appendChild(opt);
            });
            petSelect.disabled = false;
        } else {
            petSelect.innerHTML = '<option value="">— No pets found for this owner —</option>';
            petSelect.disabled  = true;
        }
        validateForm();
        recalcTotal();
    }

    /* ── Filter accommodations by pet weight ────────── */
    function filterAccommodations() {
        accSelect.innerHTML = '<option value="">— Select a unit —</option>';
        const petId = petSelect.value;

        if (!petId) { accSelect.disabled = true; validateForm(); recalcTotal(); return; }

        const pet    = petsData.find(p => p.PET_ID == petId);
        const weight = parseFloat(pet.WEIGHT);

        const valid = accommodationsData.filter(a =>
            a.OCCUPANCY_STATUS === 'Available' &&
            weight >= parseFloat(a.WEIGHT_MIN) &&
            weight <= parseFloat(a.WEIGHT_MAX)
        );

        if (valid.length > 0) {
            valid.forEach(a => {
                const opt = document.createElement('option');
                opt.value       = a.ACCOMMODATION_ID;
                opt.textContent = `${a.UNIT_NAME} — ${a.TIER_NAME} Tier`;
                accSelect.appendChild(opt);
            });
            accSelect.disabled = false;
        } else {
            accSelect.innerHTML = '<option value="">— No available units for this pet size —</option>';
            accSelect.disabled  = true;
        }
        validateForm();
        recalcTotal();
    }

    /* ── Real-time Financial Breakdown Matrix ────────── */
    function recalcTotal() {
        // 1. Get number of nights
        const cin  = document.getElementById('checkIn').value;
        const cout = document.getElementById('checkOut').value;
        let nights = 0;
        if (cin && cout) {
            const d1 = new Date(cin), d2 = new Date(cout);
            nights = Math.max(0, Math.round((d2 - d1) / 86400000));
        }

        // 2. Get daily rate for selected accommodation
        const accId = accSelect.value;
        const rate  = accId && accommodationRates[accId] ? accommodationRates[accId].daily_rate : 0;
        const accTotal = nights * rate;

        // 3. Sum selected services
        let svcTotal = 0;
        document.querySelectorAll('input[name="services[]"]:checked').forEach(cb => {
            svcTotal += parseFloat(cb.dataset.price) || 0;
        });

        // 4. Update breakdown display
        document.getElementById('costAccommodation').textContent = '₱' + accTotal.toLocaleString('en-PH', {minimumFractionDigits:2});
        const svcRow = document.getElementById('costServicesRow');
        if (svcTotal > 0) {
            svcRow.style.display = 'flex';
            document.getElementById('costServices').textContent = '₱' + svcTotal.toLocaleString('en-PH', {minimumFractionDigits:2});
        } else {
            svcRow.style.display = 'none';
        }
        const grand = accTotal + svcTotal;
        document.getElementById('costTotal').textContent = '₱' + grand.toLocaleString('en-PH', {minimumFractionDigits:2});
        document.getElementById('totalAmountInput').value = grand.toFixed(2);
    }

    /* ── Validate & enable submit ───────────────────── */
    function validateForm() {
        updateSteps();

        const ownerId  = document.getElementById('ownerSelect').value;
        const checkIn  = document.getElementById('checkIn').value;
        const checkOut = document.getElementById('checkOut').value;

        const today    = new Date(); today.setHours(0,0,0,0);
        const inDate   = new Date(checkIn);
        const outDate  = new Date(checkOut);
        const datesOk  = checkIn && inDate >= today && checkOut && outDate > inDate;

        submitBtn.disabled = !(ownerId && petSelect.value && accSelect.value && datesOk);
    }

    // Attach real-time mathematical recalculations to form change triggers
    document.getElementById('checkIn').addEventListener('change', function() { validateForm(); recalcTotal(); });
    document.getElementById('checkOut').addEventListener('change', function() { validateForm(); recalcTotal(); });
    accSelect.addEventListener('change', function() { validateForm(); recalcTotal(); });
</script>
</body>
</html>