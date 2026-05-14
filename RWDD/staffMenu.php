<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
$username = $_SESSION['username'] ?? 'teating';
if ($connection) {
    $stmt = mysqli_prepare($connection, "SELECT staff_name, staff_email FROM tbl_staff_info WHERE staff_username = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $staff_info = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);
    }
}

$fullname = $staff_info['staff_name'] ?? ($_SESSION['fullname'] ?? '');
?>

<link href="https://fonts.googleapis.com/css2?family=Bowlby+One&family=Ultra&display=swap" rel="stylesheet">

<header>
    <div onclick="showMenu('leftSideBar')" class="menuIcon"><i class="fa-solid fa-bars click-icon"></i></div>
    <div class="title" style="font-family: 'Bowlby One', sans-serif;">RideShare@APU</div>
</header>

<div class="menu-overlay" onclick="closeMenu('leftSideBar')"></div>

<div class="leftSideBar">
    <div class="closeButton">
        <i onclick="closeMenu('leftSideBar')" class="fa-solid fa-xmark click-icon"></i>
    </div>

    <a href="/RWDD_Assignment_Group10/staff/staff_Profile.php" class="profile">
        <div class="username"><?= htmlspecialchars($username) ?></div>
        <div class="fullname"><?= htmlspecialchars($fullname) ?></div>
    </a>

    <div class="line"></div>

    <nav class="menu">
        <ul>
            <li><a href="/RWDD_Assignment_Group10/staff/staff_Dashboard.php">Main Page</a></li>
            <li><a href="/RWDD_Assignment_Group10/staff/staff_ManageRideOffers.php">Manage Ride Offers</a></li>
            <li><a href="/RWDD_Assignment_Group10/staff/staff_PollManagement.php">Manage Polls</a></li>
            <li><a href="/RWDD_Assignment_Group10/staff/staff_CustomerSupportChat.php">Customer Support</a></li>
            <li><a href="/RWDD_Assignment_Group10/global/chat.php">Message</a></li>
            <li><a href="/RWDD_Assignment_Group10/global/announcement.php">Annoucements</a></li>
            <li><a href="/RWDD_Assignment_Group10/staff/staff_FAQManagement.php">FAQ Management</a></li>
            <li><a href="/RWDD_Assignment_Group10/staff/staff_ModerateReviews.php">Reviews</a></li>
        </ul>
    </nav>

    <div class="logout">
        <a href="../logout.php">
            <button><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</button>
        </a>
    </div>
</div>

<script src="../global/main.js"></script>
