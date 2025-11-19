<?php
// Start the session to access session variables
session_start();

// Check if user is logged in as a studio owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    // Redirect to login page if not logged in as owner
    header('Location: ../../auth/php/login.php');
    exit();
}

include '../../shared/config/db pdo.php';

// Get the logged-in owner's ID from session
$ownerId = $_SESSION['user_id'];

// Get filters
$ratingFilter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$studioFilter = isset($_GET['studio']) ? (int)$_GET['studio'] : 0;
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Build the query
$query = "
    SELECT f.FeedbackID, f.Rating, f.Comment, f.Date,
           c.Name as client_name, c.ClientID,
           s.StudioName, s.StudioID,
           b.BookingID, b.booking_date
    FROM feedback f
    JOIN bookings b ON f.BookingID = b.BookingID
    JOIN clients c ON b.ClientID = c.ClientID
    JOIN studios s ON b.StudioID = s.StudioID
    WHERE s.OwnerID = :ownerId
";

$params = [':ownerId' => $ownerId];

// Add rating filter if provided
if ($ratingFilter > 0) {
    $query .= " AND f.Rating = :rating";
    $params[':rating'] = $ratingFilter;
}

// Add studio filter if provided
if ($studioFilter > 0) {
    $query .= " AND s.StudioID = :studioId";
    $params[':studioId'] = $studioFilter;
}

// Add search filter if provided
if (!empty($searchTerm)) {
    // Use unique placeholders to avoid HY093 for repeated named params
    $query .= " AND (c.Name LIKE :search1 OR f.Comment LIKE :search2)";
    $params[':search1'] = "%$searchTerm%";
    $params[':search2'] = "%$searchTerm%";
}

// Add date filters if provided
if (!empty($startDate)) {
    $query .= " AND DATE(f.Date) >= :startDate";
    $params[':startDate'] = $startDate;
}
if (!empty($endDate)) {
    $query .= " AND DATE(f.Date) <= :endDate";
    $params[':endDate'] = $endDate;
}

$query .= " ORDER BY f.Date DESC";

// Execute the query
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate average rating
$totalRating = 0;
$ratingCount = count($feedback);
$ratingDistribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

foreach ($feedback as $item) {
    $totalRating += $item['Rating'];
    $ratingDistribution[$item['Rating']]++;
}

$averageRating = $ratingCount > 0 ? round($totalRating / $ratingCount, 1) : 0;

// Fetch studios for filter dropdown
$studios = $pdo->prepare("
    SELECT StudioID, StudioName
    FROM studios
    WHERE OwnerID = ?
");
$studios->execute([$ownerId]);
$studios = $studios->fetchAll(PDO::FETCH_ASSOC);

// Check for unread notifications
$notificationsStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM notifications 
    WHERE OwnerID = ? 
    AND IsRead = 0
");
$notificationsStmt->execute([$ownerId]);
$unreadNotifications = $notificationsStmt->fetchColumn();

// Fetch owner data
$owner = $pdo->prepare("
    SELECT Name, Email 
    FROM studio_owners 
    WHERE OwnerID = ?
");
$owner->execute([$ownerId]);
$owner = $owner->fetch(PDO::FETCH_ASSOC);

// Helper function to get customer initials
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}

// Helper function to generate star rating
function getStarRating($rating) {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star text-yellow-500"></i>';
        } else {
            $html .= '<i class="far fa-star text-gray-500"></i>';
        }
    }
    return $html;
}

// Render feedback items (used for full page and AJAX)
function renderFeedbackItems($feedback) {
    if (empty($feedback)) {
        echo '<div class="text-center py-8 text-gray-400"><p>No feedback found.</p></div>';
        return;
    }
    foreach ($feedback as $item) {
        echo '<div class="feedback-card p-4 feedback-item" data-feedback-id="'.$item['FeedbackID'].'">';
        echo '<div class="flex items-start gap-4">';
        echo '<div class="avatar">'.getInitials($item['client_name']).'</div>';
        echo '<div class="flex-1 min-w-0">';
        echo '<div class="flex justify-between items-start">';
        echo '<div>';
        echo '<h2 class="text-lg font-bold">'.htmlspecialchars($item['client_name']).'</h2>';
        echo '<div class="flex items-center gap-2 mt-1">';
        echo '<div class="flex">'.getStarRating($item['Rating']).'</div>';
        echo '<span class="text-sm text-gray-400">'.date('M d, Y', strtotime($item['Date'])).'</span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="text-sm text-gray-400">'.htmlspecialchars($item['StudioName']).'</div>';
        echo '</div>';
        echo '<p class="mt-3 text-sm">'.htmlspecialchars($item['Comment']).'</p>';
        echo '<div class="mt-3 text-xs text-gray-400">Booking Date: '.date('M d, Y', strtotime($item['booking_date'])).'</div>';
        echo '</div>';
        echo '</div>';
        echo '<div class="flex justify-end mt-3"><button class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-3 py-1.5 text-xs font-medium" onclick="openReplyModal('.$item['FeedbackID'].', \''.addslashes($item['client_name']).'\')">Reply</button></div>';
        echo '</div>';
    }
}

// AJAX quick-return: only list items
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';
if ($isAjax) {
    renderFeedbackItems($feedback);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>MuSeek - Feedback</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"/>
    <style>
        body {
            font-family: "Inter", sans-serif;
            color-scheme: dark; /* ensure native controls render with light icons */
        }
        .sidebar {
            display: none;
            height: 100vh;
            width: 250px;
            position: fixed;
            z-index: 40;
            top: 0;
            left: 0;
            transition: transform 0.3s ease;
        }
        .sidebar.active {
            display: block;
        }
        .main-content {
            /* Remove width to avoid horizontal overflow when sidebar adds margin-left */
            overflow-x: hidden;
        }
        .avatar {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 9999px;
            background-color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .feedback-card {
            background-color: #0a0a0a;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .rating-bar {
            height: 0.5rem;
            background-color: #222222;
            border-radius: 0.25rem;
            overflow: hidden;
        }
        .rating-fill {
            height: 100%;
            background-color: #dc2626;
        }
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            bottom: 100%;
            margin-bottom: 5px;
            background-color: #0a0a0a;
            min-width: 160px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1;
            border-radius: 0.375rem;
            border: 1px solid #222222;
        }
        .dropdown-content a {
            color: white;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 0.875rem;
        }
        .dropdown-content a:hover {
            background-color: #222222;
        }
        .dropdown:hover .dropdown-content {
            display: block;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.7);
        }
        .modal-content {
            background-color: #0a0a0a;
            margin: 10% auto;
            padding: 20px;
            border: 1px solid #222222;
            border-radius: 0.5rem;
            width: 80%;
            max-width: 500px;
        }
        /* Date inputs: hide native indicator; we use a custom overlay icon */
         input[type="date"] {
             color: #ffffff;
             color-scheme: dark;
         }
         input[type="date"]::-webkit-calendar-picker-indicator {
             opacity: 0;
             pointer-events: none;
         }
        /* Deep-link highlight for a specific feedback card */
        .feedback-card.row-highlight {
            background-color: rgba(248, 113, 113, 0.08); /* red-400 */
            outline: 2px solid #f87171;
            outline-offset: -2px;
            animation: pulseRowHighlight 2500ms ease-out;
        }
        @keyframes pulseRowHighlight {
            0% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.35); }
            50% { box-shadow: 0 0 0 8px rgba(248, 113, 113, 0.15); }
            100% { box-shadow: 0 0 0 0 rgba(248, 113, 113, 0.0); }
        }
    </style>
</head>
<body class="bg-[#161616] text-white">
    <?php include 'sidebar_netflix.php'; ?>

    <!-- Main Content -->
    <main class="main-content min-h-screen" id="mainContent">
        <header class="flex items-center h-14 px-6 border-b border-[#222222]">
            <h1 class="text-xl font-bold ml-6">Feedback</h1>
        </header>
        
        <div class="p-6">
            <!-- Rating Overview -->
            <div class="bg-[#0a0a0a] rounded-lg border border-[#222222] p-6 mb-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="flex flex-col items-center justify-center">
                        <div class="text-4xl font-bold"><?php echo $averageRating; ?></div>
                        <div class="flex items-center mt-2">
                            <?php echo getStarRating(round($averageRating)); ?>
                        </div>
                        <div class="text-sm text-gray-400 mt-2"><?php echo $ratingCount; ?> reviews</div>
                    </div>
                    
                    <div class="col-span-2">
                        <div class="space-y-3">
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <?php 
                                $percentage = $ratingCount > 0 ? ($ratingDistribution[$i] / $ratingCount) * 100 : 0;
                                ?>
                                <div class="flex items-center gap-3">
                                    <div class="text-sm font-medium w-8"><?php echo $i; ?> star</div>
                                    <div class="flex-1">
                                        <div class="rating-bar">
                                            <div class="rating-fill" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="text-sm text-gray-400 w-16"><?php echo $ratingDistribution[$i]; ?> (<?php echo round($percentage); ?>%)</div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-4">
                <form action="feedback_owner.php" method="get" class="flex flex-wrap items-center gap-4">
                    <div>
                        <label for="rating-filter" class="block text-xs text-gray-400 mb-1">Rating</label>
                        <select id="rating-filter" name="rating" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-36">
                            <option value="0">All Ratings</option>
                            <option value="5" <?php echo $ratingFilter === 5 ? 'selected' : ''; ?>>5 Stars</option>
                            <option value="4" <?php echo $ratingFilter === 4 ? 'selected' : ''; ?>>4 Stars</option>
                            <option value="3" <?php echo $ratingFilter === 3 ? 'selected' : ''; ?>>3 Stars</option>
                            <option value="2" <?php echo $ratingFilter === 2 ? 'selected' : ''; ?>>2 Stars</option>
                            <option value="1" <?php echo $ratingFilter === 1 ? 'selected' : ''; ?>>1 Star</option>
                        </select>
                    </div>
                    <div>
                        <label for="studio-filter" class="block text-xs text-gray-400 mb-1">Studio</label>
                        <select id="studio-filter" name="studio" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 w-48">
                            <option value="0">All Studios</option>
                            <?php foreach ($studios as $studio): ?>
                                <option value="<?php echo $studio['StudioID']; ?>" <?php echo $studioFilter === $studio['StudioID'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($studio['StudioName']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="start-date" class="block text-xs text-gray-400 mb-1">From</label>
                        <div class="relative">
                            <input type="date" id="start-date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 pr-10 w-40 date-input">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white date-picker-trigger" aria-label="Open calendar" data-input="#start-date">
                                <i class="fas fa-calendar-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="end-date" class="block text-xs text-gray-400 mb-1">To</label>
                        <div class="relative">
                            <input type="date" id="end-date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>" class="bg-[#0a0a0a] border border-[#222222] rounded-md text-sm p-2 pr-10 w-40 date-input">
                            <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-white date-picker-trigger" aria-label="Open calendar" data-input="#end-date">
                                <i class="fas fa-calendar-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                            Apply Filters
                        </button>
                    </div>
                </form>
                <div class="relative">
                    <form action="feedback_owner.php" method="get">
                        <input type="hidden" name="rating" value="<?php echo $ratingFilter; ?>">
                        <input type="hidden" name="studio" value="<?php echo $studioFilter; ?>">
                        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search feedback..." class="bg-[#0a0a0a] border border-[#222222] rounded-md pl-10 pr-4 py-2 text-sm w-64">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <button type="submit" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-white">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Feedback List -->
            <div id="feedback-list" class="space-y-4">
                <?php renderFeedbackItems($feedback); ?>
            </div>
            
            <!-- Pagination -->
            <?php if (!empty($feedback) && count($feedback) > 10): ?>
                <div class="flex justify-between items-center mt-6">
                    <div class="text-sm text-gray-400">
                        Showing <span class="font-medium text-white">1-10</span> of <span class="font-medium text-white"><?php echo count($feedback); ?></span> reviews
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-1 text-sm disabled:opacity-50" disabled>
                            Previous
                        </button>
                        <button class="bg-[#0a0a0a] border border-[#222222] rounded-md px-3 py-1 text-sm">
                            Next
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Reply Modal -->
    <div id="replyModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Reply to <span id="clientName"></span></h3>
                <button class="text-gray-400 hover:text-white" onclick="closeReplyModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="replyForm" action="send_reply.php" method="post">
                <input type="hidden" id="feedbackId" name="feedback_id">
                <div class="space-y-4">
                    <div>
                        <label for="reply" class="block text-sm font-medium text-gray-400 mb-1">Your Reply</label>
                        <textarea id="reply" name="reply" rows="4" required class="w-full bg-[#161616] border border-[#222222] rounded-md px-3 py-2 text-sm"></textarea>
                    </div>
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" class="bg-[#222222] hover:bg-[#333333] text-white rounded-md px-4 py-2 text-sm font-medium" onclick="closeReplyModal()">
                            Cancel
                        </button>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white rounded-md px-4 py-2 text-sm font-medium">
                            Send Reply
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Reply modal functionality (sidebar handled by sidebar_netflix.php)
        const replyModal = document.getElementById('replyModal');

        function openReplyModal(feedbackId, clientName) {
            document.getElementById('feedbackId').value = feedbackId;
            document.getElementById('clientName').textContent = clientName;
            replyModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeReplyModal() {
            replyModal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === replyModal) {
                closeReplyModal();
            }
        });

        // Date picker icon triggers native picker
        document.querySelectorAll('.date-picker-trigger').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var selector = btn.getAttribute('data-input');
                var input = document.querySelector(selector);
                if (!input) return;
                if (typeof input.showPicker === 'function') {
                    input.showPicker();
                } else {
                    input.focus();
                    // Fallback for browsers without showPicker
                    try { input.click(); } catch (err) {}
                }
            });
        });

        // Real-time search and filters (AJAX)
        (function() {
            const feedbackList = document.getElementById('feedback-list');
            const ratingSelect = document.getElementById('rating-filter');
            const studioSelect = document.getElementById('studio-filter');
            const startDateInput = document.getElementById('start-date');
            const endDateInput = document.getElementById('end-date');
            const searchInput = document.querySelector('input[name="search"]');
            const filtersForm = document.querySelector('form[action="feedback_owner.php"]');

            if (!feedbackList) return;

            function debounce(fn, delay) {
                let t;
                return function(...args) {
                    clearTimeout(t);
                    t = setTimeout(() => fn.apply(this, args), delay);
                };
            }

            function updateFeedbackList() {
                const params = new URLSearchParams({
                    ajax: '1',
                    rating: ratingSelect ? ratingSelect.value : 0,
                    studio: studioSelect ? studioSelect.value : 0,
                    start_date: startDateInput ? startDateInput.value : '',
                    end_date: endDateInput ? endDateInput.value : '',
                    search: searchInput ? searchInput.value.trim() : ''
                });

                fetch('feedback_owner.php?' + params.toString(), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.text())
                .then(html => {
                    feedbackList.innerHTML = html;
                })
                .catch(err => {
                    console.error('Realtime search error:', err);
                });
            }

            const debouncedUpdate = debounce(updateFeedbackList, 250);

            if (searchInput) {
                searchInput.addEventListener('input', debouncedUpdate);
            }
            if (ratingSelect) ratingSelect.addEventListener('change', updateFeedbackList);
            if (studioSelect) studioSelect.addEventListener('change', updateFeedbackList);
            if (startDateInput) startDateInput.addEventListener('change', updateFeedbackList);
            if (endDateInput) endDateInput.addEventListener('change', updateFeedbackList);

            if (filtersForm) {
                filtersForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    updateFeedbackList();
                });
            }
        })();

        // Highlight a specific feedback card when deep-linked via ?feedback_id=123
        document.addEventListener('DOMContentLoaded', function() {
            try {
                const params = new URLSearchParams(window.location.search);
                const targetId = params.get('feedback_id');
                if (!targetId) return;

                const selector = `.feedback-card[data-feedback-id="${CSS.escape(targetId)}"]`;
                const card = document.querySelector(selector);
                if (!card) return;

                card.classList.add('row-highlight');
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) {
                console.warn('Feedback highlight failed:', e);
            }
        });
    </script>
</body>
</html>

