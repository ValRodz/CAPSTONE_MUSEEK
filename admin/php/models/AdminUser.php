<?php

require_once __DIR__ . '/../config/database.php';

class AdminUser {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function authenticate($email, $password) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE email = ? AND is_active = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $stored = $user['password_hash'];

                // Temporarily allow plaintext comparison in addition to hashed verification
                $verified = false;
                if (is_string($stored) && $stored !== '') {
                    // Try bcrypt/hashed verification first
                    if (password_verify($password, $stored)) {
                        $verified = true;
                    } else {
                        // Fallback to direct equality for plaintext storage
                        if ($password === $stored) {
                            $verified = true;
                        }
                    }
                }

                if ($verified) {
                    $this->updateLastLogin($user['admin_id']);
                    return $user;
                }
            }
            return false;
        } catch (PDOException $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }

    private function updateLastLogin($userId) {
        $stmt = $this->db->prepare("UPDATE admin_users SET last_login = NOW() WHERE admin_id = ?");
        $stmt->execute([$userId]);
    }

    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM admin_users WHERE admin_id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll($filters = []) {
        $sql = "SELECT admin_id, username, email, full_name, role, is_active, last_login, created_at 
                FROM admin_users WHERE 1=1";
        $params = [];
        
        if (!empty($filters['role'])) {
            $sql .= " AND role = ?";
            $params[] = $filters['role'];
        }
        
        if (isset($filters['is_active'])) {
            $sql .= " AND is_active = ?";
            $params[] = $filters['is_active'];
        }
        
        $sql .= " ORDER BY created_at DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create($username, $email, $password, $fullName, $role = 'admin') {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("
            INSERT INTO admin_users (username, email, password_hash, full_name, role, is_active, created_at) 
            VALUES (?, ?, ?, ?, ?, 1, NOW())
        ");
        return $stmt->execute([$username, $email, $passwordHash, $fullName, $role]);
    }

    public function updateStatus($adminId, $isActive) {
        $stmt = $this->db->prepare("UPDATE admin_users SET is_active = ? WHERE admin_id = ?");
        return $stmt->execute([$isActive, $adminId]);
    }

    public function updatePassword($adminId, $newPassword) {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE admin_users SET password_hash = ? WHERE admin_id = ?");
        return $stmt->execute([$passwordHash, $adminId]);
    }

    public function updateProfile($adminId, $data) {
        $fields = [];
        $params = [];
        
        if (isset($data['full_name'])) {
            $fields[] = "full_name = ?";
            $params[] = $data['full_name'];
        }
        
        if (isset($data['email'])) {
            $fields[] = "email = ?";
            $params[] = $data['email'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $adminId;
        $sql = "UPDATE admin_users SET " . implode(", ", $fields) . " WHERE admin_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
