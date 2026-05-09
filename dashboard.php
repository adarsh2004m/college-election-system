<?php
require_once 'includes/auth.php';
require_once 'config/db_config.php';
requireLogin();

$role = $_SESSION['role'];

// Redirect to role-specific dashboard
if ($role == 'admin') {
    header('Location: admin/dashboard.php');
} elseif ($role == 'presiding_officer') {
    header('Location: officer/dashboard.php');
} elseif ($role == 'student') {
    header('Location: student/dashboard.php');
} else {
    header('Location: login.php');
}
exit;
?>