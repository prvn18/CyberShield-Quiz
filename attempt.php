<?php
session_start();
include("config/db.php");

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) { header("Location: dashboard.php"); exit(); }
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') { header("Location: admin.php"); exit(); }

$quiz_id = (int)$_GET['id'];
$user_id = (int)$_SESSION['user_id'];

$stmt = $conn->prepare("SELECT * FROM quizzes WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $quiz_id); $stmt->execute();
$quiz = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$quiz) { header("Location: dashboard.php"); exit(); }

// Server-side timer (never resets on refresh)
$sess_key = 'end_time_' . $quiz_id;
if (!isset($_SESSION[$sess_key])) {
    $_SESSION[$sess_key] = time() + ($quiz['duration'] * 60);
}
$remaining = $_SESSION[$sess_key] - time();
if ($remaining <= 0) {
    unset($_SESSION[$sess_key]);
    header("Location: submit.php?auto=1&quiz_id=$quiz_id"); exit();
}

// Question count
$q_count_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM questions WHERE quiz_id = ?");
$q_count_stmt->bind_param("i", $quiz_id); $q_count_stmt->execute();
$q_count = $q_count_stmt->get_result()->fetch_assoc()['cnt']; $q_count_stmt->close();

// Questions with options
$q_stmt = $conn->prepare("SELECT * FROM questions WHERE quiz_id = ? ORDER BY id ASC");
$q_stmt->bind_param("i", $quiz_id); $q_stmt->execute();
$q_res = $q_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CyberShield — Active Exam: <?= htmlspecialchars($quiz['title']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root{--bg:#030b14;--surface:#0a1628;--surface2:#0f1f38;--border:rgba(0,255,136,0.12);--border2:rgba(255,255,255,0.07);--green:#00ff88;--cyan:#00d4ff;--red:#ff4d6d;--gold:#f5c842;--text:#e8f0fe;--muted:#4a6070;--fd:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'IBM Plex Mono',monospace;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:var(--fb);min-height:100vh;}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,136,0.012) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,0.012) 1px,transparent 1px);background-size:60px 60px;pointer-events:none;}

/* TOPBAR */
.exam-bar{position:sticky;top:0;z-index:999;background:rgba(3,11,20,0.95);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:0 28px;height:64px;display:flex;align-items:center;justify-content:space-between;gap:20px;}
.exam-title{font-family:var(--fd);font-size:15px;font-weight:700;max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.exam-meta{font-size:11px;color:var(--muted);font-family:var(--fm);margin-top:2px;}
.secure-badge{display:flex;align-items:center;gap:5px;background:rgba(0,255,136,0.07);border:1px solid rgba(0,255,136,0.2);border-radius:20px;padding:4px 10px;font-family:var(--fm);font-size:10px;color:var(--green);}

/* PROGRESS */
.prog-wrap{flex:1;margin:0 20px;}
.prog-track{height:5px;background:rgba(255,255,255,0.06);border-radius:5px;overflow:hidden;}
.prog-fill{height:100%;background:linear-gradient(90deg,var(--green),var(--cyan));border-radius:5px;transition:width .5s ease;}
.prog-label{font-family:var(--fm);font-size:10px;color:var(--muted);margin-top:5px;}

/* TIMER */
#timer{font-family:var(--fm);font-size:26px;font-weight:700;color:var(--gold);min-width:80px;text-align:right;transition:color .3s;}
#timer.urgent{color:var(--red);animation:pulse .6s ease-in-out infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* VIOLATION BANNER */
#violation-banner{display:none;position:fixed;top:64px;left:0;right:0;z-index:998;background:rgba(255,77,109,0.1);border-bottom:2px solid rgba(255,77,109,0.4);padding:10px 24px;text-align:center;font-family:var(--fm);font-size:12px;color:var(--red);}

/* FULLSCREEN OVERLAY */
#fs-overlay{display:none;position:fixed;inset:0;z-index:9999;background:rgba(3,11,20,0.97);align-items:center;justify-content:center;flex-direction:column;gap:20px;text-align:center;}
#fs-overlay.show{display:flex;}
#fs-overlay h2{font-family:var(--fd);font-size:26px;}
#fs-overlay p{color:var(--muted);max-width:380px;font-size:14px;line-height:1.7;}
.btn-fs{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;border:none;padding:14px 32px;border-radius:12px;font-weight:700;cursor:pointer;font-size:15px;font-family:var(--fd);}

/* VIOLATION COUNT OVERLAY */
#viol-overlay{display:none;position:fixed;top:70px;right:20px;z-index:997;background:rgba(255,77,109,0.1);border:1px solid rgba(255,77,109,0.3);border-radius:10px;padding:8px 14px;font-family:var(--fm);font-size:11px;color:var(--red);}

/* EXAM BODY */
.exam-wrap{max-width:800px;margin:0 auto;padding:28px 20px 100px;}

/* QUESTION CARDS */
.q-card{background:var(--surface);border:1px solid var(--border2);border-radius:18px;padding:28px;margin-bottom:20px;transition:border-color .3s;scroll-margin-top:75px;}
.q-card.answered{border-color:rgba(0,255,136,0.3);}
.q-card.answered .q-num-badge{background:var(--green);color:#000;}
.q-header{display:flex;gap:14px;align-items:flex-start;margin-bottom:18px;}
.q-num-badge{width:34px;height:34px;flex-shrink:0;border-radius:9px;background:rgba(0,255,136,0.08);border:1px solid rgba(0,255,136,0.2);display:flex;align-items:center;justify-content:center;font-family:var(--fm);font-size:12px;color:var(--green);font-weight:700;transition:.3s;}
.q-text{font-size:16px;line-height:1.7;font-weight:500;padding-top:4px;}

/* QUESTION IMAGE */
.q-img-wrap{margin-bottom:18px;border-radius:12px;overflow:hidden;border:1px solid var(--border2);cursor:zoom-in;}
.q-img-wrap img{width:100%;max-height:300px;object-fit:contain;background:#050d1a;display:block;}

/* OPTIONS */
.opts-list{display:flex;flex-direction:column;gap:9px;}
.opt-label{display:flex;align-items:center;gap:14px;padding:15px 18px;border-radius:12px;background:rgba(255,255,255,0.03);border:1px solid var(--border2);cursor:pointer;transition:.2s;user-select:none;}
.opt-label:hover{background:rgba(0,255,136,0.05);border-color:rgba(0,255,136,0.25);}
.opt-label input[type="radio"]{display:none;}
.opt-label:has(input:checked){background:rgba(0,255,136,0.08);border-color:rgba(0,255,136,0.45);}
.opt-letter{width:28px;height:28px;border-radius:7px;flex-shrink:0;background:rgba(255,255,255,0.05);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;font-family:var(--fm);font-size:11px;color:var(--muted);font-weight:600;transition:.2s;}
.opt-label:has(input:checked) .opt-letter{background:var(--green);border-color:var(--green);color:#000;}
.opt-text{font-size:14px;line-height:1.5;}

/* SUBMIT BAR */
.submit-bar{position:fixed;bottom:0;left:0;right:0;z-index:990;background:rgba(3,11,20,0.96);backdrop-filter:blur(20px);border-top:1px solid var(--border2);padding:14px 28px;display:flex;justify-content:space-between;align-items:center;}
.submit-stats{font-family:var(--fm);font-size:12px;color:var(--muted);}
.submit-stats strong{color:var(--green);}
.btn-submit{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;border:none;padding:13px 36px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;font-family:var(--fd);transition:.2s;}
.btn-submit:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(0,255,136,0.35);}

/* QUESTION NAV DOTS */
.q-nav{display:flex;flex-wrap:wrap;gap:5px;padding:14px 28px;background:rgba(3,11,20,0.6);border-bottom:1px solid var(--border2);}
.q-dot{width:26px;height:26px;border-radius:7px;background:rgba(255,255,255,0.05);border:1px solid var(--border2);display:flex;align-items:center;justify-content:center;font-family:var(--fm);font-size:10px;cursor:pointer;transition:.2s;color:var(--muted);}
.q-dot:hover{border-color:var(--green);}
.q-dot.done{background:rgba(0,255,136,0.12);border-color:rgba(0,255,136,0.35);color:var(--green);}

/* Image zoom modal */
#imgModal{display:none;position:fixed;inset:0;z-index:9990;background:rgba(0,0,0,0.92);align-items:center;justify-content:center;cursor:zoom-out;}
#imgModal.show{display:flex;}
#imgModal img{max-width:90vw;max-height:90vh;border-radius:12px;box-shadow:0 0 60px rgba(0,0,0,0.8);}
</style>
</head>
<body>

<!-- FULLSCREEN OVERLAY -->
<div id="fs-overlay">
    <i data-lucide="maximize-2" size="52" style="color:var(--gold)"></i>
    <h2>Enter Fullscreen Mode</h2>
    <p>This exam requires fullscreen for integrity monitoring. Exit will be logged as a violation.</p>
    <button class="btn-fs" onclick="enterFS()">Enter Fullscreen & Start Exam</button>
</div>

<!-- VIOLATION BANNER -->
<div id="violation-banner">⚠ PROCTORING ALERT — Violation detected and logged. Multiple violations will auto-submit your exam.</div>

<!-- VIOLATION COUNT -->
<div id="viol-overlay">🚨 Violations: <span id="viol-count">0</span>/3</div>

<!-- TOP BAR -->
<header class="exam-bar">
    <div style="min-width:0;">
        <p class="exam-title"><?= htmlspecialchars($quiz['title']) ?></p>
        <p class="exam-meta">🛡️ CyberShield Proctored · <?= $quiz['duration'] ?>min · <?= $q_count ?> questions</p>
    </div>
    <div class="prog-wrap">
        <div class="prog-track"><div class="prog-fill" id="prog-fill" style="width:0%"></div></div>
        <p class="prog-label" id="prog-label">0 / <?= $q_count ?> answered</p>
    </div>
    <div style="display:flex;align-items:center;gap:12px;flex-shrink:0;">
        <div class="secure-badge"><span>●</span> MONITORED</div>
        <div id="timer">--:--</div>
    </div>
</header>

<!-- QUESTION NAV DOTS -->
<div class="q-nav" id="q-nav">
<?php
$tmp_stmt = $conn->prepare("SELECT id FROM questions WHERE quiz_id = ? ORDER BY id ASC");
$tmp_stmt->bind_param("i", $quiz_id); $tmp_stmt->execute();
$tmp_res = $tmp_stmt->get_result();
$dot_count = 1;
while ($td = $tmp_res->fetch_assoc()):
?>
<div class="q-dot" id="dot-<?= $td['id'] ?>" onclick="scrollToQ(<?= $td['id'] ?>)"><?= $dot_count++ ?></div>
<?php endwhile; $tmp_stmt->close(); ?>
</div>

<!-- EXAM FORM -->
<div class="exam-wrap">
    <form action="submit.php" method="POST" id="quizForm">
        <input type="hidden" name="quiz_id" value="<?= $quiz_id ?>">
        <?php
        $n = 1; $letters = ['A','B','C','D','E'];
        while ($q = $q_res->fetch_assoc()):
            $qid = $q['id'];
            $opt_stmt = $conn->prepare("SELECT * FROM options WHERE question_id = ? ORDER BY id ASC");
            $opt_stmt->bind_param("i", $qid); $opt_stmt->execute();
            $opts = $opt_stmt->get_result();
        ?>
        <div class="q-card" id="qcard-<?= $qid ?>">
            <div class="q-header">
                <div class="q-num-badge" id="qbadge-<?= $qid ?>"><?= $n ?></div>
                <p class="q-text"><?= htmlspecialchars($q['question_text']) ?></p>
            </div>
            <?php if (!empty($q['image_url'])): ?>
            <div class="q-img-wrap" onclick="zoomImg(this.querySelector('img').src)">
                <img src="<?= htmlspecialchars($q['image_url']) ?>" alt="Question image" loading="lazy">
            </div>
            <p style="font-family:var(--fm);font-size:10px;color:var(--muted);margin:-10px 0 14px;text-align:right;">Click image to zoom</p>
            <?php endif; ?>
            <div class="opts-list">
                <?php $oi = 0; while ($opt = $opts->fetch_assoc()):
                    $letter = $letters[$oi] ?? chr(65+$oi);
                ?>
                <label class="opt-label" onchange="markAnswered(<?= $qid ?>)">
                    <input type="radio" name="answer[<?= $qid ?>]" value="<?= $opt['id'] ?>">
                    <span class="opt-letter"><?= $letter ?></span>
                    <span class="opt-text"><?= htmlspecialchars($opt['option_text']) ?></span>
                </label>
                <?php $oi++; endwhile; $opt_stmt->close(); ?>
            </div>
        </div>
        <?php $n++; endwhile; $q_stmt->close(); ?>
    </form>
</div>

<!-- SUBMIT BAR -->
<div class="submit-bar">
    <div class="submit-stats"><strong id="ans-count">0</strong> of <?= $q_count ?> answered</div>
    <button type="button" class="btn-submit" onclick="confirmSubmit()">Submit Exam →</button>
</div>

<!-- IMAGE ZOOM MODAL -->
<div id="imgModal" onclick="this.classList.remove('show')">
    <img id="imgModalSrc" src="" alt="Zoom">
</div>

<script>
lucide.createIcons();

// ── TIMER ──
let timeLeft = <?= $remaining ?>;
const timerEl = document.getElementById('timer');
function pad(n) { return String(n).padStart(2,'0'); }
const countdown = setInterval(() => {
    if (timeLeft <= 0) {
        clearInterval(countdown);
        window.onbeforeunload = null;
        document.getElementById('quizForm').submit();
        return;
    }
    const m = Math.floor(timeLeft / 60), s = timeLeft % 60;
    timerEl.textContent = `${pad(m)}:${pad(s)}`;
    if (timeLeft <= 120) timerEl.classList.add('urgent');
    timeLeft--;
}, 1000);

// ── PROGRESS ──
const answered = new Set();
const total = <?= $q_count ?>;
function markAnswered(qid) {
    answered.add(qid);
    document.getElementById('qcard-' + qid)?.classList.add('answered');
    document.getElementById('qbadge-' + qid)?.classList.add('answered');
    document.getElementById('dot-' + qid)?.classList.add('done');
    const pct = Math.round(answered.size / total * 100);
    document.getElementById('prog-fill').style.width = pct + '%';
    document.getElementById('prog-label').textContent = `${answered.size} / ${total} answered`;
    document.getElementById('ans-count').textContent = answered.size;
}

// ── SUBMIT ──
function confirmSubmit() {
    const unanswered = total - answered.size;
    if (unanswered > 0 && !confirm(`${unanswered} unanswered question(s). Submit anyway?`)) return;
    window.onbeforeunload = null;
    document.getElementById('quizForm').submit();
}
window.onbeforeunload = () => 'Leave the exam? Your progress will be lost!';
document.getElementById('quizForm').onsubmit = () => { window.onbeforeunload = null; };

// ── PROCTORING ──
let violCount = 0;
const banner = document.getElementById('violation-banner');
const vOv = document.getElementById('viol-overlay');
const vCount = document.getElementById('viol-count');

function logViolation(type) {
    violCount++;
    vCount.textContent = violCount;
    vOv.style.display = 'block';
    banner.style.display = 'block';
    setTimeout(() => banner.style.display = 'none', 6000);
    fetch('log_violation.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'type=' + encodeURIComponent(type)
    });
    if (violCount >= 3) {
        alert('⛔ Maximum violations (3) reached. Exam is being submitted automatically.');
        window.onbeforeunload = null;
        document.getElementById('quizForm').submit();
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) logViolation('Tab switch / window hidden');
});
window.addEventListener('blur', () => {
    if (document.fullscreenElement) logViolation('Window focus lost');
});
document.addEventListener('keydown', e => {
    if (e.key === 'F12' || (e.ctrlKey && e.shiftKey && ['I','J','C','K'].includes(e.key))) {
        e.preventDefault(); logViolation('DevTools attempt (' + e.key + ')');
    }
    if (e.ctrlKey && ['c','u','a','s','p'].includes(e.key.toLowerCase())) {
        e.preventDefault(); logViolation('Keyboard shortcut: Ctrl+' + e.key.toUpperCase());
    }
    if (['PrintScreen'].includes(e.key)) {
        e.preventDefault(); logViolation('Screenshot attempt');
    }
});
document.addEventListener('contextmenu', e => {
    e.preventDefault(); logViolation('Right-click attempt');
});
document.addEventListener('copy', e => {
    e.preventDefault(); logViolation('Copy attempt');
});

// ── FULLSCREEN ──
function enterFS() {
    const el = document.documentElement;
    (el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen).call(el);
    document.getElementById('fs-overlay').classList.remove('show');
}
document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement) {
        logViolation('Fullscreen exited');
        document.getElementById('fs-overlay').classList.add('show');
    }
});
window.addEventListener('load', () => {
    setTimeout(() => document.getElementById('fs-overlay').classList.add('show'), 500);
});

// ── SCROLL TO QUESTION ──
function scrollToQ(qid) {
    document.getElementById('qcard-' + qid)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── IMAGE ZOOM ──
function zoomImg(src) {
    document.getElementById('imgModalSrc').src = src;
    document.getElementById('imgModal').classList.add('show');
}
</script>
</body>
</html>