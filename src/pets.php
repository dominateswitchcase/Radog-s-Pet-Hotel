<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// Session guard
requireLogin();
$role = $_SESSION['role'];

// 1. Strict Variable Initialization (Fixes the Undefined Variable Warning)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';

// Fetch all categories dynamically for the dropdown
$cat_stmt = $pdo->query("SELECT CATEGORY_ID, CATEGORY_NAME FROM PET_CATEGORY ORDER BY CATEGORY_ID ASC");
$all_categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Base Query
$queryStr = "SELECT P.PET_ID, P.PET_NAME, C.CATEGORY_NAME, O.FIRST_NAME, O.LAST_NAME 
             FROM PET P
             JOIN OWNER O ON P.OWNER_ID = O.OWNER_ID
             JOIN PET_CATEGORY C ON P.CATEGORY_ID = C.CATEGORY_ID
             WHERE 1=1";

$params = [];

// 2. Safe Search Binding
if ($search !== '') {
    $queryStr .= " AND LOWER(P.PET_NAME) LIKE LOWER(:search)";
    $params['search'] = '%' . $search . '%';
}

// 3. Strict Numeric Validation (Fixes ORA-01722: Invalid Number)
// We only append this to the query if the filter is NOT 'all' AND is strictly a number.
if ($category_filter !== 'all' && is_numeric($category_filter)) {
    $queryStr .= " AND P.CATEGORY_ID = :category";
    $params['category'] = (int)$category_filter; // Cast to integer for Oracle
}

$queryStr .= " ORDER BY P.PET_NAME ASC";
$stmt = $pdo->prepare($queryStr);

try {
    $stmt->execute($params);
} catch (PDOException $e) {
    // Graceful error handling instead of raw white-screen crashes
    die("Database Query Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pet Profiles - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --orange:      #FA8112;
            --orange-dk:   #d96a08;
            --orange-lt:   #fca04a;
            --black:       #222222;
            --beige:       #FAF3E1;
            --gold:        #F5E7C6;
            --white:       #ffffff;
            --radius-card:  20px;
            --radius-input: 12px;
            --radius-btn:   12px;
            --shadow-card:  0 24px 70px rgba(15, 23, 42, 0.08);
            --border-soft:  1px solid rgba(34, 34, 34, 0.08);
        }
        .sidebar{background:var(--black);color:var(--gold);position:relative}
        .sidebar::before{content:'';position:absolute;inset:0;background-image:repeating-linear-gradient(-55deg,transparent,transparent 18px,rgba(250,129,18,0.04) 18px,rgba(250,129,18,0.04) 19px);opacity:0.08}
        .brand h1{font-family:'Bebas Neue',cursive;color:var(--orange);}
        .user-pill{background:rgba(250,129,18,0.08);padding:8px 12px;border-radius:999px;color:var(--white);display:inline-flex;align-items:center;gap:8px}
        body { display: flex; min-height: 100vh; }
        .main-content { flex-grow: 1; background: #f8f9fa; }
    </style>
</head>
<body>
    <aside class="sidebar d-flex flex-column p-4">
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
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2 active" style="background-color: #FA8112; color: white;"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="checkout.php" class="nav-link d-flex align-items-center mb-2" ><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
               <?php if (isAdmin()): ?>
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
    <main class="main-content flex-grow-1 p-4 p-md-5">
        <div class="container-fluid">
            <div class="mb-4">
                <h1 class="h3 mb-1">Pet Profiles</h1>
                <p class="text-muted-custom">Centralized pet registry and historical data</p>
            </div>

            <form action="pets.php" method="GET" class="d-flex gap-3 mb-4">
                <div class="position-relative flex-grow-1" style="max-width: 500px;">
                    <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           class="form-control ps-5 bg-panel" placeholder="Search by pet name...">
                </div>
            <select name="category" class="form-select bg-panel" style="width: auto;" onchange="this.form.submit()">
                <option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
                
                <?php foreach ($all_categories as $cat): ?>
                    <option value="<?php echo $cat['CATEGORY_ID']; ?>" <?php echo $category_filter == $cat['CATEGORY_ID'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['CATEGORY_NAME']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
                <button type="submit" class="btn btn-brand">Search</button>
            </form>

            <div class="bg-panel overflow-hidden shadow-sm">
                <table class="table table-hover mb-0">
                    <thead style="background-color: #FAF3E1;">
                        <tr>
                            <th class="px-4 py-3 border-0">Pet Name</th>
                            <th class="px-4 py-3 border-0">Species</th>
                            <th class="px-4 py-3 border-0">Owner</th>
                            <th class="px-4 py-3 border-0 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                        <tr class="border-top border-secondary border-opacity-25 bg-transparent">
                            <td class="px-4 py-3 align-middle fw-bold"><?php echo htmlspecialchars($row['PET_NAME']); ?></td>
                            <td class="px-4 py-3 align-middle"><?php echo htmlspecialchars($row['CATEGORY_NAME']); ?></td>
                            <td class="px-4 py-3 align-middle"><?php echo htmlspecialchars($row['FIRST_NAME'] . " " . $row['LAST_NAME']); ?></td>
                            <td class="px-4 py-3 align-middle text-center">
                                <a href="pet_profile.php?id=<?php echo $row['PET_ID']; ?>" class="btn btn-sm btn-outline-brand me-1 mb-1">
                                    <i class="bi bi-eye"></i> Details
                                </a>

                                <?php if (isAdmin()): ?>
                                    <a href="pet_delete.php?id=<?php echo $row['PET_ID']; ?>" class="btn btn-sm btn-danger me-1 mb-1" onclick="return confirm('Delete this pet profile?');">
                                        <i class="bi bi-trash"></i> Delete
                                    </a>
                                    <a href="pet_export.php?id=<?php echo $row['PET_ID']; ?>" class="btn btn-sm btn-outline-secondary mb-1">
                                        <i class="bi bi-download"></i> Export
                                    </a>
                                <?php else: ?>
                                    <a href="pet_edit.php?id=<?php echo $row['PET_ID']; ?>" class="btn btn-sm btn-outline-brand me-1 mb-1">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                    <a href="pet_log_vaccine.php?id=<?php echo $row['PET_ID']; ?>" class="btn btn-sm btn-outline-secondary mb-1">
                                        <i class="bi bi-journal-medical"></i> Log Vaccine
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>

                        <?php if ($stmt->rowCount() === 0): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">No pet records found matching your criteria.</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
