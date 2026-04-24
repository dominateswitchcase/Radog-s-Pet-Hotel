<?php
session_start();
require_once '../config/db.php';

// RBAC: Verify session
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

// Get Owner ID from URL
$owner_id = $_GET['id'] ?? null;
if (!$owner_id) {
    header("Location: owner.php");
    exit();
}

// 1. Fetch Owner Information [cite: 384]
$owner_stmt = $pdo->prepare("SELECT FIRST_NAME, LAST_NAME, CONTACT_NUMBER FROM OWNER WHERE OWNER_ID = :id");
$owner_stmt->execute(['id' => $owner_id]);
$owner = $owner_stmt->fetch(PDO::FETCH_ASSOC);

if (!$owner) {
    die("Owner not found.");
}

// 2. Fetch Registered Pets for this Owner [cite: 312, 388]
$pets_stmt = $pdo->prepare("
    SELECT P.PET_ID, P.PET_NAME, P.SEX, P.WEIGHT, C.CATEGORY_NAME 
    FROM PET P 
    JOIN PET_CATEGORY C ON P.CATEGORY_ID = C.CATEGORY_ID 
    WHERE P.OWNER_ID = :id
");
$pets_stmt->execute(['id' => $owner_id]);
$pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Booking History [cite: 540, 542]
$booking_stmt = $pdo->prepare("
    SELECT B.BOOKING_ID, P.PET_NAME, B.CHECK_IN_DATE, B.BOOKING_STATUS 
    FROM BOOKING B 
    JOIN PET P ON B.PET_ID = P.PET_ID 
    WHERE B.OWNER_ID = :id 
    ORDER BY B.CHECK_IN_DATE DESC
");
$booking_stmt->execute(['id' => $owner_id]);
$bookings = $booking_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Profile - <?php echo htmlspecialchars($owner['LAST_NAME']); ?></title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body>
<div class="dashboard-shell d-flex min-vh-100">
    

    <main class="main-content flex-grow-1 p-4 p-md-5 bg-light">
        <div class="container">
            <a href="owner.php" class="btn btn-link text-muted-custom text-decoration-none px-0 mb-4"><i class="bi bi-arrow-left"></i> Back to Owners</a>
            
            <div class="mb-4">
                <h1 class="h3 mb-1"><?php echo htmlspecialchars($owner['LAST_NAME'] . ", " . $owner['FIRST_NAME']); ?></h1>
                <p class="text-muted-custom">Owner Profile & Management</p>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="bg-panel p-4 h-100 shadow-sm">
                        <h2 class="h5 mb-4">Contact Information</h2>
                        <div class="mb-3 d-flex align-items-center gap-2">
                            <i class="bi bi-telephone text-brand"></i> 
                            <span><?php echo htmlspecialchars($owner['CONTACT_NUMBER']); ?></span>
                        </div>
                        <div class="small text-muted">Verification Status: <span class="text-success">Verified</span></div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="bg-panel p-4 h-100 shadow-sm">
                       <h2 class="h5 mb-4">Registered Pets</h2>
                        <div class="row g-3">
                            <?php foreach ($pets as $p): ?>
                            <div class="col-sm-6">
                                <a href="pet_profile.php?id=<?php echo $p['PET_ID']; ?>" class="text-decoration-none text-dark d-block bg-white p-3 rounded border hover-shadow">
                                    <p class="mb-0 fw-bold text-brand"><?php echo htmlspecialchars($p['PET_NAME']); ?></p>
                                    <p class="small text-muted-custom mb-0">
                                        <?php echo htmlspecialchars($p['CATEGORY_NAME']); ?> • 
                                        <?php echo htmlspecialchars($p['SEX']); ?> • 
                                        <?php echo htmlspecialchars($p['WEIGHT']); ?>kg
                                    </p>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-panel shadow-sm overflow-hidden">
                <div class="p-4 border-bottom border-opacity-25 bg-white">
                    <h2 class="h5 mb-0">Booking History </h2>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead style="background-color: #FAF3E1;">
                            <tr>
                                <th class="px-4 py-3">Booking ID</th>
                                <th>Pet Name</th>
                                <th>Check-In Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): ?>
                            <tr class="border-top">
                                <td class="px-4 py-3">BK-<?php echo str_pad($b['BOOKING_ID'], 5, '0', STR_PAD_LEFT); ?></td>
                                <td><?php echo htmlspecialchars($b['PET_NAME']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($b['CHECK_IN_DATE'])); ?></td>
                                <td>
                                    <?php 
                                        $status = $b['BOOKING_STATUS'];
                                        $badge_color = ($status == 'Confirmed') ? '#FA8112' : (($status == 'Completed') ? '#198754' : '#6c757d');
                                    ?>
                                    <span class="badge" style="background-color: <?php echo $badge_color; ?>;">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($bookings)): ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No history found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>