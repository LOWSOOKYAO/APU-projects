<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
include '../global/dbConnection.php';

$page_message = null;
$create_poll_errors = [];
$create_poll_values = [
    'title' => '',
    'description' => ''
];
$show_create_modal = false;

$seen_poll_ids = array_map('intval', $_SESSION['poll_seen_polls'] ?? []);
$hidden_vote_ids = array_map('intval', $_SESSION['poll_hidden_votes'] ?? []);
$selected_poll_id = (int)($_SESSION['poll_selected_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $is_ajax = ($_POST['ajax'] ?? '') === '1';

    if ($action === 'create_poll') {
        $create_poll_values['title'] = trim($_POST['title'] ?? '');
        $create_poll_values['description'] = trim($_POST['description'] ?? '');

        if ($create_poll_values['title'] === '') {
            $create_poll_errors['title'] = 'This field is required.';
        }
        if ($create_poll_values['description'] === '') {
            $create_poll_errors['description'] = 'This field is required.';
        }
        if (!$connection) {
            $create_poll_errors['form'] = 'Database connection failed.';
        }

        if (empty($create_poll_errors)) {
            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO tbl_poll (poll_title, poll_description) VALUES (?, ?)"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "ss", $create_poll_values['title'], $create_poll_values['description']);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['poll_flash'] = [
                    'type' => 'success',
                    'text' => 'Poll created successfully.'
                ];
                header('Location: staff_PollManagement.php');
                exit;
            }

            $create_poll_errors['form'] = 'Unable to create poll.';
        }

        $show_create_modal = true;
    } elseif ($action === 'hide_vote') {
        $vote_id = (int)($_POST['vote_id'] ?? 0);
        if ($vote_id > 0) {
            $hidden_vote_ids[] = $vote_id;
            $_SESSION['poll_hidden_votes'] = array_values(array_unique($hidden_vote_ids));
            $_SESSION['poll_flash'] = [
                'type' => 'success',
                'text' => 'Response hidden from the list.'
            ];
        } else {
            $_SESSION['poll_flash'] = [
                'type' => 'error',
                'text' => 'Unable to hide the response.'
            ];
        }
        if ($is_ajax) {
            $flash = $_SESSION['poll_flash'] ?? null;
            unset($_SESSION['poll_flash']);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
                'message' => is_array($flash) ? $flash['text'] : 'Unable to hide the response.'
            ]);
            exit;
        }

        header('Location: staff_PollManagement.php');
        exit;
    } elseif ($action === 'next_poll') {
        $poll_id = (int)($_POST['poll_id'] ?? 0);
        if ($poll_id > 0) {
            $seen_poll_ids[] = $poll_id;
            $_SESSION['poll_seen_polls'] = array_values(array_unique($seen_poll_ids));
            $_SESSION['poll_flash'] = [
                'type' => 'success',
                'text' => 'Moved to polls you\'ve seen.'
            ];
        } else {
            $_SESSION['poll_flash'] = [
                'type' => 'error',
                'text' => 'Unable to move the poll.'
            ];
        }

        if ($is_ajax) {
            $flash = $_SESSION['poll_flash'] ?? null;
            unset($_SESSION['poll_flash']);
            $poll_payload = null;
            if ($poll_id > 0 && $connection) {
                $poll_stmt = mysqli_prepare(
                    $connection,
                    "SELECT poll_title, poll_created_at FROM tbl_poll WHERE poll_id = ?"
                );
                if ($poll_stmt) {
                    mysqli_stmt_bind_param($poll_stmt, "i", $poll_id);
                    mysqli_stmt_execute($poll_stmt);
                    $poll_result = mysqli_stmt_get_result($poll_stmt);
                    $poll_row = $poll_result ? mysqli_fetch_assoc($poll_result) : null;
                    mysqli_stmt_close($poll_stmt);
                } else {
                    $poll_row = null;
                }

                $agree_count = 0;
                $disagree_count = 0;
                $total_votes = 0;
                $count_stmt = mysqli_prepare(
                    $connection,
                    "SELECT vote_response, COUNT(*) AS total
                     FROM tbl_vote
                     WHERE vote_poll_id = ?
                     GROUP BY vote_response"
                );
                if ($count_stmt) {
                    mysqli_stmt_bind_param($count_stmt, "i", $poll_id);
                    mysqli_stmt_execute($count_stmt);
                    $count_result = mysqli_stmt_get_result($count_stmt);
                    if ($count_result) {
                        while ($row = mysqli_fetch_assoc($count_result)) {
                            $count = (int)$row['total'];
                            $total_votes += $count;
                            if (strtoupper($row['vote_response']) === 'AGREE') {
                                $agree_count = $count;
                            } else {
                                $disagree_count = $count;
                            }
                        }
                    }
                    mysqli_stmt_close($count_stmt);
                }

                if ($poll_row) {
                    $poll_payload = [
                        'id' => $poll_id,
                        'title' => $poll_row['poll_title'],
                        'created_at' => $poll_row['poll_created_at'],
                        'closed_at' => date('Y-m-d'),
                        'total_votes' => $total_votes,
                        'agree_votes' => $agree_count,
                        'disagree_votes' => $disagree_count
                    ];
                }
            }
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
                'message' => is_array($flash) ? $flash['text'] : 'Unable to move the poll.',
                'poll' => $poll_payload
            ]);
            exit;
        }

        header('Location: staff_PollManagement.php');
        exit;
    } elseif ($action === 'delete_poll') {
        $poll_id = (int)($_POST['poll_id'] ?? 0);
        if ($poll_id <= 0) {
            $_SESSION['poll_flash'] = [
                'type' => 'error',
                'text' => 'Unable to delete the poll.'
            ];
        } elseif (!$connection) {
            $_SESSION['poll_flash'] = [
                'type' => 'error',
                'text' => 'Database connection failed.'
            ];
        } else {
            $vote_ids = [];
            $stmt = mysqli_prepare($connection, "SELECT vote_id FROM tbl_vote WHERE vote_poll_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $poll_id);
                mysqli_stmt_execute($stmt);
                $vote_result = mysqli_stmt_get_result($stmt);
                if ($vote_result) {
                    while ($row = mysqli_fetch_assoc($vote_result)) {
                        $vote_ids[] = (int)$row['vote_id'];
                    }
                }
                mysqli_stmt_close($stmt);
            }

            $delete_ok = true;
            mysqli_begin_transaction($connection);

            $stmt = mysqli_prepare($connection, "DELETE FROM tbl_vote WHERE vote_poll_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $poll_id);
                $delete_ok = mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                $delete_ok = false;
            }

            if ($delete_ok) {
                $stmt = mysqli_prepare($connection, "DELETE FROM tbl_poll WHERE poll_id = ?");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, "i", $poll_id);
                    $delete_ok = mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                } else {
                    $delete_ok = false;
                }
            }

            if ($delete_ok) {
                mysqli_commit($connection);
                if ($selected_poll_id === $poll_id) {
                    unset($_SESSION['poll_selected_id']);
                }
                if (!empty($vote_ids)) {
                    $hidden_vote_ids = array_values(array_diff($hidden_vote_ids, $vote_ids));
                    $_SESSION['poll_hidden_votes'] = $hidden_vote_ids;
                }
                $seen_poll_ids = array_values(array_diff($seen_poll_ids, [$poll_id]));
                $_SESSION['poll_seen_polls'] = $seen_poll_ids;
                $_SESSION['poll_flash'] = [
                    'type' => 'success',
                    'text' => 'Poll deleted successfully.'
                ];
            } else {
                mysqli_rollback($connection);
                $_SESSION['poll_flash'] = [
                    'type' => 'error',
                    'text' => 'Unable to delete the poll.'
                ];
            }
        }
        if ($is_ajax) {
            $flash = $_SESSION['poll_flash'] ?? null;
            unset($_SESSION['poll_flash']);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
                'message' => is_array($flash) ? $flash['text'] : 'Unable to delete the poll.'
            ]);
            exit;
        }

        header('Location: staff_PollManagement.php');
        exit;
    } elseif ($action === 'select_poll') {
        $poll_id = (int)($_POST['poll_id'] ?? 0);
        if ($poll_id > 0) {
            $_SESSION['poll_selected_id'] = $poll_id;
        } else {
            unset($_SESSION['poll_selected_id']);
        }
        if ($is_ajax) {
            if (!$connection) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'message' => 'Database connection failed.'
                ]);
                exit;
            }
            if ($poll_id <= 0) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'message' => 'Invalid poll selection.'
                ]);
                exit;
            }

            $poll_row = null;
            $poll_stmt = mysqli_prepare(
                $connection,
                "SELECT poll_id, poll_title, poll_description, poll_created_at
                 FROM tbl_poll
                 WHERE poll_id = ?"
            );
            if ($poll_stmt) {
                mysqli_stmt_bind_param($poll_stmt, "i", $poll_id);
                mysqli_stmt_execute($poll_stmt);
                $poll_result = mysqli_stmt_get_result($poll_stmt);
                $poll_row = $poll_result ? mysqli_fetch_assoc($poll_result) : null;
                mysqli_stmt_close($poll_stmt);
            }

            if (!$poll_row) {
                header('Content-Type: application/json');
                echo json_encode([
                    'ok' => false,
                    'message' => 'Poll not found.'
                ]);
                exit;
            }

            $votes_payload = [];
            $vote_stmt = mysqli_prepare(
                $connection,
                "SELECT v.vote_id, v.vote_username, v.vote_response, v.vote_reason, ui.user_name
                 FROM tbl_vote v
                 LEFT JOIN tbl_user_info ui ON v.vote_username = ui.user_username
                 WHERE v.vote_poll_id = ?
                 ORDER BY v.vote_id DESC"
            );
            if ($vote_stmt) {
                mysqli_stmt_bind_param($vote_stmt, "i", $poll_id);
                mysqli_stmt_execute($vote_stmt);
                $vote_result = mysqli_stmt_get_result($vote_stmt);
                if ($vote_result) {
                    while ($row = mysqli_fetch_assoc($vote_result)) {
                        $vote_id = (int)$row['vote_id'];
                        if (in_array($vote_id, $hidden_vote_ids, true)) {
                            continue;
                        }
                        $response = strtoupper($row['vote_response']) === 'AGREE' ? 'Agree' : 'Disagree';
                        $comment = trim($row['vote_reason'] ?? '');
                        $comment_empty = $comment === '';
                        if ($comment_empty) {
                            $comment = 'No comment provided.';
                        }
                        $votes_payload[] = [
                            'vote_id' => $vote_id,
                            'user' => $row['vote_username'],
                            'full_name' => $row['user_name'] ?? $row['vote_username'],
                            'result' => $response,
                            'comment' => $comment,
                            'comment_empty' => $comment_empty
                        ];
                    }
                }
                mysqli_stmt_close($vote_stmt);
            }

            $agree_count = 0;
            $disagree_count = 0;
            $total_votes = 0;
            $count_stmt = mysqli_prepare(
                $connection,
                "SELECT vote_response, COUNT(*) AS total
                 FROM tbl_vote
                 WHERE vote_poll_id = ?
                 GROUP BY vote_response"
            );
            if ($count_stmt) {
                mysqli_stmt_bind_param($count_stmt, "i", $poll_id);
                mysqli_stmt_execute($count_stmt);
                $count_result = mysqli_stmt_get_result($count_stmt);
                if ($count_result) {
                    while ($row = mysqli_fetch_assoc($count_result)) {
                        $count = (int)$row['total'];
                        $total_votes += $count;
                        if (strtoupper($row['vote_response']) === 'AGREE') {
                            $agree_count = $count;
                        } else {
                            $disagree_count = $count;
                        }
                    }
                }
                mysqli_stmt_close($count_stmt);
            }

            $agree_percent = $total_votes > 0 ? round(($agree_count / $total_votes) * 100) : 0;
            $disagree_percent = $total_votes > 0 ? 100 - $agree_percent : 0;

            $trend_label = 'No votes yet';
            $trend_sub = 'Waiting for votes';
            if ($total_votes > 0) {
                if ($agree_percent === $disagree_percent) {
                    $trend_label = 'Tie';
                } else {
                    $trend_label = ($agree_percent > $disagree_percent ? 'Agree' : 'Disagree') . ' Leading';
                }
                $trend_sub = 'Current Trend';
            }

            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'poll' => [
                    'id' => (int)$poll_row['poll_id'],
                    'title' => $poll_row['poll_title'],
                    'description' => $poll_row['poll_description'],
                    'created_at' => $poll_row['poll_created_at'],
                    'total_votes' => $total_votes,
                    'agree_count' => $agree_count,
                    'disagree_count' => $disagree_count,
                    'agree_percent' => $agree_percent,
                    'disagree_percent' => $disagree_percent,
                    'trend_label' => $trend_label,
                    'trend_sub' => $trend_sub
                ],
                'votes' => $votes_payload
            ]);
            exit;
        }
        header('Location: staff_PollManagement.php');
        exit;
    }
}

if (!empty($_SESSION['poll_flash'])) {
    $page_message = $_SESSION['poll_flash'];
    unset($_SESSION['poll_flash']);
}

$current_poll = [
    "poll_id" => 0,
    "title" => "No active poll",
    "description" => "Create a poll to start collecting votes.",
    "options" => ["Agree", "Disagree"],
    "created_at" => date('Y-m-d'),
    "status" => "Active",
    "total_votes" => 0
];

$votes = [];
$past_polls = [];
$agree_count = 0;
$disagree_count = 0;
$poll_rows = [];
$open_poll_rows = [];

if ($connection) {
    $result = mysqli_query(
        $connection,
        "SELECT poll_id, poll_title, poll_description, poll_created_at
         FROM tbl_poll
         ORDER BY poll_created_at DESC"
    );
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $poll_rows[] = $row;
        }
    }

    $poll_lookup = [];
    foreach ($poll_rows as $row) {
        $poll_lookup[(int)$row['poll_id']] = $row;
    }
    $open_poll_rows = array_values(array_filter($poll_rows, function ($row) use ($seen_poll_ids) {
        return !in_array((int)$row['poll_id'], $seen_poll_ids, true);
    }));
    if ($selected_poll_id === 0 || !isset($poll_lookup[$selected_poll_id]) || in_array($selected_poll_id, $seen_poll_ids, true)) {
        $selected_poll_id = !empty($open_poll_rows) ? (int)$open_poll_rows[0]['poll_id'] : 0;
        if ($selected_poll_id > 0) {
            $_SESSION['poll_selected_id'] = $selected_poll_id;
        } else {
            unset($_SESSION['poll_selected_id']);
        }
    }
    if ($selected_poll_id > 0 && isset($poll_lookup[$selected_poll_id])) {
        $row = $poll_lookup[$selected_poll_id];
        $current_poll = [
            "poll_id" => (int)$row['poll_id'],
            "title" => $row['poll_title'],
            "description" => $row['poll_description'],
            "options" => ["Agree", "Disagree"],
            "created_at" => $row['poll_created_at'],
            "status" => "Active",
            "total_votes" => 0
        ];
    }

    if ($current_poll['poll_id'] > 0) {
        $stmt = mysqli_prepare(
            $connection,
            "SELECT v.vote_id, v.vote_username, v.vote_response, v.vote_reason, ui.user_name
             FROM tbl_vote v
             LEFT JOIN tbl_user_info ui ON v.vote_username = ui.user_username
             WHERE v.vote_poll_id = ?
             ORDER BY v.vote_id DESC"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $current_poll['poll_id']);
            mysqli_stmt_execute($stmt);
            $vote_result = mysqli_stmt_get_result($stmt);
            if ($vote_result) {
                while ($row = mysqli_fetch_assoc($vote_result)) {
                    $vote_id = (int)$row['vote_id'];
                    if (in_array($vote_id, $hidden_vote_ids, true)) {
                        continue;
                    }
                    $response = strtoupper($row['vote_response']) === 'AGREE' ? 'Agree' : 'Disagree';
                    $comment = trim($row['vote_reason'] ?? '');
                    $comment_empty = $comment === '';
                    if ($comment_empty) {
                        $comment = 'No comment provided.';
                    }
                    $votes[] = [
                        "vote_id" => $vote_id,
                        "user" => $row['vote_username'],
                        "full_name" => $row['user_name'] ?? $row['vote_username'],
                        "result" => $response,
                        "comment" => $comment,
                        "comment_empty" => $comment_empty,
                        "date" => $current_poll['created_at']
                    ];
                }
            }
            mysqli_stmt_close($stmt);
        }

        $stmt = mysqli_prepare(
            $connection,
            "SELECT vote_response, COUNT(*) AS total
             FROM tbl_vote
             WHERE vote_poll_id = ?
             GROUP BY vote_response"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $current_poll['poll_id']);
            mysqli_stmt_execute($stmt);
            $count_result = mysqli_stmt_get_result($stmt);
            $total_votes = 0;
            if ($count_result) {
                while ($row = mysqli_fetch_assoc($count_result)) {
                    $count = (int)$row['total'];
                    $total_votes += $count;
                    if (strtoupper($row['vote_response']) === 'AGREE') {
                        $agree_count = $count;
                    } else {
                        $disagree_count = $count;
                    }
                }
            }
            $current_poll['total_votes'] = $total_votes;
            mysqli_stmt_close($stmt);
        }
    }

    foreach ($poll_rows as $row) {
        $poll_id = (int)$row['poll_id'];
        if ($poll_id === $current_poll['poll_id']) {
            continue;
        }
        if (!in_array($poll_id, $seen_poll_ids, true)) {
            continue;
        }
        $count_result = mysqli_query(
            $connection,
            "SELECT vote_response, COUNT(*) AS total
             FROM tbl_vote
             WHERE vote_poll_id = {$poll_id}
             GROUP BY vote_response"
        );
        $total_votes = 0;
        $poll_agree = 0;
        $poll_disagree = 0;
        if ($count_result) {
            while ($count_row = mysqli_fetch_assoc($count_result)) {
                $count = (int)$count_row['total'];
                $total_votes += $count;
                if (strtoupper($count_row['vote_response']) === 'AGREE') {
                    $poll_agree = $count;
                } else {
                    $poll_disagree = $count;
                }
            }
        }
        $past_polls[] = [
            "poll_id" => $poll_id,
            "title" => $row['poll_title'],
            "status" => "Seen",
            "total_votes" => $total_votes,
            "agree_votes" => $poll_agree,
            "disagree_votes" => $poll_disagree,
            "created" => $row['poll_created_at'],
            "closed" => $row['poll_created_at']
        ];
    }

}

if ($current_poll['poll_id'] === 0) {
    $agree_count = 0;
    $disagree_count = 0;
}

// Calculate vote percentages
$agree_percent = $current_poll['total_votes'] > 0 ? round(($agree_count / $current_poll['total_votes']) * 100) : 0;
$disagree_percent = $current_poll['total_votes'] > 0 ? 100 - $agree_percent : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Poll Management - RideShare@APU</title>
    <link rel="stylesheet" href="staff_base.css">
    <link rel="stylesheet" href="staff_PollManagement.css">
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

    <main class="poll-management-main">
        <div class="page-header-section">
            <div class="header-content">
                <h1><i class="material-icons">poll</i> Poll Management</h1>
                <p class="page-subtitle">View and manage community polls and voting results</p>
                <?php if ($page_message): ?>
                    <div class="page-message <?= htmlspecialchars($page_message['type']) ?>">
                        <?= htmlspecialchars($page_message['text']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-actions">
                <button class="btn-primary create-poll-btn" type="button" onclick="openCreatePollModal()">
                    <i class="material-icons">add</i>
                    Create Poll
                </button>
            </div>
        </div>

        <div class="poll-switcher-row">
            <form class="poll-switcher" method="POST" action="">
                <input type="hidden" name="action" value="select_poll">
                <label for="pollSwitcherInput" class="sr-only">Switch poll</label>
                <input type="hidden" name="poll_id" id="pollSwitcherInput" value="<?= (int)$current_poll['poll_id'] ?>">
                <div class="poll-switcher-custom" data-open="false">
                    <button type="button" class="poll-switcher-trigger" id="pollSwitcherTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span class="poll-switcher-label" id="pollSwitcherLabel"><?= htmlspecialchars($current_poll['title']) ?></span>
                        <i class="material-icons" aria-hidden="true">expand_more</i>
                    </button>
                    <div class="poll-switcher-dropdown" role="listbox" aria-labelledby="pollSwitcherTrigger">
                        <?php foreach ($open_poll_rows as $row): ?>
                            <?php $poll_id = (int)$row['poll_id']; ?>
                            <button
                                type="button"
                                class="poll-switcher-option<?= $poll_id === $current_poll['poll_id'] ? ' is-selected' : '' ?>"
                                role="option"
                                aria-selected="<?= $poll_id === $current_poll['poll_id'] ? 'true' : 'false' ?>"
                                data-value="<?= $poll_id ?>">
                                <?= htmlspecialchars($row['poll_title']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Active Poll Section -->
        <div class="active-poll-container">
            <div class="poll-header-card">
                <div class="poll-title-section">
                    <h2><?= htmlspecialchars($current_poll['title']) ?></h2>
                    <p><?= htmlspecialchars($current_poll['description']) ?></p>
                </div>
            </div>

            <!-- Vote Statistics -->
            <div class="poll-stats-container">
                <div class="vote-breakdown">
                    <div class="vote-option-card vote-agree">
                        <div class="vote-icon">
                            <i class="material-icons">thumb_up</i>
                        </div>
                        <div class="vote-details">
                            <h3>Agree</h3>
                            <div class="vote-count"><?= $agree_count ?> votes</div>
                            <div class="vote-percentage"><?= $agree_percent ?>%</div>
                        </div>
                        <div class="vote-progress-vertical">
                            <div class="progress-fill-vertical" style="height: <?= $agree_percent ?>%"></div>
                        </div>
                    </div>

                    <div class="vote-option-card vote-disagree">
                        <div class="vote-icon">
                            <i class="material-icons">thumb_down</i>
                        </div>
                        <div class="vote-details">
                            <h3>Disagree</h3>
                            <div class="vote-count"><?= $disagree_count ?> votes</div>
                            <div class="vote-percentage"><?= $disagree_percent ?>%</div>
                        </div>
                        <div class="vote-progress-vertical">
                            <div class="progress-fill-vertical" style="height: <?= $disagree_percent ?>%"></div>
                        </div>
                    </div>
                </div>

                <div class="poll-overview-card">
                    <h3><i class="material-icons">insights</i> Poll Overview</h3>
                    <div class="overview-stats">
                        <div class="overview-item">
                            <i class="material-icons">how_to_vote</i>
                            <div>
                                <strong><?= $current_poll['total_votes'] ?></strong>
                                <span>Total Votes</span>
                            </div>
                        </div>
                        <div class="overview-item">
                            <i class="material-icons">event</i>
                            <div>
                                <strong><?= date('M d, Y', strtotime($current_poll['created_at'])) ?></strong>
                                <span>Started</span>
                            </div>
                        </div>
                        <div class="overview-item">
                            <i class="material-icons">trending_up</i>
                            <div>
                                <strong><?=
                                    $current_poll['total_votes'] > 0
                                        ? ($agree_percent === $disagree_percent
                                            ? 'Tie'
                                            : ($agree_percent > $disagree_percent ? 'Agree' : 'Disagree') . ' Leading')
                                        : 'No votes yet'
                                ?></strong>
                                <span><?= $current_poll['total_votes'] > 0 ? 'Current Trend' : 'Waiting for votes' ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="poll-actions-section">
                        <button class="poll-action-btn btn-close" type="button" onclick="nextPoll(<?= (int)$current_poll['poll_id'] ?>)" <?= $current_poll['poll_id'] ? '' : 'disabled' ?>>
                            <i class="material-icons">arrow_forward</i>
                            Next Poll
                        </button>
                        <button class="poll-action-btn btn-delete" type="button" onclick="deleteCurrentPoll(<?= (int)$current_poll['poll_id'] ?>)" <?= $current_poll['poll_id'] ? '' : 'disabled' ?>>
                            <i class="material-icons">delete</i>
                            Delete Poll
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Responses -->
        <div class="responses-section">
            <div class="responses-header">
                <h2><i class="material-icons">comment</i> User Responses (<?= count($votes) ?>)</h2>
                <div class="responses-filters">
                    <div class="custom-select" data-target="responseFilter" data-open="false">
                        <button type="button" class="custom-select-trigger" id="responseFilterTrigger" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-label" id="responseFilterLabel">All Responses</span>
                            <i class="material-icons" aria-hidden="true">expand_more</i>
                        </button>
                        <div class="custom-select-dropdown" role="listbox" aria-labelledby="responseFilterTrigger">
                            <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="all">All Responses</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Agree">Agree Only</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Disagree">Disagree Only</button>
                        </div>
                    </div>
                    <select class="sr-only" id="responseFilter" onchange="filterResponses()">
                        <option value="all" selected>All Responses</option>
                        <option value="Agree">Agree Only</option>
                        <option value="Disagree">Disagree Only</option>
                    </select>

                    <div class="custom-select" data-target="sortResponses" data-open="false">
                        <button type="button" class="custom-select-trigger" id="sortResponsesTrigger" aria-haspopup="listbox" aria-expanded="false">
                            <span class="custom-select-label" id="sortResponsesLabel">Newest First</span>
                            <i class="material-icons" aria-hidden="true">expand_more</i>
                        </button>
                        <div class="custom-select-dropdown" role="listbox" aria-labelledby="sortResponsesTrigger">
                            <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="newest">Newest First</button>
                            <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="oldest">Oldest First</button>
                        </div>
                    </div>
                    <select class="sr-only" id="sortResponses">
                        <option value="newest" selected>Newest First</option>
                        <option value="oldest">Oldest First</option>
                    </select>
                </div>
            </div>

            <div class="responses-grid">
                <?php foreach($votes as $vote): ?>
                <div class="response-card" data-result="<?= $vote['result'] ?>" data-vote-id="<?= $vote['vote_id'] ?>">
                    <div class="response-header">
                        <div class="user-info">
                            <div class="user-avatar-response">
                                <i class="material-icons">person</i>
                            </div>
                            <div class="user-details">
                                <h4><?= htmlspecialchars($vote['full_name']) ?></h4>
                                <span class="username-small">@<?= $vote['user'] ?></span>
                            </div>
                        </div>
                        <div class="response-badges">
                            <span class="vote-badge vote-<?= strtolower($vote['result']) ?>">
                                <i class="material-icons">
                                    <?= $vote['result'] === 'Agree' ? 'thumb_up' : 'thumb_down' ?>
                                </i>
                                <?= $vote['result'] ?>
                            </span>
                        </div>
                    </div>

                    <div class="response-body">
                        <p class="response-comment<?= !empty($vote['comment_empty']) ? ' is-muted' : '' ?>">
                            <?= htmlspecialchars($vote['comment']) ?>
                        </p>
                    </div>

                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Polls you've seen -->
        <div class="past-polls-section">
            <h2><i class="material-icons">history</i> History</h2>
            <div class="past-polls-grid">
                <?php foreach($past_polls as $poll): ?>
                <div class="past-poll-card"
                     data-poll-id="<?= $poll['poll_id'] ?>"
                     data-title="<?= htmlspecialchars($poll['title']) ?>"
                     data-total="<?= $poll['total_votes'] ?>"
                     data-agree="<?= $poll['agree_votes'] ?>"
                     data-disagree="<?= $poll['disagree_votes'] ?>">
                    <div class="past-poll-header">
                        <h3><?= htmlspecialchars($poll['title']) ?></h3>
                    </div>
                    <div class="past-poll-info">
                        <div class="info-row">
                            <i class="material-icons">how_to_vote</i>
                            <span><?= $poll['total_votes'] ?> votes</span>
                        </div>
                        <div class="info-row">
                            <i class="material-icons">event</i>
                            <span><?= date('M d', strtotime($poll['created'])) ?> - <?= date('M d, Y', strtotime($poll['closed'])) ?></span>
                        </div>
                    </div>
                <button class="view-results-btn" onclick="viewPastPoll(<?= $poll['poll_id'] ?>)">
                    <i class="material-icons">bar_chart</i>
                    View Results
                </button>
            </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Info Note -->
        <div class="info-note">
            <i class="material-icons">info</i>
            <p><strong>Note:</strong> Use the create form to draft a new poll before publishing.</p>
        </div>
    </main>

    <?php include '../global/footer.php'; ?>

    <div id="createPollModal" class="modal-overlay" onclick="closeCreatePollModal()">
        <div class="modal-content poll-create-modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2><i class="material-icons">add_circle</i> Create Poll</h2>
            </div>
            <div class="modal-body">
                <form id="createPollForm" method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="create_poll">
                    <?php if (!empty($create_poll_errors['form'])): ?>
                        <div class="modal-message"><?= htmlspecialchars($create_poll_errors['form']) ?></div>
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">title</i>
                                Poll Title
                            </label>
                            <input type="text" name="title" placeholder="Enter poll title" required value="<?= htmlspecialchars($create_poll_values['title']) ?>">
                            <span class="field-error" data-error-for="title"><?= htmlspecialchars($create_poll_errors['title'] ?? '') ?></span>
                        </div>

                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">description</i>
                                Poll Description
                            </label>
                            <textarea name="description" placeholder="Enter poll description" required><?= htmlspecialchars($create_poll_values['description']) ?></textarea>
                            <span class="field-error" data-error-for="description"><?= htmlspecialchars($create_poll_errors['description'] ?? '') ?></span>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="resetCreatePollForm()">
                            <i class="material-icons">refresh</i>
                            Reset
                        </button>
                        <button type="submit" class="btn-primary">
                            <i class="material-icons">save</i>
                            Create
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="pollResultsModal" class="modal-overlay" onclick="closeResultsModal()">
        <div class="modal-content poll-create-modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2><i class="material-icons">bar_chart</i> Poll Results</h2>
            </div>
            <div class="modal-body">
                <div class="results-summary">
                    <h3 id="resultsTitle"></h3>
                    <div class="results-grid">
                        <div class="result-card agree">
                            <span>Agree</span>
                            <strong id="resultsAgree">0</strong>
                        </div>
                        <div class="result-card disagree">
                            <span>Disagree</span>
                            <strong id="resultsDisagree">0</strong>
                        </div>
                        <div class="result-card total">
                            <span>Total Votes</span>
                            <strong id="resultsTotal">0</strong>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn-primary" onclick="closeResultsModal()">
                        <i class="material-icons">check_circle</i>
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="" id="pollActionForm">
        <input type="hidden" name="action" id="pollActionField" value="">
        <input type="hidden" name="poll_id" id="pollIdField" value="">
        <input type="hidden" name="vote_id" id="voteIdField" value="">
    </form>

    <script src="staff_custom_select.js"></script>
    <script src="staff.js"></script>
    <script>
        const scrollStorageKey = 'scroll:staff_PollManagement';
        const storeScrollPosition = window.staffUtils.setupScrollRestore(scrollStorageKey);
        const showPageMessage = window.staffUtils.showPageMessage;

        function filterResponses() {
            const filter = document.getElementById('responseFilter').value;
            const cards = document.querySelectorAll('.response-card');

            cards.forEach(card => {
                const result = card.dataset.result;
                card.style.display = (filter === 'all' || result === filter) ? 'block' : 'none';
            });
        }

        function viewPastPoll(pollId) {
            const card = document.querySelector(`.past-poll-card[data-poll-id="${pollId}"]`);
            if (!card) {
                return;
            }
            const title = card.dataset.title || 'Poll Results';
            const total = parseInt(card.dataset.total || '0', 10);
            const agree = parseInt(card.dataset.agree || '0', 10);
            const disagree = parseInt(card.dataset.disagree || '0', 10);

            document.getElementById('resultsTitle').textContent = title;
            document.getElementById('resultsTotal').textContent = total;
            document.getElementById('resultsAgree').textContent = agree;
            document.getElementById('resultsDisagree').textContent = disagree;
            openResultsModal();
        }

        function openCreatePollModal() {
            document.getElementById('createPollModal').style.display = 'flex';
        }

        function closeCreatePollModal() {
            document.getElementById('createPollModal').style.display = 'none';
        }

        function resetCreatePollForm() {
            const form = document.getElementById('createPollForm');
            form.reset();
            document.querySelectorAll('#createPollForm .field-error').forEach((error) => {
                error.textContent = '';
            });
            form.querySelectorAll('input, textarea').forEach((field) => {
                field.classList
                    .remove('is-error');
            });
        }

        document.getElementById('createPollForm').addEventListener('submit', function(e) {
            const form = e.currentTarget;
            let isValid = true;

            form.querySelectorAll('.field-error').forEach((error) => {
                error.textContent = '';
            });
            form.querySelectorAll('input, textarea').forEach((field) => {
                field.classList.remove('is-error');
            });

            ['title', 'description'].forEach((name) => {
                const field = form.querySelector(`[name="${name}"]`);
                if (!field.value.trim()) {
                    const error = form.querySelector(`[data-error-for="${name}"]`);
                    if (error) {
                        error.textContent = 'This field is required.';
                    }
                    field.classList.add('is-error');
                    isValid = false;
                }
            });

            if (!isValid) {
                e.preventDefault();
            }
        });

        function nextPoll(pollId) {
            if (!pollId) {
                return;
            }
            if (!confirm('Move to the next poll?')) {
                return;
            }
            const formData = new FormData();
            formData.append('action', 'next_poll');
            formData.append('poll_id', pollId);
            formData.append('ajax', '1');
            fetch('staff_PollManagement.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then((response) => response.json())
                .then((data) => {
                    if (!data.ok) {
                        showPageMessage('error', data.message || 'Unable to move the poll.');
                        return;
                    }
                    const currentOption = document.querySelector(`.poll-switcher-option[data-value="${pollId}"]`);
                    if (currentOption) {
                        currentOption.remove();
                    }
                    pollSwitcherOptions = document.querySelectorAll('.poll-switcher-option');
                    const nextOption = document.querySelector('.poll-switcher-option');
                    if (nextOption && pollSwitcherInput) {
                        pollSwitcherInput.value = nextOption.dataset.value || '';
                        pollSwitcherInput.closest('form').submit();
                        return;
                    }
                    window.location.reload();
                    if (data.poll && data.poll.id) {
                        const historyGrid = document.querySelector('.past-polls-grid');
                        if (historyGrid) {
                            const created = new Date(data.poll.created_at);
                            const closed = new Date(data.poll.closed_at);
                            const createdLabel = created.toLocaleDateString('en-US', { month: 'short', day: '2-digit' });
                            const closedLabel = closed.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                            const card = document.createElement('div');
                            card.className = 'past-poll-card';
                            card.dataset.pollId = data.poll.id;
                            card.dataset.title = data.poll.title;
                            card.dataset.total = data.poll.total_votes;
                            card.dataset.agree = data.poll.agree_votes;
                            card.dataset.disagree = data.poll.disagree_votes;
                            card.innerHTML = `
                                <div class="past-poll-header">
                                    <h3>${data.poll.title}</h3>
                                </div>
                                <div class="past-poll-info">
                                    <div class="info-row">
                                        <i class="material-icons">how_to_vote</i>
                                        <span>${data.poll.total_votes} votes</span>
                                    </div>
                                    <div class="info-row">
                                        <i class="material-icons">event</i>
                                        <span>${createdLabel} - ${closedLabel}</span>
                                    </div>
                                </div>
                                <button class="view-results-btn" type="button">
                                    <i class="material-icons">bar_chart</i>
                                    View Results
                                </button>
                            `;
                            const viewBtn = card.querySelector('.view-results-btn');
                            if (viewBtn) {
                                viewBtn.addEventListener('click', () => viewPastPoll(data.poll.id));
                            }
                            historyGrid.appendChild(card);
                        }
                    }
                    showPageMessage('success', data.message || 'Moved to polls you\'ve seen.');
                })
                .catch(() => {
                    showPageMessage('error', 'Unable to move the poll.');
                });
        }

        function deleteCurrentPoll(pollId) {
            if (!pollId) {
                return;
            }
            if (!confirm('Delete this poll and all of its responses?')) {
                return;
            }
            document.getElementById('pollActionField').value = 'delete_poll';
            document.getElementById('pollIdField').value = pollId;
            storeScrollPosition();
            document.getElementById('pollActionForm').submit();
        }

        function openResultsModal() {
            document.getElementById('pollResultsModal').style.display = 'flex';
        }

        function closeResultsModal() {
            document.getElementById('pollResultsModal').style.display = 'none';
        }

        const pollSwitcher = document.querySelector('.poll-switcher-custom');
        const pollSwitcherTrigger = document.getElementById('pollSwitcherTrigger');
        const pollSwitcherLabel = document.getElementById('pollSwitcherLabel');
        const pollSwitcherInput = document.getElementById('pollSwitcherInput');
        let pollSwitcherOptions = document.querySelectorAll('.poll-switcher-option');

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function renderResponseCard(response) {
            const commentClass = response.comment_empty ? ' is-muted' : '';
            const icon = response.result === 'Agree' ? 'thumb_up' : 'thumb_down';
            const fullName = escapeHtml(response.full_name);
            const username = escapeHtml(response.user);
            const comment = escapeHtml(response.comment);
            return `
                <div class="response-card" data-result="${response.result}" data-vote-id="${response.vote_id}">
                    <div class="response-header">
                        <div class="user-info">
                            <div class="user-avatar-response">
                                <i class="material-icons">person</i>
                            </div>
                            <div class="user-details">
                                <h4>${fullName}</h4>
                                <span class="username-small">@${username}</span>
                            </div>
                        </div>
                        <div class="response-badges">
                            <span class="vote-badge vote-${response.result.toLowerCase()}">
                                <i class="material-icons">${icon}</i>
                                ${response.result}
                            </span>
                        </div>
                    </div>
                    <div class="response-body">
                        <p class="response-comment${commentClass}">${comment}</p>
                    </div>
                </div>
            `;
        }

        function updatePollUI(payload) {
            if (!payload || !payload.poll) {
                return;
            }

            const poll = payload.poll;
            const votes = Array.isArray(payload.votes) ? payload.votes : [];

            if (pollSwitcherInput) {
                pollSwitcherInput.value = String(poll.id);
            }
            if (pollSwitcherLabel) {
                pollSwitcherLabel.textContent = poll.title || 'Select poll';
            }

            const titleEl = document.querySelector('.poll-title-section h2');
            const descEl = document.querySelector('.poll-title-section p');
            if (titleEl) titleEl.textContent = poll.title || 'No active poll';
            if (descEl) descEl.textContent = poll.description || 'Create a poll to start collecting votes.';

            const agreeCard = document.querySelector('.vote-option-card.vote-agree');
            const disagreeCard = document.querySelector('.vote-option-card.vote-disagree');
            if (agreeCard) {
                const count = agreeCard.querySelector('.vote-count');
                const percent = agreeCard.querySelector('.vote-percentage');
                const bar = agreeCard.querySelector('.progress-fill-vertical');
                if (count) count.textContent = `${poll.agree_count} votes`;
                if (percent) percent.textContent = `${poll.agree_percent}%`;
                if (bar) bar.style.height = `${poll.agree_percent}%`;
            }
            if (disagreeCard) {
                const count = disagreeCard.querySelector('.vote-count');
                const percent = disagreeCard.querySelector('.vote-percentage');
                const bar = disagreeCard.querySelector('.progress-fill-vertical');
                if (count) count.textContent = `${poll.disagree_count} votes`;
                if (percent) percent.textContent = `${poll.disagree_percent}%`;
                if (bar) bar.style.height = `${poll.disagree_percent}%`;
            }

            const overview = document.querySelector('.poll-overview-card');
            if (overview) {
                const strongs = overview.querySelectorAll('.overview-item strong');
                if (strongs[0]) strongs[0].textContent = String(poll.total_votes);
                if (strongs[1]) {
                    const created = new Date(poll.created_at);
                    strongs[1].textContent = created.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
                }
                if (strongs[2]) strongs[2].textContent = poll.trend_label;
                const trendLabel = overview.querySelectorAll('.overview-item span');
                if (trendLabel[2]) trendLabel[2].textContent = poll.trend_sub;
            }

            const responsesHeader = document.querySelector('.responses-header h2');
            if (responsesHeader) {
                responsesHeader.textContent = `User Responses (${votes.length})`;
            }
            const responsesGrid = document.querySelector('.responses-grid');
            if (responsesGrid) {
                responsesGrid.innerHTML = votes.map(renderResponseCard).join('');
            }

            const responseFilter = document.getElementById('responseFilter');
            if (responseFilter) {
                responseFilter.value = 'all';
            }
            const responseFilterLabel = document.getElementById('responseFilterLabel');
            if (responseFilterLabel) {
                responseFilterLabel.textContent = 'All Responses';
            }

            if (typeof filterResponses === 'function') {
                filterResponses();
            }

            const nextBtn = document.querySelector('.poll-action-btn.btn-close');
            const deleteBtn = document.querySelector('.poll-action-btn.btn-delete');
            if (nextBtn) {
                nextBtn.disabled = !poll.id;
                nextBtn.setAttribute('onclick', `nextPoll(${poll.id})`);
            }
            if (deleteBtn) {
                deleteBtn.disabled = !poll.id;
                deleteBtn.setAttribute('onclick', `deleteCurrentPoll(${poll.id})`);
            }
        }

        function closePollSwitcher() {
            if (!pollSwitcher) {
                return;
            }
            pollSwitcher.dataset.open = 'false';
            pollSwitcherTrigger.setAttribute('aria-expanded', 'false');
        }

        function togglePollSwitcher() {
            if (!pollSwitcher) {
                return;
            }
            const isOpen = pollSwitcher.dataset.open === 'true';
            pollSwitcher.dataset.open = isOpen ? 'false' : 'true';
            pollSwitcherTrigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
        }

        if (pollSwitcherTrigger) {
            pollSwitcherTrigger.addEventListener('click', () => {
                togglePollSwitcher();
            });
        }

        pollSwitcherOptions.forEach((option) => {
            option.addEventListener('click', () => {
                const value = option.dataset.value || '';
                if (!value || !pollSwitcherInput) {
                    return;
                }
                const formData = new FormData();
                formData.append('action', 'select_poll');
                formData.append('poll_id', value);
                formData.append('ajax', '1');
                fetch('staff_PollManagement.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (!data.ok) {
                            showPageMessage('error', data.message || 'Unable to load poll.');
                            return;
                        }
                        pollSwitcherOptions.forEach((item) => {
                            item.classList.remove('is-selected');
                            item.setAttribute('aria-selected', 'false');
                        });
                        option.classList.add('is-selected');
                        option.setAttribute('aria-selected', 'true');
                        closePollSwitcher();
                        updatePollUI(data);
                    })
                    .catch(() => {
                        showPageMessage('error', 'Unable to load poll.');
                    });
            });
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCreatePollModal();
                closeResultsModal();
                closePollSwitcher();
                document.querySelectorAll('.custom-select').forEach((item) => {
                    item.dataset.open = 'false';
                    const itemTrigger = item.querySelector('.custom-select-trigger');
                    if (itemTrigger) {
                        itemTrigger.setAttribute('aria-expanded', 'false');
                    }
                });
            }
        });

        document.addEventListener('click', function(e) {
            if (!pollSwitcher || !pollSwitcherTrigger) {
                return;
            }
            if (!pollSwitcher.contains(e.target)) {
                closePollSwitcher();
            }
            document.querySelectorAll('.custom-select').forEach((item) => {
                if (!item.contains(e.target)) {
                    item.dataset.open = 'false';
                    const itemTrigger = item.querySelector('.custom-select-trigger');
                    if (itemTrigger) {
                        itemTrigger.setAttribute('aria-expanded', 'false');
                    }
                }
            });
        });

        <?php if ($show_create_modal): ?>
        openCreatePollModal();
        <?php endif; ?>
    </script>
</body>
</html>
