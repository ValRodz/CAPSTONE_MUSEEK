<?php

require_once __DIR__ . '/../config/database.php';

class Report {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function getKPIs($startDate = null, $endDate = null, $studioId = null) {
        $dateFilter = '';
        $params = [];
        
        if ($startDate && $endDate) {
            $dateFilter = " AND sc.Sched_Date >= ? AND sc.Sched_Date <= ?";
            $params[] = $startDate;
            $params[] = $endDate . ' 23:59:59';
        }
        
        $studioFilter = '';
        if ($studioId) {
            $studioFilter = " AND b.StudioID = ?";
            $params[] = $studioId;
        }
        
        $sql = "SELECT 
                COUNT(DISTINCT b.BookingID) as total_bookings,
                COUNT(DISTINCT CASE WHEN bs.Book_Stats = 'Confirmed' THEN b.BookingID END) as paid_bookings,
                COUNT(DISTINCT CASE WHEN bs.Book_Stats = 'Pending' THEN b.BookingID END) as unpaid_bookings,
                COALESCE(SUM(CASE WHEN bs.Book_Stats = 'Confirmed' THEN p.Amount ELSE 0 END), 0) as total_revenue
                FROM bookings b
                INNER JOIN schedules sc ON sc.ScheduleID = b.ScheduleID
                LEFT JOIN payment p ON b.BookingID = p.BookingID
                LEFT JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                WHERE 1=1" . $dateFilter . $studioFilter;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAverageRating($studioId = null) {
        $sql = "SELECT COALESCE(AVG(f.Rating), 0) as avg_rating 
                FROM feedback f
                LEFT JOIN bookings b ON b.BookingID = f.BookingID
                WHERE 1=1";
        $params = [];
        
        if ($studioId) {
            $sql .= " AND b.StudioID = ?";
            $params[] = $studioId;
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)['avg_rating'];
    }

    public function getTopServices($limit = 5, $startDate = null, $endDate = null) {
        $dateFilter = '';
        $params = [];
        
        if ($startDate && $endDate) {
            $dateFilter = " AND sc.Sched_Date >= ? AND sc.Sched_Date <= ?";
            $params[] = $startDate;
            $params[] = $endDate . ' 23:59:59';
        }
        
        $sql = "SELECT 
                    s.Description as name,
                    s.ServiceType as type,
                    COUNT(DISTINCT b.BookingID) as booking_count,
                    COALESCE(SUM(CASE WHEN bs.Book_Stats = 'Confirmed' THEN p.Amount ELSE 0 END), 0) as revenue
                FROM services s
                INNER JOIN booking_services bsrv ON s.ServiceID = bsrv.ServiceID
                INNER JOIN bookings b ON bsrv.BookingID = b.BookingID
                INNER JOIN schedules sc ON sc.ScheduleID = b.ScheduleID
                LEFT JOIN payment p ON b.BookingID = p.BookingID
                LEFT JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                WHERE 1=1" . $dateFilter . "
                GROUP BY s.ServiceID, s.Description, s.ServiceType
                ORDER BY booking_count DESC
                LIMIT ?";
        
        $stmt = $this->db->prepare($sql);
        // Bind date parameters first, then limit
        $paramIndex = 1;
        foreach ($params as $param) {
            $stmt->bindValue($paramIndex++, $param);
        }
        $stmt->bindValue($paramIndex, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStudioPerformance($startDate = null, $endDate = null) {
        $dateFilter = '';
        $params = [];
        
        if ($startDate && $endDate) {
            // Apply date filter inside the schedules join to keep studios with zero-period data
            $dateFilter = " AND sc.Sched_Date >= ? AND sc.Sched_Date <= ?";
            $params[] = $startDate;
            $params[] = $endDate . ' 23:59:59';
        }
        
        $sql = "SELECT 
                    s.StudioID as id, 
                    s.StudioName as name, 
                    COUNT(DISTINCT CASE WHEN sc.ScheduleID IS NOT NULL THEN b.BookingID END) as bookings,
                    COALESCE(SUM(CASE WHEN sc.ScheduleID IS NOT NULL AND bs.Book_Stats = 'Confirmed' THEN p.Amount ELSE 0 END), 0) as revenue,
                    COALESCE(AVG(CASE WHEN sc.ScheduleID IS NOT NULL THEN f.Rating END), 0) as rating
                FROM studios s
                LEFT JOIN bookings b ON s.StudioID = b.StudioID
                LEFT JOIN schedules sc ON sc.ScheduleID = b.ScheduleID" . $dateFilter . "
                LEFT JOIN payment p ON b.BookingID = p.BookingID
                LEFT JOIN book_stats bs ON b.Book_StatsID = bs.Book_StatsID
                LEFT JOIN feedback f ON b.BookingID = f.BookingID
                GROUP BY s.StudioID, s.StudioName
                ORDER BY bookings DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
