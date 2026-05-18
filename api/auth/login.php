<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../config/db.php';

// Start the session and store account/role data on successful login.
// Dashboard pages must also guard access by checking $_SESSION['account_id'] and $_SESSION['role'].
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

$tns = '//' . DB_HOST . ':' . DB_PORT . '/' . DB_SID;
$conn = @oci_connect(DB_USERNAME, DB_PASSWORD, $tns, 'AL32UTF8');
if (!$conn) {
    $error = oci_error();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to connect to the database.']);
    exit;
}

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

$stid = oci_parse($conn, $sql);
oci_bind_by_name($stid, ':username', $username);
oci_execute($stid);
$user = oci_fetch_array($stid, OCI_ASSOC + OCI_RETURN_NULLS);

if (!$user || !isset($user['PASSWORD_HASH']) || !password_verify($password, trim($user['PASSWORD_HASH']))) {
    oci_free_statement($stid);
    oci_close($conn);
    echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
    exit;
}

$groupName = strtoupper(trim($user['GROUP_NAME'] ?? ''));
$userGroupId = $user['USER_GROUP_ID'] ?? null;
$role = 'Unknown';

if (in_array($groupName, ['ADMIN', 'ADMINISTRATOR'], true) || $userGroupId === 1) {
    $role = 'Admin';
} elseif (in_array($groupName, ['STAFF', 'EMPLOYEE', 'STAFF MEMBER', 'KENNEL STAFF'], true) || $userGroupId === 2) {
    $role = 'Staff';
}

if ($role === 'Unknown') {
    oci_free_statement($stid);
    oci_close($conn);
    echo json_encode(['success' => false, 'message' => 'Unable to determine user role.']);
    exit;
}

$_SESSION['account_id'] = $user['ACCOUNT_ID'];
$_SESSION['role'] = $role;
$_SESSION['group_name'] = $user['GROUP_NAME'] ?? null;
$_SESSION['employee_name'] = $user['EMPLOYEE_NAME'] ?? $user['USERNAME'];
$_SESSION['user_group_id'] = $user['USER_GROUP_ID'];
$_SESSION['username'] = $user['USERNAME'];

oci_free_statement($stid);
oci_close($conn);

echo json_encode([
    'success' => true,
    'role' => $role,
    'employee_name' => $_SESSION['employee_name'],
]);
