<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
include '../global/dbConnection.php';

$page_message = null;

function fetch_all_admins($connection): array {
    if (!$connection) {
        return [];
    }

    $admins = [];
    $stmt = mysqli_prepare(
        $connection,
        "SELECT l.login_username,
                COALESCE(s.staff_name, l.login_username) AS admin_name
         FROM tbl_login l
         LEFT JOIN tbl_staff_info s ON s.staff_username = l.login_username
         WHERE UPPER(l.login_role) = 'ADMIN'
         ORDER BY admin_name ASC"
    );
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $admins[] = [
                'username' => $row['login_username'],
                'name' => $row['admin_name']
            ];
        }
    }
    mysqli_stmt_close($stmt);

    return $admins;
}

$admin_choices = fetch_all_admins($connection);
$has_admin_choices = !empty($admin_choices);
$admin_placeholder = $has_admin_choices ? 'Select an admin' : 'No admins available';
$reported_reviews = $_SESSION['review_reported'] ?? [];
if (!is_array($reported_reviews)) {
    $reported_reviews = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['review_id'])) {
    $action = $_POST['action'];
    $review_id = (int)$_POST['review_id'];
    $is_ajax = ($_POST['ajax'] ?? '') === '1';

    if (!$connection) {
        $_SESSION['review_flash'] = [
            'type' => 'error',
            'text' => 'Database connection failed.'
        ];
    } elseif ($review_id <= 0) {
        $_SESSION['review_flash'] = [
            'type' => 'error',
            'text' => 'Invalid review selection.'
        ];
    } elseif ($action === 'report_review') {
        $admin_receiver = trim($_POST['admin_receiver'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $allowed_reasons = [
            'Spam or repetitive content',
            'Harassment or abusive language',
            'Inappropriate content',
            'Scam or suspicious request',
            'Other'
        ];

        if ($reason === '' || !in_array($reason, $allowed_reasons, true)) {
            $_SESSION['review_flash'] = [
                'type' => 'error',
                'text' => 'Please select a report reason.'
            ];
        } elseif ($admin_receiver === '') {
            $_SESSION['review_flash'] = [
                'type' => 'error',
                'text' => 'Please select an admin.'
            ];
        } elseif ($reason === 'Other' && $details === '') {
            $_SESSION['review_flash'] = [
                'type' => 'error',
                'text' => 'Please add details for "Other".'
            ];
        } else {
            $admin_usernames = array_column($admin_choices, 'username');
            if (!in_array($admin_receiver, $admin_usernames, true)) {
                $_SESSION['review_flash'] = [
                    'type' => 'error',
                    'text' => 'Selected admin is unavailable.'
                ];
            } else {
                $review_info = null;
                $stmt = mysqli_prepare(
                    $connection,
                    "SELECT r.review_id, r.review_offer_id, r.review_passenger_username, r.review_rating, r.review_comment,
                            ro.offer_driver_username,
                            ud.user_name AS driver_name,
                            up.user_name AS passenger_name
                     FROM tbl_review r
                     JOIN tbl_ride_offer ro ON r.review_offer_id = ro.offer_id
                     LEFT JOIN tbl_user_info ud ON ro.offer_driver_username = ud.user_username
                     LEFT JOIN tbl_user_info up ON r.review_passenger_username = up.user_username
                     WHERE r.review_id = ?
                     LIMIT 1"
                );
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "i", $review_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $review_info = $result ? mysqli_fetch_assoc($result) : null;
                    mysqli_stmt_close($stmt);
                }

                if (!$review_info) {
                    $_SESSION['review_flash'] = [
                        'type' => 'error',
                        'text' => 'Review details not found.'
                    ];
                } else {
                    $sender = $_SESSION['username'] ?? 'staff';
                    $passenger_username = $review_info['review_passenger_username'];
                    $content = "Username: {$passenger_username}\n"
                        . "Reason: {$reason}\n"
                        . "Details: " . ($details !== '' ? $details : '-');

                    $stmt = mysqli_prepare(
                        $connection,
                        "INSERT INTO tbl_message (message_sender, message_receiver, message_content, message_status)
                         VALUES (?, ?, ?, 'UNSEEN')"
                    );
                    if ($stmt) {
                        mysqli_stmt_bind_param($stmt, "sss", $sender, $admin_receiver, $content);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }

                    $reported_reviews[$review_id] = [
                        'reason' => $reason,
                        'details' => $details,
                        'reported_at' => date('Y-m-d H:i:s')
                    ];
                    $_SESSION['review_reported'] = $reported_reviews;

                    $_SESSION['review_flash'] = [
                        'type' => 'success',
                        'text' => 'Review reported successfully.'
                    ];
                }
            }
        }
    } else {
        $status = $action === 'hide' ? 'HIDDEN' : 'ACTIVE';
        $stmt = mysqli_prepare(
            $connection,
            "UPDATE tbl_review SET review_status = ? WHERE review_id = ?"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $status, $review_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $_SESSION['review_flash'] = [
                'type' => 'success',
                'text' => $action === 'hide' ? 'Review hidden.' : 'Review restored.'
            ];
        } else {
            $_SESSION['review_flash'] = [
                'type' => 'error',
                'text' => 'Unable to update review status.'
            ];
        }
    }

    if ($is_ajax) {
        $flash = $_SESSION['review_flash'] ?? null;
        unset($_SESSION['review_flash']);
        $response = [
            'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
            'message' => is_array($flash) ? $flash['text'] : 'Unable to update review status.'
        ];
        if (in_array($action, ['hide', 'show'], true)) {
            $response['status'] = $action === 'hide' ? 'Hidden' : 'Active';
        }
        if ($action === 'report_review') {
            $response['reason'] = $reason ?? '';
            $response['details'] = $details ?? '';
        }
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    header('Location: staff_ModerateReviews.php');
    exit;
}

if (!empty($_SESSION['review_flash'])) {
    $page_message = $_SESSION['review_flash'];
    unset($_SESSION['review_flash']);
}

$page_size = 15;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$is_load_more = ($_GET['ajax'] ?? '') === '1';

$review_counts = [
    'total' => 0,
    'active' => 0,
    'hidden' => 0,
    'flagged' => 0
];

if ($connection) {
    $count_row = mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN UPPER(review_status) = 'HIDDEN' THEN 1 ELSE 0 END) AS hidden_count,
            SUM(CASE WHEN UPPER(review_status) = 'FLAGGED' THEN 1 ELSE 0 END) AS flagged_count,
            SUM(CASE WHEN UPPER(review_status) NOT IN ('HIDDEN', 'FLAGGED') THEN 1 ELSE 0 END) AS active_count
         FROM tbl_review"
    ));
    if ($count_row) {
        $review_counts['total'] = (int)$count_row['total_count'];
        $review_counts['hidden'] = (int)$count_row['hidden_count'];
        $review_counts['flagged'] = (int)$count_row['flagged_count'];
        $review_counts['active'] = (int)$count_row['active_count'];
    }
}

$reviews = [];
if ($connection) {
    $sql = "SELECT r.review_id, r.review_offer_id, r.review_passenger_username, r.review_rating,
                   r.review_comment, r.review_created_at, r.review_status,
                   ro.offer_driver_username,
                   ud.user_name AS driver_name,
                   up.user_name AS passenger_name
            FROM tbl_review r
            JOIN tbl_ride_offer ro ON r.review_offer_id = ro.offer_id
            LEFT JOIN tbl_user_info ud ON ro.offer_driver_username = ud.user_username
            LEFT JOIN tbl_user_info up ON r.review_passenger_username = up.user_username
            ORDER BY r.review_created_at DESC
            LIMIT $page_size OFFSET $offset";
    $result = mysqli_query($connection, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $status = match (strtoupper($row['review_status'])) {
                'HIDDEN' => 'Hidden',
                'FLAGGED' => 'Flagged',
                default => 'Active'
            };
            $report_data = $reported_reviews[(int)$row['review_id']] ?? null;
            $reviews[] = [
                "review_id" => (int)$row['review_id'],
                "driver" => $row['driver_name'] ?? $row['offer_driver_username'],
                "driver_username" => $row['offer_driver_username'],
                "passenger" => $row['review_passenger_username'],
                "passenger_name" => $row['passenger_name'] ?? $row['review_passenger_username'],
                "rating" => (int)$row['review_rating'],
                "comment" => $row['review_comment'] ?? '',
                "date" => $row['review_created_at'],
                "status" => $status,
                "ride_id" => (int)$row['review_offer_id'],
                "reported" => is_array($report_data),
                "report_reason" => $report_data['reason'] ?? '',
                "report_details" => $report_data['details'] ?? ''
            ];
        }
    }
}

$has_more = ($offset + $page_size) < $review_counts['total'];

function render_review_card(array $review): string {
    ob_start();
    ?>
    <div class="review-card-modern"
         data-status="<?= htmlspecialchars($review['status']) ?>"
         data-rating="<?= $review['rating'] ?>"
         data-review-id="<?= $review['review_id'] ?>">
        
        <div class="review-card-header">
            <div class="review-participants">
                <div class="driver-section-review">
                    <div class="participant-avatar">
                        <i class="material-icons">drive_eta</i>
                    </div>
                    <div class="participant-info">
                        <span class="participant-label">Driver</span>
                        <strong><?= htmlspecialchars($review['driver']) ?></strong>
                        <span class="username-light">@<?= htmlspecialchars($review['driver_username']) ?></span>
                    </div>
                </div>
                <div class="review-arrow">
                    <i class="material-icons">arrow_forward</i>
                </div>
                <div class="passenger-section-review">
                    <div class="participant-avatar">
                        <i class="material-icons">person</i>
                    </div>
                    <div class="participant-info">
                        <span class="participant-label">Reviewed by</span>
                        <strong><?= htmlspecialchars($review['passenger_name']) ?></strong>
                        <span class="username-light">@<?= htmlspecialchars($review['passenger']) ?></span>
                    </div>
                </div>
            </div>
            <span class="review-status-badge status-<?= strtolower($review['status']) ?>">
                <i class="material-icons">
                    <?php
                    echo match($review['status']) {
                        'Active' => 'check_circle',
                        'Flagged' => 'flag',
                        'Hidden' => 'visibility_off',
                        default => 'info'
                    };
                    ?>
                </i>
                <?= htmlspecialchars($review['status']) ?>
            </span>
            <?php if (!empty($review['reported'])): ?>
                <span class="reported-badge" title="Reported: <?= htmlspecialchars($review['report_reason']) ?><?= $review['report_details'] ? ' - ' . htmlspecialchars($review['report_details']) : '' ?>">
                    <i class="material-icons">flag</i>
                    Reported
                </span>
            <?php endif; ?>
        </div>

        <div class="review-rating-section">
            <div class="rating-display">
                <div class="stars-large">
                    <?php for($i = 1; $i <= 5; $i++): ?>
                        <i class="material-icons <?= $i <= $review['rating'] ? 'filled' : '' ?>">
                            <?= $i <= $review['rating'] ? 'star' : 'star_border' ?>
                        </i>
                    <?php endfor; ?>
                </div>
                <span class="rating-number"><?= $review['rating'] ?>.0</span>
            </div>
            <span class="review-date">
                <i class="material-icons">schedule</i>
                <?= date('M d, Y H:i', strtotime($review['date'])) ?>
            </span>
        </div>

        <div class="review-comment-section">
            <p class="review-comment-text"><?= htmlspecialchars($review['comment']) ?></p>
        </div>

        <div class="review-meta-info">
            <span class="ride-id-badge">
                <i class="material-icons">confirmation_number</i>
                Ride #<?= $review['ride_id'] ?>
            </span>
        </div>

                <div class="review-actions-section">
                    <?php if($review['status'] !== 'Hidden'): ?>
                    <button class="review-action-btn btn-hide" type="button" onclick="hideReview(<?= $review['review_id'] ?>)">
                        <i class="material-icons">visibility_off</i>
                        Hide Review
                    </button>
                    <?php else: ?>
                    <button class="review-action-btn btn-show" type="button" onclick="showReview(<?= $review['review_id'] ?>)">
                        <i class="material-icons">visibility</i>
                        Show Review
                    </button>
                    <?php endif; ?>
                    <button class="review-action-btn btn-report" type="button" onclick="openReviewReportModal(<?= $review['review_id'] ?>)">
                        <i class="material-icons">flag</i>
                        Report Review
                    </button>
                </div>
    </div>
    <?php
    return ob_get_clean();
}

if ($is_load_more) {
    $cards_html = '';
    foreach ($reviews as $review) {
        $cards_html .= render_review_card($review);
    }
    header('Content-Type: application/json');
    echo json_encode([
        'html' => $cards_html,
        'has_more' => $has_more,
        'next_offset' => $offset + $page_size
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews - RideShare@APU</title>
    <link rel="stylesheet" href="staff_base.css">
    <link rel="stylesheet" href="staff_ModerateReviews.css">
    <link rel="stylesheet" href="staff_shared.css">
    <link rel="stylesheet" href="../global/main.css">
    <link rel="stylesheet" href="../global/footer.css">
    <link rel="stylesheet" href="../global/menu.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Bowlby+One&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.5.0/css/all.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body>
    <?php include 'staffMenu.php'; ?>

    <main class="moderate-reviews-main">
        <div class="page-header-section">
            <div class="header-content">
                <h1><i class="material-icons">flag</i> Reviews</h1>
                <p class="page-subtitle">Review and moderate user feedback for drivers</p>
                <?php if ($page_message): ?>
                    <div class="page-message <?= htmlspecialchars($page_message['type']) ?>">
                        <?= htmlspecialchars($page_message['text']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-filters">
                <div class="search-box">
                    <i class="material-icons">search</i>
                    <input type="text" id="reviewSearch" placeholder="Search reviews..." onkeyup="searchReviews()">
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="review-filter-tabs">
            <button class="review-tab active" onclick="filterReviews('all', event)">
                <i class="material-icons">list</i>
                All Reviews
            </button>
            <button class="review-tab" onclick="filterReviews('Active', event)">
                <i class="material-icons">visibility</i>
                Active
            </button>
            <button class="review-tab" onclick="filterReviews('Hidden', event)">
                <i class="material-icons">visibility_off</i>
                Hidden
            </button>
        </div>

        <!-- Rating Filter -->
        <div class="rating-filter-section">
            <span class="filter-label">Filter by Rating:</span>
            <div class="rating-buttons">
                <button class="rating-filter-btn active" onclick="filterByRating('all', event)">
                    All Ratings
                </button>
                <?php for($i = 5; $i >= 1; $i--): ?>
                <button class="rating-filter-btn" onclick="filterByRating(<?= $i ?>, event)">
                    <?= str_repeat('&#9733;', $i) . str_repeat('&#9734;', 5 - $i) ?>
                </button>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Reviews List -->
        <div class="reviews-list-modern">
            <?php foreach($reviews as $review): ?>
                <?= render_review_card($review) ?>
            <?php endforeach; ?>
        </div>
        <?php if ($has_more): ?>
        <div class="load-more-wrapper">
            <button type="button" class="load-more-btn" id="loadMoreReviews">Show More</button>
        </div>
        <?php endif; ?>
    </main>

    <?php include '../global/footer.php'; ?>

    <form method="POST" action="" id="reviewActionForm">
        <input type="hidden" name="action" id="reviewActionField" value="">
        <input type="hidden" name="review_id" id="reviewIdField" value="">
    </form>

    <div id="reviewReportModal" class="modal-overlay" onclick="closeReviewReportModal()">
        <div class="modal-content report-review-modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2><i class="material-icons">flag</i> Report Review</h2>
            </div>
            <div class="modal-body">
                <form id="reviewReportForm" method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="report_review">
                    <input type="hidden" name="review_id" id="reportReviewId" value="">
                    <div class="form-group">
                        <label for="reportReviewAdmin">
                            <i class="material-icons">admin_panel_settings</i>
                            Send to admin
                        </label>
                        <div class="custom-select" data-target="reportReviewAdmin" data-open="false">
                            <button type="button" class="custom-select-trigger" id="reportReviewAdminTrigger" aria-haspopup="listbox" aria-expanded="false" <?= $has_admin_choices ? '' : 'disabled' ?>>
                                <span class="custom-select-label" id="reportReviewAdminLabel"><?= htmlspecialchars($admin_placeholder) ?></span>
                                <i class="material-icons" aria-hidden="true">expand_more</i>
                            </button>
                            <div class="custom-select-dropdown" role="listbox" aria-labelledby="reportReviewAdminTrigger">
                                <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="">
                                    <?= htmlspecialchars($admin_placeholder) ?>
                                </button>
                                <?php foreach ($admin_choices as $admin): ?>
                                    <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="<?= htmlspecialchars($admin['username']) ?>">
                                        <?= htmlspecialchars($admin['name']) ?> (@<?= htmlspecialchars($admin['username']) ?>)
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <select class="sr-only" id="reportReviewAdmin" name="admin_receiver" required <?= $has_admin_choices ? '' : 'disabled' ?>>
                            <option value="" selected><?= htmlspecialchars($admin_placeholder) ?></option>
                            <?php foreach ($admin_choices as $admin): ?>
                                <option value="<?= htmlspecialchars($admin['username']) ?>">
                                    <?= htmlspecialchars($admin['name']) ?> (@<?= htmlspecialchars($admin['username']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-error" id="reportReviewAdminError"></span>
                    </div>
                    <div class="form-group">
                        <label for="reportReviewReason">
                            <i class="material-icons">flag</i>
                            Report reason
                        </label>
                        <div class="custom-select" data-target="reportReviewReason" data-open="false">
                            <button type="button" class="custom-select-trigger" id="reportReviewReasonTrigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="custom-select-label" id="reportReviewReasonLabel">Select a reason</span>
                                <i class="material-icons" aria-hidden="true">expand_more</i>
                            </button>
                            <div class="custom-select-dropdown" role="listbox" aria-labelledby="reportReviewReasonTrigger">
                                <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="">Select a reason</button>
                                <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Spam or repetitive content">Spam or repetitive content</button>
                                <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Harassment or abusive language">Harassment or abusive language</button>
                                <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Inappropriate content">Inappropriate content</button>
                                <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Scam or suspicious request">Scam or suspicious request</button>
                                <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Other">Other</button>
                            </div>
                        </div>
                        <select class="sr-only" id="reportReviewReason" name="reason" required>
                            <option value="" selected>Select a reason</option>
                            <option value="Spam or repetitive content">Spam or repetitive content</option>
                            <option value="Harassment or abusive language">Harassment or abusive language</option>
                            <option value="Inappropriate content">Inappropriate content</option>
                            <option value="Scam or suspicious request">Scam or suspicious request</option>
                            <option value="Other">Other</option>
                        </select>
                        <span class="field-error" id="reportReviewReasonError"></span>
                    </div>
                    <div class="form-group">
                        <label for="reportReviewDetails">
                            <i class="material-icons">description</i>
                            Details (optional)
                        </label>
                        <textarea id="reportReviewDetails" name="details" placeholder="Add any extra context..."></textarea>
                    </div>
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeReviewReportModal()">
                            <i class="material-icons">close</i>
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary" <?= $has_admin_choices ? '' : 'disabled' ?>>
                            <i class="material-icons">send</i>
                            Submit Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="staff_custom_select.js"></script>
    <script src="staff.js"></script>
    <script>
        const scrollStorageKey = 'scroll:staff_ModerateReviews';
        const storeScrollPosition = window.staffUtils.setupScrollRestore(scrollStorageKey);
        const showPageMessage = window.staffUtils.showPageMessage;

        const reviewsData = <?= json_encode($reviews) ?>;
        let activeStatusFilter = 'all';
        let activeRatingFilter = 'all';

        function filterReviews(status, event) {
            const tabs = document.querySelectorAll('.review-tab');

            tabs.forEach(tab => tab.classList.remove('active'));
            if (event && event.target) {
                event.target.closest('.review-tab').classList.add('active');
            }
            activeStatusFilter = status;
            applyReviewFilters();
        }

        function filterByRating(rating, event) {
            const buttons = document.querySelectorAll('.rating-filter-btn');

            buttons.forEach(btn => btn.classList.remove('active'));
            if (event && event.target) {
                event.target.classList.add('active');
            }
            activeRatingFilter = rating;
            applyReviewFilters();
        }

        function searchReviews() {
            applyReviewFilters();
        }

        function applyReviewFilters() {
            const searchInput = document.getElementById('reviewSearch');
            const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
            const cards = document.querySelectorAll('.review-card-modern');

            cards.forEach(card => {
                const cardStatus = card.dataset.status;
                const cardRating = parseInt(card.dataset.rating, 10);
                const text = card.textContent.toLowerCase();
                const matchesStatus = activeStatusFilter === 'all' || cardStatus === activeStatusFilter;
                const matchesRating = activeRatingFilter === 'all' || cardRating === activeRatingFilter;
                const matchesSearch = !searchTerm || text.includes(searchTerm);
                card.style.display = (matchesStatus && matchesRating && matchesSearch) ? 'block' : 'none';
            });
        }

        const reviewReportModal = document.getElementById('reviewReportModal');
        const reviewReportForm = document.getElementById('reviewReportForm');
        const reportReviewIdField = document.getElementById('reportReviewId');
        const reportReviewAdmin = document.getElementById('reportReviewAdmin');
        const reportReviewReason = document.getElementById('reportReviewReason');
        const reportReviewDetails = document.getElementById('reportReviewDetails');
        const reportReviewAdminError = document.getElementById('reportReviewAdminError');
        const reportReviewReasonError = document.getElementById('reportReviewReasonError');
        const reportReviewAdminTrigger = document.getElementById('reportReviewAdminTrigger');
        const reportReviewReasonTrigger = document.getElementById('reportReviewReasonTrigger');

        function openReviewReportModal(reviewId) {
            if (!reviewReportModal || !reviewReportForm) {
                return;
            }
            if (reportReviewIdField) {
                reportReviewIdField.value = reviewId;
            }
            if (reportReviewAdmin) {
                reportReviewAdmin.value = '';
            }
            if (reportReviewReason) {
                reportReviewReason.value = '';
            }
            if (reportReviewDetails) {
                reportReviewDetails.value = '';
            }
            if (reportReviewAdminError) {
                reportReviewAdminError.textContent = '';
            }
            if (reportReviewReasonError) {
                reportReviewReasonError.textContent = '';
            }
            if (reportReviewAdminTrigger) {
                reportReviewAdminTrigger.classList.remove('is-error');
            }
            if (reportReviewReasonTrigger) {
                reportReviewReasonTrigger.classList.remove('is-error');
            }
            if (reportReviewDetails) {
                reportReviewDetails.classList.remove('is-error');
            }
            const adminLabel = document.getElementById('reportReviewAdminLabel');
            if (adminLabel) {
                adminLabel.textContent = '<?= htmlspecialchars($admin_placeholder) ?>';
            }
            document.querySelectorAll('.custom-select[data-target="reportReviewAdmin"] .custom-select-option').forEach((item) => {
                const isDefault = item.dataset.value === '';
                item.classList.toggle('is-selected', isDefault);
                item.setAttribute('aria-selected', isDefault ? 'true' : 'false');
            });
            const reasonLabel = document.getElementById('reportReviewReasonLabel');
            if (reasonLabel) {
                reasonLabel.textContent = 'Select a reason';
            }
            document.querySelectorAll('.custom-select[data-target="reportReviewReason"] .custom-select-option').forEach((item) => {
                const isDefault = item.dataset.value === '';
                item.classList.toggle('is-selected', isDefault);
                item.setAttribute('aria-selected', isDefault ? 'true' : 'false');
            });
            reviewReportModal.style.display = 'flex';
        }

        function closeReviewReportModal() {
            if (reviewReportModal) {
                reviewReportModal.style.display = 'none';
            }
        }

        if (reviewReportForm) {
            reviewReportForm.addEventListener('submit', function(e) {
                e.preventDefault();
            if (reportReviewAdminError) {
                reportReviewAdminError.textContent = '';
            }
            if (reportReviewReasonError) {
                reportReviewReasonError.textContent = '';
            }
            if (reportReviewAdminTrigger) {
                reportReviewAdminTrigger.classList.remove('is-error');
            }
            if (reportReviewReasonTrigger) {
                reportReviewReasonTrigger.classList.remove('is-error');
            }
            if (reportReviewDetails) {
                reportReviewDetails.classList.remove('is-error');
            }
            if (reportReviewAdmin && !reportReviewAdmin.disabled && !reportReviewAdmin.value) {
                if (reportReviewAdminError) {
                    reportReviewAdminError.textContent = 'Please select an admin.';
                }
                if (reportReviewAdminTrigger) {
                    reportReviewAdminTrigger.classList.add('is-error');
                }
                return;
            }
            if (!reportReviewReason || !reportReviewReason.value) {
                if (reportReviewReasonError) {
                    reportReviewReasonError.textContent = 'Please select a reason.';
                }
                if (reportReviewReasonTrigger) {
                    reportReviewReasonTrigger.classList.add('is-error');
                }
                return;
            }
            if (reportReviewReason.value === 'Other' && reportReviewDetails && !reportReviewDetails.value.trim()) {
                if (reportReviewReasonError) {
                    reportReviewReasonError.textContent = 'Please add details for "Other".';
                }
                reportReviewDetails.classList.add('is-error');
                return;
            }
                const formData = new FormData(reviewReportForm);
                formData.append('ajax', '1');
                fetch('staff_ModerateReviews.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.ok) {
                            const card = document.querySelector(`.review-card-modern[data-review-id="${reportReviewIdField ? reportReviewIdField.value : ''}"]`);
                            if (card) {
                                let badge = card.querySelector('.reported-badge');
                                if (!badge) {
                                    badge = document.createElement('span');
                                    badge.className = 'reported-badge';
                                    badge.innerHTML = '<i class="material-icons">flag</i>Reported';
                                    const header = card.querySelector('.review-card-header');
                                    if (header) {
                                        header.appendChild(badge);
                                    }
                                }
                                const detailText = data.details ? ` - ${data.details}` : '';
                                badge.title = `Reported: ${data.reason || ''}${detailText}`;
                            }
                            closeReviewReportModal();
                            showPageMessage('success', data.message || 'Review reported successfully.');
                        } else {
                            showPageMessage('error', data.message || 'Unable to report review.');
                        }
                    })
                    .catch(() => {
                        showPageMessage('error', 'Unable to report review.');
                    });
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeReviewReportModal();
            }
        });

        function submitReviewAction(action, reviewId) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('review_id', reviewId);
            formData.append('ajax', '1');
            fetch('staff_ModerateReviews.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.ok) {
                        const card = document.querySelector(`.review-card-modern[data-review-id="${reviewId}"]`);
                        if (card) {
                            card.dataset.status = data.status;
                            const badge = card.querySelector('.review-status-badge');
                            if (badge) {
                                const icon = data.status === 'Hidden' ? 'visibility_off' : 'check_circle';
                                badge.className = `review-status-badge status-${data.status.toLowerCase()}`;
                                badge.innerHTML = `<i class="material-icons">${icon}</i>${data.status}`;
                            }
                            const actions = card.querySelector('.review-actions-section');
                            if (actions) {
                                if (data.status === 'Hidden') {
                                    actions.innerHTML = `<button class="review-action-btn btn-show" type="button" onclick="showReview(${reviewId})"><i class="material-icons">visibility</i>Show Review</button><button class="review-action-btn btn-report" type="button" onclick="openReviewReportModal(${reviewId})"><i class="material-icons">flag</i>Report Review</button>`;
                                } else {
                                    actions.innerHTML = `<button class="review-action-btn btn-hide" type="button" onclick="hideReview(${reviewId})"><i class="material-icons">visibility_off</i>Hide Review</button><button class="review-action-btn btn-report" type="button" onclick="openReviewReportModal(${reviewId})"><i class="material-icons">flag</i>Report Review</button>`;
                                }
                            }
                        }
                        showPageMessage('success', data.message || 'Review updated.');
                    } else {
                        showPageMessage('error', data.message || 'Unable to update review.');
                    }
                })
                .catch(() => {
                    showPageMessage('error', 'Unable to update review.');
                });
        }

        function hideReview(reviewId) {
            if(confirm('Hide this review? It will no longer be visible to users.')) {
                submitReviewAction('hide', reviewId);
            }
        }

        function showReview(reviewId) {
            if(confirm('Make this review visible again?')) {
                submitReviewAction('show', reviewId);
            }
        }

        const loadMoreReviewsBtn = document.getElementById('loadMoreReviews');
        let reviewsOffset = <?= $offset + $page_size ?>;
        const reviewsPageSize = <?= $page_size ?>;

        if (loadMoreReviewsBtn) {
            loadMoreReviewsBtn.addEventListener('click', () => {
                loadMoreReviewsBtn.disabled = true;
                const originalText = loadMoreReviewsBtn.textContent;
                loadMoreReviewsBtn.textContent = 'Loading...';
                fetch(`staff_ModerateReviews.php?ajax=1&offset=${reviewsOffset}`, {
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.html) {
                            const list = document.querySelector('.reviews-list-modern');
                            if (list) {
                                list.insertAdjacentHTML('beforeend', data.html);
                                applyReviewFilters();
                            }
                        }
                        reviewsOffset = data.next_offset ?? (reviewsOffset + reviewsPageSize);
                        if (!data.has_more) {
                            loadMoreReviewsBtn.remove();
                        } else {
                            loadMoreReviewsBtn.disabled = false;
                            loadMoreReviewsBtn.textContent = originalText;
                        }
                    })
                    .catch(() => {
                        loadMoreReviewsBtn.disabled = false;
                        loadMoreReviewsBtn.textContent = originalText;
                        showPageMessage('error', 'Unable to load more reviews.');
                    });
            });
        }

    </script>
</body>
</html>
