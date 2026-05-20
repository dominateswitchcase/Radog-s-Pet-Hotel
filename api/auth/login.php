<?php
header('Content-Type: application/json; charset=UTF-8');

// Require the DB connection ($pdo will be available from here)
require_once __DIR__ . '/../../config/db.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$username = isset($payload['username']) ? trim($payload['username']) : '';
$password = isset($payload['password']) ? $payload['password'] : '';

if ($username === '' || $password === '') {
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

// SQL Query tailored for PDO
$sql = "SELECT ua.ACCOUNT_ID,
               ua.USERNAME,
               ua.PASSWORD_HASH,
               ua.USER_GROUP_ID,
               ug.GROUP_NAME,
               COALESCE(e.EMPLOYEE_USERNAME, ua.USERNAME) AS EMPLOYEE_NAME
        FROM USER_ACCOUNT ua
        JOIN USER_GROUP ug ON ua.USER_GROUP_ID = ug.USER_GROUP_ID
        LEFT JOIN EMPLOYEE e ON ua.EMPLOYEE_ID = e.EMPLOYEE_ID
        WHERE LOWER(ua.USERNAME) = LOWER(:username)
          AND ua.ACCOUNT_STATUS = 'Active'";

try {
    // Prepare and execute using the PDO connection from db.php
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !isset($user['PASSWORD_HASH']) || !password_verify($password, trim($user['PASSWORD_HASH']))) {
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        exit;
    }

    $groupName = strtoupper(trim($user['GROUP_NAME'] ?? ''));
    $userGroupId = $user['USER_GROUP_ID'] ?? null;
    $role = 'Unknown';

    if (in_array($groupName, ['ADMIN', 'ADMINISTRATOR'], true) || $userGroupId == 1) {
        $role = 'Admin';
    } elseif (in_array($groupName, ['STAFF', 'EMPLOYEE', 'STAFF MEMBER', 'KENNEL STAFF'], true) || $userGroupId == 2) {
        $role = 'Staff';
    }

    if ($role === 'Unknown') {
        echo json_encode(['success' => false, 'message' => 'Unable to determine user role.']);
        exit;
    }

    // Set session variables
    $_SESSION['account_id'] = $user['ACCOUNT_ID'];
    $_SESSION['role'] = $role;
    $_SESSION['group_name'] = $user['GROUP_NAME'] ?? null;
    $_SESSION['employee_name'] = $user['EMPLOYEE_NAME'] ?? $user['USERNAME'];
    $_SESSION['user_group_id'] = $user['USER_GROUP_ID'];
    $_SESSION['username'] = $user['USERNAME'];

    // Send success response
    echo json_encode([
        'success' => true,
        'role' => $role,
        'employee_name' => $_SESSION['employee_name'],
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    // In production, don't output $e->getMessage() to the frontend. Just log it.
    echo json_encode(['success' => false, 'message' => 'Query execution failed.']);
    exit;
}
?>
