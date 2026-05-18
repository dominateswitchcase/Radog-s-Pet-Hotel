<!-- not being use anymore -->


<!-- <?php
    session_start();
    require_once '../config/db.php';

    $error_msg = "";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $username = $_POST['username'];
        $password = $_POST['password'];

        $stmt = $pdo->prepare(
            "SELECT UA.ACCOUNT_ID, UA.PASSWORD_HASH, UA.USER_GROUP_ID, UA.USERNAME, UG.GROUP_NAME, UA.EMPLOYEE_ID 
            FROM USER_ACCOUNT UA
            JOIN USER_GROUP UG ON UA.USER_GROUP_ID = UG.USER_GROUP_ID
            JOIN EMPLOYEE E ON UA.EMPLOYEE_ID = E.EMPLOYEE_ID
            WHERE LOWER(UA.USERNAME) = LOWER(:username) 
            AND UA.USER_GROUP_ID = 2 
            AND UA.ACCOUNT_STATUS = 'Active'" // [cite: 446, 452, 590]
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $db_hash = trim($user['PASSWORD_HASH']); // [cite: 450]
            $password_input = $_POST['password'];

            // Check bcrypt hashed password [cite: 100, 106]
            if (password_verify($password_input, $db_hash)) {
                $_SESSION['account_id'] = $user['ACCOUNT_ID']; // [cite: 447]
                $_SESSION['user_group_id'] = $user['USER_GROUP_ID']; // [cite: 452]
                $_SESSION['group_name'] = $user['GROUP_NAME']; // [cite: 444]
                $_SESSION['username'] = $user['USERNAME']; // [cite: 448]
                $_SESSION['employee_id'] = $user['EMPLOYEE_ID'];

                header("Location: staff_dashboard.php"); // [cite: 55, 98]
                exit();
            } else {
                $error_msg = "Incorrect password.";
            }
        } else {
            $error_msg = "Staff account not found or inactive.";
        }
    }
    ?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Login - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body>
<div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="w-100" style="max-width: 400px;">
        <div class="text-center mb-4">
            <div class="rounded-circle bg-brand d-inline-flex align-items-center justify-content-center mb-3" style="width: 80px; height: 80px; background-color: #FA8112;">
                <i class="bi bi-person-badge text-white fs-1"></i>
            </div>
            <h1 class="h3 mb-2">Radog's Kennel</h1>
            <a href="../index.php" class="text-decoration-none text-muted-custom small">← Back to Portal Selection</a>
        </div>
        <div class="bg-panel p-4 p-md-5">
            <h2 class="h5 mb-4">Employee Portal</h2>
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger small py-2"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>
            <form action="employee_login.php" method="POST">
                <div class="mb-3">
                    <label class="form-label small">Username</label>
                    <input type="text" name="username" class="form-control px-3 py-2" placeholder="Employee_Username" required>
                </div>
                <div class="mb-4">
                    <label class="form-label small">Password</label>
                    <input type="password" name="password" class="form-control px-3 py-2" placeholder="Password" required>
                </div>
                <button type="submit" class="btn btn-brand w-100 py-2 d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </form>
           <div class="mt-4 p-3 rounded" style="background-color: #FAF3E1;">
             <p class="small text-muted-custom mb-1">Staff Credentials:</p>
                 <p class="small text-muted-custom mb-0">Username: staff.jp, Password: jpdaluro</p>
            </div>
        </div>
    </div>
</div>
<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/validation.js"></script>
</body>
</html>   -->