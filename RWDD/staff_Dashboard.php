<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
include '../global/dbConnection.php';

$username = $_SESSION['username'] ?? '';

$stats = [
    'active_rides' => 0,
    'pending_reports' => 0,
    'active_polls' => 0,
    'unread_chats' => 0,
    'total_faqs' => 0,
    'new_reviews' => 0
];

if ($connection) {
    $stats['active_rides'] = (int)mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT COUNT(*) AS total FROM tbl_ride_offer WHERE offer_status <> 'INACTIVE'"
    ))['total'];

    $stats['pending_reports'] = (int)mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT COUNT(*) AS total FROM tbl_customer_service WHERE service_status = 'INCOMPLETE'"
    ))['total'];

    $stats['active_polls'] = (int)mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT COUNT(*) AS total FROM tbl_poll"
    ))['total'];

    $stmt = mysqli_prepare($connection, "SELECT COUNT(*) AS total FROM tbl_message WHERE message_receiver = ? AND message_status = 'UNSEEN'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $stats['unread_chats'] = (int)($result ? mysqli_fetch_assoc($result)['total'] : 0);
        mysqli_stmt_close($stmt);
    }

    $stats['total_faqs'] = (int)mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT COUNT(*) AS total FROM tbl_faq"
    ))['total'];

    $stats['new_reviews'] = (int)mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT COUNT(*) AS total FROM tbl_review WHERE review_created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    ))['total'];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - RideShare@APU</title>
    <link rel="stylesheet" href="staff_base.css">
    <link rel="stylesheet" href="staff_Dashboard.css">
    <link rel="stylesheet" href="staff_shared.css">
    <link rel="stylesheet" href="../global/main.css">
    <link rel="stylesheet" href="../global/footer.css">
    <link rel="stylesheet" href="../global/menu.css">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bowlby+One&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.5.0/css/all.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body>
    <?php include 'staffMenu.php'; ?>

    <main class="dashboard-main">
        <div class="dashboard-header">
            <div class="welcome-section">
                <h1>Welcome back, <?= htmlspecialchars($fullname) ?>!</h1>
                <p class="subtitle">Here's what's happening with RideShare@APU today</p>
            </div>
            <div class="date-time">
                <i class="material-icons">calendar_today</i>
                <span id="current-date"></span>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card stat-primary">
                <div class="stat-icon">
                    <i class="material-icons">directions_car</i>
                </div>
                <div class="stat-content">
                    <h3><?= $stats['active_rides'] ?></h3>
                    <p>Active Rides</p>
                </div>
            </div>

            <div class="stat-card stat-warning">
                <div class="stat-icon">
                    <i class="material-icons">report_problem</i>
                </div>
                <div class="stat-content">
                    <h3><?= $stats['pending_reports'] ?></h3>
                    <p>Incomplete Requests</p>
                </div>
            </div>

            <div class="stat-card stat-info">
                <div class="stat-icon">
                    <i class="material-icons">chat</i>
                </div>
                <div class="stat-content">
                    <h3><?= $stats['unread_chats'] ?></h3>
                    <p>Unread Messages</p>
                </div>
            </div>

            <div class="stat-card stat-success">
                <div class="stat-icon">
                    <i class="material-icons">poll</i>
                </div>
                <div class="stat-content">
                    <h3><?= $stats['active_polls'] ?></h3>
                    <p>Active Polls</p>
                </div>
            </div>

            <div class="stat-card stat-review">
                <div class="stat-icon">
                    <i class="material-icons">star</i>
                </div>
                <div class="stat-content">
                    <h3><?= $stats['new_reviews'] ?></h3>
                    <p>New Reviews</p>
                </div>
            </div>

            <div class="stat-card stat-faq">
                <div class="stat-icon">
                    <i class="material-icons">help</i>
                </div>
                <div class="stat-content">
                    <h3><?= $stats['total_faqs'] ?></h3>
                    <p>Total FAQs</p>
                </div>
            </div>
        </div>

            <!-- Quick Actions -->
            <div class="quick-actions-section">
                <h2><i class="material-icons">bolt</i> Quick Actions</h2>
                <div class="quick-actions-grid">
                    <a href="staff_CustomerSupportChat.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">support_agent</i>
                        </div>
                        <h4>Customer Support</h4>
                        <p>Respond to queries</p>
                    </a>

                    <a href="staff_ModerateReviews.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">flag</i>
                        </div>
                        <h4>Reviews</h4>
                        <p>Review flagged content</p>
                    </a>

                    <a href="staff_PollManagement.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">poll</i>
                        </div>
                        <h4>Manage Polls</h4>
                        <p>View poll results</p>
                    </a>

                    <a href="staff_FAQManagement.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">question_answer</i>
                        </div>
                        <h4>FAQ Management</h4>
                        <p>Update FAQs</p>
                    </a>

                    <a href="staff_ManageRideOffers.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">local_offer</i>
                        </div>
                        <h4>Manage Ride Offers</h4>
                        <p>Manage listings</p>
                    </a>

                    <a href="/RWDD_Assignment_Group10/global/chat.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">assignment</i>
                        </div>
                        <h4>Message</h4>
                        <p>Coordinate reports</p>
                    </a>

                    <a href="staff_Announcements.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">campaign</i>
                        </div>
                        <h4>Announcements</h4>
                        <p>Post updates</p>
                    </a>

                    <a href="staff_Profile.php" class="quick-action-card">
                        <div class="qa-icon">
                            <i class="material-icons">account_circle</i>
                        </div>
                        <h4>Profile</h4>
                        <p>Edit details</p>
                    </a>
                </div>
            </div>
        </div>
    </main>

    <?php include '../global/footer.php'; ?>

    <script>
        // Display current date
        const dateElement = document.getElementById('current-date');
        const today = new Date();
        const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        dateElement.textContent = today.toLocaleDateString('en-US', options);
    </script>
</body>
</html>
