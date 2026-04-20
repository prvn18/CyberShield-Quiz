<?php
session_start();
include("config/db.php");

// ── STRICT USER-ONLY ACCESS ──
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') { header("Location: admin.php"); exit(); }

$user_id = (int)$_SESSION['user_id'];
$db_uid  = $user_id;

// ── DELETE MY HISTORY ──
if (isset($_POST['delete_history']) && isset($_POST['csrf']) && $_POST['csrf'] === $_SESSION['csrf_token']) {
    $stmt = $conn->prepare("DELETE FROM user_results WHERE user_id = ?");
    $stmt->bind_param("i", $db_uid); $stmt->execute(); $stmt->close();
    header("Location: dashboard.php?msg=purged"); exit();
}

$display_name = $_SESSION['fullname'] ?? 'Cyber Scholar';
$first_name   = explode(' ', $display_name)[0];
$user_email   = $_SESSION['email'] ?? '';

// ── STATS ──
$stmt = $conn->prepare("SELECT COUNT(*) as cnt, AVG(score) as avg, MAX(score) as best FROM user_results WHERE user_id = ?");
$stmt->bind_param("i", $db_uid); $stmt->execute();
$stats = $stmt->get_result()->fetch_assoc(); $stmt->close();
$total_attempts = (int)$stats['cnt'];
$avg_score      = round($stats['avg'] ?? 0, 1);
$best_score     = round($stats['best'] ?? 0, 1);

// ── RECENT RESULTS ──
$stmt = $conn->prepare("SELECT r.*, q.title, q.difficulty FROM user_results r JOIN quizzes q ON r.quiz_id = q.id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 20");
$stmt->bind_param("i", $db_uid); $stmt->execute();
$results_data = $stmt->get_result(); $stmt->close();
$results_array = [];
while ($r = $results_data->fetch_assoc()) $results_array[] = $r;

// ── CHART DATA ──
$stmt = $conn->prepare("SELECT score, created_at FROM user_results WHERE user_id = ? ORDER BY id DESC LIMIT 8");
$stmt->bind_param("i", $db_uid); $stmt->execute();
$chart_res = $stmt->get_result(); $stmt->close();
$chart_rows = [];
while ($row = $chart_res->fetch_assoc()) $chart_rows[] = $row;
$chart_rows = array_reverse($chart_rows);
$chart_labels = array_map(fn($r) => date('M d', strtotime($r['created_at'])), $chart_rows);
$chart_scores = array_column($chart_rows, 'score');

// ── QUIZZES ──
$quizzes_res = mysqli_query($conn, "SELECT q.*, (SELECT COUNT(*) FROM questions WHERE quiz_id = q.id) as q_count FROM quizzes q ORDER BY q.id DESC");

// ── LEADERBOARD ──
$leaderboard = mysqli_query($conn, "
    SELECT u.fullname, u.id as uid, ROUND(AVG(r.score),1) as avg_score, COUNT(r.id) as attempts, MAX(r.score) as best
    FROM user_results r JOIN users u ON r.user_id = u.id
    GROUP BY r.user_id ORDER BY avg_score DESC, attempts DESC LIMIT 10
");
$leaderboard_rows = [];
$my_rank = null; $rank = 1;
while ($lb = $leaderboard->fetch_assoc()) {
    if ($lb['uid'] == $db_uid) $my_rank = $rank;
    $leaderboard_rows[] = $lb;
    $rank++;
}

// ── BADGES (computed from performance) ──
function getBadges($attempts, $avg, $best) {
    $badges = [];
    if ($attempts >= 1)  $badges[] = ['🛡️', 'First Shield', 'Completed your first quiz'];
    if ($attempts >= 5)  $badges[] = ['🔥', 'On Fire', 'Completed 5+ quizzes'];
    if ($attempts >= 10) $badges[] = ['⚡', 'Power User', 'Completed 10+ quizzes'];
    if ($best >= 100)    $badges[] = ['💯', 'Perfect Score', 'Achieved 100% on a quiz'];
    if ($avg >= 80)      $badges[] = ['🌟', 'High Achiever', 'Average score above 80%'];
    if ($avg >= 50 && $avg < 80) $badges[] = ['📈', 'Improving', 'Consistent performer'];
    if ($attempts >= 3 && $avg >= 60) $badges[] = ['🔒', 'Cyber Guardian', 'Strong awareness level'];
    return $badges;
}
$badges = getBadges($total_attempts, $avg_score, $best_score);
$awareness_level = $avg_score >= 80 ? 'Expert' : ($avg_score >= 60 ? 'Intermediate' : ($avg_score >= 40 ? 'Beginner' : 'Rookie'));
$awareness_color = $avg_score >= 80 ? '#00ff88' : ($avg_score >= 60 ? '#00d4ff' : ($avg_score >= 40 ? '#f5c842' : '#ff4d6d'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CyberShield — My Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--bg:#030b14;--surface:#0a1628;--surface2:#0f1f38;--border:rgba(0,255,136,0.12);--border2:rgba(255,255,255,0.07);--green:#00ff88;--cyan:#00d4ff;--red:#ff4d6d;--gold:#f5c842;--purple:#a855f7;--text:#e8f0fe;--muted:#4a6070;--fd:'Syne',sans-serif;--fb:'DM Sans',sans-serif;--fm:'IBM Plex Mono',monospace;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:var(--fb);display:flex;height:100vh;overflow:hidden;}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,136,0.015) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,0.015) 1px,transparent 1px);background-size:60px 60px;pointer-events:none;}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 60% at 0% 0%,rgba(0,212,255,0.04),transparent),radial-gradient(ellipse 50% 50% at 100% 100%,rgba(168,85,247,0.03),transparent);pointer-events:none;}

/* SIDEBAR */
.sidebar{width:270px;background:rgba(3,11,20,0.98);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:24px 16px;flex-shrink:0;position:relative;z-index:2;}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:32px;text-decoration:none;}
.logo-icon{width:36px;height:36px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:10px;display:flex;align-items:center;justify-content:center;box-shadow:0 0 15px rgba(0,255,136,0.2);}
.logo-text{font-family:var(--fd);font-size:18px;font-weight:800;color:var(--text);}
.logo-text span{color:var(--green);}

nav{flex:1;}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 14px;color:var(--muted);cursor:pointer;border-radius:10px;font-size:14px;font-weight:500;transition:.2s;margin-bottom:3px;border:1px solid transparent;user-select:none;text-decoration:none;}
.nav-item:hover{color:var(--text);background:rgba(255,255,255,0.03);}
.nav-item.active{color:var(--green);background:rgba(0,255,136,0.07);border-color:rgba(0,255,136,0.2);}
.nav-sep{height:1px;background:var(--border2);margin:14px 0;}

/* User card */
.user-card{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:16px;}
.user-card .name{font-weight:700;font-size:14px;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.level-tag{font-family:var(--fm);font-size:9px;padding:2px 8px;border-radius:20px;font-weight:700;display:inline-block;margin-bottom:10px;}
.bar-wrap{height:4px;background:rgba(255,255,255,0.06);border-radius:4px;overflow:hidden;margin-bottom:6px;}
.bar-fill{height:100%;border-radius:4px;transition:width 1.2s ease;}
.bar-label{font-family:var(--fm);font-size:10px;color:var(--muted);}

/* MAIN */
.main{flex:1;display:flex;flex-direction:column;overflow:hidden;position:relative;z-index:1;}
.topbar{padding:16px 32px;border-bottom:1px solid var(--border2);display:flex;justify-content:space-between;align-items:center;background:rgba(3,11,20,0.7);backdrop-filter:blur(20px);}
.topbar h1{font-family:var(--fd);font-size:20px;font-weight:700;}
.clock-wrap{font-family:var(--fm);font-size:13px;color:var(--gold);}

.viewport{flex:1;overflow-y:auto;padding:28px 32px;scrollbar-width:thin;scrollbar-color:rgba(0,255,136,0.2) transparent;}
.tab-content{display:none;}
.tab-content.active{display:block;animation:fadeUp .3s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* STATS ROW */
.stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;}
.stat-card{background:var(--surface);border:1px solid var(--border2);border-radius:16px;padding:20px;position:relative;overflow:hidden;transition:.2s;}
.stat-card:hover{border-color:rgba(0,255,136,0.2);}
.stat-card::before{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(0,255,136,0.03),transparent);pointer-events:none;}
.stat-label{font-family:var(--fm);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;}
.stat-val{font-family:var(--fd);font-size:38px;font-weight:800;margin:8px 0 2px;}
.stat-sub{font-size:11px;color:var(--muted);}

/* PANELS */
.panel{background:var(--surface);border:1px solid var(--border2);border-radius:18px;padding:24px;margin-bottom:20px;}
.panel-title{font-family:var(--fm);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:18px;display:flex;align-items:center;gap:8px;}

/* QUIZ CARDS */
.quiz-card{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;background:var(--surface2);border:1px solid var(--border2);border-radius:14px;margin-bottom:10px;transition:.2s;}
.quiz-card:hover{border-color:rgba(0,255,136,0.2);background:rgba(15,31,56,0.9);}
.quiz-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.diff-badge{font-family:var(--fm);font-size:10px;padding:3px 8px;border-radius:6px;font-weight:600;}
.diff-b{background:rgba(0,255,136,0.1);color:var(--green);}
.diff-i{background:rgba(0,212,255,0.1);color:var(--cyan);}
.diff-e{background:rgba(255,77,109,0.1);color:var(--red);}
.btn-start{background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;border:none;padding:10px 22px;border-radius:10px;font-weight:700;cursor:pointer;font-family:var(--fd);font-size:13px;transition:.2s;text-decoration:none;display:inline-block;}
.btn-start:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,255,136,0.3);}

/* HISTORY TABLE */
.hist-table{width:100%;border-collapse:separate;border-spacing:0 6px;}
.hist-table th{font-family:var(--fm);font-size:10px;color:var(--muted);padding:6px 16px;text-align:left;text-transform:uppercase;letter-spacing:1px;}
.hist-table td{padding:14px 16px;background:var(--surface2);border-top:1px solid var(--border2);border-bottom:1px solid var(--border2);font-size:13px;}
.hist-table td:first-child{border-left:1px solid var(--border2);border-radius:10px 0 0 10px;padding-left:20px;}
.hist-table td:last-child{border-right:1px solid var(--border2);border-radius:0 10px 10px 0;}
.hist-table tr:hover td{background:rgba(0,255,136,0.03);}

/* LEADERBOARD */
.lb-row{display:flex;align-items:center;gap:14px;padding:14px 18px;background:var(--surface2);border:1px solid var(--border2);border-radius:12px;margin-bottom:8px;transition:.2s;}
.lb-row:hover{border-color:rgba(0,255,136,0.15);}
.lb-row.me{border-color:rgba(0,255,136,0.35);background:rgba(0,255,136,0.04);}
.lb-rank{font-family:var(--fd);font-size:18px;font-weight:800;width:32px;text-align:center;flex-shrink:0;}
.lb-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--surface),var(--surface2));border:2px solid var(--border2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;flex-shrink:0;}
.lb-name{flex:1;font-weight:600;font-size:14px;}
.lb-score{font-family:var(--fd);font-size:18px;font-weight:800;}

/* BADGES */
.badges-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;}
.badge-card{background:var(--surface2);border:1px solid var(--border2);border-radius:12px;padding:16px;text-align:center;transition:.2s;}
.badge-card:hover{border-color:rgba(0,255,136,0.2);}
.badge-emoji{font-size:28px;margin-bottom:8px;}
.badge-name{font-family:var(--fm);font-size:11px;font-weight:600;color:var(--text);margin-bottom:4px;}
.badge-desc{font-size:11px;color:var(--muted);}
.badge-locked{opacity:0.3;filter:grayscale(100%);}

/* ALERTS */
.alert-success{background:rgba(0,255,136,0.07);border:1px solid rgba(0,255,136,0.2);color:var(--green);padding:12px 18px;border-radius:10px;font-size:13px;margin-bottom:20px;}

/* Cyber level ring */
.level-ring{width:80px;height:80px;border-radius:50%;border:3px solid;display:flex;align-items:center;justify-content:center;font-family:var(--fd);font-weight:800;font-size:14px;margin:0 auto 12px;}

/* Tips */
.tip-banner{background:linear-gradient(135deg,rgba(0,255,136,0.05),rgba(0,212,255,0.05));border:1px solid rgba(0,255,136,0.15);border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-start;}

/* Btns */
.btn-danger{background:rgba(255,77,109,0.08);color:var(--red);border:1px solid rgba(255,77,109,0.2);padding:9px 16px;border-radius:10px;font-weight:700;cursor:pointer;font-size:12px;transition:.2s;}
.btn-danger:hover{background:var(--red);color:#fff;}

::-webkit-scrollbar{width:4px;}
::-webkit-scrollbar-thumb{background:rgba(0,255,136,0.2);border-radius:4px;}
a{text-decoration:none;color:inherit;}
</style>
</head>
<body>

<aside class="sidebar">
    <a href="dashboard.php" class="logo">
        <div class="logo-icon"><i data-lucide="shield-check" size="20" color="#000"></i></div>
        <div class="logo-text">Cyber<span>Shield</span></div>
    </a>
    <nav>
        <div class="nav-item active" id="nav-overview" onclick="showTab('overview')"><i data-lucide="layout-dashboard" size="16"></i> Overview</div>
        <div class="nav-item" id="nav-arena" onclick="showTab('arena')"><i data-lucide="zap" size="16"></i> Quiz Arena</div>
        <div class="nav-item" id="nav-leaderboard" onclick="showTab('leaderboard')"><i data-lucide="trophy" size="16"></i> Leaderboard</div>
        <div class="nav-item" id="nav-history" onclick="showTab('history')"><i data-lucide="clock" size="16"></i> My History</div>
        <div class="nav-item" id="nav-badges" onclick="showTab('badges')"><i data-lucide="award" size="16"></i> Achievements</div>
        <div class="nav-sep"></div>
        <a href="logout.php" class="nav-item" style="color:var(--red);"><i data-lucide="log-out" size="16"></i> Sign Out</a>
    </nav>

    <div class="user-card" style="margin-top:16px;">
        <div class="name"><?= htmlspecialchars($display_name) ?></div>
        <span class="level-tag" style="background:<?= $awareness_color ?>22;color:<?= $awareness_color ?>;border:1px solid <?= $awareness_color ?>44;"><?= $awareness_level ?></span>
        <div class="bar-wrap">
            <div class="bar-fill" style="width:<?= min(100,$avg_score) ?>%;background:linear-gradient(90deg,<?= $awareness_color ?>,var(--cyan));"></div>
        </div>
        <div class="bar-label"><?= $avg_score ?>% avg · <?= count($badges) ?> badges earned</div>
    </div>
</aside>

<div class="main">
    <div class="topbar">
        <h1 id="tab-title">My Dashboard</h1>
        <div style="display:flex;align-items:center;gap:16px;">
            <div class="clock-wrap" id="clock">--:--:--</div>
        </div>
    </div>

    <div class="viewport">
        <?php if(isset($_GET['msg']) && $_GET['msg'] === 'purged'): ?>
        <div class="alert-success">✓ Your exam history has been cleared.</div>
        <?php endif; ?>

        <!-- OVERVIEW TAB -->
        <div id="overview" class="tab-content active">
            <div class="tip-banner">
                <i data-lucide="shield-alert" size="20" style="color:var(--green);flex-shrink:0;margin-top:2px;"></i>
                <div>
                    <div style="font-family:var(--fm);font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px;">Cyber Tip</div>
                    <p style="font-size:13px;line-height:1.6;" id="daily-tip">Loading...</p>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <p class="stat-label">Quizzes Taken</p>
                    <p class="stat-val" style="color:var(--cyan)"><?= $total_attempts ?></p>
                    <p class="stat-sub">Total attempts</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Average Score</p>
                    <p class="stat-val" style="color:var(--green)"><?= $avg_score ?>%</p>
                    <p class="stat-sub">Across all exams</p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Best Score</p>
                    <p class="stat-val" style="color:var(--gold)"><?= $best_score ?>%</p>
                    <p class="stat-sub">Personal record</p>
                </div>
            </div>

            <?php if (!empty($chart_scores)): ?>
            <div class="panel">
                <div class="panel-title"><i data-lucide="trending-up" size="14"></i> Score Trend</div>
                <canvas id="scoreChart" height="90"></canvas>
            </div>
            <?php endif; ?>

            <?php if (!empty($badges)): ?>
            <div class="panel">
                <div class="panel-title"><i data-lucide="award" size="14"></i> Your Badges</div>
                <div class="badges-grid">
                    <?php foreach($badges as $b): ?>
                    <div class="badge-card">
                        <div class="badge-emoji"><?= $b[0] ?></div>
                        <div class="badge-name"><?= $b[1] ?></div>
                        <div class="badge-desc"><?= $b[2] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- QUIZ ARENA TAB -->
        <div id="arena" class="tab-content">
            <div style="margin-bottom:24px;">
                <h2 style="font-family:var(--fd);font-size:24px;font-weight:700;margin-bottom:6px;">Quiz Arena</h2>
                <p style="color:var(--muted);font-size:13px;">Select a quiz to test your cyber awareness knowledge.</p>
            </div>

            <?php
            $cat_icons = ['Phishing'=>'🎣','Malware'=>'🦠','Social Engineering'=>'🕵️','Password'=>'🔑','Privacy'=>'🔒','Wi-Fi'=>'📡','Banking'=>'🏦','Mobile'=>'📱'];
            while ($quiz = mysqli_fetch_assoc($quizzes_res)):
                $diff = $quiz['difficulty'] ?? 'Intermediate';
                $diff_class = $diff === 'Beginner' ? 'diff-b' : ($diff === 'Expert' ? 'diff-e' : 'diff-i');
                // find matching category icon
                $icon = '🛡️';
                foreach ($cat_icons as $k => $v) { if (stripos($quiz['title'], $k) !== false || stripos($quiz['topic'] ?? '', $k) !== false) { $icon = $v; break; } }
            ?>
            <div class="quiz-card">
                <div style="display:flex;align-items:center;gap:16px;">
                    <div class="quiz-icon" style="background:rgba(0,255,136,0.08);border:1px solid rgba(0,255,136,0.15);font-size:22px;"><?= $icon ?></div>
                    <div>
                        <p style="font-weight:700;font-size:15px;"><?= htmlspecialchars($quiz['title']) ?></p>
                        <p style="color:var(--muted);font-size:12px;font-family:var(--fm);margin-top:3px;">
                            <span class="diff-badge <?= $diff_class ?>"><?= $diff ?></span>
                            &nbsp;<?= $quiz['duration'] ?>min &nbsp;·&nbsp; <?= $quiz['q_count'] ?> questions
                        </p>
                    </div>
                </div>
                <a href="attempt.php?id=<?= $quiz['id'] ?>" class="btn-start">Start →</a>
            </div>
            <?php endwhile; ?>

            <?php if (mysqli_num_rows(mysqli_query($conn, "SELECT id FROM quizzes LIMIT 1")) == 0): ?>
            <div style="text-align:center;padding:60px;color:var(--muted);">
                <i data-lucide="inbox" size="48" style="opacity:0.3;display:block;margin-bottom:16px;"></i>
                <p>No quizzes available yet. Check back soon!</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- LEADERBOARD TAB -->
        <div id="leaderboard" class="tab-content">
            <div style="margin-bottom:24px;">
                <h2 style="font-family:var(--fd);font-size:24px;font-weight:700;margin-bottom:6px;">🏆 Leaderboard</h2>
                <p style="color:var(--muted);font-size:13px;">Top cyber awareness champions on the platform.<?php if ($my_rank): ?> Your rank: <strong style="color:var(--green);">#<?= $my_rank ?></strong><?php endif; ?></p>
            </div>

            <?php
            $rank_icons = ['🥇','🥈','🥉'];
            foreach ($leaderboard_rows as $i => $lb):
                $is_me = $lb['uid'] == $db_uid;
                $r = $i + 1;
                $initials = strtoupper(substr($lb['fullname'], 0, 1));
                $colors = ['#f5c842','#a8b8c0','#cd7f32'];
                $rank_color = $r <= 3 ? $colors[$r-1] : 'var(--muted)';
            ?>
            <div class="lb-row <?= $is_me ? 'me' : '' ?>">
                <div class="lb-rank" style="color:<?= $rank_color ?>"><?= $r <= 3 ? $rank_icons[$r-1] : "#$r" ?></div>
                <div class="lb-avatar" style="<?= $is_me ? 'border-color:var(--green);color:var(--green);' : '' ?>"><?= $initials ?></div>
                <div class="lb-name">
                    <?= htmlspecialchars($lb['fullname']) ?>
                    <?= $is_me ? ' <span style="font-family:var(--fm);font-size:10px;color:var(--green);">(You)</span>' : '' ?>
                    <div style="font-size:11px;color:var(--muted);font-family:var(--fm);margin-top:2px;"><?= $lb['attempts'] ?> attempts</div>
                </div>
                <div class="lb-score" style="color:<?= $lb['avg_score'] >= 80 ? 'var(--green)' : ($lb['avg_score'] >= 50 ? 'var(--cyan)' : 'var(--muted)') ?>"><?= $lb['avg_score'] ?>%</div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($leaderboard_rows)): ?>
            <div style="text-align:center;padding:60px;color:var(--muted);">
                <p>No scores yet. Be the first to take a quiz!</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- HISTORY TAB -->
        <div id="history" class="tab-content">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
                <h2 style="font-family:var(--fd);font-size:24px;font-weight:700;">Exam History</h2>
                <?php if (!empty($results_array)): ?>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <button type="submit" name="delete_history" class="btn-danger" onclick="return confirm('Clear all your history? This cannot be undone.')">
                        <i data-lucide="trash-2" size="13" style="vertical-align:middle;"></i> Clear History
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (empty($results_array)): ?>
            <div style="text-align:center;padding:80px;color:var(--muted);">
                <i data-lucide="inbox" size="48" style="opacity:0.3;display:block;margin:0 auto 16px;"></i>
                <p>No exam history yet. Head to the Quiz Arena!</p>
            </div>
            <?php else: ?>
            <div class="panel" style="padding:0;overflow:hidden;">
                <table class="hist-table">
                    <thead><tr><th>Quiz</th><th>Score</th><th>Result</th><th>Date</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($results_array as $res):
                        $passed = $res['score'] >= 50;
                    ?>
                    <tr>
                        <td><b><?= htmlspecialchars($res['title']) ?></b>
                            <div style="font-size:11px;color:var(--muted);font-family:var(--fm);margin-top:2px;"><?= $res['correct_answers'] ?>/<?= $res['total_questions'] ?> correct</div>
                        </td>
                        <td style="font-family:var(--fd);font-size:18px;font-weight:800;color:<?= $passed ? 'var(--green)' : 'var(--red)' ?>"><?= $res['score'] ?>%</td>
                        <td><span style="font-family:var(--fm);font-size:10px;padding:3px 9px;border-radius:20px;font-weight:700;background:<?= $passed ? 'rgba(0,255,136,0.1)' : 'rgba(255,77,109,0.1)' ?>;color:<?= $passed ? 'var(--green)' : 'var(--red)' ?>;"><?= $passed ? 'PASSED' : 'RETRY' ?></span></td>
                        <td style="color:var(--muted);font-family:var(--fm);font-size:11px;"><?= date('M d, Y', strtotime($res['created_at'])) ?></td>
                        <td>
                            <form action="review.php" method="POST">
                                <input type="hidden" name="quiz_id" value="<?= $res['quiz_id'] ?>">
                                <button type="submit" style="background:none;border:none;color:var(--cyan);cursor:pointer;font-size:12px;font-weight:600;display:flex;align-items:center;gap:4px;"><i data-lucide="eye" size="13"></i> Review</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- BADGES TAB -->
        <div id="badges" class="tab-content">
            <div style="margin-bottom:24px;">
                <h2 style="font-family:var(--fd);font-size:24px;font-weight:700;margin-bottom:6px;">Your Achievements</h2>
                <p style="color:var(--muted);font-size:13px;">Complete quizzes to earn badges and level up your cyber awareness.</p>
            </div>

            <?php
            $all_badges = [
                ['🛡️', 'First Shield', 'Completed your first quiz', $total_attempts >= 1],
                ['🔥', 'On Fire', 'Completed 5+ quizzes', $total_attempts >= 5],
                ['⚡', 'Power User', 'Completed 10+ quizzes', $total_attempts >= 10],
                ['💯', 'Perfect Score', 'Achieved 100% on a quiz', $best_score >= 100],
                ['🌟', 'High Achiever', 'Average score above 80%', $avg_score >= 80],
                ['📈', 'Consistent', 'Average score 50-80%', $avg_score >= 50 && $avg_score < 80],
                ['🔒', 'Cyber Guardian', '60%+ avg with 3+ attempts', $total_attempts >= 3 && $avg_score >= 60],
                ['🎯', 'Sharp Shooter', 'Score 90%+ on any quiz', $best_score >= 90],
                ['🧠', 'Cyber Expert', '80%+ avg with 10+ attempts', $avg_score >= 80 && $total_attempts >= 10],
                ['🏆', 'Legend', '95%+ avg with 15+ attempts', $avg_score >= 95 && $total_attempts >= 15],
            ];
            ?>
            <div class="badges-grid">
                <?php foreach ($all_badges as $b): ?>
                <div class="badge-card <?= !$b[3] ? 'badge-locked' : '' ?>" style="<?= $b[3] ? 'border-color:rgba(0,255,136,0.2);' : '' ?>">
                    <div class="badge-emoji"><?= $b[0] ?></div>
                    <div class="badge-name" style="<?= $b[3] ? 'color:var(--green);' : '' ?>"><?= $b[1] ?></div>
                    <div class="badge-desc"><?= $b[2] ?></div>
                    <?php if (!$b[3]): ?>
                    <div style="font-family:var(--fm);font-size:9px;color:var(--muted);margin-top:6px;">🔒 LOCKED</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
lucide.createIcons();

const tabTitles = {overview:'My Dashboard',arena:'Quiz Arena',leaderboard:'Leaderboard',history:'Exam History',badges:'Achievements'};
function showTab(t) {
    document.querySelectorAll('.tab-content').forEach(s => s.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
    const el = document.getElementById(t);
    if (el) el.classList.add('active');
    const nav = document.getElementById('nav-' + t);
    if (nav) nav.classList.add('active');
    document.getElementById('tab-title').textContent = tabTitles[t] || t;
    history.replaceState(null,'','?tab='+t);
}

// Clock
setInterval(() => {
    const n = new Date();
    document.getElementById('clock').textContent = n.toLocaleTimeString('en-IN');
}, 1000);

// Score chart
<?php if (!empty($chart_scores)): ?>
new Chart(document.getElementById('scoreChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($chart_labels) ?>,
        datasets: [{
            label: 'Score %',
            data: <?= json_encode($chart_scores) ?>,
            borderColor: '#00ff88',
            backgroundColor: 'rgba(0,255,136,0.07)',
            tension: 0.4, fill: true,
            pointBackgroundColor: '#00ff88', pointRadius: 5
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: {
            y: { min: 0, max: 100, grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#4a6070', font: { family: 'IBM Plex Mono', size: 10 } } },
            x: { grid: { display: false }, ticks: { color: '#4a6070', font: { family: 'IBM Plex Mono', size: 10 } } }
        }
    }
});
<?php endif; ?>

// Cyber tips
const tips = [
    "Think before you click — phishing emails often create false urgency to trick you.",
    "Use multi-factor authentication on all critical accounts. It's your best defense.",
    "Never download attachments from unknown senders — they may contain malware.",
    "Strong passwords are your first shield. Use a mix of letters, numbers, and symbols.",
    "Ransomware can lock your files forever. Always back up your important data offline.",
    "Social engineering attacks exploit human psychology, not just technology.",
    "Cybercriminals often impersonate trusted organizations. Always verify requests.",
    "Public Wi-Fi is a trap for hackers. Use a VPN when connecting to public networks."
];
document.getElementById('daily-tip').textContent = tips[new Date().getDate() % tips.length];

// Restore tab from URL
const urlTab = new URLSearchParams(window.location.search).get('tab');
if (urlTab && document.getElementById(urlTab)) showTab(urlTab);
</script>
</body>
</html>