<?php
session_start();
require_once '../config/db.php';

// RBAC: Ensure authorized access
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Resolve Tier_ID from weight
// ─────────────────────────────────────────────────────────────────────────────
function resolveTierId($pdo, $weight) {
    $weight = (float)$weight;
    $stmt = $pdo->prepare(
        "SELECT TIER_ID FROM TIER
         WHERE :w >= WEIGHT_MIN AND :w2 <= WEIGHT_MAX
         FETCH FIRST 1 ROWS ONLY"
    );
    $stmt->execute(['w' => $weight, 'w2' => $weight]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return (int)$row['TIER_ID'];
    $fallback = $pdo->query(
        "SELECT TIER_ID FROM TIER ORDER BY WEIGHT_MAX DESC FETCH FIRST 1 ROWS ONLY"
    );
    $row = $fallback->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['TIER_ID'] : 1;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Normalise a 'Yes'/'No' checkbox value.
// Accepts 'Yes', '1', 'on', 'true' (case-insensitive) → 'Yes', else 'No'.
// ─────────────────────────────────────────────────────────────────────────────
function normaliseYesNo($value) {
    if ($value === null) return 'No';
    $v = strtolower(trim((string)$value));
    return in_array($v, ['yes', '1', 'on', 'true']) ? 'Yes' : 'No';
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: Save uploaded files for a pet → PET_DOCUMENT + DOCUMENT_DETAILS
// Returns array of error strings (empty = all ok).
//
// UPLOAD PATH: always resolves to <project-root>/uploads/pet_docs/
// which maps to the web path /uploads/pet_docs/<filename>
// ─────────────────────────────────────────────────────────────────────────────
function saveDocuments($pdo, $petId, $filesArray, $docType = 'Pet Document') {
    $errors = [];

    // Resolve the upload directory relative to the project root.
    // __DIR__ is  <project-root>/pages  (or wherever owner.php lives).
    // We go one level up to reach <project-root>, then into uploads/pet_docs/.
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pet_docs' . DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ["Failed to create upload directory: $uploadDir"];
        }
    }

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];

    // $filesArray must already be normalised to the standard multi-file shape:
    // ['name'=>[...],'tmp_name'=>[...],'error'=>[...],'size'=>[...],'type'=>[...]]
    $count = is_array($filesArray['name']) ? count($filesArray['name']) : 0;

    for ($i = 0; $i < $count; $i++) {
        if ($filesArray['error'][$i] !== UPLOAD_ERR_OK) continue;

        $origName = basename($filesArray['name'][$i]);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt)) {
            $errors[] = "File '$origName' has an unsupported extension.";
            continue;
        }

        $safeName = 'pet_' . $petId . '_' . uniqid() . '.' . $ext;
        $dest     = $uploadDir . $safeName;

        // Normalised web path — always forward slashes for portability
        $webPath  = '/uploads/pet_docs/' . $safeName;

        if (!move_uploaded_file($filesArray['tmp_name'][$i], $dest)) {
            $errors[] = "Failed to move uploaded file '$origName'.";
            continue;
        }

        // Generate Doc ID manually (no sequence assumed in schema)
        $doc_id_row = $pdo->query("SELECT NVL(MAX(DOC_ID), 0) + 1 AS NEXT_ID FROM PET_DOCUMENT")->fetch(PDO::FETCH_ASSOC);
        $docId      = (int) $doc_id_row['NEXT_ID'];

        $ins = $pdo->prepare(
            "INSERT INTO PET_DOCUMENT (DOC_ID, DOCUMENT_TYPE, FILEPATH, UPLOAD_DATE, PET_ID)
             VALUES (:docid, :dtype, :fpath, SYSDATE, :pid)"
        );
        $ins->execute([
            'docid' => $docId,
            'dtype' => $docType,
            'fpath' => $webPath,
            'pid'   => $petId,
        ]);

        // Insert into DOCUMENT_DETAILS — status = Pending until staff reviews
        $det = $pdo->prepare(
            "INSERT INTO DOCUMENT_DETAILS (PET_ID, DOC_ID, VERIFICATION_STATUS, DATE_VERIFIED)
             VALUES (:pid, :did, 'Pending', SYSDATE)"
        );
        $det->execute(['pid' => $petId, 'did' => $docId]);
    }

    return $errors;
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX HANDLERS
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // =========================================================================
    // CREATE OWNER + PET(S)
    // NOTE: The four verification checkboxes (consent_form, nexgard,
    // ocular_exam, vetcard) belong to the BOOKING table in the DB schema,
    // NOT to PET.  At registration time there is no booking yet, so we cannot
    // persist them here.  The values collected in the form are intentionally
    // ignored for DB storage; they will be set when the first booking is
    // created for the pet.  The checkboxes in the create modal are shown purely
    // to let staff note which requirements were already satisfied at walk-in so
    // they can fill them immediately on the booking form.
    // =========================================================================
    if ($action === 'create_owner_pet') {
        header('Content-Type: application/json');

        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $contact    = trim($_POST['contact']    ?? '');
        $pets       = $_POST['pets']            ?? [];

        if (!$first_name || !$last_name || !$contact || empty($pets)) {
            echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Generate Owner ID manually
            $id_row   = $pdo->query("SELECT NVL(MAX(OWNER_ID), 0) + 1 AS NEXT_ID FROM OWNER")->fetch(PDO::FETCH_ASSOC);
            $owner_id = (int) $id_row['NEXT_ID'];

            $owner_stmt = $pdo->prepare(
                "INSERT INTO OWNER (OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER, STATUS)
                 VALUES (:id, :fname, :lname, :contact, 'Active')"
            );
            $owner_stmt->execute([
                'id'      => $owner_id,
                'fname'   => $first_name,
                'lname'   => $last_name,
                'contact' => $contact,
            ]);

            foreach ($pets as $idx => $pet) {
                $weight  = (float)($pet['pet_weight'] ?? 0);
                $tier_id = resolveTierId($pdo, $weight);

                $pet_id_row = $pdo->query("SELECT NVL(MAX(PET_ID), 0) + 1 AS NEXT_ID FROM PET")->fetch(PDO::FETCH_ASSOC);
                $new_pet_id = (int) $pet_id_row['NEXT_ID'];

                $pet_stmt = $pdo->prepare(
                    "INSERT INTO PET (
                        PET_ID, PET_NAME, SEX, WEIGHT, FEEDING_TIME,
                        FEEDING_PORTION, OWNER_ID, CATEGORY_ID, TIER_ID,
                        BEHAVIORAL_NOTES, STATUS
                     ) VALUES (
                        :pid, :name, :sex, :weight, :ftime,
                        :fportion, :oid, :cid, :tid,
                        :notes, 'Active'
                     )"
                );
                $pet_stmt->execute([
                    'pid'      => $new_pet_id,
                    'name'     => $pet['pet_name'],
                    'sex'      => ucfirst($pet['sex'] ?? 'Male'),
                    'weight'   => $weight,
                    'ftime'    => $pet['feeding_time'] ?? null,
                    'fportion' => $pet['portion']      ?? null,
                    'oid'      => $owner_id,
                    'cid'      => (int)($pet['category_id'] ?? 1),
                    'tid'      => $tier_id,
                    'notes'    => 'Initial registration onboarding.',
                ]);

                // Save uploaded documents for this pet
                if (
                    isset($_FILES['pets']['name'][$idx]['documents']) &&
                    !empty($_FILES['pets']['name'][$idx]['documents'][0])
                ) {
                    $filesForPet = [
                        'name'     => (array)$_FILES['pets']['name'][$idx]['documents'],
                        'tmp_name' => (array)$_FILES['pets']['tmp_name'][$idx]['documents'],
                        'error'    => (array)$_FILES['pets']['error'][$idx]['documents'],
                        'size'     => (array)$_FILES['pets']['size'][$idx]['documents'],
                        'type'     => (array)$_FILES['pets']['type'][$idx]['documents'],
                    ];
                    saveDocuments($pdo, $new_pet_id, $filesForPet, 'Pet Document');
                }
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Owner and pet successfully registered.']);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (strpos($e->getMessage(), 'ORA-00001') !== false) {
                echo json_encode(['success' => false, 'message' => 'Error: The contact number provided is already registered.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
            }
            exit();
        }
    }

    // =========================================================================
    // GET OWNER DATA
    // =========================================================================
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

        // Fetch pets with latest booking verification states
        $pets_stmt = $pdo->prepare("
            SELECT P.PET_ID, P.PET_NAME, P.CATEGORY_ID, P.WEIGHT, P.SEX,
                   P.FEEDING_TIME, P.FEEDING_PORTION,
                   NVL(B.CONSENT_FORM_SIGNED, 'No') AS CONSENT_FORM_SIGNED,
                   NVL(B.NEXGARD_VERIFIED,    'No') AS NEXGARD_VERIFIED,
                   NVL(B.OCULAR_EXAM_PASSED,  'No') AS OCULAR_EXAM_PASSED,
                   NVL(B.VETCARD_VERIFIED,     'No') AS VETCARD_VERIFIED
            FROM PET P
            LEFT JOIN (
                SELECT PET_ID, CONSENT_FORM_SIGNED, NEXGARD_VERIFIED,
                       OCULAR_EXAM_PASSED, VETCARD_VERIFIED
                FROM BOOKING
                WHERE (PET_ID, BOOKING_ID) IN (
                    SELECT PET_ID, MAX(BOOKING_ID)
                    FROM BOOKING
                    GROUP BY PET_ID
                )
            ) B ON P.PET_ID = B.PET_ID
            WHERE P.OWNER_ID = :id
            AND P.STATUS = 'Active'
            ORDER BY P.PET_NAME
        ");
        $pets_stmt->execute(['id' => $owner_id]);
        $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch documents for all pets of this owner — normalise filepath slashes
        $docs_stmt = $pdo->prepare("
            SELECT pd.DOC_ID,
                   pd.DOCUMENT_TYPE,
                   REPLACE(pd.FILEPATH, '\\', '/') AS FILEPATH,
                   TO_CHAR(pd.UPLOAD_DATE, 'YYYY-MM-DD') AS UPLOAD_DATE,
                   pd.PET_ID,
                   dd.VERIFICATION_STATUS,
                   TO_CHAR(dd.DATE_VERIFIED, 'YYYY-MM-DD') AS DATE_VERIFIED
            FROM PET_DOCUMENT pd
            LEFT JOIN DOCUMENT_DETAILS dd
                   ON pd.DOC_ID = dd.DOC_ID AND pd.PET_ID = dd.PET_ID
            WHERE pd.PET_ID IN (
                SELECT PET_ID FROM PET WHERE OWNER_ID = :id AND STATUS = 'Active'
            )
            ORDER BY pd.UPLOAD_DATE DESC
        ");
        $docs_stmt->execute(['id' => $owner_id]);
        $documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data'    => [
                'owner'     => $owner,
                'pets'      => $pets,
                'documents' => $documents,
            ],
        ]);
        exit();
    }

    // =========================================================================
    // UPDATE OWNER + PET
    // Verification checkbox values (consent, nexgard, ocular, vetcard) are
    // stored on the BOOKING table.  On update we persist them to the pet's
    // MOST RECENT booking so the state is preserved for display.  If no
    // booking exists for this pet yet we skip the booking update gracefully.
    // =========================================================================
    if ($action === 'update_owner_pet') {
        header('Content-Type: application/json');

        $owner_id    = filter_input(INPUT_POST, 'owner_id',    FILTER_VALIDATE_INT);
        $pet_id      = filter_input(INPUT_POST, 'pet_id',      FILTER_VALIDATE_INT);
        $first_name  = trim($_POST['first_name']     ?? '');
        $last_name   = trim($_POST['last_name']      ?? '');
        $contact     = trim($_POST['contact_number'] ?? '');
        $pet_name    = trim($_POST['pet_name']       ?? '');
        $category_id = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $weight      = filter_input(INPUT_POST, 'weight',       FILTER_VALIDATE_FLOAT);
        $sex         = trim($_POST['sex']            ?? '');
        $feeding_time= trim($_POST['feeding_time']   ?? '');
        $portion     = trim($_POST['portion']        ?? '');

        // Normalise checkbox values — unchecked boxes send nothing, so default to 'No'
        $consent  = normaliseYesNo($_POST['consent_form']  ?? null);
        $nexgard  = normaliseYesNo($_POST['nexgard']       ?? null);
        $ocular   = normaliseYesNo($_POST['ocular_exam']   ?? null);
        $vetcard  = normaliseYesNo($_POST['vetcard']       ?? null);

        if (!$owner_id || !$first_name || !$last_name || !$contact ||
            !$pet_id || !$pet_name || !$category_id || !$weight || !$sex ||
            !$feeding_time || !$portion) {
            echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
            exit();
        }

        try {
            $pdo->beginTransaction();

            // Update owner
            $pdo->prepare(
                "UPDATE OWNER SET FIRST_NAME = :fname, LAST_NAME = :lname,
                 CONTACT_NUMBER = :contact WHERE OWNER_ID = :id"
            )->execute(['fname' => $first_name, 'lname' => $last_name, 'contact' => $contact, 'id' => $owner_id]);

            // Resolve tier from new weight
            $tier_id = resolveTierId($pdo, $weight);

            // Update pet
            $pdo->prepare(
                "UPDATE PET SET PET_NAME = :pet_name, CATEGORY_ID = :category_id,
                 WEIGHT = :weight, SEX = :sex, FEEDING_TIME = :feeding_time,
                 FEEDING_PORTION = :portion, TIER_ID = :tier_id
                 WHERE PET_ID = :pet_id AND OWNER_ID = :owner_id"
            )->execute([
                'pet_name'     => $pet_name,
                'category_id'  => $category_id,
                'weight'       => $weight,
                'sex'          => ucfirst($sex),
                'feeding_time' => $feeding_time,
                'portion'      => $portion,
                'tier_id'      => $tier_id,
                'pet_id'       => $pet_id,
                'owner_id'     => $owner_id,
            ]);

            // Persist verification checkboxes to the pet's most recent booking.
            // We do NOT touch bookings that are Cancelled or Completed — only the
            // latest active/pending/confirmed booking, if any.
            $bk_stmt = $pdo->prepare(
                "SELECT BOOKING_ID FROM BOOKING
                 WHERE PET_ID = :pid
                 ORDER BY BOOKING_ID DESC
                 FETCH FIRST 1 ROWS ONLY"
            );
            $bk_stmt->execute(['pid' => $pet_id]);
            $latestBooking = $bk_stmt->fetch(PDO::FETCH_ASSOC);

            if ($latestBooking) {
                $pdo->prepare(
                    "UPDATE BOOKING
                     SET CONSENT_FORM_SIGNED = :consent,
                         NEXGARD_VERIFIED    = :nexgard,
                         OCULAR_EXAM_PASSED  = :ocular,
                         VETCARD_VERIFIED    = :vetcard
                     WHERE BOOKING_ID = :bid"
                )->execute([
                    'consent' => $consent,
                    'nexgard' => $nexgard,
                    'ocular'  => $ocular,
                    'vetcard' => $vetcard,
                    'bid'     => (int)$latestBooking['BOOKING_ID'],
                ]);
            }
            // If no booking exists for this pet yet, the checkbox values are noted
            // in the UI only — they will be set when the first booking is created.

            // Save any newly uploaded documents
            if (
                isset($_FILES['new_document']) &&
                !empty($_FILES['new_document']['name'][0])
            ) {
                $filesArr = $_FILES['new_document'];
                if (!is_array($filesArr['name'])) {
                    $filesArr['name']     = [$filesArr['name']];
                    $filesArr['tmp_name'] = [$filesArr['tmp_name']];
                    $filesArr['error']    = [$filesArr['error']];
                    $filesArr['size']     = [$filesArr['size']];
                    $filesArr['type']     = [$filesArr['type']];
                }
                saveDocuments($pdo, $pet_id, $filesArr, 'Pet Document');
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Owner and pet information updated successfully.']);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Update failed: ' . $e->getMessage()]);
            exit();
        }
    }

    // =========================================================================
    // DEACTIVATE OWNER (admin only soft delete)
    // =========================================================================
    if ($action === 'delete_owner') {
        header('Content-Type: application/json');

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
            $pdo->prepare("UPDATE PET SET STATUS = 'Inactive' WHERE OWNER_ID = :owner_id")->execute(['owner_id' => $owner_id]);
            $pdo->prepare("UPDATE OWNER SET STATUS = 'Inactive' WHERE OWNER_ID = :owner_id")->execute(['owner_id' => $owner_id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Owner and pet profiles have been securely deactivated.']);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Deactivation failed: ' . $e->getMessage()]);
            exit();
        }
    }

    // =========================================================================
    // REACTIVATE OWNER (admin only)
    // =========================================================================
    if ($action === 'reactivate_owner') {
        header('Content-Type: application/json');

        if (!isset($_SESSION['role']) || strtolower(trim($_SESSION['role'])) !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized Access: Only Administrators can reactivate profiles.']);
            exit();
        }

        $owner_id = filter_input(INPUT_POST, 'owner_id', FILTER_VALIDATE_INT);
        if (!$owner_id) {
            echo json_encode(['success' => false, 'message' => 'Invalid owner ID']);
            exit();
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE PET SET STATUS = 'Active' WHERE OWNER_ID = :owner_id")->execute(['owner_id' => $owner_id]);
            $pdo->prepare("UPDATE OWNER SET STATUS = 'Active' WHERE OWNER_ID = :owner_id")->execute(['owner_id' => $owner_id]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Owner and pet profiles have been reactivated.']);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Reactivation failed: ' . $e->getMessage()]);
            exit();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGE LOAD — Search / Filter
// ─────────────────────────────────────────────────────────────────────────────
$search       = $_GET['search']        ?? '';
$statusFilter = $_GET['status_filter'] ?? 'active';
$isAdmin      = isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin';

if (!$isAdmin) $statusFilter = 'active';

$statusCondition = ($statusFilter === 'inactive')
    ? "STATUS = 'Inactive'"
    : "(STATUS = 'Active' OR STATUS IS NULL)";

$queryStr = "SELECT OWNER_ID, FIRST_NAME, LAST_NAME, CONTACT_NUMBER, STATUS FROM OWNER WHERE $statusCondition";
$params   = [];

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
$categories    = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

$role = $_SESSION['role'] ?? 'Staff';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Profiles — Radog's Kennel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        :root {
            --orange:      #FA8112;
            --orange-dk:   #d96a08;
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

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', sans-serif; background: var(--beige); color: var(--black); min-height: 100vh; display: flex; }
        h1, h2, h3, h4, h5 { font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.05em; }

        /* ── SIDEBAR ── */
        .sidebar {
            width: 272px; flex-shrink: 0;
            background-color: var(--black);
            background-image: repeating-linear-gradient(
                -55deg, transparent, transparent 18px,
                rgba(250,129,18,0.04) 18px, rgba(250,129,18,0.04) 19px
            );
            display: flex; flex-direction: column;
            padding: 28px 20px;
            position: sticky; top: 0; height: 100vh; overflow-y: auto;
        }
        .sidebar-brand {
            display: flex; align-items: center; gap: 14px;
            padding-bottom: 24px;
            border-bottom: 1px solid rgba(250,129,18,0.15);
            margin-bottom: 20px;
            animation: fadeUp 0.6s cubic-bezier(0.16,1,0.3,1) both;
        }
        .sidebar-logo img { width: 52px; height: 52px; object-fit: contain; filter: drop-shadow(0 0 12px rgba(250,129,18,0.5)); }
        .sidebar-wordmark-top { font-family: 'Bebas Neue', sans-serif; font-size: 1.5rem; color: var(--orange); text-shadow: 0 0 18px rgba(250,129,18,0.45); line-height: 1; }
        .sidebar-wordmark-sub { font-size: 0.68rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--gold); opacity: 0.8; margin-top: 3px; }
        .sidebar-user { display: flex; align-items: center; gap: 12px; background: rgba(250,129,18,0.1); border: 1px solid rgba(250,129,18,0.18); border-radius: 12px; padding: 12px 14px; margin-bottom: 28px; animation: fadeUp 0.65s cubic-bezier(0.16,1,0.3,1) 0.05s both; }
        .sidebar-avatar { width: 34px; height: 34px; border-radius: 50%; background: var(--orange); display: flex; align-items: center; justify-content: center; font-family: 'Bebas Neue', sans-serif; font-size: 1rem; color: var(--white); flex-shrink: 0; }
        .sidebar-user-name { font-size: 0.88rem; font-weight: 600; color: var(--white); }
        .sidebar-user-role { font-size: 0.72rem; color: var(--orange); letter-spacing: 0.06em; text-transform: uppercase; }
        .nav-section-label { font-size: 0.68rem; font-weight: 600; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(245,231,198,0.4); padding: 0 4px; margin-bottom: 8px; }
        .nav-list { display: flex; flex-direction: column; gap: 4px; flex-grow: 1; animation: fadeUp 0.7s cubic-bezier(0.16,1,0.3,1) 0.1s both; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 12px; color: rgba(245,231,198,0.7); font-size: 0.92rem; text-decoration: none; transition: background 0.18s, color 0.18s; }
        .nav-link svg { width: 17px; height: 17px; opacity: 0.8; flex-shrink: 0; }
        .nav-link:hover { background: rgba(250,129,18,0.1); color: var(--white); }
        .nav-link.active { background: var(--orange); color: var(--white); font-weight: 600; }
        .nav-link.active svg { opacity: 1; }
        .sidebar-footer { margin-top: auto; padding-top: 20px; }
        .logout-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 16px; border-radius: 12px; background: transparent; border: 1.5px solid rgba(245,231,198,0.15); color: rgba(245,231,198,0.7); font-size: 0.9rem; text-decoration: none; transition: background 0.18s, color 0.18s, border-color 0.18s; }
        .logout-btn:hover { background: rgba(250,129,18,0.12); border-color: var(--orange); color: var(--white); }
        .logout-btn svg { width: 16px; height: 16px; }

        /* ── MAIN CONTENT ── */
        .main-content { flex-grow: 1; padding: 40px 44px; overflow-y: auto; animation: fadeUp 0.8s cubic-bezier(0.16,1,0.3,1) 0.1s both; }
        .page-eyebrow { font-size: 0.75rem; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: var(--orange); display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .page-eyebrow::before { content: ''; display: block; width: 20px; height: 2px; background: var(--orange); border-radius: 99px; }
        .page-title { font-size: 2.4rem; color: var(--black); line-height: 1; margin-bottom: 6px; }
        .page-subtitle { font-size: 0.95rem; color: rgba(34,34,34,0.55); margin-bottom: 32px; }

        /* ── TOOLBAR ── */
        .toolbar { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; flex-wrap: wrap; }
        .search-wrap { position: relative; flex-grow: 1; max-width: 420px; }
        .search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); width: 16px; height: 16px; color: rgba(34,34,34,0.35); pointer-events: none; }
        .search-wrap input { width: 100%; padding: 13px 16px 13px 42px; border: 1.5px solid #e2d9ce; border-radius: 12px; background: var(--white); color: var(--black); font-family: 'DM Sans', sans-serif; font-size: 0.95rem; transition: border-color 0.2s, box-shadow 0.2s; }
        .search-wrap input:focus { border-color: var(--orange); box-shadow: 0 0 0 3px rgba(250,129,18,0.15); outline: none; }

        /* ── STATUS FILTER TABS ── */
        .status-tabs { display: flex; align-items: center; background: var(--white); border: var(--border-soft); border-radius: 12px; padding: 4px; gap: 2px; }
        .status-tab { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 9px; font-family: 'Bebas Neue', sans-serif; font-size: 0.88rem; letter-spacing: 0.1em; text-decoration: none; color: rgba(34,34,34,0.5); transition: background 0.18s, color 0.18s; border: none; cursor: pointer; background: transparent; white-space: nowrap; }
        .status-tab svg { width: 14px; height: 14px; }
        .status-tab:hover { background: rgba(250,129,18,0.08); color: var(--orange); }
        .status-tab.active-tab { background: var(--black); color: var(--white); }
        .status-tab.active-tab:hover { background: var(--orange); }
        .status-tab.inactive-tab.active-tab { background: #be123c; color: var(--white); }
        .status-tab.inactive-tab.active-tab:hover { background: #9f1239; }

        /* ── BUTTONS ── */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 22px; border: none; border-radius: 12px; font-family: 'Bebas Neue', sans-serif; font-size: 0.95rem; letter-spacing: 0.12em; cursor: pointer; text-decoration: none; transition: background 0.18s, transform 0.15s, box-shadow 0.15s; white-space: nowrap; }
        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 16px; height: 16px; }
        .btn-primary { background: var(--black); color: var(--white); }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }
        .btn-danger  { background: var(--orange); color: var(--white); }
        .btn-danger:hover  { background: var(--orange-dk); box-shadow: 0 6px 20px rgba(250,129,18,0.35); }
        .btn-ghost   { background: transparent; color: var(--black); border: 1.5px solid rgba(34,34,34,0.18); }
        .btn-ghost:hover   { background: rgba(34,34,34,0.04); border-color: var(--orange); }
        .btn-deactivate { background: #fff1f2; color: #be123c; border: 1.5px solid #fecdd3; }
        .btn-deactivate:hover { background: #ffe4e8; border-color: #f43f5e; }
        .btn-reactivate { background: #f0fdf4; color: #166534; border: 1.5px solid #bbf7d0; }
        .btn-reactivate:hover { background: #dcfce7; border-color: #4ade80; }
        .btn-sm { padding: 8px 14px; font-size: 0.82rem; }

        /* ── PANEL ── */
        .panel { background: var(--white); border: var(--border-soft); border-radius: 20px; box-shadow: var(--shadow-card); overflow: hidden; }

        /* ── TABLE ── */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table thead tr { background: rgba(250,129,18,0.04); }
        .data-table th { padding: 12px 20px; font-size: 0.72rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: rgba(34,34,34,0.45); text-align: left; border-bottom: 1px solid rgba(34,34,34,0.06); }
        .data-table tbody tr { border-bottom: 1px solid rgba(34,34,34,0.05); transition: background 0.15s; }
        .data-table tbody tr:hover { background: rgba(250,129,18,0.03); }
        .data-table td { padding: 16px 20px; font-size: 0.92rem; vertical-align: middle; }
        .data-table td:last-child { text-align: right; }
        .owner-id-badge { font-family: 'Bebas Neue', sans-serif; font-size: 0.95rem; color: var(--orange); letter-spacing: 0.08em; }
        .data-table tbody tr.row-inactive { opacity: 0.6; }
        .data-table tbody tr.row-inactive:hover { background: rgba(190,18,60,0.03); }
        .status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 99px; font-size: 0.78rem; font-weight: 600; }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; }
        .badge-active   { background: #f0fdf4; color: #166534; }
        .badge-active .status-dot   { background: #22c55e; }
        .badge-inactive { background: #fef2f2; color: #991b1b; }
        .badge-inactive .status-dot { background: #ef4444; }
        .action-group { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
        .empty-state { padding: 64px 24px; text-align: center; color: rgba(34,34,34,0.4); }
        .empty-state svg { width: 48px; height: 48px; margin-bottom: 16px; opacity: 0.3; }
        .empty-state p { font-size: 0.95rem; }

        /* ── FORM FIELDS ── */
        .field-label { display: block; font-size: 0.75rem; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: #4a3f33; margin-bottom: 8px; }
        .field-group { margin-bottom: 20px; }
        input[type="text"], input[type="tel"], input[type="number"], select, textarea { width: 100%; padding: 13px 16px; border: 1.5px solid #e2d9ce; border-radius: 12px; background: var(--beige); color: var(--black); font-family: 'DM Sans', sans-serif; font-size: 0.95rem; transition: border-color 0.2s, box-shadow 0.2s; appearance: none; }
        input:focus, select:focus, textarea:focus { border-color: var(--orange); box-shadow: 0 0 0 3px rgba(250,129,18,0.15); outline: none; background: var(--white); }
        input:disabled, select:disabled { opacity: 0.5; cursor: not-allowed; background: rgba(34,34,34,0.04); }
        textarea { resize: vertical; min-height: 90px; }

        /* ── FILE UPLOAD ── */
        .file-upload-wrap { position: relative; }
        .file-upload-label { display: flex; align-items: center; gap: 10px; padding: 13px 16px; border: 1.5px dashed #c9bfb2; border-radius: 12px; background: var(--beige); cursor: pointer; font-size: 0.9rem; color: rgba(34,34,34,0.55); transition: border-color 0.2s, background 0.2s, color 0.2s; }
        .file-upload-label:hover { border-color: var(--orange); color: var(--orange); background: rgba(250,129,18,0.04); }
        .file-upload-label svg { width: 16px; height: 16px; flex-shrink: 0; }
        .file-upload-label span.file-name { font-style: italic; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 340px; }
        input[type="file"] { display: none; }
        .file-hint { font-size: 0.75rem; color: rgba(34,34,34,0.4); margin-top: 6px; display: block; }

        /* ── CHECKBOXES ── */
        .check-group { display: flex; flex-direction: column; gap: 10px; }
        .check-label { display: flex; align-items: center; gap: 12px; cursor: pointer; font-size: 0.92rem; color: var(--black); padding: 12px 14px; border: 1.5px solid #e2d9ce; border-radius: 12px; background: var(--beige); transition: border-color 0.2s, background 0.2s; user-select: none; }
        .check-label:hover { border-color: var(--orange); background: rgba(250,129,18,0.04); }
        .check-label input[type="checkbox"] { width: 17px; height: 17px; flex-shrink: 0; accent-color: var(--orange); cursor: pointer; padding: 0; border-radius: 4px; }
        .check-label input[type="checkbox"]:focus { outline: none; box-shadow: none; }
        .check-label.checked { border-color: var(--orange); background: rgba(250,129,18,0.08); }
        .check-icon { width: 32px; height: 32px; border-radius: 8px; background: rgba(250,129,18,0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .check-icon svg { width: 15px; height: 15px; color: var(--orange); }
        .check-text-wrap { flex-grow: 1; }
        .check-title { font-size: 0.9rem; font-weight: 600; color: var(--black); line-height: 1.2; }
        .check-desc  { font-size: 0.78rem; color: rgba(34,34,34,0.45); margin-top: 2px; }

        /* ── ALERTS ── */
        .alert { display: flex; align-items: flex-start; gap: 10px; padding: 14px 18px; border-radius: 12px; font-size: 0.9rem; margin-bottom: 0; }
        .alert svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .alert-error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }

        /* ── MODALS ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(34,34,34,0.55); backdrop-filter: blur(3px); z-index: 100; align-items: center; justify-content: center; padding: 20px; }
        .modal-overlay.open { display: flex; }
        .modal-box { background: var(--white); border-radius: 20px; width: min(100%, 560px); box-shadow: 0 32px 80px rgba(15,23,42,0.18); overflow: hidden; animation: fadeUp 0.35s cubic-bezier(0.16,1,0.3,1) both; max-height: 92vh; display: flex; flex-direction: column; }
        .modal-box-lg { width: min(100%, 680px); }
        .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 22px 28px; flex-shrink: 0; background-color: var(--black); background-image: repeating-linear-gradient(-55deg, transparent, transparent 18px, rgba(250,129,18,0.05) 18px, rgba(250,129,18,0.05) 19px); }
        .modal-title { font-family: 'Bebas Neue', sans-serif; font-size: 1.3rem; color: var(--white); letter-spacing: 0.06em; }
        .modal-close { background: none; border: none; cursor: pointer; color: rgba(245,231,198,0.6); width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: background 0.18s, color 0.18s; }
        .modal-close:hover { background: rgba(250,129,18,0.2); color: var(--white); }
        .modal-close svg { width: 18px; height: 18px; }
        .modal-body { padding: 28px; overflow-y: auto; flex-grow: 1; }
        .modal-foot { display: flex; align-items: center; justify-content: flex-end; gap: 12px; padding: 20px 28px; border-top: 1px solid rgba(34,34,34,0.07); flex-shrink: 0; }
        .modal-section-label { font-size: 0.72rem; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: var(--orange); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .modal-section-label::after { content: ''; flex-grow: 1; height: 1px; background: rgba(250,129,18,0.2); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .col-full { grid-column: 1 / -1; }
        .radio-group { display: flex; gap: 16px; }
        .radio-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.92rem; color: var(--black); }
        .radio-label input[type="radio"] { width: auto; accent-color: var(--orange); }

        /* Pet entry card */
        .pet-entry { background: var(--beige); border: 1.5px solid #e2d9ce; border-radius: 14px; padding: 20px; margin-bottom: 16px; position: relative; }
        .pet-entry-header { font-family: 'Bebas Neue', sans-serif; font-size: 1rem; letter-spacing: 0.06em; color: var(--orange); margin-bottom: 14px; }
        .btn-remove-pet { position: absolute; top: 14px; right: 14px; background: #fff1f2; border: 1px solid #fecdd3; color: #be123c; border-radius: 8px; padding: 5px 10px; cursor: pointer; font-size: 0.78rem; display: flex; align-items: center; gap: 5px; transition: background 0.15s; }
        .btn-remove-pet:hover { background: #ffe4e8; }
        .btn-remove-pet svg { width: 13px; height: 13px; }
        .btn-add-pet { width: 100%; padding: 12px; border: 1.5px dashed #c9bfb2; border-radius: 12px; background: transparent; color: rgba(34,34,34,0.5); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: border-color 0.18s, color 0.18s, background 0.18s; }
        .btn-add-pet:hover { border-color: var(--orange); color: var(--orange); background: rgba(250,129,18,0.04); }
        .btn-add-pet svg { width: 15px; height: 15px; }
        .alert-wrap { margin-top: 16px; }

        /* Doc list in edit modal */
        .doc-list { display: flex; flex-direction: column; gap: 10px; }
        .doc-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: var(--beige); border: 1.5px solid #e2d9ce; border-radius: 12px; transition: border-color 0.18s; }
        .doc-item:hover { border-color: rgba(250,129,18,0.3); }
        .doc-icon { width: 34px; height: 34px; border-radius: 8px; background: rgba(250,129,18,0.1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .doc-icon svg { width: 16px; height: 16px; color: var(--orange); }
        .doc-info { flex-grow: 1; min-width: 0; }
        .doc-type { font-size: 0.88rem; font-weight: 600; color: var(--black); }
        .doc-meta { font-size: 0.75rem; color: rgba(34,34,34,0.45); margin-top: 2px; }
        .doc-badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 99px; font-size: 0.72rem; font-weight: 600; white-space: nowrap; flex-shrink: 0; }
        .doc-badge-approved  { background: #f0fdf4; color: #166534; }
        .doc-badge-pending   { background: #fffbeb; color: #92400e; }
        .doc-badge-rejected  { background: #fef2f2; color: #991b1b; }
        .doc-badge-none      { background: rgba(34,34,34,0.06); color: rgba(34,34,34,0.45); }
        .doc-link { display: inline-flex; align-items: center; gap: 5px; font-size: 0.78rem; color: var(--orange); text-decoration: none; padding: 5px 10px; border-radius: 8px; border: 1px solid rgba(250,129,18,0.25); background: rgba(250,129,18,0.05); transition: background 0.15s; flex-shrink: 0; }
        .doc-link:hover { background: rgba(250,129,18,0.12); }
        .doc-link svg { width: 12px; height: 12px; }
        .doc-empty { padding: 20px; text-align: center; color: rgba(34,34,34,0.35); font-size: 0.88rem; background: var(--beige); border-radius: 12px; border: 1.5px dashed #e2d9ce; }
        .doc-loading { padding: 20px; text-align: center; color: rgba(34,34,34,0.4); font-size: 0.88rem; }

        /* ── NOTE BANNER ── */
        .info-note { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-radius: 12px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 0.82rem; margin-bottom: 16px; }
        .info-note svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; }

        /* ── ANIMATIONS ── */
        @keyframes fadeUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        /* ── RESPONSIVE ── */
        @media (max-width: 900px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; height: auto; position: static; }
            .main-content { padding: 24px 20px; }
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .search-wrap { max-width: 100%; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-logo">
            <img src="../img/radog_logocutie.png" alt="Radog's Kennel">
        </div>
        <div>
            <div class="sidebar-wordmark-top">Radog's Kennel</div>
            <div class="sidebar-wordmark-sub">Pet Hotel Management</div>
        </div>
    </div>

    <div class="sidebar-user">
        <div class="sidebar-avatar">
            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
        </div>
        <div>
            <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></div>
            <div class="sidebar-user-role"><?php echo htmlspecialchars($role); ?></div>
        </div>
    </div>

    <div class="nav-section-label">Navigation</div>
    <nav class="nav-list">
        <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>
        <a href="admin_dashboard.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <?php else: ?>
        <a href="staff_dashboard.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <?php endif; ?>

        <a href="encode_reservation.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"/></svg>
            Schedule
        </a>

        <a href="calendar-unified.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Calendar
        </a>

        <a href="owner.php" class="nav-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Owners
        </a>

        <a href="pets.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5.172C10 3.782 8.423 2.679 6.5 3c-2.823.47-4.113 6.006-4 7 .08.703 1.725 1.722 3.656 1 1.261-.472 1.96-1.45 2.344-2.5"/><path d="M14.267 5.172c0-1.39 1.577-2.493 3.5-2.172 2.823.47 4.113 6.006 4 7-.08.703-1.725 1.722-3.656 1-1.261-.472-1.855-1.45-2.239-2.5"/><path d="M8 14v.5"/><path d="M16 14v.5"/><path d="M11.25 16.25h1.5L12 17l-.75-.75z"/><path d="M4.42 11.247A13.152 13.152 0 0 0 4 14.556C4 18.728 7.582 21 12 21s8-2.272 8-6.444c0-1.061-.162-2.2-.493-3.309m-9.243-6.082A8.801 8.801 0 0 1 12 5c.78 0 1.5.108 2.161.306"/></svg>
            Pets
        </a>

        <?php if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === 'admin'): ?>
        <a href="user_management.php" class="nav-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            User Management
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- ══════════════════════════════════════════════════════════
     MAIN CONTENT
════════════════════════════════════════════════════════════ -->
<main class="main-content">

    <div class="page-eyebrow">Client Records</div>
    <h1 class="page-title">Owner Profiles</h1>
    <p class="page-subtitle">Manage registered pet owners and their associated animals.</p>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <form action="owner.php" method="GET" style="display:contents;">
                <?php if ($isAdmin): ?>
                    <input type="hidden" name="status_filter" value="<?php echo htmlspecialchars($statusFilter); ?>">
                <?php endif; ?>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by name or contact number…" onchange="this.form.submit()">
            </form>
        </div>

        <?php if ($isAdmin): ?>
        <div class="status-tabs">
            <a href="owner.php?status_filter=active<?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>"
               class="status-tab <?php echo $statusFilter === 'active' ? 'active-tab' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Active
            </a>
            <a href="owner.php?status_filter=inactive<?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?>"
               class="status-tab inactive-tab <?php echo $statusFilter === 'inactive' ? 'active-tab' : ''; ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                Archived
            </a>
        </div>
        <?php endif; ?>

        <?php if (!$isAdmin || $statusFilter === 'active'): ?>
        <button type="button" class="btn btn-primary" onclick="openModal('createOwnerModal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
            Register Walk-in Owner
        </button>
        <?php endif; ?>
    </div>

    <!-- Table Panel -->
    <div class="panel">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Owner ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Contact</th>
                    <?php if ($isAdmin): ?><th>Status</th><?php endif; ?>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)):
                ?>
                <tr>
                    <td colspan="<?php echo $isAdmin ? 6 : 5; ?>">
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <p><?php echo $statusFilter === 'inactive' ? 'No archived owner records found.' : 'No owner records found.'; ?></p>
                        </div>
                    </td>
                </tr>
                <?php else: foreach ($rows as $row):
                    $isInactiveRow = strtolower($row['STATUS'] ?? 'active') === 'inactive';
                ?>
                <tr class="<?php echo $isInactiveRow ? 'row-inactive' : ''; ?>">
                    <td><span class="owner-id-badge">OWN-<?php echo str_pad($row['OWNER_ID'], 4, '0', STR_PAD_LEFT); ?></span></td>
                    <td><?php echo htmlspecialchars($row['FIRST_NAME']); ?></td>
                    <td><?php echo htmlspecialchars($row['LAST_NAME']); ?></td>
                    <td><?php echo htmlspecialchars($row['CONTACT_NUMBER']); ?></td>
                    <?php if ($isAdmin): ?>
                    <td>
                        <?php if ($isInactiveRow): ?>
                            <span class="status-badge badge-inactive"><span class="status-dot"></span>Archived</span>
                        <?php else: ?>
                            <span class="status-badge badge-active"><span class="status-dot"></span>Active</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <div class="action-group">
                            <a href="owner_profile.php?id=<?php echo $row['OWNER_ID']; ?>" class="btn btn-ghost btn-sm">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                View
                            </a>
                            <?php if (!$isInactiveRow): ?>
                            <button type="button" class="btn btn-primary btn-sm edit-owner-btn"
                                    data-owner-id="<?php echo $row['OWNER_ID']; ?>"
                                    onclick="openModal('editOwnerModal')">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Edit
                            </button>
                            <?php endif; ?>
                            <?php if ($isAdmin): ?>
                                <?php if ($isInactiveRow): ?>
                                <button type="button" class="btn btn-reactivate btn-sm reactivate-owner-btn"
                                        data-owner-id="<?php echo $row['OWNER_ID']; ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                                    Reactivate
                                </button>
                                <?php else: ?>
                                <button type="button" class="btn btn-deactivate btn-sm delete-owner-btn"
                                        data-owner-id="<?php echo $row['OWNER_ID']; ?>">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                                    Deactivate
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- ══════════════════════════════════════════════════════════
     CREATE OWNER MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="createOwnerModal">
    <div class="modal-box modal-box-lg">
        <div class="modal-head">
            <span class="modal-title">Register Owner &amp; Pet</span>
            <button class="modal-close" onclick="closeModal('createOwnerModal')" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="createOwnerForm" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_owner_pet">

                <div class="modal-section-label">Owner Information</div>

                <div class="grid-2" style="margin-bottom:16px;">
                    <div class="field-group">
                        <label class="field-label">First Name</label>
                        <input type="text" name="first_name" placeholder="e.g. Maria" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Last Name</label>
                        <input type="text" name="last_name" placeholder="e.g. Santos" required>
                    </div>
                </div>
                <div class="field-group" style="margin-bottom:24px;">
                    <label class="field-label">Contact Number</label>
                    <input type="tel" name="contact" placeholder="e.g. 09123456789" required>
                </div>

                <div class="modal-section-label">Pet Information</div>

                <div id="modalPetContainer">
                    <!-- Pet entries are injected by buildPetEntry() below -->
                </div>

                <button type="button" id="modalAddPetBtn" class="btn-add-pet" style="margin-bottom:16px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add Another Pet
                </button>

                <div id="createModalAlert" class="alert-wrap" style="display:none;"></div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" onclick="closeModal('createOwnerModal')">Cancel</button>
            <button type="button" class="btn btn-primary" id="submitNewOwnerBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
                Register Now
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     EDIT OWNER MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editOwnerModal">
    <div class="modal-box modal-box-lg">
        <div class="modal-head">
            <span class="modal-title">Edit Owner &amp; Pet Info</span>
            <button class="modal-close" onclick="closeModal('editOwnerModal')" aria-label="Close">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="editOwnerForm" enctype="multipart/form-data">
                <input type="hidden" id="modalOwnerId" name="owner_id">
                <input type="hidden" id="modalPetId"   name="pet_id">

                <div class="modal-section-label">Owner Information</div>

                <div class="grid-3" style="margin-bottom:16px;">
                    <div class="field-group">
                        <label class="field-label">Owner ID</label>
                        <input type="text" id="modalOwnerCode" disabled>
                    </div>
                    <div class="field-group">
                        <label class="field-label">First Name</label>
                        <input type="text" id="modalFirstName" name="first_name" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Last Name</label>
                        <input type="text" id="modalLastName" name="last_name" required>
                    </div>
                </div>
                <div class="field-group" style="margin-bottom:24px;">
                    <label class="field-label">Contact Number</label>
                    <input type="tel" id="modalContactNumber" name="contact_number" required>
                </div>

                <div class="modal-section-label">Pet Information</div>

                <div class="field-group" style="margin-bottom:16px;">
                    <label class="field-label">Select Pet</label>
                    <select id="modalPetSelect" name="pet_id" required></select>
                </div>

                <div class="grid-3" style="margin-bottom:16px;">
                    <div class="field-group">
                        <label class="field-label">Pet Name</label>
                        <input type="text" id="modalPetName" name="pet_name" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Category</label>
                        <select id="modalPetCategory" name="category_id" required>
                            <option value="">-- Select --</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['CATEGORY_ID']; ?>"><?php echo htmlspecialchars($category['CATEGORY_NAME']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Weight (kg)</label>
                        <input type="number" step="0.1" id="modalPetWeight" name="weight" required>
                    </div>
                </div>

                <div class="grid-3" style="margin-bottom:24px;">
                    <div class="field-group">
                        <label class="field-label">Sex</label>
                        <select id="modalPetSex" name="sex" required>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Feeding Time</label>
                        <input type="text" id="modalFeedingTime" name="feeding_time" placeholder="e.g. 08:00" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label">Portion</label>
                        <input type="text" id="modalPortion" name="portion" placeholder="e.g. 1 cup" required>
                    </div>
                </div>

                <!-- ── Uploaded Documents ── -->
                <div class="modal-section-label">Uploaded Documents</div>
                <div id="editDocumentsList">
                    <div class="doc-loading">Select an owner above to load documents.</div>
                </div>

                <div class="field-group" style="margin-top:16px;">
                    <label class="field-label">Upload Additional Document</label>
                    <div class="file-upload-wrap">
                        <label class="file-upload-label" id="editFileLabel" for="editPetDoc">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            <span class="file-name">Attach new document&hellip;</span>
                        </label>
                        <input type="file" name="new_document[]" id="editPetDoc"
                               accept=".pdf,.jpg,.jpeg,.png" multiple
                               onchange="updateFileLabel(this, 'editFileLabel')">
                    </div>
                    <span class="file-hint">Accepted: PDF, JPG, PNG &mdash; saved as <strong>Pending</strong> verification.</span>
                </div>

                <!-- ── Check-In Verifications (persisted to most recent BOOKING row) ── -->
                <div class="modal-section-label" style="margin-top:8px;">Check-In Verifications</div>

                <div class="info-note">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    These values reflect the pet's <strong>most recent booking</strong> record. If no booking exists yet, changes here will be applied when the first booking is created.
                </div>

                <div class="check-group" style="margin-bottom:16px;">
                    <label class="check-label" id="editCheckConsent">
                        <input type="checkbox" id="modalConsentForm" name="consent_form" value="Yes"
                               onchange="syncCheckLabel(this)">
                        <span class="check-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </span>
                        <span class="check-text-wrap">
                            <span class="check-title">Consent Form Signed</span>
                            <span class="check-desc">Owner has signed the boarding consent/waiver form.</span>
                        </span>
                    </label>

                    <label class="check-label" id="editCheckNexgard">
                        <input type="checkbox" id="modalNexgard" name="nexgard" value="Yes"
                               onchange="syncCheckLabel(this)">
                        <span class="check-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </span>
                        <span class="check-text-wrap">
                            <span class="check-title">NexGard Verified</span>
                            <span class="check-desc">NexGard anti-tick/flea treatment administered or confirmed.</span>
                        </span>
                    </label>

                    <label class="check-label" id="editCheckOcular">
                        <input type="checkbox" id="modalOcularExam" name="ocular_exam" value="Yes"
                               onchange="syncCheckLabel(this)">
                        <span class="check-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </span>
                        <span class="check-text-wrap">
                            <span class="check-title">Ocular Exam Passed</span>
                            <span class="check-desc">Pet passed visual health inspection upon arrival.</span>
                        </span>
                    </label>

                    <label class="check-label" id="editCheckVetcard">
                        <input type="checkbox" id="modalVetcard" name="vetcard" value="Yes"
                               onchange="syncCheckLabel(this)">
                        <span class="check-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                        </span>
                        <span class="check-text-wrap">
                            <span class="check-title">Vet Card Verified</span>
                            <span class="check-desc">Vaccination records checked and confirmed.</span>
                        </span>
                    </label>
                </div>

                <div id="modalAlert" class="alert-wrap" style="display:none;"></div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn btn-ghost" onclick="closeModal('editOwnerModal')">Cancel</button>
            <button type="button" class="btn btn-primary" id="saveOwnerPetBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Changes
            </button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
// ── PHP data passed to JS ──────────────────────────────────
const PHP_CATEGORIES = <?php echo json_encode(array_values($categories)); ?>;

// ── Modal helpers ──────────────────────────────────────────
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) { if (e.target === this) closeModal(this.id); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(function(m) { closeModal(m.id); });
    }
});

// ── Alert helper ───────────────────────────────────────────
function showAlert(wrapId, type, message) {
    const wrap = document.getElementById(wrapId);
    const icon = type === 'success'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    wrap.innerHTML = '<div class="alert alert-' + (type === 'success' ? 'success' : 'error') + '">' + icon + message + '</div>';
    wrap.style.display = 'block';
}

// ── File label updater ─────────────────────────────────────
function updateFileLabel(input, labelId) {
    const label = document.getElementById(labelId);
    if (!label) return;
    const span = label.querySelector('.file-name');
    if (input.files && input.files.length > 0) {
        span.textContent = Array.from(input.files).map(f => f.name).join(', ');
        label.style.borderColor = 'var(--orange)';
        label.style.color = 'var(--orange)';
    } else {
        span.textContent = 'Upload Vetcard, Waiver, or Consent Form\u2026';
        label.style.borderColor = '';
        label.style.color = '';
    }
}

// ── Checkbox visual sync ───────────────────────────────────
// Called via onchange on each checkbox.  Keeps the .checked CSS class
// on the parent .check-label in sync with the checkbox state.
function syncCheckLabel(checkbox) {
    const label = checkbox.closest('.check-label');
    if (!label) return;
    if (checkbox.checked) {
        label.classList.add('checked');
    } else {
        label.classList.remove('checked');
    }
}

// ── Programmatically set a checkbox + sync its label ──────
function setCheckbox(checkboxEl, isChecked) {
    checkboxEl.checked = isChecked;
    syncCheckLabel(checkboxEl);
}

// ── Build a pet-entry card HTML string (used for create modal) ──
// index  : integer position (0, 1, 2…)
// isFirst: boolean — first card cannot be removed
function buildPetEntry(index, isFirst) {
    const catOptions = PHP_CATEGORIES.map(function(c) {
        return '<option value="' + c.CATEGORY_ID + '">' + escHtml(c.CATEGORY_NAME) + '</option>';
    }).join('');

    const removeBtn = isFirst ? '' : `
        <button type="button" class="btn-remove-pet" onclick="this.closest('.pet-entry').remove()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            Remove
        </button>`;

    return `
    <div class="pet-entry">
        <div class="pet-entry-header">Pet #${index + 1}</div>
        ${removeBtn}

        <div class="field-group">
            <label class="field-label">Pet Name</label>
            <input type="text" name="pets[${index}][pet_name]" placeholder="e.g. Buddy" required>
        </div>

        <div class="grid-2" style="margin-bottom:16px;">
            <div class="field-group">
                <label class="field-label">Species / Category</label>
                <select name="pets[${index}][category_id]" required>
                    <option value="">Select category</option>
                    ${catOptions}
                </select>
            </div>
            <div class="field-group">
                <label class="field-label">Weight (kg)</label>
                <input type="number" step="0.1" name="pets[${index}][pet_weight]" placeholder="e.g. 12.5" required>
            </div>
        </div>

        <div class="field-group" style="margin-bottom:16px;">
            <label class="field-label">Sex</label>
            <div class="radio-group">
                <label class="radio-label"><input type="radio" name="pets[${index}][sex]" value="Male" checked> Male</label>
                <label class="radio-label"><input type="radio" name="pets[${index}][sex]" value="Female"> Female</label>
            </div>
        </div>

        <div class="grid-2" style="margin-bottom:16px;">
            <div class="field-group">
                <label class="field-label">Feeding Time</label>
                <input type="text" name="pets[${index}][feeding_time]" placeholder="e.g. BID / 08:00" required>
            </div>
            <div class="field-group">
                <label class="field-label">Portion</label>
                <input type="text" name="pets[${index}][portion]" placeholder="e.g. 1 cup" required>
            </div>
        </div>

        <div class="field-group">
            <label class="field-label">Attach Pet Documents</label>
            <div class="file-upload-wrap">
                <label class="file-upload-label" id="fileLabel_${index}" for="petDoc_${index}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="file-name">Upload Vetcard, Waiver, or Consent Form&hellip;</span>
                </label>
                <input type="file" name="pets[${index}][documents][]" id="petDoc_${index}"
                       accept=".pdf,.jpg,.jpeg,.png" multiple
                       onchange="updateFileLabel(this, 'fileLabel_${index}')">
            </div>
            <span class="file-hint">Accepted: PDF, JPG, PNG — you may select multiple files. Documents will be saved as <strong>Pending</strong> verification.</span>
        </div>

        <div class="field-group" style="margin-top:8px;margin-bottom:0;">
            <label class="field-label">Check-In Verifications</label>
            <div class="info-note" style="margin-bottom:10px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                These are noted for reference. They will be stored on the booking record when the first booking is created for this pet.
            </div>
            <div class="check-group">
                <label class="check-label">
                    <input type="checkbox" name="pets[${index}][consent_form]" value="Yes" onchange="syncCheckLabel(this)">
                    <span class="check-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                    <span class="check-text-wrap">
                        <span class="check-title">Consent Form Signed</span>
                        <span class="check-desc">Owner has signed the boarding consent/waiver form.</span>
                    </span>
                </label>
                <label class="check-label">
                    <input type="checkbox" name="pets[${index}][nexgard]" value="Yes" onchange="syncCheckLabel(this)">
                    <span class="check-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
                    <span class="check-text-wrap">
                        <span class="check-title">NexGard Verified</span>
                        <span class="check-desc">NexGard anti-tick/flea treatment administered or confirmed.</span>
                    </span>
                </label>
                <label class="check-label">
                    <input type="checkbox" name="pets[${index}][ocular_exam]" value="Yes" onchange="syncCheckLabel(this)">
                    <span class="check-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></span>
                    <span class="check-text-wrap">
                        <span class="check-title">Ocular Exam Passed</span>
                        <span class="check-desc">Pet passed visual health inspection upon arrival.</span>
                    </span>
                </label>
                <label class="check-label">
                    <input type="checkbox" name="pets[${index}][vetcard]" value="Yes" onchange="syncCheckLabel(this)">
                    <span class="check-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg></span>
                    <span class="check-text-wrap">
                        <span class="check-title">Vet Card Verified</span>
                        <span class="check-desc">Vaccination records checked and confirmed.</span>
                    </span>
                </label>
            </div>
            <span class="file-hint" style="margin-top:8px;">These will be saved on the booking record when the first booking is created for this pet.</span>
        </div>
    </div>`;
}

// Simple HTML escape helper used inside JS template literals
function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Render document list in edit modal ─────────────────────
function renderDocuments(documents, currentPetId) {
    const container = document.getElementById('editDocumentsList');
    const petDocs   = documents.filter(d => String(d.PET_ID) === String(currentPetId));

    if (petDocs.length === 0) {
        container.innerHTML = '<div class="doc-empty">No documents uploaded for this pet yet.</div>';
        return;
    }

    let html = '<div class="doc-list">';
    petDocs.forEach(function(doc) {
        const status      = doc.VERIFICATION_STATUS || 'none';
        const statusLower = status.toLowerCase();
        const validStatuses = ['approved', 'pending', 'rejected'];
        const badgeClass  = 'doc-badge-' + (validStatuses.includes(statusLower) ? statusLower : 'none');
        const statusLabel = status === 'none' ? 'Unverified' : status;
        const dotColor    = statusLower === 'approved' ? '#22c55e'
                          : statusLower === 'pending'  ? '#f59e0b'
                          : statusLower === 'rejected' ? '#ef4444'
                          : '#94a3b8';

        // Normalise filepath: ensure it starts with / and uses forward slashes
        let filepath = (doc.FILEPATH || '').replace(/\\/g, '/');
        if (filepath && !filepath.startsWith('/') && !filepath.startsWith('http')) {
            filepath = '/' + filepath;
        }
        if (!filepath) filepath = '#';

        const uploadDate   = doc.UPLOAD_DATE   || '—';
        const verifiedDate = doc.DATE_VERIFIED  ? ' &middot; Verified: ' + doc.DATE_VERIFIED : '';

        html += `<div class="doc-item">
            <div class="doc-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div class="doc-info">
                <div class="doc-type">${escHtml(doc.DOCUMENT_TYPE || 'Document')}</div>
                <div class="doc-meta">Uploaded: ${uploadDate}${verifiedDate}</div>
            </div>
            <span class="doc-badge ${badgeClass}">
                <svg style="width:7px;height:7px;fill:${dotColor};flex-shrink:0;" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                ${escHtml(statusLabel)}
            </span>
            <a href="${escHtml(filepath)}" target="_blank" rel="noopener noreferrer" class="doc-link">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                View
            </a>
        </div>`;
    });
    html += '</div>';
    container.innerHTML = html;
}

// ─────────────────────────────────────────────────────────────────────────────
// DOM READY
// ─────────────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const ownerSelect = document.getElementById('modalPetSelect');
    let ownerPets    = [];
    let allDocuments = [];

    // ── Seed the create modal with Pet #1 on load ──────────
    const petContainer = document.getElementById('modalPetContainer');
    petContainer.innerHTML = buildPetEntry(0, true);

    // ── "Add Another Pet" ──────────────────────────────────
    document.getElementById('modalAddPetBtn').addEventListener('click', function() {
        const currentCount = petContainer.querySelectorAll('.pet-entry').length;
        petContainer.insertAdjacentHTML('beforeend', buildPetEntry(currentCount, false));
    });

    // ── CREATE: submit via FormData ────────────────────────
    document.getElementById('submitNewOwnerBtn').addEventListener('click', function() {
        const form = document.getElementById('createOwnerForm');
        if (!form.checkValidity()) { form.reportValidity(); return; }

        const formData = new FormData(form);

        fetch('owner.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlert('createModalAlert', 'success', data.message);
                setTimeout(() => location.reload(), 1200);
            } else {
                showAlert('createModalAlert', 'error', data.message);
            }
        })
        .catch(error => {
            showAlert('createModalAlert', 'error', 'Registration failed. Please try again.');
            console.error(error);
        });
    });

    // ── Edit button wiring ─────────────────────────────────
    document.querySelectorAll('.edit-owner-btn').forEach(button => {
        button.addEventListener('click', function() {
            loadOwnerData(this.getAttribute('data-owner-id'));
        });
    });

    // ── Deactivate button wiring ───────────────────────────
    document.querySelectorAll('.delete-owner-btn').forEach(button => {
        button.addEventListener('click', function() {
            const ownerId = this.getAttribute('data-owner-id');
            if (!confirm('Are you sure you want to deactivate this owner and all their pet profiles? This will archive their records.')) return;
            const fd = new FormData();
            fd.append('action', 'delete_owner');
            fd.append('owner_id', ownerId);
            fetch('owner.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => { alert(data.message); if (data.success) location.reload(); })
            .catch(() => alert('An error occurred during deactivation. Please try again.'));
        });
    });

    // ── Reactivate button wiring ───────────────────────────
    document.querySelectorAll('.reactivate-owner-btn').forEach(button => {
        button.addEventListener('click', function() {
            const ownerId = this.getAttribute('data-owner-id');
            if (!confirm('Reactivate this owner and all their pet profiles?')) return;
            const fd = new FormData();
            fd.append('action', 'reactivate_owner');
            fd.append('owner_id', ownerId);
            fetch('owner.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => { alert(data.message); if (data.success) location.reload(); })
            .catch(() => alert('An error occurred. Please try again.'));
        });
    });

    // ── Pet select change → refresh fields + docs + checkboxes ──
    ownerSelect.addEventListener('change', function() {
        const selectedPet = ownerPets.find(p => String(p.PET_ID) === this.value);
        if (selectedPet) {
            document.getElementById('modalPetId').value = selectedPet.PET_ID;
            fillPetFields(selectedPet);
            renderDocuments(allDocuments, selectedPet.PET_ID);
        }
    });

    // ── EDIT: submit via FormData ──────────────────────────
    document.getElementById('saveOwnerPetBtn').addEventListener('click', function() {
        const form = document.getElementById('editOwnerForm');
        if (!form.checkValidity()) { form.reportValidity(); return; }

        const formData = new FormData(form);
        formData.append('action', 'update_owner_pet');

        fetch('owner.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showAlert('modalAlert', 'success', data.message);
                setTimeout(() => location.reload(), 1200);
            } else {
                showAlert('modalAlert', 'error', data.message);
            }
        })
        .catch(error => {
            showAlert('modalAlert', 'error', 'Could not save changes. Please try again.');
            console.error(error);
        });
    });

    // ── Load owner data via AJAX ───────────────────────────
    function loadOwnerData(ownerId) {
        document.getElementById('editDocumentsList').innerHTML = '<div class="doc-loading">Loading documents&hellip;</div>';
        document.getElementById('modalAlert').style.display = 'none';

        fetch('owner.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_owner_data&owner_id=' + encodeURIComponent(ownerId)
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showAlert('modalAlert', 'error', data.message); return; }

            const owner = data.data.owner;
            ownerPets    = data.data.pets      || [];
            allDocuments = data.data.documents || [];

            document.getElementById('modalOwnerId').value       = owner.OWNER_ID;
            document.getElementById('modalOwnerCode').value     = 'OWN-' + String(owner.OWNER_ID).padStart(4, '0');
            document.getElementById('modalFirstName').value     = owner.FIRST_NAME;
            document.getElementById('modalLastName').value      = owner.LAST_NAME;
            document.getElementById('modalContactNumber').value = owner.CONTACT_NUMBER;

            // Populate pet select
            ownerSelect.innerHTML = '';
            ownerPets.forEach(pet => {
                const opt = document.createElement('option');
                opt.value       = pet.PET_ID;
                opt.textContent = pet.PET_NAME;
                ownerSelect.appendChild(opt);
            });

            if (ownerPets.length > 0) {
                ownerSelect.value = ownerPets[0].PET_ID;
                document.getElementById('modalPetId').value = ownerPets[0].PET_ID;
                fillPetFields(ownerPets[0]);
                renderDocuments(allDocuments, ownerPets[0].PET_ID);
            } else {
                document.getElementById('modalPetId').value = '';
                ['modalPetName','modalPetCategory','modalPetWeight','modalFeedingTime','modalPortion'].forEach(id => {
                    document.getElementById(id).value = '';
                });
                document.getElementById('modalPetSex').value = 'Male';
                resetAllCheckboxes();
                document.getElementById('editDocumentsList').innerHTML = '<div class="doc-empty">No pets registered for this owner.</div>';
            }
        })
        .catch(error => {
            showAlert('modalAlert', 'error', 'Unable to load owner data.');
            console.error(error);
        });
    }

    // ── Fill edit modal pet fields + restore checkbox states ──
    function fillPetFields(pet) {
        document.getElementById('modalPetId').value       = pet.PET_ID;
        document.getElementById('modalPetName').value     = pet.PET_NAME;
        document.getElementById('modalPetCategory').value = pet.CATEGORY_ID;
        document.getElementById('modalPetWeight').value   = pet.WEIGHT;
        document.getElementById('modalPetSex').value      = pet.SEX || 'Male';
        document.getElementById('modalFeedingTime').value = pet.FEEDING_TIME  || '';
        document.getElementById('modalPortion').value     = pet.FEEDING_PORTION || '';

        // Restore verification checkbox states from the latest booking values
        // The PHP query uses NVL(…,'No') so values are always 'Yes' or 'No'.
        setCheckbox(document.getElementById('modalConsentForm'), pet.CONSENT_FORM_SIGNED === 'Yes');
        setCheckbox(document.getElementById('modalNexgard'),     pet.NEXGARD_VERIFIED    === 'Yes');
        setCheckbox(document.getElementById('modalOcularExam'),  pet.OCULAR_EXAM_PASSED  === 'Yes');
        setCheckbox(document.getElementById('modalVetcard'),     pet.VETCARD_VERIFIED    === 'Yes');
    }

    // ── Reset all four checkboxes to unchecked ─────────────
    function resetAllCheckboxes() {
        ['modalConsentForm','modalNexgard','modalOcularExam','modalVetcard'].forEach(id => {
            setCheckbox(document.getElementById(id), false);
        });
    }
});
</script>

</body>
</html>
