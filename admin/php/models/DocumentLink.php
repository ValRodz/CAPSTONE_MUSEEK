<?php

require_once __DIR__ . '/../config/database.php';

class DocumentLink {
    private $db;
    
    public function __construct() {
        // FIXED: Use Database singleton instead of undefined getDB()
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Generate a secure upload link for a registration
     */
    public function generateLink($registrationId, $adminId) {
        // Generate a secure token
        $token = bin2hex(random_bytes(32));
        
        // Link expires in 7 days
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        $stmt = $this->db->prepare("
            INSERT INTO document_upload_links (registration_id, token, expires_at, created_by)
            VALUES (?, ?, ?, ?)
            RETURNING id, token
        ");
        $stmt->execute([$registrationId, $token, $expiresAt, $adminId]);
        $result = $stmt->fetch();
        
        // Update registration status
        $stmt = $this->db->prepare("
            UPDATE studio_registrations 
            SET awaiting_documents = true, documents_requested_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$registrationId]);
        
        return $result;
    }
    
    /**
     * Get link details by token (for upload page)
     */
    public function getByToken($token) {
        $stmt = $this->db->prepare("
            SELECT dl.*, sr.studio_id, s.name as studio_name, s.owner_email
            FROM document_upload_links dl
            JOIN studio_registrations sr ON dl.registration_id = sr.id
            JOIN studios s ON sr.studio_id = s.id
            WHERE dl.token = ? AND dl.expires_at > CURRENT_TIMESTAMP AND dl.used_at IS NULL
        ");
        $stmt->execute([$token]);
        return $stmt->fetch();
    }
    
    /**
     * Mark link as used after upload
     */
    public function markAsUsed($token) {
        $stmt = $this->db->prepare("
            UPDATE document_upload_links 
            SET used_at = CURRENT_TIMESTAMP 
            WHERE token = ?
        ");
        return $stmt->execute([$token]);
    }
    
    /**
     * Get latest link for a registration
     */
    public function getByRegistrationId($registrationId) {
        $stmt = $this->db->prepare("
            SELECT * FROM document_upload_links 
            WHERE registration_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$registrationId]);
        return $stmt->fetch();
    }
}