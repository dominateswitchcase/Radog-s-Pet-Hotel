<!-- NOT BEING USE ANYMORE -->

<!-- <?php
ob_start(); 
session_start();
require_once '../config/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['username']) && !empty($_POST['password'])) {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);

        try {
            $stmt = $pdo->prepare(
                "SELECT ua.Account_ID, ua.Password_Hash, ua.User_Group_ID, ua.Username, ug.Group_Name, ua.Employee_ID
                 FROM USER_ACCOUNT ua
                 JOIN USER_GROUP ug ON ua.User_Group_ID = ug.User_Group_ID
                 WHERE LOWER(ua.Username) = LOWER(:username)
                   AND ua.User_Group_ID = 1"
            );
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Oracle returns columns in UPPERCASE by default
            $db_hash = $user['PASSWORD_HASH'] ?? $user['Password_Hash'] ?? '';
            
            if ($user && password_verify($password, $db_hash)) {
                // Store in session using Oracle's uppercase keys
                $_SESSION['account_id'] = $user['ACCOUNT_ID'] ?? $user['Account_ID'];
                $_SESSION['group_name'] = $user['GROUP_NAME'] ?? $user['Group_Name'];
                $_SESSION['username'] = $user['USERNAME'] ?? $user['Username'];
                $_SESSION['user_group_id'] = $user['USER_GROUP_ID'] ?? $user['User_Group_ID'];
                $_SESSION['employee_id'] = $user['EMPLOYEE_ID'] ?? $user['Employee_ID'];
                
                ob_end_clean(); // Clear buffer before redirect
                header('Location: admin_dashboard.php');
                exit;
            } else {
                $error = 'Invalid admin username or password.';
            }
        } catch (PDOException $e) {
            $error = 'Database Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please provide both username and password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body>
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="w-100" style="max-width: 400px;">
        <div class="text-center mb-4">
            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; background-color: #FA8112;">
                <i class="bi bi-shield-lock text-white fs-1"></i>
            </div>
            <h1 class="h3 mb-2">Radog's Kennel</h1>
            <a href="../index.php" class="text-decoration-none text-muted-custom small">← Back to Portal Selection</a>
        </div>
        <div class="bg-panel p-4 p-md-5">
            <h2 class="h5 mb-4">Admin Portal</h2>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger small py-2">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <form action="admin_login.php" method="POST">
                <div class="mb-3">
                    <label class="form-label small">Username</label>
                    <input type="text" name="username" class="form-control" placeholder="Admin Username" required>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Password" required>
                </div>
                <button type="submit" class="btn btn-brand w-100 py-2"><i class="bi bi-box-arrow-in-right"></i> Login</button>
            </form>
        </div>
    </div>
</div>
<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html> -->