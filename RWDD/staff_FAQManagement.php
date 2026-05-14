<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
include '../global/dbConnection.php';

$page_message = null;
$create_errors = [];
$edit_errors = [];
$create_values = [
    'question' => '',
    'answer' => '',
    'audience' => ''
];
$edit_values = [
    'id' => 0,
    'question' => '',
    'answer' => '',
    'audience' => ''
];
$show_create_modal = false;
$show_edit_modal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $is_ajax = ($_POST['ajax'] ?? '') === '1';

    if ($action === 'create_faq') {
        $create_values['question'] = trim($_POST['question'] ?? '');
        $create_values['answer'] = trim($_POST['answer'] ?? '');
        $create_values['audience'] = strtoupper(trim($_POST['audience'] ?? ''));

        if ($create_values['question'] === '') {
            $create_errors['question'] = 'This field is required.';
        }
        if ($create_values['answer'] === '') {
            $create_errors['answer'] = 'This field is required.';
        }
        if (!in_array($create_values['audience'], ['DRIVER', 'USER', 'BOTH'], true)) {
            $create_errors['audience'] = 'Please select an audience.';
        }

        if (!$connection) {
            $create_errors['form'] = 'Database connection failed.';
        }

        if (empty($create_errors)) {
            $stmt = mysqli_prepare(
                $connection,
                "INSERT INTO tbl_faq (faq_question, faq_answer, faq_applicable_to) VALUES (?, ?, ?)"
            );
            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "sss",
                    $create_values['question'],
                    $create_values['answer'],
                    $create_values['audience']
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['faq_flash'] = [
                    'type' => 'success',
                    'text' => 'FAQ created successfully.'
                ];
                header('Location: staff_FAQManagement.php');
                exit;
            }

            $create_errors['form'] = 'Unable to create FAQ.';
        }

        $show_create_modal = true;
    } elseif ($action === 'update_faq') {
        $edit_values['id'] = (int)($_POST['faq_id'] ?? 0);
        $edit_values['question'] = trim($_POST['question'] ?? '');
        $edit_values['answer'] = trim($_POST['answer'] ?? '');
        $edit_values['audience'] = strtoupper(trim($_POST['audience'] ?? ''));

        if ($edit_values['id'] <= 0) {
            $edit_errors['form'] = 'Invalid FAQ selection.';
        }
        if ($edit_values['question'] === '') {
            $edit_errors['question'] = 'This field is required.';
        }
        if ($edit_values['answer'] === '') {
            $edit_errors['answer'] = 'This field is required.';
        }
        if (!in_array($edit_values['audience'], ['DRIVER', 'USER', 'BOTH'], true)) {
            $edit_errors['audience'] = 'Please select an audience.';
        }

        if (!$connection) {
            $edit_errors['form'] = 'Database connection failed.';
        }

        if (empty($edit_errors)) {
            $stmt = mysqli_prepare(
                $connection,
                "UPDATE tbl_faq SET faq_question = ?, faq_answer = ?, faq_applicable_to = ? WHERE faq_id = ?"
            );
            if ($stmt) {
                mysqli_stmt_bind_param(
                    $stmt,
                    "sssi",
                    $edit_values['question'],
                    $edit_values['answer'],
                    $edit_values['audience'],
                    $edit_values['id']
                );
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['faq_flash'] = [
                    'type' => 'success',
                    'text' => 'FAQ updated successfully.'
                ];
                header('Location: staff_FAQManagement.php');
                exit;
            }

            $edit_errors['form'] = 'Unable to update FAQ.';
        }

        $show_edit_modal = true;
    } elseif ($action === 'delete_faq') {
        $faq_id = (int)($_POST['faq_id'] ?? 0);
        if ($faq_id <= 0) {
            $_SESSION['faq_flash'] = [
                'type' => 'error',
                'text' => 'Invalid FAQ selection.'
            ];
        } elseif (!$connection) {
            $_SESSION['faq_flash'] = [
                'type' => 'error',
                'text' => 'Database connection failed.'
            ];
        } else {
            $stmt = mysqli_prepare($connection, "DELETE FROM tbl_faq WHERE faq_id = ?");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "i", $faq_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $_SESSION['faq_flash'] = [
                    'type' => 'success',
                    'text' => 'FAQ deleted successfully.'
                ];
            } else {
                $_SESSION['faq_flash'] = [
                    'type' => 'error',
                    'text' => 'Unable to delete FAQ.'
                ];
            }
        }

        if ($is_ajax) {
            $flash = $_SESSION['faq_flash'] ?? null;
            unset($_SESSION['faq_flash']);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => is_array($flash) ? $flash['type'] === 'success' : false,
                'message' => is_array($flash) ? $flash['text'] : 'Unable to delete FAQ.'
            ]);
            exit;
        }

        header('Location: staff_FAQManagement.php');
        exit;
    }
}

if (!empty($_SESSION['faq_flash'])) {
    $page_message = $_SESSION['faq_flash'];
    unset($_SESSION['faq_flash']);
}

$faqs = [];
if ($connection) {
    $result = mysqli_query(
        $connection,
        "SELECT faq_id, faq_question, faq_answer, faq_applicable_to FROM tbl_faq ORDER BY faq_id DESC"
    );
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $category = match ($row['faq_applicable_to']) {
                'DRIVER' => 'Drivers',
                'USER' => 'Users',
                default => 'General'
            };
            $faqs[] = [
                'id' => (int)$row['faq_id'],
                'question' => $row['faq_question'],
                'answer' => $row['faq_answer'],
                'applicable_to' => $row['faq_applicable_to'],
                'category' => $category,
                'helpful_count' => 0
            ];
        }
    }
}

// Group FAQs by category
$categories = [];
foreach($faqs as $faq) {
    $categories[$faq['category']][] = $faq;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ Management - RideShare@APU</title>
    <link rel="stylesheet" href="staff_base.css">
    <link rel="stylesheet" href="staff_FAQManagement.css">
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

    <main class="faq-main">
        <div class="page-header-section">
            <div class="header-content">
                <h1><i class="material-icons">help_center</i> FAQ Management</h1>
                <p class="page-subtitle">View frequently asked questions and their answers</p>
                <?php if ($page_message): ?>
                    <div class="page-message <?= htmlspecialchars($page_message['type']) ?>">
                        <?= htmlspecialchars($page_message['text']) ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-actions faq-actions">
                <div class="faq-search">
                    <i class="material-icons">search</i>
                    <input 
                        type="text" 
                        id="faqSearch" 
                        placeholder="Search FAQs..." 
                        onkeyup="searchFAQs()"
                    >
                </div>
                <button class="btn-primary faq-create-btn" type="button" onclick="openCreateFaqModal()">
                    <i class="material-icons">add</i>
                    Create FAQ
                </button>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="faq-filters">
            <button class="filter-tab active" onclick="filterByAudience('all', event)">
                <i class="material-icons">list</i>
                All FAQs
            </button>
            <button class="filter-tab" onclick="filterByAudience('DRIVER', event)">
                <i class="material-icons">drive_eta</i>
                Drivers
            </button>
            <button class="filter-tab" onclick="filterByAudience('USER', event)">
                <i class="material-icons">person</i>
                Users
            </button>
            <button class="filter-tab" onclick="filterByAudience('BOTH', event)">
                <i class="material-icons">group</i>
                Both
            </button>
        </div>

        <!-- FAQ Statistics -->
        <div class="faq-stats-grid">
            <div class="faq-stat-card">
                <div class="stat-icon">
                    <i class="material-icons">question_answer</i>
                </div>
                <div class="stat-info">
                    <h3><?= count($faqs) ?></h3>
                    <p>Total FAQs</p>
                </div>
            </div>
            <div class="faq-stat-card">
                <div class="stat-icon">
                    <i class="material-icons">category</i>
                </div>
                <div class="stat-info">
                    <h3><?= count($categories) ?></h3>
                    <p>Categories</p>
                </div>
            </div>
        </div>

        <!-- FAQs by Category -->
        <div class="faq-categories-container">
            <?php foreach($categories as $category => $category_faqs): ?>
            <div class="faq-category-section">
                <h2 class="category-title">
                    <i class="material-icons">folder</i>
                    <?= $category ?>
                    <span class="category-count"><?= count($category_faqs) ?> FAQs</span>
                </h2>

                <div class="faq-accordion">
                    <?php foreach($category_faqs as $faq): ?>
                    <div class="faq-item"
                         data-id="<?= $faq['id'] ?>"
                         data-audience="<?= $faq['applicable_to'] ?>"
                         data-question="<?= htmlspecialchars($faq['question']) ?>"
                         data-answer="<?= htmlspecialchars($faq['answer']) ?>">
                        <div class="faq-question" onclick="toggleFAQ(this)">
                            <div class="question-content">
                                <i class="material-icons toggle-icon">add</i>
                                <h3><?= htmlspecialchars($faq['question']) ?></h3>
                            </div>
                            <div class="faq-badges">
                                <span class="audience-badge audience-<?= strtolower($faq['applicable_to']) ?>">
                                    <?= $faq['applicable_to'] ?>
                                </span>
                                <div class="faq-item-actions">
                                    <button type="button" class="faq-action-btn edit" onclick="openEditFaqModal(<?= $faq['id'] ?>, event)" title="Edit FAQ">
                                        <i class="material-icons">edit</i>
                                    </button>
                                    <button type="button" class="faq-action-btn delete" onclick="confirmDeleteFaq(<?= $faq['id'] ?>, event)" title="Delete FAQ">
                                        <i class="material-icons">delete</i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="faq-answer">
                            <div class="answer-content">
                                <p><?= htmlspecialchars($faq['answer']) ?></p>
                                <!-- Removed last updated display -->
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Info Note -->
        <div class="info-note">
            <i class="material-icons">info</i>
            <p><strong>Note:</strong> Staff members can create and manage FAQs for drivers and users.</p>
        </div>
    </main>

    <?php include '../global/footer.php'; ?>

    <div id="createFaqModal" class="modal-overlay" onclick="closeCreateFaqModal()">
        <div class="modal-content faq-create-modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2><i class="material-icons">add_circle</i> Create FAQ</h2>
            </div>
            <div class="modal-body">
                <form id="createFaqForm" method="POST" action="" novalidate>
                    <input type="hidden" name="action" value="create_faq">
                    <?php if (!empty($create_errors['form'])): ?>
                        <div class="modal-message"><?= htmlspecialchars($create_errors['form']) ?></div>
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">help</i>
                                Question
                            </label>
                            <input type="text" name="question" placeholder="Enter FAQ question" required value="<?= htmlspecialchars($create_values['question']) ?>">
                            <span class="field-error" data-error-for="question"><?= htmlspecialchars($create_errors['question'] ?? '') ?></span>
                        </div>

                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">description</i>
                                Answer
                            </label>
                            <textarea name="answer" placeholder="Enter FAQ answer" required><?= htmlspecialchars($create_values['answer']) ?></textarea>
                            <span class="field-error" data-error-for="answer"><?= htmlspecialchars($create_errors['answer'] ?? '') ?></span>
                        </div>

                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">groups</i>
                                Applicable To
                            </label>
                            <div class="faq-audience-options">
                                <label class="faq-audience-option">
                                    <input type="radio" name="audience" value="DRIVER" required <?= $create_values['audience'] === 'DRIVER' ? 'checked' : '' ?>>
                                    Drivers
                                </label>
                                <label class="faq-audience-option">
                                    <input type="radio" name="audience" value="USER" <?= $create_values['audience'] === 'USER' ? 'checked' : '' ?>>
                                    Users
                                </label>
                                <label class="faq-audience-option">
                                    <input type="radio" name="audience" value="BOTH" <?= $create_values['audience'] === 'BOTH' ? 'checked' : '' ?>>
                                    Both
                                </label>
                            </div>
                            <span class="field-error" data-error-for="audience"><?= htmlspecialchars($create_errors['audience'] ?? '') ?></span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="resetCreateFaqForm()">
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

    <div id="editFaqModal" class="modal-overlay" onclick="closeEditFaqModal()">
        <div class="modal-content faq-create-modal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2><i class="material-icons">edit</i> Edit FAQ</h2>
                <button class="close-modal-btn" onclick="closeEditFaqModal()">
                    <i class="material-icons">close</i>
                </button>
            </div>
            <div class="modal-body">
                <form id="editFaqForm" method="POST" action="">
                    <input type="hidden" name="action" value="update_faq">
                    <input type="hidden" name="faq_id" id="editFaqId" value="<?= (int)$edit_values['id'] ?>">
                    <?php if (!empty($edit_errors['form'])): ?>
                        <div class="modal-message"><?= htmlspecialchars($edit_errors['form']) ?></div>
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">help</i>
                                Question
                            </label>
                            <input type="text" name="question" id="editQuestion" placeholder="Enter FAQ question" required value="<?= htmlspecialchars($edit_values['question']) ?>">
                            <span class="field-error" data-error-for="edit_question"><?= htmlspecialchars($edit_errors['question'] ?? '') ?></span>
                        </div>

                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">description</i>
                                Answer
                            </label>
                            <textarea name="answer" id="editAnswer" placeholder="Enter FAQ answer" required><?= htmlspecialchars($edit_values['answer']) ?></textarea>
                            <span class="field-error" data-error-for="edit_answer"><?= htmlspecialchars($edit_errors['answer'] ?? '') ?></span>
                        </div>

                        <div class="form-group full-width">
                            <label>
                                <i class="material-icons">groups</i>
                                Applicable To
                            </label>
                            <div class="faq-audience-options">
                                <label class="faq-audience-option">
                                    <input type="radio" name="audience" value="DRIVER" required <?= $edit_values['audience'] === 'DRIVER' ? 'checked' : '' ?>>
                                    Drivers
                                </label>
                                <label class="faq-audience-option">
                                    <input type="radio" name="audience" value="USER" <?= $edit_values['audience'] === 'USER' ? 'checked' : '' ?>>
                                    Users
                                </label>
                                <label class="faq-audience-option">
                                    <input type="radio" name="audience" value="BOTH" <?= $edit_values['audience'] === 'BOTH' ? 'checked' : '' ?>>
                                    Both
                                </label>
                            </div>
                            <span class="field-error" data-error-for="edit_audience"><?= htmlspecialchars($edit_errors['audience'] ?? '') ?></span>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="closeEditFaqModal()">
                            <i class="material-icons">close</i>
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary">
                            <i class="material-icons">save</i>
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form id="faqDeleteForm" method="POST" action="">
        <input type="hidden" name="action" value="delete_faq">
        <input type="hidden" name="faq_id" id="deleteFaqId" value="">
    </form>

    <script src="staff.js"></script>
    <script>
        const scrollStorageKey = 'scroll:staff_FAQManagement';
        const storeScrollPosition = window.staffUtils.setupScrollRestore(scrollStorageKey);
        const showPageMessage = window.staffUtils.showPageMessage;

        function toggleFAQ(element) {
            const faqItem = element.parentElement;
            const answer = faqItem.querySelector('.faq-answer');
            const icon = element.querySelector('.toggle-icon');
            
            // Close other open FAQs
            document.querySelectorAll('.faq-item.active').forEach(item => {
                if(item !== faqItem) {
                    item.classList.remove('active');
                    item.querySelector('.toggle-icon').textContent = 'add';
                }
            });

            // Toggle current FAQ
            faqItem.classList.toggle('active');
            icon.textContent = faqItem.classList.contains('active') ? 'remove' : 'add';
        }

        let activeAudienceFilter = 'all';

        function filterByAudience(audience, event) {
            const tabs = document.querySelectorAll('.filter-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            if (event && event.target) {
                event.target.closest('.filter-tab').classList.add('active');
            }
            activeAudienceFilter = audience;
            applyFaqFilters();
        }

        function searchFAQs() {
            applyFaqFilters();
        }

        function applyFaqFilters() {
            const searchInput = document.getElementById('faqSearch');
            const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
            const items = document.querySelectorAll('.faq-item');

            items.forEach(item => {
                const question = item.querySelector('.faq-question h3').textContent.toLowerCase();
                const answer = item.querySelector('.faq-answer p').textContent.toLowerCase();
                const itemAudience = item.dataset.audience;
                const matchesAudience = activeAudienceFilter === 'all' || itemAudience === activeAudienceFilter || itemAudience === 'BOTH';
                const matchesSearch = !searchTerm || question.includes(searchTerm) || answer.includes(searchTerm);

                if (matchesAudience && matchesSearch) {
                    item.style.display = 'block';
                    if (searchTerm.length > 0) {
                        item.classList.add('active');
                        item.querySelector('.toggle-icon').textContent = 'remove';
                    }
                } else {
                    item.style.display = 'none';
                    item.classList.remove('active');
                    item.querySelector('.toggle-icon').textContent = 'add';
                }
            });

            document.querySelectorAll('.faq-category-section').forEach(section => {
                const visibleItems = section.querySelectorAll('.faq-item[style="display: block;"], .faq-item:not([style*=\"display: none\"])');
                section.style.display = visibleItems.length > 0 ? 'block' : 'none';
            });
        }

        function openCreateFaqModal() {
            document.getElementById('createFaqModal').style.display = 'flex';
        }

        function closeCreateFaqModal() {
            document.getElementById('createFaqModal').style.display = 'none';
        }

        function resetCreateFaqForm() {
            const form = document.getElementById('createFaqForm');
            form.reset();
            form.querySelectorAll('.field-error').forEach((error) => {
                error.textContent = '';
            });
            form.querySelectorAll('input, textarea').forEach((field) => {
                field.classList.remove('is-error');
            });
        }

        function openEditFaqModal(faqId, event) {
            if (event) {
                event.stopPropagation();
            }
            const faqItem = document.querySelector(`.faq-item[data-id="${faqId}"]`);
            if (!faqItem) {
                return;
            }
            document.getElementById('editFaqId').value = faqId;
            document.getElementById('editQuestion').value = faqItem.dataset.question || '';
            document.getElementById('editAnswer').value = faqItem.dataset.answer || '';

            const audience = faqItem.dataset.audience || '';
            document.querySelectorAll('#editFaqForm input[name="audience"]').forEach((input) => {
                input.checked = input.value === audience;
            });

            document.querySelectorAll('#editFaqForm .field-error').forEach((error) => {
                error.textContent = '';
            });
            document.getElementById('editFaqModal').style.display = 'flex';
        }

        function closeEditFaqModal() {
            document.getElementById('editFaqModal').style.display = 'none';
        }

        function confirmDeleteFaq(faqId, event) {
            if (event) {
                event.stopPropagation();
            }
            if (!confirm('Delete this FAQ? This cannot be undone.')) {
                return;
            }
            const formData = new FormData();
            formData.append('action', 'delete_faq');
            formData.append('faq_id', faqId);
            formData.append('ajax', '1');
            fetch('staff_FAQManagement.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then((response) => response.json())
                .then((data) => {
                    if (data.ok) {
                        const item = document.querySelector(`.faq-item[data-id="${faqId}"]`);
                        if (item) {
                            item.remove();
                        }
                        showPageMessage('success', data.message || 'FAQ deleted successfully.');
                    } else {
                        showPageMessage('error', data.message || 'Unable to delete FAQ.');
                    }
                })
                .catch(() => {
                    showPageMessage('error', 'Unable to delete FAQ.');
                });
        }

        document.getElementById('createFaqForm').addEventListener('submit', function(e) {
            const form = e.currentTarget;
            let isValid = true;

            form.querySelectorAll('.field-error').forEach((error) => {
                error.textContent = '';
            });
            form.querySelectorAll('input, textarea').forEach((field) => {
                field.classList.remove('is-error');
            });

            ['question', 'answer'].forEach((name) => {
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

            const audience = form.querySelector('input[name="audience"]:checked');
            if (!audience) {
                const error = form.querySelector('[data-error-for="audience"]');
                if (error) {
                    error.textContent = 'Please select an audience.';
                }
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
            }
        });

        document.getElementById('editFaqForm').addEventListener('submit', function(e) {
            const form = e.currentTarget;
            let isValid = true;

            form.querySelectorAll('.field-error').forEach((error) => {
                error.textContent = '';
            });
            form.querySelectorAll('input, textarea').forEach((field) => {
                field.classList.remove('is-error');
            });

            ['question', 'answer'].forEach((name) => {
                const field = form.querySelector(`[name="${name}"]`);
                if (!field.value.trim()) {
                    field.classList.add('is-error');
                    isValid = false;
                }
            });

            const audience = form.querySelector('input[name="audience"]:checked');
            if (!audience) {
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeCreateFaqModal();
                closeEditFaqModal();
            }
        });

        <?php if ($show_create_modal): ?>
        openCreateFaqModal();
        <?php endif; ?>
        <?php if ($show_edit_modal): ?>
        document.getElementById('editFaqModal').style.display = 'flex';
        <?php endif; ?>
    </script>
</body>
</html>
