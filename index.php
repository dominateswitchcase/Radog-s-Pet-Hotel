<?php
session_start();
require_once 'config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        // Fetch user
        $stmt = $pdo->prepare("SELECT ua.Account_ID, ua.Password_Hash, ua.User_Group_ID, ug.Group_Name, ua.Employee_ID FROM USER_ACCOUNT ua JOIN USER_GROUP ug ON ua.User_Group_ID = ug.User_Group_ID WHERE ua.Username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['Password_Hash'])) {
            $_SESSION['account_id'] = $user['Account_ID'];
            $_SESSION['group_id'] = $user['User_Group_ID'];
            $_SESSION['group_name'] = $user['Group_Name'];
            $_SESSION['employee_id'] = $user['Employee_ID'];

            header('Location: ../public/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please provide username and password.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Radog’s Pet Hotel</title>
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <link href="assets/css/custom.css" rel="stylesheet">
     <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
   <div class="container min-vh-100 d-flex align-items-center justify-content-center">
    <div class="w-100 text-center" style="max-width: 700px;">
        <div class="mb-5">
          
               <img src="../Radog-s-Pet-Hotel/img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 150px; width: auto;">
            <h1 class="display-5 fw-normal mb-2 bold"style="font-family: 'times new roman', sans-serif;, bold">Radog's Kennel</h1>
            <p class="fs-5 text-muted-custom" Style="font-family: 'times new roman', sans-serif;">Pet Hotel Management System</p>
        </div>
        <div class="bg-panel p-4 p-md-5">
            <h2 class="h4 mb-4">Select Portal</h2>  
            <div class="row g-4">
                <div class="col-md-6">
                    <a href="src/employee_login.php" class="btn btn-brand w-100 p-4 h-100 d-flex flex-column align-items-center justify-content-center gap-3">
                        <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center active" style="width: 64px; height: 64px;">
                            <i class="bi bi-person-badge fs-2"></i>
                        </div>
                        <div>
                            <div class="fs-5 mb-1">Employee Portal</div>
                            <div class="small text-white text-opacity-75">Staff & Operations Access</div>
                        </div>
                    </a>
                </div>
                <div class="col-md-6">
                    <a href="src/admin_login.php" class="btn btn-danger btn-brand w-100 p-4 h-100 d-flex flex-column align-items-center justify-content-center gap-3">
                        <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
                            <i class="bi bi-shield-lock fs-2"></i>
                        </div>
                        <div>
                            <div class="fs-5 mb-1">Admin Portal</div>
                            <div class="small text-white text-opacity-75">Administrator Access</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

    <script src="assets/js/validation.js"> </script> 
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>