uselesss    

<!-- <?php  
session_start();
require_once '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $pdo->beginTransaction();

        // 1. Insert Owner (Data Dictionary: OWNER table) 
        // Note: Oracle PDO requires special handling for RETURNING into a variable
        $owner_stmt = $pdo->prepare("INSERT INTO OWNER (OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER) 
                                     VALUES (OWNER_SEQ.NEXTVAL, :fname, :lname, :contact) 
                                     RETURNING OWNER_ID INTO :last_id");
        
        $owner_id = 0; // Initialize variable for binding
        $owner_stmt->bindParam(':fname', $_POST['first_name']);
        $owner_stmt->bindParam(':lname', $_POST['last_name']);
        $owner_stmt->bindParam(':contact', $_POST['contact']);
        $owner_stmt->bindParam(':last_id', $owner_id, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 38);
        $owner_stmt->execute();
        
        // 2. Insert Pet(s) (Data Dictionary: PET table) [cite: 312, 388, 481]
        if (isset($_POST['pets']) && is_array($_POST['pets'])) {
            $pet_stmt = $pdo->prepare("INSERT INTO PET (    
                PET_ID, PET_NAME, SEX, WEIGHT, FEEDING_TIME, 
                FEEDING_PORTION, OWNER_ID, CATEGORY_ID, BEHAVIORAL_NOTES
            ) VALUES (
                PET_SEQ.NEXTVAL, :name, :sex, :weight, :ftime, 
                :fportion, :oid, :cid, :notes
            )");

            foreach ($_POST['pets'] as $pet) {
                // Ensure correct mapping to Oracle Category IDs (1: Dog, 2: Cat, 3: Bird) [cite: 229, 304]
                $pet_stmt->execute([
                    'name'    => $pet['pet_name'],
                    'sex'     => ucfirst($pet['sex']), // Enforces check constraint ('Male', 'Female') 
                    'weight'  => $pet['pet_weight'],
                    'ftime'   => $pet['feeding_time'], // Business Rule: Mandatory Feeding Time 
                    'fportion'=> $pet['portion'],      // Business Rule: Mandatory Portion
                    'oid'     => $owner_id,
                    'cid'     => $pet['category_id'], 
                    'notes'   => "Initial registration onboarding."
                ]);
            }
        }

        $pdo->commit();
        header("Location: owner.php?success=1");
        exit();

   } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // JELLYACE: Graceful Error Handling
        if (strpos($e->getMessage(), 'ORA-00001') !== false) {
            $error_msg = "Error: The contact number provided is already registered to another owner.";
        } else {
            $error_msg = "Database Error: Please contact system administration.";
        }
        
        // Redirect back to the form with the error message
        header("Location: register_owner.php?error=" . urlencode($error_msg));
        exit();
    }
}
?>



<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Owner & Pet - Radog's Pet Hotel</title>
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
</head>
<body>
<div class="dashboard-shell d-flex min-vh-100">
    <aside class="sidebar d-flex flex-column p-4">
        <div class="sidebar-brand mb-5 text-center">
            <div class="sidebar-logo mb-3 d-inline-flex align-items-center justify-content-center">
                <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            </div>
            <div>
                <h2 class="h5 mb-1">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo $_SESSION['username'] ?? 'Juan Dela Cruz'; ?></p>
                <p class="mb-0 text-muted-custom small"><?php echo $_SESSION['group_name'] ?? 'Staff'; ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="staff_dashboard.php" class="nav-link d-flex align-items-center mb-2<?php echo basename($_SERVER['PHP_SELF']) === 'staff_dashboard.php' ? ' active' : ''; ?>">
                <i class="bi bi-house-door-fill me-3"></i> Dashboard
            </a>
            <a href="encode_reservation.php" class="nav-link d-flex align-items-center mb-2<?php echo basename($_SERVER['PHP_SELF']) === 'encode_reservation.php' ? ' active' : ''; ?>">
                <i class="bi bi-calendar-check me-3"></i> Schedule Appointment
            </a>
            <a href="calendar.php" class="nav-link d-flex align-items-center mb-2<?php echo basename($_SERVER['PHP_SELF']) === 'calendar.php' ? ' active' : ''; ?>">
                <i class="bi bi-calendar3 me-3"></i> Calendar
            </a>
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2<?php echo basename($_SERVER['PHP_SELF']) === 'owner.php' ? ' active' : ''; ?>">
                <i class="bi bi-people me-3"></i> Owners
            </a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2<?php echo basename($_SERVER['PHP_SELF']) === 'pets.php' ? ' active' : ''; ?>">
                <i class="bi bi-paw me-3"></i> Pets
            </a>
            <a href="checkout.php" class="nav-link d-flex align-items-center mb-2<?php echo basename($_SERVER['PHP_SELF']) === 'checkout.php' ? ' active' : ''; ?>">
                <i class="bi bi-cash-stack me-3"></i> Checkout/Payments
            </a>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
    <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
        <i class="bi bi-gear-fill me-3"></i> User Management
    </a>
<?php endif; ?>
        </nav>
        <div class="mt-auto">
            <a href="../logout.php" class="btn btn-link logout-link d-flex align-items-center gap-2">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>
    <main class="main-content flex-grow-1 p-4 p-md-5">
<div class="container p-4 p-md-5">
    <div class="mb-4"><h1 class="h3 mb-1">Owner & Pet Registration</h1><p class="text-muted-custom">Register walk-in owners and their pets</p></div>
    
    <!-- Pricing Information Section -->
    <div class="bg-panel p-4 mb-4 border rounded shadow-sm" style="background-color: #ffffff;">
        <h2 class="h5 mb-3">Pricing Information</h2>
        <h3 class="h6 mb-2">Daily Boarding Rates (based on pet weight and size tier):</h3>
        <ul class="list-unstyled mb-3">
            <li><strong>Small (&lt; 10 kg or &lt; 24 lb):</strong> Php 500.00 per day.</li>
            <li><strong>Medium (11-26 kg or 25-29 lb):</strong> Php 750.00 per day.</li>
            <li><strong>Large (27-45 kg or 60-99 lb):</strong> Php 1,000.00 per day.</li>
            <li><strong>Giant (&gt; 45 kg or &gt; 100 lb):</strong> Php 1,500.00 per day.</li>
        </ul>
        <h3 class="h6 mb-2">Additional Services and Charges:</h3>
        <ul class="list-unstyled mb-3">
            <li><strong>NexGard Administration:</strong> Php 675.00 (This is mandatory if ticks or fleas are spotted or if none was given prior to boarding).</li>
            <li><strong>Dog food door-to-door delivery:</strong> +Php 50.00.</li>
        </ul>
        <h3 class="h6 mb-2">Free Inclusions:</h3>
        <ul class="list-unstyled">
            <li>Supervised play (solo or with Radog's Pack) inside or outside.</li>
            <li>Walking during early morning or late afternoon.</li>
            <li>Exit bath using premium Organic Madre De Cacao bubble bath (requires a minimum stay of 3 days).</li>
        </ul>
    </div>
    
    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="bg-panel p-4 h-100">
                    <h2 class="h5 mb-4">Owner Information</h2>
                    <input type="text" name="first_name" class="form-control mb-3" placeholder="First Name" required>
                    <input type="text" name="last_name" class="form-control mb-3" placeholder="Last Name" required>
                    <input type="tel" name="contact" class="form-control mb-3" placeholder="Contact Number" required>
                </div>
            </div>
       <div class="col-lg-6">
    <div class="bg-panel p-4 h-100">
        <h2 class="h5 mb-4">Pet Information</h2>
        <div id="petContainer">
            <div class="pet-entry mb-4 p-3 border rounded bg-light">
                <input type="text" name="pets[0][pet_name]" class="form-control mb-3" placeholder="Pet Name" required>
                
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <select name="pets[0][category_id]" class="form-select" required>
                            <option value="">Species/Category</option>
                            <option value="1">Canine (Dog)</option>
                            <option value="2">Feline (Cat)</option>
                            <option value="3">Avian (Bird)</option>
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
                    <label class="form-label small">Feeding Instructions (Onboarding Requirement)</label>
                    <input type="text" name="pets[0][feeding_time]" placeholder="Feeding Time (e.g. 08:00)" class="form-control mb-2" required>
                    <input type="text" name="pets[0][portion]" placeholder="Portion (e.g. 1 cup)" class="form-control" required>
                </div>
            </div>
        </div>
        
        <button type="button" id="addPetBtn" class="btn btn-light border w-100 py-2 mt-2" style="background-color: #FAF3E1;">
            <i class="bi bi-plus-lg"></i> Add Another Pet
        </button>
    </div>
</div>
        </div>
        <div class="d-flex gap-3">
            <a href="owner.php" class="btn btn-light border px-4 py-2">Cancel</a>
            <button type="submit" class="btn btn-brand flex-grow-1 py-2">Register</button>
        </div>
        <div id="additionalPets"></div> 
                </div>
            </div>
        </div>
        
    </form>

    </main>
</div>
<script src="../assets/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/validation.js"></script>
<script>
document.getElementById('addPetBtn').addEventListener('click', function() {
    const petContainer = document.getElementById('petContainer');
    const petEntries = petContainer.querySelectorAll('.pet-entry');
    const newIndex = petEntries.length;
    
    const firstPet = petEntries[0];
    const newPet = firstPet.cloneNode(true);
    
    // Update names
    const inputs = newPet.querySelectorAll('input, select');
    inputs.forEach(input => {
        const name = input.name;
        if (name) {
            input.name = name.replace(/pets\[0\]/, `pets[${newIndex}]`);
            if (input.type === 'radio') {
                input.checked = input.value === 'Male'; // default to Male
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
</script>
</body>
</html> -->

