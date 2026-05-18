<!-- <?php
session_start();
require_once '../config/db.php'; // Include your working connection file

// RBAC: Ensure only administrators (User Group 1) can access this [cite: 100, 121, 245]
if (!isset($_SESSION['user_group_id']) || $_SESSION['user_group_id'] != 1) {
    header("Location: unauthorized.php");
    exit();
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = filter_var($_POST['username'], FILTER_SANITIZE_STRING);
    $raw_password = $_POST['password'];
    $user_group_id = filter_var($_POST['user_group_id'], FILTER_VALIDATE_INT);
    $status = filter_var($_POST['status'], FILTER_SANITIZE_STRING);

    // Secure password hashing [cite: 45, 51]
    $hashed_password = password_hash($raw_password, PASSWORD_DEFAULT);
try {
    $pdo->beginTransaction();

    // 1. Prepare the EMPLOYEE insert with a RETURNING clause
    // This allows us to get the ID without calling lastInsertId()
    $sql_emp = "INSERT INTO EMPLOYEE (Employee_ID, Employee_Username, Password_Hash) 
                VALUES (EMPLOYEE_ID_SEQ.NEXTVAL, :username, :password)
                RETURNING Employee_ID INTO :new_id";
    
    $stmt_emp = $pdo->prepare($sql_emp);
    $stmt_emp->bindParam(':username', $username);
    $stmt_emp->bindParam(':password', $hashed_password);
    
    // We bind a variable to receive the returning ID
    $new_emp_id = 0;
    $stmt_emp->bindParam(':new_id', $new_emp_id, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 32);
    $stmt_emp->execute();

    // 2. Insert into USER_ACCOUNT using the retrieved ID
    // Matches the User Account Entity Type requirements [cite: 45]
    $sql_user = "INSERT INTO USER_ACCOUNT (Account_ID, Username, Account_Status, Password_Hash, Employee_ID, User_Group_ID) 
                 VALUES (USER_ACCOUNT_ID_SEQ.NEXTVAL, :username, :status, :password, :emp_id, :group_id)";
    
    $stmt_user = $pdo->prepare($sql_user);
    $stmt_user->execute([
        ':username' => $username,
        ':status'   => $status,
        ':password' => $hashed_password,
        ':emp_id'   => $new_emp_id,
        ':group_id' => $user_group_id
    ]);

    $pdo->commit();
    $message = "<div class='alert alert-success'>Employee registered! System ID: " . $new_emp_id . "</div>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Correctly identifying errors for system debugging [cite: 105]
    $message = "<div class='alert alert-danger'>Registration Failed: " . $e->getMessage() . "</div>";
}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Registration - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
<div class="dashboard-shell d-flex min-vh-100">
    <aside class="sidebar d-flex flex-column p-4" style="background-color: #F5E7C6;">
        <div class="sidebar-brand mb-5 text-center">
            <div class="sidebar-logo mb-3 d-inline-flex align-items-center justify-content-center">
                <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            </div>
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small">Admin Portal</p>
            </div>
        </div>
        
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="admin_dashboard.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-house-fill me-3"></i> Dashboard</a>
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-people-fill me-3"></i> Owners</a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw-fill me-3"></i> Pets</a>
            <a href="user_management.php" class="nav-link d-flex align-items-center mb-2 active" style="background-color: #FA8112; color: white;"><i class="bi bi-person-gear me-3"></i> User Management</a>
        </nav>

        <div class="mt-auto">
            <a href="../logout.php" class="btn btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content flex-grow-1 p-4 p-md-5" style="background-color: #FAF3E1;">
        <div class="container" style="max-width: 800px;">
            <div class="mb-4">
                <h1 class="h3 mb-1">New User Registration</h1>
                <p class="text-muted-custom">Generate internal credentials for new kennel staff.</p>
            </div>

            <?php if (!empty($message)) echo $message; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                <div class="bg-panel p-4 mb-4 border rounded shadow-sm" style="background-color: #ffffff;">
                    <h2 class="h5 mb-4">Account Credentials</h2>
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label class="form-label small fw-bold">Username</label>
                            <input type="text" name="username" class="form-control" placeholder="Enter unique username" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Assign a secure password" required>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Role (User Group)</label>
                            <select name="user_group_id" class="form-select" required>
                                <option value="2">Kennel Staff</option>
                                <option value="1">Administrator</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Account Status</label>
                            <select name="status" class="form-select" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-3">
                    <a href="user_management.php" class="btn btn-light border px-4 py-2" style="background-color: #F5E7C6;">Return</a>
                    <button type="submit" class="btn btn-brand flex-grow-1 py-2 text-white" style="background-color: #FA8112; border: none;">
                        <i class="bi bi-person-plus-fill me-2"></i> Register Employee
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>
<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html> -->
