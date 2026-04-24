<?php
session_start();
require_once '../config/db.php';

// RBAC: Ensure only logged-in Kennel Staff can access this page
if (!isset($_SESSION['account_id']) || $_SESSION['user_group_id'] != 2) {
    header("Location: employee_login.php");
    exit();
}

$display_name = htmlspecialchars($_SESSION['username']); 

// =======================================================================
// NEW JELLYACE FUNCTION: Handle Accommodation Status Updates
// =======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_unit_status') {
    $unit_id = filter_input(INPUT_POST, 'accommodation_id', FILTER_VALIDATE_INT);
    $new_status = $_POST['occupancy_status'] ?? '';
    
    // Validate constraint against DB schema values
    if ($unit_id && in_array($new_status, ['Available', 'Booked', 'Under Maintenance'])) {
        try {
            $update_stmt = $pdo->prepare("UPDATE ACCOMMODATION SET OCCUPANCY_STATUS = :status WHERE ACCOMMODATION_ID = :id");
            $update_stmt->execute(['status' => $new_status, 'id' => $unit_id]);
            $success_message = "Unit status updated successfully.";
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid status selected.";
    }
}
// =======================================================================

// FETCH METRICS FROM ORACLE 

// 1. Today's Check-ins 
$checkin_stmt = $pdo->prepare("SELECT COUNT(*) AS TOTAL FROM BOOKING WHERE TRUNC(CHECK_IN_DATE) = TRUNC(SYSDATE)");
$checkin_stmt->execute();
$checkins_today = $checkin_stmt->fetch(PDO::FETCH_ASSOC)['TOTAL'];

// 2. Today's Check-outs 
$checkout_stmt = $pdo->prepare("SELECT COUNT(*) AS TOTAL FROM BOOKING WHERE TRUNC(CHECK_OUT_DATE) = TRUNC(SYSDATE)");
$checkout_stmt->execute();
$checkouts_today = $checkout_stmt->fetch(PDO::FETCH_ASSOC)['TOTAL'];

// 3. Accommodation Status counts 
$status_stmt = $pdo->query("SELECT OCCUPANCY_STATUS, COUNT(*) as COUNT FROM ACCOMMODATION GROUP BY OCCUPANCY_STATUS");
$acc_status = ['Available' => 0, 'Booked' => 0, 'Under Maintenance' => 0];
while ($row = $status_stmt->fetch(PDO::FETCH_ASSOC)) {
    $acc_status[$row['OCCUPANCY_STATUS']] = $row['COUNT'];
}

// 4. Fetch Unit List for the table (ADDED A.ACCOMMODATION_ID for the update function)
$units_stmt = $pdo->query("
    SELECT A.ACCOMMODATION_ID, A.UNIT_NAME, T.TIER_NAME, A.OCCUPANCY_STATUS, P.PET_NAME 
    FROM ACCOMMODATION A
    JOIN TIER T ON A.TIER_ID = T.TIER_ID
    LEFT JOIN BOOKING B ON A.ACCOMMODATION_ID = B.BOOKING_ID AND B.BOOKING_STATUS = 'Confirmed'
    LEFT JOIN PET P ON B.PET_ID = P.PET_ID
    ORDER BY A.UNIT_NAME ASC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <style>
        .dashboard-shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 280px; min-width: 280px;
            background: linear-gradient(135deg, #d35400 0%, #e67e22 100%);
            top: 0; height: 100vh; overflow-y: auto;
        }
        .sidebar .sidebar-brand { border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 1.5rem; }
        .sidebar .nav-link { color: rgba(255,255,255,0.7); border-radius: 0.5rem; transition: all 0.3s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: rgba(255,255,255,0.1); color: #fff; }
        .main-content { flex-grow: 1; background-color: #f5f5f5; }
    </style>
</head>
<body>
<div class="dashboard-shell">
    <aside class="sidebar d-flex flex-column p-4">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1 text-white">Radog's Kennel</h2>
                <p class="mb-1 text-light small"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></p>
                <p class="mb-0 text-white-50 small"><?php echo htmlspecialchars($_SESSION['group_name'] ?? 'Role'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="staff_dashboard.php" class="nav-link d-flex align-items-center mb-2 active style="background-color: #FA8112; color: white;""><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="staff_encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="staff_calendar.php" class="nav-link d-flex align-items-center mb-2 " ><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="staff_owner.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="staff_pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="staff_checkout.php" class="nav-link d-flex align-items-center mb-2 " ><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
        </nav>
        <div class="mt-auto border-top pt-3">
            <a href="../index.php" class="btn btn-link text-white text-decoration-none d-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content flex-grow-1 p-4 p-md-5 bg-light">
        <div class="container-fluid">
            <div class="mb-4">
                <h1 class="h3 mb-1">Staff Dashboard</h1>
                <p class="text-muted">Operations overview and unit management.</p>
            </div>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="bg-white shadow-sm border-0 p-4 h-100 rounded-3 border-start border-4 border-success">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Today's Check-ins</h3>
                        <p class="fs-2 fw-bold mb-0"><?php echo $checkins_today; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white shadow-sm border-0 p-4 h-100 rounded-3 border-start border-4 border-warning">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Today's Check-outs</h3>
                        <p class="fs-2 fw-bold mb-0"><?php echo $checkouts_today; ?></p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="bg-white shadow-sm border-0 p-4 h-100 rounded-3 border-start border-4 border-info">
                        <h3 class="h6 text-muted text-uppercase small mb-2">Accommodation Status</h3>
                        <div class="small lh-lg">
                            <p class="mb-0"><strong>Available:</strong> <?php echo $acc_status['Available'] ?? 0; ?></p>
                            <p class="mb-0"><strong>Booked:</strong> <?php echo $acc_status['Booked'] ?? 0; ?></p>
                            <p class="mb-0"><strong>Maintenance:</strong> <?php echo $acc_status['Under Maintenance'] ?? 0; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white shadow-sm p-4 rounded-3 border-0 overflow-hidden">
                <h2 class="h5 mb-4 fw-bold">Accommodation Units</h2>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead style="background-color: #f8f9fa;">
                            <tr>
                                <th class="px-4 py-3 fw-bold text-muted small">Unit Name</th>
                                <th class="px-4 py-3 fw-bold text-muted small">Tier</th>
                                <th class="px-4 py-3 fw-bold text-muted small">Status</th>
                                <th class="px-4 py-3 fw-bold text-muted small">Current Guest</th>
                                <th class="px-4 py-3 fw-bold text-muted small text-end">Action</th>
                            </tr>
                        </thead>
                    <tbody>
                        <?php while ($unit = $units_stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr class="border-top border-secondary border-opacity-25 bg-transparent">
                            <td class="px-4 py-3 align-middle fw-semibold"><?php echo htmlspecialchars($unit['UNIT_NAME']); ?></td>
                            <td class="px-4 py-3 align-middle"><?php echo htmlspecialchars($unit['TIER_NAME']); ?></td>
                            <td class="px-4 py-3 align-middle">
                                <?php 
                                    $badge_class = ($unit['OCCUPANCY_STATUS'] == 'Available') ? 'bg-success text-success' : 
                                                   (($unit['OCCUPANCY_STATUS'] == 'Booked') ? 'bg-warning text-dark' : 'bg-secondary text-white');
                                ?>
                                <span class="badge <?php echo $badge_class; ?> bg-opacity-25 rounded-pill px-3 py-1 fw-normal">
                                    <?php echo htmlspecialchars($unit['OCCUPANCY_STATUS']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 align-middle text-muted-custom">
                                <?php echo $unit['PET_NAME'] ? htmlspecialchars($unit['PET_NAME']) : '<span class="text-muted fst-italic">Empty</span>'; ?>
                            </td>
                            <td class="px-4 py-3 align-middle text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#updateStatusModal"
                                        data-unitid="<?php echo $unit['ACCOMMODATION_ID']; ?>"
                                        data-unitname="<?php echo htmlspecialchars($unit['UNIT_NAME']); ?>"
                                        data-unitstatus="<?php echo htmlspecialchars($unit['OCCUPANCY_STATUS']); ?>">
                                    <i class="bi bi-pencil-square"></i> Update
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background-color: #d35400; color: white;">
        <h5 class="modal-title" id="updateStatusModalLabel">Update Unit Status</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="staff_dashboard.php" method="POST">
          <div class="modal-body p-4">
              <input type="hidden" name="action" value="update_unit_status">
              <input type="hidden" name="accommodation_id" id="modalUnitId">
              
              <div class="mb-3">
                  <label class="form-label fw-bold small text-muted">Unit Name</label>
                  <input type="text" class="form-control bg-light" id="modalUnitName" readonly>
              </div>
              
              <div class="mb-3">
                  <label class="form-label fw-bold small text-muted">Occupancy Status</label>
                  <select class="form-select" name="occupancy_status" id="modalUnitStatus" required>
                      <option value="Available">Available</option>
                      <option value="Booked">Booked</option>
                      <option value="Under Maintenance">Under Maintenance</option>
                  </select>
              </div>
              <div class="alert alert-warning small mb-0">
                  <i class="bi bi-info-circle me-1"></i> Ensure the physical unit matches this status before saving.
              </div>
          </div>
          <div class="modal-footer border-top">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" style="background-color: #d35400; border: none;">Save Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
    // dynamically populate the Modal based on the button clicked
    document.addEventListener('DOMContentLoaded', function() {
        var updateStatusModal = document.getElementById('updateStatusModal');
        updateStatusModal.addEventListener('show.bs.modal', function (event) {
            // Button that triggered the modal
            var button = event.relatedTarget;
            
            // Extract info from data-* attributes
            var unitId = button.getAttribute('data-unitid');
            var unitName = button.getAttribute('data-unitname');
            var unitStatus = button.getAttribute('data-unitstatus');
            
            // Update the modal's inputs
            updateStatusModal.querySelector('#modalUnitId').value = unitId;
            updateStatusModal.querySelector('#modalUnitName').value = unitName;
            updateStatusModal.querySelector('#modalUnitStatus').value = unitStatus;
        });
    });
</script>
</body>
</html>