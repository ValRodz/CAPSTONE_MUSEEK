<?php
ob_start(); // Still useful for any stray output
ini_set('display_errors', 0); // Suppress on-screen errors
error_reporting(E_ALL);
ini_set('log_errors', 1);

session_start();
include '../../shared/config/db.php'; // Now PDO $conn
require_once '../../shared/config/mail_config.php';
require_once '../../shared/config/paths.php';

// Check login (unchanged)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    echo "<script>alert('Please log in to continue.'); window.location.href = '../../auth/php/login.html';</script>";
    exit;
}

try {
    //$conn->beginTransaction(); // Start PDO transaction

    $conn->begin_transaction(); // Start PDO transaction

    // Get studio_id and selected_slots from POST or SESSION
    $studio_id = isset($_POST['studio_id']) ? (int)$_POST['studio_id'] : 
                 (isset($_SESSION['booking_studio_id']) ? (int)$_SESSION['booking_studio_id'] : 0);

    // Retrieve selected slots from POST or SESSION
    $selected_slots = [];
    if (isset($_POST['selected_slots'])) {
        $selected_slots = is_array($_POST['selected_slots']) ? $_POST['selected_slots'] : json_decode($_POST['selected_slots'], true);
    } elseif (isset($_SESSION['selected_slots']) && is_array($_SESSION['selected_slots'])) {
        $selected_slots = $_SESSION['selected_slots'];
    }
    
    // Filter selected slots to current studio
    if (!empty($selected_slots) && $studio_id > 0) {
        $selected_slots = array_values(array_filter($selected_slots, function($slot) use ($studio_id) {
            return (int)($slot['studio_id'] ?? 0) === (int)$studio_id;
        }));
    }

    // Early validation
    if ($studio_id <= 0 || empty($selected_slots)) {
        throw new Exception("Invalid booking details. Studio ID: $studio_id, Slots: " . count($selected_slots));
    }
    
    error_log("Booking4: Processing " . count($selected_slots) . " slots for studio $studio_id");
    error_log("Booking4: Slots data: " . json_encode($selected_slots));

    // Calculate total price across all slots
    $total_price = 0;
    $slot_totals = []; // Store individual slot totals
    
    foreach ($selected_slots as $index => &$slot) {
        // Datetime validation
        $start_str = $slot['date'] . ' ' . $slot['start'];
        $end_str = $slot['date'] . ' ' . $slot['end'];
        $start = DateTime::createFromFormat('Y-m-d H:i', $start_str);
        $end = DateTime::createFromFormat('Y-m-d H:i', $end_str);
        if (!$start || !$end || $start >= $end) {
            throw new Exception("Invalid datetime in slot: " . json_encode($slot));
        }

        $interval = $start->diff($end);
        $hours = $interval->h + ($interval->days * 24) + ($interval->i / 60);
        
        // Calculate price for all services in this slot
        $slot_total = 0;
        $services = isset($slot['services']) && is_array($slot['services']) ? $slot['services'] : [];
        
        foreach ($services as $service) {
            $service_id = (int)$service['service_id'];
            
            // Fetch service price
            $stmt = $conn->prepare("SELECT Price FROM services WHERE ServiceID = ?");
            $stmt->bind_param('i', $service_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $srv_row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$srv_row) {
                throw new Exception("Service not found: $service_id");
            }
            
            $service_price = (float)$srv_row['Price'];
            $slot_total += $service_price * $hours;
        }
        
        // Calculate equipment costs for this slot
        $equipment = isset($slot['equipment']) && is_array($slot['equipment']) ? $slot['equipment'] : [];
        
        foreach ($equipment as $service_id => $equipments) {
            foreach ($equipments as $equipment_id => $quantity) {
                if ($quantity > 0) {
                    // Fetch equipment rental price
                    $stmt = $conn->prepare("SELECT rental_price FROM equipment_addons WHERE equipment_id = ?");
                    $stmt->bind_param('i', $equipment_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $eq_row = $res->fetch_assoc();
                    $stmt->close();
                    
                    if ($eq_row) {
                        $rental_price = (float)$eq_row['rental_price'];
                        $slot_total += $rental_price * $hours * $quantity;
                    }
                }
            }
        }
        
        $slot_totals[$index] = $slot_total;
        $total_price += $slot_total;
        
        error_log("Booking4: Slot $index total = ₱" . number_format($slot_total, 2));
    }
    unset($slot);
    
    // Get client_id (unchanged)
    $client_id = $_SESSION['user_id'];

    // Get owner_id and deposit percentage
    $owner_query = "SELECT OwnerID, deposit_percentage FROM studios WHERE StudioID = ?";
    $stmt = $conn->prepare($owner_query);
    $stmt->bind_param('i', $studio_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $owner_row = $res->fetch_assoc();
    if (!$owner_row) {
        throw new Exception("No owner found for StudioID: $studio_id");
    }
    $owner_id = $owner_row['OwnerID'];
    $deposit_percentage = isset($owner_row['deposit_percentage']) ? (float)$owner_row['deposit_percentage'] : 25.0;
    
    // Calculate initial payment using studio's deposit percentage
    $initial_payment = $total_price * ($deposit_percentage / 100);
    
    error_log("Booking4: Total price = ₱" . number_format($total_price, 2) . ", Deposit = " . number_format($deposit_percentage, 2) . "%, Initial = ₱" . number_format($initial_payment, 2));

    // Generate unique booking_date for grouping
    $booking_date = date('Y-m-d H:i:s');
    
    // Insert schedules and bookings in loop
    $schedule_ids = [];
    $booking_ids = [];
    $notification_messages = [];

    $schedule_query = "INSERT INTO schedules (StudioID, OwnerID, Sched_Date, Time_Start, Time_End, Avail_StatsID) VALUES (?, ?, ?, ?, ?, 2)";
    $schedule_stmt = $conn->prepare($schedule_query);

    foreach ($selected_slots as $slot_index => $slot) {
        // Insert schedule
        $schedule_stmt->bind_param('iisss', $studio_id, $owner_id, $slot['date'], $slot['start'], $slot['end']);
        $schedule_stmt->execute();
        if ($schedule_stmt->affected_rows == 0) {
            throw new Exception("Failed to insert schedule for slot: " . json_encode($slot));
        }
        $schedule_id = $conn->insert_id;
        $schedule_ids[] = $schedule_id;

        // Insert booking (main booking record)
        // Check which columns exist (backward compatibility)
        $has_instructor_column = false;
        $has_service_column = false;
        
        $columns_result = $conn->query("SHOW COLUMNS FROM bookings");
        while ($col = $columns_result->fetch_assoc()) {
            if ($col['Field'] === 'InstructorID') $has_instructor_column = true;
            if ($col['Field'] === 'ServiceID') $has_service_column = true;
        }
        
        // Build INSERT query based on available columns
        if ($has_instructor_column && $has_service_column) {
            // Old schema: Both columns exist
            $booking_query = "INSERT INTO bookings (ClientID, StudioID, ScheduleID, ServiceID, InstructorID, Book_StatsID, booking_date) 
                             VALUES (?, ?, ?, NULL, NULL, 2, ?)";
            $booking_stmt = $conn->prepare($booking_query);
            $booking_stmt->bind_param('iiis', $client_id, $studio_id, $schedule_id, $booking_date);
        } elseif ($has_service_column) {
            // Partial migration: ServiceID exists, InstructorID removed
            $booking_query = "INSERT INTO bookings (ClientID, StudioID, ScheduleID, ServiceID, Book_StatsID, booking_date) 
                             VALUES (?, ?, ?, NULL, 2, ?)";
            $booking_stmt = $conn->prepare($booking_query);
            $booking_stmt->bind_param('iiis', $client_id, $studio_id, $schedule_id, $booking_date);
        } elseif ($has_instructor_column) {
            // Partial migration: InstructorID exists, ServiceID removed
            $booking_query = "INSERT INTO bookings (ClientID, StudioID, ScheduleID, InstructorID, Book_StatsID, booking_date) 
                             VALUES (?, ?, ?, NULL, 2, ?)";
            $booking_stmt = $conn->prepare($booking_query);
            $booking_stmt->bind_param('iiis', $client_id, $studio_id, $schedule_id, $booking_date);
        } else {
            // New schema: Both columns removed (correct state)
            $booking_query = "INSERT INTO bookings (ClientID, StudioID, ScheduleID, Book_StatsID, booking_date) 
                             VALUES (?, ?, ?, 2, ?)";
            $booking_stmt = $conn->prepare($booking_query);
            $booking_stmt->bind_param('iiis', $client_id, $studio_id, $schedule_id, $booking_date);
        }
        
        $booking_stmt->execute();
        if ($booking_stmt->affected_rows == 0) {
            throw new Exception("Failed to insert booking for schedule ID: $schedule_id");
        }
        $booking_id = $conn->insert_id;
        $booking_ids[] = $booking_id;
        $booking_stmt->close();
        
        error_log("Booking4: Created booking #$booking_id for schedule #$schedule_id");

        // Calculate hours for this slot
        $start_dt = DateTime::createFromFormat('Y-m-d H:i', $slot['date'] . ' ' . $slot['start']);
        $end_dt = DateTime::createFromFormat('Y-m-d H:i', $slot['date'] . ' ' . $slot['end']);
        $hours = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 3600;
        
        // Insert services for this booking into booking_services table
        $services = isset($slot['services']) && is_array($slot['services']) ? $slot['services'] : [];
        
        foreach ($services as $service) {
            $service_id = (int)$service['service_id'];
            $instructor_id = isset($service['instructor_id']) && $service['instructor_id'] > 0 ? (int)$service['instructor_id'] : null;
            
            // Fetch service price
            $stmt = $conn->prepare("SELECT Price FROM services WHERE ServiceID = ?");
            $stmt->bind_param('i', $service_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $srv_row = $res->fetch_assoc();
            $stmt->close();
            
            if (!$srv_row) {
                throw new Exception("Service not found: $service_id");
            }
            
            $service_price = (float)$srv_row['Price'] * $hours;
            
            // Insert into booking_services (matching new_museek.sql schema)
            // Note: BookingID, ServiceID, InstructorID are capitalized, service_price (not service_price_at_booking), no quantity column
            if ($instructor_id !== null) {
                $bs_query = "INSERT INTO booking_services (BookingID, ServiceID, InstructorID, service_price) 
                            VALUES (?, ?, ?, ?)";
                $bs_stmt = $conn->prepare($bs_query);
                $bs_stmt->bind_param('iiid', $booking_id, $service_id, $instructor_id, $service_price);
            } else {
                // Handle NULL instructor_id
                $bs_query = "INSERT INTO booking_services (BookingID, ServiceID, InstructorID, service_price) 
                            VALUES (?, ?, NULL, ?)";
                $bs_stmt = $conn->prepare($bs_query);
                $bs_stmt->bind_param('iid', $booking_id, $service_id, $service_price);
            }
            $bs_stmt->execute();
            if ($bs_stmt->affected_rows == 0) {
                throw new Exception("Failed to insert booking service: ServiceID=$service_id, BookingID=$booking_id");
            }
            $booking_service_id = $conn->insert_id;
            $bs_stmt->close();
            
            error_log("Booking4: Added service #$service_id to booking #$booking_id (booking_service_id: $booking_service_id)");
            
            // Insert equipment for this service
            $equipment = isset($slot['equipment'][$service_id]) && is_array($slot['equipment'][$service_id]) ? $slot['equipment'][$service_id] : [];
            
            foreach ($equipment as $equipment_id => $quantity) {
                if ($quantity > 0) {
                    // Fetch equipment rental price
                    $stmt = $conn->prepare("SELECT rental_price FROM equipment_addons WHERE equipment_id = ?");
                    $stmt->bind_param('i', $equipment_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $eq_row = $res->fetch_assoc();
                    $stmt->close();
                    
                    if (!$eq_row) {
                        throw new Exception("Equipment not found: $equipment_id");
                    }
                    
                    $rental_price = (float)$eq_row['rental_price'] * $hours;
                    
                    // Insert into booking_equipment (matching new_museek.sql schema)
                    // Table: booking_equipment, Columns: booking_service_id, equipment_id, quantity, rental_price
                    $be_query = "INSERT INTO booking_equipment (booking_service_id, equipment_id, quantity, rental_price) 
                                VALUES (?, ?, ?, ?)";
                    $be_stmt = $conn->prepare($be_query);
                    $be_stmt->bind_param('iiid', $booking_service_id, $equipment_id, $quantity, $rental_price);
                    $be_stmt->execute();
                    if ($be_stmt->affected_rows == 0) {
                        throw new Exception("Failed to insert equipment: equipment_id=$equipment_id");
                    }
                    $be_stmt->close();
                    
                    error_log("Booking4: Added equipment #$equipment_id (qty: $quantity) to booking_service #$booking_service_id");
                }
            }
        }
        
        // Build notification message for this slot
        $slot_date_formatted = date('M j, Y', strtotime($slot['date']));
        $slot_time_formatted = date('g:i A', strtotime($slot['start'])) . ' - ' . date('g:i A', strtotime($slot['end']));
        $notification_messages[] = "$slot_date_formatted at $slot_time_formatted";
    }
    
    $schedule_stmt->close();

    // Insert payment for each booking using individual slot totals
    foreach ($booking_ids as $index => $booking_id) {
        $slot_total = $slot_totals[$index];
        $slot_initial = $slot_total * ($deposit_percentage / 100);
        
        $payment_query = "INSERT INTO payment (BookingID, OwnerID, Init_Amount, Amount, Pay_Date, Pay_Stats) 
                         VALUES (?, ?, ?, ?, NOW(), 'Pending')";
        $payment_stmt = $conn->prepare($payment_query);
        $payment_stmt->bind_param('iidd', $booking_id, $owner_id, $slot_initial, $slot_total);
        $payment_stmt->execute();
        if ($payment_stmt->affected_rows == 0) {
            throw new Exception("Failed to insert payment for booking ID: $booking_id");
        }
        $payment_stmt->close();
        
        error_log("Booking4: Created payment for booking #$booking_id - Amount: ₱" . number_format($slot_total, 2) . ", Initial: ₱" . number_format($slot_initial, 2));
    }

    // Insert notification
    $first_booking_id = $booking_ids[0];
    if (!empty($notification_messages)) {
        $notification_message = "New booking request for: " . implode(", ", $notification_messages);
        $notification_query = "INSERT INTO notifications (OwnerID, ClientID, Type, Message, RelatedID, IsRead, For_User, Created_At) VALUES (?, ?, 'Booking', ?, ?, 0, 'Owner', NOW())";
        $notification_stmt = $conn->prepare($notification_query);
        $notification_stmt->bind_param('iisi', $owner_id, $client_id, $notification_message, $first_booking_id);
        $notification_stmt->execute();
        if ($notification_stmt->affected_rows == 0) {
            throw new Exception("Failed to insert notification");
        }
        $notification_stmt->close();
        
        error_log("Booking4: Created notification for owner #$owner_id");
    }

    $conn->commit(); // Commit if all succeeds

    // Send booking details email to the client
    try {
        // Fetch client name and email
        $client_name = 'Client';
        $client_email = '';
        $stmt = $conn->prepare('SELECT Name, Email FROM clients WHERE ClientID = ?');
        $stmt->bind_param('i', $client_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $client_name = $row['Name'] ?: $client_name;
            $client_email = $row['Email'] ?: $client_email;
        }
        $stmt->close();

        if (!empty($client_email)) {
            // Fetch studio info
            $studio_name = '';
            $studio_loc = '';
            $stmt = $conn->prepare('SELECT StudioName, Loc_Desc FROM studios WHERE StudioID = ?');
            $stmt->bind_param('i', $studio_id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($s = $res->fetch_assoc()) {
                $studio_name = $s['StudioName'] ?: '';
                $studio_loc = $s['Loc_Desc'] ?: '';
            }
            $stmt->close();

            $is_multi = count($booking_ids) > 1;
            $total_amount = $total_price;
            $total_initial = $initial_payment;

            // Build slots table rows
            $rows_html = '';
            foreach ($selected_slots as $idx => $slot) {
                $slot_total = $slot_totals[$idx];
                $slot_initial = $slot_total * 0.25;
                
                // Get service names
                $service_names = [];
                $services = isset($slot['services']) && is_array($slot['services']) ? $slot['services'] : [];
                foreach ($services as $service) {
                    $service_id = (int)$service['service_id'];
                    $stmt = $conn->prepare("SELECT ServiceType FROM services WHERE ServiceID = ?");
                    $stmt->bind_param('i', $service_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($srv_row = $res->fetch_assoc()) {
                        $service_names[] = htmlspecialchars($srv_row['ServiceType']);
                    }
                    $stmt->close();
                }
                $services_text = !empty($service_names) ? implode(', ', $service_names) : 'Multiple Services';
                
                $rows_html .= '<tr style="border-bottom:1px solid #eee">'
                           . '<td style="padding:8px">#' . htmlspecialchars($booking_ids[$idx]) . '</td>'
                           . '<td style="padding:8px">' . $services_text . '</td>'
                           . '<td style="padding:8px">' . htmlspecialchars($slot['date']) . '</td>'
                           . '<td style="padding:8px">' . htmlspecialchars($slot['start']) . ' - ' . htmlspecialchars($slot['end']) . '</td>'
                           . '<td style="padding:8px;text-align:right">₱' . number_format($slot_total, 2) . '</td>'
                           . '<td style="padding:8px;text-align:right">₱' . number_format($slot_initial, 2) . '</td>'
                           . '</tr>';
            }

            // Build confirmation URL
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
            $confirm_url = $scheme . '://' . $host . '/booking/php/booking_confirmation.php?booking_id=' . $booking_ids[0] . ($is_multi ? ('&multi=1&count=' . count($booking_ids)) : '');

            $subject = 'Your Museek Booking Details';
            $htmlBody = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div style="font-family:Arial,sans-serif;color:#111">'
                      . '<h2 style="margin:0 0 8px">Booking Request Received</h2>'
                      . '<p style="margin:0 0 16px">Hi ' . htmlspecialchars($client_name) . ',</p>'
                      . '<p style="margin:0 0 12px">Thanks for booking at <strong>' . htmlspecialchars($studio_name) . '</strong>.</p>'
                      . '<p style="margin:0 0 12px"><i>' . htmlspecialchars($studio_loc) . '</i></p>'
                      . '<table style="width:100%;border-collapse:collapse;margin-top:12px">'
                      . '<thead><tr>'
                      . '<th style="text-align:left;padding:8px">Booking ID</th>'
                      . '<th style="text-align:left;padding:8px">Service</th>'
                      . '<th style="text-align:left;padding:8px">Date</th>'
                      . '<th style="text-align:left;padding:8px">Time</th>'
                      . '<th style="text-align:right;padding:8px">Amount</th>'
                      . '<th style="text-align:right;padding:8px">Initial</th>'
                      . '</tr></thead>'
                      . '<tbody>' . $rows_html . '</tbody>'
                      . '<tfoot><tr>'
                      . '<td colspan="4" style="padding:8px;text-align:right"><strong>Total</strong></td>'
                      . '<td style="padding:8px;text-align:right"><strong>₱' . number_format($total_amount, 2) . '</strong></td>'
                      . '<td style="padding:8px;text-align:right"><strong>₱' . number_format($total_initial, 2) . '</strong></td>'
                      . '</tr></tfoot>'
                      . '</table>'
                      . '<p style="margin:16px 0">You can view your booking details here: <a href="' . htmlspecialchars($confirm_url) . '" style="color:#0b5;text-decoration:none">View Booking Confirmation</a></p>'
                      . '<p style="margin:0">We’ll notify you once the studio confirms your booking.</p>'
                      . '</div></body></html>';

            $altBody = "Booking Request Received\n"
                     . "Studio: $studio_name\n"
                     . "Total: ₱" . number_format($total_amount, 2) . "\n"
                     . "Initial: ₱" . number_format($total_initial, 2) . "\n"
                     . "View: $confirm_url";

            @sendTransactionalEmail($client_email, $client_name, $subject, $htmlBody, $altBody);
        }
    } catch (Exception $mailEx) {
        error_log('Booking email error: ' . $mailEx->getMessage());
    }

    // Clear session
    unset($_SESSION['selected_slots']);
    unset($_SESSION['booking_services']);
    unset($_SESSION['booking_equipment']);
    unset($_SESSION['booking_studio_id']);
    unset($_SESSION['last_booking_studio_id']);

    if (empty($booking_ids)) {
        throw new Exception("No bookings created");
    }

    error_log("Booking4: Successfully created " . count($booking_ids) . " bookings. First ID: $first_booking_id");
    
    ob_end_clean();
    header("Location: booking_confirmation.php?booking_id=" . $first_booking_id);
    exit;

} catch (Exception $e) {
    $conn->rollback(); // mysqli rollback
    error_log("Booking error: " . $e->getMessage());
    $_SESSION['booking_error'] = "An error occurred while processing your booking: " . $e->getMessage(); // More detailed for debugging
    ob_end_clean();
    header("Location: booking3.php?studio_id=" . $studio_id);
    exit;
}
?>
