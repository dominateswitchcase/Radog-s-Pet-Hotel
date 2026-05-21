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
// UPLOAD PATH: maps precisely to Radog-s-Pet-Hotel/uploads/pet_docs/
// ─────────────────────────────────────────────────────────────────────────────
function saveDocuments($pdo, $petId, $filesArray, $docType = 'Pet Document') {
    $errors = [];

    // Resolve the upload directory relative to the project root.
    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pet_docs' . DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ["Failed to create upload directory: $uploadDir"];
        }
    }

    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];

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

        // Normalised relative path so the frontend can locate the image
        $webPath  = '../uploads/pet_docs/' . $safeName;

        if (!move_uploaded_file($filesArray['tmp_name'][$i], $dest)) {
            $errors[] = "Failed to move uploaded file '$origName'.";
            continue;
        }

        // Generate Doc ID manually
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

                $consent = normaliseYesNo($pet['consent_form'] ?? null);
                $nexgard = normaliseYesNo($pet['nexgard']      ?? null);
                $ocular  = normaliseYesNo($pet['ocular_exam']  ?? null);
                $vetcard = normaliseYesNo($pet['vetcard']      ?? null);

                $pet_stmt = $pdo->prepare(
                    "INSERT INTO PET (
                        PET_ID, PET_NAME, SEX, WEIGHT, FEEDING_TIME,
                        FEEDING_PORTION, OWNER_ID, CATEGORY_ID, TIER_ID,
                        BEHAVIORAL_NOTES, STATUS, 
                        CONSENT_FORM_SIGNED, NEXGARD_VERIFIED, OCULAR_EXAM_PASSED, VETCARD_VERIFIED
                     ) VALUES (
                        :pid, :name, :sex, :weight, :ftime,
                        :fportion, :oid, :cid, :tid,
                        :notes, 'Active', 
                        :consent, :nexgard, :ocular, :vetcard
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
                    'consent'  => $consent,
                    'nexgard'  => $nexgard,
                    'ocular'   => $ocular,
                    'vetcard'  => $vetcard
                ]);

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

        // Fetch pets with verification states directly from PET table
        $pets_stmt = $pdo->prepare("
            SELECT P.PET_ID, P.PET_NAME, P.CATEGORY_ID, P.WEIGHT, P.SEX,
                   P.FEEDING_TIME, P.FEEDING_PORTION,
                   NVL(P.CONSENT_FORM_SIGNED, 'No') AS CONSENT_FORM_SIGNED,
                   NVL(P.NEXGARD_VERIFIED,    'No') AS NEXGARD_VERIFIED,
                   NVL(P.OCULAR_EXAM_PASSED,  'No') AS OCULAR_EXAM_PASSED,
                   NVL(P.VETCARD_VERIFIED,     'No') AS VETCARD_VERIFIED
            FROM PET P
            WHERE P.OWNER_ID = :id
            AND P.STATUS = 'Active'
            ORDER BY P.PET_NAME
        ");
        $pets_stmt->execute(['id' => $owner_id]);
        $pets = $pets_stmt->fetchAll(PDO::FETCH_ASSOC);

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

            $pdo->prepare(
                "UPDATE OWNER SET FIRST_NAME = :fname, LAST_NAME = :lname,
                 CONTACT_NUMBER = :contact WHERE OWNER_ID = :id"
            )->execute(['fname' => $first_name, 'lname' => $last_name, 'contact' => $contact, 'id' => $owner_id]);

            $tier_id = resolveTierId($pdo, $weight);

            $pdo->prepare(
                "UPDATE PET SET PET_NAME = :pet_name, CATEGORY_ID = :category_id,
                 WEIGHT = :weight, SEX = :sex, FEEDING_TIME = :feeding_time,
                 FEEDING_PORTION = :portion, TIER_ID = :tier_id,
                 CONSENT_FORM_SIGNED = :consent, NEXGARD_VERIFIED = :nexgard,
                 OCULAR_EXAM_PASSED = :ocular, VETCARD_VERIFIED = :vetcard
                 WHERE PET_ID = :pet_id AND OWNER_ID = :owner_id"
            )->execute([
                'pet_name'     => $pet_name,
                'category_id'  => $category_id,
                'weight'       => $weight,
                'sex'          => ucfirst($sex),
                'feeding_time' => $feeding_time,
                'portion'      => $portion,
                'tier_id'      => $tier_id,
                'consent'      => $consent,
                'nexgard'      => $nexgard,
                'ocular'       => $ocular,
                'vetcard'      => $vetcard,
                'pet_id'       => $pet_id,
                'owner_id'     => $owner_id,
            ]);

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
    // DEACTIVATE OWNER 
    // =========================================================================
    if
