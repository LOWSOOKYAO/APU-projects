<?php
include '../global/session.php';

if (empty($_SESSION['username'])) {
    header('Location: ../index.php');
    exit;
}
include '../global/dbConnection.php';

$profile = [
    'full_name' => $_SESSION['fullname'] ?? '',
    'username' => $_SESSION['username'] ?? '',
    'email' => $_SESSION['email'] ?? '',
    'gender' => $_SESSION['gender'] ?? '',
    'dob' => $_SESSION['dob'] ?? '',
    'phone' => $_SESSION['contact'] ?? '',
    'ic' => $_SESSION['ic_passport'] ?? ''
];

$form_message = '';
$form_message_type = '';
$form_errors = [];

if ($connection && !empty($profile['username'])) {
    $stmt = mysqli_prepare(
        $connection,
        "SELECT staff_name, staff_ic_passport, staff_dob, staff_gender, staff_contact, staff_email
         FROM tbl_staff_info
         WHERE staff_username = ?"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "s", $profile['username']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = $result ? mysqli_fetch_assoc($result) : null;
        if ($row) {
            $profile['full_name'] = $row['staff_name'] ?? $profile['full_name'];
            $profile['email'] = $row['staff_email'] ?? $profile['email'];
            $profile['gender'] = ucfirst(strtolower($row['staff_gender'] ?? $profile['gender']));
            $profile['dob'] = $row['staff_dob'] ?? $profile['dob'];
            $profile['phone'] = $row['staff_contact'] ?? $profile['phone'];
            $profile['ic'] = $row['staff_ic_passport'] ?? $profile['ic'];
            $profile['email'] = preg_replace('/\s+/u', '', $profile['email']);
        }
        mysqli_stmt_close($stmt);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_password' && ($_POST['ajax'] ?? '') === '1') {
    $current_password = $_POST['current_password'] ?? '';
    $is_valid = false;
    if ($connection && $profile['username'] !== '') {
        $password_stmt = mysqli_prepare($connection, "SELECT login_password FROM tbl_login WHERE login_username = ?");
        if ($password_stmt) {
            mysqli_stmt_bind_param($password_stmt, "s", $profile['username']);
            mysqli_stmt_execute($password_stmt);
            $password_result = mysqli_stmt_get_result($password_stmt);
            $password_row = $password_result ? mysqli_fetch_assoc($password_result) : null;
            mysqli_stmt_close($password_stmt);
            if ($password_row) {
                $stored = $password_row['login_password'];
                $is_valid = password_verify($current_password, $stored) || $current_password === $stored;
            }
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => $is_valid]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $original_username = $profile['username'];
    $submitted_username = trim($_POST['username'] ?? $original_username);
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $email = preg_replace('/\s+/u', '', $email);
    $gender = trim($_POST['gender'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone_digits = preg_replace('/\D+/', '', $phone);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($full_name === '') {
        $form_errors['full_name'] = 'This field is required.';
    }
    if ($submitted_username === '') {
        $form_errors['username'] = 'This field is required.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $submitted_username)) {
        $form_errors['username'] = 'Use 3-50 letters, numbers, dots, underscores, or hyphens.';
    }
    if ($email === '') {
        $form_errors['email'] = 'This field is required.';
    } elseif (!preg_match('/@.*\\.com$/i', $email)) {
        $form_errors['email'] = 'Email must contain @ and end with .com.';
    }
    if ($gender === '') {
        $form_errors['gender'] = 'This field is required.';
    } else {
        $gender = strtoupper($gender);
        if (!in_array($gender, ['MALE', 'FEMALE'], true)) {
            $form_errors['gender'] = 'Please select a valid gender.';
        }
    }
    if ($dob === '') {
        $form_errors['dob'] = 'This field is required.';
    }
    if ($phone === '') {
        $form_errors['phone'] = 'This field is required.';
    } elseif (!preg_match('/^(011\\d{8}|01[2-9]\\d{7})$/', $phone_digits)) {
        $form_errors['phone'] = 'Incorrect format';
    }

    if ($new_password !== '' || $confirm_password !== '') {
        if ($current_password === '') {
            $form_errors['current_password'] = 'Enter your current password first.';
        } elseif (strlen($new_password) < 8) {
            $form_errors['new_password'] = 'Use at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $form_errors['confirm_password'] = 'Passwords do not match.';
        }
    }

    if (!$connection) {
        $form_errors['form'] = 'Database connection failed.';
    }

    if ($connection && empty($form_errors) && $submitted_username !== $original_username) {
        $check_stmt = mysqli_prepare($connection, "SELECT login_username FROM tbl_login WHERE login_username = ?");
        if ($check_stmt) {
            mysqli_stmt_bind_param($check_stmt, "s", $submitted_username);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            $existing = $check_result ? mysqli_fetch_assoc($check_result) : null;
            mysqli_stmt_close($check_stmt);
            if ($existing) {
                $form_errors['username'] = 'That username is already taken.';
            }
        } else {
            $form_errors['username'] = 'Unable to verify username.';
        }
    }

    if ($connection && empty($form_errors)) {
        if ($submitted_username !== $original_username) {
            mysqli_begin_transaction($connection);
            $login_stmt = mysqli_prepare(
                $connection,
                "SELECT login_password, login_role, login_status FROM tbl_login WHERE login_username = ?"
            );
            if ($login_stmt) {
                mysqli_stmt_bind_param($login_stmt, "s", $original_username);
                mysqli_stmt_execute($login_stmt);
                $login_result = mysqli_stmt_get_result($login_stmt);
                $login_row = $login_result ? mysqli_fetch_assoc($login_result) : null;
                mysqli_stmt_close($login_stmt);
            } else {
                $login_row = null;
            }

            if (!$login_row) {
                $form_errors['username'] = 'Unable to locate the current login account.';
                mysqli_rollback($connection);
            } else {
                $insert_stmt = mysqli_prepare(
                    $connection,
                    "INSERT INTO tbl_login (login_username, login_password, login_role, login_status)
                     VALUES (?, ?, ?, ?)"
                );
                if ($insert_stmt) {
                    mysqli_stmt_bind_param(
                        $insert_stmt,
                        "ssss",
                        $submitted_username,
                        $login_row['login_password'],
                        $login_row['login_role'],
                        $login_row['login_status']
                    );
                    mysqli_stmt_execute($insert_stmt);
                    mysqli_stmt_close($insert_stmt);
                } else {
                    $form_errors['username'] = 'Unable to create the new login account.';
                    mysqli_rollback($connection);
                }

                if (empty($form_errors)) {
                    $rename_updates = [
                        ["UPDATE tbl_staff_info SET staff_username = ? WHERE staff_username = ?", "ss"],
                        ["UPDATE tbl_booking SET booking_passenger_username = ? WHERE booking_passenger_username = ?", "ss"],
                        ["UPDATE tbl_driver_info SET driver_username = ? WHERE driver_username = ?", "ss"],
                        ["UPDATE tbl_driver_update SET update_driver_username = ? WHERE update_driver_username = ?", "ss"],
                        ["UPDATE tbl_message SET message_sender = ? WHERE message_sender = ?", "ss"],
                        ["UPDATE tbl_message SET message_receiver = ? WHERE message_receiver = ?", "ss"],
                        ["UPDATE tbl_redeem SET redeem_username = ? WHERE redeem_username = ?", "ss"],
                        ["UPDATE tbl_review SET review_passenger_username = ? WHERE review_passenger_username = ?", "ss"],
                        ["UPDATE tbl_ride_offer SET offer_driver_username = ? WHERE offer_driver_username = ?", "ss"],
                        ["UPDATE tbl_trip_passenger SET trip_passenger_passenger_username = ? WHERE trip_passenger_passenger_username = ?", "ss"],
                        ["UPDATE tbl_user_info SET user_username = ? WHERE user_username = ?", "ss"],
                        ["UPDATE tbl_vote SET vote_username = ? WHERE vote_username = ?", "ss"]
                    ];
                    foreach ($rename_updates as $update) {
                        [$sql, $types] = $update;
                        $stmt = mysqli_prepare($connection, $sql);
                        if (!$stmt) {
                            $form_errors['username'] = 'Unable to update linked records.';
                            break;
                        }
                        mysqli_stmt_bind_param($stmt, $types, $submitted_username, $original_username);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }
                }

                if (empty($form_errors)) {
                    $delete_stmt = mysqli_prepare($connection, "DELETE FROM tbl_login WHERE login_username = ?");
                    if ($delete_stmt) {
                        mysqli_stmt_bind_param($delete_stmt, "s", $original_username);
                        mysqli_stmt_execute($delete_stmt);
                        mysqli_stmt_close($delete_stmt);
                    } else {
                        $form_errors['username'] = 'Unable to finalize the username update.';
                    }
                }

                if (!empty($form_errors)) {
                    mysqli_rollback($connection);
                } else {
                    mysqli_commit($connection);
                    $_SESSION['username'] = $submitted_username;
                    $profile['username'] = $submitted_username;
                }
            }
        } else {
            $profile['username'] = $submitted_username;
        }
    }

    if ($connection && empty($form_errors)) {
        if ($new_password !== '') {
            $password_stmt = mysqli_prepare($connection, "SELECT login_password FROM tbl_login WHERE login_username = ?");
            if ($password_stmt) {
                mysqli_stmt_bind_param($password_stmt, "s", $profile['username']);
                mysqli_stmt_execute($password_stmt);
                $password_result = mysqli_stmt_get_result($password_stmt);
                $password_row = $password_result ? mysqli_fetch_assoc($password_result) : null;
                mysqli_stmt_close($password_stmt);

                if (
                    !$password_row
                    || (
                        !password_verify($current_password, $password_row['login_password'])
                        && $current_password !== $password_row['login_password']
                    )
                ) {
                    $form_errors['current_password'] = 'Current password is incorrect.';
                }
            } else {
                $form_errors['current_password'] = 'Unable to verify password.';
            }
        }
    }

    if ($connection && empty($form_errors)) {
        $target_username = $profile['username'];
        if ($phone_digits !== '') {
            $phone = substr($phone_digits, 0, 3) . '-' . substr($phone_digits, 3);
        }
        $update_stmt = mysqli_prepare(
            $connection,
            "UPDATE tbl_staff_info
             SET staff_name = ?, staff_email = ?, staff_gender = ?, staff_dob = ?, staff_contact = ?
             WHERE staff_username = ?"
        );
        if ($update_stmt) {
            mysqli_stmt_bind_param(
                $update_stmt,
                "ssssss",
                $full_name,
                $email,
                $gender,
                $dob,
                $phone,
                $target_username
            );
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        } else {
            $form_errors['form'] = 'Unable to save profile changes.';
        }
    }

    if ($connection && empty($form_errors) && $new_password !== '') {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update_password_stmt = mysqli_prepare(
            $connection,
            "UPDATE tbl_login SET login_password = ? WHERE login_username = ?"
        );
        if ($update_password_stmt) {
            mysqli_stmt_bind_param($update_password_stmt, "ss", $hashed_password, $profile['username']);
            mysqli_stmt_execute($update_password_stmt);
            mysqli_stmt_close($update_password_stmt);
        } else {
            $form_errors['new_password'] = 'Unable to update password.';
        }
    }

    if (empty($form_errors)) {
        $_SESSION['fullname'] = $full_name;
        $_SESSION['email'] = $email;
        $_SESSION['gender'] = $gender;
        $_SESSION['dob'] = $dob;
        $_SESSION['phone'] = $phone;
        $_SESSION['username'] = $profile['username'];

        $profile['full_name'] = $full_name;
        $profile['email'] = $email;
        $profile['gender'] = ucfirst(strtolower($gender));
        $profile['dob'] = $dob;
        $profile['phone'] = $phone;

        $_SESSION['profile_flash'] = [
            'type' => 'success',
            'text' => 'Changes saved successfully.'
        ];
        header('Location: staff_Profile.php');
        exit;
    } else {
        $profile['full_name'] = $full_name;
        $profile['email'] = $email;
        $profile['gender'] = ucfirst(strtolower($gender));
        $profile['dob'] = $dob;
        $profile['phone'] = $phone;
        $form_message = $form_errors['form'] ?? 'Please fix the highlighted fields.';
        $form_message_type = 'error';
    }
}

if (!empty($_SESSION['profile_flash'])) {
    $form_message = $_SESSION['profile_flash']['text'] ?? '';
    $form_message_type = $_SESSION['profile_flash']['type'] ?? '';
    unset($_SESSION['profile_flash']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Profile - RideShare@APU</title>
    <link rel="stylesheet" href="staff_base.css">
    <link rel="stylesheet" href="staff_Profile.css">
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

    <main class="profile-main">
        <div class="profile-container">

            <!-- Profile Header Card -->
            <div class="profile-header-card">
                <div class="profile-banner"></div>
                <div class="profile-info-top">
                    <div class="profile-avatar-section">
                        <div class="profile-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                    </div>
                    <div class="profile-title-section">
                        <h1><?= htmlspecialchars($profile['full_name']) ?></h1>
                        <p class="profile-role">
                            <i class="fas fa-user-tie"></i>
                            Staff Member
                        </p>
                        <p class="profile-username">@<?= htmlspecialchars($profile['username']) ?></p>
                    </div>
                </div>
            </div>

            <div class="profile-form-card">
                <div class="form-header">
                    <h2><i class="fas fa-user-edit"></i> Edit Staff Details</h2>
                    <p>Update your staff profile details and save changes directly.</p>
                    <?php if ($form_message): ?>
                        <div class="form-message <?= htmlspecialchars($form_message_type) ?>">
                            <?= htmlspecialchars($form_message) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <form method="POST" action="" id="editProfileForm" novalidate>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>
                                <i class="fas fa-id-card"></i>
                                Full Name
                            </label>
                            <input type="text"
                                   name="full_name"
                                   value="<?= htmlspecialchars($profile['full_name']) ?>"
                                   data-required="true">
                            <span class="field-error" data-error-for="full_name"><?= htmlspecialchars($form_errors['full_name'] ?? '') ?></span>
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-user"></i>
                                Username
                            </label>
                            <input type="text"
                                   name="username"
                                   value="<?= htmlspecialchars($profile['username']) ?>"
                                   data-required="true"
                                   pattern="[A-Za-z0-9._-]{3,50}">
                            <span class="field-hint"></span>
                            <span class="field-error" data-error-for="username"><?= htmlspecialchars($form_errors['username'] ?? '') ?></span>
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-envelope"></i>
                                Email
                            </label>
                            <input type="email"
                                   name="email"
                                   value="<?= htmlspecialchars($profile['email']) ?>"
                                   data-required="true">
                            <span class="field-error" data-error-for="email"><?= htmlspecialchars($form_errors['email'] ?? '') ?></span>
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-venus-mars"></i>
                                Gender
                            </label>
                            <div class="custom-select" data-target="genderSelect" data-open="false">
                                <button type="button" class="custom-select-trigger" id="genderTrigger" aria-haspopup="listbox" aria-expanded="false">
                                    <span class="custom-select-label" id="genderLabel">
                                        <?= htmlspecialchars($profile['gender'] !== '' ? $profile['gender'] : 'Select gender') ?>
                                    </span>
                                    <i class="material-icons" aria-hidden="true">expand_more</i>
                                </button>
                                <div class="custom-select-dropdown" role="listbox" aria-labelledby="genderTrigger">
                                    <button type="button" class="custom-select-option<?= $profile['gender'] === '' ? ' is-selected' : '' ?>" role="option" aria-selected="<?= $profile['gender'] === '' ? 'true' : 'false' ?>" data-value="">
                                        Select gender
                                    </button>
                                    <button type="button" class="custom-select-option<?= $profile['gender'] === 'Male' ? ' is-selected' : '' ?>" role="option" aria-selected="<?= $profile['gender'] === 'Male' ? 'true' : 'false' ?>" data-value="Male">
                                        Male
                                    </button>
                                    <button type="button" class="custom-select-option<?= $profile['gender'] === 'Female' ? ' is-selected' : '' ?>" role="option" aria-selected="<?= $profile['gender'] === 'Female' ? 'true' : 'false' ?>" data-value="Female">
                                        Female
                                    </button>
                                </div>
                            </div>
                            <select class="sr-only" name="gender" id="genderSelect" data-required="true">
                                <option value="" <?= $profile['gender'] === '' ? 'selected' : '' ?>>Select gender</option>
                                <option value="Male" <?= $profile['gender'] === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= $profile['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                            <span class="field-error" data-error-for="gender"><?= htmlspecialchars($form_errors['gender'] ?? '') ?></span>
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-calendar-alt"></i>
                                Date of Birth
                            </label>
                            <input type="date"
                                   name="dob"
                                   value="<?= htmlspecialchars($profile['dob']) ?>"
                                   data-required="true">
                            <span class="field-error" data-error-for="dob"><?= htmlspecialchars($form_errors['dob'] ?? '') ?></span>
                        </div>

                        <div class="form-group">
                            <label>
                                <i class="fas fa-phone"></i>
                                Phone Number
                            </label>
                            <input type="tel"
                                   name="phone"
                                   value="<?= htmlspecialchars($profile['phone']) ?>"
                                   placeholder="012-3456789"
                                   pattern="01[0-9]-?[0-9]{7}"
                                   data-required="true">
                            <span class="field-hint"></span>
                            <span class="field-error" data-error-for="phone"><?= htmlspecialchars($form_errors['phone'] ?? '') ?></span>
                        </div>

                        <div class="form-group full-width">
                            <label>
                                <i class="fas fa-id-badge"></i>
                                IC/Passport Number
                            </label>
                            <input type="text"
                                   value="<?= htmlspecialchars($profile['ic']) ?>"
                                   class="readonly-field"
                                   readonly>
                            <span class="field-hint">IC/Passport cannot be changed</span>
                        </div>
                    </div>

                    <div class="password-section">
                        <h3><i class="fas fa-lock"></i> Update Password</h3>
                    <p class="section-hint">Enter your current password to update it.</p>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>
                                <i class="fas fa-key"></i>
                                Current Password
                            </label>
                            <div class="password-field">
                                <input type="password"
                                       name="current_password"
                                       placeholder="Enter current password">
                                <button type="button"
                                        class="password-toggle"
                                        data-target="current_password"
                                        aria-label="Show current password"
                                        aria-pressed="false">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <span class="field-error" data-error-for="current_password"><?= htmlspecialchars($form_errors['current_password'] ?? '') ?></span>
                        </div>

                    <div class="password-reveal full-width" id="passwordReveal">
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-lock"></i>
                                    New Password
                                </label>
                                <div class="password-field">
                                    <input type="password"
                                           name="new_password"
                                           minlength="8"
                                           placeholder="Enter new password">
                                </div>
                                <div class="password-strength" id="passwordStrength">
                                    <span class="strength-bar"></span>
                                    <span class="strength-label">Strength: --</span>
                                </div>
                                <span class="field-hint">Minimum 8 characters</span>
                                <span class="field-error" data-error-for="new_password"><?= htmlspecialchars($form_errors['new_password'] ?? '') ?></span>
                            </div>

                            <div class="form-group">
                                <label>
                                    <i class="fas fa-lock"></i>
                                    Confirm New Password
                                </label>
                                <div class="password-field">
                                    <input type="password"
                                           name="confirm_password"
                                           placeholder="Confirm new password">
                                </div>
                                <span class="field-error" data-error-for="confirm_password"><?= htmlspecialchars($form_errors['confirm_password'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="resetEditProfileForm()">
                            <i class="fas fa-undo"></i>
                            Reset
                        </button>
                        <button type="submit" name="update_profile" class="btn-primary">
                            <i class="fas fa-save"></i>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>

    <?php include '../global/footer.php'; ?>

    <script src="staff_custom_select.js"></script>
    <script>
        function resetEditProfileForm() {
            const form = document.getElementById('editProfileForm');
            form.reset();
            const phoneInput = form.querySelector('[name="phone"]');
            if (phoneInput) {
                delete phoneInput.dataset.touched;
            }
            clearFieldErrors(form);
            const message = document.querySelector('.form-message');
            if (message) {
                message.remove();
            }
            const reveal = document.getElementById('passwordReveal');
            if (reveal) {
                reveal.classList.remove('is-visible');
            }
        }

        function clearFieldErrors(form) {
            form.querySelectorAll('.field-error').forEach((error) => {
                error.textContent = '';
            });
            form.querySelectorAll('.field-success').forEach((input) => {
                input.classList.remove('field-success');
            });
            form.querySelectorAll('.field-error-input').forEach((input) => {
                input.classList.remove('field-error-input');
            });
        }

        function setFieldError(form, fieldName, message) {
            const error = form.querySelector(`[data-error-for="${fieldName}"]`);
            if (error) {
                error.textContent = message;
            }
            const input = form.querySelector(`[name="${fieldName}"]`);
            if (input) {
                input.classList.add('field-error-input');
                input.classList.remove('field-success');
            }
        }

        function setFieldSuccess(form, fieldName) {
            const input = form.querySelector(`[name="${fieldName}"]`);
            if (input) {
                input.classList.add('field-success');
                input.classList.remove('field-error-input');
            }
            const error = form.querySelector(`[data-error-for="${fieldName}"]`);
            if (error) {
                error.textContent = '';
            }
        }

        function clearFieldState(form, fieldName) {
            const input = form.querySelector(`[name="${fieldName}"]`);
            if (input) {
                input.classList.remove('field-success');
                input.classList.remove('field-error-input');
            }
            const error = form.querySelector(`[data-error-for="${fieldName}"]`);
            if (error) {
                error.textContent = '';
            }
        }

        function setToggleState(button, input, isVisible) {
            const icon = button.querySelector('i');
            input.type = isVisible ? 'text' : 'password';
            button.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
            button.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
            if (icon) {
                icon.classList.toggle('fa-eye', !isVisible);
                icon.classList.toggle('fa-eye-slash', isVisible);
            }
        }

        function updateStrengthMeter(value) {
            const meter = document.getElementById('passwordStrength');
            const bar = meter.querySelector('.strength-bar');
            const label = meter.querySelector('.strength-label');

            let score = 0;
            if (value.length >= 8) score += 1;
            if (/[A-Z]/.test(value)) score += 1;
            if (/[0-9]/.test(value)) score += 1;
            if (/[^A-Za-z0-9]/.test(value)) score += 1;

            const labels = ['Weak', 'Fair', 'Good', 'Strong'];
            const colors = ['#e74c3c', '#f39c12', '#27ae60', '#1b8f4b'];
            const idx = Math.max(score - 1, 0);

            bar.style.width = `${(score / 4) * 100}%`;
            bar.style.background = colors[idx] || '#e0e0e0';
            label.textContent = `Strength: ${score === 0 ? '--' : labels[idx]}`;
        }

        const currentPasswordInput = document.querySelector('[name="current_password"]');
        const newPasswordInput = document.querySelector('[name="new_password"]');
        const confirmPasswordInput = document.querySelector('[name="confirm_password"]');
        const phoneInput = document.querySelector('[name="phone"]');
        const emailInput = document.querySelector('[name="email"]');
        const formElement = document.getElementById('editProfileForm');
        const passwordReveal = document.getElementById('passwordReveal');

        document.querySelectorAll('.password-toggle').forEach((button) => {
            const target = button.getAttribute('data-target');
            const input = document.querySelector(`[name="${target}"]`);
            if (!input) {
                return;
            }
            setToggleState(button, input, false);
            button.addEventListener('click', () => {
                const shouldShow = input.type === 'password';
                setToggleState(button, input, shouldShow);
                input.focus();
            });
        });

        if (currentPasswordInput && passwordReveal) {
            let checkTimer = null;

            const hideReveal = () => {
                passwordReveal.classList.remove('is-visible');
            };

            const verifyCurrentPassword = () => {
                const value = currentPasswordInput.value.trim();
                if (!value) {
                    hideReveal();
                    clearFieldState(formElement, 'current_password');
                    return;
                }
                const formData = new FormData();
                formData.append('action', 'check_password');
                formData.append('ajax', '1');
                formData.append('current_password', value);
                fetch('staff_Profile.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                })
                    .then((response) => response.json())
                    .then((data) => {
                        if (data.ok) {
                            setFieldSuccess(formElement, 'current_password');
                            passwordReveal.classList.add('is-visible');
                        } else {
                            setFieldError(formElement, 'current_password', 'Current password is incorrect.');
                            hideReveal();
                        }
                    })
                    .catch(() => {
                        setFieldError(formElement, 'current_password', 'Unable to verify password.');
                        hideReveal();
                    });
            };

            currentPasswordInput.addEventListener('input', () => {
                clearFieldState(formElement, 'current_password');
                hideReveal();
                if (checkTimer) {
                    clearTimeout(checkTimer);
                }
            });

            currentPasswordInput.addEventListener('blur', () => {
                if (checkTimer) {
                    clearTimeout(checkTimer);
                }
                checkTimer = setTimeout(verifyCurrentPassword, 150);
            });
        }

        if (phoneInput) {
            phoneInput.addEventListener('input', () => {
                phoneInput.dataset.touched = 'true';
            });
        }

        if (emailInput) {
            const emailPattern = /@.*\.com$/i;
            emailInput.addEventListener('input', () => {
                const raw = emailInput.value;
                const value = raw.replace(/\s+/g, '');
                if (raw !== value) {
                    emailInput.value = value;
                }
                if (!value) {
                    clearFieldState(formElement, 'email');
                    return;
                }
                if (emailPattern.test(value)) {
                    setFieldSuccess(formElement, 'email');
                } else {
                    setFieldError(formElement, 'email', 'Email must contain @ and end with .com.');
                }
            });
        }

        if (phoneInput) {
            const phonePattern = /^(011\d{8}|01[2-9]\d{7})$/;
            phoneInput.addEventListener('input', () => {
                const value = phoneInput.value.trim();
                const digits = value.replace(/\D+/g, '');
                if (!value) {
                    clearFieldState(formElement, 'phone');
                    return;
                }
                if (phonePattern.test(digits)) {
                    setFieldSuccess(formElement, 'phone');
                } else {
                    setFieldError(formElement, 'phone', 'Incorrect format');
                }
            });
        }

        newPasswordInput.addEventListener('input', (e) => {
            updateStrengthMeter(e.target.value);
            if (confirmPasswordInput.value.trim()) {
                if (confirmPasswordInput.value.trim() !== e.target.value.trim()) {
                    setFieldError(document.getElementById('editProfileForm'), 'confirm_password', 'Passwords do not match.');
                } else {
                    setFieldSuccess(document.getElementById('editProfileForm'), 'confirm_password');
                    const error = document.querySelector('[data-error-for="confirm_password"]');
                    if (error) {
                        error.textContent = '';
                    }
                }
            }
        });

        confirmPasswordInput.addEventListener('input', (e) => {
            const value = e.target.value.trim();
            const compare = newPasswordInput.value.trim();
            if (!value) {
                setFieldError(document.getElementById('editProfileForm'), 'confirm_password', 'Please confirm the new password.');
                return;
            }
            if (value !== compare) {
                setFieldError(document.getElementById('editProfileForm'), 'confirm_password', 'Passwords do not match.');
                return;
            }
            setFieldSuccess(document.getElementById('editProfileForm'), 'confirm_password');
            const error = document.querySelector('[data-error-for="confirm_password"]');
            if (error) {
                error.textContent = '';
            }
        });

        document.getElementById('editProfileForm').addEventListener('submit', function(e) {
            const form = e.currentTarget;
            clearFieldErrors(form);

            let isValid = true;
            const requiredFields = ['full_name', 'username', 'email', 'gender', 'dob', 'phone'];
            requiredFields.forEach((field) => {
                const input = form.querySelector(`[name="${field}"]`);
                if (!input || !input.value.trim()) {
                    setFieldError(form, field, 'This field is required.');
                    isValid = false;
                } else {
                    setFieldSuccess(form, field);
                }
            });

            const emailInput = form.querySelector('[name="email"]');
            const emailValue = emailInput ? emailInput.value.replace(/\s+/g, '') : '';
            const emailPattern = /@.*\.com$/i;
            if (emailValue && !emailPattern.test(emailValue)) {
                setFieldError(form, 'email', 'Email must contain @ and end with .com.');
                isValid = false;
            }

            const usernameInput = form.querySelector('[name="username"]');
            const usernameValue = usernameInput ? usernameInput.value.trim() : '';
            const usernamePattern = /^[A-Za-z0-9._-]{3,50}$/;
            if (usernameValue && !usernamePattern.test(usernameValue)) {
                setFieldError(form, 'username', 'Use 3-50 letters, numbers, dots, underscores, or hyphens.');
                isValid = false;
            }

            const phoneInput = form.querySelector('[name="phone"]');
            const phoneValue = phoneInput ? phoneInput.value.trim() : '';
            const phoneDigits = phoneValue.replace(/\D+/g, '');
            const phonePattern = /^(011\d{8}|01[2-9]\d{7})$/;
            const shouldValidatePhone = phoneInput
                && phoneValue
                && phoneInput.dataset.touched === 'true';
            if (shouldValidatePhone && !phonePattern.test(phoneDigits)) {
                setFieldError(form, 'phone', 'Incorrect format');
                isValid = false;
            }

            const currentPassword = form.querySelector('[name="current_password"]').value.trim();
            const newPassword = form.querySelector('[name="new_password"]').value.trim();
            const confirmPassword = form.querySelector('[name="confirm_password"]').value.trim();

            if (currentPassword) {
                if (!newPassword) {
                    setFieldError(form, 'new_password', 'New password is required.');
                    isValid = false;
                } else if (newPassword.length < 8) {
                    setFieldError(form, 'new_password', 'Use at least 8 characters.');
                    isValid = false;
                } else {
                    setFieldSuccess(form, 'new_password');
                }

                if (!confirmPassword) {
                    setFieldError(form, 'confirm_password', 'Please confirm the new password.');
                    isValid = false;
                } else if (newPassword !== confirmPassword) {
                    setFieldError(form, 'confirm_password', 'Passwords do not match.');
                    isValid = false;
                } else {
                    setFieldSuccess(form, 'confirm_password');
                }
            } else if (newPassword || confirmPassword) {
                setFieldError(form, 'current_password', 'Enter your current password first.');
                isValid = false;
            }

            if (!isValid) {
                e.preventDefault();
                return;
            }

            if (!confirm('Save changes to your profile?')) {
                e.preventDefault();
            }
        });

        window.addEventListener('load', () => {
            const navEntry = performance.getEntriesByType('navigation')[0];
            const isReload = (navEntry && navEntry.type === 'reload')
                || (performance.navigation && performance.navigation.type === 1);
            if (!isReload) {
                return;
            }
            const message = document.querySelector('.form-message');
            if (message) {
                message.remove();
            }
            if (formElement) {
                clearFieldErrors(formElement);
            }
        });

    </script>
</body>
</html>
