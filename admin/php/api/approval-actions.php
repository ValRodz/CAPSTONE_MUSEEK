<?php
require_once __DIR__ . '/../config/session.php';
requireLogin();

require_once __DIR__ . '/../models/Registration.php';
require_once __DIR__ . '/../models/Studio.php';
require_once __DIR__ . '/../models/AuditLog.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$registrationId = (int)($_POST['registration_id'] ?? 0);
$adminId = $_SESSION['admin_id'];

$response = ['success' => false, 'message' => ''];
$registrationModel = new Registration();
$studioModel = new Studio();
$auditModel = new AuditLog();

try {
    switch ($action) {
        case 'approve_with_location':
            $latitude = (float)($_POST['latitude'] ?? 0);
            $longitude = (float)($_POST['longitude'] ?? 0);
            $locDesc = trim($_POST['loc_desc'] ?? '');
            $services = $_POST['services'] ?? [];
            
            // Validate coordinates
            if (!$latitude || !$longitude) {
                $response['message'] = 'Please set studio location on map first';
                break;
            }
            
            // Get registration details
            $registration = $registrationModel->getById($registrationId);
            if (!$registration) {
                $response['message'] = 'Registration not found';
                break;
            }
            
            // Start transaction
            $db = Database::getInstance()->getConnection();
            $db->beginTransaction();
            
            try {
                // 1. Create or update studio owner
                $stmt = $db->prepare("
                    INSERT INTO studio_owners (Name, Email, Phone, Address) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                        Name = VALUES(Name), 
                        Phone = VALUES(Phone),
                        Address = VALUES(Address),
                        OwnerID = LAST_INSERT_ID(OwnerID)
                ");
                $stmt->execute([
                    $registration['owner_name'],
                    $registration['owner_email'],
                    $registration['owner_phone'] ?? '',
                    $registration['owner_address'] ?? ''
                ]);
                $ownerId = $db->lastInsertId();
                
                // 2. Create studio
                $stmt = $db->prepare("
                    INSERT INTO studios (StudioName, Loc_Desc, Latitude, Longitude, OwnerID, is_active, approved_by_admin, approved_at)
                    VALUES (?, ?, ?, ?, ?, 1, 1, NOW())
                ");
                $stmt->execute([
                    $registration['business_name'],
                    $locDesc,
                    $latitude,
                    $longitude,
                    $ownerId
                ]);
                $studioId = $db->lastInsertId();
                
                // 3. Add services
                if (!empty($services)) {
                    $stmt = $db->prepare("INSERT INTO studio_services (StudioID, ServiceID) VALUES (?, ?)");
                    foreach ($services as $serviceId) {
                        $stmt->execute([$studioId, (int)$serviceId]);
                    }
                }
                
                // 4. Update registration status
                $stmt = $db->prepare("
                    UPDATE studio_registrations 
                    SET registration_status = 'approved', 
                        reviewed_by = ?,
                        reviewed_at = NOW()
                    WHERE registration_id = ?
                ");
                $stmt->execute([$adminId, $registrationId]);
                
                // 5. Log action
                $auditModel->log($adminId, 'registration', $registrationId, "Registration approved - Studio #{$studioId} created");
                
                $db->commit();
                
                $response = [
                    'success' => true,
                    'message' => 'Studio approved and created successfully',
                    'studio_id' => $studioId
                ];
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'reject_with_reason':
            $reason = trim($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                $response['message'] = 'Please provide a rejection reason';
                break;
            }
            
            // Use the unified rejection method
            try {
                if ($registrationModel->reject($registrationId, $adminId, $reason)) {
                    // Log action
                $auditModel->log($adminId, 'registration', $registrationId, "Registration rejected: {$reason}");
                
                $response = [
                    'success' => true,
                        'message' => 'Registration rejected. All studio data deleted and registration archived with rejection reason.'
                ];
                }
            } catch (Exception $e) {
                // Get the detailed error message
                $response['message'] = $e->getMessage();
                error_log("Rejection error in API: " . $e->getMessage());
            }
            break;
            
        default:
            $response['message'] = 'Invalid action';
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
