<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
include '../global/dbConnection.php';

$staff_username = $_SESSION['username'] ?? '';
$page_message = null;
$requested_active_id = null;
$is_ajax_fetch = false;
$accepted_requests = $_SESSION['accepted_requests'] ?? [];
if (!is_array($accepted_requests)) {
    $accepted_requests = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'fetch_requests' && ($_POST['ajax'] ?? '') === '1') {
        $requested_active_id = (int)($_POST['active_id'] ?? 0);
        $is_ajax_fetch = true;
    } elseif ($action === 'accept_request') {
        $service_id = (int)($_POST['service_id'] ?? 0);
        $is_ajax = ($_POST['ajax'] ?? '') === '1';
        if ($service_id > 0) {
            $accepted_requests[] = $service_id;
            $accepted_requests = array_values(array_unique(array_map('intval', $accepted_requests)));
            $_SESSION['accepted_requests'] = $accepted_requests;
        }
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $service_id > 0]);
            exit;
        }
        header('Location: staff_CustomerSupportChat.php?id=' . $service_id);
        exit;
    } elseif ($action === 'reply_request') {
        $service_id = (int)($_POST['service_id'] ?? 0);
        $response = trim($_POST['response'] ?? '');
        $is_ajax = ($_POST['ajax'] ?? '') === '1';

        if ($service_id <= 0 || $response === '') {
            $_SESSION['support_flash'] = [
                'type' => 'error',
                'text' => 'Response message is required.'
            ];
        } elseif (!$connection) {
            $_SESSION['support_flash'] = [
                'type' => 'error',
                'text' => 'Database connection failed.'
            ];
        } else {
            $stmt = mysqli_prepare(
                $connection,
                "SELECT service_status FROM tbl_customer_service WHERE service_id = ?"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $service_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);

                if (!$row) {
                    $_SESSION['support_flash'] = [
                        'type' => 'error',
                        'text' => 'Support request not found.'
                    ];
                } elseif (strtoupper($row['service_status']) === 'COMPLETE') {
                    $_SESSION['support_flash'] = [
                        'type' => 'error',
                        'text' => 'This request already has a reply.'
                    ];
                } else {
                    $update = mysqli_prepare(
                        $connection,
                        "UPDATE tbl_customer_service
                         SET service_response = ?, service_status = 'COMPLETE'
                         WHERE service_id = ?"
                    );
                    if ($update) {
                        mysqli_stmt_bind_param($update, 'si', $response, $service_id);
                        mysqli_stmt_execute($update);
                        mysqli_stmt_close($update);
                        $_SESSION['support_flash'] = [
                            'type' => 'success',
                            'text' => 'Reply sent and request closed.'
                        ];
                    } else {
                        $_SESSION['support_flash'] = [
                            'type' => 'error',
                            'text' => 'Unable to save the reply.'
                        ];
                    }
                }
            } else {
                $_SESSION['support_flash'] = [
                    'type' => 'error',
                    'text' => 'Unable to load the request.'
                ];
            }
        }

        if ($is_ajax) {
            $flash = $_SESSION['support_flash'] ?? null;
            unset($_SESSION['support_flash']);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
                'message' => is_array($flash) ? $flash['text'] : 'Unable to send reply.'
            ]);
            exit;
        }

        header('Location: staff_CustomerSupportChat.php?id=' . $service_id);
        exit;
    } elseif ($action === 'report_user') {
        $receiver = trim($_POST['receiver'] ?? '');
        $service_id = (int)($_POST['service_id'] ?? 0);
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
        $is_ajax = ($_POST['ajax'] ?? '') === '1';

        if ($receiver === '' || $service_id <= 0 || $reason === '' || !in_array($reason, $allowed_reasons, true)) {
            $_SESSION['support_flash'] = [
                'type' => 'error',
                'text' => 'Please provide a valid report reason.'
            ];
        } elseif ($admin_receiver === '') {
            $_SESSION['support_flash'] = [
                'type' => 'error',
                'text' => 'Please select an admin.'
            ];
        } elseif ($reason === 'Other' && $details === '') {
            $_SESSION['support_flash'] = [
                'type' => 'error',
                'text' => 'Please add details for "Other".'
            ];
        } elseif (!$connection) {
            $_SESSION['support_flash'] = [
                'type' => 'error',
                'text' => 'Database connection failed.'
            ];
        } else {
            $admin_usernames = array_column(fetch_all_admins($connection), 'username');
            if (!in_array($admin_receiver, $admin_usernames, true)) {
                $_SESSION['support_flash'] = [
                    'type' => 'error',
                    'text' => 'Selected admin is unavailable.'
                ];
            } else {
                $sender = $staff_username !== '' ? $staff_username : 'staff';
                $content = "Username: {$receiver}\n"
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
                    $_SESSION['support_flash'] = [
                        'type' => 'success',
                        'text' => 'Report sent to the selected admin.'
                    ];
                } else {
                    $_SESSION['support_flash'] = [
                        'type' => 'error',
                        'text' => 'Unable to submit the report.'
                    ];
                }
            }
        }

        if ($is_ajax) {
            $flash = $_SESSION['support_flash'] ?? null;
            unset($_SESSION['support_flash']);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
                'message' => is_array($flash) ? $flash['text'] : 'Unable to submit the report.'
            ]);
            exit;
        }

        header('Location: staff_CustomerSupportChat.php?id=' . $service_id);
        exit;
    }
}

if (!empty($_SESSION['support_flash'])) {
    $page_message = $_SESSION['support_flash'];
    unset($_SESSION['support_flash']);
}

function fetch_user_profile($connection, string $username): array {
    $profile = [
        'name' => $username,
        'role' => 'user',
        'email' => '-',
        'phone' => '-',
        'member_since' => 'N/A',
        'total_rides' => '-'
    ];

    if (!$connection) {
        return $profile;
    }

    $stmt = mysqli_prepare($connection, "SELECT login_role FROM tbl_login WHERE login_username = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        if ($row) {
            $profile['role'] = strtolower($row['login_role']);
        }
        mysqli_stmt_close($stmt);
    }

    if (in_array($profile['role'], ['driver', 'user'], true)) {
        $stmt = mysqli_prepare($connection, "SELECT user_name, user_email, user_contact FROM tbl_user_info WHERE user_username = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            if ($row) {
                $profile['name'] = $row['user_name'] ?? $profile['name'];
                $profile['email'] = $row['user_email'] ?? $profile['email'];
                $profile['phone'] = $row['user_contact'] ?? $profile['phone'];
            }
            mysqli_stmt_close($stmt);
        }

        if ($profile['role'] === 'driver') {
            $stmt = mysqli_prepare($connection, "SELECT COUNT(*) AS total FROM tbl_ride_offer WHERE offer_driver_username = ?");
        } else {
            $stmt = mysqli_prepare($connection, "SELECT COUNT(*) AS total FROM tbl_booking WHERE booking_passenger_username = ?");
        }
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            if ($row) {
                $profile['total_rides'] = $row['total'] . ' rides';
            }
            mysqli_stmt_close($stmt);
        }
    } else {
        $stmt = mysqli_prepare($connection, "SELECT staff_name, staff_email, staff_contact FROM tbl_staff_info WHERE staff_username = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $username);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            if ($row) {
                $profile['name'] = $row['staff_name'] ?? $profile['name'];
                $profile['email'] = $row['staff_email'] ?? $profile['email'];
                $profile['phone'] = $row['staff_contact'] ?? $profile['phone'];
            }
            mysqli_stmt_close($stmt);
        }
    }

    return $profile;
}

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

$requests = [];
$stats = [
    'pending' => 0,
    'complete' => 0
];

if ($connection) {
    $stmt = mysqli_prepare(
        $connection,
        "SELECT cs.service_id,
                cs.service_username,
                cs.service_reason,
                cs.service_description,
                cs.service_response,
                cs.service_status,
                UPPER(l.login_role) AS login_role,
                COALESCE(u.user_name, cs.service_username) AS display_name
         FROM tbl_customer_service cs
         JOIN tbl_login l ON l.login_username = cs.service_username
         LEFT JOIN tbl_user_info u ON u.user_username = cs.service_username
         WHERE UPPER(l.login_role) IN ('USER', 'DRIVER')
         ORDER BY (cs.service_status = 'INCOMPLETE') DESC, cs.service_id DESC"
    );
    if ($stmt) {
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                    $requests[] = [
                        'service_id' => (int)$row['service_id'],
                        'service_username' => $row['service_username'],
                        'service_reason' => $row['service_reason'],
                        'service_description' => $row['service_description'],
                        'service_response' => $row['service_response'],
                        'service_status' => $row['service_status'],
                        'role' => strtolower($row['login_role']),
                        'display_name' => $row['display_name'],
                        'accepted' => in_array((int)$row['service_id'], $accepted_requests, true)
                    ];
                if (strtoupper($row['service_status']) === 'COMPLETE') {
                    $stats['complete']++;
                } else {
                    $stats['pending']++;
                }
            }
        }
        mysqli_stmt_close($stmt);
    } else {
        $page_message = [
            'type' => 'error',
            'text' => 'Unable to load support requests.'
        ];
    }
} else {
    $page_message = [
        'type' => 'error',
        'text' => 'Database connection failed.'
    ];
}

$role_counts = [
    'all' => count($requests),
    'user' => 0,
    'driver' => 0
];
foreach ($requests as $request) {
    if (isset($role_counts[$request['role']])) {
        $role_counts[$request['role']]++;
    }
}

$active_request_id = $requested_active_id !== null
    ? $requested_active_id
    : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$active_request = null;
foreach ($requests as $request) {
    if ($request['service_id'] === $active_request_id) {
        $active_request = $request;
        break;
    }
}

$active_profile = null;
if ($active_request) {
    $active_profile = fetch_user_profile($connection, $active_request['service_username']);
}

$body_class = (!empty($requests) || $active_request) ? 'chat-open' : 'chat-list';
$is_closed = $active_request && strtoupper($active_request['service_status']) === 'COMPLETE';
$is_accepted = $active_request ? in_array((int)$active_request['service_id'], $accepted_requests, true) : false;
$active_role = $active_profile ? $active_profile['role'] : ($active_request['role'] ?? '');
$active_name = $active_request ? ($active_profile ? $active_profile['name'] : $active_request['service_username']) : '';
$all_admins = fetch_all_admins($connection);
$has_admin_choices = !empty($all_admins);

function role_icon(string $role): string {
    if ($role === 'driver') {
        return 'directions_car';
    }
    if ($role === 'user') {
        return 'person';
    }
    return 'support_agent';
}

if ($is_ajax_fetch) {
    $active_payload = null;
    $profile_payload = null;
    if ($active_request) {
        $active_payload = [
            'service_id' => (int)$active_request['service_id'],
            'service_username' => $active_request['service_username'],
            'display_name' => $active_name,
            'role' => $active_request['role'],
            'service_reason' => $active_request['service_reason'],
            'service_description' => $active_request['service_description'],
            'service_response' => $active_request['service_response'],
            'service_status' => $active_request['service_status'],
            'accepted' => in_array((int)$active_request['service_id'], $accepted_requests, true)
        ];
    }
    if ($active_profile) {
        $profile_payload = [
            'name' => $active_profile['name'],
            'role' => $active_profile['role'],
            'email' => $active_profile['email'],
            'phone' => $active_profile['phone'],
            'member_since' => $active_profile['member_since'],
            'total_rides' => $active_profile['total_rides']
        ];
    }
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'stats' => $stats,
        'role_counts' => $role_counts,
        'requests' => $requests,
        'active_request' => $active_payload,
        'active_profile' => $profile_payload
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Support - RideShare@APU</title>
    <link rel="stylesheet" href="staff_base.css">
    <link rel="stylesheet" href="staff_CustomerSupportChat.css">
    <link rel="stylesheet" href="staff_shared.css">
    <link rel="stylesheet" href="../global/main.css">
    <link rel="stylesheet" href="../global/menu.css">
    <link rel="stylesheet" href="../global/footer.css">
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.5.0/css/all.css">
    <link href="https://fonts.googleapis.com/css2?family=Bowlby+One&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($body_class) ?> info-collapsed" data-explicit-id="<?= isset($_GET['id']) && trim($_GET['id']) !== '' ? '1' : '0' ?>">
    <?php include 'staffMenu.php'; ?>

    <main class="support-chat-main">
        <div class="page-header-section">
            <div class="header-content">
                <h1><i class="material-icons">support_agent</i> Customer Support</h1>
                <p>Handle one-time support requests from users and drivers.</p>
                <?php if ($page_message): ?>
                    <div class="page-message <?= htmlspecialchars($page_message['type']) ?>">
                        <?= htmlspecialchars($page_message['text']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-actions chat-header-stats">
                <div class="stat-mini">
                    <span class="stat-value" id="supportOpenCount"><?= (int)$stats['pending'] ?></span>
                    <span class="stat-label">Open</span>
                </div>
                <div class="stat-mini">
                    <span class="stat-value" id="supportClosedCount"><?= (int)$stats['complete'] ?></span>
                    <span class="stat-label">Closed</span>
                </div>
            </div>
        </div>

        <div class="chat-container-modern">
            <aside class="chat-sidebar">
                <div class="chat-sidebar-top">
                    <div class="chat-sidebar-header">
                        <h2>Requests</h2>
                        <button class="refresh-btn" type="button" onclick="fetchRequests()" aria-label="Refresh requests">
                            <i class="material-icons">refresh</i>
                        </button>
                    </div>
                    <div class="role-tabs">
                        <button class="role-tab active" type="button" data-role="all">
                            <i class="material-icons">group</i>
                            All
                            <span class="tab-count" id="tabCountAll"><?= (int)$role_counts['all'] ?></span>
                        </button>
                        <button class="role-tab" type="button" data-role="user">
                            <i class="material-icons">person</i>
                            Users
                            <span class="tab-count" id="tabCountUsers"><?= (int)$role_counts['user'] ?></span>
                        </button>
                        <button class="role-tab" type="button" data-role="driver">
                            <i class="material-icons">directions_car</i>
                            Drivers
                            <span class="tab-count" id="tabCountDrivers"><?= (int)$role_counts['driver'] ?></span>
                        </button>
                    </div>
                    <div class="chat-search">
                        <i class="material-icons">search</i>
                        <input id="chatSearchInput" type="text" placeholder="Search by name or username">
                    </div>
                </div>

                <div class="chat-list-modern" id="requestList">
                    <?php if (empty($requests)): ?>
                        <div class="empty-chat-list">No support requests yet.</div>
                    <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                            <?php
                            $is_active = $active_request && $request['service_id'] === $active_request_id;
                            $status_closed = strtoupper($request['service_status']) === 'COMPLETE';
                            ?>
                            <a
                                class="chat-list-item<?= $is_active ? ' active' : '' ?>"
                                href="staff_CustomerSupportChat.php?id=<?= (int)$request['service_id'] ?>"
                                data-service-id="<?= (int)$request['service_id'] ?>"
                                data-role="<?= htmlspecialchars($request['role']) ?>"
                                data-name="<?= htmlspecialchars($request['display_name']) ?>"
                                data-username="<?= htmlspecialchars($request['service_username']) ?>"
                            >
                                <div class="chat-avatar-wrapper">
                                    <div class="chat-avatar">
                                        <i class="material-icons"><?= role_icon($request['role']) ?></i>
                                    </div>
                                </div>
                                <div class="chat-preview">
                                    <div class="chat-preview-header">
                                        <h4><?= htmlspecialchars($request['display_name']) ?></h4>
                                        <span class="chat-time">#<?= (int)$request['service_id'] ?></span>
                                    </div>
                                    <div class="chat-preview-body">
                                        <p class="last-message"><?= htmlspecialchars($request['service_reason']) ?></p>
                                        <?php if ($status_closed): ?>
                                            <span class="chat-closed-label">Closed</span>
                                        <?php else: ?>
                                            <span class="unread-count">Request</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="chat-window-modern">
                <?php if (!$active_request): ?>
                    <div class="empty-chat">Select a request to view details.</div>
                <?php else: ?>
                    <div class="chat-window-header">
                        <div class="chat-user-info">
                            <a class="chat-back-btn" href="staff_CustomerSupportChat.php" aria-label="Back to list">
                                <i class="material-icons">arrow_back</i>
                            </a>
                            <div class="chat-avatar-large">
                                <i class="material-icons" id="activeRequestRoleIcon"><?= role_icon($active_role ?? '') ?></i>
                            </div>
                            <div class="user-info-details">
                                <h3 id="activeRequestName"><?= htmlspecialchars($active_name ?? '') ?></h3>
                                <div class="user-status">
                                    <span class="status-dot <?= $is_closed ? '' : 'active' ?>" id="activeRequestStatusDot"></span>
                                    <span id="activeRequestStatusText"><?= $is_closed ? 'Closed' : 'Open' ?> request</span>
                                </div>
                                <div class="user-status reason">Reason: <span id="activeRequestReason"><?= htmlspecialchars($active_request['service_reason'] ?? '') ?></span></div>
                            </div>
                        </div>
                        <div class="chat-header-actions">
                            <button class="header-action-btn" type="button" title="View Profile" onclick="viewProfile()">
                                <i class="material-icons">account_circle</i>
                            </button>
                            <button class="header-action-btn" type="button" title="Full Screen" onclick="toggleFullScreen()" aria-label="Toggle full screen">
                                <i class="material-icons" id="fullscreenIcon">fullscreen</i>
                            </button>
                            <button
                                class="header-action-btn"
                                type="button"
                                title="Report User"
                                aria-label="Report user"
                                onclick="openReportModal()"
                                <?= $active_request ? '' : 'disabled' ?>
                            >
                                <i class="material-icons">flag</i>
                            </button>
                            <div class="action-tooltip" id="reportActionTooltip" role="status" aria-live="polite">
                                <i class="material-icons">warning</i>
                                Please select an item in the list.
                            </div>
                        </div>
                    </div>

                    <div class="chat-messages-area">
                        <div class="message-wrapper">
                            <div class="message-avatar">
                                <i class="material-icons" id="activeRequestMessageRoleIcon"><?= role_icon($active_role ?? '') ?></i>
                            </div>
                            <div class="message-content">
                                <div class="message-bubble" id="activeRequestDescription">
                                    <?= nl2br(htmlspecialchars($active_request['service_description'] ?? '')) ?>
                                </div>
                                <div class="message-time">Request ID: #<span id="activeRequestIdDisplay"><?= (int)($active_request['service_id'] ?? 0) ?></span></div>
                            </div>
                        </div>

                        <div id="activeRequestResponseWrapper">
                            <?php if (!empty($active_request['service_response'])): ?>
                                <div class="message-wrapper message-staff">
                                    <div class="message-avatar">
                                        <i class="material-icons">support_agent</i>
                                    </div>
                                    <div class="message-content">
                                        <div class="message-bubble">
                                            <?= nl2br(htmlspecialchars($active_request['service_response'])) ?>
                                        </div>
                                        <div class="message-time">Staff reply</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form class="chat-input-area" method="post">
                        <input type="hidden" name="action" value="reply_request">
                        <input type="hidden" name="service_id" value="<?= (int)($active_request['service_id'] ?? 0) ?>" id="activeRequestIdField">
                        <input type="hidden" id="activeRequestUserField" value="<?= htmlspecialchars($active_request['service_username'] ?? '') ?>">
                        <textarea
                            class="chat-input-field"
                            id="activeResponseInput"
                            name="response"
                            placeholder="<?= $is_closed ? 'Request closed.' : ($is_accepted ? 'Type your reply (one reply only)' : 'Accept the request to reply') ?>"
                            rows="2"
                            <?= ($is_closed || !$is_accepted) ? 'disabled' : '' ?>
                        ></textarea>
                        <button class="accept-request-btn" id="acceptRequestBtn" type="button" <?= ($is_closed || $is_accepted) ? 'disabled' : '' ?> onclick="acceptRequest()" style="<?= ($is_closed || $is_accepted) ? 'display:none;' : '' ?>">
                            <i class="material-icons">check_circle</i>
                            Accept
                        </button>
                        <button class="send-message-btn" id="activeReplyButton" type="submit" <?= ($is_closed || !$is_accepted) ? 'disabled' : '' ?> aria-label="Send reply">
                            <i class="material-icons">send</i>
                        </button>
                    </form>
                <?php endif; ?>
            </section>

            <aside class="chat-info-panel">
                <?php if ($active_profile): ?>
                    <div class="info-panel-header">
                        <h3>User Info</h3>
                        <button class="info-toggle-btn" type="button" onclick="toggleInfoPanel()" aria-label="Toggle info panel">
                            <i class="material-icons" id="infoPanelToggleIcon">chevron_left</i>
                        </button>
                    </div>
                    <div class="info-panel-body">
                        <div class="info-avatar-section">
                            <div class="info-avatar-large">
                                <i class="material-icons" id="infoPanelRoleIcon"><?= role_icon($active_profile['role']) ?></i>
                            </div>
                            <h3 id="infoPanelName"><?= htmlspecialchars($active_profile['name']) ?></h3>
                            <span class="info-username" id="infoPanelUsername">@<?= htmlspecialchars($active_request['service_username']) ?></span>
                            <span class="info-role-badge role-<?= htmlspecialchars($active_profile['role']) ?>" id="infoPanelRole">
                                <?= strtoupper($active_profile['role']) ?>
                            </span>
                        </div>
                        <div class="info-details-list">
                            <div class="info-detail-item">
                                <i class="material-icons">mail</i>
                                <div>
                                    <span class="detail-label">Email</span>
                                    <strong id="infoPanelEmail"><?= htmlspecialchars($active_profile['email']) ?></strong>
                                </div>
                            </div>
                            <div class="info-detail-item">
                                <i class="material-icons">phone</i>
                                <div>
                                    <span class="detail-label">Phone</span>
                                    <strong id="infoPanelPhone"><?= htmlspecialchars($active_profile['phone']) ?></strong>
                                </div>
                            </div>
                            <div class="info-detail-item">
                                <i class="material-icons">directions_car</i>
                                <div>
                                    <span class="detail-label">Total Rides</span>
                                    <strong id="infoPanelTotalRides"><?= htmlspecialchars($active_profile['total_rides']) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="info-panel-header">
                        <h3>User Info</h3>
                    </div>
                    <div class="info-panel-body">
                        <div class="empty-chat">Select a request to view user info.</div>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </main>

    <div class="modal-overlay" id="reportUserModal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="material-icons">flag</i> Report User</h2>
            </div>
            <div class="modal-body">
                <form id="reportUserForm" method="post" novalidate>
                    <input type="hidden" name="action" value="report_user">
                    <input type="hidden" name="receiver" id="reportUserReceiver">
                    <input type="hidden" name="service_id" id="reportServiceId">
                    <div class="form-group">
                        <label for="reportAdminSelect"><i class="material-icons">admin_panel_settings</i> Send to admin</label>
                        <div class="custom-select" data-target="reportAdminSelect" data-open="false">
                            <button type="button" class="custom-select-trigger" id="reportAdminTrigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="custom-select-label" id="reportAdminLabel"><?= htmlspecialchars($has_admin_choices ? 'Select an admin' : 'No admins available') ?></span>
                                <i class="material-icons" aria-hidden="true">expand_more</i>
                            </button>
                            <div class="custom-select-dropdown" role="listbox" aria-labelledby="reportAdminTrigger">
                                <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="">
                                    <?= htmlspecialchars($has_admin_choices ? 'Select an admin' : 'No admins available') ?>
                                </button>
                                <?php foreach ($all_admins as $admin): ?>
                                    <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="<?= htmlspecialchars($admin['username']) ?>">
                                        <?= htmlspecialchars($admin['name']) ?> (@<?= htmlspecialchars($admin['username']) ?>)
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <select class="sr-only" id="reportAdminSelect" name="admin_receiver" <?= $has_admin_choices ? '' : 'disabled' ?>>
                            <option value="" selected><?= $has_admin_choices ? 'Select an admin' : 'No admins available' ?></option>
                            <?php foreach ($all_admins as $admin): ?>
                                <option value="<?= htmlspecialchars($admin['username']) ?>"><?= htmlspecialchars($admin['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-error" id="reportAdminError"></span>
                    </div>
                    <div class="form-group">
                        <label for="reportReasonSelect"><i class="material-icons">flag</i> Report reason</label>
                        <div class="custom-select" data-target="reportReasonSelect" data-open="false">
                            <button type="button" class="custom-select-trigger" id="reportReasonTrigger" aria-haspopup="listbox" aria-expanded="false">
                                <span class="custom-select-label" id="reportReasonLabel">Select a reason</span>
                                <i class="material-icons" aria-hidden="true">expand_more</i>
                            </button>
                            <div class="custom-select-dropdown" role="listbox" aria-labelledby="reportReasonTrigger">
                                <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="">Select a reason</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Spam or repetitive content">Spam or repetitive content</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Harassment or abusive language">Harassment or abusive language</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Inappropriate content">Inappropriate content</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Scam or suspicious request">Scam or suspicious request</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Other">Other</button>
                            </div>
                        </div>
                        <select class="sr-only" id="reportReasonSelect" name="reason" required>
                            <option value="" selected>Select a reason</option>
                            <option value="Spam or repetitive content">Spam or repetitive content</option>
                            <option value="Harassment or abusive language">Harassment or abusive language</option>
                            <option value="Inappropriate content">Inappropriate content</option>
                            <option value="Scam or suspicious request">Scam or suspicious request</option>
                            <option value="Other">Other</option>
                        </select>
                        <span class="field-error" id="reportReasonError"></span>
                    </div>
                    <div class="form-group">
                        <label for="reportDetails"><i class="material-icons">description</i> Details (optional)</label>
                        <textarea id="reportDetails" name="details" placeholder="Add any extra context..."></textarea>
                        <span class="field-error" id="reportDetailsError"></span>
                    </div>
                    <div class="form-actions">
                        <button class="btn-secondary" type="button" onclick="closeReportModal()">Cancel</button>
                        <button class="btn-primary" type="submit" <?= $has_admin_choices ? '' : 'disabled' ?>>
                            <i class="material-icons">send</i> Submit Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include '../global/footer.php'; ?>

    <script src="staff_custom_select.js"></script>
    <script>
        const searchInput = document.getElementById('chatSearchInput');
        const roleTabs = document.querySelectorAll('.role-tab');
        let activeRoleFilter = 'all';
        let isFetching = false;

        function roleIcon(role) {
            if (role === 'driver') {
                return 'directions_car';
            }
            if (role === 'user') {
                return 'person';
            }
            return 'support_agent';
        }

        function applyFilters() {
            const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
            document.querySelectorAll('.chat-list-item').forEach((item) => {
                const role = item.dataset.role;
                const name = (item.dataset.name || '').toLowerCase();
                const username = (item.dataset.username || '').toLowerCase();
                const matchesRole = activeRoleFilter === 'all' || role === activeRoleFilter;
                const matchesSearch = !query || name.includes(query) || username.includes(query);
                item.style.display = (matchesRole && matchesSearch) ? 'flex' : 'none';
            });
        }

        function updateStats(stats, roleCounts) {
            const openEl = document.getElementById('supportOpenCount');
            const closedEl = document.getElementById('supportClosedCount');
            const tabAll = document.getElementById('tabCountAll');
            const tabUsers = document.getElementById('tabCountUsers');
            const tabDrivers = document.getElementById('tabCountDrivers');
            if (openEl) openEl.textContent = stats.pending ?? 0;
            if (closedEl) closedEl.textContent = stats.complete ?? 0;
            if (tabAll) tabAll.textContent = roleCounts.all ?? 0;
            if (tabUsers) tabUsers.textContent = roleCounts.user ?? 0;
            if (tabDrivers) tabDrivers.textContent = roleCounts.driver ?? 0;
        }

        function renderRequestList(requests, activeId) {
            const list = document.getElementById('requestList');
            if (!list) {
                return;
            }
            list.innerHTML = '';
            if (!requests || requests.length === 0) {
                list.innerHTML = '<div class="empty-chat-list">No support requests yet.</div>';
                return;
            }
            requests.forEach((request) => {
                const isActive = request.service_id === activeId;
                const isClosed = (request.service_status || '').toUpperCase() === 'COMPLETE';
                const anchor = document.createElement('a');
                anchor.className = `chat-list-item${isActive ? ' active' : ''}`;
                anchor.href = `staff_CustomerSupportChat.php?id=${request.service_id}`;
                anchor.dataset.serviceId = request.service_id;
                anchor.dataset.role = request.role;
                anchor.dataset.name = request.display_name;
                anchor.dataset.username = request.service_username;

                const avatarWrap = document.createElement('div');
                avatarWrap.className = 'chat-avatar-wrapper';
                const avatar = document.createElement('div');
                avatar.className = 'chat-avatar';
                const icon = document.createElement('i');
                icon.className = 'material-icons';
                icon.textContent = roleIcon(request.role);
                avatar.appendChild(icon);
                avatarWrap.appendChild(avatar);

                const preview = document.createElement('div');
                preview.className = 'chat-preview';
                const previewHeader = document.createElement('div');
                previewHeader.className = 'chat-preview-header';
                const name = document.createElement('h4');
                name.textContent = request.display_name;
                const time = document.createElement('span');
                time.className = 'chat-time';
                time.textContent = `#${request.service_id}`;
                previewHeader.appendChild(name);
                previewHeader.appendChild(time);

                const previewBody = document.createElement('div');
                previewBody.className = 'chat-preview-body';
                const reason = document.createElement('p');
                reason.className = 'last-message';
                reason.textContent = request.service_reason;
                previewBody.appendChild(reason);
                if (isClosed) {
                    const closed = document.createElement('span');
                    closed.className = 'chat-closed-label';
                    closed.textContent = 'Closed';
                    previewBody.appendChild(closed);
                } else {
                    const open = document.createElement('span');
                    open.className = 'unread-count';
                    open.textContent = 'Request';
                    previewBody.appendChild(open);
                }

                preview.appendChild(previewHeader);
                preview.appendChild(previewBody);

                anchor.appendChild(avatarWrap);
                anchor.appendChild(preview);
                list.appendChild(anchor);
            });
            applyFilters();
        }

        function updateActiveRequest(activeRequest, profile) {
            if (!activeRequest) {
                return;
            }
            const isClosed = (activeRequest.service_status || '').toUpperCase() === 'COMPLETE';
            const isAccepted = !!activeRequest.accepted;
            const role = activeRequest.role || '';

            const nameEl = document.getElementById('activeRequestName');
            if (nameEl) nameEl.textContent = activeRequest.display_name || activeRequest.service_username;
            const statusDot = document.getElementById('activeRequestStatusDot');
            if (statusDot) statusDot.classList.toggle('active', !isClosed);
            const statusText = document.getElementById('activeRequestStatusText');
            if (statusText) statusText.textContent = `${isClosed ? 'Closed' : 'Open'} request`;
            const reasonEl = document.getElementById('activeRequestReason');
            if (reasonEl) reasonEl.textContent = activeRequest.service_reason || '';
            const descEl = document.getElementById('activeRequestDescription');
            if (descEl) descEl.textContent = activeRequest.service_description || '';
            const idDisplay = document.getElementById('activeRequestIdDisplay');
            if (idDisplay) idDisplay.textContent = activeRequest.service_id;
            const idField = document.getElementById('activeRequestIdField');
            if (idField) idField.value = activeRequest.service_id;
            const userField = document.getElementById('activeRequestUserField');
            if (userField) userField.value = activeRequest.service_username;

            const roleIconEl = document.getElementById('activeRequestRoleIcon');
            const roleIconMessageEl = document.getElementById('activeRequestMessageRoleIcon');
            if (roleIconEl) roleIconEl.textContent = roleIcon(role);
            if (roleIconMessageEl) roleIconMessageEl.textContent = roleIcon(role);

            const responseWrap = document.getElementById('activeRequestResponseWrapper');
            if (responseWrap) {
                responseWrap.innerHTML = '';
                if (activeRequest.service_response) {
                    const wrapper = document.createElement('div');
                    wrapper.className = 'message-wrapper message-staff';
                    const avatar = document.createElement('div');
                    avatar.className = 'message-avatar';
                    const icon = document.createElement('i');
                    icon.className = 'material-icons';
                    icon.textContent = 'support_agent';
                    avatar.appendChild(icon);
                    const content = document.createElement('div');
                    content.className = 'message-content';
                    const bubble = document.createElement('div');
                    bubble.className = 'message-bubble';
                    bubble.textContent = activeRequest.service_response;
                    const time = document.createElement('div');
                    time.className = 'message-time';
                    time.textContent = 'Staff reply';
                    content.appendChild(bubble);
                    content.appendChild(time);
                    wrapper.appendChild(avatar);
                    wrapper.appendChild(content);
                    responseWrap.appendChild(wrapper);
                }
            }

            const responseInput = document.getElementById('activeResponseInput');
            const replyButton = document.getElementById('activeReplyButton');
            const acceptButton = document.getElementById('acceptRequestBtn');
            if (responseInput) {
                responseInput.disabled = isClosed || !isAccepted;
                responseInput.placeholder = isClosed
                    ? 'Request closed.'
                    : (isAccepted ? 'Type your reply (one reply only)' : 'Accept the request to reply');
            }
            if (replyButton) replyButton.disabled = isClosed || !isAccepted;
            if (acceptButton) {
                acceptButton.disabled = isClosed || isAccepted;
                acceptButton.style.display = (isClosed || isAccepted) ? 'none' : 'inline-flex';
            }

            if (profile) {
                const infoName = document.getElementById('infoPanelName');
                const infoUser = document.getElementById('infoPanelUsername');
                const infoRole = document.getElementById('infoPanelRole');
                const infoRoleIcon = document.getElementById('infoPanelRoleIcon');
                const infoEmail = document.getElementById('infoPanelEmail');
                const infoPhone = document.getElementById('infoPanelPhone');
                const infoSince = document.getElementById('infoPanelMemberSince');
                const infoRides = document.getElementById('infoPanelTotalRides');
                if (infoName) infoName.textContent = profile.name || activeRequest.display_name;
                if (infoUser) infoUser.textContent = `@${activeRequest.service_username}`;
                if (infoRole) {
                    infoRole.textContent = (profile.role || '').toUpperCase();
                    infoRole.classList.remove('role-driver', 'role-user', 'role-staff', 'role-admin');
                    if (profile.role) {
                        infoRole.classList.add(`role-${profile.role}`);
                    }
                }
                if (infoRoleIcon && profile.role) {
                    infoRoleIcon.textContent = roleIcon(profile.role);
                }
                if (infoEmail) infoEmail.textContent = profile.email || '-';
                if (infoPhone) infoPhone.textContent = profile.phone || '-';
                if (infoSince) infoSince.textContent = profile.member_since || 'N/A';
                if (infoRides) infoRides.textContent = profile.total_rides || '-';
            }
        }

        function fetchRequests(activeIdOverride) {
            if (isFetching) {
                return;
            }
            isFetching = true;
            const formData = new FormData();
            formData.append('action', 'fetch_requests');
            formData.append('ajax', '1');
            const activeIdField = document.getElementById('activeRequestIdField');
            const activeIdValue = typeof activeIdOverride !== 'undefined'
                ? String(activeIdOverride)
                : (activeIdField ? activeIdField.value : '0');
            formData.append('active_id', activeIdValue);
            fetch('staff_CustomerSupportChat.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data.ok) {
                        return;
                    }
                    updateStats(data.stats || {}, data.role_counts || {});
                    const activeId = data.active_request ? data.active_request.service_id : 0;
                    renderRequestList(data.requests || [], activeId);
                    if (data.active_request) {
                        updateActiveRequest(data.active_request, data.active_profile || null);
                    }
                    if (activeIdField && activeId) {
                        activeIdField.value = activeId;
                    }
                })
                .catch(() => {})
                .finally(() => {
                    isFetching = false;
                });
        }

        function fetchRequestsFor(activeId) {
            const activeIdField = document.getElementById('activeRequestIdField');
            if (activeIdField) {
                activeIdField.value = activeId;
            }
            fetchRequests(activeId);
        }

        function acceptRequest() {
            const activeIdField = document.getElementById('activeRequestIdField');
            const serviceId = activeIdField ? parseInt(activeIdField.value || '0', 10) : 0;
            if (!serviceId) {
                return;
            }
            const formData = new FormData();
            formData.append('action', 'accept_request');
            formData.append('service_id', serviceId);
            formData.append('ajax', '1');
            fetch('staff_CustomerSupportChat.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.ok) {
                        fetchRequestsFor(serviceId);
                    }
                })
                .catch(() => {});
        }

        roleTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                roleTabs.forEach((node) => node.classList.remove('active'));
                tab.classList.add('active');
                activeRoleFilter = tab.dataset.role || 'all';
                applyFilters();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }

        function toggleInfoPanel() {
            if (document.body.classList.contains('show-info')) {
                document.body.classList.remove('show-info');
                return;
            }
            const container = document.querySelector('.chat-container-modern');
            if (!container) {
                return;
            }
            const isCollapsed = container.classList.toggle('collapsed-info');
            document.body.classList.toggle('info-collapsed', isCollapsed);
            const icon = document.getElementById('infoPanelToggleIcon');
            if (icon) {
                icon.textContent = isCollapsed ? 'chevron_right' : 'chevron_left';
            }
        }

        function syncInfoPanelState() {
            const container = document.querySelector('.chat-container-modern');
            const icon = document.getElementById('infoPanelToggleIcon');
            if (!container) {
                return;
            }
            const isCollapsed = container.classList.contains('collapsed-info');
            document.body.classList.toggle('info-collapsed', isCollapsed);
            if (icon) {
                icon.textContent = isCollapsed ? 'chevron_right' : 'chevron_left';
            }
        }

        function viewProfile() {
            const panel = document.querySelector('.chat-info-panel');
            if (!panel) {
                return;
            }
            const isMobile = window.matchMedia('(max-width: 768px)').matches;
            if (isMobile) {
                const container = document.querySelector('.chat-container-modern');
                if (container && container.classList.contains('collapsed-info')) {
                    container.classList.remove('collapsed-info');
                    document.body.classList.remove('info-collapsed');
                }
                document.body.classList.add('show-info');
                document.body.classList.remove('chat-list');
                document.body.classList.add('chat-open');
                return;
            }
            const container = document.querySelector('.chat-container-modern');
            if (container && container.classList.contains('collapsed-info')) {
                container.classList.remove('collapsed-info');
                document.body.classList.remove('info-collapsed');
                const icon = document.getElementById('infoPanelToggleIcon');
                if (icon) {
                    icon.textContent = 'chevron_left';
                }
            }
            panel.scrollIntoView({ behavior: 'smooth' });
        }

        function setFullScreenState(isFull) {
            const icon = document.getElementById('fullscreenIcon');
            if (icon) {
                icon.textContent = isFull ? 'fullscreen_exit' : 'fullscreen';
            }
            document.body.classList.toggle('fullscreen-chat', isFull);
        }

        function toggleFullScreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen?.().then(() => {
                    setFullScreenState(true);
                }).catch(() => {});
            } else {
                document.exitFullscreen?.().finally(() => {
                    setFullScreenState(false);
                });
            }
        }

        document.addEventListener('fullscreenchange', () => {
            setFullScreenState(!!document.fullscreenElement);
        });

        function openReportModal() {
            const modal = document.getElementById('reportUserModal');
            const userField = document.getElementById('activeRequestUserField');
            const serviceField = document.getElementById('activeRequestIdField');
            const receiverInput = document.getElementById('reportUserReceiver');
            const serviceInput = document.getElementById('reportServiceId');
            const tooltip = document.getElementById('reportActionTooltip');
            if (!modal || !userField || !serviceField || !receiverInput || !serviceInput) {
                return;
            }
            if (!userField.value || !serviceField.value) {
                if (tooltip) {
                    tooltip.classList.add('is-visible');
                    clearTimeout(tooltip._hideTimer);
                    tooltip._hideTimer = setTimeout(() => {
                        tooltip.classList.remove('is-visible');
                    }, 2500);
                }
                return;
            }
            if (tooltip) {
                tooltip.classList.remove('is-visible');
                clearTimeout(tooltip._hideTimer);
            }
            document.body.classList.add('modal-open');
            receiverInput.value = userField.value;
            serviceInput.value = serviceField.value;
            const adminSelect = document.getElementById('reportAdminSelect');
            const reasonSelect = document.getElementById('reportReasonSelect');
            if (adminSelect) {
                adminSelect.value = '';
            }
            if (reasonSelect) {
                reasonSelect.value = '';
            }
            document.getElementById('reportDetails').value = '';
            const detailsField = document.getElementById('reportDetails');
            if (detailsField) {
                detailsField.classList.remove('is-error');
            }
            document.getElementById('reportAdminError').textContent = '';
            document.getElementById('reportReasonError').textContent = '';
            document.getElementById('reportDetailsError').textContent = '';
            const adminTrigger = document.getElementById('reportAdminTrigger');
            const reasonTrigger = document.getElementById('reportReasonTrigger');
            if (adminTrigger) {
                adminTrigger.classList.remove('is-error');
            }
            if (reasonTrigger) {
                reasonTrigger.classList.remove('is-error');
            }
            const adminLabel = document.getElementById('reportAdminLabel');
            if (adminLabel) {
                adminLabel.textContent = '<?= htmlspecialchars($has_admin_choices ? 'Select an admin' : 'No admins available') ?>';
            }
            document.querySelectorAll('.custom-select[data-target="reportAdminSelect"] .custom-select-option').forEach((item) => {
                const isDefault = item.dataset.value === '';
                item.classList.toggle('is-selected', isDefault);
                item.setAttribute('aria-selected', isDefault ? 'true' : 'false');
            });
            const reasonLabel = document.getElementById('reportReasonLabel');
            if (reasonLabel) {
                reasonLabel.textContent = 'Select a reason';
            }
            document.querySelectorAll('.custom-select[data-target="reportReasonSelect"] .custom-select-option').forEach((item) => {
                const isDefault = item.dataset.value === '';
                item.classList.toggle('is-selected', isDefault);
                item.setAttribute('aria-selected', isDefault ? 'true' : 'false');
            });
            modal.style.display = 'flex';
        }

        function closeReportModal() {
            const modal = document.getElementById('reportUserModal');
            if (modal) {
                modal.style.display = 'none';
            }
            const tooltip = document.getElementById('reportActionTooltip');
            if (tooltip) {
                tooltip.classList.remove('is-visible');
                clearTimeout(tooltip._hideTimer);
            }
            document.body.classList.remove('modal-open');
        }

        const reportForm = document.getElementById('reportUserForm');
        if (reportForm) {
            reportForm.addEventListener('submit', (event) => {
                const adminSelect = document.getElementById('reportAdminSelect');
                const reasonSelect = document.getElementById('reportReasonSelect');
                const detailsField = document.getElementById('reportDetails');
                const adminError = document.getElementById('reportAdminError');
                const reasonError = document.getElementById('reportReasonError');
                const detailsError = document.getElementById('reportDetailsError');
                const adminTrigger = document.getElementById('reportAdminTrigger');
                const reasonTrigger = document.getElementById('reportReasonTrigger');
                const reportTooltip = document.getElementById('reportActionTooltip');
                event.preventDefault();
                adminError.textContent = '';
                reasonError.textContent = '';
                detailsError.textContent = '';
                if (adminTrigger) {
                    adminTrigger.classList.remove('is-error');
                }
                if (reasonTrigger) {
                    reasonTrigger.classList.remove('is-error');
                }
                if (reportTooltip) {
                    reportTooltip.classList.remove('is-visible');
                }

                if (adminSelect && !adminSelect.disabled && !adminSelect.value) {
                    adminError.textContent = 'Please select an admin.';
                    if (adminTrigger) {
                        adminTrigger.classList.add('is-error');
                    }
                    return;
                }
                if (!reasonSelect.value) {
                    reasonError.textContent = 'Please select a reason.';
                    if (reasonTrigger) {
                        reasonTrigger.classList.add('is-error');
                    }
                    return;
                } else if (reasonSelect.value === 'Other' && !detailsField.value.trim()) {
                    detailsError.textContent = 'Please add details for "Other".';
                    detailsField.classList.add('is-error');
                    return;
                }

                const formData = new FormData(reportForm);
                formData.append('ajax', '1');
                fetch('staff_CustomerSupportChat.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.ok) {
                            closeReportModal();
                        } else {
                            const reasonError = document.getElementById('reportReasonError');
                            if (reasonError) {
                                reasonError.textContent = data.message || 'Unable to submit report.';
                            }
                        }
                    })
                    .catch(() => {
                        const reasonError = document.getElementById('reportReasonError');
                        if (reasonError) {
                            reasonError.textContent = 'Unable to submit report.';
                        }
                    });
            });
        }

        const replyForm = document.querySelector('.chat-input-area');
        if (replyForm) {
            replyForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const responseInput = document.getElementById('activeResponseInput');
                const serviceField = document.getElementById('activeRequestIdField');
                if (!responseInput || !serviceField || responseInput.disabled) {
                    return;
                }
                const message = responseInput.value.trim();
                if (!message) {
                    return;
                }
                const formData = new FormData();
                formData.append('action', 'reply_request');
                formData.append('service_id', serviceField.value);
                formData.append('response', message);
                formData.append('ajax', '1');
                fetch('staff_CustomerSupportChat.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.ok) {
                            responseInput.value = '';
                            fetchRequestsFor(parseInt(serviceField.value, 10));
                        }
                    })
                    .catch(() => {});
            });

            replyForm.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    replyForm.dispatchEvent(new Event('submit'));
                }
            });
        }

        document.addEventListener('click', (event) => {
            const modal = document.getElementById('reportUserModal');
            if (!modal || modal.style.display === 'none') {
                return;
            }
            if (event.target === modal) {
                closeReportModal();
            }
        });

        const requestList = document.getElementById('requestList');
        if (requestList) {
            requestList.addEventListener('click', (event) => {
                const item = event.target.closest('.chat-list-item');
                if (!item) {
                    return;
                }
                event.preventDefault();
                if (!document.querySelector('.chat-messages-area')) {
                    window.location.href = item.getAttribute('href');
                    return;
                }
                const serviceId = parseInt(item.dataset.serviceId || '0', 10);
                if (!serviceId) {
                    return;
                }
                requestList.querySelectorAll('.chat-list-item.active').forEach((node) => {
                    node.classList.remove('active');
                });
                item.classList.add('active');
                document.body.classList.remove('show-info');
                document.body.classList.remove('chat-list');
                document.body.classList.add('chat-open');
                fetchRequestsFor(serviceId);
                if (window.history && window.history.pushState) {
                    window.history.pushState({}, '', `staff_CustomerSupportChat.php?id=${serviceId}`);
                }
            });
        }

        const initialActiveField = document.getElementById('activeRequestIdField');
        const explicitId = document.body.dataset.explicitId === '1';
        const isMobile = window.matchMedia('(max-width: 768px)').matches;
        if (isMobile && !explicitId) {
            document.body.classList.remove('chat-open', 'show-info');
            document.body.classList.add('chat-list');
            if (initialActiveField) {
                initialActiveField.value = '';
            }
        }
        if (initialActiveField && initialActiveField.value && !(isMobile && !explicitId)) {
            const initialId = parseInt(initialActiveField.value, 10);
            if (initialId) {
                fetchRequestsFor(initialId);
            }
        }
        if (isMobile && document.body.classList.contains('chat-open')) {
            const container = document.querySelector('.chat-container-modern');
            if (container && container.classList.contains('collapsed-info')) {
                container.classList.remove('collapsed-info');
                document.body.classList.remove('info-collapsed');
            }
        }
        const infoContainer = document.querySelector('.chat-container-modern');
        if (infoContainer) {
            infoContainer.classList.add('collapsed-info');
            document.body.classList.add('info-collapsed');
        }
        syncInfoPanelState();
    </script>
</body>
</html>
