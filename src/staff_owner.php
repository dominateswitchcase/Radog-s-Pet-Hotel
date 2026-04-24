<?php
session_start();
require_once '../config/db.php';

// RBAC: Ensure authorized access
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

// AJAX owner/pet edit handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // =======================================================================
    // JELLYACE: Create New Owner and Pet(s) Logic
    // =======================================================================
    if ($action === 'create_owner_pet') {
        header('Content-Type: application/json');
        
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $pets = $_POST['pets'] ?? [];

        if (!$first_name || !$last_name || !$contact || empty($pets)) {
            echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // 1. Insert Owner & RETURNING ID
            $owner_stmt = $pdo->prepare("INSERT INTO OWNER (OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER, STATUS) 
                                         VALUES (OWNER_SEQ.NEXTVAL, :fname, :lname, :contact, 'Active') 
                                         RETURNING OWNER_ID INTO :last_id");
            
            $owner_id = 0; 
            $owner_stmt->bindParam(':fname', $first_name);
            $owner_stmt->bindParam(':lname', $last_name);
            $owner_stmt->bindParam(':contact', $contact);
            $owner_stmt->bindParam(':last_id', $owner_id, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 38);
            $owner_stmt->execute();
            
            // 2. Insert Pet(s)
            $pet_stmt = $pdo->prepare("INSERT INTO PET (    
                PET_ID, PET_NAME, SEX, WEIGHT, FEEDING_TIME, 
                FEEDING_PORTION, OWNER_ID, CATEGORY_ID, BEHAVIORAL_NOTES, STATUS
            ) VALUES (
                PET_SEQ.NEXTVAL, :name, :sex, :weight, :ftime, 
                :fportion, :oid, :cid, :notes, 'Active'
            )");

            foreach ($pets as $pet) {
                $pet_stmt->execute([
                    'name'    => $pet['pet_name'],
                    'sex'     => ucfirst($pet['sex']), 
                    'weight'  => $pet['pet_weight'],
                    'ftime'   => $pet['feeding_time'], 
                    'fportion'=> $pet['portion'],      
                    'oid'     => $owner_id,
                    'cid'     => $pet['category_id'], 
                    'notes'   => "Initial registration onboarding."
                ]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Owner and pet successfully registered.']);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Graceful constraint error handling
            if (strpos($e->getMessage(), 'ORA-00001') !== false) {
                echo json_encode(['success' => false, 'message' => 'Error: The contact number provided is already registered.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
            }
            exit();
        }
    }

    if ($action === 'get_owner_data') {
        header('Content-Type: application/json');
        $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_VALIDATE_INT);
        if (!$owner_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
            exit();
        }

        $owner_stmt = $pdo->prepare("SELECT OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER FROM OWNER WHERE OWNER_ID = :id");
        $owner_stmt->execute(['id' => $owner_id]);
        $owner = $owner_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$owner) {
            echo json_encode(['success' => false, 'message' => 'Owner not found']);
            exit();
        }

        $pets_stmt = $pdo->prepare("SELECT PET_ID, PET_NAME, CATEGORY_ID, WEIGHT, SEX, FEEDING_TIME, FEEDING_PORTION FROM PET WHERE OWNER_ID = :id ORDER BY PET_NAME");
        $pets_stmt->execute(['id' => $owner_id]);
        $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => ['owner' => $owner, 'pets' => $pets]]);
        exit();
    }

    if ($action === 'update_owner_pet') {
        header('Content-Type: application/json');
        $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_VALIDATE_INT);
        $pet_id = filter_input(INPUT_POST, 'pet_id', FILTER_VALIDATE_INT);
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $pet_name = trim($_POST['pet_name'] ?? '');
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $weight = filter_input(INPUT_POST, 'weight', FILTER_VALIDATE_FLOAT);
        $sex = trim($_POST['sex'] ?? '');
        $feeding_time = trim($_POST['feeding_time'] ?? '');
        $portion = trim($_POST['portion'] ?? '');

        if (!$owner_id || !$first_name || !$last_name || !$contact || !$pet_id || !$pet_name || !$category_id || !$weight || !$sex || !$feeding_time || !$portion) {
            echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
            exit();
        }

        try {
            $pdo->beginTransaction();
            $owner_update = $pdo->prepare("UPDATE OWNER SET FIRST_NAME = :fname, LAST_NAME = :lname, CONTACT_NUMBER = :contact WHERE OWNER_ID = :id");
            $owner_update->execute([
                'fname' => $first_name,
                'lname' => $last_name,
                'contact' => $contact,
                'id' => $owner_id,
            ]);

            $pet_update = $pdo->prepare("UPDATE PET SET PET_NAME = :pet_name, CATEGORY_ID = :category_id, WEIGHT = :weight, SEX = :sex, FEEDING_TIME = :feeding_time, FEEDING_PORTION = :portion WHERE PET_ID = :pet_id AND OWNER_ID = :owner_id");
            $pet_update->execute([
                'pet_name' => $pet_name,
                'category_id' => $category_id,
                'weight' => $weight,
                'sex' => ucfirst($sex),
                'feeding_time' => $feeding_time,
                'portion' => $portion,
                'pet_id' => $pet_id,
                'owner_id' => $owner_id,
            ]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Owner and pet information updated successfully.']);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'delete_owner') {
        header('Content-Type: application/json');
        
        // JELLYACE SECURITY CONSTRAINT: Admin Only Soft Delete
        if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized Access: Only Administrators can deactivate profiles.']);
            exit();
        }

        $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_VALIDATE_INT);
        
        if (!$owner_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
            exit();
        }

        $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM BOOKING WHERE OWNER_ID = :id AND Booking_Status IN ('Confirmed', 'Pending')");
        $check_stmt->execute(['id' => $owner_id]);
        $active_bookings = $check_stmt->fetchColumn();

        if ($active_bookings > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot deactivate: Owner currently has active or pending bookings.']);
            exit();
        }

        try {
            $pdo->beginTransaction();
            $pet_update = $pdo->prepare("UPDATE PET SET STATUS = 'Inactive' WHERE OWNER_ID = :owner_id");
            $pet_update->execute(['owner_id' => $owner_id]);
            
            $owner_update = $pdo->prepare("UPDATE OWNER SET STATUS = 'Inactive' WHERE OWNER_ID = :owner_id");
            $owner_update->execute(['owner_id' => $owner_id]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Owner and pet profiles have been securely deactivated.']);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'Deactivation failed: ' . $e->getMessage()]);
            exit();
        }
    }
}

// Handle Search Query
$search = $_GET['search'] ?? '';
$queryStr = "SELECT OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER FROM OWNER WHERE (STATUS = 'Active' OR STATUS IS NULL)";
$params = [];

if (!empty($search)) {
    $queryStr .= " AND (LOWER(FIRST_NAME) LIKE LOWER(:search) 
                  OR LOWER(LAST_NAME) LIKE LOWER(:search) 
                  OR CONTACT_NUMBER LIKE :search)";
    $params['search'] = '%' . $search . '%';
}

$queryStr .= " ORDER BY OWNER_ID DESC";
$stmt = $pdo->prepare($queryStr);
$stmt->execute($params);

$category_stmt = $pdo->query("SELECT CATEGORY_ID, CATEGORY_NAME FROM PET_CATEGORY ORDER BY CATEGORY_NAME");
$categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Profiles - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    <style>
        body { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: #fff; border-right: 1px solid rgba(0,0,0,.1); position: sticky; top: 0; height: 100vh; }
        .main-content { flex-grow: 1; background: #f8f9fa; }
    </style>
</head>
<body>
    <aside class="sidebar d-flex flex-column p-4" style="background-color: #F5E7C6;">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo htmlentities($_SESSION['username'] ?? 'Staff'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
          <a href="staff_dashboard.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="staff_encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="staff_calendar.php" class="nav-link d-flex align-items-center mb-2" style="background-color: #FA8112; color: white;"><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="staff_owner.php" class="nav-link d-flex align-items-center mb-2 active"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="staff_pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="staff_checkout.php" class="nav-link d-flex align-items-center mb-2 " ><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
            <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>
                <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
                    <i class="bi bi-gear-fill me-3"></i> User Management
                </a>
            <?php endif; ?>
        </nav>
        <div class="mt-auto">
            <a href="../index.php" class="btn btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>

    <main class="main-content p-4 p-md-5">
        <div class="mb-4">
            <h1 class="h3 mb-1">Owner Profiles</h1>
        </div>

        <div class="d-flex gap-3 mb-4 flex-wrap">
            <div class="position-relative flex-grow-1" style="max-width: 500px;">
                <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted-custom"></i>
                <form action="owner.php" method="GET">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           class="form-control px-5 py-2 bg-panel" placeholder="Search by name or contact...">
                </form>
            </div>
            
            <button type="button" class="btn btn-brand py-2 px-4 d-inline-flex align-items-center gap-2 text-white" style="background-color: #FA8112; border: none;" data-bs-toggle="modal" data-bs-target="#createOwnerModal">
                <i class="bi bi-person-plus"></i> Register Walk-in Owner
            </button>
        </div>

        <div class="bg-panel overflow-hidden shadow-sm">
            <table class="table table-hover mb-0">
                <thead style="background-color: #FAF3E1;">
                    <tr>
                        <th class="px-4 py-3 border-0">Owner ID</th>
                        <th class="px-4 py-3 border-0">First Name</th>
                        <th class="px-4 py-3 border-0">Last Name</th>
                        <th class="px-4 py-3 border-0">Contact</th>
                        <th class="px-4 py-3 border-0 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr class="border-top border-secondary border-opacity-25 bg-transparent">
                        <td class="px-4 py-3 fw-bold">OWN-<?php echo str_pad($row['OWNER_ID'], 4, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo htmlspecialchars($row['FIRST_NAME']); ?></td>
                        <td><?php echo htmlspecialchars($row['LAST_NAME']); ?></td>
                        <td><?php echo htmlspecialchars($row['CONTACT_NUMBER']); ?></td>
                        <td class="text-center">
                            <a href="owner_profile.php?id=<?php echo $row['OWNER_ID']; ?>" class="btn btn-sm btn-outline-brand me-2">
                                <i class="bi bi-eye"></i> View Profile
                            </a>
                            <button type="button" class="btn btn-sm btn-brand text-white edit-owner-btn me-2" 
                                    data-owner-id="<?php echo $row['OWNER_ID']; ?>" 
                                    data-bs-toggle="modal" 
                                    data-bs-target="#editOwnerModal">
                                <i class="bi bi-pencil-square"></i> Edit
                            </button>
                            <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>
                                <button type="button" class="btn btn-sm btn-danger text-white delete-owner-btn" 
                                        data-owner-id="<?php echo $row['OWNER_ID']; ?>">
                                    <i class="bi bi-archive"></i> Deactivate
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    
                    <?php if ($stmt->rowCount() === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">No owner records found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <div class="modal fade" id="createOwnerModal" tabindex="-1" aria-labelledby="createOwnerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header p-4" style="background-color: #FA8112;">
                    <h5 class="modal-title text-white" id="createOwnerModalLabel">Register Owner & Pet</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
                    <form id="createOwnerForm">
                        <input type="hidden" name="action" value="create_owner_pet">
                        
                        <h6 class="fw-bold mb-3 text-muted">Owner Information</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <input type="text" name="first_name" class="form-control" placeholder="First Name" required>
                            </div>
                            <div class="col-md-6">
                                <input type="text" name="last_name" class="form-control" placeholder="Last Name" required>
                            </div>
                            <div class="col-md-12">
                                <input type="tel" name="contact" class="form-control" placeholder="Contact Number (e.g., 09123456789)" required>
                            </div>
                        </div>
                        
                        <hr>
                        <h6 class="fw-bold mb-3 text-muted">Pet Information</h6>
                        <div id="modalPetContainer">
                            <div class="pet-entry mb-4 p-3 border rounded bg-light">
                                <input type="text" name="pets[0][pet_name]" class="form-control mb-3" placeholder="Pet Name" required>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <select name="pets[0][category_id]" class="form-select" required>
                                            <option value="">Species/Category</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option value="<?php echo $category['CATEGORY_ID']; ?>"><?php echo htmlspecialchars($category['CATEGORY_NAME']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <input type="number" step="0.1" name="pets[0][pet_weight]" class="form-control" placeholder="Weight (kg)" required>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="small d-block mb-2">Sex</label>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="pets[0][sex]" value="Male" checked>
                                        <label class="form-check-label small">Male</label>
                                    </div>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="radio" name="pets[0][sex]" value="Female">
                                        <label class="form-check-label small">Female</label>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small">Feeding Instructions</label>
                                    <input type="text" name="pets[0][feeding_time]" placeholder="Feeding Time (e.g. 08:00)" class="form-control mb-2" required>
                                    <input type="text" name="pets[0][portion]" placeholder="Portion (e.g. 1 cup)" class="form-control" required>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" id="modalAddPetBtn" class="btn btn-light border w-100 py-2 mb-3" style="background-color: #FAF3E1;">
                            <i class="bi bi-plus-lg"></i> Add Another Pet
                        </button>

                        <div id="createModalAlert" class="alert" role="alert" style="display: none;"></div>
                    </form>
                </div>
                <div class="modal-footer p-4 border-top">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-brand text-white" id="submitNewOwnerBtn" style="background-color: #FA8112; border: none;">Register Now</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editOwnerModal" tabindex="-1" aria-labelledby="editOwnerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header p-4" style="background-color: #FA8112;">
                    <h5 class="modal-title text-white" id="editOwnerModalLabel">Edit Owner & Pet Information</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="editOwnerForm">
                        <input type="hidden" id="modalOwnerId" name="owner_id">
                        <input type="hidden" id="modalPetId" name="pet_id">

                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Owner ID</label>
                                <input type="text" class="form-control" id="modalOwnerCode" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">First Name</label>
                                <input type="text" class="form-control" id="modalFirstName" name="first_name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Last Name</label>
                                <input type="text" class="form-control" id="modalLastName" name="last_name" required>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label small fw-bold">Contact Number</label>
                            <input type="tel" class="form-control" id="modalContactNumber" name="contact_number" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">Select Pet</label>
                            <select class="form-select" id="modalPetSelect" name="pet_id" required></select>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Pet Name</label>
                                <input type="text" class="form-control" id="modalPetName" name="pet_name" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Category</label>
                                <select class="form-select" id="modalPetCategory" name="category_id" required>
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['CATEGORY_ID']; ?>"><?php echo htmlspecialchars($category['CATEGORY_NAME']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Weight (kg)</label>
                                <input type="number" step="0.1" class="form-control" id="modalPetWeight" name="weight" required>
                            </div>
                        </div>

                        <div class="row g-3 mt-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Sex</label>
                                <select class="form-select" id="modalPetSex" name="sex" required>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Feeding Time</label>
                                <input type="text" class="form-control" id="modalFeedingTime" name="feeding_time" placeholder="e.g. 08:00" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Portion</label>
                                <input type="text" class="form-control" id="modalPortion" name="portion" placeholder="e.g. 1 cup" required>
                            </div>
                        </div>

                        <div id="modalAlert" class="alert" role="alert" style="display: none;"></div>
                    </form>
                </div>
                <div class="modal-footer p-4 border-top">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-brand" id="saveOwnerPetBtn" style="background-color: #FA8112; border: none;">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const editModal = new bootstrap.Modal(document.getElementById('editOwnerModal'), {});
            const ownerSelect = document.getElementById('modalPetSelect');
            const modalAlert = document.getElementById('modalAlert');
            const createModalAlert = document.getElementById('createModalAlert');
            let ownerPets = [];

            // ========================================================
            // JELLYACE: "Add Another Pet" Dynamic Cloning Logic
            // ========================================================
            document.getElementById('modalAddPetBtn').addEventListener('click', function() {
                const petContainer = document.getElementById('modalPetContainer');
                const petEntries = petContainer.querySelectorAll('.pet-entry');
                const newIndex = petEntries.length;
                
                const firstPet = petEntries[0];
                const newPet = firstPet.cloneNode(true);
                
                // Reset values and update nested array indices
                const inputs = newPet.querySelectorAll('input, select');
                inputs.forEach(input => {
                    const name = input.name;
                    if (name) {
                        input.name = name.replace(/pets\[0\]/, `pets[${newIndex}]`);
                        if (input.type === 'radio') {
                            input.checked = input.value === 'Male';
                        } else {
                            input.value = '';
                        }
                    }
                });
                
                // Add remove button
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-danger btn-sm mt-2';
                removeBtn.innerHTML = '<i class="bi bi-trash"></i> Remove';
                removeBtn.addEventListener('click', function() {
                    newPet.remove();
                });
                newPet.appendChild(removeBtn);
                petContainer.appendChild(newPet);
            });

            // ========================================================
            // AJAX Form Submission for Create
            // ========================================================
            document.getElementById('submitNewOwnerBtn').addEventListener('click', function() {
                const form = document.getElementById('createOwnerForm');
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                // URLSearchParams perfectly handles nested PHP arrays from FormData
                const formData = new URLSearchParams(new FormData(form));

                fetch('owner.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        createModalAlert.className = 'alert alert-success';
                        createModalAlert.textContent = data.message;
                        createModalAlert.style.display = 'block';
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        createModalAlert.className = 'alert alert-danger';
                        createModalAlert.textContent = data.message;
                        createModalAlert.style.display = 'block';
                    }
                })
                .catch(error => {
                    createModalAlert.className = 'alert alert-danger';
                    createModalAlert.textContent = 'Registration failed. Please try again.';
                    createModalAlert.style.display = 'block';
                    console.error(error);
                });
            });

            // ========================================================
            // Handle Edit & Deactivate (Existing Logic)
            // ========================================================
            document.querySelectorAll('.edit-owner-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const ownerId = this.getAttribute('data-owner-id');
                    loadOwnerData(ownerId);
                });
            });

            document.querySelectorAll('.delete-owner-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const ownerId = this.getAttribute('data-owner-id');
                    
                    if (confirm('Are you sure you want to deactivate this owner and all their pet profiles? This will archive their records.')) {
                        const formData = new URLSearchParams();
                        formData.append('action', 'delete_owner');
                        formData.append('owner_id', ownerId);

                        fetch('owner.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData.toString()
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                location.reload();
                            } else {
                                alert(data.message);
                            }
                        })
                        .catch(error => {
                            alert('An error occurred during deactivation. Please try again.');
                            console.error(error);
                        });
                    }
                });
            });

            ownerSelect.addEventListener('change', function() {
                const selectedPet = ownerPets.find(p => p.PET_ID === this.value);
                if (selectedPet) {
                    fillPetFields(selectedPet);
                }
            });

            document.getElementById('saveOwnerPetBtn').addEventListener('click', function() {
                const form = document.getElementById('editOwnerForm');
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                const formData = new URLSearchParams(new FormData(form));
                formData.append('action', 'update_owner_pet');

                fetch('owner.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        modalAlert.className = 'alert alert-success';
                        modalAlert.textContent = data.message;
                        modalAlert.style.display = 'block';
                        setTimeout(() => location.reload(), 1200);
                    } else {
                        modalAlert.className = 'alert alert-danger';
                        modalAlert.textContent = data.message;
                        modalAlert.style.display = 'block';
                    }
                })
                .catch(error => {
                    modalAlert.className = 'alert alert-danger';
                    modalAlert.textContent = 'Could not save changes. Please try again.';
                    modalAlert.style.display = 'block';
                    console.error(error);
                });
            });

            function loadOwnerData(ownerId) {
                fetch('owner.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=get_owner_data&owner_id=' + encodeURIComponent(ownerId)
                })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        modalAlert.className = 'alert alert-danger';
                        modalAlert.textContent = data.message;
                        modalAlert.style.display = 'block';
                        return;
                    }

                    modalAlert.style.display = 'none';
                    const owner = data.data.owner;
                    ownerPets = data.data.pets || [];

                    document.getElementById('modalOwnerId').value = owner.OWNER_ID;
                    document.getElementById('modalOwnerCode').value = 'OWN-' + String(owner.OWNER_ID).padStart(4, '0');
                    document.getElementById('modalFirstName').value = owner.FIRST_NAME;
                    document.getElementById('modalLastName').value = owner.LAST_NAME;
                    document.getElementById('modalContactNumber').value = owner.CONTACT_NUMBER;

                    ownerSelect.innerHTML = '';
                    ownerPets.forEach((pet, index) => {
                        const option = document.createElement('option');
                        option.value = pet.PET_ID;
                        option.textContent = pet.PET_NAME;
                        ownerSelect.appendChild(option);
                    });

                    if (ownerPets.length > 0) {
                        ownerSelect.value = ownerPets[0].PET_ID;
                        fillPetFields(ownerPets[0]);
                    } else {
                        document.getElementById('modalPetId').value = '';
                        document.getElementById('modalPetName').value = '';
                        document.getElementById('modalPetCategory').value = '';
                        document.getElementById('modalPetWeight').value = '';
                        document.getElementById('modalPetSex').value = 'Male';
                        document.getElementById('modalFeedingTime').value = '';
                        document.getElementById('modalPortion').value = '';
                    }
                })
                .catch(error => {
                    modalAlert.className = 'alert alert-danger';
                    modalAlert.textContent = 'Unable to load owner data.';
                    modalAlert.style.display = 'block';
                    console.error(error);
                });
            }

            function fillPetFields(pet) {
                document.getElementById('modalPetId').value = pet.PET_ID;
                document.getElementById('modalPetName').value = pet.PET_NAME;
                document.getElementById('modalPetCategory').value = pet.CATEGORY_ID;
                document.getElementById('modalPetWeight').value = pet.WEIGHT;
                document.getElementById('modalPetSex').value = pet.SEX;
                document.getElementById('modalFeedingTime').value = pet.FEEDING_TIME;
                document.getElementById('modalPortion').value = pet.FEEDING_PORTION;
            }
        });
    </script>
</body>
</html>