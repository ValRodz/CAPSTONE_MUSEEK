<?php

require_once __DIR__ . '/../config/database.php';

class Studio {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Get all studios with filters and pagination
     */
    public function getAll($filters = [], $limit = null, $offset = null) {
        $sql = "
            SELECT 
                s.*,
                o.`Name` as owner_name,
                o.Email as owner_email,
                o.last_login owner_last_login,
                COUNT(DISTINCT ss.StudioServiceID) as services_count, 
                COUNT(DISTINCT i.InstructorID) as instructors_count,
                COUNT(DISTINCT CASE WHEN sc.Sched_Date >= CURDATE() AND sc.Sched_Date < DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN sc.ScheduleID END) as weekly_schedules
            FROM studios s
            LEFT JOIN studio_owners o ON s.OwnerID = o.OwnerID
            LEFT JOIN studio_services ss ON s.StudioID = ss.StudioID
            LEFT JOIN instructors i ON s.OwnerID = i.OwnerID
            LEFT JOIN schedules sc ON s.StudioID = sc.StudioID
            WHERE 1=1
        ";
        
        $params = [];
        
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $sql .= " AND s.is_active = 1";
            } elseif ($filters['status'] === 'inactive') {
                $sql .= " AND s.is_active = 0";
            }
        }
        
        if (!empty($filters['approved'])) {
            if ($filters['approved'] === 'yes') {
                $sql .= " AND s.approved_by_admin = 1";
            } elseif ($filters['approved'] === 'no') {
                $sql .= " AND s.approved_by_admin = 0";
            }
        }
        
        if (!empty($filters['location'])) {
            $sql .= " AND s.Loc_Desc LIKE ?";
            $params[] = '%' . $filters['location'] . '%';
        }
        
        if (!empty($filters['owner'])) {
            $sql .= " AND (o.Name LIKE ? OR o.Email LIKE ?)";
            $params[] = '%' . $filters['owner'] . '%';
            $params[] = '%' . $filters['owner'] . '%';
        }

        if (!empty($filters['studio_name'])) {
            $sql .= " AND s.StudioName LIKE ?";
            $params[] = '%' . $filters['studio_name'] . '%';
        }

        // SUPPORT FOR MAP FILTERS: has_coords
        if (isset($filters['has_coords'])) {
            if ($filters['has_coords'] === true) {
                $sql .= " AND s.Latitude IS NOT NULL AND s.Longitude IS NOT NULL";
            } elseif ($filters['has_coords'] === false) {
                $sql .= " AND (s.Latitude IS NULL OR s.Longitude IS NULL)";
            }
        }
        
        $sql .= " GROUP BY s.StudioID ORDER BY s.approved_at DESC, s.StudioID DESC";

        // Add LIMIT and OFFSET only if provided
        if ($limit !== null && $offset !== null) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
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
     * Count total studios matching filters (for pagination)
     */
    public function getAllCount($filters = []) {
        $sql = "SELECT COUNT(DISTINCT s.StudioID) FROM studios s 
                LEFT JOIN studio_owners o ON s.OwnerID = o.OwnerID
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $sql .= " AND s.is_active = 1";
            } elseif ($filters['status'] === 'inactive') {
                $sql .= " AND s.is_active = 0";
            }
        }
        
        if (!empty($filters['approved'])) {
            if ($filters['approved'] === 'yes') {
                $sql .= " AND s.approved_by_admin = 1";
            } elseif ($filters['approved'] === 'no') {
                $sql .= " AND s.approved_by_admin = 0";
            }
        }
        
        if (!empty($filters['location'])) {
            $sql .= " AND s.Loc_Desc LIKE ?";
            $params[] = '%' . $filters['location'] . '%';
        }
        
        if (!empty($filters['owner'])) {
            $sql .= " AND (o.Name LIKE ? OR o.Email LIKE ?)";
            $params[] = '%' . $filters['owner'] . '%';
            $params[] = '%' . $filters['owner'] . '%';
        }

        if (!empty($filters['studio_name'])) {
            $sql .= " AND s.StudioName LIKE ?";
            $params[] = '%' . $filters['studio_name'] . '%';
        }

        // SUPPORT FOR MAP FILTERS: has_coords
        if (isset($filters['has_coords'])) {
            if ($filters['has_coords'] === true) {
                $sql .= " AND s.Latitude IS NOT NULL AND s.Longitude IS NOT NULL";
            } elseif ($filters['has_coords'] === false) {
                $sql .= " AND (s.Latitude IS NULL OR s.Longitude IS NULL)";
            }
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    public function getById($id) {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   o.Name as owner_name, 
                   o.Email as owner_email,
                   o.Phone as owner_phone,
                   au.full_name as approved_by_name
            FROM studios s
            LEFT JOIN studio_owners o ON s.OwnerID = o.OwnerID
            LEFT JOIN admin_users au ON s.approved_by_admin = au.admin_id
            WHERE s.StudioID = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateStatus($id, $isActive) {
        $stmt = $this->db->prepare("UPDATE studios SET is_active = ? WHERE StudioID = ?");
        return $stmt->execute([$isActive, $id]);
    }

    public function updateLocation($id, $latitude, $longitude, $locDesc) {
        $stmt = $this->db->prepare("
            UPDATE studios 
            SET Latitude = ?, Longitude = ?, Loc_Desc = ? 
            WHERE StudioID = ?
        ");
        return $stmt->execute([$latitude, $longitude, $locDesc, $id]);
    }

    public function approveStudio($id, $adminId) {
        $stmt = $this->db->prepare("
            UPDATE studios 
            SET approved_by_admin = 1, approved_at = NOW() 
            WHERE StudioID = ?
        ");
        return $stmt->execute([$id]);
    }
    
    public function update($id, $data) {
        $fields = [];
        $params = [];
        
        if (isset($data['StudioName'])) {
            $fields[] = "StudioName = ?";
            $params[] = $data['StudioName'];
        }
        if (isset($data['Loc_Desc'])) {
            $fields[] = "Loc_Desc = ?";
            $params[] = $data['Loc_Desc'];
        }
        if (isset($data['Latitude'])) {
            $fields[] = "Latitude = ?";
            $params[] = $data['Latitude'];
        }
        if (isset($data['Longitude'])) {
            $fields[] = "Longitude = ?";
            $params[] = $data['Longitude'];
        }
        if (isset($data['Time_IN'])) {
            $fields[] = "Time_IN = ?";
            $params[] = $data['Time_IN'];
        }
        if (isset($data['Time_OUT'])) {
            $fields[] = "Time_OUT = ?";
            $params[] = $data['Time_OUT'];
        }
        if (isset($data['is_active'])) {
            $fields[] = "is_active = ?";
            $params[] = $data['is_active'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $params[] = $id;
        $sql = "UPDATE studios SET " . implode(", ", $fields) . " WHERE StudioID = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function getServices($studioId) {
        $stmt = $this->db->prepare("
            SELECT s.*, ss.StudioServiceID
            FROM services s
            INNER JOIN studio_services ss ON s.ServiceID = ss.ServiceID
            WHERE ss.StudioID = ?
            ORDER BY s.ServiceType
        ");
        $stmt->execute([$studioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInstructors($studioId) {
        $stmt = $this->db->prepare("
            SELECT i.* 
            FROM instructors i
            INNER JOIN studios s ON i.OwnerID = s.OwnerID
            WHERE s.StudioID = ?
            ORDER BY i.Name
        ");
        $stmt->execute([$studioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSchedules($studioId, $limit = 5) {
        $stmt = $this->db->prepare("
            SELECT 
                sc.*,
                GROUP_CONCAT(DISTINCT i.Name ORDER BY i.Name SEPARATOR ', ') as instructor_name
            FROM schedules sc
            LEFT JOIN bookings b ON b.ScheduleID = sc.ScheduleID
            LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
            LEFT JOIN instructors i ON bsrv.InstructorID = i.InstructorID
            WHERE sc.StudioID = ? AND sc.Sched_Date >= CURDATE()
            GROUP BY sc.ScheduleID
            ORDER BY sc.Sched_Date ASC, sc.Time_Start ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $studioId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBookings($studioId, $limit = 20) {
        // Updated query to work with new schema using booking_services junction table and payment table
        $stmt = $this->db->prepare("
            SELECT 
                b.BookingID,
                b.BookingID as BookingID,
                b.ClientID,
                b.booking_date,
                sc.Time_Start,
                sc.Time_End,
                c.Name as client_name,
                GROUP_CONCAT(DISTINCT sv.ServiceType ORDER BY sv.ServiceType SEPARATOR ', ') as service_name,
                SUM(bsrv.service_price) as Price,
                bs.Book_Stats as booking_status,
                p.Pay_Stats as payment_status,
                p.PaymentID
            FROM bookings b
            LEFT JOIN schedules sc ON sc.ScheduleID = b.ScheduleID
            LEFT JOIN clients c ON b.ClientID = c.ClientID
            LEFT JOIN booking_services bsrv ON b.BookingID = bsrv.BookingID
            LEFT JOIN services sv ON bsrv.ServiceID = sv.ServiceID
            LEFT JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
            LEFT JOIN payment p ON b.BookingID = p.BookingID
            WHERE b.StudioID = ?
            GROUP BY b.BookingID
            ORDER BY b.booking_date DESC, sc.Time_Start DESC
            LIMIT ?
        ");
        
        $stmt->bindValue(1, $studioId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addService($studioId, $serviceId) {
        $stmt = $this->db->prepare("
            INSERT INTO studio_services (StudioID, ServiceID) 
            VALUES (?, ?)
        ");
        return $stmt->execute([$studioId, $serviceId]);
    }

    public function removeService($studioId, $serviceId) {
        $stmt = $this->db->prepare("
            DELETE FROM studio_services 
            WHERE StudioID = ? AND ServiceID = ?
        ");
        return $stmt->execute([$studioId, $serviceId]);
    }

    public function getAvailableServices() {
        $stmt = $this->db->prepare("SELECT * FROM services ORDER BY ServiceType");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudioEarnings($studioId, $startDate = null, $endDate = null) {
        // OLD schema query
        $sql = "
            SELECT 
                COUNT(DISTINCT b.BookingID) as total_bookings,
                COALESCE(SUM(CASE WHEN bs.Book_Stats = 'Finished' THEN p.Amount ELSE 0 END), 0) as total_earnings
            FROM bookings b
            LEFT JOIN payment p ON b.BookingID = p.BookingID
            LEFT JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
            WHERE b.StudioID = ?
        ";
        
        $params = [$studioId];
        
        if ($startDate) {
            $sql .= " AND b.Date >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $sql .= " AND b.Date <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getDocuments($studioId) {
        // Note: Documents are linked to registrations, not studios directly
        // We'll get documents from the studio's owner registration
        $stmt = $this->db->prepare("
            SELECT d.* 
            FROM documents d
            INNER JOIN studio_registrations sr ON d.registration_id = sr.registration_id
            INNER JOIN studios s ON sr.owner_email = (
                SELECT o.Email FROM studio_owners o WHERE o.OwnerID = s.OwnerID
            )
            WHERE s.StudioID = ?
            ORDER BY d.uploaded_at DESC
        ");
        $stmt->execute([$studioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
