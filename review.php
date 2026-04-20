<?php
session_start();
include("config/db.php");

if (!isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit(); }

$user_id  = (int)$_SESSION['user_id'];
$q_results = [];
$score = 0; $total = 0; $correct = 0;
$quiz_id = 0; $quiz_info = null; $is_fresh = false;

if (isset($_GET['result_id'])) {
    $result_id = (int)$_GET['result_id'];
    $sess_key  = 'review_data_' . $result_id;

    if (isset($_SESSION[$sess_key]) && $_SESSION[$sess_key]['expires'] > time()) {
        $rev = $_SESSION[$sess_key];
        unset($_SESSION[$sess_key]);
        $quiz_id = $rev['quiz_id'];
        $submitted = $rev['answers'];
        $score   = $rev['score'];
        $correct = $rev['correct'];
        $total   = $rev['total'];
        $is_fresh = true;

        // Build per-question breakdown
        $stmt = $conn->prepare("SELECT q.id as qid, q.question_text, q.explanation, q.image_url, o.id as oid, o.option_text, o.is_correct FROM questions q JOIN options o ON q.id = o.question_id WHERE q.quiz_id = ? ORDER BY q.id, o.id");
        $stmt->bind_param("i", $quiz_id); $stmt->execute();
        $rows = $stmt->get_result(); $stmt->close();

        while ($row = $rows->fetch_assoc()) {
            $qid = (int)$row['qid'];
            $oid = (int)$row['oid'];
            if (!isset($q_results[$qid])) {
                $q_results[$qid] = ['text' => $row['question_text'], 'explanation' => $row['explanation'] ?? '', 'image_url' => $row['image_url'] ?? null, 'user_correct' => false, 'correct_text' => '', 'user_text' => 'Not answered'];
            }
            if ($row['is_correct'] == 1) $q_results[$qid]['correct_text'] = $row['option_text'];
            if (isset($submitted[$qid]) && (int)$submitted[$qid] === $oid) {
                $q_results[$qid]['user_text'] = $row['option_text'];
                if ($row['is_correct'] == 1) $q_results[$qid]['user_correct'] = true;
            }
        }
        $qi = $conn->prepare("SELECT title, difficulty FROM quizzes WHERE id = ? LIMIT 1");
        $qi->bind_param("i", $quiz_id); $qi->execute();
        $quiz_info = $qi->get_result()->fetch_assoc(); $qi->close();

    } else {
        // Session expired — pull from DB
        $stmt = $conn->prepare("SELECT r.*, q.title, q.difficulty FROM user_results r JOIN quizzes q ON r.quiz_id = q.id WHERE r.id = ? LIMIT 1");
        $stmt->bind_param("i", $result_id); $stmt->execute();
        $db_r = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$db_r) { header("Location: dashboard.php"); exit(); }
        $score = $db_r['score']; $total = $db_r['total_questions']; $correct = $db_r['correct_answers'];
        $quiz_id = $db_r['quiz_id'];
        $quiz_info = ['title' => $db_r['title'], 'difficulty' => $db_r['difficulty'] ?? ''];
    }
} elseif (isset($_POST['quiz_id'])) {
    $quiz_id = (int)$_POST['quiz_id'];
    $stmt = $conn->prepare("SELECT * FROM user_results WHERE user_id = ? AND quiz_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ii", $user_id, $quiz_id); $stmt->execute();
    $db_r = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$db_r) { header("Location: dashboard.php"); exit(); }
    $score = $db_r['score']; $total = $db_r['total_questions']; $correct = $db_r['correct_answers'];
    $qi = $conn->prepare("SELECT title, difficulty FROM quizzes WHERE id = ? LIMIT 1");
    $qi->bind_param("i", $quiz_id); $qi->execute();
    $quiz_info = $qi->get_result()->fetch_assoc(); $qi->close();
} else {
    header("Location: dashboard.php"); exit();
}

$title = htmlspecialchars($quiz_info['title'] ?? 'Assessment');
$wrong = $total - $correct;
$passed = $score >= 50;
$grade = $score >= 90 ? 'A+' : ($score >= 80 ? 'A' : ($score >= 70 ? 'B' : ($score >= 60 ? 'C' : ($score >= 50 ? 'D' : 'F'))));
$grade_color = $score >= 70 ? '#00ff88' : ($score >= 50 ? '#f5c842' : '#ff4d6d');
$user_name = $_SESSION['fullname'] ?? 'Student';

// Cyber tips based on score
$cyber_tips_by_score = [
    'high'   => ["Excellent! You're a cyber awareness champion. Keep sharing this knowledge with friends and family.", "Your high score shows strong digital safety habits. Consider completing more Advanced quizzes."],
    'medium' => ["Good effort! Review the questions you got wrong — those are your learning opportunities.", "You're on the right track. Practice more quizzes to strengthen your cyber safety knowledge."],
    'low'    => ["Don't worry — every expert was once a beginner. Review the explanations carefully.", "Cyber crime is real and growing. Take time to learn from these questions — your digital safety matters."]
];
$tip_key = $score >= 75 ? 'high' : ($score >= 50 ? 'medium' : 'low');
$tip = $cyber_tips_by_score[$tip_key][array_rand($cyber_tips_by_score[$tip_key])];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CyberShield — Review: <?= $title ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
<style>
:root{--bg:#030b14;--surface:#0a1628;--surface2:#0f1f38;--border2:rgba(255,255,255,0.07);--green:#00ff88;--cyan:#00d4ff;--red:#ff4d6d;--gold:#f5c842;--text:#e8f0fe;--muted:#4a6070;--fd:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'IBM Plex Mono',monospace;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:var(--fb);display:flex;height:100vh;overflow:hidden;}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,136,0.012) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,0.012) 1px,transparent 1px);background-size:60px 60px;pointer-events:none;}

/* SIDEBAR */
.sidebar{width:300px;background:rgba(3,11,20,0.98);border-right:1px solid var(--border2);padding:28px 22px;display:flex;flex-direction:column;flex-shrink:0;overflow-y:auto;}
.logo{font-family:var(--fd);font-weight:800;font-size:17px;color:var(--green);margin-bottom:28px;display:flex;align-items:center;gap:8px;}

/* SCORE HERO */
.score-hero{background:var(--surface);border:1px solid var(--border2);border-radius:18px;padding:28px 20px;text-align:center;margin-bottom:18px;position:relative;overflow:hidden;}
.score-hero::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 50% 0%,<?= $grade_color ?>11,transparent 70%);pointer-events:none;}
.grade-ring{width:96px;height:96px;border-radius:50%;border:3px solid <?= $grade_color ?>;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-family:var(--fd);font-size:34px;font-weight:800;color:<?= $grade_color ?>;box-shadow:0 0 30px <?= $grade_color ?>30;}
.score-pct{font-family:var(--fm);font-size:26px;font-weight:700;color:<?= $grade_color ?>;}
.score-label{font-size:12px;color:var(--muted);margin-top:4px;}

/* STAT ROWS */
.stat-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border2);font-size:14px;}
.stat-row:last-child{border-bottom:none;}
.stat-row span:last-child{font-family:var(--fm);font-weight:700;}

.verdict{text-align:center;padding:12px;border-radius:10px;font-family:var(--fm);font-size:12px;font-weight:700;letter-spacing:1px;margin:16px 0;}

.btn-home{display:block;background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;padding:14px;border-radius:12px;text-align:center;font-weight:700;font-family:var(--fd);text-decoration:none;transition:.2s;margin-top:auto;}
.btn-home:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(0,255,136,0.3);}

.btn-retry{display:block;background:rgba(255,255,255,0.04);border:1px solid var(--border2);color:var(--text);padding:11px;border-radius:12px;text-align:center;font-weight:600;font-size:14px;text-decoration:none;transition:.2s;margin-bottom:10px;}
.btn-retry:hover{border-color:var(--green);}

/* Certificate button */
.btn-cert{display:flex;align-items:center;justify-content:center;gap:8px;background:rgba(245,200,66,0.08);border:1px solid rgba(245,200,66,0.25);color:var(--gold);padding:11px;border-radius:12px;text-align:center;font-weight:600;font-size:14px;cursor:pointer;transition:.2s;margin-bottom:10px;width:100%;}
.btn-cert:hover{background:rgba(245,200,66,0.14);}

/* MAIN REVIEW */
.viewport{flex:1;overflow-y:auto;padding:28px 36px;scrollbar-width:thin;scrollbar-color:rgba(0,255,136,0.2) transparent;}

/* TIP BANNER */
.tip-banner{background:linear-gradient(135deg,rgba(0,255,136,0.05),rgba(0,212,255,0.05));border:1px solid rgba(0,255,136,0.15);border-radius:14px;padding:16px 20px;margin-bottom:24px;display:flex;gap:12px;align-items:flex-start;}

/* Q REVIEW CARD */
.q-review{background:var(--surface);border:1px solid var(--border2);border-radius:16px;padding:24px;margin-bottom:16px;}
.q-status{display:inline-flex;align-items:center;gap:6px;font-family:var(--fm);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;padding:4px 10px;border-radius:20px;margin-bottom:14px;}
.q-status.ok{background:rgba(0,255,136,0.1);color:var(--green);}
.q-status.wrong{background:rgba(255,77,109,0.1);color:var(--red);}
.q-text{font-size:15px;font-weight:500;line-height:1.7;margin-bottom:16px;}
.q-img{margin-bottom:14px;border-radius:10px;overflow:hidden;border:1px solid var(--border2);}
.q-img img{width:100%;max-height:250px;object-fit:contain;background:#050d1a;display:block;}

.ans-row{padding:14px 16px;border-radius:10px;margin-bottom:8px;}
.ans-row.correct{background:rgba(0,255,136,0.07);border:1px solid rgba(0,255,136,0.2);}
.ans-row.wrong-sel{background:rgba(255,77,109,0.07);border:1px solid rgba(255,77,109,0.2);}
.ans-tag{font-family:var(--fm);font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:5px;}
.ans-text{font-size:14px;}

.explanation{margin-top:14px;padding:12px 16px;background:rgba(0,212,255,0.05);border-left:3px solid rgba(0,212,255,0.35);border-radius:0 9px 9px 0;font-size:13px;color:var(--muted);line-height:1.7;}
.explanation strong{color:var(--cyan);font-family:var(--fm);font-size:10px;display:block;margin-bottom:4px;}

/* Summary only */
.summary-card{background:var(--surface);border:1px solid var(--border2);border-radius:18px;padding:36px;text-align:center;max-width:480px;margin:60px auto 0;}
.notice-box{background:rgba(245,200,66,0.06);border:1px solid rgba(245,200,66,0.2);border-radius:12px;padding:16px;margin-top:24px;}

/* CERTIFICATE MODAL */
#certModal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.9);align-items:center;justify-content:center;}
#certModal.show{display:flex;}
.cert-box{background:linear-gradient(135deg,#0a1628,#0f1f38);border:2px solid var(--gold);border-radius:20px;padding:50px;text-align:center;max-width:600px;width:90%;position:relative;}
.cert-box::before{content:'';position:absolute;inset:8px;border:1px solid rgba(245,200,66,0.2);border-radius:14px;pointer-events:none;}
.cert-title{font-family:var(--fd);font-size:30px;font-weight:800;color:var(--gold);margin-bottom:8px;}
.cert-sub{color:var(--muted);font-size:14px;margin-bottom:28px;}
.cert-name{font-family:var(--fd);font-size:26px;font-weight:700;color:var(--text);margin-bottom:6px;}
.cert-score{font-family:var(--fm);font-size:18px;font-weight:700;margin-bottom:24px;}
.cert-close{position:absolute;top:16px;right:16px;background:none;border:none;color:var(--muted);cursor:pointer;}

::-webkit-scrollbar{width:4px;}
::-webkit-scrollbar-thumb{background:rgba(0,255,136,0.2);border-radius:4px;}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="logo"><i data-lucide="shield-check" size="20"></i> CyberShield</div>

    <div class="score-hero">
        <div class="grade-ring"><?= $grade ?></div>
        <div class="score-pct"><?= $score ?>%</div>
        <div class="score-label">Final Score</div>
    </div>

    <div style="background:var(--surface);border:1px solid var(--border2);border-radius:14px;padding:14px;margin-bottom:16px;">
        <div class="stat-row"><span>✅ Correct</span><span style="color:var(--green)"><?= $correct ?></span></div>
        <div class="stat-row"><span>❌ Wrong</span><span style="color:var(--red)"><?= $wrong ?></span></div>
        <div class="stat-row"><span>📝 Total Qs</span><span><?= $total ?></span></div>
        <div class="stat-row" style="border:none"><span>🏅 Grade</span><span style="color:<?= $grade_color ?>"><?= $grade ?></span></div>
    </div>

    <?php if ($passed): ?>
    <div class="verdict" style="background:rgba(0,255,136,0.07);color:var(--green);border:1px solid rgba(0,255,136,0.2);">✓ PASSED — CYBER AWARE</div>
    <?php else: ?>
    <div class="verdict" style="background:rgba(255,77,109,0.07);color:var(--red);border:1px solid rgba(255,77,109,0.2);">✗ RETRY RECOMMENDED</div>
    <?php endif; ?>

    <?php if ($passed): ?>
    <button class="btn-cert" onclick="showCertificate()"><i data-lucide="award" size="16"></i> View Certificate</button>
    <?php endif; ?>
    <a href="attempt.php?id=<?= $quiz_id ?>" class="btn-retry"><i data-lucide="refresh-cw" size="14" style="vertical-align:middle;margin-right:4px;"></i> Retake Quiz</a>
    <a href="dashboard.php" class="btn-home">← Back to Dashboard</a>
</aside>

<!-- MAIN -->
<main class="viewport">
    <?php if ($is_fresh && !empty($q_results)): ?>
    <!-- FULL DETAILED REVIEW -->
    <header style="margin-bottom:24px;">
        <h1 style="font-family:var(--fd);font-size:22px;font-weight:700;"><?= $title ?> — Full Review</h1>
        <p style="color:var(--muted);font-size:13px;margin-top:5px;"><?= $correct ?> correct out of <?= $total ?> questions · <?= $score ?>% score</p>
    </header>

    <div class="tip-banner">
        <i data-lucide="<?= $passed ? 'check-circle' : 'info' ?>" size="18" style="color:var(--green);flex-shrink:0;margin-top:2px;"></i>
        <div>
            <div style="font-family:var(--fm);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Cyber Safety Insight</div>
            <p style="font-size:13px;line-height:1.6;"><?= htmlspecialchars($tip) ?></p>
        </div>
    </div>

    <?php $n = 1; foreach ($q_results as $data): $ok = $data['user_correct']; ?>
    <div class="q-review">
        <span class="q-status <?= $ok ? 'ok' : 'wrong' ?>">
            <i data-lucide="<?= $ok ? 'check-circle' : 'x-circle' ?>" size="12"></i>
            Q<?= $n++ ?> — <?= $ok ? 'Correct' : 'Incorrect' ?>
        </span>
        <p class="q-text"><?= htmlspecialchars($data['text']) ?></p>
        <?php if (!empty($data['image_url'])): ?>
        <div class="q-img"><img src="<?= htmlspecialchars($data['image_url']) ?>" alt="Question image" loading="lazy"></div>
        <?php endif; ?>
        <div class="ans-row correct">
            <div class="ans-tag" style="color:var(--green);">✓ Correct Answer</div>
            <div class="ans-text"><?= htmlspecialchars($data['correct_text']) ?></div>
        </div>
        <?php if (!$ok): ?>
        <div class="ans-row wrong-sel">
            <div class="ans-tag" style="color:var(--red);">✗ Your Answer</div>
            <div class="ans-text"><?= htmlspecialchars($data['user_text']) ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($data['explanation'])): ?>
        <div class="explanation">
            <strong>EXPLANATION</strong>
            <?= htmlspecialchars($data['explanation']) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- SUMMARY ONLY -->
    <div class="summary-card">
        <i data-lucide="bar-chart-2" size="48" style="color:var(--cyan);margin-bottom:18px;display:block;"></i>
        <h2 style="font-family:var(--fd);font-size:22px;margin-bottom:8px;"><?= $title ?></h2>
        <p style="color:var(--muted);font-size:14px;margin-bottom:24px;">Your performance summary for this quiz attempt.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:20px;">
            <div style="background:var(--surface2);border-radius:12px;padding:18px;">
                <p style="font-family:var(--fm);font-size:10px;color:var(--muted);margin-bottom:6px;">SCORE</p>
                <p style="font-family:var(--fd);font-size:26px;font-weight:800;color:<?= $grade_color ?>;"><?= $score ?>%</p>
            </div>
            <div style="background:var(--surface2);border-radius:12px;padding:18px;">
                <p style="font-family:var(--fm);font-size:10px;color:var(--muted);margin-bottom:6px;">CORRECT</p>
                <p style="font-family:var(--fd);font-size:26px;font-weight:800;color:var(--green);"><?= $correct ?></p>
            </div>
            <div style="background:var(--surface2);border-radius:12px;padding:18px;">
                <p style="font-family:var(--fm);font-size:10px;color:var(--muted);margin-bottom:6px;">TOTAL</p>
                <p style="font-family:var(--fd);font-size:26px;font-weight:800;"><?= $total ?></p>
            </div>
        </div>
        <div class="notice-box">
            <i data-lucide="info" size="18" style="color:var(--gold);"></i>
            <p style="font-size:13px;color:var(--gold);margin-top:6px;">Detailed per-question review is only available right after submitting. Retake the quiz for a full breakdown.</p>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- CERTIFICATE MODAL -->
<div id="certModal">
    <div class="cert-box">
        <button class="cert-close" onclick="document.getElementById('certModal').classList.remove('show')">
            <i data-lucide="x" size="20"></i>
        </button>
        <div style="font-size:40px;margin-bottom:12px;">🏅</div>
        <div class="cert-title">Certificate of Achievement</div>
        <div class="cert-sub">CyberShield Awareness Platform</div>
        <p style="color:var(--muted);font-size:13px;margin-bottom:12px;">This certifies that</p>
        <div class="cert-name"><?= htmlspecialchars($user_name) ?></div>
        <p style="color:var(--muted);font-size:13px;margin-bottom:12px;">has successfully completed</p>
        <p style="font-family:var(--fd);font-size:18px;font-weight:700;color:var(--cyan);margin-bottom:8px;"><?= $title ?></p>
        <div class="cert-score" style="color:<?= $grade_color ?>;">Score: <?= $score ?>% &nbsp;·&nbsp; Grade: <?= $grade ?></div>
        <p style="font-family:var(--fm);font-size:11px;color:var(--muted);">Date: <?= date('F d, Y') ?> &nbsp;·&nbsp; Stop Cyber Crime · Stay Aware</p>
    </div>
</div>

<script>
lucide.createIcons();

function showCertificate() {
    document.getElementById('certModal').classList.add('show');
    confetti({ particleCount: 120, spread: 70, origin: { y: 0.5 }, colors: ['#00ff88','#f5c842','#00d4ff'] });
}
document.getElementById('certModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('show');
});

// Auto-confetti on pass (fresh review)
<?php if ($is_fresh && $passed): ?>
setTimeout(() => {
    confetti({ particleCount: 80, spread: 60, origin: { y: 0.4 }, colors: ['#00ff88','#f5c842','#00d4ff'] });
}, 600);
<?php endif; ?>
</script>
</body>
</html>