<?php
session_start();
require_once '../config/db.php';

// RBAC: Ensure authorized access
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

// Get Pet ID from URL
$pet_id = $_GET['id'] ?? null;

if (!$pet_id) {
    header("Location: pets.php");
    exit();
}

// Fetch Pet details with Owner and Category information [cite: 312, 315, 316]
$query = "SELECT P.PET_NAME, P.WEIGHT, P.SEX, P.FEEDING_TIME, P.FEEDING_PORTION, 
                 P.BEHAVIORAL_NOTES, O.FIRST_NAME, O.LAST_NAME, C.CATEGORY_NAME
          FROM PET P
          JOIN OWNER O ON P.OWNER_ID = O.OWNER_ID
          JOIN PET_CATEGORY C ON P.CATEGORY_ID = C.CATEGORY_ID
          WHERE P.PET_ID = :pid";

$stmt = $pdo->prepare($query);
$stmt->execute(['pid' => $pet_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet) {
    die("Pet profile not found.");
}

// Logic for Pricing Tier based on weight [cite: 104, 258]
$weight = (float)$pet['WEIGHT'];
if ($weight <= 10) $tier = "Small";
elseif ($weight <= 26) $tier = "Medium";
elseif ($weight <= 45) $tier = "Large";
else $tier = "Giant";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pet['PET_NAME']); ?>'s Profile - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body>
<div class="dashboard-shell d-flex min-vh-100">
   <aside class="sidebar d-flex flex-column p-4" style="background-color: #F5E7C6;">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo htmlentities($_SESSION['username'] ?? 'Staff'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="admin_dashboard.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="calendar.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2 active" style="background-color: #FA8112; color: white;"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="checkout.php" class="nav-link d-flex align-items-center mb-2 "><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
               <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
    <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
        <i class="bi bi-gear-fill me-3"></i> User Management
    </a>
<?php endif; ?>
        </nav>
        <div class="mt-auto">
            <a href="../logout.php" class="btn btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content flex-grow-1 p-4 p-md-5 bg-light">
        <div class="container">
            <a href="pets.php" class="btn btn-link text-muted-custom text-decoration-none px-0 mb-4">
                <i class="bi bi-arrow-left"></i> Back to Pets
            </a>
            
            <div class="mb-4">
                <h1 class="h3 mb-1"><?php echo htmlspecialchars($pet['PET_NAME']); ?>'s Profile</h1>
                <p class="text-muted-custom"><?php echo htmlspecialchars($pet['CATEGORY_NAME']); ?> care details and records</p>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="bg-panel p-4 h-100 shadow-sm">
                        <h2 class="h5 mb-4">Basic Information</h2>
                        <div class="mb-2"><small class="text-muted-custom text-uppercase fw-bold">Sex: </small><span><?php echo htmlspecialchars($pet['SEX']); ?></span></div>
                        <div class="mb-2"><small class="text-muted-custom text-uppercase fw-bold">Weight: </small><span><?php echo htmlspecialchars($pet['WEIGHT']); ?> kg</span></div>
                        <div class="mb-2"><small class="text-muted-custom text-uppercase fw-bold">Pricing Tier: </small><span class="badge bg-brand bg-opacity-10 text-brand"><?php echo $tier; ?></span></div>
                        <div><small class="text-muted-custom text-uppercase fw-bold">Owner: </small><span><?php echo htmlspecialchars($pet['FIRST_NAME'] . ' ' . $pet['LAST_NAME']); ?></span></div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="bg-panel p-4 h-100 shadow-sm">
                        <h2 class="h5 mb-4">Feeding Instructions</h2>
                        <div class="mb-3">
                            <small class="text-muted-custom d-block mb-1">Preferred Time:</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($pet['FEEDING_TIME']); ?></p>
                        </div>
                        <div>
                            <small class="text-muted-custom d-block mb-1">Portion Size:</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($pet['FEEDING_PORTION']); ?></p>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="bg-panel p-4 h-100 shadow-sm">
                        <h2 class="h5 mb-4">Compliance Vault</h2>
                        <p class="small text-muted mb-3">Verify vet cards and vaccination status before confirming bookings.</p>
                        <button class="btn btn-brand w-100 py-2" id="viewDocumentsBtn"><i class="bi bi-file-earmark-medical"></i> View Documents</button>
                        <div id="documentCheckboxes" class="mt-3" style="display: none;">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="regForm" disabled>
                                <label class="form-check-label small" for="regForm">
                                    Pet Physical Registration Form
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="waiverForm" disabled>
                                <label class="form-check-label small" for="waiverForm">
                                    Waiver and Consent Form
                                </label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="vaccCard" disabled>
                                <label class="form-check-label small" for="vaccCard">
                                    Complete and Updated Vaccination Card (Vet Card)
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-panel p-4 shadow-sm">
                <h2 class="h5 mb-4">Behavioral & Handling Notes</h2>
                <textarea readonly rows="3" class="form-control bg-light border-0"><?php echo htmlspecialchars($pet['BEHAVIORAL_NOTES'] ?? 'No behavioral notes recorded.'); ?></textarea>
            </div>
        </div>
    </main>
</div>
<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('viewDocumentsBtn').addEventListener('click', function() {
    const checkboxes = document.getElementById('documentCheckboxes');
    checkboxes.style.display = checkboxes.style.display === 'none' ? 'block' : 'none';
});
</script>
</body>
</html>
