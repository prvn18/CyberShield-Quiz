<?php
session_start();
include("config/db.php");

// Auth check
if (!isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit(); }
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') { header("Location: admin.php"); exit(); }

// Allow both auto-submit (GET) and manual (POST)
$quiz_id = isset($_POST['quiz_id']) ? (int)$_POST['quiz_id'] : (isset($_GET['quiz_id']) ? (int)$_GET['quiz_id'] : 0);
if (!$quiz_id) { header("Location: dashboard.php"); exit(); }

$user_id = (int)$_SESSION['user_id'];

// Clean up timer session
$sess_key = 'end_time_' . $quiz_id;
if (isset($_SESSION[$sess_key])) unset($_SESSION[$sess_key]);

// Get submitted answers
$submitted_answers = $_POST['answer'] ?? [];

// Validate quiz exists
$stmt = $conn->prepare("SELECT id, title FROM quizzes WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $quiz_id); $stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$quiz) { header("Location: dashboard.php"); exit(); }

// ── SCORE CALCULATION (server-side, tamper-proof) ──
$stmt_q = $conn->prepare("SELECT id FROM questions WHERE quiz_id = ?");
$stmt_q->bind_param("i", $quiz_id); $stmt_q->execute();
$q_result = $stmt_q->get_result();
$total_questions = $q_result->num_rows;
$correct_answers = 0;

while ($q = $q_result->fetch_assoc()) {
    $qid = $q['id'];
    if (isset($submitted_answers[$qid])) {
        $selected = (int)$submitted_answers[$qid];
        $opt_stmt = $conn->prepare("SELECT is_correct FROM options WHERE id = ? AND question_id = ? LIMIT 1");
        $opt_stmt->bind_param("ii", $selected, $qid); $opt_stmt->execute();
        $opt = $opt_stmt->get_result()->fetch_assoc(); $opt_stmt->close();
        if ($opt && $opt['is_correct'] == 1) $correct_answers++;
    }
}
$stmt_q->close();

$score = ($total_questions > 0) ? round(($correct_answers / $total_questions) * 100, 1) : 0;

// ── SAVE RESULT ──
$stmt_save = $conn->prepare("INSERT INTO user_results (user_id, quiz_id, score, total_questions, correct_answers) VALUES (?, ?, ?, ?, ?)");
$stmt_save->bind_param("iidii", $user_id, $quiz_id, $score, $total_questions, $correct_answers);
$stmt_save->execute();
$result_id = $conn->insert_id;
$stmt_save->close();

// ── STORE FOR REVIEW (1hr expiry) ──
$_SESSION['review_data_' . $result_id] = [
    'quiz_id'   => $quiz_id,
    'answers'   => $submitted_answers,
    'score'     => $score,
    'correct'   => $correct_answers,
    'total'     => $total_questions,
    'result_id' => $result_id,
    'expires'   => time() + 3600
];

header("Location: review.php?result_id=" . $result_id);
exit();