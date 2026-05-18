<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['account_id'])) {
    header('Location: employee_login.php');
    exit();
}

if (!isset($_SESSION['user_group_id']) || $_SESSION['user_group_id'] != 1) {
    header('Location: staff_dashboard.php');
    exit();
}

function escape($value) {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function columnExists(PDO $pdo, $table, $column) {
    $table = strtoupper($table);
    $column = strtoupper($column);
    $allowedTables = ['TIER', 'PET_CATEGORY', 'SERVICE'];
    $allowedColumns = ['STATUS'];
    if (!in_array($table, $allowedTables, true) || !in_array($column, $allowedColumns, true)) {
        return false;
    }
    $sql = "SELECT COUNT(*) FROM USER_TAB_COLUMNS WHERE TABLE_NAME = '$table' AND COLUMN_NAME = '$column'";
    $stmt = $pdo->query($sql);
    return (int) $stmt->fetchColumn() > 0;
}

function getNextId(PDO $pdo, $table, $column) {
    $table = strtoupper($table);
    $column = strtoupper($column);
    $allowedTables = ['ACCOMMODATION', 'TIER', 'PET_CATEGORY', 'SERVICE'];
    $allowedColumns = ['ACCOMMODATION_ID', 'TIER_ID', 'CATEGORY_ID', 'SERVICE_ID'];
    if (!in_array($table, $allowedTables, true) || !in_array($column, $allowedColumns, true)) {
        throw new InvalidArgumentException('Invalid table or column name for getNextId().');
    }
    $stmt = $pdo->query("SELECT NVL(MAX($column), 0) + 1 FROM $table");
    return (int) $stmt->fetchColumn();
}

$supportsStatus = [
    'TIER' => columnExists($pdo, 'TIER', 'STATUS'),
    'PET_CATEGORY' => columnExists($pdo, 'PET_CATEGORY', 'STATUS'),
    'SERVICE' => columnExists($pdo, 'SERVICE', 'STATUS'),
];

$allowedTabs = ['accommodation', 'tier', 'pet_category', 'service'];
$activeTab = isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true) ? $_GET['tab'] : 'accommodation';
$success = isset($_GET['success']) && $_GET['success'] === '1';
$formErrors = [
    'accommodation' => '',
    'tier' => '',
    'pet_category' => '',
    'service' => '',
    'account' => '',
    'password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $requestedTab = $_POST['tab'] ?? $activeTab;
    $redirectTab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'accommodation';
    $action = trim($_POST['action']);

    try {
        if ($action === 'add_accommodation') {
            $accommodationType = trim($_POST['accommodation_type'] ?? '');
            $tierId = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $unitName = trim($_POST['unit_name'] ?? '');
            if ($accommodationType === '' || !$tierId || $unitName === '') {
                $formErrors['accommodation'] = 'Please provide an accommodation type and select a tier.';
            } else {
                $newId = getNextId($pdo, 'ACCOMMODATION', 'ACCOMMODATION_ID');
                $insert = $pdo->prepare(
                    'INSERT INTO ACCOMMODATION (ACCOMMODATION_ID, UNIT_NAME, ACCOMMODATION_TYPE, OCCUPANCY_STATUS, TIER_ID) VALUES (:id, :unit, :type, :status, :tier)'
                );
                $insert->execute([
                    'id' => $newId,
                    'unit' => $unitName,
                    'type' => $accommodationType,
                    'status' => 'Available',
                    'tier' => $tierId,
                ]);
                header('Location: user_management.php?tab=accommodation&success=1');
                exit();
            }
        } elseif ($action === 'edit_accommodation') {
            $accId = filter_var($_POST['accommodation_id'] ?? '', FILTER_VALIDATE_INT);
            $accommodationType = trim($_POST['accommodation_type'] ?? '');
            $tierId = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $occupancyStatus = trim($_POST['occupancy_status'] ?? '');
            $allowedStatus = ['Available', 'Booked', 'Under Maintenance'];
            if (!$accId || $accommodationType === '' || !$tierId || !in_array($occupancyStatus, $allowedStatus, true)) {
                $formErrors['accommodation'] = 'Please complete the accommodation update form correctly.';
            } else {
                $update = $pdo->prepare(
                    'UPDATE ACCOMMODATION SET ACCOMMODATION_TYPE = :type, TIER_ID = :tier, OCCUPANCY_STATUS = :status WHERE ACCOMMODATION_ID = :id'
                );
                $update->execute([
                    'type' => $accommodationType,
                    'tier' => $tierId,
                    'status' => $occupancyStatus,
                    'id' => $accId,
                ]);
                header('Location: user_management.php?tab=accommodation&success=1');
                exit();
            }
        } elseif ($action === 'deactivate_accommodation') {
            $accId = filter_var($_POST['accommodation_id'] ?? '', FILTER_VALIDATE_INT);
            if (!$accId) {
                $formErrors['accommodation'] = 'Unable to deactivate accommodation record.';
            } else {
                $update = $pdo->prepare("UPDATE ACCOMMODATION SET OCCUPANCY_STATUS = 'Under Maintenance' WHERE ACCOMMODATION_ID = :id");
                $update->execute(['id' => $accId]);
                header('Location: user_management.php?tab=accommodation&success=1');
                exit();
            }
        } elseif ($action === 'add_tier') {
            $tierName = trim($_POST['tier_name'] ?? '');
            $description = trim($_POST['tier_description'] ?? '');
            $weightMin = filter_var($_POST['weight_min'] ?? '', FILTER_VALIDATE_FLOAT);
            $weightMax = filter_var($_POST['weight_max'] ?? '', FILTER_VALIDATE_FLOAT);
            $dailyRate = filter_var($_POST['daily_rate'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($tierName === '' || $weightMin === false || $weightMax === false || $dailyRate === false || $weightMax <= $weightMin) {
                $formErrors['tier'] = 'Please complete the tier form and ensure Weight Max is greater than Weight Min.';
            } else {
                $newId = getNextId($pdo, 'TIER', 'TIER_ID');
                if ($supportsStatus['TIER']) {
                    $insert = $pdo->prepare(
                        "INSERT INTO TIER (TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE, STATUS) VALUES (:id, :name, :desc, :min, :max, :rate, 'Active')"
                    );
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO TIER (TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE) VALUES (:id, :name, :desc, :min, :max, :rate)'
                    );
                }
                $insert->execute([
                    'id' => $newId,
                    'name' => $tierName,
                    'desc' => $description,
                    'min' => $weightMin,
                    'max' => $weightMax,
                    'rate' => $dailyRate,
                ]);
                header('Location: user_management.php?tab=tier&success=1');
                exit();
            }
        } elseif ($action === 'edit_tier') {
            $tierId = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $tierName = trim($_POST['tier_name'] ?? '');
            $description = trim($_POST['tier_description'] ?? '');
            $weightMin = filter_var($_POST['weight_min'] ?? '', FILTER_VALIDATE_FLOAT);
            $weightMax = filter_var($_POST['weight_max'] ?? '', FILTER_VALIDATE_FLOAT);
            $dailyRate = filter_var($_POST['daily_rate'] ?? '', FILTER_VALIDATE_FLOAT);
            $recordStatus = trim($_POST['status'] ?? 'Active');
            if (!$tierId || $tierName === '' || $weightMin === false || $weightMax === false || $dailyRate === false || $weightMax <= $weightMin) {
                $formErrors['tier'] = 'Please complete the tier update form and ensure the weight range is valid.';
            } else {
                $setFields = 'TIER_NAME = :name, TIER_DESCRIPTION = :desc, WEIGHT_MIN = :min, WEIGHT_MAX = :max, DAILY_RATE = :rate';
                if ($supportsStatus['TIER']) {
                    $setFields .= ', STATUS = :status';
                }
                $sql = "UPDATE TIER SET $setFields WHERE TIER_ID = :id";
                $update = $pdo->prepare($sql);
                $params = [
                    'name' => $tierName,
                    'desc' => $description,
                    'min' => $weightMin,
                    'max' => $weightMax,
                    'rate' => $dailyRate,
                    'id' => $tierId,
                ];
                if ($supportsStatus['TIER']) {
                    $params['status'] = in_array($recordStatus, ['Active', 'Inactive'], true) ? $recordStatus : 'Active';
                }
                $update->execute($params);
                header('Location: user_management.php?tab=tier&success=1');
                exit();
            }
        } elseif ($action === 'deactivate_tier') {
            $tierId = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $newStatus = trim($_POST['status'] ?? 'Inactive');
            if (!$tierId || !$supportsStatus['TIER']) {
                $formErrors['tier'] = 'Unable to update tier status because the database schema does not support it.';
            } else {
                $statusValue = in_array($newStatus, ['Active', 'Inactive'], true) ? $newStatus : 'Inactive';
                $update = $pdo->prepare('UPDATE TIER SET STATUS = :status WHERE TIER_ID = :id');
                $update->execute(['status' => $statusValue, 'id' => $tierId]);
                header('Location: user_management.php?tab=tier&success=1');
                exit();
            }
        } elseif ($action === 'add_pet_category') {
            $categoryName = trim($_POST['category_name'] ?? '');
            $speciesNotes = trim($_POST['species_notes'] ?? '');
            if ($categoryName === '') {
                $formErrors['pet_category'] = 'Category name cannot be empty.';
            } else {
                $newId = getNextId($pdo, 'PET_CATEGORY', 'CATEGORY_ID');
                if ($supportsStatus['PET_CATEGORY']) {
                    $insert = $pdo->prepare(
                        "INSERT INTO PET_CATEGORY (CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES, STATUS) VALUES (:id, :name, :notes, 'Active')"
                    );
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO PET_CATEGORY (CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES) VALUES (:id, :name, :notes)'
                    );
                }
                $insert->execute(['id' => $newId, 'name' => $categoryName, 'notes' => $speciesNotes]);
                header('Location: user_management.php?tab=pet_category&success=1');
                exit();
            }
        } elseif ($action === 'edit_pet_category') {
            $categoryId = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
            $categoryName = trim($_POST['category_name'] ?? '');
            $speciesNotes = trim($_POST['species_notes'] ?? '');
            $recordStatus = trim($_POST['status'] ?? 'Active');
            if (!$categoryId || $categoryName === '') {
                $formErrors['pet_category'] = 'Please complete the category update form.';
            } else {
                $fields = 'CATEGORY_NAME = :name, SPECIES_NOTES = :notes';
                if ($supportsStatus['PET_CATEGORY']) {
                    $fields .= ', STATUS = :status';
                }
                $sql = "UPDATE PET_CATEGORY SET $fields WHERE CATEGORY_ID = :id";
                $update = $pdo->prepare($sql);
                $params = ['name' => $categoryName, 'notes' => $speciesNotes, 'id' => $categoryId];
                if ($supportsStatus['PET_CATEGORY']) {
                    $params['status'] = in_array($recordStatus, ['Active', 'Inactive'], true) ? $recordStatus : 'Active';
                }
                $update->execute($params);
                header('Location: user_management.php?tab=pet_category&success=1');
                exit();
            }
        } elseif ($action === 'deactivate_pet_category') {
            $categoryId = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
            $newStatus = trim($_POST['status'] ?? 'Inactive');
            if (!$categoryId || !$supportsStatus['PET_CATEGORY']) {
                $formErrors['pet_category'] = 'Unable to update category status because the database schema does not support it.';
            } else {
                $statusValue = in_array($newStatus, ['Active', 'Inactive'], true) ? $newStatus : 'Inactive';
                $update = $pdo->prepare('UPDATE PET_CATEGORY SET STATUS = :status WHERE CATEGORY_ID = :id');
                $update->execute(['status' => $statusValue, 'id' => $categoryId]);
                header('Location: user_management.php?tab=pet_category&success=1');
                exit();
            }
        } elseif ($action === 'add_service') {
            $serviceName = trim($_POST['service_name'] ?? '');
            $serviceDescription = trim($_POST['service_description'] ?? '');
            $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($serviceName === '' || $price === false || $price < 0) {
                $formErrors['service'] = 'Please complete the service form and provide a valid price.';
            } else {
                $newId = getNextId($pdo, 'SERVICE', 'SERVICE_ID');
                if ($supportsStatus['SERVICE']) {
                    $insert = $pdo->prepare(
                        "INSERT INTO SERVICE (SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE, STATUS) VALUES (:id, :name, :desc, :price, 'Active')"
                    );
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO SERVICE (SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE) VALUES (:id, :name, :desc, :price)'
                    );
                }
                $insert->execute(['id' => $newId, 'name' => $serviceName, 'desc' => $serviceDescription, 'price' => $price]);
                header('Location: user_management.php?tab=service&success=1');
                exit();
            }
        } elseif ($action === 'edit_service') {
            $serviceId = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
            $serviceName = trim($_POST['service_name'] ?? '');
            $serviceDescription = trim($_POST['service_description'] ?? '');
            $price = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            $recordStatus = trim($_POST['status'] ?? 'Active');
            if (!$serviceId || $serviceName === '' || $price === false || $price < 0) {
                $formErrors['service'] = 'Please complete the service update form and provide a valid price.';
            } else {
                $fields = 'SERVICE_NAME = :name, SERVICE_DESCRIPTION = :desc, PRICE = :price';
                if ($supportsStatus['SERVICE']) {
                    $fields .= ', STATUS = :status';
                }
                $sql = "UPDATE SERVICE SET $fields WHERE SERVICE_ID = :id";
                $update = $pdo->prepare($sql);
                $params = ['name' => $serviceName, 'desc' => $serviceDescription, 'price' => $price, 'id' => $serviceId];
                if ($supportsStatus['SERVICE']) {
                    $params['status'] = in_array($recordStatus, ['Active', 'Inactive'], true) ? $recordStatus : 'Active';
                }
                $update->execute($params);
                header('Location: user_management.php?tab=service&success=1');
                exit();
            }
        } elseif ($action === 'deactivate_service') {
            $serviceId = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
            $newStatus = trim($_POST['status'] ?? 'Inactive');
            if (!$serviceId || !$supportsStatus['SERVICE']) {
                $formErrors['service'] = 'Unable to update service status because the database schema does not support it.';
            } else {
                $statusValue = in_array($newStatus, ['Active', 'Inactive'], true) ? $newStatus : 'Inactive';
                $update = $pdo->prepare('UPDATE SERVICE SET STATUS = :status WHERE SERVICE_ID = :id');
                $update->execute(['status' => $statusValue, 'id' => $serviceId]);
                header('Location: user_management.php?tab=service&success=1');
                exit();
            }
        } elseif ($action === 'update_username') {
            $username = trim($_POST['username'] ?? '');
            $accountId = $_SESSION['account_id'];
            if ($username === '') {
                $formErrors['account'] = 'Username cannot be blank.';
                $redirectTab = $requestedTab;
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM USER_ACCOUNT WHERE USERNAME = :username AND ACCOUNT_ID <> :id');
                $stmt->execute(['username' => $username, 'id' => $accountId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $formErrors['account'] = 'That username is already taken.';
                    $redirectTab = $requestedTab;
                } else {
                    $update = $pdo->prepare('UPDATE USER_ACCOUNT SET USERNAME = :username WHERE ACCOUNT_ID = :id');
                    $update->execute(['username' => $username, 'id' => $accountId]);
                    header('Location: user_management.php?tab=' . $requestedTab . '&success=1');
                    exit();
                }
            }
        } elseif ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';
            $accountId = $_SESSION['account_id'];
            if ($current === '' || $newPass === '' || $confirmPass === '') {
                $formErrors['password'] = 'Please complete all password fields.';
                $redirectTab = $requestedTab;
            } elseif ($newPass !== $confirmPass) {
                $formErrors['password'] = 'New password and confirm password must match.';
                $redirectTab = $requestedTab;
            } elseif (strlen($newPass) < 8) {
                $formErrors['password'] = 'New password must be at least 8 characters.';
                $redirectTab = $requestedTab;
            } else {
                $stmt = $pdo->prepare('SELECT PASSWORD_HASH FROM USER_ACCOUNT WHERE ACCOUNT_ID = :id');
                $stmt->execute(['id' => $accountId]);
                $hash = $stmt->fetchColumn();
                if (!$hash || !password_verify($current, $hash)) {
                    $formErrors['password'] = 'Current password is incorrect.';
                    $redirectTab = $requestedTab;
                } else {
                    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                    $update = $pdo->prepare('UPDATE USER_ACCOUNT SET PASSWORD_HASH = :hash WHERE ACCOUNT_ID = :id');
                    $update->execute(['hash' => $newHash, 'id' => $accountId]);
                    header('Location: user_management.php?tab=' . $requestedTab . '&success=1');
                    exit();
                }
            }
        }
    } catch (PDOException $e) {
        $message = 'Database error: ' . $e->getMessage();
        if (in_array($action, ['add_accommodation', 'edit_accommodation', 'deactivate_accommodation'], true)) {
            $formErrors['accommodation'] = $message;
        } elseif (in_array($action, ['add_tier', 'edit_tier', 'deactivate_tier'], true)) {
            $formErrors['tier'] = $message;
        } elseif (in_array($action, ['add_pet_category', 'edit_pet_category', 'deactivate_pet_category'], true)) {
            $formErrors['pet_category'] = $message;
        } elseif (in_array($action, ['add_service', 'edit_service', 'deactivate_service'], true)) {
            $formErrors['service'] = $message;
        } elseif ($action === 'update_username') {
            $formErrors['account'] = $message;
        } elseif ($action === 'change_password') {
            $formErrors['password'] = $message;
        }
    }
    $activeTab = $redirectTab;
}

$statusFilter = $supportsStatus['TIER'] ? "WHERE STATUS = 'Active'" : '';
$inactiveTierFilter = $supportsStatus['TIER'] ? "WHERE STATUS = 'Inactive'" : '';
$activeCategoryFilter = $supportsStatus['PET_CATEGORY'] ? "WHERE STATUS = 'Active'" : '';
$inactiveCategoryFilter = $supportsStatus['PET_CATEGORY'] ? "WHERE STATUS = 'Inactive'" : '';
$activeServiceFilter = $supportsStatus['SERVICE'] ? "WHERE STATUS = 'Active'" : '';
$inactiveServiceFilter = $supportsStatus['SERVICE'] ? "WHERE STATUS = 'Inactive'" : '';

$activeTiers = $pdo->query("SELECT TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE " . ($supportsStatus['TIER'] ? ', STATUS' : '') . " FROM TIER $statusFilter ORDER BY TIER_ID")->fetchAll(PDO::FETCH_ASSOC);
$allTiers = $pdo->query('SELECT TIER_ID, TIER_NAME FROM TIER ORDER BY TIER_ID')->fetchAll(PDO::FETCH_ASSOC);
$inactiveTiers = $supportsStatus['TIER'] ? $pdo->query("SELECT TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE, STATUS FROM TIER $inactiveTierFilter ORDER BY TIER_ID")->fetchAll(PDO::FETCH_ASSOC) : [];
$activeCategories = $pdo->query("SELECT CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES " . ($supportsStatus['PET_CATEGORY'] ? ', STATUS' : '') . " FROM PET_CATEGORY $activeCategoryFilter ORDER BY CATEGORY_ID")->fetchAll(PDO::FETCH_ASSOC);
$inactiveCategories = $supportsStatus['PET_CATEGORY'] ? $pdo->query("SELECT CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES, STATUS FROM PET_CATEGORY $inactiveCategoryFilter ORDER BY CATEGORY_ID")->fetchAll(PDO::FETCH_ASSOC) : [];
$activeServices = $pdo->query("SELECT SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE " . ($supportsStatus['SERVICE'] ? ', STATUS' : '') . " FROM SERVICE $activeServiceFilter ORDER BY SERVICE_ID")->fetchAll(PDO::FETCH_ASSOC);
$inactiveServices = $supportsStatus['SERVICE'] ? $pdo->query("SELECT SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE, STATUS FROM SERVICE $inactiveServiceFilter ORDER BY SERVICE_ID")->fetchAll(PDO::FETCH_ASSOC) : [];
$accommodations = $pdo->query(
    'SELECT A.ACCOMMODATION_ID, A.UNIT_NAME, A.ACCOMMODATION_TYPE, A.OCCUPANCY_STATUS, A.TIER_ID, T.TIER_NAME FROM ACCOMMODATION A JOIN TIER T ON A.TIER_ID = T.TIER_ID ORDER BY A.ACCOMMODATION_ID'
)->fetchAll(PDO::FETCH_ASSOC);

$countsStmt = $pdo->query('SELECT TIER_ID, COUNT(*) AS CNT FROM ACCOMMODATION GROUP BY TIER_ID');
$accommodationCounts = [];
while ($row = $countsStmt->fetch(PDO::FETCH_ASSOC)) {
    $accommodationCounts[$row['TIER_ID']] = (int) $row['CNT'];
}

$currentUserStmt = $pdo->prepare(
    'SELECT UA.ACCOUNT_ID, UA.USERNAME, UA.ACCOUNT_STATUS, E.EMPLOYEE_USERNAME, UG.GROUP_NAME FROM USER_ACCOUNT UA JOIN EMPLOYEE E ON UA.EMPLOYEE_ID = E.EMPLOYEE_ID JOIN USER_GROUP UG ON UA.USER_GROUP_ID = UG.USER_GROUP_ID WHERE UA.ACCOUNT_ID = :id'
);
$currentUserStmt->execute(['id' => $_SESSION['account_id']]);
$currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC) ?: ['USERNAME' => '', 'ACCOUNT_STATUS' => '', 'EMPLOYEE_USERNAME' => '', 'GROUP_NAME' => ''];

function renderStatusBadge($status) {
    $styles = [
        'Available' => 'badge-available',
        'Booked' => 'badge-booked',
        'Under Maintenance' => 'badge-maintenance',
    ];
    $class = $styles[$status] ?? 'badge-muted';
    return '<span class="status-pill ' . $class . '">' . escape($status) . '</span>';
}

function badgeFree() {
    return '<span class="status-pill badge-free">FREE</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management – Radog's Kennel Pet Hotel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --orange: #FA8112;
            --orange-dk: #d96a08;
            --black: #222222;
            --beige: #FAF3E1;
            --gold: #F5E7C6;
            --white: #ffffff;
            --radius-card: 20px;
            --radius-input: 12px;
            --radius-btn: 12px;
            --shadow-card: 0 24px 70px rgba(15,23,42,0.08);
            --border-soft: 1.5px solid #e2d9ce;
        }
        html, body { min-height: 100%; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--beige);
            color: var(--black);
            display: flex;
            min-height: 100vh;
        }
        h1, h2, h3, h4, h5, button, .eyebrow { font-family: 'Bebas Neue', sans-serif; }
        a { text-decoration: none; }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: translateY(0); } }
        .page-shell {
            display: flex;
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
            padding: 28px;
            gap: 28px;
        }
        .sidebar {
            width: 280px;
            flex-shrink: 0;
            background-color: var(--black);
            background-image: repeating-linear-gradient(-55deg, transparent 0 18px, rgba(250,129,18,0.04) 18px 19px);
            border-radius: var(--radius-card);
            padding: 28px 22px;
            display: flex;
            flex-direction: column;
            gap: 28px;
            animation: fadeUp 0.9s ease both;
        }
        .sidebar-brand { display: flex; align-items: center; gap: 14px; }
        .sidebar-logo {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--orange), var(--gold));
            display: grid;
            place-items: center;
            color: var(--white);
            font-size: 1.5rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            box-shadow: 0 14px 30px rgba(250,129,18,0.25);
        }
        .sidebar-wordmark-top { color: var(--orange); font-size: 1.45rem; line-height: 1; }
        .sidebar-wordmark-sub { font-size: 0.7rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--gold); }
        .sidebar-user {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: var(--radius-card);
            padding: 18px;
            display: grid;
            gap: 10px;
            animation: fadeUp 0.9s ease both 0.1s;
        }
        .user-title { font-size: 0.82rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--gold); }
        .user-name { font-size: 1.1rem; color: var(--white); }
        .user-role { font-size: 0.92rem; color: rgba(255,255,255,0.72); }
        .sidebar-nav { display: grid; gap: 10px; animation: fadeUp 0.9s ease both 0.2s; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 14px; color: rgba(255,255,255,0.9); transition: background 0.25s ease, transform 0.2s ease; }
        .nav-link:hover, .nav-link.active { background: rgba(250,129,18,0.14); transform: translateX(2px); }
        .nav-link svg { width: 18px; height: 18px; fill: currentColor; }
        .main-content { flex: 1; display: flex; flex-direction: column; gap: 20px; animation: fadeUp 0.9s ease both 0.3s; }
        .page-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; }
        .page-copy { max-width: 680px; }
        .eyebrow { display: inline-block; margin-bottom: 12px; color: var(--orange); font-size: 0.8rem; letter-spacing: 0.2em; text-transform: uppercase; }
        .page-copy h1 { font-size: clamp(2.2rem, 2.25vw, 3rem); line-height: 1; margin-bottom: 14px; }
        .page-copy p { font-size: 1rem; line-height: 1.75; color: #4f4f4f; }
        .page-actions { display: flex; justify-content: flex-end; align-items: center; }
        .btn { border: 0; border-radius: var(--radius-btn); font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.12em; text-transform: uppercase; padding: 14px 22px; transition: background 0.25s ease, color 0.25s ease, transform 0.2s ease; }
        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); }
        .btn-danger { background: var(--orange); color: var(--white); }
        .btn-danger:hover { background: var(--orange-dk); }
        .btn-ghost { background: transparent; border: 1px solid rgba(34,34,34,0.14); color: var(--black); }
        .btn-ghost:hover { background: rgba(250,129,18,0.08); }
        .btn-small { padding: 10px 16px; font-size: 0.85rem; }
        .tab-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 10px; }
        .tab-btn { border: 1px solid rgba(34,34,34,0.14); background: transparent; color: var(--black); padding: 12px 18px; border-radius: 999px; transition: background 0.25s ease, color 0.25s ease; }
        .tab-btn.active { background: var(--black); color: var(--white); border-color: transparent; }
        .tab-btn:hover { background: rgba(250,129,18,0.08); }
        .panel { background: var(--white); border-radius: var(--radius-card); box-shadow: var(--shadow-card); padding: 28px; display: grid; gap: 24px; }
        .panel-row { display: grid; gap: 24px; }
        .panel-row.two { grid-template-columns: 1fr 1fr; }
        .field { display: grid; gap: 10px; }
        label { display: block; font-weight: 600; font-size: 0.95rem; color: #2f2f2f; }
        small { color: #6d6d6d; }
        input, select, textarea { width: 100%; border: var(--border-soft); border-radius: var(--radius-input); background: var(--beige); padding: 14px 16px; color: var(--black); outline: none; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
        input:focus, select:focus, textarea:focus { border-color: var(--orange); box-shadow: 0 0 0 4px rgba(250,129,18,0.12); }
        textarea { min-height: 120px; resize: vertical; }
        .form-row { display: grid; gap: 18px; }
        .form-row.split { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .field-prefix { display: flex; align-items: center; gap: 8px; }
        .field-prefix span { color: #4f4f4f; }
        .alert { padding: 16px 18px; border-radius: 16px; background: #f4fbf6; border: 1px solid #d1efd7; color: #1f5b2f; }
        .alert.error { background: #fff1f0; border-color: #f3c1c2; color: #991b1b; }
        .records-table { width: 100%; border-collapse: collapse; }
        .records-table thead { background: rgba(250,129,18,0.04); }
        .records-table th, .records-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid rgba(34,34,34,0.08); }
        .records-table th { text-transform: uppercase; letter-spacing: 0.12em; font-size: 0.72rem; color: #555; }
        .records-table tbody tr:hover { background: rgba(250,129,18,0.03); }
        .records-table td.actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .status-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; font-size: 0.88rem; font-weight: 700; }
        .badge-available { background: rgba(34,197,94,0.12); color: #166534; }
        .badge-booked { background: rgba(250,129,18,0.12); color: var(--orange-dk); }
        .badge-maintenance { background: rgba(148,163,184,0.15); color: #475569; }
        .badge-free { background: rgba(34,197,94,0.12); color: #166534; }
        .badge-muted { background: rgba(34,34,34,0.08); color: #2f2f2f; }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(17,24,39,0.55); backdrop-filter: blur(3px); display: none; align-items: center; justify-content: center; padding: 24px; z-index: 20; }
        .modal-backdrop.open { display: flex; }
        .modal { width: min(760px, 100%); background: #fff; border-radius: var(--radius-card); overflow: hidden; box-shadow: var(--shadow-card); }
        .modal-header { padding: 22px 24px; background: linear-gradient(135deg, #111827, #1f2937); color: var(--white); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .modal-header h3 { margin: 0; font-size: 1.3rem; }
        .modal-body { padding: 24px; display: grid; gap: 20px; }
        .modal-close { background: transparent; border: 1px solid rgba(255,255,255,0.24); border-radius: 50%; width: 38px; height: 38px; color: var(--white); display: grid; place-items: center; }
        .modal-close:hover { background: rgba(255,255,255,0.12); }
        .modal-section { display: grid; gap: 16px; }
        .modal-section h4 { margin-bottom: 8px; font-size: 1rem; }
        .modal-details { display: grid; gap: 10px; }
        .detail-row { display: grid; gap: 6px; }
        .detail-row span { color: #4f4f4f; }
        .inactive-toggle { display: inline-flex; align-items: center; gap: 10px; color: var(--orange-dk); background: rgba(250,129,18,0.08); border-radius: 999px; padding: 10px 14px; border: 1px solid rgba(250,129,18,0.18); }
        .inactive-toggle button { border: none; background: transparent; color: var(--orange-dk); font-weight: 700; cursor: pointer; padding: 0; }
        .hidden { display: none !important; }
        @media (max-width: 900px) {
            body { display: block; }
            .page-shell { flex-direction: column; padding: 18px; }
            .sidebar { width: 100%; position: relative; }
            .page-head { flex-direction: column; align-items: stretch; }
            .form-row.split { grid-template-columns: 1fr; }
            .page-actions { justify-content: stretch; }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <aside class="sidebar">
            <div class="sidebar-brand">
                <div class="sidebar-logo">RK</div>
                <div>
                    <div class="sidebar-wordmark-top">Radog's Kennel</div>
                    <div class="sidebar-wordmark-sub">Pet Hotel Admin</div>
                </div>
            </div>
            <div class="sidebar-user">
                <div class="user-title">Signed in as</div>
                <div class="user-name"><?php echo escape($_SESSION['username'] ?? 'Admin'); ?></div>
                <div class="user-role"><?php echo escape($_SESSION['group_name'] ?? 'Administrator'); ?></div>
            </div>
            <nav class="sidebar-nav">
                <?php
                $nav = [
                    ['href' => 'admin_dashboard.php', 'label' => 'Dashboard', 'icon' => '<svg viewBox="0 0 24 24"><path d="M4 13h6V4H4v9zm0 7h6v-5H4v5zm10 0h6V11h-6v9zm0-18v7h6V2h-6z"/></svg>'],
                    ['href' => 'encode_reservation.php', 'label' => 'Schedule', 'icon' => '<svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v11zm0-13H5V6h14v1z"/></svg>'],
                    ['href' => 'calendar-unified.php', 'label' => 'Calendar', 'icon' => '<svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4H5V6h14v2zm0 12H5V10h14v10z"/></svg>'],
                    ['href' => 'owner.php', 'label' => 'Owners', 'icon' => '<svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>'],
                    ['href' => 'pets.php', 'label' => 'Pets', 'icon' => '<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/></svg>'],
                    // ['href' => 'checkout.php', 'label' => 'Checkout / Payments', 'icon' => '<svg viewBox="0 0 24 24"><path d="M20 6H4a2 2 0 0 0-2 2v2h20V8a2 2 0 0 0-2-2zm0 6H2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6zm-3 5H7v-2h10v2z"/></svg>'],
                    ['href' => 'user_management.php', 'label' => 'User Management', 'icon' => '<svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>', 'active' => true],
                    ['href' => '../logout.php', 'label' => 'Logout', 'icon' => '<svg viewBox="0 0 24 24"><path d="M16 13v-2H7V8l-5 4 5 4v-3h9zM20 3h-8v2h8v14h-8v2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></svg>'],
                ];
                foreach ($nav as $item):
                ?>
                    <a href="<?php echo escape($item['href']); ?>" class="nav-link<?php echo !empty($item['active']) ? ' active' : ''; ?>">
                        <?php echo $item['icon']; ?>
                        <span><?php echo escape($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <main class="main-content">
            <div class="page-head">
                <div class="page-copy">
                    <div class="eyebrow">Administration</div>
                    <h1>User Management</h1>
                    <p>Manage system reference data and account settings.</p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn btn-ghost" id="openAccountModal">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg" style="margin-right:10px;"><path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM4 20v-1c0-2.76 3.58-5 8-5s8 2.24 8 5v1H4z"/></svg>
                        My Account
                    </button>
                </div>
            </div>
            <div class="tab-bar" role="tablist">
                <button type="button" class="tab-btn" data-tab="accommodation">Accommodation</button>
                <button type="button" class="tab-btn" data-tab="tier">Tier</button>
                <button type="button" class="tab-btn" data-tab="pet_category">Pet Category</button>
                <button type="button" class="tab-btn" data-tab="service">Service</button>
            </div>

            <div id="panel-accommodation" class="tab-panel">
                <?php if ($activeTab === 'accommodation' && $success): ?>
                    <div class="alert">Accommodation action completed successfully.</div>
                <?php endif; ?>
                <div class="panel">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px;"><h2>New Accommodation</h2></div>
                    <?php if ($formErrors['accommodation']): ?>
                        <div class="alert error"><?php echo escape($formErrors['accommodation']); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="user_management.php?tab=accommodation" class="form-row split">
                        <div class="field">
                            <label for="unit_name">Unit Name</label>
                            <input type="text" id="unit_name" name="unit_name" readonly>
                        </div>
                        <div class="field">
                            <label for="accommodation_type">Accommodation Type</label>
                            <input type="text" id="accommodation_type" name="accommodation_type" placeholder="Stainless Cage, Airconditioned Room" required>
                        </div>
                        <div class="field" style="grid-column: span 2;">
                            <label for="tier_id">Tier</label>
                            <select id="tier_id" name="tier_id" required>
                                <?php foreach ($activeTiers as $tier): ?>
                                    <option value="<?php echo escape($tier['TIER_ID']); ?>" data-tier-name="<?php echo escape($tier['TIER_NAME']); ?>"><?php echo escape($tier['TIER_NAME']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="action" value="add_accommodation">
                        <input type="hidden" name="tab" value="accommodation">
                        <button type="submit" class="btn btn-primary" style="grid-column: span 2; justify-self:start;">Add Accommodation</button>
                    </form>
                </div>
                <div class="panel">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:14px;"><h2>Accommodation Records</h2></div>
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Unit Name</th>
                                <th>Accommodation Type</th>
                                <th>Tier</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($accommodations as $unit): ?>
                            <tr>
                                <td><?php echo escape($unit['UNIT_NAME']); ?></td>
                                <td><?php echo escape($unit['ACCOMMODATION_TYPE']); ?></td>
                                <td><?php echo escape($unit['TIER_NAME']); ?></td>
                                <td><?php echo renderStatusBadge($unit['OCCUPANCY_STATUS']); ?></td>
                                <td class="actions">
                                    <button type="button" class="btn btn-ghost btn-small edit-accommodation" 
                                        data-id="<?php echo escape($unit['ACCOMMODATION_ID']); ?>"
                                        data-unit="<?php echo escape($unit['UNIT_NAME']); ?>"
                                        data-type="<?php echo escape($unit['ACCOMMODATION_TYPE']); ?>"
                                        data-tier="<?php echo escape($unit['TIER_ID']); ?>"
                                        data-status="<?php echo escape($unit['OCCUPANCY_STATUS']); ?>">
                                        Edit
                                    </button>
                                    <?php if ($unit['OCCUPANCY_STATUS'] !== 'Under Maintenance'): ?>
                                    <form method="POST" action="user_management.php?tab=accommodation" style="display:inline;">
                                        <input type="hidden" name="action" value="deactivate_accommodation">
                                        <input type="hidden" name="accommodation_id" value="<?php echo escape($unit['ACCOMMODATION_ID']); ?>">
                                        <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Put this accommodation under maintenance?');">Deactivate</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="panel-tier" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'tier' && $success): ?>
                    <div class="alert">Tier action completed successfully.</div>
                <?php endif; ?>
                <div class="panel">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px;"><h2>New Tier</h2></div>
                    <?php if ($formErrors['tier']): ?>
                        <div class="alert error"><?php echo escape($formErrors['tier']); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="user_management.php?tab=tier" class="form-row">
                        <div class="form-row split">
                            <div class="field">
                                <label for="tier_name">Tier Name</label>
                                <input type="text" id="tier_name" name="tier_name" placeholder="Small, Medium, Large, Giant" required>
                            </div>
                            <div class="field">
                                <label for="tier_description">Description</label>
                                <input type="text" id="tier_description" name="tier_description" placeholder="Short tier description">
                            </div>
                        </div>
                        <div class="form-row split">
                            <div class="field">
                                <label for="weight_min">Weight Min (kg)</label>
                                <input type="number" id="weight_min" name="weight_min" min="0" step="0.01" required>
                            </div>
                            <div class="field">
                                <label for="weight_max">Weight Max (kg)</label>
                                <input type="number" id="weight_max" name="weight_max" min="0" step="0.01" required>
                            </div>
                        </div>
                        <div class="field">
                            <label for="daily_rate">Daily Rate</label>
                            <div class="field-prefix"><span>₱</span><input type="number" id="daily_rate" name="daily_rate" min="0" step="0.01" required></div>
                        </div>
                        <input type="hidden" name="action" value="add_tier">
                        <input type="hidden" name="tab" value="tier">
                        <button type="submit" class="btn btn-primary">Add Tier</button>
                    </form>
                </div>
                <div class="panel">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:14px;">
                        <h2>Tier Records</h2>
                        <?php if ($supportsStatus['TIER']): ?>
                            <button type="button" class="inactive-toggle" id="toggleTierInactive">Show Inactive</button>
                        <?php endif; ?>
                    </div>
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Tier Name</th>
                                <th>Description</th>
                                <th>Weight Range</th>
                                <th>Daily Rate</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeTiers as $tier): ?>
                            <tr>
                                <td><?php echo escape($tier['TIER_NAME']); ?></td>
                                <td><?php echo escape($tier['TIER_DESCRIPTION']); ?></td>
                                <td><?php echo escape(number_format($tier['WEIGHT_MIN'], 2)); ?> – <?php echo escape(number_format($tier['WEIGHT_MAX'], 2)); ?> kg</td>
                                <td>₱<?php echo escape(number_format($tier['DAILY_RATE'], 2)); ?></td>
                                <td class="actions">
                                    <button type="button" class="btn btn-ghost btn-small edit-tier"
                                        data-id="<?php echo escape($tier['TIER_ID']); ?>"
                                        data-name="<?php echo escape($tier['TIER_NAME']); ?>"
                                        data-description="<?php echo escape($tier['TIER_DESCRIPTION']); ?>"
                                        data-min="<?php echo escape($tier['WEIGHT_MIN']); ?>"
                                        data-max="<?php echo escape($tier['WEIGHT_MAX']); ?>"
                                        data-rate="<?php echo escape($tier['DAILY_RATE']); ?>"
                                        data-status="<?php echo escape($tier['STATUS'] ?? 'Active'); ?>">
                                        Edit
                                    </button>
                                    <?php if ($supportsStatus['TIER']): ?>
                                        <form method="POST" action="user_management.php?tab=tier" style="display:inline;">
                                            <input type="hidden" name="action" value="deactivate_tier">
                                            <input type="hidden" name="tier_id" value="<?php echo escape($tier['TIER_ID']); ?>">
                                            <input type="hidden" name="status" value="Inactive">
                                            <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Deactivate this tier?');">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($supportsStatus['TIER']): ?>
                        <div class="inactive-section hidden" id="tierInactiveSection">
                            <h3>Inactive Tier Records</h3>
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Tier Name</th>
                                        <th>Description</th>
                                        <th>Weight Range</th>
                                        <th>Daily Rate</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($inactiveTiers as $tier): ?>
                                    <tr>
                                        <td><?php echo escape($tier['TIER_NAME']); ?></td>
                                        <td><?php echo escape($tier['TIER_DESCRIPTION']); ?></td>
                                        <td><?php echo escape(number_format($tier['WEIGHT_MIN'], 2)); ?> – <?php echo escape(number_format($tier['WEIGHT_MAX'], 2)); ?> kg</td>
                                        <td>₱<?php echo escape(number_format($tier['DAILY_RATE'], 2)); ?></td>
                                        <td class="actions">
                                            <form method="POST" action="user_management.php?tab=tier" style="display:inline;">
                                                <input type="hidden" name="action" value="deactivate_tier">
                                                <input type="hidden" name="tier_id" value="<?php echo escape($tier['TIER_ID']); ?>">
                                                <input type="hidden" name="status" value="Active">
                                                <button type="submit" class="btn btn-primary btn-small">Reactivate</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="panel-pet_category" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'pet_category' && $success): ?>
                    <div class="alert">Pet category action completed successfully.</div>
                <?php endif; ?>
                <div class="panel">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px;"><h2>New Pet Category</h2></div>
                    <?php if ($formErrors['pet_category']): ?>
                        <div class="alert error"><?php echo escape($formErrors['pet_category']); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="user_management.php?tab=pet_category" class="form-row">
                        <div class="field">
                            <label for="category_name">Category Name</label>
                            <input type="text" id="category_name" name="category_name" placeholder="Dog, Cat, Hamster" required>
                        </div>
                        <div class="field">
                            <label for="species_notes">Species Notes / Description</label>
                            <textarea id="species_notes" name="species_notes" placeholder="Notes for this species"></textarea>
                        </div>
                        <input type="hidden" name="action" value="add_pet_category">
                        <input type="hidden" name="tab" value="pet_category">
                        <button type="submit" class="btn btn-primary">Add Category</button>
                    </form>
                </div>
                <div class="panel">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:14px;">
                        <h2>Pet Category Records</h2>
                        <?php if ($supportsStatus['PET_CATEGORY']): ?>
                            <button type="button" class="inactive-toggle" id="toggleCategoryInactive">Show Inactive</button>
                        <?php endif; ?>
                    </div>
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Category Name</th>
                                <th>Species Notes</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeCategories as $cat): ?>
                            <tr>
                                <td><?php echo escape($cat['CATEGORY_NAME']); ?></td>
                                <td><?php echo escape($cat['SPECIES_NOTES']); ?></td>
                                <td class="actions">
                                    <button type="button" class="btn btn-ghost btn-small edit-category"
                                        data-id="<?php echo escape($cat['CATEGORY_ID']); ?>"
                                        data-name="<?php echo escape($cat['CATEGORY_NAME']); ?>"
                                        data-notes="<?php echo escape($cat['SPECIES_NOTES']); ?>"
                                        data-status="<?php echo escape($cat['STATUS'] ?? 'Active'); ?>">
                                        Edit
                                    </button>
                                    <?php if ($supportsStatus['PET_CATEGORY']): ?>
                                        <form method="POST" action="user_management.php?tab=pet_category" style="display:inline;">
                                            <input type="hidden" name="action" value="deactivate_pet_category">
                                            <input type="hidden" name="category_id" value="<?php echo escape($cat['CATEGORY_ID']); ?>">
                                            <input type="hidden" name="status" value="Inactive">
                                            <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Deactivate this category?');">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($supportsStatus['PET_CATEGORY']): ?>
                        <div class="inactive-section hidden" id="categoryInactiveSection">
                            <h3>Inactive Categories</h3>
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Category Name</th>
                                        <th>Species Notes</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($inactiveCategories as $cat): ?>
                                    <tr>
                                        <td><?php echo escape($cat['CATEGORY_NAME']); ?></td>
                                        <td><?php echo escape($cat['SPECIES_NOTES']); ?></td>
                                        <td class="actions">
                                            <form method="POST" action="user_management.php?tab=pet_category" style="display:inline;">
                                                <input type="hidden" name="action" value="deactivate_pet_category">
                                                <input type="hidden" name="category_id" value="<?php echo escape($cat['CATEGORY_ID']); ?>">
                                                <input type="hidden" name="status" value="Active">
                                                <button type="submit" class="btn btn-primary btn-small">Reactivate</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="panel-service" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'service' && $success): ?>
                    <div class="alert">Service action completed successfully.</div>
                <?php endif; ?>
                <div class="panel">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px;"><h2>New Service</h2></div>
                    <?php if ($formErrors['service']): ?>
                        <div class="alert error"><?php echo escape($formErrors['service']); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="user_management.php?tab=service" class="form-row">
                        <div class="field">
                            <label for="service_name">Service Name</label>
                            <input type="text" id="service_name" name="service_name" placeholder="Premium Bubble Bath" required>
                        </div>
                        <div class="field">
                            <label for="service_description">Description</label>
                            <textarea id="service_description" name="service_description" placeholder="Service details"></textarea>
                        </div>
                        <div class="field">
                            <label for="price">Price</label>
                            <div class="field-prefix"><span>₱</span><input type="number" id="price" name="price" min="0" step="0.01" value="0.00" required></div>
                        </div>
                        <input type="hidden" name="action" value="add_service">
                        <input type="hidden" name="tab" value="service">
                        <button type="submit" class="btn btn-primary">Add Service</button>
                    </form>
                </div>
                <div class="panel">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:14px;">
                        <h2>Service Records</h2>
                        <?php if ($supportsStatus['SERVICE']): ?>
                            <button type="button" class="inactive-toggle" id="toggleServiceInactive">Show Inactive</button>
                        <?php endif; ?>
                    </div>
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Service Name</th>
                                <th>Description</th>
                                <th>Price</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activeServices as $svc): ?>
                            <tr>
                                <td><?php echo escape($svc['SERVICE_NAME']); ?></td>
                                <td><?php echo escape($svc['SERVICE_DESCRIPTION']); ?></td>
                                <td><?php echo $svc['PRICE'] === 0.0 ? badgeFree() : '₱' . escape(number_format($svc['PRICE'], 2)); ?></td>
                                <td class="actions">
                                    <button type="button" class="btn btn-ghost btn-small edit-service"
                                        data-id="<?php echo escape($svc['SERVICE_ID']); ?>"
                                        data-name="<?php echo escape($svc['SERVICE_NAME']); ?>"
                                        data-desc="<?php echo escape($svc['SERVICE_DESCRIPTION']); ?>"
                                        data-price="<?php echo escape($svc['PRICE']); ?>"
                                        data-status="<?php echo escape($svc['STATUS'] ?? 'Active'); ?>">
                                        Edit
                                    </button>
                                    <?php if ($supportsStatus['SERVICE']): ?>
                                        <form method="POST" action="user_management.php?tab=service" style="display:inline;">
                                            <input type="hidden" name="action" value="deactivate_service">
                                            <input type="hidden" name="service_id" value="<?php echo escape($svc['SERVICE_ID']); ?>">
                                            <input type="hidden" name="status" value="Inactive">
                                            <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Deactivate this service?');">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($supportsStatus['SERVICE']): ?>
                        <div class="inactive-section hidden" id="serviceInactiveSection">
                            <h3>Inactive Services</h3>
                            <table class="records-table">
                                <thead>
                                    <tr>
                                        <th>Service Name</th>
                                        <th>Description</th>
                                        <th>Price</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($inactiveServices as $svc): ?>
                                    <tr>
                                        <td><?php echo escape($svc['SERVICE_NAME']); ?></td>
                                        <td><?php echo escape($svc['SERVICE_DESCRIPTION']); ?></td>
                                        <td><?php echo $svc['PRICE'] === 0.0 ? badgeFree() : '₱' . escape(number_format($svc['PRICE'], 2)); ?></td>
                                        <td class="actions">
                                            <form method="POST" action="user_management.php?tab=service" style="display:inline;">
                                                <input type="hidden" name="action" value="deactivate_service">
                                                <input type="hidden" name="service_id" value="<?php echo escape($svc['SERVICE_ID']); ?>">
                                                <input type="hidden" name="status" value="Active">
                                                <button type="submit" class="btn btn-primary btn-small">Reactivate</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <div class="modal-backdrop" id="accountModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle">
            <div class="modal-header">
                <h3 id="accountModalTitle">My Account</h3>
                <button type="button" class="modal-close" id="closeAccountModal">×</button>
            </div>
            <div class="modal-body">
                <div class="modal-section">
                    <h4>Account Info</h4>
                    <div class="detail-row"><label>Username</label><span><?php echo escape($currentUser['USERNAME']); ?></span></div>
                    <div class="detail-row"><label>Employee Username</label><span><?php echo escape($currentUser['EMPLOYEE_USERNAME']); ?></span></div>
                    <div class="detail-row"><label>Account Status</label><span><?php echo escape($currentUser['ACCOUNT_STATUS']); ?></span></div>
                    <div class="detail-row"><label>Role / Group</label><span><?php echo escape($currentUser['GROUP_NAME']); ?></span></div>
                </div>
                <div class="modal-section">
                    <h4>Edit Username</h4>
                    <?php if ($formErrors['account']): ?>
                        <div class="alert error"><?php echo escape($formErrors['account']); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="user_management.php" id="usernameForm">
                        <div class="field"><label for="account_username">Username</label><input type="text" id="account_username" name="username" value="<?php echo escape($currentUser['USERNAME']); ?>" required></div>
                        <input type="hidden" name="action" value="update_username">
                        <input type="hidden" name="tab" id="usernameFormTab" value="<?php echo escape($activeTab); ?>">
                        <button type="submit" class="btn btn-primary">Save Username</button>
                    </form>
                </div>
                <div class="modal-section">
                    <h4>Change Password</h4>
                    <?php if ($formErrors['password']): ?>
                        <div class="alert error"><?php echo escape($formErrors['password']); ?></div>
                    <?php endif; ?>
                    <form method="POST" action="user_management.php" id="passwordForm">
                        <div class="field"><label for="current_password">Current Password</label><input type="password" id="current_password" name="current_password" required></div>
                        <div class="field"><label for="new_password">New Password</label><input type="password" id="new_password" name="new_password" required></div>
                        <div class="field"><label for="confirm_password">Confirm New Password</label><input type="password" id="confirm_password" name="confirm_password" required></div>
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="tab" id="passwordFormTab" value="<?php echo escape($activeTab); ?>">
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        const tabs = document.querySelectorAll('.tab-btn');
        const panels = document.querySelectorAll('.tab-panel');
        const accountModal = document.getElementById('accountModal');
        const openAccountModal = document.getElementById('openAccountModal');
        const closeAccountModal = document.getElementById('closeAccountModal');
        const usernameFormTab = document.getElementById('usernameFormTab');
        const passwordFormTab = document.getElementById('passwordFormTab');
        const tierSelect = document.getElementById('tier_id');
        const unitNameInput = document.getElementById('unit_name');
        const inactiveToggleButtons = document.querySelectorAll('.inactive-toggle');

        const accommodationCounts = <?php echo json_encode($accommodationCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function showTab(name) {
            panels.forEach(p => p.style.display = 'none');
            tabs.forEach(b => b.classList.remove('active'));
            const target = document.getElementById('panel-' + name);
            const button = document.querySelector('[data-tab="' + name + '"]');
            if (target && button) {
                target.style.display = 'block';
                button.classList.add('active');
            }
            history.replaceState(null, '', '?tab=' + name);
            if (usernameFormTab) usernameFormTab.value = name;
            if (passwordFormTab) passwordFormTab.value = name;
        }

        tabs.forEach(tab => tab.addEventListener('click', () => showTab(tab.dataset.tab)));
        showTab('<?php echo escape($activeTab); ?>');

        function tierPrefix(name) {
            if (name === 'Small') return 'CAGE-S';
            if (name === 'Medium') return 'ROOM-M';
            if (name === 'Large') return 'ROOM-L';
            if (name === 'Giant') return 'ROOM-G';
            return 'UNIT-';
        }

        function updateUnitName() {
            if (!tierSelect || !unitNameInput) return;
            const tierName = tierSelect.options[tierSelect.selectedIndex]?.dataset.tierName || '';
            const prefix = tierPrefix(tierName);
            const count = Number(accommodationCounts[tierSelect.value] || 0) + 1;
            unitNameInput.value = prefix + count;
        }
        if (tierSelect) {
            tierSelect.addEventListener('change', updateUnitName);
            updateUnitName();
        }

        function openModal() {
            accountModal.classList.add('open');
        }
        function closeModal() {
            accountModal.classList.remove('open');
        }
        openAccountModal.addEventListener('click', openModal);
        closeAccountModal.addEventListener('click', closeModal);
        accountModal.addEventListener('click', (event) => {
            if (event.target === accountModal) closeModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeModal();
        });

        function createEditHandler(selector, buildHtml) {
            document.querySelectorAll(selector).forEach(btn => {
                btn.addEventListener('click', () => {
                    accountModal.classList.add('open');
                    document.getElementById('accountModalTitle').textContent = buildHtml.title;
                    document.querySelector('.modal-body').innerHTML = buildHtml.content(btn);
                });
            });
        }

        document.querySelectorAll('.edit-accommodation').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.id;
                const unit = btn.dataset.unit;
                const type = btn.dataset.type;
                const tier = btn.dataset.tier;
                const status = btn.dataset.status;
                const options = Array.from(tierSelect.options).map(opt => `<option value="${opt.value}" ${opt.value === tier ? 'selected' : ''}>${opt.textContent}</option>`).join('');
                document.getElementById('accountModalTitle').textContent = 'Edit Accommodation';
                document.querySelector('.modal-body').innerHTML = `
                    <form method="POST" action="user_management.php?tab=accommodation" class="form-row">
                        <div class="field"><label>Unit Name</label><input type="text" name="unit_name" value="${unit}" readonly></div>
                        <div class="field"><label>Accommodation Type</label><input type="text" name="accommodation_type" value="${type}" required></div>
                        <div class="field"><label>Tier</label><select name="tier_id" required>${options}</select></div>
                        <div class="field"><label>Occupancy Status</label><select name="occupancy_status" required><option value="Available" ${status === 'Available' ? 'selected' : ''}>Available</option><option value="Booked" ${status === 'Booked' ? 'selected' : ''}>Booked</option><option value="Under Maintenance" ${status === 'Under Maintenance' ? 'selected' : ''}>Under Maintenance</option></select></div>
                        <input type="hidden" name="action" value="edit_accommodation">
                        <input type="hidden" name="accommodation_id" value="${id}">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                `;
                openModal();
            });
        });

        document.querySelectorAll('.edit-tier').forEach(btn => {
            btn.addEventListener('click', () => {
                const statusSelect = <?php echo $supportsStatus['TIER'] ? 'true' : 'false'; ?>;
                document.getElementById('accountModalTitle').textContent = 'Edit Tier';
                document.querySelector('.modal-body').innerHTML = `
                    <form method="POST" action="user_management.php?tab=tier" class="form-row">
                        <div class="field"><label>Tier Name</label><input type="text" name="tier_name" value="${btn.dataset.name}" required></div>
                        <div class="field"><label>Description</label><input type="text" name="tier_description" value="${btn.dataset.description}"></div>
                        <div class="field"><label>Weight Min</label><input type="number" step="0.01" min="0" name="weight_min" value="${btn.dataset.min}" required></div>
                        <div class="field"><label>Weight Max</label><input type="number" step="0.01" min="0" name="weight_max" value="${btn.dataset.max}" required></div>
                        <div class="field"><label>Daily Rate</label><div class="field-prefix"><span>₱</span><input type="number" step="0.01" min="0" name="daily_rate" value="${btn.dataset.rate}" required></div></div>
                        ${statusSelect ? `<div class="field"><label>Status</label><select name="status"><option value="Active" ${btn.dataset.status === 'Active' ? 'selected' : ''}>Active</option><option value="Inactive" ${btn.dataset.status === 'Inactive' ? 'selected' : ''}>Inactive</option></select></div>` : ''}
                        <input type="hidden" name="action" value="edit_tier">
                        <input type="hidden" name="tier_id" value="${btn.dataset.id}">
                        <button type="submit" class="btn btn-primary">Save Tier</button>
                    </form>
                `;
                openModal();
            });
        });

        document.querySelectorAll('.edit-category').forEach(btn => {
            btn.addEventListener('click', () => {
                const statusSelect = <?php echo $supportsStatus['PET_CATEGORY'] ? 'true' : 'false'; ?>;
                document.getElementById('accountModalTitle').textContent = 'Edit Pet Category';
                document.querySelector('.modal-body').innerHTML = `
                    <form method="POST" action="user_management.php?tab=pet_category" class="form-row">
                        <div class="field"><label>Category Name</label><input type="text" name="category_name" value="${btn.dataset.name}" required></div>
                        <div class="field"><label>Species Notes</label><textarea name="species_notes">${btn.dataset.notes}</textarea></div>
                        ${statusSelect ? `<div class="field"><label>Status</label><select name="status"><option value="Active" ${btn.dataset.status === 'Active' ? 'selected' : ''}>Active</option><option value="Inactive" ${btn.dataset.status === 'Inactive' ? 'selected' : ''}>Inactive</option></select></div>` : ''}
                        <input type="hidden" name="action" value="edit_pet_category">
                        <input type="hidden" name="category_id" value="${btn.dataset.id}">
                        <button type="submit" class="btn btn-primary">Save Category</button>
                    </form>
                `;
                openModal();
            });
        });

        document.querySelectorAll('.edit-service').forEach(btn => {
            btn.addEventListener('click', () => {
                const statusSelect = <?php echo $supportsStatus['SERVICE'] ? 'true' : 'false'; ?>;
                document.getElementById('accountModalTitle').textContent = 'Edit Service';
                document.querySelector('.modal-body').innerHTML = `
                    <form method="POST" action="user_management.php?tab=service" class="form-row">
                        <div class="field"><label>Service Name</label><input type="text" name="service_name" value="${btn.dataset.name}" required></div>
                        <div class="field"><label>Description</label><textarea name="service_description">${btn.dataset.desc}</textarea></div>
                        <div class="field"><label>Price</label><div class="field-prefix"><span>₱</span><input type="number" step="0.01" min="0" name="price" value="${btn.dataset.price}" required></div></div>
                        ${statusSelect ? `<div class="field"><label>Status</label><select name="status"><option value="Active" ${btn.dataset.status === 'Active' ? 'selected' : ''}>Active</option><option value="Inactive" ${btn.dataset.status === 'Inactive' ? 'selected' : ''}>Inactive</option></select></div>` : ''}
                        <input type="hidden" name="action" value="edit_service">
                        <input type="hidden" name="service_id" value="${btn.dataset.id}">
                        <button type="submit" class="btn btn-primary">Save Service</button>
                    </form>
                `;
                openModal();
            });
        });

        inactiveToggleButtons.forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.id === 'toggleTierInactive' ? 'tierInactiveSection' : button.id === 'toggleCategoryInactive' ? 'categoryInactiveSection' : 'serviceInactiveSection';
                const section = document.getElementById(targetId);
                if (!section) return;
                const hidden = section.classList.toggle('hidden');
                button.textContent = hidden ? 'Hide Inactive' : 'Show Inactive';
            });
        });
    </script>
</body>
</html>
