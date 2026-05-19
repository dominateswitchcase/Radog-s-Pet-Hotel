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
    $allowedTables = ['ACCOMMODATION', 'TIER', 'PET_CATEGORY', 'SERVICE', 'USER_ACCOUNT'];
    $allowedColumns = ['ACCOMMODATION_ID', 'TIER_ID', 'CATEGORY_ID', 'SERVICE_ID', 'ACCOUNT_ID'];
    if (!in_array($table, $allowedTables, true) || !in_array($column, $allowedColumns, true)) {
        throw new InvalidArgumentException('Invalid table or column name for getNextId().');
    }
    $stmt = $pdo->query("SELECT NVL(MAX($column), 0) + 1 FROM $table");
    return (int) $stmt->fetchColumn();
}

$supportsStatus = [
    'TIER'         => columnExists($pdo, 'TIER', 'STATUS'),
    'PET_CATEGORY' => columnExists($pdo, 'PET_CATEGORY', 'STATUS'),
    'SERVICE'      => columnExists($pdo, 'SERVICE', 'STATUS'),
];

$allowedTabs = ['accommodation', 'tier', 'pet_category', 'service', 'employee', 'account'];
$activeTab   = isset($_GET['tab']) && in_array($_GET['tab'], $allowedTabs, true) ? $_GET['tab'] : 'accommodation';
$success     = isset($_GET['success']) && $_GET['success'] === '1';
$formErrors  = [
    'accommodation' => '',
    'tier'          => '',
    'pet_category'  => '',
    'service'       => '',
    'employee'      => '',
    'account'       => '',
    'password'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $requestedTab = $_POST['tab'] ?? $activeTab;
    $redirectTab  = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'accommodation';
    $action       = trim($_POST['action']);

    try {
        if ($action === 'add_accommodation') {
            $accommodationType = trim($_POST['accommodation_type'] ?? '');
            $tierId            = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $unitName          = trim($_POST['unit_name'] ?? '');
            if ($accommodationType === '' || !$tierId || $unitName === '') {
                $formErrors['accommodation'] = 'Please provide an accommodation type and select a tier.';
            } else {
                $newId  = getNextId($pdo, 'ACCOMMODATION', 'ACCOMMODATION_ID');
                $insert = $pdo->prepare(
                    'INSERT INTO ACCOMMODATION (ACCOMMODATION_ID, UNIT_NAME, ACCOMMODATION_TYPE, OCCUPANCY_STATUS, TIER_ID) VALUES (:id, :unit, :type, :status, :tier)'
                );
                $insert->execute(['id' => $newId, 'unit' => $unitName, 'type' => $accommodationType, 'status' => 'Available', 'tier' => $tierId]);
                header('Location: user_management.php?tab=accommodation&success=1');
                exit();
            }
        } elseif ($action === 'edit_accommodation') {
            $accId             = filter_var($_POST['accommodation_id'] ?? '', FILTER_VALIDATE_INT);
            $accommodationType = trim($_POST['accommodation_type'] ?? '');
            $tierId            = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $occupancyStatus   = trim($_POST['occupancy_status'] ?? '');
            $allowedStatus     = ['Available', 'Booked', 'Under Maintenance'];
            if (!$accId || $accommodationType === '' || !$tierId || !in_array($occupancyStatus, $allowedStatus, true)) {
                $formErrors['accommodation'] = 'Please complete the accommodation update form correctly.';
            } else {
                $update = $pdo->prepare(
                    'UPDATE ACCOMMODATION SET ACCOMMODATION_TYPE = :type, TIER_ID = :tier, OCCUPANCY_STATUS = :status WHERE ACCOMMODATION_ID = :id'
                );
                $update->execute(['type' => $accommodationType, 'tier' => $tierId, 'status' => $occupancyStatus, 'id' => $accId]);
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
            $tierName    = trim($_POST['tier_name'] ?? '');
            $description = trim($_POST['tier_description'] ?? '');
            $weightMin   = filter_var($_POST['weight_min'] ?? '', FILTER_VALIDATE_FLOAT);
            $weightMax   = filter_var($_POST['weight_max'] ?? '', FILTER_VALIDATE_FLOAT);
            $dailyRate   = filter_var($_POST['daily_rate'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($tierName === '' || $weightMin === false || $weightMax === false || $dailyRate === false || $weightMax <= $weightMin) {
                $formErrors['tier'] = 'Please complete the tier form and ensure Weight Max is greater than Weight Min.';
            } else {
                $newId = getNextId($pdo, 'TIER', 'TIER_ID');
                if ($supportsStatus['TIER']) {
                    $insert = $pdo->prepare("INSERT INTO TIER (TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE, STATUS) VALUES (:id, :name, :desc, :min, :max, :rate, 'Active')");
                } else {
                    $insert = $pdo->prepare('INSERT INTO TIER (TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE) VALUES (:id, :name, :desc, :min, :max, :rate)');
                }
                $insert->execute(['id' => $newId, 'name' => $tierName, 'desc' => $description, 'min' => $weightMin, 'max' => $weightMax, 'rate' => $dailyRate]);
                header('Location: user_management.php?tab=tier&success=1');
                exit();
            }
        } elseif ($action === 'edit_tier') {
            $tierId       = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $tierName     = trim($_POST['tier_name'] ?? '');
            $description  = trim($_POST['tier_description'] ?? '');
            $weightMin    = filter_var($_POST['weight_min'] ?? '', FILTER_VALIDATE_FLOAT);
            $weightMax    = filter_var($_POST['weight_max'] ?? '', FILTER_VALIDATE_FLOAT);
            $dailyRate    = filter_var($_POST['daily_rate'] ?? '', FILTER_VALIDATE_FLOAT);
            $recordStatus = trim($_POST['status'] ?? 'Active');
            if (!$tierId || $tierName === '' || $weightMin === false || $weightMax === false || $dailyRate === false || $weightMax <= $weightMin) {
                $formErrors['tier'] = 'Please complete the tier update form and ensure the weight range is valid.';
            } else {
                $setFields = 'TIER_NAME = :name, TIER_DESCRIPTION = :desc, WEIGHT_MIN = :min, WEIGHT_MAX = :max, DAILY_RATE = :rate';
                if ($supportsStatus['TIER']) { $setFields .= ', STATUS = :status'; }
                $sql    = "UPDATE TIER SET $setFields WHERE TIER_ID = :id";
                $update = $pdo->prepare($sql);
                $params = ['name' => $tierName, 'desc' => $description, 'min' => $weightMin, 'max' => $weightMax, 'rate' => $dailyRate, 'id' => $tierId];
                if ($supportsStatus['TIER']) {
                    $params['status'] = in_array($recordStatus, ['Active', 'Inactive'], true) ? $recordStatus : 'Active';
                }
                $update->execute($params);
                header('Location: user_management.php?tab=tier&success=1');
                exit();
            }
        } elseif ($action === 'deactivate_tier') {
            $tierId    = filter_var($_POST['tier_id'] ?? '', FILTER_VALIDATE_INT);
            $newStatus = trim($_POST['status'] ?? 'Inactive');
            if (!$tierId || !$supportsStatus['TIER']) {
                $formErrors['tier'] = 'Unable to update tier status because the database schema does not support it.';
            } else {
                $statusValue = in_array($newStatus, ['Active', 'Inactive'], true) ? $newStatus : 'Inactive';
                $update      = $pdo->prepare('UPDATE TIER SET STATUS = :status WHERE TIER_ID = :id');
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
                    $insert = $pdo->prepare("INSERT INTO PET_CATEGORY (CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES, STATUS) VALUES (:id, :name, :notes, 'Active')");
                } else {
                    $insert = $pdo->prepare('INSERT INTO PET_CATEGORY (CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES) VALUES (:id, :name, :notes)');
                }
                $insert->execute(['id' => $newId, 'name' => $categoryName, 'notes' => $speciesNotes]);
                header('Location: user_management.php?tab=pet_category&success=1');
                exit();
            }
        } elseif ($action === 'edit_pet_category') {
            $categoryId   = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);
            $categoryName = trim($_POST['category_name'] ?? '');
            $speciesNotes = trim($_POST['species_notes'] ?? '');
            $recordStatus = trim($_POST['status'] ?? 'Active');
            if (!$categoryId || $categoryName === '') {
                $formErrors['pet_category'] = 'Please complete the category update form.';
            } else {
                $fields = 'CATEGORY_NAME = :name, SPECIES_NOTES = :notes';
                if ($supportsStatus['PET_CATEGORY']) { $fields .= ', STATUS = :status'; }
                $sql    = "UPDATE PET_CATEGORY SET $fields WHERE CATEGORY_ID = :id";
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
            $newStatus  = trim($_POST['status'] ?? 'Inactive');
            if (!$categoryId || !$supportsStatus['PET_CATEGORY']) {
                $formErrors['pet_category'] = 'Unable to update category status because the database schema does not support it.';
            } else {
                $statusValue = in_array($newStatus, ['Active', 'Inactive'], true) ? $newStatus : 'Inactive';
                $update      = $pdo->prepare('UPDATE PET_CATEGORY SET STATUS = :status WHERE CATEGORY_ID = :id');
                $update->execute(['status' => $statusValue, 'id' => $categoryId]);
                header('Location: user_management.php?tab=pet_category&success=1');
                exit();
            }
        } elseif ($action === 'add_service') {
            $serviceName        = trim($_POST['service_name'] ?? '');
            $serviceDescription = trim($_POST['service_description'] ?? '');
            $price              = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($serviceName === '' || $price === false || $price < 0) {
                $formErrors['service'] = 'Please complete the service form and provide a valid price.';
            } else {
                $newId = getNextId($pdo, 'SERVICE', 'SERVICE_ID');
                if ($supportsStatus['SERVICE']) {
                    $insert = $pdo->prepare("INSERT INTO SERVICE (SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE, STATUS) VALUES (:id, :name, :desc, :price, 'Active')");
                } else {
                    $insert = $pdo->prepare('INSERT INTO SERVICE (SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE) VALUES (:id, :name, :desc, :price)');
                }
                $insert->execute(['id' => $newId, 'name' => $serviceName, 'desc' => $serviceDescription, 'price' => $price]);
                header('Location: user_management.php?tab=service&success=1');
                exit();
            }
        } elseif ($action === 'edit_service') {
            $serviceId          = filter_var($_POST['service_id'] ?? '', FILTER_VALIDATE_INT);
            $serviceName        = trim($_POST['service_name'] ?? '');
            $serviceDescription = trim($_POST['service_description'] ?? '');
            $price              = filter_var($_POST['price'] ?? '', FILTER_VALIDATE_FLOAT);
            $recordStatus       = trim($_POST['status'] ?? 'Active');
            if (!$serviceId || $serviceName === '' || $price === false || $price < 0) {
                $formErrors['service'] = 'Please complete the service update form and provide a valid price.';
            } else {
                $fields = 'SERVICE_NAME = :name, SERVICE_DESCRIPTION = :desc, PRICE = :price';
                if ($supportsStatus['SERVICE']) { $fields .= ', STATUS = :status'; }
                $sql    = "UPDATE SERVICE SET $fields WHERE SERVICE_ID = :id";
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
                $update      = $pdo->prepare('UPDATE SERVICE SET STATUS = :status WHERE SERVICE_ID = :id');
                $update->execute(['status' => $statusValue, 'id' => $serviceId]);
                header('Location: user_management.php?tab=service&success=1');
                exit();
            }
        } elseif ($action === 'add_employee') {
            $empId = filter_var($_POST['employee_id'] ?? '', FILTER_VALIDATE_INT);
            $empName = trim($_POST['employee_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $userGroup = filter_var($_POST['user_group_id'] ?? '', FILTER_VALIDATE_INT);
            $status = trim($_POST['account_status'] ?? 'Active');

            if (!$empId || $empName === '' || $username === '' || $password === '' || !$userGroup) {
                $formErrors['employee'] = 'Please complete all required fields.';
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM EMPLOYEE WHERE Employee_ID = ?");
                $stmt->execute([$empId]);
                if ($stmt->fetchColumn() > 0) {
                    $formErrors['employee'] = 'Employee ID already exists.';
                } else {
                    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM USER_ACCOUNT WHERE Username = ?");
                    $stmt2->execute([$username]);
                    if ($stmt2->fetchColumn() > 0) {
                        $formErrors['employee'] = 'Username already exists.';
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $hash = password_hash($password, PASSWORD_BCRYPT);
                            
                            $insEmp = $pdo->prepare("INSERT INTO EMPLOYEE (Employee_ID, Employee_Username, Password_Hash) VALUES (?, ?, ?)");
                            $insEmp->execute([$empId, $empName, $hash]);

                            $accId = getNextId($pdo, 'USER_ACCOUNT', 'ACCOUNT_ID');
                            $insAcc = $pdo->prepare("INSERT INTO USER_ACCOUNT (Account_ID, Username, Account_Status, Password_Hash, Employee_ID, User_Group_ID) VALUES (?, ?, ?, ?, ?, ?)");
                            $insAcc->execute([$accId, $username, $status, $hash, $empId, $userGroup]);

                            $pdo->commit();
                            header('Location: user_management.php?tab=employee&success=1');
                            exit();
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            throw $e;
                        }
                    }
                }
            }
        } elseif ($action === 'edit_employee') {
            $accId = filter_var($_POST['account_id'] ?? '', FILTER_VALIDATE_INT);
            $userGroup = filter_var($_POST['user_group_id'] ?? '', FILTER_VALIDATE_INT);
            $status = trim($_POST['account_status'] ?? '');

            if (!$accId || !$userGroup || !in_array($status, ['Active', 'Inactive'])) {
                $formErrors['employee'] = 'Invalid form data provided for account update.';
            } else {
                $update = $pdo->prepare("UPDATE USER_ACCOUNT SET User_Group_ID = ?, Account_Status = ? WHERE Account_ID = ?");
                $update->execute([$userGroup, $status, $accId]);
                header('Location: user_management.php?tab=employee&success=1');
                exit();
            }
        } elseif ($action === 'update_username') {
            $username  = trim($_POST['username'] ?? '');
            $accountId = $_SESSION['account_id'];
            if ($username === '') {
                $formErrors['account'] = 'Username cannot be blank.';
                $redirectTab = 'account';
            } else {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM USER_ACCOUNT WHERE USERNAME = :username AND ACCOUNT_ID <> :id');
                $stmt->execute(['username' => $username, 'id' => $accountId]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $formErrors['account'] = 'That username is already taken.';
                    $redirectTab = 'account';
                } else {
                    $update = $pdo->prepare('UPDATE USER_ACCOUNT SET USERNAME = :username WHERE ACCOUNT_ID = :id');
                    $update->execute(['username' => $username, 'id' => $accountId]);
                    header('Location: user_management.php?tab=account&success=1');
                    exit();
                }
            }
        } elseif ($action === 'change_password') {
            $current     = $_POST['current_password'] ?? '';
            $newPass     = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';
            $accountId   = $_SESSION['account_id'];
            if ($current === '' || $newPass === '' || $confirmPass === '') {
                $formErrors['password'] = 'Please complete all password fields.';
                $redirectTab = 'account';
            } elseif ($newPass !== $confirmPass) {
                $formErrors['password'] = 'New password and confirm password must match.';
                $redirectTab = 'account';
            } elseif (strlen($newPass) < 8) {
                $formErrors['password'] = 'New password must be at least 8 characters.';
                $redirectTab = 'account';
            } else {
                $stmt = $pdo->prepare('SELECT PASSWORD_HASH FROM USER_ACCOUNT WHERE ACCOUNT_ID = :id');
                $stmt->execute(['id' => $accountId]);
                $hash = $stmt->fetchColumn();
                if (!$hash || !password_verify($current, $hash)) {
                    $formErrors['password'] = 'Current password is incorrect.';
                    $redirectTab = 'account';
                } else {
                    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                    $update  = $pdo->prepare('UPDATE USER_ACCOUNT SET PASSWORD_HASH = :hash WHERE ACCOUNT_ID = :id');
                    $update->execute(['hash' => $newHash, 'id' => $accountId]);
                    header('Location: user_management.php?tab=account&success=1');
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
        } elseif (in_array($action, ['add_employee', 'edit_employee'], true)) {
            $formErrors['employee'] = $message;
        } elseif ($action === 'update_username') {
            $formErrors['account'] = $message;
        } elseif ($action === 'change_password') {
            $formErrors['password'] = $message;
        }
    }
    $activeTab = $redirectTab;
}

$statusFilter           = $supportsStatus['TIER']         ? "WHERE STATUS = 'Active'" : '';
$inactiveTierFilter     = $supportsStatus['TIER']         ? "WHERE STATUS = 'Inactive'" : '';
$activeCategoryFilter   = $supportsStatus['PET_CATEGORY'] ? "WHERE STATUS = 'Active'" : '';
$inactiveCategoryFilter = $supportsStatus['PET_CATEGORY'] ? "WHERE STATUS = 'Inactive'" : '';
$activeServiceFilter    = $supportsStatus['SERVICE']      ? "WHERE STATUS = 'Active'" : '';
$inactiveServiceFilter  = $supportsStatus['SERVICE']      ? "WHERE STATUS = 'Inactive'" : '';

$activeTiers        = $pdo->query("SELECT TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE " . ($supportsStatus['TIER'] ? ', STATUS' : '') . " FROM TIER $statusFilter ORDER BY TIER_ID")->fetchAll(PDO::FETCH_ASSOC);
$allTiers           = $pdo->query('SELECT TIER_ID, TIER_NAME FROM TIER ORDER BY TIER_ID')->fetchAll(PDO::FETCH_ASSOC);
$inactiveTiers      = $supportsStatus['TIER'] ? $pdo->query("SELECT TIER_ID, TIER_NAME, TIER_DESCRIPTION, WEIGHT_MIN, WEIGHT_MAX, DAILY_RATE, STATUS FROM TIER $inactiveTierFilter ORDER BY TIER_ID")->fetchAll(PDO::FETCH_ASSOC) : [];
$activeCategories   = $pdo->query("SELECT CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES " . ($supportsStatus['PET_CATEGORY'] ? ', STATUS' : '') . " FROM PET_CATEGORY $activeCategoryFilter ORDER BY CATEGORY_ID")->fetchAll(PDO::FETCH_ASSOC);
$inactiveCategories = $supportsStatus['PET_CATEGORY'] ? $pdo->query("SELECT CATEGORY_ID, CATEGORY_NAME, SPECIES_NOTES, STATUS FROM PET_CATEGORY $inactiveCategoryFilter ORDER BY CATEGORY_ID")->fetchAll(PDO::FETCH_ASSOC) : [];
$activeServices     = $pdo->query("SELECT SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE " . ($supportsStatus['SERVICE'] ? ', STATUS' : '') . " FROM SERVICE $activeServiceFilter ORDER BY SERVICE_ID")->fetchAll(PDO::FETCH_ASSOC);
$inactiveServices   = $supportsStatus['SERVICE'] ? $pdo->query("SELECT SERVICE_ID, SERVICE_NAME, SERVICE_DESCRIPTION, PRICE, STATUS FROM SERVICE $inactiveServiceFilter ORDER BY SERVICE_ID")->fetchAll(PDO::FETCH_ASSOC) : [];
$accommodations     = $pdo->query(
    'SELECT A.ACCOMMODATION_ID, A.UNIT_NAME, A.ACCOMMODATION_TYPE, A.OCCUPANCY_STATUS, A.TIER_ID, T.TIER_NAME FROM ACCOMMODATION A JOIN TIER T ON A.TIER_ID = T.TIER_ID ORDER BY A.ACCOMMODATION_ID'
)->fetchAll(PDO::FETCH_ASSOC);

$employeesList      = $pdo->query("
    SELECT e.Employee_ID, e.Employee_Username, u.Account_ID, u.Username, u.Account_Status, g.Group_Name, u.User_Group_ID 
    FROM EMPLOYEE e 
    JOIN USER_ACCOUNT u ON e.Employee_ID = u.Employee_ID 
    JOIN USER_GROUP g ON u.User_Group_ID = g.User_Group_ID 
    ORDER BY e.Employee_ID
")->fetchAll(PDO::FETCH_ASSOC);

$countsStmt        = $pdo->query('SELECT TIER_ID, COUNT(*) AS CNT FROM ACCOMMODATION GROUP BY TIER_ID');
$accommodationCounts = [];
while ($row = $countsStmt->fetch(PDO::FETCH_ASSOC)) {
    $accommodationCounts[$row['TIER_ID']] = (int) $row['CNT'];
}

$currentUserStmt = $pdo->prepare(
    'SELECT UA.ACCOUNT_ID, UA.USERNAME, UA.ACCOUNT_STATUS, E.EMPLOYEE_USERNAME, UG.GROUP_NAME FROM USER_ACCOUNT UA JOIN EMPLOYEE E ON UA.EMPLOYEE_ID = E.EMPLOYEE_ID JOIN USER_GROUP UG ON UA.USER_GROUP_ID = UG.USER_GROUP_ID WHERE UA.ACCOUNT_ID = :id'
);
$currentUserStmt->execute(['id' => $_SESSION['account_id']]);
$currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC) ?: ['USERNAME' => '', 'ACCOUNT_STATUS' => '', 'EMPLOYEE_USERNAME' => '', 'GROUP_NAME' => ''];

$role = $_SESSION['user_group_id'] == 1 ? 'Administrator' : 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management – Radog's Kennel Pet Hotel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --orange:       #FA8112;
            --orange-dk:    #d96a08;
            --black:        #222222;
            --beige:        #FAF3E1;
            --gold:         #F5E7C6;
            --white:        #ffffff;
            --radius-card:  20px;
            --radius-input: 12px;
            --radius-btn:   12px;
            --shadow-card:  0 24px 70px rgba(15, 23, 42, 0.08);
            --border-soft:  1px solid rgba(34, 34, 34, 0.08);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { min-height: 100%; }
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
        a { text-decoration: none; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── SIDEBAR ─────────────────────────────────────── */
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
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) both;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 24px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.05s both;
        }
        .sidebar-logo {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .sidebar-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: drop-shadow(0 0 12px rgba(250,129,18,0.5));
        }
        .sidebar-logo-placeholder {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--orange), #f5b44a);
            display: grid;
            place-items: center;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.4rem;
            color: var(--white);
            letter-spacing: 0.1em;
            flex-shrink: 0;
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

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.18);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 28px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }
        .sidebar-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--orange);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1rem;
            color: var(--white);
            display: grid;
            place-items: center;
            flex-shrink: 0;
        }
        .sidebar-user-name {
            font-size: 0.88rem;
            font-weight: 600;
            color: var(--white);
            line-height: 1.2;
        }
        .sidebar-user-role {
            font-size: 0.72rem;
            color: var(--orange);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .nav-section-label {
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: rgba(245,231,198,0.4);
            padding: 0 4px;
            margin-bottom: 8px;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.15s both;
        }
        .nav-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.2s both;
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
            text-decoration: none;
        }
        .nav-link svg { width: 17px; height: 17px; opacity: 0.8; flex-shrink: 0; }
        .nav-link:hover { background: rgba(250,129,18,0.1); color: var(--white); }
        .nav-link.active { background: var(--orange); color: var(--white); font-weight: 600; }
        .nav-link.active svg { opacity: 1; }

        .sidebar-spacer { flex: 1; }

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
            margin-top: 16px;
        }
        .logout-btn svg { width: 16px; height: 16px; }
        .logout-btn:hover { background: rgba(250,129,18,0.12); border-color: var(--orange); color: var(--white); }

        /* ── MAIN CONTENT ────────────────────────────────── */
        .main-content {
            flex-grow: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both;
        }

        /* ── TOP BAR ─────────────────────────────────────── */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 24px 44px 0 44px;
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
        .page-title    { font-size: 2.6rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); }

        .top-bar-right { display: flex; align-items: center; gap: 12px; }

        .profile-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--orange);
            display: grid;
            place-items: center;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.2rem;
            color: var(--white);
            cursor: pointer;
            border: 3px solid var(--white);
            box-shadow: 0 4px 16px rgba(250,129,18,0.35);
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
            flex-shrink: 0;
        }
        .profile-circle:hover {
            transform: scale(1.08);
            box-shadow: 0 6px 24px rgba(250,129,18,0.5);
        }
        .profile-circle-tooltip {
            position: absolute;
            bottom: -32px;
            right: 0;
            background: var(--black);
            color: var(--white);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.72rem;
            padding: 4px 10px;
            border-radius: 6px;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.18s;
        }
        .profile-circle:hover .profile-circle-tooltip { opacity: 1; }

        /* ── FOLDER TAB BAR ──────────────────────────────── */
        .folder-tab-area {
            padding: 28px 44px 0 44px;
        }

        .folder-tabs {
            display: flex;
            align-items: flex-end;
            gap: 0;
            position: relative;
        }

        .folder-tab {
            position: relative;
            padding: 11px 26px 18px 26px;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem;
            letter-spacing: 0.1em;
            cursor: pointer;
            border: none;
            background: transparent;
            color: rgba(34,34,34,0.45);
            border-radius: 14px 14px 0 0;
            margin-right: -6px;
            transition: color 0.18s;
            z-index: 1;
            outline: none;
        }

        .folder-tab span {
            position: relative;
            z-index: 2;
            pointer-events: none;
            display: block;
        }

        .folder-tab::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 14px 14px 0 0;
            background: rgba(34,34,34,0.06);
            border: 1.5px solid rgba(34,34,34,0.1);
            border-bottom: none;
            transition: background 0.18s;
            z-index: 0;
        }

        .folder-tab:hover {
            color: var(--black);
            z-index: 2;
        }
        .folder-tab:hover::before {
            background: rgba(250,129,18,0.1);
            border-color: rgba(250,129,18,0.25);
        }

        .folder-tab.active {
            color: var(--black);
            z-index: 10;
        }
        .folder-tab.active::before {
            background: var(--white);
            border-color: rgba(34,34,34,0.1);
            box-shadow: 0 -4px 16px rgba(15,23,42,0.06);
        }

        .folder-tab.active::after {
            content: '';
            position: absolute;
            bottom: 7px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--orange);
            z-index: 2;
        }

        .folder-content-wrap {
            background: var(--white);
            border: 1.5px solid rgba(34,34,34,0.1);
            border-radius: 0 16px 16px 16px;
            padding: 28px;
            box-shadow: var(--shadow-card);
            position: relative;
            z-index: 5;
        }

        /* ── PANEL / CARD ────────────────────────────────── */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: 20px;
            padding: 28px;
            box-shadow: var(--shadow-card);
            margin-bottom: 20px;
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
        .panel-header-left { display: flex; align-items: center; gap: 12px; }
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
        .panel-icon svg { width: 18px; height: 18px; color: var(--orange); fill: var(--orange); }
        .panel-heading  { font-family: 'Bebas Neue', sans-serif; font-size: 1.3rem; color: var(--black); letter-spacing: 0.05em; }
        .panel-subtext  { font-size: 0.8rem; color: rgba(34,34,34,0.45); margin-top: 2px; }

        /* ── BUTTONS ─────────────────────────────────────── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            border: none;
            border-radius: 12px;
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem;
            letter-spacing: 0.12em;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn svg   { width: 16px; height: 16px; flex-shrink: 0; }
        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }
        .btn-danger  { background: var(--orange); color: var(--white); }
        .btn-danger:hover  { background: var(--orange-dk); box-shadow: 0 6px 20px rgba(250,129,18,0.35); }
        .btn-ghost   { background: transparent; color: var(--black); border: 1.5px solid rgba(34,34,34,0.18); }
        .btn-ghost:hover { background: rgba(34,34,34,0.04); border-color: var(--orange); }
        .btn-sm { padding: 8px 14px; font-size: 0.78rem; }

        /* ── FORMS ───────────────────────────────────────── */
        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #4a3f33;
            margin-bottom: 8px;
        }
        input, select, textarea {
            width: 100%;
            padding: 13px 16px;
            border: 1.5px solid #e2d9ce;
            border-radius: 12px;
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
        input[readonly] { opacity: 0.65; cursor: default; }
        textarea { resize: vertical; min-height: 90px; }
        .form-grid { display: grid; gap: 18px; }
        .form-grid-2 { grid-template-columns: 1fr 1fr; }
        .field-prefix-wrap { display: flex; align-items: center; gap: 0; }
        .field-prefix-wrap .prefix-symbol {
            background: #e2d9ce;
            border: 1.5px solid #e2d9ce;
            border-right: none;
            border-radius: 12px 0 0 12px;
            padding: 13px 14px;
            font-size: 0.95rem;
            color: #6b5a48;
            font-weight: 600;
            line-height: 1;
        }
        .field-prefix-wrap input {
            border-radius: 0 12px 12px 0;
            border-left: none;
        }
        .field-prefix-wrap input:focus { border-color: var(--orange); }

        /* ── ALERTS ──────────────────────────────────────── */
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
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }

        /* ── DATA TABLE ──────────────────────────────────── */
        .table-wrap { overflow-x: auto; }
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
            white-space: nowrap;
        }
        .data-table tbody tr { border-bottom: 1px solid rgba(34,34,34,0.05); transition: background 0.15s; }
        .data-table tbody tr:hover { background: rgba(250,129,18,0.03); }
        .data-table td { padding: 16px 20px; font-size: 0.92rem; vertical-align: middle; }
        .td-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

        /* ── STATUS BADGES ───────────────────────────────── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 99px;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; }
        .badge-available   { background: rgba(34,197,94,0.12);  color: #166534; }
        .badge-booked      { background: rgba(250,129,18,0.12); color: var(--orange-dk); }
        .badge-maintenance { background: rgba(148,163,184,0.15); color: #475569; }
        .badge-free        { background: rgba(34,197,94,0.12);  color: #166534; }
        .dot-available   { background: #22c55e; }
        .dot-booked      { background: var(--orange); }
        .dot-maintenance { background: #94a3b8; }

        /* ── INACTIVE SECTION ────────────────────────────── */
        .inactive-section { margin-top: 24px; }
        .inactive-section-title {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.1rem;
            color: rgba(34,34,34,0.45);
            letter-spacing: 0.08em;
            margin-bottom: 14px;
            padding-top: 16px;
            border-top: 1px dashed rgba(34,34,34,0.1);
        }
        .hidden { display: none !important; }
        .show-inactive-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 99px;
            background: rgba(250,129,18,0.08);
            border: 1px solid rgba(250,129,18,0.2);
            color: var(--orange-dk);
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.18s;
        }
        .show-inactive-btn:hover { background: rgba(250,129,18,0.16); }

        /* ── ACCOUNT TAB ─────────────────────────────────── */
        .account-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        .account-info-card {
            /* FIXED: Use background property with multiple values to ensure layers are retained */
            background: 
                repeating-linear-gradient(
                    -55deg, transparent, transparent 18px,
                    rgba(250,129,18,0.05) 18px, rgba(250,129,18,0.05) 19px
                ),
                linear-gradient(135deg, var(--black), #1a1a2e);
            border-radius: 16px;
            padding: 24px;
            color: var(--white);
            grid-column: span 2;
        }
        
        .account-info-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        .account-avatar-large {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background: var(--orange);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.6rem;
            color: var(--white);
            display: grid;
            place-items: center;
            box-shadow: 0 0 0 4px rgba(250,129,18,0.25);
        }
        .account-info-name  { font-family: 'Bebas Neue', sans-serif; font-size: 1.6rem; line-height: 1; }
        .account-info-role  { font-size: 0.8rem; color: var(--orange); letter-spacing: 0.1em; text-transform: uppercase; margin-top: 3px; }
        .account-meta-grid  { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .account-meta-label { font-size: 0.7rem; letter-spacing: 0.14em; text-transform: uppercase; color: rgba(245,231,198,0.45); margin-bottom: 4px; }
        .account-meta-value { font-size: 0.95rem; color: rgba(255,255,255,0.9); font-weight: 500; }
        .account-status-active   { color: #86efac; }
        .account-status-inactive { color: #fca5a5; }

        /* ── MODAL ───────────────────────────────────────── */
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
            width: min(100%, 440px);
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 32px 80px rgba(15,23,42,0.18);
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
            position: sticky;
            top: 0;
            z-index: 1;
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
            font-size: 1.4rem;
            line-height: 1;
        }
        .modal-close:hover { background: rgba(250,129,18,0.2); color: var(--white); }
        .modal-body { padding: 28px; }
        .modal-body .form-grid { gap: 18px; }

        /* ── RESPONSIVE ──────────────────────────────────── */
        @media (max-width: 1100px) {
            .top-bar, .folder-tab-area { padding-left: 24px; padding-right: 24px; }
        }
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .top-bar, .folder-tab-area { padding: 20px 16px 0 16px; }
            .folder-content-wrap { padding: 20px 16px; border-radius: 0 0 16px 16px; }
            .form-grid-2 { grid-template-columns: 1fr; }
            .account-grid { grid-template-columns: 1fr; }
            .account-info-card { grid-column: span 1; }
            .account-meta-grid { grid-template-columns: 1fr 1fr; }
            .folder-tab { padding: 9px 14px 16px 14px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo-placeholder">RK</div>
        <div>
            <div class="sidebar-wordmark-top">Radog's Kennel</div>
            <div class="sidebar-wordmark-sub">Pet Hotel Management</div>
        </div>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-avatar"><?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)); ?></div>
        <div>
            <div class="sidebar-user-name"><?php echo escape($_SESSION['username'] ?? 'Admin'); ?></div>
            <div class="sidebar-user-role"><?php echo escape($role); ?></div>
        </div>
    </div>

    <div class="nav-section-label">Navigation</div>
    <nav class="nav-list">
        <a href="admin_dashboard.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 13h6V4H4v9zm0 7h6v-5H4v5zm10 0h6V11h-6v9zm0-18v7h6V2h-6z"/></svg>
            Dashboard
        </a>
        <a href="encode_reservation.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 16H5V9h14v11zm0-13H5V6h14v1z"/></svg>
            Schedule
        </a>
        <a href="calendar.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4H5V6h14v2zm0 12H5V10h14v10z"/></svg>
            Calendar
        </a>
        <a href="owner.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>
            Owners
        </a>
        <a href="pets.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M4.5 11c.8 0 1.5-.7 1.5-1.5v-4C6 4.7 5.3 4 4.5 4S3 4.7 3 5.5v4c0 .8.7 1.5 1.5 1.5zm6.5-1.5c0 .8-.7 1.5-1.5 1.5S8 10.3 8 9.5v-4C8 4.7 8.7 4 9.5 4S11 4.7 11 5.5v4zm4-4C15 4.7 15.7 4 16.5 4S18 4.7 18 5.5v4c0 .8-.7 1.5-1.5 1.5S15 10.3 15 9.5v-4zm-2.28 9.59L10.5 12.5C9.12 11.59 7.5 12.56 7.5 14.15v.09c0 .94.47 1.82 1.25 2.34l2.48 1.65c.14.09.27.16.42.2.39.12.83.06 1.18-.18l2.42-1.62c.78-.52 1.25-1.4 1.25-2.34v-.13c-.01-1.57-1.62-2.55-3.03-1.62z"/></svg>
            Pets
        </a>
        <a href="checkout.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z"/></svg>
            Checkout / Payments
        </a>
        <a href="user_management.php" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
            User Management
        </a>
    </nav>

    <div class="sidebar-spacer"></div>

    <a href="../logout.php" class="logout-btn">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M16 13v-2H7V8l-5 4 5 4v-3h9zM20 3h-8v2h8v14h-8v2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/></svg>
        Logout
    </a>
</aside>

<main class="main-content">

    <div class="top-bar">
        <div>
            <div class="page-eyebrow">Administration</div>
            <h1 class="page-title">User Management</h1>
            <p class="page-subtitle">Manage accommodations, tiers, pet categories, services, and your account settings.</p>
        </div>
        <div class="top-bar-right">
            <div class="profile-circle" id="profileCircleBtn" title="My Account" role="button" tabindex="0" aria-label="Go to My Account">
                <?php echo strtoupper(substr($_SESSION['username'] ?? 'A', 0, 1)); ?>
                <span class="profile-circle-tooltip">My Account</span>
            </div>
        </div>
    </div>

    <div class="folder-tab-area">
        <div class="folder-tabs" role="tablist">
            <button type="button" class="folder-tab" data-tab="accommodation" role="tab"><span>Accommodation</span></button>
            <button type="button" class="folder-tab" data-tab="tier" role="tab"><span>Tier</span></button>
            <button type="button" class="folder-tab" data-tab="pet_category" role="tab"><span>Pet Category</span></button>
            <button type="button" class="folder-tab" data-tab="service" role="tab"><span>Service</span></button>
            <button type="button" class="folder-tab" data-tab="employee" role="tab"><span>Employees</span></button>
            <button type="button" class="folder-tab" data-tab="account" role="tab"><span>My Account</span></button>
        </div>

        <div class="folder-content-wrap">

            <div id="panel-accommodation" class="tab-panel">
                <?php if ($activeTab === 'accommodation' && $success): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Accommodation updated successfully.
                    </div>
                <?php endif; ?>
                <?php if ($formErrors['accommodation']): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <?php echo escape($formErrors['accommodation']); ?>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-header-left">
                            <div class="panel-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
                            </div>
                            <div>
                                <div class="panel-heading">Accommodation Units</div>
                                <div class="panel-subtext"><?php echo count($accommodations); ?> unit(s) registered</div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary" id="openAddAccommodation">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                            Add Unit
                        </button>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Unit Name</th>
                                    <th>Type</th>
                                    <th>Tier</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($accommodations as $unit): ?>
                                <?php
                                    $badgeClass = 'badge-maintenance'; $dotClass = 'dot-maintenance';
                                    if ($unit['OCCUPANCY_STATUS'] === 'Available') { $badgeClass = 'badge-available'; $dotClass = 'dot-available'; }
                                    elseif ($unit['OCCUPANCY_STATUS'] === 'Booked') { $badgeClass = 'badge-booked'; $dotClass = 'dot-booked'; }
                                ?>
                                <tr>
                                    <td><strong><?php echo escape($unit['UNIT_NAME']); ?></strong></td>
                                    <td><?php echo escape($unit['ACCOMMODATION_TYPE']); ?></td>
                                    <td><?php echo escape($unit['TIER_NAME']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $badgeClass; ?>">
                                            <span class="status-dot <?php echo $dotClass; ?>"></span>
                                            <?php echo escape($unit['OCCUPANCY_STATUS']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="td-actions">
                                            <button type="button" class="btn btn-ghost btn-sm edit-accommodation"
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
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Put this unit under maintenance?');">Deactivate</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($accommodations)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:32px;color:rgba(34,34,34,0.4);">No accommodation units yet. Add one to get started.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="panel-tier" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'tier' && $success): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Tier updated successfully.
                    </div>
                <?php endif; ?>
                <?php if ($formErrors['tier']): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <?php echo escape($formErrors['tier']); ?>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-header-left">
                            <div class="panel-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            </div>
                            <div>
                                <div class="panel-heading">Pet Tiers</div>
                                <div class="panel-subtext"><?php echo count($activeTiers); ?> active tier(s)</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;">
                            <?php if ($supportsStatus['TIER']): ?>
                                <button type="button" class="show-inactive-btn" id="toggleTierInactive">Show Inactive</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-primary" id="openAddTier">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                Add Tier
                            </button>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Tier</th>
                                    <th>Description</th>
                                    <th>Weight Range</th>
                                    <th>Daily Rate</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activeTiers as $tier): ?>
                                <tr>
                                    <td><strong><?php echo escape($tier['TIER_NAME']); ?></strong></td>
                                    <td><?php echo escape($tier['TIER_DESCRIPTION']); ?></td>
                                    <td><?php echo escape(number_format($tier['WEIGHT_MIN'], 2)); ?> – <?php echo escape(number_format($tier['WEIGHT_MAX'], 2)); ?> kg</td>
                                    <td>₱<?php echo escape(number_format($tier['DAILY_RATE'], 2)); ?></td>
                                    <td>
                                        <div class="td-actions">
                                            <button type="button" class="btn btn-ghost btn-sm edit-tier"
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
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this tier?');">Deactivate</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($activeTiers)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:32px;color:rgba(34,34,34,0.4);">No tiers found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($supportsStatus['TIER']): ?>
                    <div class="inactive-section hidden" id="tierInactiveSection">
                        <div class="inactive-section-title">Inactive Tiers</div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr><th>Tier</th><th>Description</th><th>Weight Range</th><th>Daily Rate</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($inactiveTiers as $tier): ?>
                                    <tr>
                                        <td><?php echo escape($tier['TIER_NAME']); ?></td>
                                        <td><?php echo escape($tier['TIER_DESCRIPTION']); ?></td>
                                        <td><?php echo escape(number_format($tier['WEIGHT_MIN'], 2)); ?> – <?php echo escape(number_format($tier['WEIGHT_MAX'], 2)); ?> kg</td>
                                        <td>₱<?php echo escape(number_format($tier['DAILY_RATE'], 2)); ?></td>
                                        <td>
                                            <form method="POST" action="user_management.php?tab=tier" style="display:inline;">
                                                <input type="hidden" name="action" value="deactivate_tier">
                                                <input type="hidden" name="tier_id" value="<?php echo escape($tier['TIER_ID']); ?>">
                                                <input type="hidden" name="status" value="Active">
                                                <button type="submit" class="btn btn-primary btn-sm">Reactivate</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($inactiveTiers)): ?>
                                    <tr><td colspan="5" style="text-align:center;padding:24px;color:rgba(34,34,34,0.4);">No inactive tiers.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="panel-pet_category" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'pet_category' && $success): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Pet category updated successfully.
                    </div>
                <?php endif; ?>
                <?php if ($formErrors['pet_category']): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <?php echo escape($formErrors['pet_category']); ?>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-header-left">
                            <div class="panel-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M4.5 11c.8 0 1.5-.7 1.5-1.5v-4C6 4.7 5.3 4 4.5 4S3 4.7 3 5.5v4c0 .8.7 1.5 1.5 1.5zm6.5-1.5c0 .8-.7 1.5-1.5 1.5S8 10.3 8 9.5v-4C8 4.7 8.7 4 9.5 4S11 4.7 11 5.5v4zm4-4C15 4.7 15.7 4 16.5 4S18 4.7 18 5.5v4c0 .8-.7 1.5-1.5 1.5S15 10.3 15 9.5v-4zm-2.28 9.59L10.5 12.5C9.12 11.59 7.5 12.56 7.5 14.15v.09c0 .94.47 1.82 1.25 2.34l2.48 1.65c.14.09.27.16.42.2.39.12.83.06 1.18-.18l2.42-1.62c.78-.52 1.25-1.4 1.25-2.34v-.13c-.01-1.57-1.62-2.55-3.03-1.62z"/></svg>
                            </div>
                            <div>
                                <div class="panel-heading">Pet Categories</div>
                                <div class="panel-subtext"><?php echo count($activeCategories); ?> active categor<?php echo count($activeCategories) === 1 ? 'y' : 'ies'; ?></div>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;">
                            <?php if ($supportsStatus['PET_CATEGORY']): ?>
                                <button type="button" class="show-inactive-btn" id="toggleCategoryInactive">Show Inactive</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-primary" id="openAddCategory">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                Add Category
                            </button>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr><th>Category</th><th>Species Notes</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activeCategories as $cat): ?>
                                <tr>
                                    <td><strong><?php echo escape($cat['CATEGORY_NAME']); ?></strong></td>
                                    <td><?php echo escape($cat['SPECIES_NOTES']); ?></td>
                                    <td>
                                        <div class="td-actions">
                                            <button type="button" class="btn btn-ghost btn-sm edit-category"
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
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this category?');">Deactivate</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($activeCategories)): ?>
                                <tr><td colspan="3" style="text-align:center;padding:32px;color:rgba(34,34,34,0.4);">No categories found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($supportsStatus['PET_CATEGORY']): ?>
                    <div class="inactive-section hidden" id="categoryInactiveSection">
                        <div class="inactive-section-title">Inactive Categories</div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Category</th><th>Species Notes</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($inactiveCategories as $cat): ?>
                                    <tr>
                                        <td><?php echo escape($cat['CATEGORY_NAME']); ?></td>
                                        <td><?php echo escape($cat['SPECIES_NOTES']); ?></td>
                                        <td>
                                            <form method="POST" action="user_management.php?tab=pet_category" style="display:inline;">
                                                <input type="hidden" name="action" value="deactivate_pet_category">
                                                <input type="hidden" name="category_id" value="<?php echo escape($cat['CATEGORY_ID']); ?>">
                                                <input type="hidden" name="status" value="Active">
                                                <button type="submit" class="btn btn-primary btn-sm">Reactivate</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($inactiveCategories)): ?>
                                    <tr><td colspan="3" style="text-align:center;padding:24px;color:rgba(34,34,34,0.4);">No inactive categories.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="panel-service" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'service' && $success): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Service updated successfully.
                    </div>
                <?php endif; ?>
                <?php if ($formErrors['service']): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <?php echo escape($formErrors['service']); ?>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-header-left">
                            <div class="panel-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                            </div>
                            <div>
                                <div class="panel-heading">Services</div>
                                <div class="panel-subtext"><?php echo count($activeServices); ?> active service(s)</div>
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;align-items:center;">
                            <?php if ($supportsStatus['SERVICE']): ?>
                                <button type="button" class="show-inactive-btn" id="toggleServiceInactive">Show Inactive</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-primary" id="openAddService">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                Add Service
                            </button>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr><th>Service Name</th><th>Description</th><th>Price</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($activeServices as $svc): ?>
                                <tr>
                                    <td><strong><?php echo escape($svc['SERVICE_NAME']); ?></strong></td>
                                    <td><?php echo escape($svc['SERVICE_DESCRIPTION']); ?></td>
                                    <td>
                                        <?php if ((float)$svc['PRICE'] === 0.0): ?>
                                            <span class="status-badge badge-free">FREE</span>
                                        <?php else: ?>
                                            ₱<?php echo escape(number_format($svc['PRICE'], 2)); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="td-actions">
                                            <button type="button" class="btn btn-ghost btn-sm edit-service"
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
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Deactivate this service?');">Deactivate</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($activeServices)): ?>
                                <tr><td colspan="4" style="text-align:center;padding:32px;color:rgba(34,34,34,0.4);">No services found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($supportsStatus['SERVICE']): ?>
                    <div class="inactive-section hidden" id="serviceInactiveSection">
                        <div class="inactive-section-title">Inactive Services</div>
                        <div class="table-wrap">
                            <table class="data-table">
                                <thead><tr><th>Service Name</th><th>Description</th><th>Price</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach ($inactiveServices as $svc): ?>
                                    <tr>
                                        <td><?php echo escape($svc['SERVICE_NAME']); ?></td>
                                        <td><?php echo escape($svc['SERVICE_DESCRIPTION']); ?></td>
                                        <td>
                                            <?php if ((float)$svc['PRICE'] === 0.0): ?>
                                                <span class="status-badge badge-free">FREE</span>
                                            <?php else: ?>
                                                ₱<?php echo escape(number_format($svc['PRICE'], 2)); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" action="user_management.php?tab=service" style="display:inline;">
                                                <input type="hidden" name="action" value="deactivate_service">
                                                <input type="hidden" name="service_id" value="<?php echo escape($svc['SERVICE_ID']); ?>">
                                                <input type="hidden" name="status" value="Active">
                                                <button type="submit" class="btn btn-primary btn-sm">Reactivate</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($inactiveServices)): ?>
                                    <tr><td colspan="4" style="text-align:center;padding:24px;color:rgba(34,34,34,0.4);">No inactive services.</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="panel-employee" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'employee' && $success): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Employee updated successfully.
                    </div>
                <?php endif; ?>
                <?php if ($formErrors['employee']): ?>
                    <div class="alert alert-error">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <?php echo escape($formErrors['employee']); ?>
                    </div>
                <?php endif; ?>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-header-left">
                            <div class="panel-icon">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                            </div>
                            <div>
                                <div class="panel-heading">Employees</div>
                                <div class="panel-subtext"><?php echo count($employeesList); ?> user account(s) registered</div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary" id="openAddEmployee">
                            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                            Create New User
                        </button>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Emp ID</th>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>Group</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($employeesList as $emp): ?>
                                <?php
                                    $empBadge = $emp['ACCOUNT_STATUS'] === 'Active' ? 'badge-available' : 'badge-maintenance';
                                    $empDot = $emp['ACCOUNT_STATUS'] === 'Active' ? 'dot-available' : 'dot-maintenance';
                                ?>
                                <tr>
                                    <td><?php echo escape($emp['EMPLOYEE_ID']); ?></td>
                                    <td><strong><?php echo escape($emp['EMPLOYEE_USERNAME']); ?></strong></td>
                                    <td><?php echo escape($emp['USERNAME']); ?></td>
                                    <td><?php echo escape($emp['GROUP_NAME']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $empBadge; ?>">
                                            <span class="status-dot <?php echo $empDot; ?>"></span>
                                            <?php echo escape($emp['ACCOUNT_STATUS']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="td-actions">
                                            <button type="button" class="btn btn-ghost btn-sm edit-employee"
                                                data-acc-id="<?php echo escape($emp['ACCOUNT_ID']); ?>"
                                                data-emp-id="<?php echo escape($emp['EMPLOYEE_ID']); ?>"
                                                data-emp-name="<?php echo escape($emp['EMPLOYEE_USERNAME']); ?>"
                                                data-username="<?php echo escape($emp['USERNAME']); ?>"
                                                data-group-id="<?php echo escape($emp['USER_GROUP_ID']); ?>"
                                                data-status="<?php echo escape($emp['ACCOUNT_STATUS']); ?>">
                                                Edit
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($employeesList)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:32px;color:rgba(34,34,34,0.4);">No employee accounts found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="panel-account" class="tab-panel" style="display:none;">
                <?php if ($activeTab === 'account' && $success): ?>
                    <div class="alert alert-success">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                        Account settings saved successfully.
                    </div>
                <?php endif; ?>

                <div class="account-info-card">
                    <div class="account-info-header">
                        <div class="account-avatar-large"><?php echo strtoupper(substr($currentUser['USERNAME'] ?? 'A', 0, 1)); ?></div>
                        <div>
                            <div class="account-info-name"><?php echo escape($currentUser['USERNAME']); ?></div>
                            <div class="account-info-role"><?php echo escape($currentUser['GROUP_NAME']); ?></div>
                        </div>
                    </div>
                    <div class="account-meta-grid">
                        <div class="account-meta-item">
                            <div class="account-meta-label">Employee Username</div>
                            <div class="account-meta-value"><?php echo escape($currentUser['EMPLOYEE_USERNAME']); ?></div>
                        </div>
                        <div class="account-meta-item">
                            <div class="account-meta-label">Account Status</div>
                            <div class="account-meta-value <?php echo $currentUser['ACCOUNT_STATUS'] === 'Active' ? 'account-status-active' : 'account-status-inactive'; ?>">
                                <?php echo escape($currentUser['ACCOUNT_STATUS']); ?>
                            </div>
                        </div>
                        <div class="account-meta-item">
                            <div class="account-meta-label">Role / Group</div>
                            <div class="account-meta-value"><?php echo escape($currentUser['GROUP_NAME']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="account-grid" style="margin-top:20px;">
                    <div class="panel">
                        <div class="panel-header" style="margin-bottom:20px;">
                            <div class="panel-header-left">
                                <div class="panel-icon">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>
                                </div>
                                <div class="panel-heading">Edit Username</div>
                            </div>
                        </div>
                        <?php if ($formErrors['account']): ?>
                            <div class="alert alert-error">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                                <?php echo escape($formErrors['account']); ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="user_management.php" class="form-grid">
                            <div>
                                <label class="field-label" for="account_username">New Username</label>
                                <input type="text" id="account_username" name="username" value="<?php echo escape($currentUser['USERNAME']); ?>" required>
                            </div>
                            <input type="hidden" name="action" value="update_username">
                            <input type="hidden" name="tab" value="account">
                            <div>
                                <button type="submit" class="btn btn-primary">Save Username</button>
                            </div>
                        </form>
                    </div>

                    <div class="panel">
                        <div class="panel-header" style="margin-bottom:20px;">
                            <div class="panel-header-left">
                                <div class="panel-icon">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                                </div>
                                <div class="panel-heading">Change Password</div>
                            </div>
                        </div>
                        <?php if ($formErrors['password']): ?>
                            <div class="alert alert-error">
                                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                                <?php echo escape($formErrors['password']); ?>
                            </div>
                        <?php endif; ?>
                        <form method="POST" action="user_management.php" class="form-grid">
                            <div>
                                <label class="field-label" for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" required>
                            </div>
                            <div>
                                <label class="field-label" for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" required>
                            </div>
                            <div>
                                <label class="field-label" for="confirm_password">Confirm New Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            <input type="hidden" name="action" value="change_password">
                            <input type="hidden" name="tab" value="account">
                            <div>
                                <button type="submit" class="btn btn-primary">Change Password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div></div></main>

<div class="modal-overlay" id="accountModal">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="accountModalTitle">
        <div class="modal-head">
            <span class="modal-title" id="accountModalTitle">Edit</span>
            <button type="button" class="modal-close" id="closeAccountModal" aria-label="Close">&#x2715;</button>
        </div>
        <div class="modal-body" id="modalBody">
            </div>
    </div>
</div>

<script>
(function () {
    /* ── DATA FROM PHP ──────────────────────────────── */
    const accommodationCounts = <?php echo json_encode($accommodationCounts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const allTiers             = <?php echo json_encode(array_map(fn($t) => ['id' => (int)$t['TIER_ID'], 'name' => $t['TIER_NAME']], $activeTiers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const supportsStatusTier   = <?php echo $supportsStatus['TIER'] ? 'true' : 'false'; ?>;
    const supportsStatusCat    = <?php echo $supportsStatus['PET_CATEGORY'] ? 'true' : 'false'; ?>;
    const supportsStatusSvc    = <?php echo $supportsStatus['SERVICE'] ? 'true' : 'false'; ?>;
    const initialTab           = <?php echo json_encode($activeTab); ?>;

    /* ── ELEMENTS ───────────────────────────────────── */
    const modal          = document.getElementById('accountModal');
    const modalTitle     = document.getElementById('accountModalTitle');
    const modalBody      = document.getElementById('modalBody');
    const closeModalBtn  = document.getElementById('closeAccountModal');

    /* ── HELPERS ────────────────────────────────────── */
    function esc(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function tierPrefix(name) {
        if (name === 'Small')  return 'CAGE-S';
        if (name === 'Medium') return 'ROOM-M';
        if (name === 'Large')  return 'ROOM-L';
        if (name === 'Giant')  return 'ROOM-G';
        return 'UNIT-';
    }

    function buildTierOptions(selectedId) {
        return allTiers.map(t =>
            `<option value="${t.id}" data-tier-name="${esc(t.name)}" ${String(t.id) === String(selectedId) ? 'selected' : ''}>${esc(t.name)}</option>`
        ).join('');
    }

    function fieldRow(label, inputHtml) {
        return `<div><label class="field-label">${label}</label>${inputHtml}</div>`;
    }

    /* ── MODAL OPEN / CLOSE ─────────────────────────── */
    function openModal(title, bodyHtml, afterRender) {
        modalTitle.textContent = title;
        modalBody.innerHTML    = bodyHtml;
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
        if (typeof afterRender === 'function') afterRender();
    }

    function closeModal() {
        modal.classList.remove('open');
        document.body.style.overflow = '';
    }

    closeModalBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

    /* ── TAB SWITCHING ──────────────────────────────── */
    const tabs   = document.querySelectorAll('.folder-tab');
    const panels = document.querySelectorAll('.tab-panel');

    function showTab(name) {
        panels.forEach(p => p.style.display = 'none');
        tabs.forEach(b => b.classList.remove('active'));
        const target = document.getElementById('panel-' + name);
        const button = document.querySelector('[data-tab="' + name + '"]');
        if (target)  target.style.display  = '';
        if (button)  button.classList.add('active');
        history.replaceState(null, '', '?tab=' + name);
    }

    tabs.forEach(tab => tab.addEventListener('click', () => showTab(tab.dataset.tab)));
    showTab(initialTab);

    /* ── PROFILE CIRCLE → My Account tab ───────────── */
    const profileCircleBtn = document.getElementById('profileCircleBtn');
    if (profileCircleBtn) {
        profileCircleBtn.addEventListener('click', function () {
            showTab('account');
        });
        profileCircleBtn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                showTab('account');
            }
        });
    }

    /* ── UNIT NAME AUTO-GENERATION (in modal) ───────── */
    function setupUnitNameGeneration() {
        const tierSel  = document.getElementById('modal_tier_id');
        const unitInp  = document.getElementById('modal_unit_name');
        if (!tierSel || !unitInp) return;

        function refresh() {
            const tierName = tierSel.options[tierSel.selectedIndex]?.dataset.tierName || '';
            const prefix   = tierPrefix(tierName);
            const cnt      = Number(accommodationCounts[tierSel.value] || 0) + 1;
            unitInp.value  = prefix + cnt;
        }
        tierSel.addEventListener('change', refresh);
        refresh();
    }

    /* ── ADD — ACCOMMODATION ────────────────────────── */
    document.getElementById('openAddAccommodation').addEventListener('click', function () {
        openModal('New Accommodation', `
            <form method="POST" action="user_management.php?tab=accommodation" class="form-grid">
                ${fieldRow('Unit Name (auto-generated)', '<input type="text" id="modal_unit_name" name="unit_name" readonly required>')}
                ${fieldRow('Accommodation Type', '<input type="text" name="accommodation_type" placeholder="Stainless Cage, Airconditioned Room" required>')}
                ${fieldRow('Tier', `<select id="modal_tier_id" name="tier_id" required>${buildTierOptions('')}</select>`)}
                <input type="hidden" name="action" value="add_accommodation">
                <input type="hidden" name="tab" value="accommodation">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Add Accommodation</button>
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                </div>
            </form>
        `, setupUnitNameGeneration);
    });

    /* ── ADD — TIER ─────────────────────────────────── */
    document.getElementById('openAddTier').addEventListener('click', function () {
        openModal('New Tier', `
            <form method="POST" action="user_management.php?tab=tier" class="form-grid">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                    ${fieldRow('Tier Name', '<input type="text" name="tier_name" placeholder="Small, Medium, Large, Giant" required>')}
                    ${fieldRow('Description', '<input type="text" name="tier_description" placeholder="Short tier description">')}
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                    ${fieldRow('Weight Min (kg)', '<input type="number" name="weight_min" min="0" step="0.01" required>')}
                    ${fieldRow('Weight Max (kg)', '<input type="number" name="weight_max" min="0" step="0.01" required>')}
                </div>
                ${fieldRow('Daily Rate (₱)', '<div class="field-prefix-wrap"><span class="prefix-symbol">₱</span><input type="number" name="daily_rate" min="0" step="0.01" required></div>')}
                <input type="hidden" name="action" value="add_tier">
                <input type="hidden" name="tab" value="tier">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Add Tier</button>
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                </div>
            </form>
        `);
    });

    /* ── ADD — PET CATEGORY ─────────────────────────── */
    document.getElementById('openAddCategory').addEventListener('click', function () {
        openModal('New Pet Category', `
            <form method="POST" action="user_management.php?tab=pet_category" class="form-grid">
                ${fieldRow('Category Name', '<input type="text" name="category_name" placeholder="Dog, Cat, Hamster" required>')}
                ${fieldRow('Species Notes / Description', '<textarea name="species_notes" placeholder="Notes for this species"></textarea>')}
                <input type="hidden" name="action" value="add_pet_category">
                <input type="hidden" name="tab" value="pet_category">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Add Category</button>
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                </div>
            </form>
        `);
    });

    /* ── ADD — SERVICE ──────────────────────────────── */
    document.getElementById('openAddService').addEventListener('click', function () {
        openModal('New Service', `
            <form method="POST" action="user_management.php?tab=service" class="form-grid">
                ${fieldRow('Service Name', '<input type="text" name="service_name" placeholder="Premium Bubble Bath" required>')}
                ${fieldRow('Description', '<textarea name="service_description" placeholder="Service details"></textarea>')}
                ${fieldRow('Price (₱)', '<div class="field-prefix-wrap"><span class="prefix-symbol">₱</span><input type="number" name="price" min="0" step="0.01" value="0.00" required></div>')}
                <input type="hidden" name="action" value="add_service">
                <input type="hidden" name="tab" value="service">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Add Service</button>
                    <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                </div>
            </form>
        `);
    });

    /* ── ADD — EMPLOYEE (SINGLE COLUMN) ─────────────── */
    const openAddEmployeeBtn = document.getElementById('openAddEmployee');
    if (openAddEmployeeBtn) {
        openAddEmployeeBtn.addEventListener('click', function () {
            openModal('New User Registration', `
                <form method="POST" action="user_management.php?tab=employee" class="form-grid">
                    ${fieldRow('Employee ID', '<input type="number" name="employee_id" required>')}
                    ${fieldRow('Full Name', '<input type="text" name="employee_name" required>')}
                    ${fieldRow('Username', '<input type="text" name="username" required>')}
                    ${fieldRow('Password', '<input type="password" name="password" required>')}
                    ${fieldRow('User Group', `<select name="user_group_id" required>
                        <option value="1">Administrator</option>
                        <option value="2">Staff</option>
                    </select>`)}
                    ${fieldRow('Account Status', `<select name="account_status" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>`)}
                    <input type="hidden" name="action" value="add_employee">
                    <input type="hidden" name="tab" value="employee">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;">
                        <button type="submit" class="btn btn-primary">Add User</button>
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                    </div>
                </form>
            `);
        });
    }

    /* ── EDIT — ACCOMMODATION ───────────────────────── */
    document.querySelectorAll('.edit-accommodation').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id     = btn.dataset.id;
            const unit   = btn.dataset.unit;
            const type   = btn.dataset.type;
            const tier   = btn.dataset.tier;
            const status = btn.dataset.status;
            const opts   = buildTierOptions(tier);
            openModal('Edit Accommodation', `
                <form method="POST" action="user_management.php?tab=accommodation" class="form-grid">
                    ${fieldRow('Unit Name', `<input type="text" name="unit_name" value="${esc(unit)}" readonly>`)}
                    ${fieldRow('Accommodation Type', `<input type="text" name="accommodation_type" value="${esc(type)}" required>`)}
                    ${fieldRow('Tier', `<select name="tier_id" required>${opts}</select>`)}
                    ${fieldRow('Occupancy Status', `<select name="occupancy_status" required>
                        <option value="Available" ${status === 'Available' ? 'selected' : ''}>Available</option>
                        <option value="Booked" ${status === 'Booked' ? 'selected' : ''}>Booked</option>
                        <option value="Under Maintenance" ${status === 'Under Maintenance' ? 'selected' : ''}>Under Maintenance</option>
                    </select>`)}
                    <input type="hidden" name="action" value="edit_accommodation">
                    <input type="hidden" name="accommodation_id" value="${esc(id)}">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                    </div>
                </form>
            `);
        });
    });

    /* ── EDIT — TIER ────────────────────────────────── */
    document.querySelectorAll('.edit-tier').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const statusField = supportsStatusTier ? fieldRow('Status', `<select name="status">
                <option value="Active" ${btn.dataset.status === 'Active' ? 'selected' : ''}>Active</option>
                <option value="Inactive" ${btn.dataset.status === 'Inactive' ? 'selected' : ''}>Inactive</option>
            </select>`) : '';
            openModal('Edit Tier', `
                <form method="POST" action="user_management.php?tab=tier" class="form-grid">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                        ${fieldRow('Tier Name', `<input type="text" name="tier_name" value="${esc(btn.dataset.name)}" required>`)}
                        ${fieldRow('Description', `<input type="text" name="tier_description" value="${esc(btn.dataset.description)}">`)}
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                        ${fieldRow('Weight Min (kg)', `<input type="number" step="0.01" min="0" name="weight_min" value="${esc(btn.dataset.min)}" required>`)}
                        ${fieldRow('Weight Max (kg)', `<input type="number" step="0.01" min="0" name="weight_max" value="${esc(btn.dataset.max)}" required>`)}
                    </div>
                    ${fieldRow('Daily Rate (₱)', `<div class="field-prefix-wrap"><span class="prefix-symbol">₱</span><input type="number" step="0.01" min="0" name="daily_rate" value="${esc(btn.dataset.rate)}" required></div>`)}
                    ${statusField}
                    <input type="hidden" name="action" value="edit_tier">
                    <input type="hidden" name="tier_id" value="${esc(btn.dataset.id)}">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">Save Tier</button>
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                    </div>
                </form>
            `);
        });
    });

    /* ── EDIT — PET CATEGORY ────────────────────────── */
    document.querySelectorAll('.edit-category').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const statusField = supportsStatusCat ? fieldRow('Status', `<select name="status">
                <option value="Active" ${btn.dataset.status === 'Active' ? 'selected' : ''}>Active</option>
                <option value="Inactive" ${btn.dataset.status === 'Inactive' ? 'selected' : ''}>Inactive</option>
            </select>`) : '';
            openModal('Edit Pet Category', `
                <form method="POST" action="user_management.php?tab=pet_category" class="form-grid">
                    ${fieldRow('Category Name', `<input type="text" name="category_name" value="${esc(btn.dataset.name)}" required>`)}
                    ${fieldRow('Species Notes', `<textarea name="species_notes">${esc(btn.dataset.notes)}</textarea>`)}
                    ${statusField}
                    <input type="hidden" name="action" value="edit_pet_category">
                    <input type="hidden" name="category_id" value="${esc(btn.dataset.id)}">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">Save Category</button>
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                    </div>
                </form>
            `);
        });
    });

    /* ── EDIT — SERVICE ─────────────────────────────── */
    document.querySelectorAll('.edit-service').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const statusField = supportsStatusSvc ? fieldRow('Status', `<select name="status">
                <option value="Active" ${btn.dataset.status === 'Active' ? 'selected' : ''}>Active</option>
                <option value="Inactive" ${btn.dataset.status === 'Inactive' ? 'selected' : ''}>Inactive</option>
            </select>`) : '';
            openModal('Edit Service', `
                <form method="POST" action="user_management.php?tab=service" class="form-grid">
                    ${fieldRow('Service Name', `<input type="text" name="service_name" value="${esc(btn.dataset.name)}" required>`)}
                    ${fieldRow('Description', `<textarea name="service_description">${esc(btn.dataset.desc)}</textarea>`)}
                    ${fieldRow('Price (₱)', `<div class="field-prefix-wrap"><span class="prefix-symbol">₱</span><input type="number" step="0.01" min="0" name="price" value="${esc(btn.dataset.price)}" required></div>`)}
                    ${statusField}
                    <input type="hidden" name="action" value="edit_service">
                    <input type="hidden" name="service_id" value="${esc(btn.dataset.id)}">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">Save Service</button>
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                    </div>
                </form>
            `);
        });
    });

    /* ── EDIT — EMPLOYEE (SINGLE COLUMN) ────────────── */
    document.querySelectorAll('.edit-employee').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal('Edit User', `
                <form method="POST" action="user_management.php?tab=employee" class="form-grid">
                    ${fieldRow('Employee ID', `<input type="text" value="${esc(btn.dataset.empId)}" readonly>`)}
                    ${fieldRow('Full Name', `<input type="text" value="${esc(btn.dataset.empName)}" readonly>`)}
                    ${fieldRow('Username', `<input type="text" value="${esc(btn.dataset.username)}" readonly>`)}
                    ${fieldRow('User Group', `<select name="user_group_id" required>
                        <option value="1" ${btn.dataset.groupId === '1' ? 'selected' : ''}>Administrator</option>
                        <option value="2" ${btn.dataset.groupId === '2' ? 'selected' : ''}>Staff</option>
                    </select>`)}
                    ${fieldRow('Account Status', `<select name="account_status" required>
                        <option value="Active" ${btn.dataset.status === 'Active' ? 'selected' : ''}>Active</option>
                        <option value="Inactive" ${btn.dataset.status === 'Inactive' ? 'selected' : ''}>Inactive</option>
                    </select>`)}
                    <input type="hidden" name="action" value="edit_employee">
                    <input type="hidden" name="account_id" value="${esc(btn.dataset.accId)}">
                    <input type="hidden" name="tab" value="employee">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <button type="button" class="btn btn-ghost" onclick="document.getElementById('accountModal').classList.remove('open');document.body.style.overflow=''">Cancel</button>
                    </div>
                </form>
            `);
        });
    });

    /* ── INACTIVE SECTION TOGGLES ───────────────────── */
    document.querySelectorAll('.show-inactive-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const targetId =
                button.id === 'toggleTierInactive'     ? 'tierInactiveSection' :
                button.id === 'toggleCategoryInactive' ? 'categoryInactiveSection' :
                                                          'serviceInactiveSection';
            const section = document.getElementById(targetId);
            if (!section) return;
            const nowHidden = section.classList.toggle('hidden');
            button.textContent = nowHidden ? 'Show Inactive' : 'Hide Inactive';
        });
    });

})();
</script>
</body>
</html>
