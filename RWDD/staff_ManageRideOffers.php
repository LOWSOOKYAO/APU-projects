<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
include '../global/dbConnection.php';

$page_message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['offer_action'], $_POST['offer_id'])) {
    $action = $_POST['offer_action'];
    $offer_id = (int)$_POST['offer_id'];
    $is_ajax = ($_POST['ajax'] ?? '') === '1';

    if (!$connection) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => 'Database connection failed.'
        ];
    } elseif (!in_array($action, ['suspend', 'activate'], true) || $offer_id <= 0) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'text' => 'Invalid offer action.'
        ];
    } else {
        $status = $action === 'suspend' ? 'INACTIVE' : 'INCOMPLETE';
        $stmt = mysqli_prepare(
            $connection,
            "UPDATE tbl_ride_offer SET offer_status = ? WHERE offer_id = ?"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "si", $status, $offer_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $_SESSION['flash_message'] = [
                'type' => 'success',
                'text' => $action === 'suspend' ? 'Offer suspended successfully.' : 'Offer activated successfully.'
            ];
        } else {
            $_SESSION['flash_message'] = [
                'type' => 'error',
                'text' => 'Unable to update offer status.'
            ];
        }
    }

    if ($is_ajax) {
        $flash = $_SESSION['flash_message'] ?? null;
        unset($_SESSION['flash_message']);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
            'message' => is_array($flash) ? $flash['text'] : 'Unable to update offer status.',
            'status' => $action === 'suspend' ? 'Inactive' : 'Incomplete'
        ]);
        exit;
    }

    header('Location: staff_ManageRideOffers.php');
    exit;
}

if (!empty($_SESSION['flash_message'])) {
    $page_message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

$page_size = 15;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$is_load_more = ($_GET['ajax'] ?? '') === '1';

$offer_stats = [
    'incomplete' => 0,
    'complete' => 0,
    'inactive' => 0,
    'total_bookings' => 0,
    'total_offers' => 0
];

if ($connection) {
    $stat_row = mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT
            SUM(CASE WHEN UPPER(offer_status) = 'INACTIVE' THEN 1 ELSE 0 END) AS inactive_count,
            SUM(CASE WHEN UPPER(offer_status) = 'COMPLETE' THEN 1 ELSE 0 END) AS complete_count,
            SUM(CASE WHEN UPPER(offer_status) NOT IN ('INACTIVE', 'COMPLETE') THEN 1 ELSE 0 END) AS incomplete_count
         FROM tbl_ride_offer"
    ));
    if ($stat_row) {
        $offer_stats['inactive'] = (int)$stat_row['inactive_count'];
        $offer_stats['complete'] = (int)$stat_row['complete_count'];
        $offer_stats['incomplete'] = (int)$stat_row['incomplete_count'];
    }

    $booking_row = mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT COUNT(*) AS total FROM tbl_booking WHERE booking_status = 'ACCEPTED'"
    ));
    if ($booking_row) {
        $offer_stats['total_bookings'] = (int)$booking_row['total'];
    }

    $total_row = mysqli_fetch_assoc(mysqli_query(
        $connection,
        "SELECT COUNT(*) AS total FROM tbl_ride_offer"
    ));
    if ($total_row) {
        $offer_stats['total_offers'] = (int)$total_row['total'];
    }
}

$offers = [];
if ($connection) {
    $sql = "SELECT ro.offer_id, ro.offer_driver_username, ro.offer_type_of_ride, ro.offer_date, ro.offer_time,
                   ro.offer_price_per_minute, ro.offer_seat_available, ro.offer_status,
                   l.location_name,
                   ui.user_name AS driver_name,
                   SUM(CASE WHEN b.booking_status = 'ACCEPTED' THEN 1 ELSE 0 END) AS accepted_bookings
            FROM tbl_ride_offer ro
            LEFT JOIN tbl_location l ON ro.offer_location_id = l.location_id
            LEFT JOIN tbl_booking b ON ro.offer_id = b.booking_offer_id
            LEFT JOIN tbl_user_info ui ON ro.offer_driver_username = ui.user_username
            GROUP BY ro.offer_id
            ORDER BY ro.offer_date DESC, ro.offer_time DESC
            LIMIT $page_size OFFSET $offset";
    $result = mysqli_query($connection, $sql);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $location = $row['location_name'] ?? 'Unknown';
            $route = $location;
            if (stripos($row['offer_type_of_ride'], 'TO APU') !== false) {
                $route = $location . ' → APU';
            } elseif (stripos($row['offer_type_of_ride'], 'FROM APU') !== false) {
                $route = 'APU → ' . $location;
            }

            $seats_available = (int)$row['offer_seat_available'];
            $bookings = (int)$row['accepted_bookings'];
            $total_seats = $seats_available + $bookings;
            if ($total_seats == 0) {
                $total_seats = $seats_available;
            }

            $raw_status = strtoupper($row['offer_status'] ?? 'INCOMPLETE');
            if ($raw_status === 'INACTIVE') {
                $status = 'Inactive';
            } elseif ($raw_status === 'COMPLETE') {
                $status = 'Complete';
            } else {
                $status = 'Incomplete';
            }

            $offers[] = [
                "offer_id" => (int)$row['offer_id'],
                "driver" => $row['driver_name'] ?? $row['offer_driver_username'],
                "driver_username" => $row['offer_driver_username'],
                "route" => $route,
                "seats_available" => $seats_available,
                "total_seats" => $total_seats,
                "progress" => $total_seats > 0 ? ($bookings / $total_seats) * 100 : 0,
                "price" => 'RM ' . number_format((float)$row['offer_price_per_minute'], 2) . '/min',
                "date" => $row['offer_date'],
                "time" => date('h:i A', strtotime($row['offer_time'])),
                "time_raw" => $row['offer_time'],
                "status" => $status,
                "bookings" => $bookings,
                "created_at" => $row['offer_date']
            ];
        }
    }
}

$has_more = ($offset + $page_size) < $offer_stats['total_offers'];

function render_offer_card(array $offer): string {
    ob_start();
    ?>
    <div class="offer-card-modern"
         data-status="<?= htmlspecialchars($offer['status']) ?>"
         data-offer-id="<?= $offer['offer_id'] ?>"
         data-bookings="<?= (int)$offer['bookings'] ?>"
         data-seats="<?= (int)$offer['seats_available'] ?>"
         data-total-seats="<?= (int)$offer['total_seats'] ?>"
         data-date="<?= htmlspecialchars($offer['date'] . ' ' . $offer['time_raw']) ?>">
        <div class="offer-card-header">
            <div class="driver-profile">
                <div class="driver-avatar-small">
                    <i class="material-icons">person</i>
                </div>
                <div class="driver-name-section">
                    <h3><?= htmlspecialchars($offer['driver']) ?></h3>
                    <span class="username-tag">@<?= htmlspecialchars($offer['driver_username']) ?></span>
                </div>
            </div>
            <span class="offer-status-badge status-<?= strtolower($offer['status']) ?>">
                <?= htmlspecialchars($offer['status']) ?>
            </span>
        </div>

        <div class="offer-card-body">
            <div class="route-display">
                <div class="route-badge">
                    <i class="material-icons">route</i>
                    <span><?= htmlspecialchars($offer['route']) ?></span>
                </div>
            </div>

            <div class="offer-details-grid">
                <div class="detail-item">
                    <i class="material-icons">event</i>
                    <div>
                        <span class="detail-label">Date</span>
                        <strong><?= date('M d, Y', strtotime($offer['date'])) ?></strong>
                    </div>
                </div>
                <div class="detail-item">
                    <i class="material-icons">schedule</i>
                    <div>
                        <span class="detail-label">Time</span>
                        <strong><?= htmlspecialchars($offer['time']) ?></strong>
                    </div>
                </div>
                <div class="detail-item">
                    <i class="material-icons">event_seat</i>
                    <div>
                        <span class="detail-label">Seats</span>
                        <strong><?= $offer['seats_available'] ?>/<?= $offer['total_seats'] ?> available</strong>
                    </div>
                </div>
                <div class="detail-item">
                    <i class="material-icons">payments</i>
                    <div>
                        <span class="detail-label">Price</span>
                        <strong><?= htmlspecialchars($offer['price']) ?></strong>
                    </div>
                </div>
            </div>

            <div class="offer-bookings-section">
                <div class="bookings-indicator">
                    <i class="material-icons">people</i>
                    <span><?= $offer['bookings'] ?> <?= $offer['bookings'] == 1 ? 'booking' : 'bookings' ?></span>
                </div>
                <div class="seats-progress">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $offer['progress'] ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="offer-card-footer">
            <span class="created-date">
                <i class="material-icons">access_time</i>
                Posted: <?= date('M d, Y', strtotime($offer['created_at'])) ?>
            </span>
            <div class="offer-actions-btns">
                <?php if($offer['status'] === 'Inactive'): ?>
                <button class="action-icon-btn btn-activate" title="Activate" onclick="activateOffer(<?= $offer['offer_id'] ?>)">
                    <i class="material-icons">check_circle</i>
                </button>
                <?php elseif($offer['status'] !== 'Complete'): ?>
                <button class="action-icon-btn btn-suspend" title="Suspend" onclick="suspendOffer(<?= $offer['offer_id'] ?>)">
                    <i class="material-icons">block</i>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

if ($is_load_more) {
    $cards_html = '';
    foreach ($offers as $offer) {
        $cards_html .= render_offer_card($offer);
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
    <title>Manage Ride Offers - RideShare@APU</title>
    <link rel="stylesheet" href="staff_base.css">
    <link rel="stylesheet" href="staff_ManageRideOffers.css">
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

    <main class="manage-offers-main">
        <div class="page-header-section">
            <div class="header-content">
                <h1><i class="material-icons">local_offer</i> Manage Ride Offers</h1>
                <p class="page-subtitle">Monitor and manage all ride offers posted by drivers</p>
                <?php if ($page_message): ?>
                    <div class="page-message <?= htmlspecialchars($page_message['type']) ?>">
                        <?= htmlspecialchars($page_message['text']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-filters">
                <div class="custom-select" data-target="statusFilter" data-open="false">
                    <button type="button" class="custom-select-trigger" id="statusFilterTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span class="custom-select-label" id="statusFilterLabel">All Status</span>
                        <i class="material-icons" aria-hidden="true">expand_more</i>
                    </button>
                    <div class="custom-select-dropdown" role="listbox" aria-labelledby="statusFilterTrigger">
                        <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="all">All Status</button>
                        <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Incomplete">Incomplete</button>
                        <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Complete">Complete</button>
                        <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="Inactive">Inactive</button>
                    </div>
                </div>
                <select class="sr-only" id="statusFilter" onchange="filterOffers()">
                    <option value="all" selected>All Status</option>
                    <option value="Incomplete">Incomplete</option>
                    <option value="Complete">Complete</option>
                    <option value="Inactive">Inactive</option>
                </select>

                <div class="custom-select" data-target="sortBy" data-open="false">
                    <button type="button" class="custom-select-trigger" id="sortByTrigger" aria-haspopup="listbox" aria-expanded="false">
                        <span class="custom-select-label" id="sortByLabel">Sort by Date</span>
                        <i class="material-icons" aria-hidden="true">expand_more</i>
                    </button>
                    <div class="custom-select-dropdown" role="listbox" aria-labelledby="sortByTrigger">
                        <button type="button" class="custom-select-option is-selected" role="option" aria-selected="true" data-value="date">Sort by Date</button>
                        <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="bookings">Sort by Bookings</button>
                        <button type="button" class="custom-select-option" role="option" aria-selected="false" data-value="seats">Sort by Available Seats</button>
                    </div>
                </div>
                <select class="sr-only" id="sortBy">
                    <option value="date" selected>Sort by Date</option>
                    <option value="bookings">Sort by Bookings</option>
                    <option value="seats">Sort by Available Seats</option>
                </select>
            </div>
        </div>

        <form method="POST" action="" id="offerActionForm">
            <input type="hidden" name="offer_action" id="offerActionField">
            <input type="hidden" name="offer_id" id="offerIdField">
        </form>

        <!-- Statistics -->
        <div class="offers-stats-grid">
            <div class="offer-stat-card">
                <div class="stat-icon-circle stat-incomplete">
                    <i class="material-icons">check_circle</i>
                </div>
                <div class="stat-content">
                    <h3><?= $offer_stats['incomplete'] ?></h3>
                    <p>Incomplete Offers</p>
                </div>
            </div>
            <div class="offer-stat-card">
                <div class="stat-icon-circle stat-complete">
                    <i class="material-icons">event_seat</i>
                </div>
                <div class="stat-content">
                    <h3><?= $offer_stats['complete'] ?></h3>
                    <p>Complete Offers</p>
                </div>
            </div>
            <div class="offer-stat-card">
                <div class="stat-icon-circle stat-inactive">
                    <i class="material-icons">block</i>
                </div>
                <div class="stat-content">
                    <h3><?= $offer_stats['inactive'] ?></h3>
                    <p>Inactive</p>
                </div>
            </div>
            <div class="offer-stat-card">
                <div class="stat-icon-circle stat-total">
                    <i class="material-icons">format_list_bulleted</i>
                </div>
                <div class="stat-content">
                    <h3><?= $offer_stats['total_bookings'] ?></h3>
                    <p>Total Bookings</p>
                </div>
            </div>
        </div>

        <!-- Offers List -->
        <div class="offers-list">
            <?php foreach($offers as $offer): ?>
                <?= render_offer_card($offer) ?>
            <?php endforeach; ?>
        </div>
        <?php if ($has_more): ?>
        <div class="load-more-wrapper">
            <button type="button" class="load-more-btn" id="loadMoreOffers">Show More</button>
        </div>
        <?php endif; ?>
    </main>

    <?php include '../global/footer.php'; ?>

    <script src="staff_custom_select.js"></script>
    <script src="staff.js"></script>
    <script>
        const scrollStorageKey = 'scroll:staff_ManageRideOffers';
        const storeScrollPosition = window.staffUtils.setupScrollRestore(scrollStorageKey);
        const showPageMessage = window.staffUtils.showPageMessage;

        function filterOffers() {
            const status = document.getElementById('statusFilter').value;
            const cards = document.querySelectorAll('.offer-card-modern');

            cards.forEach(card => {
                const cardStatus = card.dataset.status;
                card.style.display = (status === 'all' || cardStatus === status) ? 'block' : 'none';
            });
        }

        function sortOffers() {
            const sortBy = document.getElementById('sortBy').value;
            const list = document.querySelector('.offers-list');
            if (!list) {
                return;
            }

            const cards = Array.from(list.querySelectorAll('.offer-card-modern'));
            const getNumber = (value) => Number.parseInt(value || '0', 10);

            cards.sort((a, b) => {
                const bookingsDiff = getNumber(b.dataset.bookings) - getNumber(a.dataset.bookings);
                const seatsDiff = getNumber(b.dataset.seats) - getNumber(a.dataset.seats);
                const totalSeatsDiff = getNumber(b.dataset.totalSeats) - getNumber(a.dataset.totalSeats);
                const dateA = new Date(a.dataset.date || 0).getTime();
                const dateB = new Date(b.dataset.date || 0).getTime();
                const dateDiff = dateB - dateA;

                if (sortBy === 'bookings') {
                    if (bookingsDiff !== 0) return bookingsDiff;
                    if (seatsDiff !== 0) return seatsDiff * -1;
                    return dateDiff;
                }
                if (sortBy === 'seats') {
                    if (totalSeatsDiff !== 0) return totalSeatsDiff * -1;
                    if (seatsDiff !== 0) return seatsDiff * -1;
                    if (bookingsDiff !== 0) return bookingsDiff * -1;
                    return dateDiff;
                }
                if (dateDiff !== 0) return dateDiff;
                if (bookingsDiff !== 0) return bookingsDiff;
                return seatsDiff;
            });

            cards.forEach(card => list.appendChild(card));
            filterOffers();
        }

        function updateOfferCardStatus(offerId, status) {
            const card = document.querySelector(`.offer-card-modern[data-offer-id="${offerId}"]`);
            if (!card) {
                return;
            }
            card.dataset.status = status;
            const badge = card.querySelector('.offer-status-badge');
            if (badge) {
                badge.className = `offer-status-badge status-${status.toLowerCase()}`;
                badge.textContent = status;
            }
            const actions = card.querySelector('.offer-actions-btns');
            if (actions) {
                if (status === 'Inactive') {
                    actions.innerHTML = '<button class="action-icon-btn btn-activate" title="Activate" onclick="activateOffer(' + offerId + ')"><i class="material-icons">check_circle</i></button>';
                } else if (status !== 'Complete') {
                    actions.innerHTML = '<button class="action-icon-btn btn-suspend" title="Suspend" onclick="suspendOffer(' + offerId + ')"><i class="material-icons">block</i></button>';
                } else {
                    actions.innerHTML = '';
                }
            }
        }

        function suspendOffer(offerId) {
            if(confirm('Suspend this offer?')) {
                const formData = new FormData();
                formData.append('offer_action', 'suspend');
                formData.append('offer_id', offerId);
                formData.append('ajax', '1');
                fetch('staff_ManageRideOffers.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.ok) {
                            updateOfferCardStatus(offerId, data.status || 'Inactive');
                            showPageMessage('success', data.message || 'Offer suspended successfully.');
                        } else {
                            showPageMessage('error', data.message || 'Unable to update offer status.');
                        }
                    })
                    .catch(() => {
                        showPageMessage('error', 'Unable to update offer status.');
                    });
            }
        }

        function activateOffer(offerId) {
            if(confirm('Activate this offer?')) {
                const formData = new FormData();
                formData.append('offer_action', 'activate');
                formData.append('offer_id', offerId);
                formData.append('ajax', '1');
                fetch('staff_ManageRideOffers.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.ok) {
                            updateOfferCardStatus(offerId, data.status || 'Incomplete');
                            showPageMessage('success', data.message || 'Offer activated successfully.');
                        } else {
                            showPageMessage('error', data.message || 'Unable to update offer status.');
                        }
                    })
                    .catch(() => {
                        showPageMessage('error', 'Unable to update offer status.');
                    });
            }
        }

        const loadMoreOffersBtn = document.getElementById('loadMoreOffers');
        let offersOffset = <?= $offset + $page_size ?>;
        const offersPageSize = <?= $page_size ?>;

        if (loadMoreOffersBtn) {
            loadMoreOffersBtn.addEventListener('click', () => {
                loadMoreOffersBtn.disabled = true;
                const originalText = loadMoreOffersBtn.textContent;
                loadMoreOffersBtn.textContent = 'Loading...';
                fetch(`staff_ManageRideOffers.php?ajax=1&offset=${offersOffset}`, {
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.html) {
                            const list = document.querySelector('.offers-list');
                            if (list) {
                                list.insertAdjacentHTML('beforeend', data.html);
                                sortOffers();
                            }
                        }
                        offersOffset = data.next_offset ?? (offersOffset + offersPageSize);
                        if (!data.has_more) {
                            loadMoreOffersBtn.remove();
                        } else {
                            loadMoreOffersBtn.disabled = false;
                            loadMoreOffersBtn.textContent = originalText;
                        }
                    })
                    .catch(() => {
                        loadMoreOffersBtn.disabled = false;
                        loadMoreOffersBtn.textContent = originalText;
                        showPageMessage('error', 'Unable to load more offers.');
                    });
            });
        }

        document.getElementById('statusFilter').addEventListener('change', filterOffers);
        document.getElementById('sortBy').addEventListener('change', sortOffers);
        sortOffers();
    </script>
</body>
</html>
