<?php
session_start();
include("config/db.php");

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['user_id']) || !isset($_POST['type'])) {
    http_response_code(400); echo json_encode(['error' => 'Unauthorized']); exit();
}
// Don't log for admin
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    http_response_code(200); echo json_encode(['logged' => false, 'reason' => 'admin_exempt']); exit();
}

$user_id = (int)$_SESSION['user_id'];
$type    = substr(trim($_POST['type']), 0, 120);
if (empty($type)) { http_response_code(400); exit(); }

// Rate limit: max 20 violations per session
if (!isset($_SESSION['violation_count'])) $_SESSION['violation_count'] = 0;
$_SESSION['violation_count']++;
if ($_SESSION['violation_count'] > 20) {
    http_response_code(429); echo json_encode(['error' => 'Rate limit exceeded']); exit();
}

// Persist to DB
$stmt = $conn->prepare("INSERT INTO fraud_logs (user_id, violation_type, timestamp) VALUES (?, ?, NOW())");
$stmt->bind_param("is", $user_id, $type);
$stmt->execute();
$stmt->close();

http_response_code(200);
echo json_encode([
    'logged' => true,
    'count'  => $_SESSION['violation_count'],
    'type'   => $type
]);