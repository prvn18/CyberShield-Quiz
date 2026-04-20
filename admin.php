<?php
session_start();
include("config/db.php");

// ── STRICT ADMIN-ONLY ACCESS ──
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php"); exit();
}

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ── API KEY ──
$CLAUDE_KEY = defined('CLAUDE_API_KEY') ? CLAUDE_API_KEY : "YOUR_CLAUDE_API_KEY_HERE";
$GEMINI_KEY = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : "AIzaSyDldZswSjw9_Eoi7OJG4OwJ2aaMsooXBns";

// ── ENSURE DB COLUMNS ──
if (!mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'image_url'")))
    mysqli_query($conn, "ALTER TABLE questions ADD COLUMN image_url VARCHAR(255) DEFAULT NULL");
if (!mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM questions LIKE 'explanation'")))
    mysqli_query($conn, "ALTER TABLE questions ADD COLUMN explanation TEXT DEFAULT NULL");
if (!mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM quizzes LIKE 'difficulty'")))
    mysqli_query($conn, "ALTER TABLE quizzes ADD COLUMN difficulty VARCHAR(30) DEFAULT 'Intermediate'");
if (!mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM quizzes LIKE 'topic'")))
    mysqli_query($conn, "ALTER TABLE quizzes ADD COLUMN topic VARCHAR(100) DEFAULT NULL");

// ── UPLOAD DIR ──
if (!is_dir("uploads/q_images")) mkdir("uploads/q_images", 0755, true);

// ── DELETE QUIZ ──
if (isset($_GET['delete_quiz'])) {
    $del_id = (int)$_GET['delete_quiz'];
    $qs = mysqli_query($conn, "SELECT id FROM questions WHERE quiz_id = $del_id");
    while ($qr = mysqli_fetch_assoc($qs))
        mysqli_query($conn, "DELETE FROM options WHERE question_id = " . (int)$qr['id']);
    mysqli_query($conn, "DELETE FROM questions WHERE quiz_id = $del_id");
    mysqli_query($conn, "DELETE FROM user_results WHERE quiz_id = $del_id");
    mysqli_query($conn, "DELETE FROM quizzes WHERE id = $del_id");
    header("Location: admin.php?msg=deleted&target=manage"); exit();
}

// ── STATS ──
function qval($conn, $sql) { $r = mysqli_query($conn, $sql); $row = mysqli_fetch_assoc($r); return $row['v'] ?? 0; }
$total_quizzes  = qval($conn, "SELECT COUNT(*) as v FROM quizzes");
$total_students = qval($conn, "SELECT COUNT(*) as v FROM users");
$avg_score      = round(qval($conn, "SELECT AVG(score) as v FROM user_results"), 1);
$total_attempts = qval($conn, "SELECT COUNT(*) as v FROM user_results");
$total_fraud    = qval($conn, "SELECT COUNT(*) as v FROM fraud_logs");
$total_qs       = qval($conn, "SELECT COUNT(*) as v FROM questions");

$chart_labels = []; $chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('D', strtotime($date));
    $chart_data[]   = qval($conn, "SELECT COUNT(*) as v FROM user_results WHERE DATE(created_at) = '$date'");
}

// ── AI GENERATORS ──
function callClaude($key, $prompt) {
    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json", "x-api-key: $key", "anthropic-version: 2023-06-01"],
        CURLOPT_POSTFIELDS => json_encode(["model" => "claude-sonnet-4-20250514", "max_tokens" => 4096,
            "messages" => [["role" => "user", "content" => $prompt]]]),
        CURLOPT_TIMEOUT => 90
    ]);
    $res = curl_exec($ch); curl_close($ch);
    $data = json_decode($res, true);
    $txt = $data['content'][0]['text'] ?? null;
    if (!$txt) return null;
    $txt = preg_replace('/^```json\s*|```$/m', '', trim($txt));
    if (preg_match('/\[[\s\S]+\]/m', $txt, $m)) $txt = $m[0];
    return json_decode($txt, true);
}

function callGemini($key, $prompt) {

    // ✅ LATEST MODEL (IMPORTANT FIX)
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=".$key;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],

        // ✅ FIXED BODY
        CURLOPT_POSTFIELDS => json_encode([
            "contents" => [
                ["parts" => [["text" => $prompt]]]
            ],
            "generationConfig" => [
                "temperature" => 0.7,
                "maxOutputTokens" => 4096
            ]
        ]),

        CURLOPT_TIMEOUT => 120
    ]);

    $res = curl_exec($ch);

    // ❌ CURL ERROR
    if (curl_errno($ch)) {
        die("CURL ERROR: " . curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($res, true);

    // ❌ SHOW REAL ERROR
    if ($httpCode !== 200) {
        echo "<pre>HTTP ERROR ($httpCode):\n";
        print_r($data);
        echo "</pre>";
        exit();
    }

    // ✅ EXTRACT TEXT SAFELY
    $text = '';
    if (isset($data['candidates'][0]['content']['parts'])) {
        foreach ($data['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }
    }

    if (empty($text)) {
        echo "<pre>EMPTY RESPONSE:\n";
        print_r($data);
        echo "</pre>";
        exit();
    }

    // 🧹 CLEAN JSON
    $text = str_replace(["```json", "```"], "", $text);
    $text = trim($text);

    // ✅ EXTRACT ARRAY
    preg_match('/\[[\s\S]*\]/', $text, $matches);
    $jsonText = $matches[0] ?? '';

    $result = json_decode($jsonText, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<pre>JSON ERROR:\n$jsonText</pre>";
        exit();
    }

    return $result;
}
function saveGeneratedQuiz($conn, $topic, $time, $diff, $ai_questions) {
    $stmt = $conn->prepare("INSERT INTO quizzes (title, topic, duration, difficulty) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $topic, $topic, $time, $diff);
    $stmt->execute();
    $quiz_id = $conn->insert_id; $stmt->close();
    $sq = $conn->prepare("INSERT INTO questions (quiz_id, question_text, explanation) VALUES (?, ?, ?)");
    $so = $conn->prepare("INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
    foreach ($ai_questions as $q) {
        if (empty($q['question']) || empty($q['options'])) continue;
        $expl = $q['explanation'] ?? '';
        $sq->bind_param("iss", $quiz_id, $q['question'], $expl); $sq->execute();
        $qid = $conn->insert_id;
        foreach ($q['options'] as $idx => $opt) {
            $isc = ($idx == ($q['correct_index'] ?? 0)) ? 1 : 0;
            $so->bind_param("isi", $qid, $opt, $isc); $so->execute();
        }
    }
    $sq->close(); $so->close();
    return $quiz_id;
}

// ── POST HANDLING ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

// AI GENERATION
  if (isset($_POST['generate_ai_quiz'])) {

    // ✅ FIRST define variables
    $topic  = substr(trim($_POST['topic']), 0, 100);
    $count  = max(3, min(30, (int)$_POST['q_count']));
    $time   = max(5, (int)$_POST['duration']);
    $diff   = in_array($_POST['difficulty'], ['Beginner','Intermediate','Expert']) ? $_POST['difficulty'] : 'Intermediate';
    $engine = $_POST['engine'] ?? 'claude';
    $lang   = $_POST['lang'] ?? 'English';

    // ✅ safety check
    if (empty($topic)) {
        die("Topic is required");
    }

    // ✅ context
    $cyber_context = "This is for a CYBER CRIME AWARENESS platform designed to educate users about online safety and stopping cyber crime.";

    // ✅ FINAL FIXED PROMPT
    $prompt = $cyber_context . "
Generate exactly $count multiple choice questions about '$topic' for $diff level students in $lang.

Return ONLY valid JSON array.

Each question MUST include:
- question
- options (4)
- correct_index
- explanation

IMPORTANT:
- Do NOT cut response
- Complete full JSON
- No markdown
- No extra text

Format:
[
  {
    \"question\": \"...\",
    \"options\": [\"A\",\"B\",\"C\",\"D\"],
    \"correct_index\": 0,
    \"explanation\": \"...\"
  }
]
";

    // ✅ call AI
    $ai_questions = null;

    if ($engine === 'claude') {
        $ai_questions = callClaude($CLAUDE_KEY, $prompt);
    } else {
        $ai_questions = callGemini($GEMINI_KEY, $prompt);
    }

    // ✅ save quiz
    if ($ai_questions && is_array($ai_questions) && count($ai_questions) > 0) {
        saveGeneratedQuiz($conn, $topic, $time, $diff, $ai_questions);
        header("Location: admin.php?msg=gen_success&target=manage");
        exit();
    } else {
        header("Location: admin.php?msg=ai_error&target=gen");
        exit();
    }
}
    // MANUAL QUIZ CREATION
    if (isset($_POST['save_manual_quiz'])) {
        $title = substr(trim($_POST['m_title']), 0, 200);
        $topic = substr(trim($_POST['m_topic']), 0, 100);
        $time  = max(5, (int)$_POST['m_duration']);
        $diff  = in_array($_POST['m_difficulty'], ['Beginner','Intermediate','Expert']) ? $_POST['m_difficulty'] : 'Intermediate';
        if (empty($title)) { header("Location: admin.php?msg=err_title&target=manual"); exit(); }

        $stmt = $conn->prepare("INSERT INTO quizzes (title, topic, duration, difficulty) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $title, $topic, $time, $diff); $stmt->execute();
        $quiz_id = $conn->insert_id; $stmt->close();

        if (isset($_POST['qs']) && is_array($_POST['qs'])) {
            $sq = $conn->prepare("INSERT INTO questions (quiz_id, question_text, explanation, image_url) VALUES (?, ?, ?, ?)");
            $so = $conn->prepare("INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
            foreach ($_POST['qs'] as $idx => $q) {
                $q_txt = trim($q['text'] ?? '');
                $expl  = trim($q['explanation'] ?? '');
                if (empty($q_txt)) continue;
                $img_url = null;
                // Handle image upload
                if (isset($_FILES['qs']['error'][$idx]['image']) && $_FILES['qs']['error'][$idx]['image'] === UPLOAD_ERR_OK) {
                    $tmp  = $_FILES['qs']['tmp_name'][$idx]['image'];
                    $size = $_FILES['qs']['size'][$idx]['image'];
                    $ext  = strtolower(pathinfo($_FILES['qs']['name'][$idx]['image'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png','gif','webp']) && $size < 5000000) {
                        $fname = "q_" . uniqid() . "." . $ext;
                        $dest  = "uploads/q_images/" . $fname;
                        if (move_uploaded_file($tmp, $dest)) $img_url = $dest;
                    }
                }
                $sq->bind_param("isss", $quiz_id, $q_txt, $expl, $img_url); $sq->execute();
                $qid = $conn->insert_id;
                if (isset($q['opts']) && is_array($q['opts'])) {
                    $correct_idx = (int)($q['correct'] ?? 0);
                    foreach ($q['opts'] as $oi => $opt_txt) {
                        $opt_txt = trim($opt_txt);
                        if (empty($opt_txt)) continue;
                        $isc = ($oi === $correct_idx) ? 1 : 0;
                        $so->bind_param("isi", $qid, $opt_txt, $isc); $so->execute();
                    }
                }
            }
            $sq->close(); $so->close();
        }
        header("Location: admin.php?msg=man_success&target=manage"); exit();
    }
}

// ── FETCH DATA ──
$quizzes_res  = mysqli_query($conn, "SELECT q.*, (SELECT COUNT(*) FROM questions WHERE quiz_id=q.id) as q_cnt, (SELECT COUNT(*) FROM user_results WHERE quiz_id=q.id) as attempt_cnt FROM quizzes q ORDER BY q.id DESC");
$fraud_logs   = mysqli_query($conn, "SELECT f.*, u.fullname FROM fraud_logs f LEFT JOIN users u ON f.user_id = u.id ORDER BY f.timestamp DESC LIMIT 50");
$students_data = mysqli_query($conn, "SELECT u.fullname, u.email, COUNT(r.id) as attempts, ROUND(AVG(r.score),1) as avg_score, MAX(r.score) as best, MAX(r.created_at) as last_seen FROM users u LEFT JOIN user_results r ON u.id = r.user_id GROUP BY u.id ORDER BY avg_score DESC LIMIT 100");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CyberShield — Admin Command Center</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=IBM+Plex+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--bg:#030b14;--surface:#0a1628;--surface2:#0f1f38;--border:rgba(0,255,136,0.12);--border2:rgba(255,255,255,0.06);--green:#00ff88;--cyan:#00d4ff;--red:#ff4d6d;--gold:#f5c842;--purple:#a855f7;--text:#e8f0fe;--muted:#4a6070;--fd:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'IBM Plex Mono',monospace;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:var(--fb);display:flex;height:100vh;overflow:hidden;}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,136,0.015) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,0.015) 1px,transparent 1px);background-size:60px 60px;pointer-events:none;}

/* SIDEBAR */
.sidebar{width:260px;background:rgba(3,11,20,0.98);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:24px 14px;flex-shrink:0;}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.logo-icon{width:34px;height:34px;background:linear-gradient(135deg,var(--red),#ff8800);border-radius:9px;display:flex;align-items:center;justify-content:center;}
.logo-text{font-family:var(--fd);font-size:17px;font-weight:800;}
.logo-badge{background:var(--gold);color:#000;font-size:8px;font-weight:700;padding:2px 6px;border-radius:4px;font-family:var(--fm);margin-left:auto;}
.admin-label{font-family:var(--fm);font-size:9px;color:var(--gold);text-transform:uppercase;letter-spacing:1px;margin-bottom:24px;padding-left:2px;}
.nav-item{display:flex;align-items:center;gap:11px;padding:11px 14px;color:var(--muted);cursor:pointer;border-radius:9px;font-size:13px;font-weight:500;transition:.2s;margin-bottom:3px;border:1px solid transparent;user-select:none;text-decoration:none;}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,0.03);}
.nav-item.active{color:var(--green);background:rgba(0,255,136,0.07);border-color:rgba(0,255,136,0.18);}
.nav-sep{height:1px;background:var(--border2);margin:12px 0;}
.sidebar-footer{margin-top:auto;}
.admin-card{background:rgba(245,200,66,0.06);border:1px solid rgba(245,200,66,0.18);border-radius:12px;padding:14px;}
.admin-card p{font-family:var(--fm);font-size:9px;color:var(--gold);text-transform:uppercase;letter-spacing:1px;}
.admin-card h4{font-size:13px;margin-top:5px;}

/* MAIN */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;}
.topbar{padding:16px 30px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;align-items:center;background:rgba(3,11,20,0.8);backdrop-filter:blur(20px);}
.topbar h1{font-family:var(--fd);font-size:20px;font-weight:700;}
.tag{font-family:var(--fm);font-size:10px;padding:4px 10px;border-radius:6px;border:1px solid;}
.tag-live{color:var(--green);border-color:rgba(0,255,136,0.3);background:rgba(0,255,136,0.08);}
.tag-warn{color:var(--gold);border-color:rgba(245,200,66,0.3);background:rgba(245,200,66,0.08);}

.viewport{flex:1;overflow-y:auto;padding:28px 30px;scrollbar-width:thin;scrollbar-color:rgba(0,255,136,0.2) transparent;}
.tab-content{display:none;}
.tab-content.active{display:block;animation:fadeSlide .3s ease;}
@keyframes fadeSlide{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* STAT GRID */
.stat-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:24px;}
.stat-card{background:var(--surface);border:1px solid var(--border2);border-radius:14px;padding:18px;position:relative;overflow:hidden;transition:.2s;}
.stat-card:hover{border-color:rgba(0,255,136,0.2);}
.stat-label{font-family:var(--fm);font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
.stat-val{font-family:var(--fd);font-size:30px;font-weight:800;margin:7px 0 3px;}
.stat-sub{font-size:11px;color:var(--muted);}

/* PANELS */
.panel{background:var(--surface);border:1px solid var(--border2);border-radius:18px;padding:24px;margin-bottom:20px;}
.section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;}
.section-title{font-family:var(--fd);font-size:22px;font-weight:700;}
.section-sub{font-size:12px;color:var(--muted);margin-top:4px;}

/* FORMS */
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-family:var(--fm);font-size:10px;color:var(--muted);margin-bottom:7px;text-transform:uppercase;letter-spacing:0.5px;}
.form-group input,.form-group select,.form-group textarea{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border2);border-radius:9px;padding:11px 14px;color:var(--text);font-family:var(--fb);font-size:14px;transition:.2s;outline:none;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--green);background:rgba(0,255,136,0.04);}
.form-group select option{background:#0a1628;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:9px;font-weight:600;font-size:13px;cursor:pointer;border:none;transition:.2s;font-family:var(--fb);}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;}
.btn-primary:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(0,255,136,0.3);}
.btn-gold{background:var(--gold);color:#000;}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(245,200,66,0.3);}
.btn-danger{background:rgba(255,77,109,0.1);color:var(--red);border:1px solid rgba(255,77,109,0.25);}
.btn-danger:hover{background:var(--red);color:#fff;}
.btn-ghost{background:rgba(255,255,255,0.04);color:var(--text);border:1px solid var(--border2);}
.btn-full{width:100%;justify-content:center;padding:14px;font-size:14px;}

/* TABLES */
.data-table{width:100%;border-collapse:separate;border-spacing:0 5px;}
.data-table th{font-family:var(--fm);font-size:9px;color:var(--muted);padding:6px 14px;text-align:left;text-transform:uppercase;letter-spacing:1px;}
.data-table td{padding:14px;background:var(--surface2);border-top:1px solid var(--border2);border-bottom:1px solid var(--border2);font-size:13px;}
.data-table td:first-child{border-left:1px solid var(--border2);border-radius:9px 0 0 9px;padding-left:18px;}
.data-table td:last-child{border-right:1px solid var(--border2);border-radius:0 9px 9px 0;}
.data-table tr:hover td{background:rgba(0,255,136,0.02);}

/* BADGE */
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:700;font-family:var(--fm);}
.badge-green{background:rgba(0,255,136,0.1);color:var(--green);}
.badge-cyan{background:rgba(0,212,255,0.1);color:var(--cyan);}
.badge-gold{background:rgba(245,200,66,0.1);color:var(--gold);}
.badge-red{background:rgba(255,77,109,0.1);color:var(--red);}
.badge-purple{background:rgba(168,85,247,0.1);color:var(--purple);}

/* QUIZ ROW */
.quiz-row{display:flex;justify-content:space-between;align-items:center;padding:16px 18px;background:var(--surface2);border:1px solid var(--border2);border-radius:12px;margin-bottom:8px;transition:.2s;}
.quiz-row:hover{border-color:rgba(0,255,136,0.18);}

/* AI ENGINE CARDS */
.engine-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px;}
.engine-card{background:var(--surface2);border:2px solid var(--border2);border-radius:12px;padding:18px;cursor:pointer;transition:.2s;text-align:center;}
.engine-card:hover{border-color:rgba(0,255,136,0.3);}
.engine-card.selected{border-color:var(--green);background:rgba(0,255,136,0.05);}

/* TOPIC PILLS */
.topic-pills{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;}
.topic-pill{padding:7px 14px;border-radius:20px;border:1px solid var(--border2);background:rgba(255,255,255,0.03);font-size:12px;cursor:pointer;transition:.2s;font-family:var(--fm);}
.topic-pill:hover{border-color:var(--green);color:var(--green);background:rgba(0,255,136,0.05);}

/* MANUAL BUILDER */
.q-block{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:18px;margin-bottom:14px;position:relative;}
.q-num-label{font-family:var(--fm);font-size:10px;color:var(--green);margin-bottom:12px;text-transform:uppercase;letter-spacing:1px;}
.opts-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:12px;}
.correct-sel{display:flex;gap:7px;flex-wrap:wrap;}
.correct-btn{padding:7px 14px;border-radius:8px;border:1px solid var(--border2);background:transparent;color:var(--muted);cursor:pointer;font-size:11px;font-family:var(--fm);transition:.2s;}
.correct-btn.sel{background:rgba(0,255,136,0.1);border-color:var(--green);color:var(--green);}
.remove-q{position:absolute;top:14px;right:14px;background:none;border:none;color:var(--muted);cursor:pointer;}
.remove-q:hover{color:var(--red);}

/* Image upload */
.img-upload-zone{border:2px dashed var(--border);border-radius:9px;padding:20px;text-align:center;cursor:pointer;transition:.2s;}
.img-upload-zone:hover{border-color:var(--green);}
.img-preview{max-width:180px;max-height:120px;border-radius:8px;margin-top:10px;}

/* CHART */
.chart-wrap{background:var(--surface);border:1px solid var(--border2);border-radius:14px;padding:22px;}

/* ALERTS */
.alert{padding:12px 18px;border-radius:9px;font-size:13px;margin-bottom:18px;display:flex;gap:10px;align-items:center;}
.alert-success{background:rgba(0,255,136,0.07);border:1px solid rgba(0,255,136,0.2);color:var(--green);}
.alert-error{background:rgba(255,77,109,0.07);border:1px solid rgba(255,77,109,0.2);color:var(--red);}

/* AI LOADER */
#ai-loader{display:none;position:fixed;inset:0;background:rgba(3,11,20,0.95);z-index:9999;align-items:center;justify-content:center;flex-direction:column;gap:20px;}
.loader-ring{width:60px;height:60px;border:3px solid rgba(0,255,136,0.1);border-top-color:var(--green);border-radius:50%;animation:spin 1s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}

::-webkit-scrollbar{width:4px;}
::-webkit-scrollbar-thumb{background:rgba(0,255,136,0.2);border-radius:4px;}
a{text-decoration:none;color:inherit;}

.risk-indicator{display:flex;align-items:center;gap:6px;}
.risk-dot{width:8px;height:8px;border-radius:50%;}
</style>
</head>
<body>

<!-- AI LOADER -->
<div id="ai-loader">
    <div class="loader-ring"></div>
    <p style="font-family:var(--fm);font-size:12px;color:var(--green);">AI GENERATING CYBER CRIME QUIZ...</p>
    <p style="font-size:12px;color:var(--muted);">This may take 15–45 seconds</p>
</div>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="logo">
        <div class="logo-icon"><i data-lucide="shield-alert" size="18" color="#fff"></i></div>
        <div class="logo-text" style="color:var(--text);">CyberShield</div>
        <span class="logo-badge">ADMIN</span>
    </div>
    <div class="admin-label">Command Center</div>

    <nav>
        <div class="nav-item active" data-tab="overview" onclick="switchTab('overview')"><i data-lucide="layout-dashboard" size="15"></i> Overview</div>
        <div class="nav-item" data-tab="manage" onclick="switchTab('manage')"><i data-lucide="book-open" size="15"></i> Manage Quizzes</div>
        <div class="nav-item" data-tab="gen" onclick="switchTab('gen')"><i data-lucide="sparkles" size="15"></i> AI Generator</div>
        <div class="nav-item" data-tab="manual" onclick="switchTab('manual')"><i data-lucide="pencil-ruler" size="15"></i> Manual Builder</div>
        <div class="nav-item" data-tab="students" onclick="switchTab('students')"><i data-lucide="users" size="15"></i> Students</div>
        <div class="nav-item" data-tab="fraud" onclick="switchTab('fraud')"><i data-lucide="alert-triangle" size="15"></i> Fraud Logs <span class="badge badge-red" style="margin-left:auto;font-size:9px;"><?= $total_fraud ?></span></div>
        <div class="nav-sep"></div>
        <a href="logout.php" class="nav-item" style="color:var(--red);"><i data-lucide="log-out" size="15"></i> Sign Out</a>
    </nav>

    <div class="sidebar-footer">
        <div class="admin-card">
            <p>Authenticated As</p>
            <h4>System Administrator</h4>
        </div>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <div class="topbar">
        <h1 id="tab-title">Command Overview</h1>
        <div style="display:flex;gap:10px;align-items:center;">
            <span class="tag tag-live">● LIVE</span>
            <span class="tag tag-warn" id="clock">--:--:--</span>
        </div>
    </div>

    <div class="viewport">
        <?php if(isset($_GET['msg'])): ?>
        <div class="alert <?= $_GET['msg'] === 'ai_error' ? 'alert-error' : 'alert-success' ?>">
            <i data-lucide="<?= $_GET['msg'] === 'ai_error' ? 'x-circle' : 'check-circle' ?>" size="16"></i>
            <?= $_GET['msg'] === 'ai_error' ? 'AI generation failed. Check API key and try again.' : ($_GET['msg'] === 'deleted' ? 'Quiz deleted.' : 'Operation completed successfully!') ?>
        </div>
        <?php endif; ?>

        <!-- ── OVERVIEW ── -->
        <div id="overview" class="tab-content active">
            <div class="stat-grid">
                <div class="stat-card"><p class="stat-label">Quizzes</p><p class="stat-val" style="color:var(--cyan)"><?= $total_quizzes ?></p><p class="stat-sub">Published</p></div>
                <div class="stat-card"><p class="stat-label">Questions</p><p class="stat-val" style="color:var(--green)"><?= $total_qs ?></p><p class="stat-sub">In database</p></div>
                <div class="stat-card"><p class="stat-label">Students</p><p class="stat-val" style="color:var(--gold)"><?= $total_students ?></p><p class="stat-sub">Registered</p></div>
                <div class="stat-card"><p class="stat-label">Attempts</p><p class="stat-val"><?= $total_attempts ?></p><p class="stat-sub">All time</p></div>
                <div class="stat-card"><p class="stat-label">Avg Score</p><p class="stat-val" style="color:var(--green)"><?= $avg_score ?>%</p><p class="stat-sub">Platform wide</p></div>
                <div class="stat-card"><p class="stat-label">Violations</p><p class="stat-val" style="color:var(--red)"><?= $total_fraud ?></p><p class="stat-sub">Fraud alerts</p></div>
            </div>
            <div class="chart-wrap">
                <p style="font-family:var(--fm);font-size:10px;color:var(--muted);margin-bottom:14px;text-transform:uppercase;letter-spacing:1px;">7-Day Activity</p>
                <canvas id="actChart" height="90"></canvas>
            </div>
        </div>

        <!-- ── MANAGE QUIZZES ── -->
        <div id="manage" class="tab-content">
            <div class="section-header">
                <div><h2 class="section-title">Quiz Repository</h2><p class="section-sub"><?= $total_quizzes ?> quizzes published</p></div>
                <div style="display:flex;gap:10px;">
                    <button class="btn btn-ghost" onclick="switchTab('manual')"><i data-lucide="pencil-ruler" size="14"></i> Manual</button>
                    <button class="btn btn-primary" onclick="switchTab('gen')"><i data-lucide="sparkles" size="14"></i> AI Generate</button>
                </div>
            </div>
            <?php while ($quiz = mysqli_fetch_assoc($quizzes_res)):
                $diff = $quiz['difficulty'] ?? 'Intermediate';
                $dc = $diff === 'Expert' ? 'badge-red' : ($diff === 'Beginner' ? 'badge-green' : 'badge-cyan');
            ?>
            <div class="quiz-row">
                <div style="display:flex;align-items:center;gap:14px;">
                    <div style="width:40px;height:40px;background:rgba(0,255,136,0.07);border:1px solid rgba(0,255,136,0.15);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:20px;">🛡️</div>
                    <div>
                        <p style="font-weight:700;font-size:14px;"><?= htmlspecialchars($quiz['title']) ?></p>
                        <p style="color:var(--muted);font-size:11px;font-family:var(--fm);margin-top:3px;">
                            <?= $quiz['duration'] ?>min &nbsp;·&nbsp; <?= $quiz['q_cnt'] ?> Qs &nbsp;·&nbsp; <?= $quiz['attempt_cnt'] ?> attempts
                        </p>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <span class="badge <?= $dc ?>"><?= $diff ?></span>
                    <button class="btn btn-danger" style="padding:7px 13px;font-size:11px;" onclick="confirmDelete(<?= $quiz['id'] ?>)"><i data-lucide="trash-2" size="12"></i> Delete</button>
                </div>
            </div>
            <?php endwhile; ?>
            <?php if ($total_quizzes == 0): ?>
            <div style="text-align:center;padding:60px;color:var(--muted);"><p>No quizzes yet. Use AI Generator or Manual Builder!</p></div>
            <?php endif; ?>
        </div>

        <!-- ── AI GENERATOR ── -->
        <div id="gen" class="tab-content">
            <div class="section-header">
                <div><h2 class="section-title">AI Quiz Generator</h2><p class="section-sub">Auto-generate cyber crime awareness quizzes using AI</p></div>
            </div>

            <!-- CYBER CRIME TOPIC SUGGESTIONS -->
            <div class="panel">
                <p style="font-family:var(--fm);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;">⚡ Quick Topic Suggestions — Click to Use</p>
                <div class="topic-pills" id="topic-pills">
                    <?php
                    $cyber_topics = [
                        'Phishing & Email Scams','Ransomware Attacks','Social Engineering',
                        'Identity Theft & Data Breach','Password Security & Hygiene',
                        'Online Banking Safety','Wi-Fi Security & VPNs','Malware & Viruses',
                        'Cyber Bullying Awareness','Dark Web Threats','Mobile Device Security',
                        'Safe Online Shopping','Two-Factor Authentication','Privacy & Data Protection',
                        'QR Code Scams','OTP & SIM Swap Fraud'
                    ];
                    foreach ($cyber_topics as $t): ?>
                    <div class="topic-pill" onclick="setTopic(this)"><?= $t ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- AI ENGINE -->
            <div class="panel">
                <p style="font-family:var(--fm);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;">Select AI Engine</p>
                <div class="engine-grid">
                    <div class="engine-card selected" onclick="selectEngine('claude',this)">
                        <div style="font-size:28px;margin-bottom:8px;">🤖</div>
                        <p style="font-family:var(--fm);font-size:12px;font-weight:600;">Claude AI</p>
                        <p style="font-size:11px;color:var(--muted);">Best quality · Anthropic</p>
                    </div>
                    <div class="engine-card" onclick="selectEngine('gemini',this)">
                        <div style="font-size:28px;margin-bottom:8px;">💎</div>
                        <p style="font-family:var(--fm);font-size:12px;font-weight:600;">Gemini</p>
                        <p style="font-size:11px;color:var(--muted);">Google · Research focused</p>
                    </div>
                </div>

                <form method="POST" onsubmit="document.getElementById('ai-loader').style.display='flex'">
                    <input type="hidden" name="engine" id="engine-input" value="claude">
                    <div class="form-group">
                        <label>Quiz Topic / Domain</label>
                        <input type="text" name="topic" id="topic-input" placeholder="e.g. Phishing & Email Scams, Ransomware Attacks..." required>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px;">
                        <div class="form-group">
                            <label>Question Count</label>
                            <input type="number" name="q_count" value="10" min="3" max="30">
                        </div>
                        <div class="form-group">
                            <label>Duration (min)</label>
                            <input type="number" name="duration" value="15" min="5" max="120">
                        </div>
                        <div class="form-group">
                            <label>Difficulty</label>
                            <select name="difficulty">
                                <option>Beginner</option>
                                <option selected>Intermediate</option>
                                <option>Expert</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Language</label>
                            <select name="lang">
                                <option>English</option>
                                <option>Hindi</option>
                                <option>Marathi</option>
                                <option>Tamil</option>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="generate_ai_quiz" class="btn btn-gold btn-full">
                        <i data-lucide="sparkles" size="16"></i> GENERATE QUIZ WITH AI
                    </button>
                </form>
            </div>
        </div>

        <!-- ── MANUAL BUILDER ── -->
        <div id="manual" class="tab-content">
            <div class="section-header">
                <div><h2 class="section-title">Manual Quiz Builder</h2><p class="section-sub">Create custom questions with text and images</p></div>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="panel">
                    <p style="font-family:var(--fm);font-size:10px;color:var(--green);text-transform:uppercase;letter-spacing:1px;margin-bottom:18px;">Exam Configuration</p>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                        <div class="form-group">
                            <label>Exam Title</label>
                            <input type="text" name="m_title" placeholder="e.g. Phishing Awareness Test" required>
                        </div>
                        <div class="form-group">
                            <label>Topic / Category</label>
                            <input type="text" name="m_topic" placeholder="e.g. Phishing" required>
                        </div>
                        <div class="form-group">
                            <label>Duration (minutes)</label>
                            <input type="number" name="m_duration" value="20" min="5" max="180">
                        </div>
                        <div class="form-group">
                            <label>Difficulty Level</label>
                            <select name="m_difficulty">
                                <option>Beginner</option>
                                <option selected>Intermediate</option>
                                <option>Expert</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="manual-qs-root"></div>

                <button type="button" onclick="addQuestion()" class="btn btn-ghost btn-full" style="margin-bottom:16px;">
                    <i data-lucide="plus-circle" size="15"></i> Add Question
                </button>
                <button type="submit" name="save_manual_quiz" class="btn btn-primary btn-full">
                    <i data-lucide="upload-cloud" size="15"></i> PUBLISH QUIZ
                </button>
            </form>
        </div>

        <!-- ── STUDENTS ── -->
        <div id="students" class="tab-content">
            <div class="section-header">
                <div><h2 class="section-title">Student Analytics</h2><p class="section-sub"><?= $total_students ?> registered users</p></div>
            </div>
            <div class="panel" style="padding:0;overflow:hidden;">
                <table class="data-table">
                    <thead><tr><th style="padding-left:18px;">Student</th><th>Email</th><th>Attempts</th><th>Avg Score</th><th>Best</th><th>Last Active</th></tr></thead>
                    <tbody>
                    <?php while ($s = mysqli_fetch_assoc($students_data)):
                        $sa = $s['avg_score'] ?? 0;
                    ?>
                    <tr>
                        <td><b><?= htmlspecialchars($s['fullname']) ?></b></td>
                        <td style="color:var(--muted);font-size:12px;"><?= htmlspecialchars($s['email']) ?></td>
                        <td><span class="badge badge-cyan"><?= $s['attempts'] ?? 0 ?>×</span></td>
                        <td style="font-family:var(--fm);font-weight:700;color:<?= $sa >= 70 ? 'var(--green)' : ($sa >= 50 ? 'var(--gold)' : 'var(--red)') ?>"><?= $sa ?? '—' ?>%</td>
                        <td style="font-family:var(--fm);"><?= $s['best'] ?? '—' ?>%</td>
                        <td style="color:var(--muted);font-size:11px;font-family:var(--fm);"><?= $s['last_seen'] ? date('M d, H:i', strtotime($s['last_seen'])) : 'Never' ?></td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── FRAUD LOGS ── -->
        <div id="fraud" class="tab-content">
            <div class="section-header">
                <div><h2 class="section-title">Fraud & Violation Logs</h2><p class="section-sub"><?= $total_fraud ?> total violations detected</p></div>
            </div>
            <div class="panel" style="padding:0;overflow:hidden;">
                <table class="data-table">
                    <thead><tr><th style="padding-left:18px;">Student</th><th>Violation Type</th><th>Timestamp</th><th>Risk Level</th></tr></thead>
                    <tbody>
                    <?php while ($fl = mysqli_fetch_assoc($fraud_logs)):
                        $vtype = $fl['violation_type'];
                        $risk = 'LOW'; $rc = 'badge-green';
                        if (stripos($vtype,'tab') !== false || stripos($vtype,'fullscreen') !== false || stripos($vtype,'blur') !== false) { $risk = 'MEDIUM'; $rc = 'badge-gold'; }
                        if (stripos($vtype,'copy') !== false || stripos($vtype,'devtools') !== false || stripos($vtype,'right-click') !== false || stripos($vtype,'inspect') !== false) { $risk = 'HIGH'; $rc = 'badge-red'; }
                    ?>
                    <tr>
                        <td><b><?= htmlspecialchars($fl['fullname'] ?? 'Unknown') ?></b></td>
                        <td><span class="badge badge-red"><?= htmlspecialchars($vtype) ?></span></td>
                        <td style="color:var(--muted);font-family:var(--fm);font-size:11px;"><?= date('M d Y, H:i:s', strtotime($fl['timestamp'])) ?></td>
                        <td><span class="badge <?= $rc ?>"><?= $risk ?></span></td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
lucide.createIcons();

// Clock
setInterval(() => { document.getElementById('clock').textContent = new Date().toLocaleTimeString('en-IN'); }, 1000);

// Tab switcher
const tabTitles = {overview:'Command Overview',manage:'Manage Quizzes',gen:'AI Generator',manual:'Manual Builder',students:'Student Analytics',fraud:'Fraud Logs'};
function switchTab(id) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.nav-item[data-tab]').forEach(n => n.classList.remove('active'));
    const el = document.getElementById(id);
    if (el) el.classList.add('active');
    const nav = document.querySelector(`[data-tab="${id}"]`);
    if (nav) nav.classList.add('active');
    document.getElementById('tab-title').textContent = tabTitles[id] || id;
    localStorage.setItem('admin_tab', id);
}

// AI Engine selector
function selectEngine(val, card) {
    document.querySelectorAll('.engine-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');
    document.getElementById('engine-input').value = val;
}

// Topic pill
function setTopic(el) {
    document.getElementById('topic-input').value = el.textContent;
    document.querySelectorAll('.topic-pill').forEach(p => p.style.borderColor = '');
    el.style.borderColor = 'var(--green)';
    el.style.color = 'var(--green)';
}

// Manual builder
let qIndex = 0;
function addQuestion() {
    const root = document.getElementById('manual-qs-root');
    const qi = qIndex++;
    const div = document.createElement('div');
    div.className = 'q-block';
    div.id = 'qb-' + qi;
    div.innerHTML = `
    <p class="q-num-label">Question ${qi + 1}</p>
    <button type="button" class="remove-q" onclick="document.getElementById('qb-${qi}').remove()">
        <i data-lucide="x" size="14"></i></button>
    <div class="form-group">
        <label>Question Text</label>
        <textarea name="qs[${qi}][text]" rows="2" placeholder="Enter question..." required
            style="width:100%;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:11px 13px;color:#e8f0fe;font-size:14px;resize:vertical;outline:none;font-family:'DM Sans',sans-serif;"></textarea>
    </div>
    <div class="form-group">
        <label>Question Image (optional)</label>
        <div class="img-upload-zone" onclick="document.getElementById('qimg-${qi}').click()">
            <i data-lucide="image" size="18" style="color:var(--muted)"></i>
            <p style="font-size:11px;color:var(--muted);margin-top:6px;">Click to upload image (JPG, PNG, WebP — max 5MB)</p>
            <img id="qprev-${qi}" class="img-preview" style="display:none;">
        </div>
        <input type="file" id="qimg-${qi}" name="qs[${qi}][image]" accept="image/*" style="display:none"
            onchange="previewImg(this,'${qi}')">
    </div>
    <div class="form-group">
        <label>Answer Options</label>
        <div class="opts-grid">
            ${['A','B','C','D'].map((l,i) => `<input type="text" name="qs[${qi}][opts][${i}]" placeholder="Option ${l}" ${i<2?'required':''} style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:10px 13px;color:#e8f0fe;font-size:13px;outline:none;">`).join('')}
        </div>
    </div>
    <div class="form-group">
        <label>Correct Answer</label>
        <div class="correct-sel">
            ${['A','B','C','D'].map((l,i) => `<button type="button" class="correct-btn${i===0?' sel':''}" onclick="selectCorrect(${qi},${i},this)">${l} — Option ${i+1}</button>`).join('')}
        </div>
        <input type="hidden" name="qs[${qi}][correct]" id="corr-${qi}" value="0">
    </div>
    <div class="form-group">
        <label>Explanation (optional)</label>
        <input type="text" name="qs[${qi}][explanation]" placeholder="Why is this the correct answer?"
            style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:10px 13px;color:#e8f0fe;font-size:13px;outline:none;">
    </div>`;
    root.appendChild(div);
    lucide.createIcons();
}

function selectCorrect(qi, val, btn) {
    btn.closest('.correct-sel').querySelectorAll('.correct-btn').forEach(b => b.classList.remove('sel'));
    btn.classList.add('sel');
    document.getElementById('corr-' + qi).value = val;
}

function previewImg(input, qi) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.getElementById('qprev-' + qi);
            img.src = e.target.result; img.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Delete confirm (SweetAlert2)
function confirmDelete(id) {
    Swal.fire({
        title: 'Delete This Quiz?',
        text: 'All questions and student results for this quiz will be permanently removed.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: 'Delete', cancelButtonText: 'Cancel',
        confirmButtonColor: '#ff4d6d', background: '#0a1628', color: '#e8f0fe',
        backdrop: 'rgba(3,11,20,0.85)'
    }).then(r => { if (r.isConfirmed) window.location.href = 'admin.php?delete_quiz=' + id; });
}

// Chart
new Chart(document.getElementById('actChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{ label: 'Attempts', data: <?= json_encode($chart_data) ?>,
            backgroundColor: 'rgba(0,255,136,0.12)', borderColor: '#00ff88',
            borderWidth: 2, borderRadius: 8, borderSkipped: false }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: {
            y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#4a6070', font: { family: 'IBM Plex Mono', size: 10 } } },
            x: { grid: { display: false }, ticks: { color: '#4a6070', font: { family: 'IBM Plex Mono', size: 10 } } }
        }
    }
});

// Auto-dismiss alerts
setTimeout(() => { document.querySelectorAll('.alert').forEach(a => { a.style.opacity = '0'; setTimeout(() => a.remove(), 300); }); }, 5000);

// Init
window.onload = () => {
    addQuestion();
    const tab = new URLSearchParams(window.location.search).get('target') || localStorage.getItem('admin_tab') || 'overview';
    switchTab(tab);
};
</script>
</body>
</html>