<?php

require_once __DIR__ . '/../config/database.php';

class AuditLog {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Log an action
     */
    public function log($adminId, $action, $entityType, $entityId, $description = '', $ipAddress = null, $userAgent = null) {
        // Support legacy call order used across the codebase:
        // log('Admin', $adminId, 'ACTION', 'EntityType', $entityId, 'Description')
        // In that case, the first parameter is an actor type string and the second is the numeric admin ID.
        if (is_string($adminId) && !is_numeric($adminId) && (is_int($action) || (is_string($action) && is_numeric($action)))) {
            $actorType = $adminId;             // e.g., 'Admin'
            $adminId = (int)$action;           // real admin id
            $action = (string)$entityType;     // action string
            $entityType = (string)$entityId;   // entity type string
            // entityId remains as provided (5th arg) if numeric; otherwise default to 0
            $entityId = is_numeric($description) ? (int)$description : (is_numeric($entityId) ? (int)$entityId : 0);
            $description = is_string($description) ? $description : '';
            // Optionally add actor type context to description
            if (!empty($actorType)) {
                $description = "[{$actorType}] " . $description;
            }
        }

        if ($ipAddress === null) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        }
        if ($userAgent === null) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        }
        
        // Normalize numeric fields
        $adminId = is_numeric($adminId) ? (int)$adminId : 0;
        $entityId = is_numeric($entityId) ? (int)$entityId : 0;
        
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, description, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([$adminId, $action, $entityType, $entityId, $description, $ipAddress, $userAgent]);
    }

    /**
     * Get all logs with filters and pagination
     */
    public function getAll($filters = [], $limit = null, $offset = null) {
        $sql = "
            SELECT al.*, au.full_name as admin_name
            FROM audit_logs al
            LEFT JOIN admin_users au ON al.admin_id = au.admin_id
            WHERE 1=1
        ";
        $params = [];
        
        if (!empty($filters['admin_id'])) {
            $sql .= " AND al.admin_id = ?";
            $params[] = $filters['admin_id'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['entity_id'])) {
            $sql .= " AND al.entity_id = ?";
            $params[] = $filters['entity_id'];
        }
        
        $sql .= " ORDER BY al.created_at DESC";

        // Add LIMIT and OFFSET only if provided
        if ($limit !== null && $offset !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        } else {
            // Fallback: limit to 100 if no pagination
            $sql .= " LIMIT 100";
        }
        
        $stmt = $this->db->prepare($sql);

        // Bind LIMIT/OFFSET as integers
        if ($limit !== null && $offset !== null) {
            $stmt->bindValue(count($params) - 1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(count($params), $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count total logs matching filters (for pagination)
     */
    public function getAllCount($filters = []) {
        $sql = "SELECT COUNT(*) FROM audit_logs al WHERE 1=1";
        $params = [];
        
        if (!empty($filters['admin_id'])) {
            $sql .= " AND al.admin_id = ?";
            $params[] = $filters['admin_id'];
        }
        
        if (!empty($filters['action'])) {
            $sql .= " AND al.action = ?";
            $params[] = $filters['action'];
        }
        
        if (!empty($filters['entity_type'])) {
            $sql .= " AND al.entity_type = ?";
            $params[] = $filters['entity_type'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND al.created_at >= ?";
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND al.created_at <= ?";
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        
        if (!empty($filters['entity_id'])) {
            $sql .= " AND al.entity_id = ?";
            $params[] = $filters['entity_id'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}