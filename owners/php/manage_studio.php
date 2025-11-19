<?php
session_start();
include '../../shared/config/db pdo.php';
// Mailer for sending document upload link after studio creation
require_once __DIR__ . '/../../shared/config/mail_config.php';

// Check if user is logged in as a studio owner
// If this is an AJAX request, return JSON with 401 instead of redirecting
$ajaxRequest = isset($_GET['ajax']) || isset($_POST['ajax']);
if (!isset($_SESSION['user_id']) || ($_SESSION['user_type'] ?? null) !== 'owner') {
    if ($ajaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated as owner. Please log in.'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        // Redirect to login page if not logged in as owner
        header('Location: ../../auth/php/login.php');
    }
    exit();
}

$owner_id = $_SESSION['user_id'];

// Function to get subscription limits
function getSubscriptionLimits($pdo, $owner_id) {
    $stmt = $pdo->prepare("
        SELECT sp.max_studios, sp.plan_name
        FROM studio_owners so
        LEFT JOIN subscription_plans sp ON so.subscription_plan_id = sp.plan_id
        WHERE so.OwnerID = ?
    ");
    $stmt->execute([$owner_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || !$result['max_studios']) {
        return ['max_studios' => 5, 'plan_name' => 'Starter'];
    }
    
    return $result;
}

// Function to count studios (only approved studios)
function countStudios($pdo, $owner_id) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM studios 
        WHERE OwnerID = ? 
        AND approved_by_admin IS NOT NULL 
        AND approved_at IS NOT NULL
    ");
    $stmt->execute([$owner_id]);
    return (int)$stmt->fetchColumn();
}

// Utility: sanitize all strings to valid UTF-8 to avoid json_encode warnings
function sanitize_utf8($value) {
    if (is_array($value)) {
        foreach ($value as $k => $v) { $value[$k] = sanitize_utf8($v); }
        return $value;
    }
    if (is_string($value)) {
        // Convert to UTF-8; ignore invalid bytes
        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    return $value;
}

function json_print($data) {
    $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($data, $flags);
    if ($json === false) {
        $safe = sanitize_utf8($data);
        $json = json_encode($safe, $flags);
    }
    echo $json;
}

// AJAX: Return studio details for modal view
if (isset($_GET['ajax']) && $_GET['ajax'] === 'studio_details') {
    // Ensure clean JSON output for AJAX: suppress notices and clear any buffered output
    if (function_exists('ini_set')) { @ini_set('display_errors', '0'); }
    if (function_exists('ob_get_level') && ob_get_level() > 0) {
        // Clear all output buffers to avoid stray HTML corrupting JSON
        try { while (ob_get_level() > 0) { @ob_end_clean(); } } catch (\Throwable $___) {}
    }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $studioId = isset($_GET['studio_id']) ? (int)$_GET['studio_id'] : 0;
        if ($studioId <= 0) {
            error_log('ManageStudio: studio_details invalid studio_id ' . $studioId . ' for owner ' . ($owner_id ?? 'n/a'));
            json_print(['success' => false, 'message' => 'Invalid studio ID']);
            exit;
        }

        // Inspect ENUM options for documents.document_type to populate dropdown dynamically
        $documentTypeEnum = [];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM documents LIKE 'document_type'");
            $col = $colStmt->fetch(PDO::FETCH_ASSOC);
            $typeDef = $col['Type'] ?? '';
            if ($typeDef && preg_match('/^enum\((.*)\)$/i', $typeDef, $m)) {
                $inside = $m[1];
                $matches = [];
                if (preg_match_all("/'([^']+)'/", $inside, $mm)) { $matches = $mm[1]; }
                if (!empty($matches)) { $documentTypeEnum = $matches; }
            }
        } catch (Exception $e) {
            $documentTypeEnum = [];
        }

        // Fetch studio owned by current user
        $stmt = $pdo->prepare("SELECT * FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
        $stmt->execute([$studioId, $owner_id]);
        $studio = $stmt->fetch(PDO::FETCH_ASSOC);
        // Attach preview source: if StudioImg is a local path, use it; otherwise convert blob to data URL
        if ($studio && !empty($studio['StudioImg'])) {
            $val = $studio['StudioImg'];
            if (is_string($val) && preg_match('/\.(jpg|jpeg|png|webp)$/i', $val)) {
                $studio['StudioImgBase64'] = $val; // treat as path for <img src>
            } else {
                $studio['StudioImgBase64'] = 'data:image/jpeg;base64,' . base64_encode($val);
            }
        }

        if (!$studio) {
            error_log('ManageStudio: studio_details not found/denied for owner ' . ($owner_id ?? 'n/a') . ' studio_id ' . $studioId);
            json_print(['success' => false, 'message' => 'Studio not found or access denied']);
            exit;
        }

        // Try to fetch related documents by studio_id; fallback to legacy registration linkage if needed
        $documents = [];
        try {
            // Preferred: documents linked directly to studio_id
            $docStmt = $pdo->prepare(
                "SELECT d.document_id, d.document_type, d.file_name, d.file_path, d.mime_type, d.uploaded_at\n"
                . "FROM documents d\n"
                . "WHERE d.studio_id = ?\n"
                . "ORDER BY d.uploaded_at DESC"
            );
            $docStmt->execute([$studioId]);
            $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            try {
                // Fallback: legacy schema without documents.studio_id — link via registrations
                $docStmt = $pdo->prepare(
                    "SELECT d.document_id, d.document_type, d.file_name, d.file_path, d.mime_type, d.uploaded_at\n"
                    . "FROM documents d\n"
                    . "INNER JOIN studio_registrations sr ON d.registration_id = sr.registration_id\n"
                    . "WHERE sr.studio_id = ?\n"
                    . "ORDER BY d.uploaded_at DESC"
                );
                $docStmt->execute([$studioId]);
                $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                $documents = [];
            }
        }

        // Fetch assigned services for this studio
        $servicesAssigned = [];
        try {
            $svcStmt = $pdo->prepare(
                "SELECT s.ServiceID, s.ServiceType, s.Description, s.Price, s.OwnerID\n"
                . "FROM services s\n"
                . "INNER JOIN studio_services ss ON s.ServiceID = ss.ServiceID\n"
                . "WHERE ss.StudioID = ?\n"
                . "ORDER BY s.ServiceType"
            );
            $svcStmt->execute([$studioId]);
            $servicesAssigned = $svcStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $servicesAssigned = [];
        }

        // Fetch available services not yet linked to this studio, limited to current OwnerID
        $servicesAvailable = [];
        try {
            $availStmt = $pdo->prepare(
                "SELECT s.ServiceID, s.ServiceType, s.Description, s.Price, s.OwnerID\n"
                . "FROM services s\n"
                . "WHERE s.OwnerID = ? AND s.ServiceID NOT IN (SELECT ss.ServiceID FROM studio_services ss WHERE ss.StudioID = ?)\n"
                . "ORDER BY s.ServiceType"
            );
            $availStmt->execute([$owner_id, $studioId]);
            $servicesAvailable = $availStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $servicesAvailable = [];
        }

        // Compute instructor availability based on service overlap
        $studioServiceIds = [];
        try {
            $sidStmt = $pdo->prepare("SELECT ServiceID FROM studio_services WHERE StudioID = ?");
            $sidStmt->execute([$studioId]);
            $studioServiceIds = array_map('intval', $sidStmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Exception $e) {
            $studioServiceIds = array_map('intval', array_column($servicesAssigned, 'ServiceID'));
        }

        // Eligible instructors: owner instructors offering any of the studio's services
        $eligibleInstructors = [];
        if (!empty($studioServiceIds)) {
            try {
                // Build dynamic IN clause for service IDs
                $placeholders = implode(',', array_fill(0, count($studioServiceIds), '?'));
                $sql =
                    "SELECT \n"
                    . "  i.InstructorID, i.Name, i.Profession, i.Phone, i.Email,\n"
                    . "  GROUP_CONCAT(DISTINCT s.ServiceID ORDER BY s.ServiceType SEPARATOR ',') AS _svc_ids,\n"
                    . "  GROUP_CONCAT(DISTINCT s.ServiceType ORDER BY s.ServiceType SEPARATOR ',') AS _svc_names\n"
                    . "FROM instructors i\n"
                    . "INNER JOIN instructor_services ins ON ins.InstructorID = i.InstructorID AND ins.ServiceID IN ($placeholders)\n"
                    . "LEFT JOIN instructor_services isa ON isa.InstructorID = i.InstructorID\n"
                    . "LEFT JOIN services s ON s.ServiceID = isa.ServiceID\n"
                    . "WHERE i.OwnerID = ?\n"
                    . "GROUP BY i.InstructorID, i.Name, i.Profession, i.Phone, i.Email\n"
                    . "ORDER BY i.Name";
                $params = array_merge($studioServiceIds, [$owner_id]);
                $insStmt = $pdo->prepare($sql);
                $insStmt->execute($params);
                $eligibleInstructors = $insStmt->fetchAll(PDO::FETCH_ASSOC);
                // Parse aggregated services into array of { ServiceID, ServiceType }
                foreach ($eligibleInstructors as &$row) {
                    $idsStr = isset($row['_svc_ids']) ? (string)$row['_svc_ids'] : '';
                    $namesStr = isset($row['_svc_names']) ? (string)$row['_svc_names'] : '';
                    $ids = $idsStr === '' ? [] : explode(',', $idsStr);
                    $names = $namesStr === '' ? [] : explode(',', $namesStr);
                    // Clean arrays
                    $ids = array_values(array_filter($ids, fn($v) => $v !== '' && $v !== null));
                    $names = array_values(array_filter($names, fn($v) => $v !== '' && $v !== null));
                    $svc = [];
                    $n = max(count($ids), count($names));
                    for ($i = 0; $i < $n; $i++) {
                        $svc[] = [
                            'ServiceID' => isset($ids[$i]) ? (int)$ids[$i] : null,
                            'ServiceType' => isset($names[$i]) ? $names[$i] : null,
                        ];
                    }
                    $row['InstructorServices'] = $svc;
                    unset($row['_svc_ids'], $row['_svc_names']);
                }
                unset($row);
            } catch (Exception $e) {
                $eligibleInstructors = [];
            }
        }

        // Determine restricted mode (any rows in studio_instructors)
        $restrictedMode = false;
        try {
            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM studio_instructors WHERE StudioID = ?");
            $cntStmt->execute([$studioId]);
            $restrictedMode = ((int)$cntStmt->fetchColumn()) > 0;
        } catch (Exception $e) {
            $restrictedMode = false;
        }

        // Fetch instructors explicitly assigned to this studio (restriction list)
        $assignedInstructors = [];
        try {
            $sql =
                "SELECT DISTINCT i.InstructorID, i.Name, i.Profession, i.Phone, i.Email\n"
                . "FROM studio_instructors si\n"
                . "INNER JOIN instructors i ON i.InstructorID = si.InstructorID\n"
                . "WHERE si.StudioID = ? AND i.OwnerID = ?\n"
                . "ORDER BY i.Name";
            $stmtAI = $pdo->prepare($sql);
            $stmtAI->execute([$studioId, $owner_id]);
            $assignedInstructors = $stmtAI->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $assignedInstructors = [];
        }

        // Available instructors for selection in UI
        // Do NOT base availability on studio_instructors; show all owner instructors.
        // Assignment state is reflected via checked checkboxes and chips, using $assignedInstructors.
        $availableInstructors = $eligibleInstructors;

        // Attach overlapping services per instructor for display
        $svcMap = [];
        if (!empty($studioServiceIds)) {
            foreach ([$assignedInstructors, $availableInstructors] as &$listRef) {
                foreach ($listRef as &$row) {
                    $iid = (int)$row['InstructorID'];
                    if (!isset($svcMap[$iid])) {
                        try {
                            $placeholders = implode(',', array_fill(0, count($studioServiceIds), '?'));
                            $sql =
                                "SELECT s.ServiceID, s.ServiceType\n"
                                . "FROM services s\n"
                                . "INNER JOIN instructor_services ins ON ins.ServiceID = s.ServiceID\n"
                                . "WHERE ins.InstructorID = ? AND s.ServiceID IN ($placeholders)\n"
                                . "ORDER BY s.ServiceType";
                            $params = array_merge([$iid], $studioServiceIds);
                            $sstmt = $pdo->prepare($sql);
                            $sstmt->execute($params);
                            $svcMap[$iid] = $sstmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $svcMap[$iid] = [];
                        }
                    }
                    $row['ServicesForStudio'] = $svcMap[$iid];
                }
            }
            unset($listRef);
        }

        // Attach ALL services per instructor (not limited to studio services) — only if not already set
        $allSvcMap = [];
        foreach ([$assignedInstructors, $availableInstructors] as &$listRefAll) {
            foreach ($listRefAll as &$rowAll) {
                if (isset($rowAll['InstructorServices']) && is_array($rowAll['InstructorServices'])) { continue; }
                $iidAll = (int)$rowAll['InstructorID'];
                if (!isset($allSvcMap[$iidAll])) {
                    try {
                        $sqlAll =
                            "SELECT s.ServiceID, s.ServiceType\n"
                            . "FROM services s\n"
                            . "INNER JOIN instructor_services ins ON ins.ServiceID = s.ServiceID\n"
                            . "WHERE ins.InstructorID = ?\n"
                            . "ORDER BY s.ServiceType";
                        $stmtAll = $pdo->prepare($sqlAll);
                        $stmtAll->execute([$iidAll]);
                        $allSvcMap[$iidAll] = $stmtAll->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $allSvcMap[$iidAll] = [];
                    }
                }
                $rowAll['InstructorServices'] = $allSvcMap[$iidAll];
            }
        }
        unset($listRefAll);

        json_print([
            'success' => true,
            'studio' => $studio,
            'documents' => $documents,
            'document_type_enum' => $documentTypeEnum,
            'services_assigned' => $servicesAssigned,
            'services_available' => $servicesAvailable,
            'instructors_assigned' => $assignedInstructors,
            'instructors_available' => $availableInstructors,
            'restricted_mode' => $restrictedMode,
        ]);
    } catch (Exception $e) {
        json_print(['success' => false, 'message' => 'Unexpected server error']);
    }
    exit;
}

// Helper: get registration_id linked to a specific studio
function getStudioRegistrationId(PDO $pdo, int $studioId): ?int {
    try {
        $stmt = $pdo->prepare("SELECT registration_id FROM studio_registrations WHERE studio_id = ? ORDER BY registration_id DESC LIMIT 1");
        $stmt->execute([$studioId]);
        $regId = $stmt->fetchColumn();
        if ($regId) { return (int)$regId; }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

// Helper: check if a table has a specific column (used for safe migrations)
function hasColumn(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        return false;
    }
}

// AJAX: POST endpoints for editing studio and managing documents
if (isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax'];
    try {
        if ($action === 'studio_update') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            if ($studioId <= 0) { json_print(['success' => false, 'message' => 'Invalid studio ID']); exit; }
            // Ensure studio belongs to current owner
            $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
            $chk->execute([$studioId, $owner_id]);
            if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }

            $studio_name = trim($_POST['studio_name'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $time_in = $_POST['time_in'] ?? null;
            $time_out = $_POST['time_out'] ?? null;
            // Normalize to HH:MM:SS for MySQL TIME columns
            if ($time_in && preg_match('/^\d{2}:\d{2}$/', $time_in)) { $time_in .= ':00'; }
            if ($time_out && preg_match('/^\d{2}:\d{2}$/', $time_out)) { $time_out .= ':00'; }
            $latitude = isset($_POST['latitude']) ? trim($_POST['latitude']) : null;
            $longitude = isset($_POST['longitude']) ? trim($_POST['longitude']) : null;
            $deposit_percentage = isset($_POST['deposit_percentage']) ? round((float)$_POST['deposit_percentage'], 1) : 25.0;
            // Validate deposit percentage (0-100)
            if ($deposit_percentage < 0) { $deposit_percentage = 0.0; }
            if ($deposit_percentage > 100) { $deposit_percentage = 100.0; }
            $img_action = isset($_POST['img_action']) ? strtolower(trim($_POST['img_action'])) : 'keep';
            if (!in_array($img_action, ['keep','upload','remove'], true)) { $img_action = 'keep'; }

            // Get old image path for cleanup when replacing or removing
            $oldImgPath = null;
            try {
                $selOld = $pdo->prepare("SELECT StudioImg FROM studios WHERE StudioID = ? LIMIT 1");
                $selOld->execute([$studioId]);
                $oldImgPath = $selOld->fetchColumn();
            } catch (Exception $e) { /* ignore */ }

            // Optional: handle profile image upload — store as local file path, not blob
            $hasImage = false;
            $studioImgPath = null;
            if (isset($_FILES['studio_img']) && is_array($_FILES['studio_img']) && (int)($_FILES['studio_img']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $imgFile = $_FILES['studio_img'];
                $ext = strtolower(pathinfo($imgFile['name'], PATHINFO_EXTENSION));
                $allowedImg = ['jpg','jpeg','png','webp'];
                if (!in_array($ext, $allowedImg, true)) {
                    json_print(['success' => false, 'message' => 'Unsupported image type. Allowed: JPG, PNG, WEBP']);
                    exit;
                }
                $maxImg = 10 * 1024 * 1024; // 10MB
                if ((int)$imgFile['size'] > $maxImg) {
                    json_print(['success' => false, 'message' => 'Image too large (max 10MB)']);
                    exit;
                }
                // Ensure upload directory exists: /uploads/studios/<StudioID>/
                $uploadDir = realpath(__DIR__ . '/../../uploads');
                if ($uploadDir === false) { $uploadDir = __DIR__ . '/../../uploads'; }
                $studioDir = $uploadDir . '/studios/' . $studioId;
                if (!is_dir($studioDir)) { @mkdir($studioDir, 0755, true); }
                // Generate unique filename
                $filename = 'profile_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $fullPath = $studioDir . '/' . $filename;
                if (!@move_uploaded_file($imgFile['tmp_name'], $fullPath)) {
                    json_print(['success' => false, 'message' => 'Failed to save uploaded image']);
                    exit;
                }
                // Store web-visible path from site root
                $studioImgPath = '/uploads/studios/' . $studioId . '/' . $filename;
                $hasImage = true;
            }

            if (empty($studio_name) || empty($location)) {
                json_print(['success' => false, 'message' => 'Studio name and location are required']);
                exit;
            }

            // Apply image action: remove, upload, or keep
            if ($img_action === 'remove') {
                // Delete old image file if it exists and seems safe
                if (is_string($oldImgPath) && preg_match('/^uploads\/studios\/' . $studioId . '\/[^\s]+\.(jpg|jpeg|png|webp)$/i', (string)$oldImgPath)) {
                    $fullOld = __DIR__ . '/../../' . $oldImgPath;
                    if (file_exists($fullOld)) { @unlink($fullOld); }
                }
                $upd = $pdo->prepare("UPDATE studios SET StudioName = ?, Loc_Desc = ?, Latitude = ?, Longitude = ?, Time_IN = ?, Time_OUT = ?, deposit_percentage = ?, StudioImg = NULL WHERE StudioID = ?");
                $upd->execute([$studio_name, $location, $latitude, $longitude, $time_in, $time_out, $deposit_percentage, $studioId]);
            } elseif ($hasImage && $img_action === 'upload') {
                // Replace old file if present, then set new path
                if (is_string($oldImgPath) && preg_match('/^uploads\/studios\/' . $studioId . '\/[^\s]+\.(jpg|jpeg|png|webp)$/i', (string)$oldImgPath)) {
                    $fullOld = __DIR__ . '/../../' . $oldImgPath;
                    if (file_exists($fullOld)) { @unlink($fullOld); }
                }
                $upd = $pdo->prepare("UPDATE studios SET StudioName = ?, Loc_Desc = ?, Latitude = ?, Longitude = ?, Time_IN = ?, Time_OUT = ?, deposit_percentage = ?, StudioImg = ? WHERE StudioID = ?");
                $upd->execute([$studio_name, $location, $latitude, $longitude, $time_in, $time_out, $deposit_percentage, $studioImgPath, $studioId]);
            } else {
                // Keep current image reference
                $upd = $pdo->prepare("UPDATE studios SET StudioName = ?, Loc_Desc = ?, Latitude = ?, Longitude = ?, Time_IN = ?, Time_OUT = ?, deposit_percentage = ? WHERE StudioID = ?");
                $upd->execute([$studio_name, $location, $latitude, $longitude, $time_in, $time_out, $deposit_percentage, $studioId]);
            }
            $get = $pdo->prepare("SELECT * FROM studios WHERE StudioID = ?");
            $get->execute([$studioId]);
            $studio = $get->fetch(PDO::FETCH_ASSOC);
            json_print(['success' => true, 'message' => 'Studio updated', 'studio' => $studio]);
            exit;
        }

        if ($action === 'document_add') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            $registrationId = isset($_POST['registration_id']) ? (int)$_POST['registration_id'] : 0;

            // Two flows:
            // 1) New studio registration flow: registration_id provided. Resolve and authorize, derive studio_id
            // 2) Established studio flow: studio_id provided directly. Authorize via studios
            if ($registrationId > 0) {
                $regSel = $pdo->prepare(
                    "SELECT sr.registration_id, sr.studio_id FROM studio_registrations sr INNER JOIN studios s ON sr.studio_id = s.StudioID WHERE sr.registration_id = ? AND s.OwnerID = ? LIMIT 1"
                );
                $regSel->execute([$registrationId, $owner_id]);
                $reg = $regSel->fetch(PDO::FETCH_ASSOC);
                if (!$reg) { json_print(['success' => false, 'message' => 'Registration not found or access denied']); exit; }
                $studioId = (int)$reg['studio_id'];
            } else {
                if ($studioId <= 0) { json_print(['success' => false, 'message' => 'Studio ID required']); exit; }
                // Verify studio belongs to owner
                $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
                $chk->execute([$studioId, $owner_id]);
                if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }
            }

            $document_type = $_POST['document_type'] ?? 'other';
            $file = $_FILES['file'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                json_print(['success' => false, 'message' => 'No file uploaded']);
                exit;
            }

            $allowedExt = ['jpg','jpeg','png','pdf','doc','docx'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                json_print(['success' => false, 'message' => 'Unsupported file type']);
                exit;
            }
            $maxSize = 15 * 1024 * 1024; // 15MB
            if ((int)$file['size'] > $maxSize) {
                json_print(['success' => false, 'message' => 'File too large']);
                exit;
            }

            $uploadDir = __DIR__ . '/../../uploads/documents/' . $studioId;
            if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
            $filename = $document_type . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            $fullPath = $uploadDir . '/' . $filename;
            $publicPath = 'uploads/documents/' . $studioId . '/' . $filename;
            if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                json_print(['success' => false, 'message' => 'Failed to save file']);
                exit;
            }

            $mimeType = $file['type'] ?? 'application/octet-stream';
            if (!hasColumn($pdo, 'documents', 'studio_id')) {
                json_print(['success' => false, 'message' => 'Schema not updated: add documents.studio_id and backfill (and make registration_id nullable or remove it).']);
                exit;
            }
            try {
                $hasRegCol = hasColumn($pdo, 'documents', 'registration_id');
                $regToUse = null;
                if ($hasRegCol) {
                    if ($registrationId > 0) {
                        $regToUse = $registrationId;
                    } else {
                        // Derive latest registration_id for this studio if available
                        $regToUse = getStudioRegistrationId($pdo, $studioId);
                    }
                    $ins = $pdo->prepare("INSERT INTO documents (registration_id, studio_id, document_type, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ok = $ins->execute([$regToUse, $studioId, $document_type, $file['name'], $publicPath, (int)$file['size'], $mimeType]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO documents (studio_id, document_type, file_name, file_path, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?)");
                    $ok = $ins->execute([$studioId, $document_type, $file['name'], $publicPath, (int)$file['size'], $mimeType]);
                }
            } catch (Exception $e) {
                @unlink($fullPath);
                $errMsg = $e->getMessage();
                if (stripos($errMsg, 'fk_documents_registration') !== false || stripos($errMsg, 'registration_id') !== false) {
                    $errMsg = 'Registration FK blocked insert: make documents.registration_id NULL-able or relax/drop FK (ON DELETE SET NULL).';
                }
                json_print(['success' => false, 'message' => $errMsg]);
                exit;
            }
            if (!$ok) {
                @unlink($fullPath);
                $err = $ins ? ($ins->errorInfo()[2] ?? 'Database insert failed') : 'Database insert failed';
                json_print(['success' => false, 'message' => $err]);
                exit;
            }
            $docId = (int)$pdo->lastInsertId();
            json_print(['success' => true, 'message' => 'Document added', 'document' => [
                'document_id' => $docId,
                'studio_id' => $studioId,
                'registration_id' => $registrationId > 0 ? $registrationId : null,
                'document_type' => $document_type,
                'file_name' => $file['name'],
                'file_path' => $publicPath,
                'file_size' => (int)$file['size'],
                'mime_type' => $mimeType
            ]]);
            exit;
        }

        if ($action === 'document_delete') {
            $docId = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
            if ($docId <= 0) { json_print(['success' => false, 'message' => 'Invalid document ID']); exit; }
            // Authorization via direct studio link
            $sel = $pdo->prepare("SELECT d.document_id, d.file_path, d.studio_id FROM documents d INNER JOIN studios s ON d.studio_id = s.StudioID WHERE d.document_id = ? AND s.OwnerID = ? LIMIT 1");
            $sel->execute([$docId, $owner_id]);
            $doc = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$doc) { json_print(['success' => false, 'message' => 'Not found or access denied']); exit; }
            // Delete file
            $abs = __DIR__ . '/../../' . ($doc['file_path'] ?? '');
            if (!empty($doc['file_path']) && file_exists($abs)) { @unlink($abs); }
            $del = $pdo->prepare("DELETE FROM documents WHERE document_id = ?");
            $del->execute([$docId]);
            json_print(['success' => true, 'message' => 'Document deleted']);
            exit;
        }

        if ($action === 'document_replace') {
            $docId = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
            if ($docId <= 0) { json_print(['success' => false, 'message' => 'Invalid document ID']); exit; }
            // Authorization via direct studio link
            $sel = $pdo->prepare("SELECT d.* FROM documents d INNER JOIN studios s ON d.studio_id = s.StudioID WHERE d.document_id = ? AND s.OwnerID = ? LIMIT 1");
            $sel->execute([$docId, $owner_id]);
            $doc = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$doc) { json_print(['success' => false, 'message' => 'Not found or access denied']); exit; }

            $document_type = $_POST['document_type'] ?? ($doc['document_type'] ?? 'other');
            $file = $_FILES['file'] ?? null;
            $newFileUploaded = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

            if (!$newFileUploaded && $document_type === ($doc['document_type'] ?? 'other')) {
                json_print(['success' => false, 'message' => 'No changes provided']);
                exit;
            }

            if ($newFileUploaded) {
                $allowedExt = ['jpg','jpeg','png','pdf','doc','docx'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) { json_print(['success' => false, 'message' => 'Unsupported file type']); exit; }
                $maxSize = 15 * 1024 * 1024;
                if ((int)$file['size'] > $maxSize) { json_print(['success' => false, 'message' => 'File too large']); exit; }

                $studioIdForDoc = (int)$doc['studio_id'];
                $uploadDir = __DIR__ . '/../../uploads/documents/' . $studioIdForDoc;
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
                $filename = $document_type . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
                $fullPath = $uploadDir . '/' . $filename;
                $publicPath = 'uploads/documents/' . $studioIdForDoc . '/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $fullPath)) { json_print(['success' => false, 'message' => 'Failed to save file']); exit; }

                // Remove old file
                $oldAbs = __DIR__ . '/../../' . ($doc['file_path'] ?? '');
                if (!empty($doc['file_path']) && file_exists($oldAbs)) { @unlink($oldAbs); }

                $mimeType = $file['type'] ?? 'application/octet-stream';
                $upd = $pdo->prepare("UPDATE documents SET document_type = ?, file_name = ?, file_path = ?, file_size = ?, mime_type = ?, uploaded_at = NOW() WHERE document_id = ?");
                $upd->execute([$document_type, $file['name'], $publicPath, (int)$file['size'], $mimeType, $docId]);
                json_print(['success' => true, 'message' => 'Document replaced', 'document' => [
                    'document_id' => $docId,
                    'document_type' => $document_type,
                    'file_name' => $file['name'],
                    'file_path' => $publicPath,
                    'file_size' => (int)$file['size'],
                    'mime_type' => $mimeType
                ]]);
                exit;
            } else {
                // Only update type
                $upd = $pdo->prepare("UPDATE documents SET document_type = ? WHERE document_id = ?");
                $upd->execute([$document_type, $docId]);
                json_print(['success' => true, 'message' => 'Document type updated', 'document' => [
                    'document_id' => $docId,
                    'document_type' => $document_type,
                    'file_name' => $doc['file_name'],
                    'file_path' => $doc['file_path'],
                    'file_size' => $doc['file_size'],
                    'mime_type' => $doc['mime_type']
                ]]);
                exit;
            }
        }

        if ($action === 'document_update') {
            $docId = isset($_POST['document_id']) ? (int)$_POST['document_id'] : 0;
            if ($docId <= 0) { json_print(['success' => false, 'message' => 'Invalid document ID']); exit; }
            // Authorization via direct studio link
            $sel = $pdo->prepare("SELECT d.* FROM documents d INNER JOIN studios s ON d.studio_id = s.StudioID WHERE d.document_id = ? AND s.OwnerID = ? LIMIT 1");
            $sel->execute([$docId, $owner_id]);
            $doc = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$doc) { json_print(['success' => false, 'message' => 'Not found or access denied']); exit; }

            $document_type = $_POST['document_type'] ?? ($doc['document_type'] ?? 'other');
            $upd = $pdo->prepare("UPDATE documents SET document_type = ? WHERE document_id = ?");
            $upd->execute([$document_type, $docId]);
            json_print(['success' => true, 'message' => 'Document updated', 'document' => [
                'document_id' => $docId,
                'document_type' => $document_type,
                'file_name' => $doc['file_name'],
                'file_path' => $doc['file_path'],
                'file_size' => $doc['file_size'],
                'mime_type' => $doc['mime_type']
            ]]);
            exit;
        }

        // Assign a service to a studio (link only; does not create new service rows)
        if ($action === 'studio_service_assign') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            if ($studioId <= 0 || $serviceId <= 0) { json_print(['success' => false, 'message' => 'Studio and Service IDs are required']); exit; }
            // Ensure studio belongs to current owner
            $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
            $chk->execute([$studioId, $owner_id]);
            if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }
            // Link; guard against duplicates
            try {
                // Use INSERT IGNORE if available; otherwise check first
                $exists = $pdo->prepare("SELECT 1 FROM studio_services WHERE StudioID = ? AND ServiceID = ?");
                $exists->execute([$studioId, $serviceId]);
                if (!$exists->fetch()) {
                    $ins = $pdo->prepare("INSERT INTO studio_services (StudioID, ServiceID) VALUES (?, ?)");
                    $ins->execute([$studioId, $serviceId]);
                }
                json_print(['success' => true, 'message' => 'Service assigned']);
            } catch (Exception $e) {
                json_print(['success' => false, 'message' => 'Failed to assign service']);
            }
            exit;
        }

        // Remove a service assignment from a studio (unlink only)
        if ($action === 'studio_service_remove') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            if ($studioId <= 0 || $serviceId <= 0) { json_print(['success' => false, 'message' => 'Studio and Service IDs are required']); exit; }
            // Ensure studio belongs to current owner
            $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
            $chk->execute([$studioId, $owner_id]);
            if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }
            try {
                $del = $pdo->prepare("DELETE FROM studio_services WHERE StudioID = ? AND ServiceID = ?");
                $del->execute([$studioId, $serviceId]);
                json_print(['success' => true, 'message' => 'Service removed']);
            } catch (Exception $e) {
                json_print(['success' => false, 'message' => 'Failed to remove service']);
            }
            exit;
        }

        // Assign an instructor to a studio (restriction mode)
        if ($action === 'studio_instructor_assign') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            $instructorId = isset($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : 0;
            if ($studioId <= 0 || $instructorId <= 0) { json_print(['success' => false, 'message' => 'Studio and Instructor IDs are required']); exit; }
            // Ensure studio belongs to current owner
            $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
            $chk->execute([$studioId, $owner_id]);
            if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }
            // Ensure instructor belongs to current owner
            $ichk = $pdo->prepare("SELECT InstructorID FROM instructors WHERE InstructorID = ? AND OwnerID = ? LIMIT 1");
            $ichk->execute([$instructorId, $owner_id]);
            if (!$ichk->fetch()) { json_print(['success' => false, 'message' => 'Instructor not found or access denied']); exit; }
            try {
                $exists = $pdo->prepare("SELECT 1 FROM studio_instructors WHERE StudioID = ? AND InstructorID = ?");
                $exists->execute([$studioId, $instructorId]);
                if (!$exists->fetch()) {
                    $ins = $pdo->prepare("INSERT INTO studio_instructors (StudioID, InstructorID) VALUES (?, ?)");
                    $ins->execute([$studioId, $instructorId]);
                }
                json_print(['success' => true, 'message' => 'Instructor assigned']);
            } catch (Exception $e) {
                json_print(['success' => false, 'message' => 'Failed to assign instructor']);
            }
            exit;
        }

        // Remove instructor restriction from a studio
        if ($action === 'studio_instructor_remove') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            $instructorId = isset($_POST['instructor_id']) ? (int)$_POST['instructor_id'] : 0;
            if ($studioId <= 0 || $instructorId <= 0) { json_print(['success' => false, 'message' => 'Studio and Instructor IDs are required']); exit; }
            // Ensure studio belongs to current owner
            $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
            $chk->execute([$studioId, $owner_id]);
            if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }
            try {
                $del = $pdo->prepare("DELETE FROM studio_instructors WHERE StudioID = ? AND InstructorID = ?");
                $del->execute([$studioId, $instructorId]);
                json_print(['success' => true, 'message' => 'Instructor removed']);
            } catch (Exception $e) {
                json_print(['success' => false, 'message' => 'Failed to remove instructor']);
            }
            exit;
        }

        // Quick action: assign all eligible instructors (enter restricted mode)
        if ($action === 'studio_instructor_assign_all') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            if ($studioId <= 0) { json_print(['success' => false, 'message' => 'Studio ID is required']); exit; }
            // Ensure studio belongs to current owner
            $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
            $chk->execute([$studioId, $owner_id]);
            if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }
            try {
                // Fetch eligible instructor IDs via service overlap
                $svc = $pdo->prepare("SELECT ServiceID FROM studio_services WHERE StudioID = ?");
                $svc->execute([$studioId]);
                $svcIds = array_map('intval', $svc->fetchAll(PDO::FETCH_COLUMN));
                if (empty($svcIds)) { json_print(['success' => false, 'message' => 'No services assigned; no eligible instructors']); exit; }
                $ph = implode(',', array_fill(0, count($svcIds), '?'));
                $sql = "SELECT DISTINCT i.InstructorID FROM instructors i INNER JOIN instructor_services ins ON i.InstructorID = ins.InstructorID WHERE ins.ServiceID IN ($ph) AND i.OwnerID = ?";
                $params = array_merge($svcIds, [$owner_id]);
                $st = $pdo->prepare($sql);
                $st->execute($params);
                $eligible = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $count = 0;
                foreach ($eligible as $iid) {
                    $exists = $pdo->prepare("SELECT 1 FROM studio_instructors WHERE StudioID = ? AND InstructorID = ?");
                    $exists->execute([$studioId, $iid]);
                    if (!$exists->fetch()) {
                        $ins = $pdo->prepare("INSERT INTO studio_instructors (StudioID, InstructorID) VALUES (?, ?)");
                        $ins->execute([$studioId, $iid]);
                        $count++;
                    }
                }
                json_print(['success' => true, 'message' => 'Assigned all eligible instructors', 'assigned_count' => $count]);
            } catch (Exception $e) {
                json_print(['success' => false, 'message' => 'Failed to assign all eligible instructors']);
            }
            exit;
        }

        // Quick action: clear all instructor restrictions (return to automatic mode)
        if ($action === 'studio_instructor_clear') {
            $studioId = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 0;
            if ($studioId <= 0) { json_print(['success' => false, 'message' => 'Studio ID is required']); exit; }
            // Ensure studio belongs to current owner
            $chk = $pdo->prepare("SELECT StudioID FROM studios WHERE StudioID = ? AND OwnerID = ? LIMIT 1");
            $chk->execute([$studioId, $owner_id]);
            if (!$chk->fetch()) { json_print(['success' => false, 'message' => 'Access denied']); exit; }
            try {
                $del = $pdo->prepare("DELETE FROM studio_instructors WHERE StudioID = ?");
                $del->execute([$studioId]);
                json_print(['success' => true, 'message' => 'Cleared instructor restrictions']);
            } catch (Exception $e) {
                json_print(['success' => false, 'message' => 'Failed to clear instructor restrictions']);
            }
            exit;
        }

        json_print(['success' => false, 'message' => 'Unknown action']);
    } catch (Exception $e) {
        error_log('Owner AJAX error: ' . $e->getMessage());
        json_print(['success' => false, 'message' => 'Server error']);
    }
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studio_name = trim($_POST['studio_name']);
    $location = trim($_POST['location']);
    $time_in = $_POST['time_in'];
    $time_out = $_POST['time_out'];
    $latitude = isset($_POST['latitude']) ? trim($_POST['latitude']) : null;
    $longitude = isset($_POST['longitude']) ? trim($_POST['longitude']) : null;
    
    // Normalize to HH:MM:SS for MySQL TIME columns
    if ($time_in && preg_match('/^\d{2}:\d{2}$/', $time_in)) { $time_in .= ':00'; }
    if ($time_out && preg_match('/^\d{2}:\d{2}$/', $time_out)) { $time_out .= ':00'; }
    
    if (!empty($studio_name) && !empty($location)) {
        // Check subscription limit before adding
        $limits = getSubscriptionLimits($pdo, $owner_id);
        $current_count = countStudios($pdo, $owner_id);
        
        if ($current_count >= $limits['max_studios']) {
            echo "<script>
                alert('Studio limit reached! Your {$limits['plan_name']} subscription allows up to {$limits['max_studios']} studios. Please upgrade your subscription to add more studios.');
                window.location.href = 'manage_studio.php';
            </script>";
            exit();
        }
        
        try {
            $insert_studio = $pdo->prepare("
                INSERT INTO studios (StudioName, Loc_Desc, Latitude, Longitude, Time_IN, Time_OUT, OwnerID)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $insert_studio->execute([$studio_name, $location, $latitude, $longitude, $time_in, $time_out, $owner_id]);
            $newStudioId = (int)$pdo->lastInsertId();
            
            // Also create a registration and send an upload link via email
            try {
                // Fetch owner contact info
                $ownerStmt = $pdo->prepare("SELECT Name, Email, Phone FROM studio_owners WHERE OwnerID = ? LIMIT 1");
                $ownerStmt->execute([$owner_id]);
                $ownerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);

                $ownerName  = trim($ownerRow['Name'] ?? '');
                $ownerEmail = trim($ownerRow['Email'] ?? '');
                $ownerPhone = trim($ownerRow['Phone'] ?? '');

                // Pick a default plan (prefer 'Starter', otherwise first active)
                $defaultPlanId = 1;
                try {
                    $planStmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE plan_name = ? AND is_active = 1 LIMIT 1");
                    $planStmt->execute(['Starter']);
                    $plan = $planStmt->fetchColumn();
                    if ($plan) {
                        $defaultPlanId = (int)$plan;
                    } else {
                        $planStmt = $pdo->prepare("SELECT plan_id FROM subscription_plans WHERE is_active = 1 ORDER BY plan_id ASC LIMIT 1");
                        $planStmt->execute();
                        $plan = $planStmt->fetchColumn();
                        if ($plan) { $defaultPlanId = (int)$plan; }
                    }
                } catch (Exception $e) {
                    // Keep fallback default
                }

                // Create registration record for this studio addition (prefer schema with studio_id)
                try {
                    $regIns = $pdo->prepare("INSERT INTO studio_registrations (studio_id, business_name, owner_name, owner_email, owner_phone, business_address, business_type, plan_id, subscription_duration) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $regIns->execute([
                        $newStudioId,
                        $studio_name,
                        $ownerName ?: 'Studio Owner',
                        $ownerEmail,
                        $ownerPhone,
                        $location,
                        'recording_studio',
                        $defaultPlanId,
                        'monthly'
                    ]);
                } catch (Exception $e) {
                    // Fallback for legacy schema without studio_id
                    $regIns = $pdo->prepare("INSERT INTO studio_registrations (business_name, owner_name, owner_email, owner_phone, business_address, business_type, plan_id, subscription_duration) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $regIns->execute([
                        $studio_name,
                        $ownerName ?: 'Studio Owner',
                        $ownerEmail,
                        $ownerPhone,
                        $location,
                        'recording_studio',
                        $defaultPlanId,
                        'monthly'
                    ]);
                }
                $registrationId = (int)$pdo->lastInsertId();

                // Generate a secure upload token (7-day expiry)
                $token = bin2hex(random_bytes(32));
                $tokIns = $pdo->prepare("INSERT INTO document_upload_tokens (registration_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
                $tokIns->execute([$registrationId, $token]);

                // Build absolute upload URL
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $uploadUrl = $scheme . '://' . $host . '/auth/php/upload-documents.php?token=' . urlencode($token);

                // Send email (best-effort)
                if (!empty($ownerEmail)) {
                    $subject = 'MuSeek – Upload Your Studio Documents';
                    $html = '<p>Hello ' . htmlspecialchars($ownerName ?: $ownerEmail) . ',</p>' .
                            '<p>Your studio <strong>' . htmlspecialchars($studio_name) . '</strong> has been added.</p>' .
                            '<p>To complete setup, please upload your required documents using the secure link below:</p>' .
                            '<p><a href="' . htmlspecialchars($uploadUrl) . '" style="display:inline-block;padding:10px 16px;background:#e11d48;color:#fff;text-decoration:none;border-radius:6px">Upload Documents</a></p>' .
                            '<p>This link will expire in 7 days.</p>' .
                            '<p>Best regards,<br/>MuSeek Team</p>';
                    $alt = "Upload your studio documents: $uploadUrl\nThis link will expire in 7 days.";
                    @sendTransactionalEmail($ownerEmail, $ownerName ?: $ownerEmail, $subject, $html, $alt);
                }
            } catch (Exception $e) {
                error_log('ManageStudio: upload link generation failed: ' . $e->getMessage());
            }

            // Persist the upload URL for immediate access after redirect
            $_SESSION['upload_verification_url'] = $uploadUrl;
            $_SESSION['success_message'] = "Studio added successfully! We emailed you a secure link to upload documents for verification.";
            // Redirect back to Manage Studio to keep users in context
            header("Location: manage_studio.php");
            exit();
            
        } catch (Exception $e) {
            $error_message = "Error adding studio. Please try again.";
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Get existing studios (show only registered/approved ones)
$studios_query = "
    SELECT s.*
    FROM studios s
    WHERE s.OwnerID = ?
      AND s.approved_by_admin IS NOT NULL
      AND s.approved_at IS NOT NULL
    ORDER BY s.StudioName
";
$studios_stmt = $pdo->prepare($studios_query);
$studios_stmt->execute([$owner_id]);
$studios = $studios_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Studios - MuSeek Studio Management</title>
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
    <!-- Global CSS (fix wrong relative path) -->
    <link rel="stylesheet" href="../css/style.css">
    <!-- Leaflet CSS/JS (stable 1.9.4) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <style>
        :root {
            --primary-color: #e11d48;
            --primary-hover: #f43f5e;
            --header-height: 64px;
            --card-bg: #0f0f0f;
            --body-bg: #0a0a0a;
            --border-color: #222222;
            --text-primary: #ffffff;
            --text-secondary: #a1a1aa;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --sidebar-width: 280px;
            --sidebar-collapsed-width: 70px;
        }

        body {
            background-color: var(--body-bg);
            color: var(--text-primary);
            font-family: "Inter", sans-serif;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            min-height: 100vh;
        }

        .main-content {
            margin-left: var(--sidebar-collapsed-width);
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--body-bg);
        }

        .main-content.full-width {
            margin-left: 0;
        }

        .header {
            height: var(--header-height);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--body-bg);
        }

        .page-title {
            font-size: 1.25rem;
            font-weight: 600;
        }

        .manage-studio-container {
            flex: 1;
            overflow-y: visible;
            overflow-x: hidden;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            min-height: calc(100vh - var(--header-height));
            width: 100%;
            max-width: none;
            margin: 0;
        }

        /* Split layout: form left, studios right */
        .content-split {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 1rem;
        }
        .form-panel { min-width: 0; }
        .studios-panel {
            min-width: 0;
            max-height: none;
            overflow: visible;
            padding-right: 0;
        }

        .page-header { margin-bottom: 0.5rem; display:flex; justify-content: space-between; align-items: center; }
        .page-subtitle {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }

        .form-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
        }

        .form-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }

        .form-title i {
            margin-right: 10px;
            color: var(--primary-color);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-label {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .form-control {
            background: var(--body-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(225, 29, 72, 0.2);
        }

        .form-control::placeholder {
            color: var(--text-secondary);
        }

        .btn {
            background-color: var(--primary-color);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
            background-color: var(--primary-hover);
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.08);
        }

        .studios-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 20px;
            align-items: stretch;
        }

        .studio-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 0.8rem;
            transition: background-color 0.2s;
            position: relative;
            overflow: hidden;
        }

        .studio-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary-color);
        }

        .studio-card:hover {
            transform: translateY(-2px);
            border-color: var(--primary-color);
        }

        .studio-name {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .studio-card-header { display:flex; align-items:center; gap:16px; margin-bottom:10px; }
        .studio-avatar { width: 72px; height: 72px; border-radius: 10px; overflow: hidden; border: 1px solid var(--border-color); display:flex; align-items:center; justify-content:center; background:#151515; }
        .studio-avatar img { width: 100%; height: 100%; object-fit: cover; display:block; }
        .letter-avatar { font-weight: 700; font-size: 1.6rem; color: #fff; }

        .studio-location {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }

        .studio-location i {
            margin-right: 8px;
            color: var(--primary-color);
        }

        .studio-hours {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 15px;
        }

        .studio-description {
            font-size: 0.9rem;
            color: var(--text-secondary);
            line-height: 1.4;
            margin-bottom: 20px;
        }

        .studio-actions {
            display: flex;
            gap: 10px;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            border: 1px solid;
            display: flex;
            align-items: center;
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--success-color);
            color: var(--success-color);
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border-color: var(--danger-color);
            color: var(--danger-color);
        }

        .alert i {
            margin-right: 12px;
            font-size: 18px;
        }

        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .manage-studio-container {
                padding: 20px;
            }
            
            .content-split { grid-template-columns: 1fr; }
            .studios-panel { max-height: none; }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .studios-grid {
                grid-template-columns: 1fr;
            }
            
            .page-title {
                font-size: 2rem;
            }
        }

        /* Map widget styles */
        #studioMap {
            width: 100%;
            height: 320px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        /* Prevent global CSS from breaking Leaflet tiles/canvas */
        .leaflet-container img, .leaflet-container canvas { max-width: none !important; }
        .coords-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 12px;
            align-items: end;
            margin-top: 12px;
        }
        .geo-btn {
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        /* Modal styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: linear-gradient(135deg, var(--netflix-dark-gray) 0%, #1a1a1a 100%);
            border: 1px solid #333;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
            color: var(--netflix-white);
            padding: 20px;
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .modal-title { font-size: 1.25rem; font-weight: 600; }
        .close-btn { background: none; border: none; color: var(--netflix-light-gray); font-size: 1.2rem; cursor: pointer; }
        .doc-badge { display: inline-block; padding: 4px 8px; border: 1px solid #333; border-radius: 8px; font-size: 12px; margin-right: 8px; }
        .doc-list { display: grid; grid-template-columns: 1fr; gap: 8px; }

        /* Theme link styles (fix default purple visited links) */
        .modal-content a { color: var(--primary-color); text-decoration: none; }
        .modal-content a:visited { color: var(--primary-color); }
        .modal-content a:hover { color: var(--primary-hover); text-decoration: underline; }
        .doc-list a { font-weight: 600; }
    </style>
</head>
<body>
    <?php include 'sidebar_netflix.php'; ?>

    <main class="main-content" id="mainContent">
        <header class="header">
            <h1 class="page-title">Manage Studios</h1>
        </header>
        <div class="manage-studio-container">
            <!-- Page Header -->
            <div class="page-header fade-in">
                <p class="page-subtitle">Add and manage your studio locations</p>
                
                <?php
                // Get subscription limits and counts for display
                $studio_limits = getSubscriptionLimits($pdo, $owner_id);
                $studio_count = countStudios($pdo, $owner_id);
                ?>
                
                <!-- Subscription Limit Display -->
                <div class="mt-3 p-3 rounded-lg <?php echo $studio_count >= $studio_limits['max_studios'] ? 'bg-red-900/20 border border-red-600/30' : 'bg-blue-900/20 border border-blue-600/30'; ?>" style="background: <?php echo $studio_count >= $studio_limits['max_studios'] ? 'rgba(127, 29, 29, 0.2)' : 'rgba(30, 58, 138, 0.2)'; ?>; border: 1px solid <?php echo $studio_count >= $studio_limits['max_studios'] ? 'rgba(220, 38, 38, 0.3)' : 'rgba(37, 99, 235, 0.3)'; ?>;">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-building" style="color: <?php echo $studio_count >= $studio_limits['max_studios'] ? '#fca5a5' : '#93c5fd'; ?>"></i>
                            <span class="text-sm font-medium">
                                <span style="color: <?php echo $studio_count >= $studio_limits['max_studios'] ? '#fca5a5' : '#fff'; ?>">
                                    <?php echo $studio_count; ?> / <?php echo $studio_limits['max_studios']; ?> Studios
                                </span>
                                <span class="text-gray-400 ml-2">(<?php echo $studio_limits['plan_name']; ?>)</span>
                            </span>
                        </div>
                        <?php if ($studio_count >= $studio_limits['max_studios']): ?>
                            <span class="text-xs px-3 py-1 rounded-full" style="background: #dc2626; color: white; font-weight: 600;">
                                <i class="fas fa-exclamation-circle mr-1"></i> Limit Reached
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <button id="addNewStudioBtn" class="btn btn-primary" <?php echo $studio_count >= $studio_limits['max_studios'] ? 'disabled style="opacity: 0.5; cursor: not-allowed;" title="Studio limit reached. Upgrade your plan to add more."' : ''; ?>><i class="fas fa-plus"></i> Add New Studio</button>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success fade-in">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                    <?php if (!empty($_SESSION['upload_verification_url'])): ?>
                        <span style="margin-left: 8px; display: inline-block;">
                            <a href="<?php echo htmlspecialchars($_SESSION['upload_verification_url']); ?>" class="btn btn-sm btn-secondary" style="margin-left: 6px;">
                                <i class="fas fa-file-upload"></i>
                                Open Upload Page
                            </a>
                        </span>
                    <?php endif; ?>
                    <?php unset($_SESSION['success_message']); ?>
                    <?php // keep URL for subsequent page loads until explicitly cleared ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-error fade-in">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <div class="content-split">
                <!-- Left: Add Studio Form -->
                <section class="form-panel" style="display:none;">
                    <div class="form-container fade-in">
                        <h3 class="form-title">
                            <i class="fas fa-plus"></i>
                            Add New Studio
                        </h3>
                        
                        <form method="POST">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="studio_name">Studio Name *</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="studio_name" 
                                           name="studio_name" 
                                           placeholder="Enter studio name"
                                           required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="location">Location *</label>
                                    <input type="text" 
                                           class="form-control" 
                                           id="location" 
                                           name="location" 
                                           placeholder="Enter studio location"
                                           required>
                                </div>
                                <div class="form-group full-width">
                                    <label class="form-label">Set Studio Coordinates</label>
                                    <div id="studioMap"></div>
                                    <div class="coords-row">
                                        <div class="form-group">
                                            <label class="form-label" for="latitude">Latitude</label>
                                            <input type="text" class="form-control" id="latitude_display" placeholder="Click map or use GPS" readonly>
                                            <input type="hidden" id="latitude" name="latitude">
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label" for="longitude">Longitude</label>
                                            <input type="text" class="form-control" id="longitude_display" placeholder="Click map or use GPS" readonly>
                                            <input type="hidden" id="longitude" name="longitude">
                                        </div>
                                        <button type="button" class="geo-btn" id="useMyLocation"><i class="fas fa-location-crosshairs"></i> Use my location</button>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="time_in">Opening Time</label>
                                    <select class="form-control" id="time_in" name="time_in">
                                        <option value="00:00">12:00 AM</option>
                                        <option value="01:00">1:00 AM</option>
                                        <option value="02:00">2:00 AM</option>
                                        <option value="03:00">3:00 AM</option>
                                        <option value="04:00">4:00 AM</option>
                                        <option value="05:00">5:00 AM</option>
                                        <option value="06:00" selected>6:00 AM</option>
                                        <option value="07:00">7:00 AM</option>
                                        <option value="08:00">8:00 AM</option>
                                        <option value="09:00">9:00 AM</option>
                                        <option value="10:00">10:00 AM</option>
                                        <option value="11:00">11:00 AM</option>
                                        <option value="12:00">12:00 PM</option>
                                        <option value="13:00">1:00 PM</option>
                                        <option value="14:00">2:00 PM</option>
                                        <option value="15:00">3:00 PM</option>
                                        <option value="16:00">4:00 PM</option>
                                        <option value="17:00">5:00 PM</option>
                                        <option value="18:00">6:00 PM</option>
                                        <option value="19:00">7:00 PM</option>
                                        <option value="20:00">8:00 PM</option>
                                        <option value="21:00">9:00 PM</option>
                                        <option value="22:00">10:00 PM</option>
                                        <option value="23:00">11:00 PM</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="time_out">Closing Time</label>
                                    <select class="form-control" id="time_out" name="time_out">
                                        <option value="00:00">12:00 AM</option>
                                        <option value="01:00">1:00 AM</option>
                                        <option value="02:00">2:00 AM</option>
                                        <option value="03:00">3:00 AM</option>
                                        <option value="04:00">4:00 AM</option>
                                        <option value="05:00">5:00 AM</option>
                                        <option value="06:00">6:00 AM</option>
                                        <option value="07:00">7:00 AM</option>
                                        <option value="08:00">8:00 AM</option>
                                        <option value="09:00">9:00 AM</option>
                                        <option value="10:00">10:00 AM</option>
                                        <option value="11:00">11:00 AM</option>
                                        <option value="12:00">12:00 PM</option>
                                        <option value="13:00">1:00 PM</option>
                                        <option value="14:00">2:00 PM</option>
                                        <option value="15:00">3:00 PM</option>
                                        <option value="16:00">4:00 PM</option>
                                        <option value="17:00">5:00 PM</option>
                                        <option value="18:00">6:00 PM</option>
                                        <option value="19:00">7:00 PM</option>
                                        <option value="20:00">8:00 PM</option>
                                        <option value="21:00">9:00 PM</option>
                                        <option value="22:00" selected>10:00 PM</option>
                                        <option value="23:00">11:00 PM</option>
                                    </select>
                                </div>
                                
                                
                            </div>
                            
                            <div style="display: flex; gap: 15px; justify-content: flex-end;">
                                <button type="button" class="btn btn-secondary" onclick="clearForm()">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                                <button type="submit" class="btn">
                                    <i class="fas fa-save"></i>
                                    Add Studio
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <!-- Right: Existing Studios (scrollable) -->
                <section class="studios-panel" style="grid-column: 1 / -1;">
                    <?php if (!empty($studios)): ?>
                        <div class="studios-section">
                            <h3 class="form-title" style="margin-bottom: 12px;">
                                <i class="fas fa-list"></i>
                                Your Studios
                            </h3>
                            <div class="studios-grid">
                                <?php foreach ($studios as $index => $studio): ?>
                                    <?php 
                                        $firstLetter = strtoupper(substr($studio['StudioName'] ?? '', 0, 1));
                                        $imgSrc = '';
                                        if (!empty($studio['StudioImg'])) {
                                            $val = $studio['StudioImg'];
                                            if (is_string($val) && preg_match('/\.(jpg|jpeg|png|webp)$/i', $val)) {
                                                $imgSrc = trim($val); // local path stored in DB
                                            } else {
                                                // StudioImg is stored as binary (BLOB). Convert to data URL.
                                                $imgSrc = 'data:image/jpeg;base64,' . base64_encode($val);
                                            }
                                        } elseif (!empty($studio['StudioImgBase64'])) {
                                            // Some queries may already provide a Base64 or path fallback.
                                            $imgSrc = trim($studio['StudioImgBase64']);
                                        }
                                    ?>
                                    <div class="studio-card slide-in-left" data-studio-id="<?php echo (int)$studio['StudioID']; ?>" style="animation-delay: <?php echo $index * 0.1; ?>s">
                                        <div class="studio-card-header">
                                            <div class="studio-avatar">
                                                <?php if (!empty($imgSrc)) { ?>
                                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="Studio Image">
                                                <?php } else { ?>
                                                    <div class="letter-avatar"><?php echo htmlspecialchars($firstLetter ?: '?'); ?></div>
                                                <?php } ?>
                                            </div>
                                            <div>
                                                <h4 class="studio-name"><?php echo htmlspecialchars($studio['StudioName']); ?></h4>
                                                <div class="studio-location">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?php echo htmlspecialchars($studio['Loc_Desc']); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="studio-hours">
                                            <i class="fas fa-clock"></i>
                                            <?php echo date('g:i A', strtotime($studio['Time_IN'])); ?> - 
                                            <?php echo date('g:i A', strtotime($studio['Time_OUT'])); ?>
                                        </div>
                                        <?php if (!empty($studio['Description'] ?? '')): ?>
                                            <div class="studio-description">
                                                <?php echo htmlspecialchars($studio['Description'] ?? '') ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="studio-actions">
                                            <button class="btn btn-sm" onclick="editStudio(<?php echo $studio['StudioID']; ?>)">
                                                <i class="fas fa-edit"></i>
                                                Edit
                                            </button>
                                            <button class="btn btn-sm" onclick="openOfferingsModal(<?php echo $studio['StudioID']; ?>)">
                                                <i class="fas fa-users-gear"></i>
                                                Assign Services and Instructors
                                            </button>
                                            <button class="btn btn-sm btn-secondary" onclick="viewStudio(<?php echo $studio['StudioID']; ?>)">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="form-container fade-in" style="text-align: center; padding: 40px 24px;">
                            <i class="fas fa-building" style="font-size: 3rem; color: var(--text-secondary); margin-bottom: 16px;"></i>
                            <h3 style="color: var(--text-primary); margin-bottom: 10px;">No Studios Yet</h3>
                            <p style="color: var(--text-secondary); margin-bottom: 12px;">
                                Click "Add New Studio" to create your first studio.
                            </p>
                            <button id="addNewStudioBtnEmpty" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Studio</button>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    <script>
        function clearForm() {
            document.getElementById('studio_name').value = '';
            document.getElementById('location').value = '';
            document.getElementById('time_in').value = '06:00';
            document.getElementById('time_out').value = '22:00';
            document.getElementById('description').value = '';
            const latInput = document.getElementById('latitude');
            const lngInput = document.getElementById('longitude');
            const latDisp = document.getElementById('latitude_display');
            const lngDisp = document.getElementById('longitude_display');
            if (latInput) latInput.value = '';
            if (lngInput) lngInput.value = '';
            if (latDisp) latDisp.value = '';
            if (lngDisp) lngDisp.value = '';
        }

        // editStudio will be defined below with a full-featured modal

        // viewStudio is defined later with modal and AJAX fetch

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate cards on scroll
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observe all cards
            document.querySelectorAll('.studio-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                observer.observe(card);

                // Open view modal when card is clicked (excluding action buttons)
                card.addEventListener('click', function(e) {
                    const inActions = e.target.closest('.studio-actions');
                    const isInteractive = e.target.closest('button, a');
                    if (inActions || isInteractive) return;
                    const id = this.dataset.studioId;
                    if (id) {
                        try { window.viewStudio(id); } catch (_) {}
                    }
                });
            });

            // Add hover effects
            document.querySelectorAll('.btn').forEach(btn => {
                btn.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                
                btn.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.remove();
                }, 500);
            });
        }, 5000);

        // Leaflet map initializer with CDN fallback and stronger sizing
        (function initStudioMap() {
            const init = () => {
                const mapEl = document.getElementById('studioMap');
                if (!mapEl || typeof L === 'undefined') return;
                const map = L.map('studioMap').setView([14.5995, 120.9842], 11); // Default center: Manila
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);
                // Ensure proper sizing after animations/layout
                map.whenReady(() => map.invalidateSize());
                setTimeout(() => map.invalidateSize(), 100);
                setTimeout(() => map.invalidateSize(), 600);

                let marker = null;
                const latInput = document.getElementById('latitude');
                const lngInput = document.getElementById('longitude');
                const latDisp = document.getElementById('latitude_display');
                const lngDisp = document.getElementById('longitude_display');
                function setCoords(lat, lng) {
                    if (latInput) latInput.value = lat;
                    if (lngInput) lngInput.value = lng;
                    if (latDisp) latDisp.value = lat;
                    if (lngDisp) lngDisp.value = lng;
                }

                // Lightweight reverse geocoding: only after drag ends or on click
                let reverseCtrl = null;
                async function reverseGeocode(lat, lng) {
                    try {
                        if (reverseCtrl) { try { reverseCtrl.abort(); } catch (_) {} }
                        reverseCtrl = new AbortController();
                        const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(lat) + '&lon=' + encodeURIComponent(lng);
                        const resp = await fetch(url, { headers: { 'Accept-Language': 'en' }, signal: reverseCtrl.signal });
                        const data = await resp.json();
                        const name = (data && data.display_name) ? String(data.display_name) : '';
                        const locEl = document.getElementById('location');
                        if (locEl) {
                            if (name) {
                                locEl.value = name;
                            } else {
                                // Fallback: fill with coordinates if address is unavailable
                                const la = Number(lat).toFixed(6);
                                const lo = Number(lng).toFixed(6);
                                locEl.value = la + ', ' + lo;
                            }
                        }
                    } catch (err) {
                        // Network or API error: still fill with coordinates so the field is not left empty
                        const locEl = document.getElementById('location');
                        if (locEl) {
                            const la = Number(lat).toFixed(6);
                            const lo = Number(lng).toFixed(6);
                            locEl.value = la + ', ' + lo;
                        }
                    }
                }

                function ensureMarker(lat, lng) {
                    const la = Number(lat);
                    const lo = Number(lng);
                    if (marker) {
                        marker.setLatLng([la, lo]);
                    } else {
                        marker = L.marker([la, lo], { draggable: true }).addTo(map);
                        marker.on('dragend', (e) => {
                            const pos = e.target.getLatLng();
                            setCoords(pos.lat.toFixed(6), pos.lng.toFixed(6));
                            // Reverse geocode only after drag ends to keep movement light
                            reverseGeocode(pos.lat, pos.lng);
                        });
                        marker.on('click', (e) => {
                            const pos = e.target.getLatLng();
                            setCoords(pos.lat.toFixed(6), pos.lng.toFixed(6));
                            reverseGeocode(pos.lat, pos.lng);
                        });
                    }
                    setCoords(la.toFixed(6), lo.toFixed(6));
                }

                map.on('click', function(e) {
                    const { lat, lng } = e.latlng;
                    ensureMarker(lat, lng);
                    reverseGeocode(lat, lng);
                });

                const useMyLocationBtn = document.getElementById('useMyLocation');
                if (useMyLocationBtn) {
                    useMyLocationBtn.addEventListener('click', function() {
                        if (!navigator.geolocation) { alert('Geolocation not supported by your browser'); return; }
                        navigator.geolocation.getCurrentPosition(pos => {
                            const { latitude: lat, longitude: lng } = pos.coords;
                            map.setView([lat, lng], 15);
                            ensureMarker(lat, lng);
                            reverseGeocode(lat, lng);
                            setTimeout(() => map.invalidateSize(), 100);
                        }, () => alert('Unable to retrieve your location'));
                    });
                }

                // Live geocoding when typing location (Nominatim)
                const locInput = document.getElementById('location');
                let geoDebounce = null;
                let lastQuery = '';
                async function geocode(q) {
                    try {
                        const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q);
                        const resp = await fetch(url, { headers: { 'Accept-Language': 'en' } });
                        const results = await resp.json();
                        if (Array.isArray(results) && results.length > 0) {
                            const lat = parseFloat(results[0].lat);
                            const lon = parseFloat(results[0].lon);
                            map.setView([lat, lon], 14);
                            ensureMarker(lat, lon);
                        }
                    } catch (err) {
                        // best-effort; ignore errors
                    }
                }
                if (locInput) {
                    locInput.addEventListener('input', () => {
                        const q = String(locInput.value || '').trim();
                        if (!q || q.length < 3) return;
                        if (q === lastQuery) return;
                        lastQuery = q;
                        if (geoDebounce) clearTimeout(geoDebounce);
                        geoDebounce = setTimeout(() => geocode(q), 500);
                    });
                }

                // Recalculate size on window resize
                window.addEventListener('resize', () => map.invalidateSize());
            };

            const ensureLeafletThenInit = () => {
                if (typeof L !== 'undefined') { init(); return; }
                // Try loading Leaflet from unpkg as a fallback
                const fallback = document.createElement('script');
                fallback.src = 'https://unpkg.com/leaflet@1.10.2/dist/leaflet.js';
                fallback.onload = () => init();
                fallback.onerror = () => {
                    // Secondary fallback to older version if needed
                    const secondary = document.createElement('script');
                    secondary.src = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js';
                    secondary.onload = () => init();
                    document.head.appendChild(secondary);
                };
                document.head.appendChild(fallback);
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', ensureLeafletThenInit);
            } else {
                ensureLeafletThenInit();
            }
        })();

        // Modal creation and view handler
        (function setupViewModal() {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'studioViewModal';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="modal-title" id="modalTitle">Studio Details</div>
                        <button class="close-btn" onclick="closeStudioModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <div id="modalBody">
                        <div class="view-identity" style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                            <div class="view-avatar">
                                <img id="modalAvatarImg" alt="Studio Image" style="width:64px;height:64px;border-radius:50%;object-fit:cover;display:none;" />
                                <div id="modalAvatarInitial" class="letter-avatar" style="width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#232323;color:#eee;font-weight:600;font-size:1.2rem;">S</div>
                            </div>
                            <div>
                                <div id="modalName" style="font-weight:600;">—</div>
                            </div>
                        </div>
                        <div style="margin-bottom: 12px;">
                            <strong>Location:</strong> <span id="modalLocation">—</span><br/>
                            <strong>Hours:</strong> <span id="modalHours">—</span><br/>
                            <strong>Coordinates:</strong> <span id="modalCoords">—</span>
                        </div>
                        <div style="margin-top:16px;">
                            <h4 style="margin-bottom:8px;">Documents</h4>
                            <div id="modalDocs" class="doc-list"></div>
                        </div>
                        <div style="margin-top:16px;">
                            <h4 style="margin-bottom:8px;">Services</h4>
                            <div id="modalServices" class="chip-list"></div>
                        </div>
                        <div style="margin-top:16px;">
                            <h4 style="margin-bottom:8px;">Instructors</h4>
                            <div id="modalInstructors" class="instructor-list"></div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            // Scoped visual polish for view modal
            const viewStyle = document.createElement('style');
            viewStyle.textContent = `
                #studioViewModal .modal-content { border-radius: 14px; box-shadow: 0 10px 28px rgba(0,0,0,0.5); padding: 22px; background: linear-gradient(160deg, #161616 0%, #1c1c1c 100%); border: 1px solid var(--border-color,#333); }
                #studioViewModal .modal-header { padding-bottom: 8px; border-bottom: 1px solid var(--border-color,#333); margin-bottom: 14px; }
                #studioViewModal .modal-title { color: var(--netflix-white,#fff); }
                #studioViewModal #modalBody strong { color: var(--netflix-white,#fff); }
                #studioViewModal #modalBody span { color: var(--netflix-light-gray,#bbb); }
                #studioViewModal .doc-list { display: grid; grid-template-columns: 1fr; gap: 8px; }
                #studioViewModal .doc-list > div { display: grid; grid-template-columns: auto 1fr; gap: 10px; align-items: center; background: #131313; border: 1px solid #2a2a2a; border-radius: 10px; padding: 10px 12px; }
                #studioViewModal .doc-badge { background:#232323; border: 1px solid #2f2f2f; color:#e5e5e5; border-radius:8px; padding:4px 8px; font-size:0.85rem; }
                #studioViewModal .doc-list a { color: var(--primary-color,#e50914); font-weight: 600; }
                #studioViewModal .chip-list { display: flex; flex-wrap: wrap; gap: 8px; }
                #studioViewModal .chip { background:#232323; border: 1px solid #2f2f2f; color:#e5e5e5; border-radius:999px; padding:6px 10px; font-size:0.9rem; }
                #studioViewModal .instructor-list { display: grid; grid-template-columns: 1fr; gap: 8px; }
                #studioViewModal .instructor-item { background:#131313; border: 1px solid #2a2a2a; border-radius: 10px; padding: 10px 12px; color:#e5e5e5; }
            `;
            document.head.appendChild(viewStyle);
            // Document preview modal and helpers removed

            window.closeStudioModal = function() {
                document.getElementById('studioViewModal').classList.remove('active');
            }

            window.viewStudio = async function(studioId) {
                try {
                    // Build URL against current page to avoid relative path issues
                    const base = new URL(window.location.href);
                    const reqUrl = new URL(base.pathname, base.origin);
                    reqUrl.searchParams.set('ajax', 'studio_details');
                    reqUrl.searchParams.set('studio_id', String(studioId));
                    const resp = await fetch(reqUrl.toString(), { credentials: 'same-origin' });
                    // Handle non-JSON or unauthorized responses gracefully
                    const contentType = resp.headers.get('content-type') || '';
                    if (!resp.ok || !contentType.includes('application/json')) {
                        let msg = 'Failed to load studio details';
                        try {
                            if (contentType.includes('application/json')) {
                                const errData = await resp.json();
                                msg = errData.message || msg;
                            } else if (resp.status === 401) {
                                msg = 'Session expired. Please log in again.';
                            }
                        } catch (_) {}
                        console.error('viewStudio fetch failed:', resp.status, contentType);
                        alert(msg);
                        return;
                    }
                    // Read raw text first so we can surface parse errors
                    const raw = await resp.text();
                    let data;
                    try {
                        data = JSON.parse(raw);
                    } catch (err) {
                        console.error('viewStudio JSON parse error:', err, raw);
                        alert('Server returned invalid data for studio details.');
                        return;
                    }
                    if (!data.success) {
                        alert(data.message || 'Failed to load studio details');
                        return;
                    }
                    const s = data.studio;
                    document.getElementById('modalTitle').textContent = s.StudioName || 'Studio Details';
                    document.getElementById('modalName').textContent = s.StudioName || '—';
                    document.getElementById('modalLocation').textContent = s.Loc_Desc || '—';
                    const fmtTime = (t) => {
                        try {
                            const d = new Date(`1970-01-01T${t}`);
                            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                        } catch (_) { return t || '—'; }
                    };
                    document.getElementById('modalHours').textContent = `${fmtTime(s.Time_IN)} - ${fmtTime(s.Time_OUT)}`;
                    document.getElementById('modalCoords').textContent = (s.Latitude && s.Longitude) ? `${s.Latitude}, ${s.Longitude}` : '—';

                    // Resolve StudioImg for avatar
                    const resolveImgSrc = (raw) => {
                        const val = String(raw || '').trim();
                        if (!val) return '';
                        if (/^data:/i.test(val)) return val;
                        if (/^https?:\/\//i.test(val)) return val;
                        return val.startsWith('/') ? val : `/${val}`;
                    };
                    const imgEl = document.getElementById('modalAvatarImg');
                    const initEl = document.getElementById('modalAvatarInitial');
                    let imgSrc = '';
                    if (s.StudioImgBase64 && String(s.StudioImgBase64).length > 0) {
                        imgSrc = String(s.StudioImgBase64);
                    } else if (s.StudioImg && /\.(jpg|jpeg|png|webp)$/i.test(String(s.StudioImg))) {
                        imgSrc = resolveImgSrc(s.StudioImg);
                    }
                    if (imgSrc) {
                        imgEl.src = imgSrc;
                        imgEl.style.display = 'block';
                        initEl.style.display = 'none';
                    } else {
                        const initial = String(s.StudioName || 'S').trim().charAt(0).toUpperCase();
                        initEl.textContent = initial || 'S';
                        initEl.style.display = 'flex';
                        imgEl.style.display = 'none';
                        imgEl.removeAttribute('src');
                    }

                    const docsContainer = document.getElementById('modalDocs');
                    docsContainer.innerHTML = '';
                    if (Array.isArray(data.documents) && data.documents.length) {
                        data.documents.forEach(doc => {
                            const type = doc.document_type ? String(doc.document_type).replace(/_/g, ' ') : 'Document';
                            const fileName = doc.file_name || 'File';
                            const filePath = doc.file_path || '';
                            const row = document.createElement('div');
                            row.style.display = 'flex';
                            row.style.alignItems = 'center';
                            row.style.gap = '8px';
                            row.innerHTML = `
                                <span class="doc-badge">${type}</span>
                                ${filePath ? `<a href="/${filePath}" target="_blank" rel="noopener">${fileName}</a>` : `${fileName}`}
                            `;
                            docsContainer.appendChild(row);
                        });
                    } else {
                        docsContainer.textContent = 'No documents uploaded.';
                    }

                    // Services
                    const servicesContainer = document.getElementById('modalServices');
                    servicesContainer.innerHTML = '';
                    if (Array.isArray(data.services_assigned) && data.services_assigned.length) {
                        data.services_assigned.forEach(svc => {
                            const chip = document.createElement('div');
                            chip.className = 'chip';
                            chip.textContent = svc.ServiceType || 'Service';
                            servicesContainer.appendChild(chip);
                        });
                    } else {
                        servicesContainer.textContent = 'No services assigned.';
                    }

                    // Instructors
                    const instructorsContainer = document.getElementById('modalInstructors');
                    instructorsContainer.innerHTML = '';
                    if (Array.isArray(data.instructors_assigned) && data.instructors_assigned.length) {
                        data.instructors_assigned.forEach(ins => {
                            const item = document.createElement('div');
                            item.className = 'instructor-item';
                            const prof = ins.Profession ? ` — ${ins.Profession}` : '';
                            item.textContent = `${ins.Name || 'Instructor'}${prof}`;
                            instructorsContainer.appendChild(item);
                        });
                    } else {
                        instructorsContainer.textContent = 'No instructors assigned.';
                    }

                    document.getElementById('studioViewModal').classList.add('active');
                } catch (e) {
                    console.error(e);
                    alert('Error loading studio details');
                }
            }
        })();

        // Modal creation and edit handler
        (function setupEditModal() {
            const modal = document.createElement('div');
            modal.className = 'modal-overlay';
            modal.id = 'studioEditModal';
            modal.innerHTML = `
                <div class="modal-content">
                    <div class="modal-header">
                        <div class="modal-title" id="editModalTitle">Edit Studio</div>
                        <button class="close-btn" onclick="closeEditStudioModal()"><i class="fas fa-times"></i></button>
                    </div>
                    <div id="editModalBody">
                        <div class="tabbar" id="editTabBar" style="display:flex;gap:8px;border-bottom:1px solid var(--border-color,#333);margin-bottom:12px;">
                            <button type="button" class="tab active" data-target="panelStudio" style="background:transparent;border:none;padding:8px 12px;border-radius:10px 10px 0 0;cursor:pointer;color:var(--text-color,#ddd);">Studio</button>
                            <button type="button" class="tab" data-target="panelDocs" style="background:transparent;border:none;padding:8px 12px;border-radius:10px 10px 0 0;cursor:pointer;color:var(--text-color,#ddd);">Documents</button>
                            <button type="button" class="tab" data-target="panelUpload" style="background:transparent;border:none;padding:8px 12px;border-radius:10px 10px 0 0;cursor:pointer;color:var(--text-color,#ddd);">Upload</button>
                        </div>

                        <div id="panelStudio" class="tab-panel active" style="display:block;">
                            <div class="form-grid" style="margin-bottom:0.5rem;">
                                <div class="form-group full-width">
                                    <label class="form-label">Studio Name *</label>
                                    <input type="text" class="form-control" id="editStudioName" placeholder="Studio name">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Opening Time</label>
                                    <select class="form-control" id="editTimeIn">
                                        ${Array.from({length:24}, (_,h)=>`<option value="${String(h).padStart(2,'0')}:00">${new Date(`1970-01-01T${String(h).padStart(2,'0')}:00`).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit'})}</option>`).join('')}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Closing Time</label>
                                    <select class="form-control" id="editTimeOut">
                                        ${Array.from({length:24}, (_,h)=>`<option value="${String(h).padStart(2,'0')}:00">${new Date(`1970-01-01T${String(h).padStart(2,'0')}:00`).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit'})}</option>`).join('')}
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label class="form-label">Initial Deposit Percentage 
                                        <span style="font-size:0.85em;color:#888;">(Booking Down Payment)</span>
                                    </label>
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <input type="number" class="form-control" id="editDepositPercentage" 
                                               min="0" max="100" step="0.1" value="25.0" 
                                               style="max-width:120px;"
                                               placeholder="25.0">
                                        <span style="font-size:0.9em;color:#aaa;">%</span>
                                    </div>
                                    <small style="display:block;margin-top:4px;color:#888;font-size:0.85em;">
                                        Percentage of total booking amount clients pay upfront (0-100%). Recommended: 20-50%
                                    </small>
                                </div>
                                <div class="form-group center" style="display:flex;justify-content:center;align-items:center;padding:8px 0;">
                                    <!-- Circular avatar like client_profile.php -->
                                    <div id="studioAvatar" style="width:120px;height:120px;min-width:120px;min-height:120px;border-radius:50%;
                                        background: linear-gradient(135deg, var(--primary-color,#e50914), var(--accent-color,#8e44ad));
                                        color:#fff;display:flex;align-items:center;justify-content:center;font-size:48px;font-weight:700;
                                        box-shadow: 0 8px 20px rgba(0,0,0,0.35);border:3px solid rgba(255,255,255,0.12);overflow:hidden;position:relative;flex-shrink:0;">
                                        <img id="studioImgPreviewImg" src="" alt="Studio image preview" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:none;">
                                        <div id="studioAvatarInitial" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
                                            <!-- Initial letter fallback -->
                                            <span id="studioAvatarInitialText" style="line-height:1;">S</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                        <label class="form-label">Profile Image</label>
                                        <div style="display:flex;gap:10px;align-items:center;margin:6px 0 8px;">
                                            <select id="editImgAction" class="form-control" style="max-width:220px;">
                                                <option value="keep" selected>Keep current</option>
                                                <option value="upload">Upload new</option>
                                            </select>
                                        </div>
                                        <input type="file" class="form-control" id="editStudioImg" accept="image/*,.jpg,.jpeg,.png,.webp" disabled>
                                        <small style="color:#aaa;display:block;margin-top:4px;">Accepted: JPG, PNG, WEBP. Max 10MB.</small>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group full-width location-map-section" id="locationMapSection" style="margin-bottom:0.75rem;">
                                <div class="form-group" style="margin-bottom:8px;">
                                    <label class="form-label">Location *</label>
                                    <input type="text" class="form-control" id="editStudioLocation" placeholder="Location">
                                </div>
                                <label class="form-label">Adjust Coordinates</label>
                                <div id="editStudioMap" style="width:100%;height:260px;border:1px solid var(--border-color,#333);border-radius:12px;overflow:hidden;"></div>
                                <div class="coords-row" style="margin-top:8px;display:flex;gap:10px;align-items:flex-end;">
                                    <div class="form-group">
                                        <label class="form-label" for="editLatitudeDisp">Latitude</label>
                                        <input type="text" class="form-control" id="editLatitudeDisp" readonly>
                                        <input type="hidden" id="editLatitude">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="editLongitudeDisp">Longitude</label>
                                        <input type="text" class="form-control" id="editLongitudeDisp" readonly>
                                        <input type="hidden" id="editLongitude">
                                    </div>
                                    <button type="button" class="geo-btn" id="editUseMyLocation"><i class="fas fa-location-crosshairs"></i> Use my location</button>
                                </div>
                            </div>
                            <div id="studioActionButtons" style="display:flex;gap:10px;justify-content:flex-end;margin-top:12px;">
                                <button class="btn btn-secondary" id="editCancelBtn">Cancel</button>
                                <button class="btn" id="editSaveBtn"><i class="fas fa-save"></i> Save Changes</button>
                            </div>
                        </div>

                        <div id="panelDocs" class="tab-panel" style="display:none;">
                            <div id="editDocs"></div>
                        </div>

                        <div id="panelUpload" class="tab-panel" style="display:none;">
                            <div id="uploadToolbar" style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:8px;">
                                <button class="btn btn-secondary" id="addUploadRowBtn" title="Add another upload" style="padding:10px 14px;"><i class="fas fa-plus"></i></button>
                            </div>
                            <div id="uploadRows"></div>
                            <div id="uploadBottomBar" style="display:flex;justify-content:center;align-items:center;margin-top:12px;">
                                <button class="btn" id="uploadAllBtn"><i class="fas fa-upload"></i> Upload All</button>
                            </div>
                            <p style="font-size:0.9rem;color:#999;margin-top:8px;">Accepted: JPG, PNG, PDF, DOC, DOCX</p>
                        </div>

                        
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            // Inject minimal styles for tabs scoped to this modal
            const style = document.createElement('style');
            style.textContent = `
                /* Modal shell */
                #studioEditModal .modal-content { border-radius: 14px; box-shadow: 0 10px 28px rgba(0,0,0,0.5); padding: 24px; background: linear-gradient(160deg, #161616 0%, #1c1c1c 100%); border: 1px solid var(--border-color,#333); }
                #studioEditModal .modal-header { padding-bottom: 8px; border-bottom: 1px solid var(--border-color,#333); margin-bottom: 16px; }
                #studioEditModal .modal-title { color: var(--netflix-white,#fff); }

                /* Tab bar */
                #studioEditModal .tabbar { gap: 10px; border-bottom: 1px solid var(--border-color,#333); }
                #studioEditModal .tab { color: var(--text-secondary,#aaa); border: 1px solid transparent; padding: 10px 14px; border-radius: 10px; transition: all .18s ease; }
                #studioEditModal .tab:hover { background: #181818; color: var(--netflix-white,#fff); border-color: var(--border-color,#333); }
                #studioEditModal .tab.active { background: #0f0f0f; color: var(--netflix-white,#fff); border-color: var(--primary-color,#e50914); font-weight: 600; }

                /* Form controls */
                #studioEditModal .form-control { background: #141414; color: var(--netflix-white,#fff); border-color: var(--border-color,#333); }
                #studioEditModal .form-control:focus { border-color: var(--primary-color,#e50914); box-shadow: 0 0 0 2px rgba(229,9,20,0.25); }

                /* Coordinates row */
                #studioEditModal .coords-row { gap: 12px; }
                #studioEditModal .geo-btn { background: #171717; border-color: var(--border-color,#333); color: var(--netflix-white,#fff); }
                
                /* Location/Map Section - only visible in Studio tab */
                #studioEditModal .location-map-section { transition: opacity 0.2s ease; }

                /* Documents table */
                #studioEditModal #editDocs { border: 1px solid var(--border-color,#333); border-radius: 12px; overflow: hidden; background: #131313; }
#studioEditModal .doc-table { width:100%; border-collapse: separate; border-spacing:0; table-layout: fixed; }
#studioEditModal .doc-table thead th { position: sticky; top: 0; background: #191919; color: var(--netflix-white,#fff); text-align: left; font-weight: 600; border-bottom: 1px solid #2a2a2a; }
#studioEditModal .doc-table tbody tr td { border-bottom: 1px solid #2a2a2a; }
#studioEditModal .doc-table th, #studioEditModal .doc-table td { padding: 10px 12px; vertical-align: middle; }
#studioEditModal .doc-table tr:hover td { background: rgba(255,255,255,0.03); }
#studioEditModal .doc-table .file-cell a { display:inline-block; max-width:100%; white-space: normal; word-break: break-word; overflow-wrap: anywhere; color: var(--primary-color,#e50914); }
#studioEditModal .doc-table .action-cell { gap:8px; }
#studioEditModal .doc-table .type-cell select { width:100%; max-width:220px; }
/* Pending row highlight */
#studioEditModal .doc-table tbody tr.doc-pending td { border-bottom-color: var(--primary-color,#e50914); background: rgba(229,9,20,0.08); }
/* Footer for update button */
#studioEditModal .doc-actions-footer { position: sticky; bottom: 0; background: #0f0f0f; padding: 10px 12px; border-top: 1px solid #2a2a2a; display:flex; justify-content:flex-end; }
                #studioEditModal .doc-badge { display:inline-block; padding:4px 8px; background:#232323; border: 1px solid #2f2f2f; border-radius:8px; font-size:0.85rem; color:#e5e5e5; }

                /* Buttons */
                #studioEditModal .btn { border-radius: 10px; }
                #studioEditModal .btn-secondary { background: #1c1c1c; color: #fff; border-color: var(--border-color,#333); }
                #studioEditModal .btn-secondary:hover { background: #222; }
                #studioEditModal .btn-danger { background: #b00020; }
                #studioEditModal .btn-sm { padding: 8px 12px; font-size: 0.85rem; }

                
            `;
            document.head.appendChild(style);

            // Tab switching setup
            const tabs = modal.querySelectorAll('#editTabBar .tab');
            const panels = modal.querySelectorAll('#studioEditModal .tab-panel');
            const setActive = (targetId) => {
                tabs.forEach(t => t.classList.toggle('active', t.getAttribute('data-target') === targetId));
                panels.forEach(p => p.style.display = (p.id === targetId ? 'block' : 'none'));
                const contentEl = modal.querySelector('.modal-content');
                if (contentEl) {
                    if (targetId === 'panelDocs') {
                        contentEl.style.maxWidth = '1100px';
                        contentEl.style.width = '95%';
                    } else {
                        contentEl.style.maxWidth = '800px';
                        contentEl.style.width = '90%';
                    }
                }
                // Control visibility of location/map section based on active tab
                const locationMapSection = document.getElementById('locationMapSection');
                if (locationMapSection) {
                    // Only show location and map in Studio (Edit) tab, hide in Documents and Upload tabs
                    locationMapSection.style.display = (targetId === 'panelStudio') ? 'block' : 'none';
                }
                // Control visibility of action buttons (Cancel/Save Changes)
                const actionButtons = document.getElementById('studioActionButtons');
                if (actionButtons) {
                    // Only show action buttons in Studio (Edit) tab, hide in Documents and Upload tabs
                    actionButtons.style.display = (targetId === 'panelStudio') ? 'flex' : 'none';
                }
                if (targetId === 'panelStudio' && editMap) {
                    setTimeout(() => editMap.invalidateSize(), 100);
                }
            };
            tabs.forEach(tab => tab.addEventListener('click', () => setActive(tab.getAttribute('data-target'))));

            

            let currentStudioId = null;
            let editMap = null;
            let editMarker = null;

            function initEditMap(lat, lng) {
                const mapEl = document.getElementById('editStudioMap');
                if (!mapEl) return;
                if (editMap) { editMap.remove(); editMap = null; }
                editMap = L.map('editStudioMap').setView([lat || 14.5995, lng || 120.9842], (lat && lng) ? 14 : 11);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(editMap);
                if (lat && lng) { editMarker = L.marker([lat, lng], { draggable: true }).addTo(editMap); }
                const setCoords = (la, lo) => {
                    document.getElementById('editLatitude').value = la;
                    document.getElementById('editLongitude').value = lo;
                    document.getElementById('editLatitudeDisp').value = la;
                    document.getElementById('editLongitudeDisp').value = lo;
                };
                let editReverseCtrl = null;
                async function reverseGeocodeEdit(la, lo) {
                    try {
                        if (editReverseCtrl) { try { editReverseCtrl.abort(); } catch (_) {} }
                        editReverseCtrl = new AbortController();
                        const url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=' + encodeURIComponent(la) + '&lon=' + encodeURIComponent(lo);
                        const resp = await fetch(url, { headers: { 'Accept-Language': 'en' }, signal: editReverseCtrl.signal });
                        const data = await resp.json();
                        const name = (data && data.display_name) ? String(data.display_name) : '';
                        const editLocEl = document.getElementById('editStudioLocation');
                        if (name && editLocEl) { editLocEl.value = name; }
                    } catch (e) { /* ignore */ }
                }
                function ensureEditMarker(la, lo) {
                    const latNum = Number(la);
                    const lngNum = Number(lo);
                    if (editMarker) {
                        editMarker.setLatLng([latNum, lngNum]);
                    } else {
                        editMarker = L.marker([latNum, lngNum], { draggable: true }).addTo(editMap);
                        editMarker.on('dragend', (e) => {
                            const pos = e.target.getLatLng();
                            setCoords(pos.lat.toFixed(6), pos.lng.toFixed(6));
                            reverseGeocodeEdit(pos.lat, pos.lng);
                        });
                        editMarker.on('click', (e) => {
                            const pos = e.target.getLatLng();
                            setCoords(pos.lat.toFixed(6), pos.lng.toFixed(6));
                            reverseGeocodeEdit(pos.lat, pos.lng);
                        });
                    }
                    setCoords(latNum.toFixed(6), lngNum.toFixed(6));
                }
                if (lat && lng) { setCoords(Number(lat).toFixed(6), Number(lng).toFixed(6)); }
                editMap.on('click', (e) => {
                    const { lat: la, lng: lo } = e.latlng;
                    ensureEditMarker(la, lo);
                    reverseGeocodeEdit(la, lo);
                });
                const btn = document.getElementById('editUseMyLocation');
                if (btn) {
                    btn.onclick = () => {
                        if (!navigator.geolocation) { alert('Geolocation not supported'); return; }
                        navigator.geolocation.getCurrentPosition(pos => {
                            const { latitude: la, longitude: lo } = pos.coords;
                            editMap.setView([la, lo], 15);
                            ensureEditMarker(la, lo);
                            reverseGeocodeEdit(la, lo);
                            setTimeout(() => editMap.invalidateSize(), 100);
                        }, () => alert('Unable to retrieve your location'));
                    };
                }
                // Live geocoding when typing edit location
                const editLoc = document.getElementById('editStudioLocation');
                if (editLoc && !editLoc.__geoAttached) {
                    editLoc.__geoAttached = true;
                    let debounce = null;
                    let lastQ = '';
                    const geocodeEdit = async (q) => {
                        try {
                            const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q);
                            const resp = await fetch(url, { headers: { 'Accept-Language': 'en' } });
                            const results = await resp.json();
                            if (Array.isArray(results) && results.length > 0) {
                                const la = parseFloat(results[0].lat);
                                const lo = parseFloat(results[0].lon);
                                editMap.setView([la, lo], 14);
                                ensureEditMarker(la, lo);
                            }
                        } catch (e) { /* ignore */ }
                    };
                    editLoc.addEventListener('input', () => {
                        const q = String(editLoc.value || '').trim();
                        if (!q || q.length < 3) return;
                        if (q === lastQ) return;
                        lastQ = q;
                        if (debounce) clearTimeout(debounce);
                        debounce = setTimeout(() => geocodeEdit(q), 500);
                    });
                }
                setTimeout(() => editMap.invalidateSize(), 100);
                setTimeout(() => editMap.invalidateSize(), 600);
                window.addEventListener('resize', () => editMap && editMap.invalidateSize());
            }

            // Track staged changes for documents
            const pendingDocReplacements = new Map(); // docId -> File
            const pendingDocTypeChanges = new Map(); // docId -> new type
            const originalDocTypes = new Map(); // docId -> original type

            function labelForMime(m) {
                const mime = String(m || '').toLowerCase();
                if (mime.includes('pdf')) return 'PDF';
                if (mime.includes('image/') || mime.includes('jpeg') || mime.includes('png')) return 'Image';
                if (mime.includes('msword') || mime.includes('word') || mime.includes('officedocument')) return 'Word';
                if (!mime) return 'Document';
                return mime;
            }

            function updateDocsUpdateButtonState() {
                const btn = document.getElementById('updateDocsBtn');
                if (!btn) return;
                const changed = pendingDocReplacements.size > 0 || pendingDocTypeChanges.size > 0;
                btn.disabled = !changed;
            }

            function renderDocs(docs, enumOptions) {
                const container = document.getElementById('editDocs');
                container.innerHTML = '';
                if (!Array.isArray(docs) || docs.length === 0) {
                    container.textContent = 'No documents uploaded.';
                    return;
                }
                const table = document.createElement('table');
                table.className = 'doc-table';
                const colgroup = document.createElement('colgroup');
                colgroup.innerHTML = '<col style="width:33.33%"><col style="width:33.33%"><col style="width:33.33%">';
                table.appendChild(colgroup);
                const thead = document.createElement('thead');
                thead.innerHTML = '<tr><th>Type</th><th>File</th><th>Actions</th></tr>';
                const tbody = document.createElement('tbody');
                docs.forEach(doc => {
                    originalDocTypes.set(parseInt(doc.document_id,10), String(doc.document_type || 'other'));
                    const tr = document.createElement('tr');
                    const typeSelId = `docType_${doc.document_id}`;
                    const optionsHtml = Array.isArray(enumOptions) && enumOptions.length
                        ? enumOptions.map(v => `<option value="${v}" ${doc.document_type===v?'selected':''}>${String(v).replace(/_/g,' ')}</option>`).join('')
                        : [
                            'business_permit','dti_registration','bir_certificate','mayors_permit','id_proof','other'
                          ].map(v => `<option value="${v}" ${doc.document_type===v?'selected':''}>${v.replace(/_/g,' ')}</option>`).join('');
                    tr.innerHTML = `
                        <td class="type-cell">
                            <span class="doc-badge" style="margin-right:6px;">${labelForMime(doc.mime_type)}</span>
                            <select class="form-control" id="${typeSelId}" style="max-width:220px;">${optionsHtml}</select>
                        </td>
                        <td class="file-cell">
                            ${doc.file_path ? `<a id="fileName_${doc.document_id}" href="/${doc.file_path}" target="_blank" rel="noopener">${doc.file_name || 'File'}</a>` : `<span id="fileName_${doc.document_id}">${doc.file_name || 'File'}</span>`}
                        </td>
                        <td class="action-cell">
                            <button class="btn btn-info btn-sm" data-doc="${doc.document_id}" data-action="view">View</button>
                            <button class="btn btn-secondary btn-sm" data-doc="${doc.document_id}" data-action="replace">Replace</button>
                            <button class="btn btn-danger btn-sm" data-doc="${doc.document_id}" data-action="delete">Delete</button>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
                table.appendChild(thead);
                table.appendChild(tbody);
                container.appendChild(table);
                // Enable click-to-preview in a new tab when a staged replacement exists
                container.querySelectorAll('[id^="fileName_"]').forEach(el => {
                    el.addEventListener('click', (ev) => {
                        const idStr = String(el.id || '').split('_')[1];
                        const docId = parseInt(idStr, 10);
                        const staged = pendingDocReplacements.get(docId);
                        if (staged) {
                            ev.preventDefault();
                            const url = URL.createObjectURL(staged);
                            const a = document.createElement('a');
                            a.href = url;
                            a.target = '_blank';
                            a.rel = 'noopener';
                            document.body.appendChild(a);
                            a.click();
                            a.remove();
                            setTimeout(() => { try { URL.revokeObjectURL(url); } catch(_){} }, 30000);
                        }
                    });
                });

                // Footer with staged update button
                const footer = document.createElement('div');
                footer.className = 'doc-actions-footer';
                footer.setAttribute('style', 'display:flex;justify-content:flex-end;margin-top:12px;');
                footer.innerHTML = `<button id="updateDocsBtn" class="btn btn-primary">Update Documents</button>`;
                container.appendChild(footer);
                updateDocsUpdateButtonState();

                // Wire type changes to staged map
                container.querySelectorAll('select[id^="docType_"]').forEach(sel => {
                    sel.addEventListener('change', () => {
                        const docId = parseInt(sel.id.replace('docType_',''), 10);
                        const newType = sel.value || 'other';
                        const original = originalDocTypes.get(docId);
                        if (newType === original && !pendingDocReplacements.has(docId)) {
                            pendingDocTypeChanges.delete(docId);
                            const tr = sel.closest('tr');
                            if (tr) tr.classList.remove('doc-pending');
                        } else {
                            pendingDocTypeChanges.set(docId, newType);
                            const tr = sel.closest('tr');
                            if (tr) tr.classList.add('doc-pending');
                        }
                        updateDocsUpdateButtonState();
                    });
                });

                // Attach actions
                container.querySelectorAll('button[data-action]').forEach(btn => {
                    btn.onclick = async (e) => {
                        const docId = parseInt(btn.getAttribute('data-doc'), 10);
                        const action = btn.getAttribute('data-action');
                        if (action === 'delete') {
                            if (!confirm('Delete this document?')) return;
                            const form = new FormData();
                            form.append('ajax', 'document_delete');
                            form.append('document_id', String(docId));
                            const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                            const data = await resp.json();
                            if (!data.success) { alert(data.message || 'Delete failed'); return; }
                            // Reload docs
                            viewDocsOnly(currentStudioId);
                        } else if (action === 'view') {
                            // Open staged file if available; otherwise open existing link
                            const staged = pendingDocReplacements.get(docId);
                            if (staged) {
                                const url = URL.createObjectURL(staged);
                                const a = document.createElement('a');
                                a.href = url;
                                a.target = '_blank';
                                a.rel = 'noopener';
                                document.body.appendChild(a);
                                a.click();
                                a.remove();
                                setTimeout(() => { try { URL.revokeObjectURL(url); } catch(_){} }, 30000);
                            } else {
                                const link = document.getElementById(`fileName_${docId}`);
                                if (link && link.tagName.toLowerCase() === 'a') {
                                    link.click();
                                } else {
                                    alert('No file available to view.');
                                }
                            }
                        } else if (action === 'replace') {
                            const input = document.createElement('input');
                            input.type = 'file';
                            input.accept = '.jpg,.jpeg,.png,.pdf,.doc,.docx';
                            input.onchange = async () => {
                                if (!input.files || input.files.length === 0) return;
                                const newFile = input.files[0];
                                // Stage replacement and preview filename ONLY; upload on Update button
                                pendingDocReplacements.set(docId, newFile);
                                const sel = document.getElementById(`docType_${docId}`);
                                const tr = sel ? sel.closest('tr') : btn.closest('tr');
                                const link = document.getElementById(`fileName_${docId}`);
                                if (link) link.textContent = newFile.name;
                                if (tr) tr.classList.add('doc-pending');
                                updateDocsUpdateButtonState();
                            };
                            input.click();
                        }
                    };
                });

                // Handle staged update submission
                const updateBtn = document.getElementById('updateDocsBtn');
                if (updateBtn && !updateBtn.__wired) {
                    updateBtn.__wired = true;
                    updateBtn.addEventListener('click', async () => {
                        const docIds = new Set([
                            ...Array.from(pendingDocReplacements.keys()),
                            ...Array.from(pendingDocTypeChanges.keys()),
                        ]);
                        if (docIds.size === 0) return;
                        updateBtn.disabled = true;
                        try {
                            for (const docId of docIds) {
                                const file = pendingDocReplacements.get(docId);
                                const newType = pendingDocTypeChanges.get(docId);
                                if (file) {
                                    const form = new FormData();
                                    form.append('ajax', 'document_replace');
                                    form.append('document_id', String(docId));
                                    if (newType) form.append('document_type', newType);
                                    form.append('file', file);
                                    const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                                    const data = await resp.json();
                                    if (!data.success) { throw new Error(data.message || 'Replace failed'); }
                                } else if (newType) {
                                    const form = new FormData();
                                    form.append('ajax', 'document_update');
                                    form.append('document_id', String(docId));
                                    form.append('document_type', newType);
                                    const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                                    const data = await resp.json();
                                    if (!data.success) { throw new Error(data.message || 'Update failed'); }
                                }
                            }
                            // Clear staged changes and refresh
                            pendingDocReplacements.clear();
                            pendingDocTypeChanges.clear();
                            alert('Documents updated successfully');
                            viewDocsOnly(currentStudioId);
                        } catch (err) {
                            alert(err.message || String(err));
                            updateBtn.disabled = false;
                        }
                    });
                }
            }

            

            async function viewDocsOnly(studioId) {
                const resp = await fetch(`manage_studio.php?ajax=studio_details&studio_id=${studioId}`, { credentials: 'same-origin' });
                const data = await resp.json();
                if (data && data.success) { renderDocs(data.documents || [], data.document_type_enum || []); }
            }

            window.closeEditStudioModal = function() {
                document.getElementById('studioEditModal').classList.remove('active');
            };

            window.editStudio = async function(studioId) {
                try {
                    const base = new URL(window.location.href);
                    const reqUrl = new URL(base.pathname, base.origin);
                    reqUrl.searchParams.set('ajax', 'studio_details');
                    reqUrl.searchParams.set('studio_id', String(studioId));
                    const resp = await fetch(reqUrl.toString(), { credentials: 'same-origin' });
                    const contentType = resp.headers.get('content-type') || '';
                    if (!resp.ok || !contentType.includes('application/json')) {
                        let msg = 'Failed to load studio';
                        try {
                            if (contentType.includes('application/json')) {
                                const errData = await resp.json();
                                msg = errData.message || msg;
                            } else if (resp.status === 401) {
                                msg = 'Session expired. Please log in again.';
                            }
                        } catch (_) {}
                        console.error('editStudio fetch failed:', resp.status, contentType);
                        alert(msg);
                        return;
                    }
                    const raw = await resp.text();
                    let data;
                    try {
                        data = JSON.parse(raw);
                    } catch (err) {
                        console.error('editStudio JSON parse error:', err, raw);
                        alert('Server returned invalid data while loading studio for edit.');
                        return;
                    }
                    if (!data.success) { alert(data.message || 'Failed to load studio'); return; }
                    const s = data.studio;
                    currentStudioId = studioId;
                    document.getElementById('editModalTitle').textContent = s.StudioName ? `${s.StudioName}` : 'Edit Studio';
                    document.getElementById('editStudioName').value = s.StudioName || '';
                    document.getElementById('editStudioLocation').value = s.Loc_Desc || '';
                    const toHHMM = (t) => {
                        if (!t) return '';
                        const m = String(t).match(/^(\d{2}):(\d{2})/);
                        return m ? `${m[1]}:${m[2]}` : String(t).slice(0,5);
                    };
                    document.getElementById('editTimeIn').value = toHHMM(s.Time_IN) || '06:00';
                    document.getElementById('editTimeOut').value = toHHMM(s.Time_OUT) || '22:00';
                    document.getElementById('editDepositPercentage').value = s.deposit_percentage ? parseFloat(s.deposit_percentage).toFixed(1) : '25.0';
                    const imgField = document.getElementById('editStudioImg');
                    if (imgField) { imgField.value = ''; }
                    const previewImg = document.getElementById('studioImgPreviewImg');
                    const avatarInitialWrap = document.getElementById('studioAvatarInitial');
                    const avatarInitialText = document.getElementById('studioAvatarInitialText');
                    const actionSelect = document.getElementById('editImgAction');
                    // Normalize relative paths (e.g., 'uploads/...') to site-root paths
                    const resolveImgSrc = (raw) => {
                        const val = String(raw || '').trim();
                        if (!val) return '';
                        if (/^data:/i.test(val)) return val;
                        if (/^https?:\/\//i.test(val)) return val;
                        return val.startsWith('/') ? val : `/${val}`;
                    };
                    // Helpers to show either image or initial
                    const showAvatarImage = (src) => {
                        if (!previewImg) return;
                        if (src) {
                            previewImg.src = src;
                            previewImg.style.display = 'block';
                            if (avatarInitialWrap) avatarInitialWrap.style.display = 'none';
                        } else {
                            previewImg.removeAttribute('src');
                            previewImg.style.display = 'none';
                            if (avatarInitialWrap) avatarInitialWrap.style.display = 'flex';
                        }
                    };
                    const showAvatarInitial = (name) => {
                        const initial = String(name || '').trim() ? String(name).trim().charAt(0).toUpperCase() : 'S';
                        if (avatarInitialText) avatarInitialText.textContent = initial;
                        if (avatarInitialWrap) avatarInitialWrap.style.display = 'flex';
                        if (previewImg) { previewImg.style.display = 'none'; previewImg.removeAttribute('src'); }
                    };
                    // Show current stored profile image (path or base64) or initial
                    let originalSrc = '';
                    if (s.StudioImgBase64 && String(s.StudioImgBase64).length > 0) {
                        originalSrc = String(s.StudioImgBase64);
                    } else if (s.StudioImg && /\.(jpg|jpeg|png|webp)$/i.test(String(s.StudioImg))) {
                        originalSrc = resolveImgSrc(s.StudioImg);
                    }
                    if (originalSrc) {
                        showAvatarImage(originalSrc);
                    } else {
                        showAvatarInitial(s.StudioName);
                    }
                    if (previewImg) { previewImg.dataset.originalSrc = originalSrc; }
                    // Initialize dropdown selection and enable/disable file input
                    const setImgActionUI = (act) => {
                        if (!imgField) return;
                        if (act === 'upload') {
                            imgField.disabled = false;
                        } else {
                            imgField.disabled = true;
                            imgField.value = '';
                            // Restore original avatar when keeping current
                            const src = previewImg ? (previewImg.dataset.originalSrc || '') : '';
                            if (src) { showAvatarImage(src); } else { showAvatarInitial(s.StudioName); }
                        }
                    };
                    if (actionSelect) {
                        const defaultAct = (previewImg && previewImg.style.display === 'block') ? 'keep' : 'upload';
                        actionSelect.value = defaultAct;
                        actionSelect.onchange = () => setImgActionUI(actionSelect.value);
                        setImgActionUI(defaultAct);
                    }
                    // When uploading new, update avatar preview to selected file
                    if (imgField) {
                        imgField.onchange = () => {
                            if (!imgField.files || !imgField.files[0]) return;
                            const act = actionSelect ? actionSelect.value : 'keep';
                            if (act !== 'upload') return;
                            const file = imgField.files[0];
                            if (!/^image\//.test(file.type)) return;
                            const src = URL.createObjectURL(file);
                            showAvatarImage(src);
                        };
                    }
                    // Pre-fill coordinates in the edit form
                    document.getElementById('editLatitude').value = s.Latitude || '';
                    document.getElementById('editLongitude').value = s.Longitude || '';
                    document.getElementById('editLatitudeDisp').value = s.Latitude || '';
                    document.getElementById('editLongitudeDisp').value = s.Longitude || '';
                    document.getElementById('studioEditModal').classList.add('active');
                    initEditMap(parseFloat(s.Latitude), parseFloat(s.Longitude));
                    let lastData = data;
                    renderDocs(lastData.documents || [], lastData.document_type_enum || []);

                    

                    // Wire buttons
                    const cancelBtn = document.getElementById('editCancelBtn');
                    const saveBtn = document.getElementById('editSaveBtn');
                    const addRowBtn = document.getElementById('addUploadRowBtn');
                    const uploadAllBtn = document.getElementById('uploadAllBtn');
                    const uploadRows = document.getElementById('uploadRows');
                    if (cancelBtn) cancelBtn.onclick = () => closeEditStudioModal();
                    if (saveBtn) saveBtn.onclick = async () => {
                        const form = new FormData();
                        form.append('ajax', 'studio_update');
                        form.append('studio_id', String(studioId));
                        form.append('studio_name', document.getElementById('editStudioName').value);
                        form.append('location', document.getElementById('editStudioLocation').value);
                        form.append('time_in', document.getElementById('editTimeIn').value);
                        form.append('time_out', document.getElementById('editTimeOut').value);
                        form.append('deposit_percentage', document.getElementById('editDepositPercentage').value);
                        const imgInput = document.getElementById('editStudioImg');
                        const imgAction = (document.getElementById('editImgAction')?.value) || 'keep';
                        form.append('img_action', imgAction);
                        if (imgAction === 'upload' && imgInput && imgInput.files && imgInput.files[0]) {
                            form.append('studio_img', imgInput.files[0]);
                        }
                        form.append('latitude', document.getElementById('editLatitude').value);
                        form.append('longitude', document.getElementById('editLongitude').value);
                        const resp2 = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                        const data2 = await resp2.json();
                        if (!data2.success) { alert(data2.message || 'Save failed'); return; }
                        // Update card values on page without reload
                        const cards = document.querySelectorAll('.studio-card');
                        cards.forEach(card => {
                            const btn = card.querySelector(`button[onclick="editStudio(${studioId})"]`);
                            if (btn) {
                                const nameEl = card.querySelector('.studio-name');
                                const locEl = card.querySelector('.studio-location');
                                const hoursEl = card.querySelector('.studio-hours');
                                if (nameEl) nameEl.textContent = data2.studio.StudioName || nameEl.textContent;
                                if (locEl) {
                                    const icon = locEl.querySelector('i');
                                    locEl.textContent = data2.studio.Loc_Desc || '';
                                    if (icon) locEl.prepend(icon);
                                }
                                if (hoursEl) {
                                    const tIn = data2.studio.Time_IN || s.Time_IN;
                                    const tOut = data2.studio.Time_OUT || s.Time_OUT;
                                    hoursEl.innerHTML = `<i class="fas fa-clock"></i> ${new Date(`1970-01-01T${tIn}`).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})} - ${new Date(`1970-01-01T${tOut}`).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})}`;
                                }
                            }
                        });
                        alert('Studio updated');
                        closeEditStudioModal();
                    };
                    let uploadRowCounter = 0;
                    function addUploadRow() {
                        uploadRowCounter += 1;
                        const rowId = uploadRowCounter;
                        const row = document.createElement('div');
                        row.className = 'form-grid upload-row';
                        row.setAttribute('data-row', String(rowId));
                        row.setAttribute('style', 'display:flex;gap:10px;align-items:flex-end;');
                        // Build document type options from ENUM provided in studio_details
                        const enumTypes = (typeof lastData !== 'undefined' && Array.isArray(lastData.document_type_enum)) ? lastData.document_type_enum : [];
                        let orderedTypes = enumTypes.slice();
                        const otherIdx = orderedTypes.indexOf('other');
                        if (otherIdx > -1) {
                            orderedTypes.splice(otherIdx, 1);
                            orderedTypes.unshift('other');
                        }
                        if (orderedTypes.length === 0) { orderedTypes = ['other']; }
                        const typeOptionsHtml = orderedTypes.map(t => `<option value="${t}" ${t === 'other' ? 'selected' : ''}>${t}</option>`).join('');
                        row.innerHTML = `
                            <div class="form-group" style="flex:0 0 230px;">
                                <label class="form-label">New Document Type</label>
                                <select class="form-control" id="docType_${rowId}">
                                    ${typeOptionsHtml}
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label class="form-label">File</label>
                                <input type="file" class="form-control" id="docFile_${rowId}" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                            </div>
                            <div class="form-group" style="display:flex;align-items:flex-end;">
                                <button class="btn btn-danger btn-sm removeRowBtn" data-row="${rowId}" title="Remove row">-</button>
                            </div>
                        `;
                        uploadRows.appendChild(row);
                    }
                    if (addRowBtn) addRowBtn.onclick = () => addUploadRow();
                    // create initial row
                    addUploadRow();
                    // Remove-row delegation
                    if (uploadRows) uploadRows.addEventListener('click', (ev) => {
                        const btn = ev.target.closest && ev.target.closest('.removeRowBtn');
                        if (!btn) return;
                        const rowEl = btn.closest && btn.closest('.upload-row');
                        if (rowEl) rowEl.remove();
                    });

                    // Single Upload All button to upload all rows
                    if (uploadAllBtn) uploadAllBtn.onclick = async () => {
                        const rows = Array.from(uploadRows.querySelectorAll('.upload-row'));
                        const uploads = [];
                        for (const row of rows) {
                            const rowId = row.getAttribute('data-row');
                            const typeEl = document.getElementById(`docType_${rowId}`);
                            const fileEl = document.getElementById(`docFile_${rowId}`);
                            if (fileEl && fileEl.files && fileEl.files.length > 0) {
                                const type = typeEl ? typeEl.value : 'other';
                                const form = new FormData();
                                form.append('ajax', 'document_add');
                                form.append('studio_id', String(studioId));
                                form.append('document_type', type);
                                form.append('file', fileEl.files[0]);
                                uploads.push({ form, fileEl, row });
                            }
                        }
                        if (uploads.length === 0) { alert('Choose a file in at least one row'); return; }
                        let successCount = 0;
                        let failMsgs = [];
                        for (const u of uploads) {
                            try {
                                const resp = await fetch('manage_studio.php', { method: 'POST', body: u.form, credentials: 'same-origin' });
                                const data = await resp.json();
                                if (data && data.success) {
                                    successCount += 1;
                                    // Remove the uploaded row immediately
                                    if (u.row && u.row.remove) { u.row.remove(); }
                                } else {
                                    failMsgs.push(String((data && data.message) || 'Upload failed'));
                                }
                            } catch (e) {
                                failMsgs.push('Network error during upload');
                            }
                        }
                        if (successCount > 0) {
                            alert('Upload Complete');
                            viewDocsOnly(studioId);
                        }
                        if (failMsgs.length > 0) {
                            alert('Some uploads failed:\n' + failMsgs.join('\n'));
                        }
                        // Reset upload area to a single fresh row after uploads
                        if (uploadRows) {
                            uploadRows.innerHTML = '';
                            addUploadRow();
                        }
                    };
                } catch (e) {
                    console.error(e);
                    alert('Error loading studio for edit');
                }
            };
        })();
    </script>
    <script>
        (function setupAddStudioModal() {
            const existingFormContainer = document.querySelector('.form-panel .form-container');
            if (!existingFormContainer) return;

            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'addStudioModal';
            overlay.innerHTML = `
                <div class="modal fade-in">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title"><i class="fas fa-plus"></i> Add New Studio</h2>
                            <button class="close-btn" aria-label="Close">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-warning" style="margin-bottom: 12px;">
                                <i class="fas fa-info-circle"></i>
                                Note: New studios require document uploads for processing another studio.
                            </div>
                            <div id="addStudioModalBody"></div>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(overlay);

            const modalBody = overlay.querySelector('#addStudioModalBody');
            const closeBtn = overlay.querySelector('.close-btn');
            const originalParent = existingFormContainer.parentElement;

            function openAddModal() {
                modalBody.appendChild(existingFormContainer);
                overlay.classList.add('active');
                setTimeout(() => { try { window.dispatchEvent(new Event('resize')); } catch (_) {} }, 100);
            }
            function closeAddModal() {
                overlay.classList.remove('active');
                if (originalParent) originalParent.appendChild(existingFormContainer);
            }

            // Wire buttons
            const headerBtn = document.getElementById('addNewStudioBtn');
            const emptyBtn = document.getElementById('addNewStudioBtnEmpty');
            if (headerBtn) headerBtn.addEventListener('click', openAddModal);
            if (emptyBtn) emptyBtn.addEventListener('click', openAddModal);
            if (closeBtn) closeBtn.addEventListener('click', closeAddModal);

            // Close on overlay click outside modal content
            overlay.addEventListener('click', (ev) => {
                const content = overlay.querySelector('.modal-content');
                if (!content) return;
                if (!content.contains(ev.target)) closeAddModal();
            });
            // Expose for programmatic control
            window.openAddStudioModal = openAddModal;
            window.closeAddStudioModal = closeAddModal;
        })();

        // Standalone Offerings modal (Assign Services and Instructors)
        (function setupOfferingsModal() {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.id = 'studioOfferingsModal';
            overlay.innerHTML = `
                <div class="modal fade-in">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h2 class="modal-title"><i class="fas fa-users-gear"></i> Assign Services and Instructors</h2>
                            <button class="close-btn" aria-label="Close" onclick="closeOfferingsModal()">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="subtabbar" id="offeringsTabs" style="display:flex;gap:8px;border-bottom:1px solid var(--border-color,#333);margin-bottom:12px;">
                                <button type="button" class="subtab active" data-target="offeringsServicesPanel">Services</button>
                                <button type="button" class="subtab" data-target="offeringsInstructorsPanel">Instructors</button>
                            </div>

                            <div id="offeringsServicesPanel" class="subtab-panel" style="display:block;">
                                <div class="section-header small">
                                    <h4 class="section-title" style="margin:0;">Assigned Services</h4>
                                </div>
                                <div id="offeringsServicesAssigned" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
                                <hr style="border-color:#2a2a2a;margin:14px 0;"/>
                                <div class="section-header small" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                                    <h4 class="section-title" style="margin:0;">Available Services</h4>
                                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                        <input type="text" id="servicesSearchInput" placeholder="Search services..." style="padding:6px 10px;border-radius:8px;border:1px solid #2a2a2a;background:#121212;color:#e5e5e5;min-width:220px;" />
                                        <div style="display:flex;gap:8px;align-items:center;">
                                            <span style="color:#cfcfcf;">Price ₱<span id="servicesPriceMinLabel">100</span> to ₱<span id="servicesPriceMaxLabel">5000</span></span>
                                            <div class="services-slider-wrap">
                                                <div class="services-range-base"></div>
                                                <div id="servicesRangeFill" class="services-range-fill"></div>
                                                <input type="range" id="servicesPriceMin" min="100" max="5000" step="50" value="100" />
                                                <input type="range" id="servicesPriceMax" min="100" max="5000" step="50" value="5000" />
                                                <div id="servicesPriceMinBubble" class="services-price-bubble">₱<span id="servicesPriceMinBubbleValue">100</span></div>
                                                <div id="servicesPriceMaxBubble" class="services-price-bubble">₱<span id="servicesPriceMaxBubbleValue">5000</span></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div id="offeringsServicesAvailable" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px;"></div>
                            </div>

                            <div id="offeringsInstructorsPanel" class="subtab-panel" style="display:none;">
                                <div style="margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
                                    <div>
                                        <h4 style="margin:0;">Instructors</h4>
                                        <div id="offeringsInstructorsModeNote" style="font-size:0.9rem;color:#999;margin-top:4px;"></div>
                                    </div>
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <button class="btn btn-secondary" id="offeringsAssignAllBtn" title="Assign all eligible"><i class="fas fa-user-plus"></i> Assign All Eligible</button>
                                        <button class="btn btn-secondary" id="offeringsClearBtn" title="Clear selection"><i class="fas fa-broom"></i> Clear Selection</button>
                                    </div>
                                </div>
                                <div id="offeringsInstructorsAssigned" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
                                <div style="margin-top:10px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                                    <input type="text" id="instructorsSearchInput" placeholder="Search instructors..." style="padding:6px 10px;border-radius:8px;border:1px solid #2a2a2a;background:#121212;color:#e5e5e5;min-width:220px;" />
                                    <div style="display:flex;gap:8px;align-items:center;">
                                        <label style="color:#cfcfcf;">Filter by service:</label>
                                        <select id="instructorsServiceFilter" style="padding:6px 10px;border-radius:8px;border:1px solid #2a2a2a;background:#121212;color:#e5e5e5;">
                                            <option value="">All services</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="section-header small" style="margin-top:10px;">
                                    <h4 class="section-title" style="margin:0;">Available Instructors</h4>
                                </div>
                                <div id="offeringsInstructorsAvailable" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px;"></div>
                            </div>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(overlay);

            // Scoped styles for this modal
            const style = document.createElement('style');
            style.textContent = `
                #studioOfferingsModal.modal-overlay.active { display: block; }
                #studioOfferingsModal .modal-content { border-radius: 14px; box-shadow: 0 10px 28px rgba(0,0,0,0.5); padding: 24px; background: linear-gradient(160deg, #161616 0%, #1c1c1c 100%); border: 1px solid var(--border-color,#333); width: 1200px; max-width: 1200px; margin: 40px auto; }
                #studioOfferingsModal .modal-header { padding-bottom: 8px; border-bottom: 1px solid var(--border-color,#333); margin-bottom: 16px; }
                #studioOfferingsModal .modal-title { color: var(--netflix-white,#fff); }
                #studioOfferingsModal .subtab { color: var(--text-secondary,#aaa); border: 1px solid transparent; padding: 8px 12px; border-radius: 10px; transition: all .18s ease; background: transparent; }
                #studioOfferingsModal .subtab:hover { background: #181818; color: var(--netflix-white,#fff); border-color: var(--border-color,#333); }
                #studioOfferingsModal .subtab.active { background: #0f0f0f; color: var(--netflix-white,#fff); border-color: var(--primary-color,#e50914); font-weight: 600; }
                #studioOfferingsModal .service-chip, #studioOfferingsModal .instructor-chip { display:inline; align-items:center; gap:8px; padding:6px 10px; background:#141414; border:1px solid #2a2a2a; border-radius:999px; color:#e5e5e5; }
                #studioOfferingsModal .service-chip .price { color:#46d369; font-weight:600; }
                #studioOfferingsModal .service-chip button, #studioOfferingsModal .instructor-chip button { background:transparent; border:none; color:#ff6b6b; cursor:pointer; }
                /* Bold red checkbox theme */
                #studioOfferingsModal input.offerings-checkbox { appearance: none; width: 22px; height: 22px; border-radius: 4px; border: 2px solid #b20710; background: #0c0c0c; position: relative; cursor: pointer; }
                #studioOfferingsModal input.offerings-checkbox:hover { box-shadow: 0 0 0 2px rgba(229,9,20,0.25); }
                #studioOfferingsModal input.offerings-checkbox:checked { background: #e50914; border-color: #e50914; }
                #studioOfferingsModal input.offerings-checkbox:checked::after { content: '✓'; position: absolute; color: #fff; font-size: 16px; font-weight: 900; left: 50%; top: 50%; transform: translate(-50%, -55%); }
                /* Consistent grid sizing across tabs */
                #studioOfferingsModal #offeringsServicesAvailable { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)) !important; }
                #studioOfferingsModal #offeringsInstructorsAvailable { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)) !important; }
                /* Dual-slider wrapper for adjustable min/max */
                #studioOfferingsModal .services-slider-wrap { position: relative; width: 260px; height: 26px; display: inline-block; }
                #studioOfferingsModal .services-slider-wrap input[type=range] { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 100%; height: 6px; border-radius: 6px; outline: none; background: transparent; appearance: none; -webkit-appearance: none; pointer-events: none; }
                /* Single lane: align both thumbs on the same track; z-index switches on interaction */
                #studioOfferingsModal #servicesPriceMin { z-index: 3; }
                #studioOfferingsModal #servicesPriceMax { z-index: 4; }
                /* Ensure native range tracks stay transparent across browsers */
                #studioOfferingsModal .services-slider-wrap input[type=range]::-webkit-slider-runnable-track { background: transparent; border: none; }
                #studioOfferingsModal .services-slider-wrap input[type=range]::-moz-range-track { background: transparent; border: none; }
                #studioOfferingsModal .services-slider-wrap input[type=range]::-ms-track { background: transparent; border-color: transparent; color: transparent; }
                /* Thumb styling: bold red circles */
                #studioOfferingsModal #servicesPriceMin::-webkit-slider-thumb, 
                #studioOfferingsModal #servicesPriceMax::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 20px; height: 20px; border-radius: 50%; background: #e50914; border: 2px solid #ffffff; cursor: pointer; pointer-events: auto; }
                #studioOfferingsModal #servicesPriceMax::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 20px; height: 20px; border-radius: 50%; background: #e50914; border: 2px solid #ffffff; cursor: pointer; pointer-events: auto; }
                #studioOfferingsModal #servicesPriceMax::-moz-range-thumb { width: 20px; height: 20px; border-radius: 50%; background: #e50914; border: 2px solid #ffffff; cursor: pointer; pointer-events: auto; }
                #studioOfferingsModal #servicesPriceMin::-moz-range-thumb { width: 20px; height: 20px; border-radius: 50%; background: #e50914; border: 2px solid #ffffff; cursor: pointer; pointer-events: auto; }
                #studioOfferingsModal .services-slider-wrap input[type=range]::-ms-thumb { width: 20px; height: 20px; border-radius: 50%; background: #e50914; border: 2px solid #ffffff; cursor: pointer; pointer-events: auto; }
                #studioOfferingsModal #servicesPriceMax:hover { box-shadow: 0 0 0 2px rgba(229,9,20,0.25); }
                #studioOfferingsModal #servicesPriceMin:hover { box-shadow: 0 0 0 2px rgba(229,9,20,0.25); }
                /* Custom base track and fill */
                #studioOfferingsModal .services-range-base { position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 100%; height: 6px; background: #ffffff; border-radius: 6px; z-index: 1; pointer-events: none; }
                #studioOfferingsModal .services-range-fill { position: absolute; top: 50%; transform: translateY(-50%); height: 6px; background: #e50914; border-radius: 6px; z-index: 2; pointer-events: none; }
                /* Price bubbles */
                #studioOfferingsModal .services-price-bubble { position: absolute; top: -26px; transform: translateX(-50%); background: #0f0f0f; color: #ffffff; border: 1px solid #2a2a2a; border-radius: 8px; padding: 2px 6px; font-size: 12px; white-space: nowrap; z-index: 3; }
                #studioOfferingsModal #servicesPriceMinBubble { left: 0%; }
                #studioOfferingsModal #servicesPriceMaxBubble { left: 100%; }
                #studioOfferingsModal .services-price-bubble.overlap-up { top: -48px; }
            `;
            document.head.appendChild(style);

            // Subtab switching
            const tabbar = overlay.querySelector('#offeringsTabs');
            function setActive(targetId) {
                const panels = ['offeringsServicesPanel','offeringsInstructorsPanel'];
                panels.forEach(id => {
                    const p = overlay.querySelector('#' + id);
                    if (!p) return;
                    p.style.display = (id === targetId) ? 'block' : 'none';
                });
                const tabs = tabbar ? Array.from(tabbar.querySelectorAll('.subtab')) : [];
                tabs.forEach(t => t.classList.toggle('active', t.getAttribute('data-target') === targetId));
            }
            if (tabbar) {
                tabbar.addEventListener('click', (ev) => {
                    const btn = ev.target.closest && ev.target.closest('.subtab');
                    if (!btn) return;
                    const target = btn.getAttribute('data-target');
                    setActive(target);
                });
            }

            let offeringsStudioId = null;
            let lastData = null;

            function offeringsRenderServices(assigned, available) {
                const chipsEl = overlay.querySelector('#offeringsServicesAssigned');
                const availEl = overlay.querySelector('#offeringsServicesAvailable');
                const searchInput = overlay.querySelector('#servicesSearchInput');
                const maxSlider = overlay.querySelector('#servicesPriceMax');
                const minSlider = overlay.querySelector('#servicesPriceMin');
                const maxLabel = overlay.querySelector('#servicesPriceMaxLabel');
                const minLabel = overlay.querySelector('#servicesPriceMinLabel');
                const maxBubble = overlay.querySelector('#servicesPriceMaxBubble');
                const maxBubbleValue = overlay.querySelector('#servicesPriceMaxBubbleValue');
                const minBubble = overlay.querySelector('#servicesPriceMinBubble');
                const minBubbleValue = overlay.querySelector('#servicesPriceMinBubbleValue');
                const rangeFill = overlay.querySelector('#servicesRangeFill');
                if (!chipsEl || !availEl) return;
                const assignedIds = new Set((assigned || []).map(s => String(s.ServiceID)));
                chipsEl.innerHTML = '';
                (assigned || []).forEach(svc => {
                    const chip = document.createElement('div');
                    chip.className = 'service-chip';
                    const price = svc.Price ? ('₱' + parseFloat(svc.Price).toFixed(2)) : '';
                    chip.innerHTML = `<span class="name">${svc.ServiceType}</span> ${price ? `<span class="price">${price}</span>` : ''} <button title="Remove" data-service-id="${svc.ServiceID}"><i class="fas fa-times"></i></button>`;
                    chipsEl.appendChild(chip);
                });
                // Filter available services to only match current studio owner (defensive client-side)
                const studioOwnerId = (lastData && lastData.studio && (lastData.studio.OwnerID || lastData.studio.owner_id)) || null;
                const byId = new Map();
                (available || []).filter(s => !studioOwnerId || String(s.OwnerID || s.owner_id) === String(studioOwnerId)).forEach(svc => byId.set(String(svc.ServiceID), svc));
                (assigned || []).forEach(svc => byId.set(String(svc.ServiceID), svc));
                // Read filters
                const term = (searchInput && searchInput.value || '').toLowerCase();
                let minVal = (minSlider && parseFloat(minSlider.value)) || 100;
                let maxVal = (maxSlider && parseFloat(maxSlider.value)) || 5000;
                if (maxVal < minVal) maxVal = minVal;
                const peso = (v) => {
                    try { return new Intl.NumberFormat('en-PH').format(Math.round(v)); } catch(_) { return String(Math.round(v)); }
                };
                if (maxLabel) maxLabel.textContent = peso(maxVal);
                if (minLabel) minLabel.textContent = peso(minVal);
                if (maxBubbleValue) maxBubbleValue.textContent = peso(maxVal);
                if (minBubbleValue) minBubbleValue.textContent = peso(minVal);
                if (maxBubble && maxSlider) {
                    const min = parseFloat(maxSlider.min || '100');
                    const max = parseFloat(maxSlider.max || '5000');
                    const pctMax = ((maxVal - min) / (max - min)) * 100;
                    const pctMin = ((minVal - min) / (max - min)) * 100;
                    const pctMaxClamped = Math.max(0, Math.min(100, pctMax));
                    const pctMinClamped = Math.max(0, Math.min(100, pctMin));
                    maxBubble.style.left = pctMaxClamped + '%';
                    if (minBubble) minBubble.style.left = pctMinClamped + '%';
                    if (Math.abs(pctMaxClamped - pctMinClamped) < 8) {
                        maxBubble.classList.add('overlap-up');
                    } else {
                        maxBubble.classList.remove('overlap-up');
                    }
                    if (rangeFill) {
                        rangeFill.style.left = pctMinClamped + '%';
                        rangeFill.style.width = Math.max(0, pctMaxClamped - pctMinClamped) + '%';
                    }
                }
                // Render filtered available services
                availEl.innerHTML = '';
                for (const [id, svc] of byId.entries()) {
                    const priceNum = svc.Price ? parseFloat(svc.Price) : 0;
                    const matchesTerm = !term || (svc.ServiceType || '').toLowerCase().includes(term);
                    const matchesPrice = priceNum >= minVal && priceNum <= maxVal;
                    if (!(matchesTerm && matchesPrice)) continue;
                    const price = svc.Price ? ('₱' + priceNum.toFixed(2)) : '';
                    const item = document.createElement('label');
                    item.setAttribute('style', 'display:flex;align-items:center;gap:8px;padding:8px;background:#141414;border:1px solid #2a2a2a;border-radius:10px;');
                    item.innerHTML = `
                        <input type="checkbox" class="offerings-checkbox svc-check" data-service-id="${id}" ${assignedIds.has(String(id)) ? 'checked' : ''}>
                        <span style="font-weight:600;">${svc.ServiceType}${price ? ' — ' + price : ''}</span>
                    `;
                    availEl.appendChild(item);
                }
            }

            function offeringsRenderInstructors(assigned, available, restrictedMode, assignedServicesIds) {
                const chipsEl = overlay.querySelector('#offeringsInstructorsAssigned');
                const availEl = overlay.querySelector('#offeringsInstructorsAvailable');
                const noteEl = overlay.querySelector('#offeringsInstructorsModeNote');
                const searchInput = overlay.querySelector('#instructorsSearchInput');
                const serviceFilter = overlay.querySelector('#instructorsServiceFilter');
                if (!chipsEl || !availEl || !noteEl) return;
                noteEl.textContent = restrictedMode
                    ? 'Restricted mode: only selected instructors are shown to clients.'
                    : 'Automatic mode: all eligible instructors are available to clients.';
                chipsEl.innerHTML = '';
                const assignedIds = new Set((assigned || []).map(i => String(i.InstructorID)));
                (assigned || []).forEach(ins => {
                    const chip = document.createElement('div');
                    chip.className = 'instructor-chip';
                    const allSvcs = Array.isArray(ins.InstructorServices) ? ins.InstructorServices : (Array.isArray(ins.instructor_services) ? ins.instructor_services : []);
                    const svcAllNames = allSvcs.map(s => (typeof s === 'string' ? s : (s.ServiceType || s.name || ''))).join(', ');
                    chip.innerHTML = `<span class="name">${ins.instructor_name || ins.Name || ('Instructor #' + ins.InstructorID)}</span>${svcAllNames ? ` <span class="svc-badge">${svcAllNames}</span>` : ''} <button title="Remove" data-instructor-id="${ins.InstructorID}"><i class="fas fa-times"></i></button>`;
                    chipsEl.appendChild(chip);
                });
                const byId = new Map();
                // Server already enforces OwnerID for eligible instructors; avoid client-side filtering
                (available || []).forEach(ins => byId.set(String(ins.InstructorID), ins));
                (assigned || []).forEach(ins => byId.set(String(ins.InstructorID), ins));
                // Populate service filter options from assigned services (once per render)
                if (serviceFilter) {
                    const curr = new Set(Array.from(serviceFilter.options).map(o => o.value));
                    const assignedSvc = Array.isArray(lastData?.services_assigned) ? lastData.services_assigned : [];
                    let needBuild = (serviceFilter.__builtCount || 0) !== assignedSvc.length;
                    if (needBuild) {
                        serviceFilter.innerHTML = '<option value="">All services</option>';
                        assignedSvc.forEach(s => {
                            const opt = document.createElement('option');
                            opt.value = String(s.ServiceID);
                            opt.textContent = s.ServiceType;
                            serviceFilter.appendChild(opt);
                        });
                        serviceFilter.__builtCount = assignedSvc.length;
                    }
                }
                const term = (searchInput && searchInput.value || '').toLowerCase();
                const serviceId = serviceFilter ? String(serviceFilter.value || '') : '';
                const assignedSvc = Array.isArray(lastData?.services_assigned) ? lastData.services_assigned : [];
                const selectedSvc = serviceId ? assignedSvc.find(s => String(s.ServiceID) === serviceId) : null;
                const selectedSvcName = (selectedSvc && (selectedSvc.ServiceType || '') || '').toLowerCase().trim();
                availEl.innerHTML = '';
                for (const [id, ins] of byId.entries()) {
                    const svcsForStudio = Array.isArray(ins.ServicesForStudio) ? ins.ServicesForStudio : (Array.isArray(ins.overlap_services) ? ins.overlap_services : []);
                    const allSvcs = Array.isArray(ins.InstructorServices) ? ins.InstructorServices : (Array.isArray(ins.instructor_services) ? ins.instructor_services : []);
                    const nameStr = (ins.instructor_name || ins.Name || '').toLowerCase();
                    const profStr = (ins.Profession || '').toLowerCase();
                    const matchesTerm = !term || nameStr.includes(term) || profStr.includes(term);
                    let matchesService = true;
                    if (serviceId) {
                        const source = (allSvcs && allSvcs.length > 0) ? allSvcs : svcsForStudio;
                        matchesService = (source || []).some(s => {
                            const sid = String((s && s.ServiceID) || '');
                            const sname = (typeof s === 'string' ? s : (s && s.ServiceType) || '').toLowerCase().trim();
                            return (sid === serviceId) || (!!selectedSvcName && sname === selectedSvcName);
                        });
                    }
                    if (!(matchesTerm && matchesService)) continue;
                    const item = document.createElement('label');
                    item.setAttribute('style', 'display:flex;align-items:flex-start;gap:8px;padding:8px;background:#141414;border:1px solid #2a2a2a;border-radius:10px;');
                    const svcAllNames = (allSvcs || []).map(s => (typeof s === 'string' ? s : (s.ServiceType || s.name || ''))).join(', ');
                    const svcMarkup = svcAllNames ? `\n                        <div style="font-size:0.9rem;color:#cfcfcf;">${svcAllNames}</div>` : '';
                    item.innerHTML = `
                        <input type="checkbox" class="offerings-checkbox ins-check" data-instructor-id="${id}" ${assignedIds.has(String(id)) ? 'checked' : ''}>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <div style="font-weight:600;">${ins.instructor_name || ins.Name || ('Instructor #' + ins.InstructorID)}</div>${svcMarkup}
                        </div>
                    `;
                    availEl.appendChild(item);
                }
            }

            overlay.addEventListener('click', (ev) => {
                const content = overlay.querySelector('.modal-content');
                if (!content) return;
                if (!content.contains(ev.target)) closeOfferingsModal();
            });

            window.closeOfferingsModal = function() {
                overlay.classList.remove('active');
                offeringsStudioId = null;
                lastData = null;
            };

            window.openOfferingsModal = async function(studioId) {
                try {
                    const base = new URL(window.location.href);
                    const reqUrl = new URL(base.pathname, base.origin);
                    reqUrl.searchParams.set('ajax', 'studio_details');
                    reqUrl.searchParams.set('studio_id', String(studioId));
                    const resp = await fetch(reqUrl.toString(), { credentials: 'same-origin' });
                    const contentType = resp.headers.get('content-type') || '';
                    if (!resp.ok || !contentType.includes('application/json')) {
                        let msg = 'Failed to load studio';
                        try {
                            if (contentType.includes('application/json')) {
                                const errData = await resp.json();
                                msg = errData.message || msg;
                            } else if (resp.status === 401) {
                                msg = 'Session expired. Please log in again.';
                            }
                        } catch (_) {}
                        console.error('openOfferingsModal fetch failed:', resp.status, contentType);
                        alert(msg);
                        return;
                    }
                    const raw = await resp.text();
                    let data;
                    try { data = JSON.parse(raw); } catch (err) { console.error('JSON parse error:', err, raw); alert('Server returned invalid data.'); return; }
                    if (!data.success) { alert(data.message || 'Failed to load studio'); return; }
                    offeringsStudioId = studioId;
                    lastData = data;
                    const assignedServiceIds = new Set((lastData.services_assigned || []).map(s => String(s.ServiceID)));
                    offeringsRenderServices(lastData.services_assigned || [], lastData.services_available || []);
                    offeringsRenderInstructors(lastData.instructors_assigned || [], lastData.instructors_available || [], !!lastData.restricted_mode, assignedServiceIds);
                    overlay.classList.add('active');

                    // Wire service changes
                    const svcAvailEl = overlay.querySelector('#offeringsServicesAvailable');
                    const svcSearchInput = overlay.querySelector('#servicesSearchInput');
                    const svcMaxSlider = overlay.querySelector('#servicesPriceMax');
                    const svcMinSlider = overlay.querySelector('#servicesPriceMin');
                    const svcAssignedEl = overlay.querySelector('#offeringsServicesAssigned');
                    if (svcAvailEl && !svcAvailEl.__wired) {
                        svcAvailEl.__wired = true;
                        svcAvailEl.addEventListener('change', async (ev) => {
                            const cb = ev.target.closest && ev.target.closest('input.svc-check');
                            if (!cb || !offeringsStudioId) return;
                            const sid = cb.getAttribute('data-service-id');
                            const form = new FormData();
                            form.append('ajax', cb.checked ? 'studio_service_assign' : 'studio_service_remove');
                            form.append('studio_id', String(offeringsStudioId));
                            form.append('service_id', String(sid));
                            const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                            const res = await resp.json();
                            if (!res.success) { alert(res.message || 'Update failed'); cb.checked = !cb.checked; return; }
                            const refresh = await fetch(`manage_studio.php?ajax=studio_details&studio_id=${offeringsStudioId}`, { credentials: 'same-origin' });
                            lastData = await refresh.json();
                            const assignedServiceIds2 = new Set((lastData.services_assigned || []).map(s => String(s.ServiceID)));
                            offeringsRenderServices(lastData.services_assigned || [], lastData.services_available || []);
                            offeringsRenderInstructors(lastData.instructors_assigned || [], lastData.instructors_available || [], !!lastData.restricted_mode, assignedServiceIds2);
                        });
                    }
                    // Wire services filters
                    const reRenderServices = () => {
                        offeringsRenderServices(lastData.services_assigned || [], lastData.services_available || []);
                    };
                    if (svcSearchInput && !svcSearchInput.__wired) { svcSearchInput.__wired = true; svcSearchInput.addEventListener('input', reRenderServices); }
                    if (svcMaxSlider && !svcMaxSlider.__wired) {
                        svcMaxSlider.__wired = true;
                        const onMaxInput = () => {
                            const step = parseFloat(svcMaxSlider.step || '1');
                            const min = parseFloat((svcMinSlider && svcMinSlider.value) || '100');
                            let max = parseFloat(svcMaxSlider.value || '5000');
                            if (max <= min + step) { max = min + step; svcMaxSlider.value = String(max); }
                            reRenderServices();
                        };
                        svcMaxSlider.addEventListener('input', onMaxInput);
                        svcMaxSlider.addEventListener('change', onMaxInput);
                    }
                    if (svcMinSlider && !svcMinSlider.__wired) {
                        svcMinSlider.__wired = true;
                        const onMinInput = () => {
                            const step = parseFloat(svcMinSlider.step || '1');
                            let min = parseFloat(svcMinSlider.value || '100');
                            const max = parseFloat((svcMaxSlider && svcMaxSlider.value) || '5000');
                            if (min >= max - step) { min = max - step; svcMinSlider.value = String(min); }
                            reRenderServices();
                        };
                        svcMinSlider.addEventListener('input', onMinInput);
                        svcMinSlider.addEventListener('change', onMinInput);
                    }
                    // Ensure the slider being interacted with stays on top for easier dragging
                    // Ensure the slider being interacted with stays on top for easier dragging (mouse/touch/pointer)
                    const bringToFront = (el, other) => { if (!el) return; el.style.zIndex = '4'; if (other) other.style.zIndex = '3'; };
                    if (svcMaxSlider && !svcMaxSlider.__zwired) {
                        svcMaxSlider.__zwired = true;
                        ['mousedown','touchstart','pointerdown'].forEach(evt => svcMaxSlider.addEventListener(evt, () => bringToFront(svcMaxSlider, svcMinSlider)));
                    }
                    if (svcMinSlider && !svcMinSlider.__zwired) {
                        svcMinSlider.__zwired = true;
                        ['mousedown','touchstart','pointerdown'].forEach(evt => svcMinSlider.addEventListener(evt, () => bringToFront(svcMinSlider, svcMaxSlider)));
                    }
                    if (svcAssignedEl && !svcAssignedEl.__wired) {
                        svcAssignedEl.__wired = true;
                        svcAssignedEl.addEventListener('click', async (ev) => {
                            const btn = ev.target.closest && ev.target.closest('button[data-service-id]');
                            if (!btn || !offeringsStudioId) return;
                            const sid = btn.getAttribute('data-service-id');
                            const form = new FormData();
                            form.append('ajax', 'studio_service_remove');
                            form.append('studio_id', String(offeringsStudioId));
                            form.append('service_id', String(sid));
                            const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                            const res = await resp.json();
                            if (!res.success) { alert(res.message || 'Remove failed'); return; }
                            const refresh = await fetch(`manage_studio.php?ajax=studio_details&studio_id=${offeringsStudioId}`, { credentials: 'same-origin' });
                            lastData = await refresh.json();
                            const assignedServiceIds2 = new Set((lastData.services_assigned || []).map(s => String(s.ServiceID)));
                            offeringsRenderServices(lastData.services_assigned || [], lastData.services_available || []);
                            offeringsRenderInstructors(lastData.instructors_assigned || [], lastData.instructors_available || [], !!lastData.restricted_mode, assignedServiceIds2);
                        });
                    }

                    // Instructors wiring
                    const insAvailEl = overlay.querySelector('#offeringsInstructorsAvailable');
                    const instructorsListEl = overlay.querySelector('#offeringsInstructorsAssigned');
                    const insSearchInput = overlay.querySelector('#instructorsSearchInput');
                    const insServiceFilter = overlay.querySelector('#instructorsServiceFilter');
                    const assignAllBtn = overlay.querySelector('#offeringsAssignAllBtn');
                    const clearBtn = overlay.querySelector('#offeringsClearBtn');
                    if (insAvailEl && !insAvailEl.__wired) {
                        insAvailEl.__wired = true;
                        insAvailEl.addEventListener('change', async (ev) => {
                            const cb = ev.target.closest && ev.target.closest('input.ins-check');
                            if (!cb || !offeringsStudioId) return;
                            const iid = cb.getAttribute('data-instructor-id');
                            const form = new FormData();
                            form.append('ajax', cb.checked ? 'studio_instructor_assign' : 'studio_instructor_remove');
                            form.append('studio_id', String(offeringsStudioId));
                            form.append('instructor_id', String(iid));
                            const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                            const res = await resp.json();
                            if (!res.success) { alert(res.message || 'Update failed'); cb.checked = !cb.checked; return; }
                            const refresh = await fetch(`manage_studio.php?ajax=studio_details&studio_id=${offeringsStudioId}`, { credentials: 'same-origin' });
                            lastData = await refresh.json();
                            const assignedServiceIds2 = new Set((lastData.services_assigned || []).map(s => String(s.ServiceID)));
                            offeringsRenderInstructors(lastData.instructors_assigned || [], lastData.instructors_available || [], !!lastData.restricted_mode, assignedServiceIds2);
                        });
                    }
                    // Wire instructor filters
                    const reRenderInstructors = () => {
                        const assignedServiceIds2 = new Set((lastData.services_assigned || []).map(s => String(s.ServiceID)));
                        offeringsRenderInstructors(lastData.instructors_assigned || [], lastData.instructors_available || [], !!lastData.restricted_mode, assignedServiceIds2);
                    };
                    if (insSearchInput && !insSearchInput.__wired) { insSearchInput.__wired = true; insSearchInput.addEventListener('input', reRenderInstructors); }
                    if (insServiceFilter && !insServiceFilter.__wired) { insServiceFilter.__wired = true; insServiceFilter.addEventListener('change', reRenderInstructors); }
                    if (instructorsListEl && !instructorsListEl.__wired) {
                        instructorsListEl.__wired = true;
                        instructorsListEl.addEventListener('click', async (ev) => {
                            const btn = ev.target.closest && ev.target.closest('button[data-instructor-id]');
                            if (!btn || !offeringsStudioId) return;
                            const iid = btn.getAttribute('data-instructor-id');
                            const form = new FormData();
                            form.append('ajax', 'studio_instructor_remove');
                            form.append('studio_id', String(offeringsStudioId));
                            form.append('instructor_id', String(iid));
                            const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                            const res = await resp.json();
                            if (!res.success) { alert(res.message || 'Remove failed'); return; }
                            const refresh = await fetch(`manage_studio.php?ajax=studio_details&studio_id=${offeringsStudioId}`, { credentials: 'same-origin' });
                            const refreshed = await refresh.json();
                            const assignedServiceIds2 = new Set((refreshed.services_assigned || []).map(s => String(s.ServiceID)));
                            offeringsRenderInstructors(refreshed.instructors_assigned || [], refreshed.instructors_available || [], !!refreshed.restricted_mode, assignedServiceIds2);
                        });
                    }
                    if (assignAllBtn) {
                        assignAllBtn.onclick = async () => {
                            const form = new FormData();
                            form.append('ajax', 'studio_instructor_assign_all');
                            form.append('studio_id', String(offeringsStudioId));
                            const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                            const res = await resp.json();
                            if (!res.success) { alert(res.message || 'Assign-all failed'); return; }
                            const refresh = await fetch(`manage_studio.php?ajax=studio_details&studio_id=${offeringsStudioId}`, { credentials: 'same-origin' });
                            const refreshed = await refresh.json();
                            const assignedServiceIds2 = new Set((refreshed.services_assigned || []).map(s => String(s.ServiceID)));
                            offeringsRenderInstructors(refreshed.instructors_assigned || [], refreshed.instructors_available || [], !!refreshed.restricted_mode, assignedServiceIds2);
                        };
                    }
                    if (clearBtn) {
                        clearBtn.onclick = async () => {
                            const form = new FormData();
                            form.append('ajax', 'studio_instructor_clear');
                            form.append('studio_id', String(offeringsStudioId));
                            const resp = await fetch('manage_studio.php', { method: 'POST', body: form, credentials: 'same-origin' });
                            const res = await resp.json();
                            if (!res.success) { alert(res.message || 'Clear failed'); return; }
                            const refresh = await fetch(`manage_studio.php?ajax=studio_details&studio_id=${offeringsStudioId}`, { credentials: 'same-origin' });
                            const refreshed = await refresh.json();
                            const assignedServiceIds2 = new Set((refreshed.services_assigned || []).map(s => String(s.ServiceID)));
                            offeringsRenderInstructors(refreshed.instructors_assigned || [], refreshed.instructors_available || [], !!refreshed.restricted_mode, assignedServiceIds2);
                        };
                    }
                } catch (e) {
                    console.error(e);
                    alert('Error loading offerings');
                }
            };
        })();
    </script>
</body>
</html>

