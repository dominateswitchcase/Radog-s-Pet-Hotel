<?php
session_start();
require_once '../config/db.php';

// RBAC: Check if user is logged in
if (!isset($_SESSION['account_id'])) {
    header('Location: ../index.php');
    exit;
}

try {
    // Total Bookings (All time)
    $stmt = $pdo->query("SELECT COUNT(*) FROM BOOKING");
    $total_bookings = $stmt->fetchColumn();

    //  Monthly Sales (Sum of Paid Payments)
    $stmt = $pdo->query("SELECT SUM(Total_Amount) FROM PAYMENT WHERE Payment_Status = 'Paid'");
    $monthly_sales = $stmt->fetchColumn() ?: 0;

    //  Occupancy Rate (Booked Rooms / Total Rooms)
    $stmtTotal = $pdo->query("SELECT COUNT(*) FROM ACCOMMODATION");
    $total_rooms = $stmtTotal->fetchColumn();
    
    $stmtBooked = $pdo->query("SELECT COUNT(*) FROM ACCOMMODATION WHERE Occupancy_Status = 'Booked'");
    $booked_rooms = $stmtBooked->fetchColumn();
    
    $occupancy_rate = ($total_rooms > 0) ? ($booked_rooms / $total_rooms) * 100 : 0;

    
    // Booking Status Distribution
    $stmtStatus = $pdo->query("SELECT Booking_Status, COUNT(*) as Status_Count FROM BOOKING GROUP BY Booking_Status");
    $statusData = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);
    
    $statusLabels = [];
    $statusCounts = [];
    foreach ($statusData as $row) {
        $statusLabels[] = $row['BOOKING_STATUS'];
        $statusCounts[] = $row['STATUS_COUNT'];
    }

    //  Revenue Trend (Grouping Paid payments by Check Out Month)
    $revenueQuery = "SELECT TO_CHAR(B.Check_Out_Date, 'MON YYYY') AS Sale_Month, 
                            SUM(P.Total_Amount) AS Monthly_Revenue 
                     FROM PAYMENT P 
                     JOIN BOOKING B ON P.Booking_ID = B.Booking_ID 
                     WHERE P.Payment_Status = 'Paid' 
                     GROUP BY TO_CHAR(B.Check_Out_Date, 'MON YYYY'), TO_CHAR(B.Check_Out_Date, 'YYYY-MM') 
                     ORDER BY TO_CHAR(B.Check_Out_Date, 'YYYY-MM')";
    $stmtRevenue = $pdo->query($revenueQuery);
    $revenueData = $stmtRevenue->fetchAll(PDO::FETCH_ASSOC);

    $revenueLabels = [];
    $revenueAmounts = [];
    foreach ($revenueData as $row) {
        $revenueLabels[] = $row['SALE_MONTH'];
        $revenueAmounts[] = $row['MONTHLY_REVENUE'];
    }

} catch (PDOException $e) {
    // In production, log error instead of displaying
    $db_error = "Error loading data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="dashboard-shell d-flex min-vh-100">
    <aside class="sidebar d-flex flex-column p-4" style="background-color: #F5E7C6;">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></p>
                <p class="mb-0 text-muted-custom small"><?php echo htmlspecialchars($_SESSION['group_name'] ?? 'Role'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="admin_dashboard.php" class="nav-link d-flex align-items-center mb-2 active" style="background-color: #FA8112; color: white;"><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="calendar.php" class="nav-link d-flex align-items-center mb-2" ><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="checkout.php" class="nav-link d-flex align-items-center mb-2 " ><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>        
 
    <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
        <i class="bi bi-gear-fill me-3"></i> User Management
    </a>

        </nav>
        <div class="mt-auto"> 
            <a href="../index.php" class="btn btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content flex-grow-1 p-4 p-md-5" style="background-color: #FAF3E1;">
        <div class="container-fluid">
            <div class="mb-4">  
                <h1 class="h3 mb-1">Admin Dashboard</h1>
                <p class="text-muted-custom">Real-time kennel performance overview.</p>
            </div>

            <?php if(isset($db_error)): ?>
                <div class="alert alert-warning py-2 small"><?php echo $db_error; ?></div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="bg-white shadow-sm border-0 p-4 h-100 rounded-3 border-start border-4 border-success">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Total Sales (Paid)</h3>
                        <p class="fs-2 fw-bold mb-0">₱<?php echo number_format($monthly_sales, 2); ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white shadow-sm border-0 p-4 h-100 rounded-3 border-start border-4 border-primary">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Occupancy Rate</h3>
                        <p class="fs-2 fw-bold mb-0"><?php echo number_format($occupancy_rate, 1); ?>%</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white shadow-sm border-0 p-4 h-100 rounded-3 border-start border-4 border-warning">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Total Bookings</h3>
                        <p class="fs-2 fw-bold mb-0"><?php echo $total_bookings; ?></p>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-5">
                    <div class="bg-white shadow-sm p-4 rounded-3 border-0 h-100">
                        <h2 class="h6 mb-4 fw-bold text-muted text-uppercase">Booking Status Distribution</h2>
                        <div style="position: relative; height:250px; width:100%">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="bg-white shadow-sm p-4 rounded-3 border-0 h-100">
                        <h2 class="h6 mb-4 fw-bold text-muted text-uppercase">Revenue Trend</h2>
                        <div style="position: relative; height:250px; width:100%">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-md-12">
                    <div class="bg-white shadow-sm p-4 rounded-3 border-0">
                        <h2 class="h5 mb-4 fw-bold">Quick Actions</h2>
                        <div class="d-flex gap-3">
                            <a href="encode_reservation.php" class="btn text-white py-2 px-4" style="background-color: #FA8112;">
                                <i class="bi bi-plus-lg me-2"></i> Schedule New Appointment
                            </a>
                            <a href="pets.php" class="btn btn-outline-dark py-2 px-4">
                                <i class="bi bi-search me-2"></i> Search Pet Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // ----------------------------------------
    // 1. Booking Status Chart (Doughnut)
    // ----------------------------------------
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    const statusLabels = <?php echo json_encode($statusLabels); ?>;
    const statusData = <?php echo json_encode($statusCounts); ?>;

    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                data: statusData,
                backgroundColor: [
                    '#FA8112', // Radog's Orange
                    '#198754', // Bootstrap Success
                    '#ffc107', // Bootstrap Warning
                    '#dc3545'  // Bootstrap Danger
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right' }
            }
        }
    });

    // ----------------------------------------
    // 2. Revenue Trend Chart (Line)
    // ----------------------------------------
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueLabels = <?php echo json_encode($revenueLabels); ?>;
    const revenueData = <?php echo json_encode($revenueAmounts); ?>;

    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: revenueLabels,
            datasets: [{
                label: 'Monthly Revenue (₱)',
                data: revenueData,
                borderColor: '#FA8112',
                backgroundColor: 'rgba(250, 129, 18, 0.2)',
                borderWidth: 3,
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₱' + value;
                        }
                    }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
});
</script>
</body>
</html>