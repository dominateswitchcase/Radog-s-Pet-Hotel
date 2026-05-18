<?php
session_start();
require_once '../config/db.php';

// Check if the user is logged in
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

// Only User_Group_ID 1 (Admin) is allowed
if ($_SESSION['user_group_id'] != 1) {
    header("Location: staff_dashboard.php");
    exit();
}

// Handle AJAX requests for CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    // ==========================================================
    // JELLYACE NEW FUNCTION: Create User
    // ==========================================================
    if ($_POST['action'] === 'create_user') {
        $emp_username = trim(filter_input(INPUT_POST, 'emp_username', FILTER_SANITIZE_STRING));
        $account_username = trim(filter_input(INPUT_POST, 'account_username', FILTER_SANITIZE_STRING));
        $password = $_POST['password'] ?? '';
        $group_id = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

        if ($emp_username && $account_username && $password && $group_id && in_array($status, ['Active', 'Inactive'])) {
            try {
                $pdo->beginTransaction();

                // 1. Generate Next IDs
                $emp_id_stmt = $pdo->query("SELECT NVL(MAX(EMPLOYEE_ID), 0) + 1 FROM EMPLOYEE");
                $new_emp_id = $emp_id_stmt->fetchColumn();

                $acc_id_stmt = $pdo->query("SELECT NVL(MAX(ACCOUNT_ID), 0) + 1 FROM USER_ACCOUNT");
                $new_acc_id = $acc_id_stmt->fetchColumn();

                // 2. Hash Password (SECURITY COMPLIANCE)
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // 3. Insert into EMPLOYEE Table
                $insert_emp = $pdo->prepare("INSERT INTO EMPLOYEE (EMPLOYEE_ID, EMPLOYEE_USERNAME, PASSWORD_HASH) VALUES (:id, :e_user, :pass)");
                $insert_emp->execute([
                    'id'     => $new_emp_id,
                    'e_user' => $emp_username,
                    'pass'   => $hashed_password
                ]);

                // 4. Insert into USER_ACCOUNT Table
                $insert_acc = $pdo->prepare("INSERT INTO USER_ACCOUNT (ACCOUNT_ID, USERNAME, ACCOUNT_STATUS, PASSWORD_HASH, EMPLOYEE_ID, USER_GROUP_ID) VALUES (:id, :a_user, :status, :pass, :emp_id, :grp_id)");
                $insert_acc->execute([
                    'id'     => $new_acc_id,
                    'a_user' => $account_username,
                    'status' => $status,
                    'pass'   => $hashed_password,
                    'emp_id' => $new_emp_id,
                    'grp_id' => $group_id
                ]);

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'User successfully registered.';
            } catch (Exception $e) {
                $pdo->rollBack();
                if (strpos($e->getMessage(), 'ORA-00001') !== false) {
                    $response['message'] = 'Error: Username or Employee Ref already exists.';
                } else {
                    $response['message'] = 'Database Error: ' . $e->getMessage();
                }
            }
        } else {
            $response['message'] = 'Please fill out all required fields properly.';
        }
        echo json_encode($response);
        exit();
    }
    // ==========================================================

    elseif ($_POST['action'] === 'get_user') {
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        if ($account_id) {
            $stmt = $pdo->prepare(
                "SELECT UA.ACCOUNT_ID, UA.USERNAME, UA.ACCOUNT_STATUS, UA.USER_GROUP_ID,
                        E.EMPLOYEE_ID, E.EMPLOYEE_USERNAME, UG.GROUP_NAME
                 FROM USER_ACCOUNT UA
                 JOIN USER_GROUP UG ON UA.USER_GROUP_ID = UG.USER_GROUP_ID
                 JOIN EMPLOYEE E ON UA.EMPLOYEE_ID = E.EMPLOYEE_ID
                 WHERE UA.ACCOUNT_ID = :id"
            );
            $stmt->execute(['id' => $account_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $response['success'] = true;
                $response['data'] = $user;
            } else {
                $response['message'] = 'User not found';
            }
        }
        echo json_encode($response);
        exit();

    } elseif ($_POST['action'] === 'update_user') {
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        $username   = filter_input(INPUT_POST, 'username',   FILTER_SANITIZE_STRING);
        $status     = filter_input(INPUT_POST, 'status',     FILTER_SANITIZE_STRING);
        $group_id   = filter_input(INPUT_POST, 'group_id',   FILTER_VALIDATE_INT);

        if ($account_id && $username && in_array($status, ['Active', 'Inactive']) && $group_id) {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE USER_ACCOUNT SET USERNAME = :username, ACCOUNT_STATUS = :status, USER_GROUP_ID = :group_id
                     WHERE ACCOUNT_ID = :id"
                );
                $stmt->execute([
                    'username' => $username,
                    'status'   => $status,
                    'group_id' => $group_id,
                    'id'       => $account_id
                ]);
                $response['success'] = true;
                $response['message'] = 'User updated successfully';
            } catch (Exception $e) {
                $response['message'] = 'Failed to update user: ' . $e->getMessage();
            }
        } else {
            $response['message'] = 'Invalid input data';
        }
        echo json_encode($response);
        exit();

    } elseif ($_POST['action'] === 'delete_user') {
        $account_id = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);
        if ($account_id) {
            $stmt   = $pdo->prepare("DELETE FROM USER_ACCOUNT WHERE ACCOUNT_ID = :id");
            $result = $stmt->execute(['id' => $account_id]);
            if ($result) {
                $response['success'] = true;
                $response['message'] = 'User deleted successfully';
            } else {
                $response['message'] = 'Failed to delete user';
            }
        } else {
            $response['message'] = 'Invalid account ID';
        }
        echo json_encode($response);
        exit();
    }
}

// Fetch User Accounts from Oracle
$query = "SELECT UA.ACCOUNT_ID, UA.USERNAME, UA.ACCOUNT_STATUS,
                 E.EMPLOYEE_ID, E.EMPLOYEE_USERNAME,
                 UG.GROUP_NAME, UA.USER_GROUP_ID
          FROM USER_ACCOUNT UA
          JOIN USER_GROUP UG ON UA.USER_GROUP_ID = UG.USER_GROUP_ID
          JOIN EMPLOYEE E ON UA.EMPLOYEE_ID = E.EMPLOYEE_ID
          ORDER BY UA.ACCOUNT_ID ASC";

$stmt  = $pdo->query($query);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user groups for dropdown
$groups_stmt = $pdo->query("SELECT USER_GROUP_ID, GROUP_NAME FROM USER_GROUP");
$user_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);

$role = $_SESSION['group_name'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management – Radog's Pet Hotel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        /* ─── Reset & Variables ──────────────────────────────── */
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
        h1, h2, h3, h4, h5 { font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.05em; }
        a { text-decoration: none; }

        /* ─── Animation ──────────────────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ─── Sidebar ────────────────────────────────────────── */
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
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }
        .sidebar-logo img {
            width: 52px; height: 52px;
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

        /* User badge */
        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 28px;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.2s both;
        }
        .sidebar-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: var(--orange);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1rem;
            color: var(--white);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .sidebar-user-name { font-size: 0.88rem; font-weight: 600; color: var(--white); }
        .sidebar-user-role {
            font-size: 0.72rem;
            color: var(--orange);
            letter-spacing: 0.06em;
            text-transform: uppercase;
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
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.3s both;
        }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            border-radius: 12px;
            color: rgba(245,231,198,0.7);
            font-size: 0.92rem;
            transition: background 0.18s, color 0.18s;
        }
        .nav-link svg { width: 17px; height: 17px; opacity: 0.8; flex-shrink: 0; }
        .nav-link:hover { background: rgba(250,129,18,0.1); color: var(--white); }
        .nav-link.active { background: var(--orange); color: var(--white); font-weight: 600; }
        .nav-link.active svg { opacity: 1; }

        /* Logout */
        .sidebar-footer { margin-top: auto; padding-top: 16px; }
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
            transition: background 0.18s, color 0.18s, border-color 0.18s;
        }
        .logout-btn:hover { background: rgba(250,129,18,0.12); border-color: var(--orange); color: var(--white); }
        .logout-btn svg { width: 16px; height: 16px; }

        /* ─── Main Content ────────────────────────────────────── */
        .main-content {
            flex-grow: 1;
            padding: 40px 44px;
            overflow-y: auto;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
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
            width: 20px; height: 2px;
            background: var(--orange);
            border-radius: 99px;
        }
        .page-title { font-size: 2.4rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); margin-bottom: 32px; }

        /* ─── Buttons ─────────────────────────────────────────── */
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
        .btn-delete {
            background: transparent;
            color: #991b1b;
            border: 1.5px solid rgba(153,27,27,0.25);
        }
        .btn-delete:hover { background: #fef2f2; border-color: #f87171; }
        .btn-sm { padding: 8px 14px; font-size: 0.82rem; }

        /* ─── Panels ──────────────────────────────────────────── */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-card);
            margin-bottom: 20px;
        }
        .panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }
        .panel-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(250,129,18,0.1);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .panel-icon svg { width: 18px; height: 18px; color: var(--orange); }
        .panel-heading {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.3rem;
            color: var(--black);
            letter-spacing: 0.05em;
        }

        /* ─── Role Permission Cards ───────────────────────────── */
        .role-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .role-card {
            background: var(--beige);
            border: 1.5px solid rgba(34,34,34,0.08);
            border-radius: 14px;
            padding: 18px 20px;
        }
        .role-card-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1rem;
            letter-spacing: 0.06em;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }
        .role-card-title svg { width: 16px; height: 16px; flex-shrink: 0; }
        .role-card-title.admin  { color: var(--black); }
        .role-card-title.staff  { color: var(--orange); }
        .role-card p { font-size: 0.85rem; color: rgba(34,34,34,0.6); line-height: 1.55; }

        /* ─── Table ───────────────────────────────────────────── */
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
        .data-table td.center { text-align: center; }

        .acc-id { font-weight: 700; color: rgba(34,34,34,0.5); font-size: 0.85rem; }
        .username-cell { font-weight: 600; }
        .emp-ref { color: rgba(34,34,34,0.5); font-size: 0.88rem; }

        /* Role badge */
        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.04em;
        }
        .role-badge-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .role-admin { background: rgba(34,34,34,0.08); color: var(--black); }
        .role-admin .role-badge-dot { background: var(--black); }
        .role-staff { background: rgba(250,129,18,0.1); color: #c05f00; }
        .role-staff .role-badge-dot { background: var(--orange); }

        /* Status badge */
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
        .status-active   { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .status-active   .status-dot { background: #22c55e; }
        .status-inactive { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .status-inactive .status-dot { background: #ef4444; }

        /* Edit icon button */
        .icon-btn {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(250,129,18,0.08);
            border: 1.5px solid rgba(250,129,18,0.2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.18s, border-color 0.18s;
        }
        .icon-btn:hover { background: rgba(250,129,18,0.18); border-color: var(--orange); }
        .icon-btn svg { width: 15px; height: 15px; color: var(--orange); }

        /* Empty state */
        .empty-state { text-align: center; padding: 60px 24px; color: rgba(34,34,34,0.35); }
        .empty-state svg { width: 48px; height: 48px; margin-bottom: 14px; opacity: 0.25; }

        /* ─── Form Fields ─────────────────────────────────────── */
        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #4a3f33;
            margin-bottom: 8px;
        }
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
        input:disabled, select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: rgba(34,34,34,0.04);
        }

        /* Two-column form grid */
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        /* ─── Alerts ──────────────────────────────────────────── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 0.9rem;
            margin-top: 16px;
        }
        .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }

        /* ─── Modals ──────────────────────────────────────────── */
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
            width: min(100%, 460px);
            box-shadow: 0 32px 80px rgba(15,23,42,0.18);
            overflow: hidden;
            animation: fadeUp 0.35s cubic-bezier(0.16,1,0.3,1) both;
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
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
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
        .modal-foot-left { margin-right: auto; }

        /* ─── Responsive ──────────────────────────────────────── */
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .role-cards { grid-template-columns: 1fr; }
            .field-row  { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════
     SIDEBAR
═══════════════════════════════════════════════════════ -->
<aside class="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <img src="../img/radog_logo.png" alt="Radog's Kennel">
        </div>
        <div>
            <div class="sidebar-wordmark-top">Radog's Kennel</div>
            <div class="sidebar-wordmark-sub">Pet Hotel Management</div>
        </div>
    </div>

    <!-- User badge -->
    <div class="sidebar-user">
        <div class="sidebar-avatar">
            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
        </div>
        <div>
            <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
            <div class="sidebar-user-role"><?php echo htmlspecialchars($role); ?></div>
        </div>
    </div>

    <!-- Nav -->
    <p class="nav-section-label">Main Menu</p>
    <ul class="nav-list">
        <li>
            <a href="admin_dashboard.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                Dashboard
            </a>
        </li>
        <li>
            <a href="encode_reservation.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
                Schedule
            </a>
        </li>
        <li>
            <a href="calendar.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
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
            <a href="pets.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>
                Pets
            </a>
        </li>
        <li>
            <a href="checkout.php" class="nav-link">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Checkout / Payments
            </a>
        </li>
        <?php if (isset($_SESSION['user_group_id']) && $_SESSION['user_group_id'] == 1): ?>
        <li>
            <a href="user_management.php" class="nav-link active">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                User Management
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- Logout -->
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- ══════════════════════════════════════════════════════
     MAIN CONTENT
═══════════════════════════════════════════════════════ -->
<main class="main-content">

    <!-- Page header -->
    <div class="page-eyebrow">Administration</div>
    <h1 class="page-title">User Management</h1>
    <p class="page-subtitle">User security and account privilege oversight</p>

    <!-- Create user button -->
    <div style="margin-bottom: 24px;">
        <button type="button" class="btn btn-primary" id="openCreateModalBtn">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
            Create New User
        </button>
    </div>

    <!-- Role Permissions Guide -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <span class="panel-heading">Role Permissions Guide</span>
        </div>
        <div class="role-cards">
            <div class="role-card">
                <div class="role-card-title admin">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Administrator
                </div>
                <p>Full system access. Can view financial reports, modify core pricing, and manage user accounts and passwords.</p>
            </div>
            <div class="role-card">
                <div class="role-card-title staff">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Kennel Staff
                </div>
                <p>Operational access. Can encode reservations, manage the calendar, and view owner/pet profiles. No access to settings or revenue data.</p>
            </div>
        </div>
    </div>

    <!-- Employee Accounts Table -->
    <div class="panel" style="padding: 0; overflow: hidden;">
        <div class="panel-header" style="padding: 22px 28px 18px;">
            <div class="panel-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <span class="panel-heading">Employee Accounts</span>
        </div>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Account ID</th>
                    <th>Username</th>
                    <th>Employee Ref</th>
                    <th>Role Group</th>
                    <th>Status</th>
                    <th class="center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td class="acc-id">ACC-<?php echo str_pad($user['ACCOUNT_ID'], 3, '0', STR_PAD_LEFT); ?></td>
                    <td class="username-cell"><?php echo htmlspecialchars($user['USERNAME']); ?></td>
                    <td class="emp-ref"><?php echo htmlspecialchars($user['EMPLOYEE_USERNAME']); ?></td>
                    <td>
                        <?php $isAdmin = ($user['GROUP_NAME'] === 'Administrator'); ?>
                        <span class="role-badge <?php echo $isAdmin ? 'role-admin' : 'role-staff'; ?>">
                            <span class="role-badge-dot"></span>
                            <?php echo htmlspecialchars($user['GROUP_NAME']); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($user['ACCOUNT_STATUS'] === 'Active'): ?>
                            <span class="status-badge status-active">
                                <span class="status-dot"></span> Active
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">
                                <span class="status-dot"></span> Inactive
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="center">
                        <button type="button"
                                class="icon-btn edit-user-btn"
                                data-account-id="<?php echo $user['ACCOUNT_ID']; ?>"
                                title="Edit User">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            <p>No system users found.</p>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>


<!-- ══════════════════════════════════════════════════════
     MODAL: Create New User
═══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="createUserModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Register New User</span>
            <button class="modal-close" id="closeCreateModal">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="createUserForm">
                <div class="field-group">
                    <label class="field-label" for="newEmpUsername">Employee Full Name / Reference</label>
                    <input type="text" id="newEmpUsername" placeholder="e.g. John Doe" required>
                </div>
                <div class="field-group">
                    <label class="field-label" for="newAccountUsername">System Login Username</label>
                    <input type="text" id="newAccountUsername" placeholder="e.g. jdoe_admin" required>
                </div>
                <div class="field-group">
                    <label class="field-label" for="newPassword">Password</label>
                    <input type="password" id="newPassword" placeholder="Minimum 6 characters" required>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label" for="newUserGroup">Role Group</label>
                        <select id="newUserGroup" required>
                            <option value="" disabled selected>Select Role</option>
                            <?php foreach ($user_groups as $group): ?>
                                <option value="<?php echo $group['USER_GROUP_ID']; ?>">
                                    <?php echo htmlspecialchars($group['GROUP_NAME']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="newAccountStatus">Initial Status</label>
                        <select id="newAccountStatus" required>
                            <option value="Active" selected>Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div id="createModalMessage" style="display: none;"></div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" id="cancelCreateModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveNewUserBtn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                Register User
            </button>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════
     MODAL: Edit User
═══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-title">Edit User Account</span>
            <button class="modal-close" id="closeEditModal">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="editUserForm">
                <div class="field-group">
                    <label class="field-label" for="accountId">Account ID</label>
                    <input type="text" id="accountId" disabled>
                </div>
                <div class="field-group">
                    <label class="field-label" for="username">Username</label>
                    <input type="text" id="username" required>
                </div>
                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label" for="userGroup">Role Group</label>
                        <select id="userGroup" required>
                            <option value="">-- Select Role --</option>
                            <?php foreach ($user_groups as $group): ?>
                                <option value="<?php echo $group['USER_GROUP_ID']; ?>">
                                    <?php echo htmlspecialchars($group['GROUP_NAME']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="accountStatus">Account Status</label>
                        <select id="accountStatus" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div id="modalMessage" style="display: none;"></div>
            </form>
        </div>
        <div class="modal-foot">
            <div class="modal-foot-left">
                <button type="button" class="btn btn-delete" id="deleteUserBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Delete
                </button>
            </div>
            <button type="button" class="btn btn-ghost" id="cancelEditModal">Cancel</button>
            <button type="button" class="btn btn-primary" id="updateUserBtn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>


<script>
    document.addEventListener('DOMContentLoaded', function () {

        // ── Modal helpers ────────────────────────────────────
        function openModal(id) {
            document.getElementById(id).classList.add('open');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }

        // Close on overlay click
        document.getElementById('createUserModal').addEventListener('click', function (e) {
            if (e.target === this) closeModal('createUserModal');
        });
        document.getElementById('editUserModal').addEventListener('click', function (e) {
            if (e.target === this) closeModal('editUserModal');
        });

        // Close on Escape
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal('createUserModal');
                closeModal('editUserModal');
            }
        });

        // Wire close buttons
        document.getElementById('closeCreateModal').addEventListener('click',  () => closeModal('createUserModal'));
        document.getElementById('cancelCreateModal').addEventListener('click', () => closeModal('createUserModal'));
        document.getElementById('closeEditModal').addEventListener('click',    () => closeModal('editUserModal'));
        document.getElementById('cancelEditModal').addEventListener('click',   () => closeModal('editUserModal'));

        // Open create modal
        document.getElementById('openCreateModalBtn').addEventListener('click', function () {
            document.getElementById('createUserForm').reset();
            hideAlert('createModalMessage');
            openModal('createUserModal');
        });

        // ── Alert helpers ─────────────────────────────────────
        function showAlert(id, message, type) {
            const el = document.getElementById(id);
            const icon = type === 'success'
                ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
            el.innerHTML = icon + '<span>' + message + '</span>';
            el.className = 'alert alert-' + (type === 'success' ? 'success' : 'error');
            el.style.display = 'flex';
        }
        function hideAlert(id) {
            const el = document.getElementById(id);
            el.style.display = 'none';
        }

        // ── Create New User ───────────────────────────────────
        document.getElementById('saveNewUserBtn').addEventListener('click', function () {
            const form = document.getElementById('createUserForm');
            if (!form.checkValidity()) { form.reportValidity(); return; }

            const empUsername = document.getElementById('newEmpUsername').value;
            const accUsername = document.getElementById('newAccountUsername').value;
            const password    = document.getElementById('newPassword').value;
            const groupId     = document.getElementById('newUserGroup').value;
            const status      = document.getElementById('newAccountStatus').value;

            fetch('user_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=create_user&emp_username=${encodeURIComponent(empUsername)}&account_username=${encodeURIComponent(accUsername)}&password=${encodeURIComponent(password)}&group_id=${groupId}&status=${status}`
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('createModalMessage', data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('createModalMessage', data.message, 'error');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showAlert('createModalMessage', 'An error occurred while creating user.', 'error');
            });
        });

        // ── Edit User ─────────────────────────────────────────
        let currentAccountId = null;

        document.querySelectorAll('.edit-user-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                currentAccountId = this.getAttribute('data-account-id');
                loadUserData(currentAccountId);
                openModal('editUserModal');
            });
        });

        function loadUserData(accountId) {
            fetch('user_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_user&account_id=' + accountId
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const u = data.data;
                    document.getElementById('accountId').value    = 'ACC-' + String(u.ACCOUNT_ID).padStart(3, '0');
                    document.getElementById('username').value     = u.USERNAME;
                    document.getElementById('userGroup').value    = u.USER_GROUP_ID;
                    document.getElementById('accountStatus').value = u.ACCOUNT_STATUS;
                    hideAlert('modalMessage');
                } else {
                    showAlert('modalMessage', 'Error: ' + data.message, 'error');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showAlert('modalMessage', 'An error occurred while loading user data.', 'error');
            });
        }

        // Update user
        document.getElementById('updateUserBtn').addEventListener('click', function () {
            const form = document.getElementById('editUserForm');
            if (!form.checkValidity()) { form.reportValidity(); return; }

            const username = document.getElementById('username').value;
            const groupId  = document.getElementById('userGroup').value;
            const status   = document.getElementById('accountStatus').value;

            fetch('user_management.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=update_user&account_id=' + currentAccountId + '&username=' + encodeURIComponent(username) + '&group_id=' + groupId + '&status=' + status
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showAlert('modalMessage', 'User updated successfully!', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('modalMessage', 'Error: ' + data.message, 'error');
                }
            })
            .catch(err => {
                console.error('Error:', err);
                showAlert('modalMessage', 'An error occurred while updating user.', 'error');
            });
        });

        // Delete user
        document.getElementById('deleteUserBtn').addEventListener('click', function () {
            if (confirm('Are you sure you want to delete this user account? This action cannot be undone.')) {
                fetch('user_management.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=delete_user&account_id=' + currentAccountId
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showAlert('modalMessage', 'User deleted successfully!', 'success');
                        setTimeout(() => {
                            closeModal('editUserModal');
                            location.reload();
                        }, 1500);
                    } else {
                        showAlert('modalMessage', 'Error: ' + data.message, 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    showAlert('modalMessage', 'An error occurred while deleting user.', 'error');
                });
            }
        });

    }); // end DOMContentLoaded
</script>

</body>
</html>
