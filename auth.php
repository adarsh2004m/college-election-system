<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../login.php');
        exit;
    }
}

function requireRole($role) {
    requireLogin();
    if ($_SESSION['role'] !== $role && $_SESSION['role'] !== 'admin') {
        header('Location: ../dashboard.php');
        exit;
    }
}

function getCurrentUser($pdo) {
    if (!isLoggedIn()) return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function hasVoted($pdo, $student_id, $election_id) {
    $stmt = $pdo->prepare("SELECT id FROM votes WHERE student_id = ? AND election_id = ?");
    $stmt->execute([$student_id, $election_id]);
    return $stmt->fetch() !== false;
}
?>