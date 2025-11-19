
<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../../../shared/config/mail_config.php';

class Registration {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getAll($filters = [], $limit = null, $offset = null) {
        $sql = "SELECT sr.*,
                       sp.plan_name,
                       au.full_name as approved_by_name,
                       (SELECT COUNT(*) FROM documents WHERE registration_id = sr.registration_id) as document_count,
                       rp.payment_status as actual_payment_status
                FROM studio_registrations sr
                LEFT JOIN subscription_plans sp ON sr.plan_id = sp.plan_id
                LEFT JOIN admin_users au ON sr.approved_by = au.admin_id
                LEFT JOIN registration_payments rp ON sr.registration_id = rp.registration_id
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            // When filtering by 'pending', also include 'payment_submitted' (awaiting admin review)
            if ($filters['status'] === 'pending') {
                $sql .= " AND sr.registration_status IN ('pending', 'payment_submitted')";
            } else {
            $sql .= " AND sr.registration_status = ?";
            $params[] = $filters['status'];
            }
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND sr.submitted_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND sr.submitted_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['business_name'])) {
            $sql .= " AND sr.business_name LIKE ?";
            $params[] = '%' . $filters['business_name'] . '%';
        }
        
        if (!empty($filters['owner_email'])) {
            $sql .= " AND sr.owner_email LIKE ?";
            $params[] = '%' . $filters['owner_email'] . '%';
        }
        
        $sql .= " ORDER BY sr.submitted_at DESC";

        if ($limit !== null && $offset !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }
        
        $stmt = $this->db->prepare($sql);
        
        if ($limit !== null && $offset !== null) {
            $stmt->bindValue(count($params) - 1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(count($params), $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllCount($filters = []) {
        $sql = "SELECT COUNT(*) FROM studio_registrations sr WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            // When filtering by 'pending', also include 'payment_submitted' (awaiting admin review)
            if ($filters['status'] === 'pending') {
                $sql .= " AND sr.registration_status IN ('pending', 'payment_submitted')";
            } else {
            $sql .= " AND sr.registration_status = ?";
            $params[] = $filters['status'];
            }
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND sr.submitted_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND sr.submitted_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['business_name'])) {
            $sql .= " AND sr.business_name LIKE ?";
            $params[] = '%' . $filters['business_name'] . '%';
        }
        
        if (!empty($filters['owner_email'])) {
            $sql .= " AND sr.owner_email LIKE ?";
            $params[] = '%' . $filters['owner_email'] . '%';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT sr.*,
                   sp.plan_name,
                   sp.monthly_price,
                   sp.yearly_price,
                   au.full_name as approved_by_name
            FROM studio_registrations sr
            LEFT JOIN subscription_plans sp ON sr.plan_id = sp.plan_id
            LEFT JOIN admin_users au ON sr.approved_by = au.admin_id
            WHERE sr.registration_id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getPendingCount() {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM studio_registrations WHERE registration_status = 'pending'");
        $stmt->execute();
        return $stmt->fetch()['count'];
    }

    public function getRecentDecisions($limit = 5, $offset = 0) {
        $sql = "
            SELECT 
                sr.registration_id,
                sr.business_name,
                sr.owner_name,
                sr.registration_status,
                sr.approved_at,
                au.full_name as admin_name
            FROM studio_registrations sr
            LEFT JOIN admin_users au ON sr.approved_by = au.admin_id
            WHERE sr.registration_status IN ('approved', 'rejected')
            ORDER BY sr.approved_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function approve($id, $adminId, $note = '') {
        $this->db->beginTransaction();
        try {
            // Get registration details including plan info
            $regStmt = $this->db->prepare("
                SELECT sr.*, sp.plan_name
                FROM studio_registrations sr
                LEFT JOIN subscription_plans sp ON sr.plan_id = sp.plan_id
                WHERE sr.registration_id = ?
            ");
            $regStmt->execute([$id]);
            $registration = $regStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$registration) {
                throw new Exception("Registration not found");
            }
            
            // Update studio_registrations table
            $stmt = $this->db->prepare("
                UPDATE studio_registrations 
                SET registration_status = 'approved', 
                    approved_by = ?, 
                    approved_at = NOW(), 
                    admin_notes = ?
                WHERE registration_id = ?
            ");
            $stmt->execute([$adminId, $note, $id]);
            
            // Update studios table if studio_id exists
            if (!empty($registration['studio_id'])) {
                // UPDATE studios table: approved_by_admin and approved_at
                $studioStmt = $this->db->prepare("
                    UPDATE studios 
                    SET approved_by_admin = ?, 
                        approved_at = NOW()
                    WHERE StudioID = ?
                ");
                $result = $studioStmt->execute([$adminId, $registration['studio_id']]);
                
                if (!$result) {
                    throw new Exception("Failed to update studios table");
                }
                
                $rowsAffected = $studioStmt->rowCount();
                if ($rowsAffected === 0) {
                    error_log("Warning: Studio #{$registration['studio_id']} not found in studios table");
                } else {
                    error_log("✓ Studios table updated: StudioID={$registration['studio_id']}, approved_by_admin={$adminId}, approved_at=NOW()");
                }
                
                // Get OwnerID from studios table
                $ownerIdStmt = $this->db->prepare("SELECT OwnerID FROM studios WHERE StudioID = ?");
                $ownerIdStmt->execute([$registration['studio_id']]);
                $ownerId = $ownerIdStmt->fetchColumn();
                
                if ($ownerId) {
                    // UPDATE studio_owners table: approved_by_admin, approved_at, and subscription dates
                    $planName = strtolower($registration['plan_name'] ?? '');
                    $subscriptionDuration = strtolower($registration['subscription_duration'] ?? 'monthly');
                    $isFree = (stripos($planName, 'free') !== false);
                    
                    if ($isFree) {
                        // FREE plan: both subscription dates are NULL
                        $ownerStmt = $this->db->prepare("
                            UPDATE studio_owners 
                            SET approved_by_admin = ?, 
                                approved_at = NOW(),
                                subscription_start = NULL,
                                subscription_end = NULL
                            WHERE OwnerID = ?
                        ");
                        $result = $ownerStmt->execute([$adminId, $ownerId]);
                        
                        if (!$result) {
                            throw new Exception("Failed to update studio_owners table");
                        }
                        
                        error_log("✓ Studio_owners table updated: OwnerID={$ownerId}, approved_by_admin={$adminId}, approved_at=NOW(), subscription=FREE (NULL dates)");
                    } else {
                        // PAID plan: calculate subscription end date
                        if ($subscriptionDuration === 'monthly') {
                            // Monthly: add 1 month
                            $ownerStmt = $this->db->prepare("
                                UPDATE studio_owners 
                                SET approved_by_admin = ?, 
                                    approved_at = NOW(),
                                    subscription_start = NOW(),
                                    subscription_end = DATE_ADD(NOW(), INTERVAL 1 MONTH)
                                WHERE OwnerID = ?
                            ");
                            $result = $ownerStmt->execute([$adminId, $ownerId]);
                            
                            if (!$result) {
                                throw new Exception("Failed to update studio_owners table");
                            }
                            
                            error_log("✓ Studio_owners table updated: OwnerID={$ownerId}, approved_by_admin={$adminId}, approved_at=NOW(), subscription=MONTHLY (+1 month)");
                        } else {
                            // Yearly/Annual: add 1 year
                            $ownerStmt = $this->db->prepare("
                                UPDATE studio_owners 
                                SET approved_by_admin = ?, 
                                    approved_at = NOW(),
                                    subscription_start = NOW(),
                                    subscription_end = DATE_ADD(NOW(), INTERVAL 1 YEAR)
                                WHERE OwnerID = ?
                            ");
                            $result = $ownerStmt->execute([$adminId, $ownerId]);
                            
                            if (!$result) {
                                throw new Exception("Failed to update studio_owners table");
                            }
                            
                            error_log("✓ Studio_owners table updated: OwnerID={$ownerId}, approved_by_admin={$adminId}, approved_at=NOW(), subscription=YEARLY (+1 year)");
                        }
                    }
                } else {
                    error_log("Warning: Could not find OwnerID for Studio #{$registration['studio_id']}");
                }
                
                error_log("Approval complete: Registration #{$id}, Studio #{$registration['studio_id']}, Plan: {$planName}, Admin: {$adminId}");
            } else {
                error_log("Warning: Registration #{$id} approved but has no studio_id. Studios and studio_owners tables not updated.");
            }
            
            // Send approval email notification
            try {
                $ownerEmail = $registration['owner_email'] ?? '';
                $ownerName = $registration['owner_name'] ?? 'Studio Owner';
                $studioName = $registration['business_name'] ?? 'Your Studio';
                
                if (!empty($ownerEmail)) {
                    $subject = 'Congratulations! Your Studio Registration Has Been Approved';
                    
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'museek.com';
                    $loginUrl = $scheme . '://' . $host . '/auth/php/login.php';
                    
                    $html = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                        <h2 style="color: #28a745;">🎉 Studio Registration Approved!</h2>
                        <p>Dear ' . htmlspecialchars($ownerName) . ',</p>
                        
                        <p>Great news! Your studio registration for <strong>"' . htmlspecialchars($studioName) . '"</strong> has been approved by our admin team.</p>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <h3 style="margin-top: 0; color: #333;">What\'s Next?</h3>
                            <ul style="line-height: 1.8;">
                                <li>Your studio is now live on the Museek platform</li>
                                <li>Users can now discover and book your services</li>
                                <li>Access your studio dashboard to manage bookings, schedules, and more</li>
                            </ul>
                        </div>
                        
                        <div style="text-align: center; margin: 30px 0;">
                            <a href="' . htmlspecialchars($loginUrl) . '" style="display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
                                Access Your Dashboard
                            </a>
                        </div>
                        
                        <p style="color: #666; font-size: 14px;">If you have any questions or need assistance, please don\'t hesitate to contact our support team.</p>
                        
                        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
                        
                        <p style="color: #999; font-size: 12px; text-align: center;">
                            This is an automated message from Museek. Please do not reply to this email.
                        </p>
                    </div>';
                    
                    $altText = "Congratulations! Your studio registration for \"{$studioName}\" has been approved. You can now access your dashboard at: {$loginUrl}";
                    
                    sendTransactionalEmail($ownerEmail, $ownerName, $subject, $html, $altText);
                    error_log("Approval email sent to {$ownerEmail} for Registration #{$id}");
                }
            } catch (Exception $e) {
                error_log("Failed to send approval email: " . $e->getMessage());
                // Don't fail the approval if email fails
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Registration approval error: " . $e->getMessage());
            return false;
        }
    }

    public function reject($id, $adminId, $reason) {
        $this->db->beginTransaction();
        try {
            // Get registration details including studio_id and owner info
            $regStmt = $this->db->prepare("
                SELECT sr.*, s.OwnerID 
                FROM studio_registrations sr
                LEFT JOIN studios s ON sr.studio_id = s.StudioID
                WHERE sr.registration_id = ?
            ");
            $regStmt->execute([$id]);
            $registration = $regStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$registration) {
                throw new Exception("Registration not found");
            }
            
            $studioId = $registration['studio_id'];
            $ownerId = $registration['OwnerID'] ?? null;
            
            // IMPORTANT: Nullify studio_id in registration to avoid foreign key constraint issues
            if ($studioId) {
                $nullifyStmt = $this->db->prepare("UPDATE studio_registrations SET studio_id = NULL WHERE registration_id = ?");
                $nullifyStmt->execute([$id]);
                error_log("Nullified studio_id in registration #{$id} before deletion");
            }
            
            // If studio exists, delete all related records
            if ($studioId) {
                error_log("Rejecting registration #{$id} - Deleting all records for Studio #{$studioId}");
                
                // 1. Get gallery photos for file deletion
                $galleryStmt = $this->db->prepare("SELECT file_path FROM studio_gallery WHERE StudioID = ?");
                $galleryStmt->execute([$studioId]);
                $galleryPhotos = $galleryStmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete gallery photo files from filesystem
                foreach ($galleryPhotos as $photoPath) {
                    $fullPath = __DIR__ . '/../../../' . $photoPath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                        error_log("Deleted gallery photo: {$photoPath}");
                    }
                }
                
                // 2. Delete gallery records
                $deleteGallery = $this->db->prepare("DELETE FROM studio_gallery WHERE StudioID = ?");
                $deleteGallery->execute([$studioId]);
                
                // 3. Delete studio services
                $deleteServices = $this->db->prepare("DELETE FROM studio_services WHERE StudioID = ?");
                $deleteServices->execute([$studioId]);
                
                // 4. Delete instructors
                $deleteInstructors = $this->db->prepare("DELETE FROM instructors WHERE StudioID = ?");
                $deleteInstructors->execute([$studioId]);
                
                // 5. Delete studio_instructors (if exists)
                try {
                    $deleteStudioInstructors = $this->db->prepare("DELETE FROM studio_instructors WHERE StudioID = ?");
                    $deleteStudioInstructors->execute([$studioId]);
                } catch (PDOException $e) {
                    error_log("studio_instructors table might not exist: " . $e->getMessage());
                }
                
                // 6. Delete schedules
                $deleteSchedules = $this->db->prepare("DELETE FROM schedules WHERE StudioID = ?");
                $deleteSchedules->execute([$studioId]);
                
                // 7. Delete bookings
                $deleteBookings = $this->db->prepare("DELETE FROM bookings WHERE StudioID = ?");
                $deleteBookings->execute([$studioId]);
                
                // 8. Delete from studios table
                $deleteStudio = $this->db->prepare("DELETE FROM studios WHERE StudioID = ?");
                $deleteStudio->execute([$studioId]);
                
                error_log("Deleted studio record #{$studioId} and all related data");
            }
            
            // 9. Get documents for file deletion
            $docsStmt = $this->db->prepare("SELECT file_path FROM documents WHERE registration_id = ?");
            $docsStmt->execute([$id]);
            $documents = $docsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Delete document files from filesystem
            foreach ($documents as $docPath) {
                $fullPath = __DIR__ . '/../../../' . $docPath;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                    error_log("Deleted document: {$docPath}");
                }
            }
            
            // 10. Delete documents from database
            $deleteDocs = $this->db->prepare("DELETE FROM documents WHERE registration_id = ?");
            $deleteDocs->execute([$id]);
            
            // 11. Delete document upload tokens
            $deleteTokens = $this->db->prepare("DELETE FROM document_upload_tokens WHERE registration_id = ?");
            $deleteTokens->execute([$id]);
            
            // 12. Delete payment records
            $deletePayments = $this->db->prepare("DELETE FROM registration_payments WHERE registration_id = ?");
            $deletePayments->execute([$id]);
            
            // 13. Delete studio_owner record if exists and no other studios
            if ($ownerId) {
                // Check if owner has other studios
                $otherStudiosStmt = $this->db->prepare("SELECT COUNT(*) FROM studios WHERE OwnerID = ?");
                $otherStudiosStmt->execute([$ownerId]);
                $otherStudios = $otherStudiosStmt->fetchColumn();
                
                if ($otherStudios == 0) {
                    $deleteOwner = $this->db->prepare("DELETE FROM studio_owners WHERE OwnerID = ?");
                    $deleteOwner->execute([$ownerId]);
                    error_log("Deleted owner record #{$ownerId} (no other studios)");
                }
            }
            
            // 14. Send rejection email notification BEFORE deleting registration
            try {
                $ownerEmail = $registration['owner_email'] ?? '';
                $ownerName = $registration['owner_name'] ?? 'Studio Owner';
                $studioName = $registration['business_name'] ?? 'Your Studio';
                
                if (!empty($ownerEmail)) {
                    $subject = 'Studio Registration Update - Action Required';
                    
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'museek.com';
                    $registerUrl = $scheme . '://' . $host . '/auth/php/owner_register.php';
                    
                    $html = '
                    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                        <h2 style="color: #dc3545;">Studio Registration Status Update</h2>
                        <p>Dear ' . htmlspecialchars($ownerName) . ',</p>
                        
                        <p>Thank you for your interest in joining the Museek platform. After careful review, we regret to inform you that your studio registration for <strong>"' . htmlspecialchars($studioName) . '"</strong> could not be approved at this time.</p>
                        
                        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
                            <h3 style="margin-top: 0; color: #856404;">Reason for Disapproval:</h3>
                            <p style="margin: 0; color: #856404;">' . nl2br(htmlspecialchars($reason)) . '</p>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                            <h3 style="margin-top: 0; color: #333;">What Can You Do?</h3>
                            <ul style="line-height: 1.8;">
                                <li>Review the reason provided above</li>
                                <li>Address the issues mentioned</li>
                                <li>Submit a new registration once requirements are met</li>
                                <li>Contact our support team if you need clarification</li>
                            </ul>
                        </div>
                        
                        <div style="text-align: center; margin: 30px 0;">
                            <a href="' . htmlspecialchars($registerUrl) . '" style="display: inline-block; padding: 12px 30px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
                                Submit New Registration
                            </a>
                        </div>
                        
                        <p style="color: #666; font-size: 14px;">We appreciate your understanding and look forward to the possibility of working with you in the future.</p>
                        
                        <hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">
                        
                        <p style="color: #999; font-size: 12px; text-align: center;">
                            This is an automated message from Museek. Please do not reply to this email.<br>
                            For support, contact us at support@museek.com
                        </p>
                    </div>';
                    
                    $altText = "Your studio registration for \"{$studioName}\" could not be approved. Reason: {$reason}. You may submit a new registration at: {$registerUrl}";
                    
                    sendTransactionalEmail($ownerEmail, $ownerName, $subject, $html, $altText);
                    error_log("Rejection email sent to {$ownerEmail} for Registration #{$id}");
                }
            } catch (Exception $e) {
                error_log("Failed to send rejection email: " . $e->getMessage());
                // Don't fail the rejection if email fails
            }
            
            // 15. Finally, nullify the registration record (keep for audit trail)
            // Keep: registration_id, plan_id, admin_notes, rejection_reason
            // Nullify: everything else
            $updateReg = $this->db->prepare("
                UPDATE studio_registrations 
                SET 
                    studio_id = NULL,
                    business_name = NULL,
                    owner_name = NULL,
                    owner_email = NULL,
                    owner_phone = NULL,
                    business_address = NULL,
                    subscription_duration = NULL,
                    registration_status = 'rejected',
                    rejection_reason = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW(),
                    approved_by = NULL,
                    approved_at = NULL,
                    updated_at = NOW()
                WHERE registration_id = ?
            ");
            $updateReg->execute([$reason, $adminId, $id]);
            
            error_log("Registration #{$id} rejected and data nullified by admin #{$adminId}. Reason: {$reason}");

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Registration rejection error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // Throw the exception so we can see the actual error message
            throw new Exception("Rejection failed: " . $e->getMessage());
        }
    }

    public function getDocuments($registrationId) {
        $stmt = $this->db->prepare("
            SELECT * FROM documents 
            WHERE registration_id = ? 
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$registrationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updatePaymentStatus($id, $status) {
        $stmt = $this->db->prepare("
            UPDATE studio_registrations 
            SET payment_status = ?, updated_at = NOW() 
            WHERE registration_id = ?
        ");
        return $stmt->execute([$status, $id]);
    }

    public function bulkApprove($ids, $adminId) {
        $this->db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE studio_registrations 
                    SET registration_status = 'approved', 
                        approved_by = ?, 
                        approved_at = NOW() 
                    WHERE registration_id IN ($placeholders)";
            
            $stmt = $this->db->prepare($sql);
            $params = array_merge([$adminId], $ids);
            $stmt->execute($params);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Bulk approval error: " . $e->getMessage());
            return false;
        }
    }

    public function bulkReject($ids, $adminId, $reason) {
        $this->db->beginTransaction();
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE studio_registrations 
                    SET registration_status = 'rejected', 
                        approved_by = ?, 
                        approved_at = NOW(), 
                        rejection_reason = ? 
                    WHERE registration_id IN ($placeholders)";
            
            $stmt = $this->db->prepare($sql);
            $params = array_merge([$adminId, $reason], $ids);
            $stmt->execute($params);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Bulk rejection error: " . $e->getMessage());
            return false;
        }
    }
}
