<?php
require_once __DIR__ . '/../config/session.php';
requireLogin();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Studio.php';
require_once __DIR__ . '/../models/AuditLog.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$studioId = (int)($_POST['studio_id'] ?? 0);
$adminId = $_SESSION['admin_id'];

$response = ['success' => false, 'message' => ''];
$studioModel = new Studio();
$auditModel = new AuditLog();

try {
    switch ($action) {
        case 'toggle_status':
            $currentStatus = (int)($_POST['current_status'] ?? 0);
            $newStatus = $currentStatus ? 0 : 1;
            
            if ($studioModel->updateStatus($studioId, $newStatus)) {
                $statusText = $newStatus ? 'activated' : 'deactivated';
                $auditModel->log($adminId, 'studio_status', $studioId, "Studio {$statusText}");
                $response = [
                    'success' => true,
                    'message' => "Studio {$statusText} successfully",
                    'new_status' => $newStatus
                ];
            } else {
                $response['message'] = 'Failed to update studio status';
            }
            break;
            
        case 'update_location':
            $latitude = (float)($_POST['latitude'] ?? 0);
            $longitude = (float)($_POST['longitude'] ?? 0);
            $locDesc = trim($_POST['loc_desc'] ?? '');
            
            if (!$latitude || !$longitude) {
                $response['message'] = 'Invalid coordinates';
                break;
            }
            
            if ($studioModel->updateLocation($studioId, $latitude, $longitude, $locDesc)) {
                $auditModel->log($adminId, 'studio_location', $studioId, "Location updated to: {$locDesc}");
                $response = [
                    'success' => true,
                    'message' => 'Location updated successfully'
                ];
            } else {
                $response['message'] = 'Failed to update location';
            }
            break;
            
        case 'add_service':
            $serviceId = (int)($_POST['service_id'] ?? 0);
            
            if (!$serviceId) {
                $response['message'] = 'Invalid service';
                break;
            }
            
            if ($studioModel->addService($studioId, $serviceId)) {
                $auditModel->log($adminId, 'studio_service', $studioId, "Service #{$serviceId} added");
                $response = [
                    'success' => true,
                    'message' => 'Service added successfully'
                ];
            } else {
                $response['message'] = 'Failed to add service (may already exist)';
            }
            break;
            
        case 'remove_service':
            $serviceId = (int)($_POST['service_id'] ?? 0);
            
            if (!$serviceId) {
                $response['message'] = 'Invalid service';
                break;
            }
            
            if ($studioModel->removeService($studioId, $serviceId)) {
                $auditModel->log($adminId, 'studio_service', $studioId, "Service #{$serviceId} removed");
                $response = [
                    'success' => true,
                    'message' => 'Service removed successfully'
                ];
            } else {
                $response['message'] = 'Failed to remove service';
            }
            break;
            
        case 'update_description':
            $description = trim($_POST['description'] ?? '');
            
            if ($studioModel->update($studioId, ['description' => $description])) {
                $auditModel->log($adminId, 'studio_update', $studioId, "Description updated");
                $response = [
                    'success' => true,
                    'message' => 'Description updated successfully'
                ];
            } else {
                $response['message'] = 'Failed to update description';
            }
            break;
            
        case 'toggle_featured':
            // Get current featured status
            $studio = $studioModel->getById($studioId);
            $currentFeatured = $studio['is_featured'] ?? 0;
            $newFeatured = $currentFeatured ? 0 : 1;
            
            // Update featured status
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("UPDATE studios SET is_featured = ? WHERE StudioID = ?");
            
            if ($stmt->execute([$newFeatured, $studioId])) {
                $statusText = $newFeatured ? 'featured' : 'unfeatured';
                $auditModel->log($adminId, 'studio_featured', $studioId, "Studio {$statusText}");
                $response = [
                    'success' => true,
                    'message' => "Studio {$statusText} successfully",
                    'is_featured' => $newFeatured
                ];
            } else {
                $response['message'] = 'Failed to update featured status';
            }
            break;
            
        default:
            $response['message'] = 'Invalid action';
    }
} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
}

echo json_encode($response);
