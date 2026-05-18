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
                    'id' => $new_emp_id,
                    'e_user' => $emp_username,
                    'pass' => $hashed_password
                ]);

                // 4. Insert into USER_ACCOUNT Table
                $insert_acc = $pdo->prepare("INSERT INTO USER_ACCOUNT (ACCOUNT_ID, USERNAME, ACCOUNT_STATUS, PASSWORD_HASH, EMPLOYEE_ID, USER_GROUP_ID) VALUES (:id, :a_user, :status, :pass, :emp_id, :grp_id)");
                $insert_acc->execute([
                    'id' => $new_acc_id,
                    'a_user' => $account_username,
                    'status' => $status,
                    'pass' => $hashed_password,
                    'emp_id' => $new_emp_id,
                    'grp_id' => $group_id
                ]);

                $pdo->commit();
                $response['success'] = true;
                $response['message'] = 'User successfully registered.';
            } catch (Exception $e) {
                $pdo->rollBack();
                // Check for unique constraint violation (username already exists)
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
        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        $group_id = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT);

        if ($account_id && $username && in_array($status, ['Active', 'Inactive']) && $group_id) {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE USER_ACCOUNT SET USERNAME = :username, ACCOUNT_STATUS = :status, USER_GROUP_ID = :group_id
                     WHERE ACCOUNT_ID = :id"
                );
                $stmt->execute([
                    'username' => $username,
                    'status' => $status,
                    'group_id' => $group_id,
                    'id' => $account_id
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
            // Because of FK constraints, we should technically handle EMPLOYEE table too, 
            // but assuming cascading or logical deletions based on current code.
            $stmt = $pdo->prepare("DELETE FROM USER_ACCOUNT WHERE ACCOUNT_ID = :id");
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

$stmt = $pdo->query($query);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user groups for dropdown
$groups_stmt = $pdo->query("SELECT USER_GROUP_ID, GROUP_NAME FROM USER_GROUP");
$user_groups = $groups_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <style>
        body { display: flex; min-height: 100vh; background-color: #f8f9fa; }
        .sidebar { width: 280px; min-width: 280px; background: #fff; border-right: 1px solid rgba(0,0,0,.1); position: sticky; top: 0; height: 100vh; }
        .main-content { flex-grow: 1; }
    </style>
</head>
<body>
     <aside class="sidebar d-flex flex-column p-4" style="background-color: #F5E7C6;">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo htmlentities($_SESSION['username'] ?? 'Staff'); ?></p>
                 <p class="mb-0 text-muted-custom small"><?php echo htmlspecialchars($_SESSION['group_name'] ?? 'Role'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="admin_dashboard.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="calendar.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="checkout.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
          <?php if (isset($_SESSION['user_group_id']) && $_SESSION['user_group_id'] == 1): ?>
            <a href="user_management.php" class="nav-link d-flex align-items-center mb-2 active" style="background-color: #FA8112; color: white;">
                <i class="bi bi-gear-fill me-3"></i> User Management
            </a>
          <?php endif; ?>
        </nav>
        <div class="mt-auto">
            <a href="../logout.php" class="btn btn-danger btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content p-4 p-md-5">
        <div class="container-fluid">
            <div class="mb-4">
                <h1 class="h3 mb-1">System Administration</h1>
                <p class="text-muted-custom">User security and Account  Privilege oversight</p>
            </div>
            
            <div class="mb-4">
                <button type="button" class="btn btn-brand py-2 px-4 text-white" style="background-color: #FA8112; border: none;" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="bi bi-person-plus"></i> Create New User
                </button>
            </div>
            
            <div class="bg-panel p-4 shadow-sm">
                <h2 class="h5 mb-4">Role Permissions Guide</h2>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="bg-white p-3 rounded border h-100">
                            <h3 class="h6 text-danger"><i class="bi bi-shield-lock-fill"></i> Administrator</h3>
                            <p class="small text-muted mb-0">Full system access. Can view financial reports, modify core pricing, and manage user accounts/passwords.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="bg-white p-3 rounded border h-100">
                            <h3 class="h6" style="color: #FA8112;"><i class="bi bi-person-badge-fill"></i> Kennel Staff</h3>
                            <p class="small text-muted mb-0">Operational access. Can encode reservations, manage the calendar, and view owner/pet profiles. No access to settings or revenue data.</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <br>
            
            <div class="bg-panel overflow-hidden mb-4 shadow-sm border-0 rounded-3" style="background-color: #ffffff;">
                <div class="p-4 border-bottom d-flex justify-content-between align-items-center">
                    <h2 class="h5 mb-0 fw-bold" style="color: #222222;">Employee Accounts</h2>
                    <i class="bi bi-people text-muted fs-5"></i>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead style="background-color: #FAF3E1;">
                            <tr>
                                <th class="px-4 py-3 border-0 text-muted small text-uppercase fw-bold" style="letter-spacing: 0.5px;">Account ID</th>
                                <th class="px-4 py-3 border-0 text-muted small text-uppercase fw-bold" style="letter-spacing: 0.5px;">Username</th>
                                <th class="px-4 py-3 border-0 text-muted small text-uppercase fw-bold" style="letter-spacing: 0.5px;">Employee Ref</th>
                                <th class="px-4 py-3 border-0 text-muted small text-uppercase fw-bold" style="letter-spacing: 0.5px;">Role Group</th>
                                <th class="px-4 py-3 border-0 text-muted small text-uppercase fw-bold" style="letter-spacing: 0.5px;">Status</th>
                                <th class="px-4 py-3 border-0 text-muted small text-uppercase fw-bold text-center" style="letter-spacing: 0.5px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="border-top-0">
                            <?php foreach ($users as $user): ?>
                            <tr style="transition: background-color 0.2s;">
                                <td class="px-4 py-3 fw-bold" style="color: #444;">
                                    ACC-<?php echo str_pad($user['ACCOUNT_ID'], 3, '0', STR_PAD_LEFT); ?>
                                </td>
                                <td class="px-4 py-3 text-dark fw-medium">
                                    <?php echo htmlspecialchars($user['USERNAME']); ?>
                                </td>
                                <td class="px-4 py-3 text-muted">
                                    <?php echo htmlspecialchars($user['EMPLOYEE_USERNAME']); ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php 
                                        $badge_bg = ($user['GROUP_NAME'] == 'Administrator') ? '#222222' : '#FA8112'; 
                                    ?>
                                    <span class="badge rounded-pill px-3 py-2 fw-normal" style="background-color: <?php echo $badge_bg; ?>;">
                                        <?php echo htmlspecialchars($user['GROUP_NAME']); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if($user['ACCOUNT_STATUS'] == 'Active'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-2 fw-medium">
                                            <i class="bi bi-check-circle-fill me-1"></i> Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3 py-2 fw-medium">
                                            <i class="bi bi-x-circle-fill me-1"></i> Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button type="button" class="btn btn-sm btn-light border rounded-circle p-2 custom-hover edit-user-btn" 
                                            data-account-id="<?php echo $user['ACCOUNT_ID']; ?>" 
                                            title="Edit User" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editUserModal"
                                            style="width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;">
                                        <i class="bi bi-pencil-square" style="color: #FA8112;"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <div class="text-muted d-flex flex-column align-items-center">
                                        <i class="bi bi-inboxes fs-1 mb-2 opacity-50"></i>
                                        <span class="fw-medium">No system users found.</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header p-4" style="background-color: #FA8112;">
                    <h5 class="modal-title text-white" id="createUserModalLabel">Register New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="createUserForm">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Employee Full Name / Reference</label>
                            <input type="text" class="form-control" id="newEmpUsername" placeholder="e.g. John Doe" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">System Login Username</label>
                            <input type="text" class="form-control" id="newAccountUsername" placeholder="e.g. jdoe_admin" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Password</label>
                            <input type="password" class="form-control" id="newPassword" placeholder="Minimum 6 characters" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Role Group</label>
                                <select class="form-select" id="newUserGroup" required>
                                    <option value="" disabled selected>Select Role</option>
                                    <?php foreach ($user_groups as $group): ?>
                                        <option value="<?php echo $group['USER_GROUP_ID']; ?>">
                                            <?php echo htmlspecialchars($group['GROUP_NAME']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Initial Status</label>
                                <select class="form-select" id="newAccountStatus" required>
                                    <option value="Active" selected>Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div id="createModalMessage" class="alert" style="display: none;" role="alert"></div>
                    </form>
                </div>
                <div class="modal-footer p-4 border-top">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-brand" id="saveNewUserBtn" style="background-color: #FA8112; border: none;">
                        <i class="bi bi-person-check-fill"></i> Register User
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header p-4" style="background-color: #222222;">
                    <h5 class="modal-title text-white" id="editUserModalLabel">Edit User Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="editUserForm">
                        <div class="mb-3">
                            <label for="accountId" class="form-label small fw-bold">Account ID</label>
                            <input type="text" class="form-control bg-light" id="accountId" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label small fw-bold">Username</label>
                            <input type="text" class="form-control" id="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="userGroup" class="form-label small fw-bold">Role Group</label>
                            <select class="form-select" id="userGroup" required>
                                <option value="">-- Select Role --</option>
                                <?php foreach ($user_groups as $group): ?>
                                    <option value="<?php echo $group['USER_GROUP_ID']; ?>">
                                        <?php echo htmlspecialchars($group['GROUP_NAME']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="accountStatus" class="form-label small fw-bold">Account Status</label>
                            <select class="form-select" id="accountStatus" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <div id="modalMessage" class="alert" style="display: none;" role="alert"></div>
                    </form>
                </div>
                <div class="modal-footer p-4 border-top">
                    <button type="button" class="btn btn-outline-danger me-auto" id="deleteUserBtn">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-dark" id="updateUserBtn">
                        <i class="bi bi-check-circle"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const editUserModal = new bootstrap.Modal(document.getElementById('editUserModal'), {});
            const createUserModal = new bootstrap.Modal(document.getElementById('createUserModal'), {});
            
            const editUserForm = document.getElementById('editUserForm');
            const createUserForm = document.getElementById('createUserForm');
            
            const modalMessage = document.getElementById('modalMessage');
            const createModalMessage = document.getElementById('createModalMessage');
            let currentAccountId = null;

            // ==========================================
            // JELLYACE: Create New User Logic
            // ==========================================
            document.getElementById('saveNewUserBtn').addEventListener('click', function() {
                if (!createUserForm.checkValidity()) {
                    createUserForm.reportValidity();
                    return;
                }

                const empUsername = document.getElementById('newEmpUsername').value;
                const accUsername = document.getElementById('newAccountUsername').value;
                const password = document.getElementById('newPassword').value;
                const groupId = document.getElementById('newUserGroup').value;
                const status = document.getElementById('newAccountStatus').value;

                fetch('user_management.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=create_user&emp_username=${encodeURIComponent(empUsername)}&account_username=${encodeURIComponent(accUsername)}&password=${encodeURIComponent(password)}&group_id=${groupId}&status=${status}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        createModalMessage.textContent = data.message;
                        createModalMessage.className = 'alert alert-success';
                        createModalMessage.style.display = 'block';
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        createModalMessage.textContent = data.message;
                        createModalMessage.className = 'alert alert-danger';
                        createModalMessage.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    createModalMessage.textContent = 'An error occurred while creating user.';
                    createModalMessage.className = 'alert alert-danger';
                    createModalMessage.style.display = 'block';
                });
            });

            // ==========================================
            // Handle edit button click
            // ==========================================
            document.querySelectorAll('.edit-user-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    currentAccountId = this.getAttribute('data-account-id');
                    loadUserData(currentAccountId);
                });
            });

            function loadUserData(accountId) {
                fetch('user_management.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_user&account_id=' + accountId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.data;
                        document.getElementById('accountId').value = 'ACC-' + String(user.ACCOUNT_ID).padStart(3, '0');
                        document.getElementById('username').value = user.USERNAME;
                        document.getElementById('userGroup').value = user.USER_GROUP_ID;
                        document.getElementById('accountStatus').value = user.ACCOUNT_STATUS;
                        modalMessage.style.display = 'none';
                    } else {
                        showMessage('Error: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('An error occurred while loading user data', 'danger');
                });
            }

            // Update user
            document.getElementById('updateUserBtn').addEventListener('click', function() {
                if (!editUserForm.checkValidity()) {
                    editUserForm.reportValidity();
                    return;
                }

                const username = document.getElementById('username').value;
                const groupId = document.getElementById('userGroup').value;
                const status = document.getElementById('accountStatus').value;

                fetch('user_management.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=update_user&account_id=' + currentAccountId + '&username=' + encodeURIComponent(username) + '&group_id=' + groupId + '&status=' + status
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showMessage('User updated successfully!', 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showMessage('Error: ' + data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showMessage('An error occurred while updating user', 'danger');
                });
            });

            // Delete user
            document.getElementById('deleteUserBtn').addEventListener('click', function() {
                if (confirm('Are you sure you want to delete this user account? This action cannot be undone.')) {
                    fetch('user_management.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=delete_user&account_id=' + currentAccountId
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showMessage('User deleted successfully!', 'success');
                            setTimeout(() => {
                                editUserModal.hide();
                                location.reload();
                            }, 1500);
                        } else {
                            showMessage('Error: ' + data.message, 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showMessage('An error occurred while deleting user', 'danger');
                    });
                }
            });

            function showMessage(message, type) {
                modalMessage.textContent = message;
                modalMessage.className = 'alert alert-' + type;
                modalMessage.style.display = 'block';
            }
        });
    </script>
</body>
</html>
