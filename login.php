<?php
session_start();
include("config/db.php");
$error = "";

// Already logged in? Route correctly
if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') { header("Location: admin.php"); exit(); }
    if ($_SESSION['role'] === 'user')  { header("Location: dashboard.php"); exit(); }
}

if (isset($_POST['login'])) {
    $email    = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = $_POST['password'];

    // ── ADMIN CHECK (hardcoded, isolated route) ──
    if ($email === "admin@gmail.com" && $password === "Admin@123") {
        session_regenerate_id(true);
        $_SESSION['user_id']  = 'admin_01';
        $_SESSION['fullname'] = 'System Administrator';
        $_SESSION['email']    = $email;
        $_SESSION['role']     = 'admin';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: admin.php"); exit();
    }

    // ── USER CHECK ──
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && password_verify($password, $row['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $row['id'];
        $_SESSION['fullname'] = $row['fullname'];
        $_SESSION['email']    = $row['email'];
        $_SESSION['role']     = 'user';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: dashboard.php"); exit();
    } else {
        $error = "Invalid credentials. Please try again.";
    }
}

$signup_success = isset($_GET['signup']) && $_GET['signup'] === 'success';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CyberShield — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root{--bg:#030b14;--surface:#0a1628;--border:rgba(0,255,136,0.15);--border2:rgba(255,255,255,0.07);--green:#00ff88;--cyan:#00d4ff;--red:#ff4d6d;--gold:#f5c842;--text:#e8f0fe;--muted:#4a6070;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;overflow:hidden;}
/* Animated grid bg */
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,136,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,0.025) 1px,transparent 1px);background-size:50px 50px;pointer-events:none;}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(0,212,255,0.08),transparent),radial-gradient(ellipse 60% 60% at 100% 100%,rgba(0,255,136,0.05),transparent);pointer-events:none;}

/* Floating particles */
.particle{position:fixed;border-radius:50%;pointer-events:none;animation:float linear infinite;opacity:0.4;}
@keyframes float{0%{transform:translateY(100vh) rotate(0deg);opacity:0;}10%{opacity:0.4;}90%{opacity:0.4;}100%{transform:translateY(-100px) rotate(720deg);opacity:0;}}

.auth-wrap{position:relative;z-index:1;width:100%;max-width:460px;padding:20px;}

/* Brand */
.brand{text-align:center;margin-bottom:36px;}
.brand-logo{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:10px;}
.shield-icon{width:52px;height:52px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:14px;display:flex;align-items:center;justify-content:center;box-shadow:0 0 30px rgba(0,255,136,0.3);}
.brand-name{font-family:'Syne',sans-serif;font-size:30px;font-weight:800;color:var(--text);}
.brand-name span{color:var(--green);}
.brand-tag{display:inline-block;background:rgba(255,77,109,0.1);border:1px solid rgba(255,77,109,0.3);color:var(--red);font-family:'IBM Plex Mono',monospace;font-size:10px;font-weight:600;padding:3px 10px;border-radius:20px;text-transform:uppercase;letter-spacing:1px;}

/* Card */
.auth-card{background:var(--surface);border:1px solid var(--border);border-radius:24px;padding:36px;backdrop-filter:blur(20px);box-shadow:0 24px 80px rgba(0,0,0,0.5);}
.auth-card h2{font-family:'Syne',sans-serif;font-size:22px;font-weight:700;margin-bottom:6px;}
.auth-card .sub{font-size:13px;color:var(--muted);margin-bottom:28px;}

/* Form */
.form-group{margin-bottom:18px;}
.form-group label{display:block;font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:8px;}
.input-wrap{position:relative;}
.form-group input{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border2);border-radius:12px;padding:13px 16px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:14px;transition:.2s;outline:none;}
.form-group input:focus{border-color:var(--green);background:rgba(0,255,136,0.04);box-shadow:0 0 0 3px rgba(0,255,136,0.08);}
.eye-btn{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;transition:.2s;}
.eye-btn:hover{color:var(--text);}

/* Messages */
.error-msg{background:rgba(255,77,109,0.08);border:1px solid rgba(255,77,109,0.25);color:var(--red);padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.success-msg{background:rgba(0,255,136,0.08);border:1px solid rgba(0,255,136,0.25);color:var(--green);padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;}

.btn-main{width:100%;background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;border:none;padding:14px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;font-family:'Syne',sans-serif;letter-spacing:0.5px;transition:.25s;margin-top:8px;}
.btn-main:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,255,136,0.3);}

.auth-footer{text-align:center;margin-top:20px;font-size:13px;color:var(--muted);}
.auth-footer a{color:var(--cyan);font-weight:600;text-decoration:none;}
.auth-footer a:hover{color:var(--green);}

/* Cyber tips at bottom */
.cyber-tip{margin-top:24px;background:rgba(255,77,109,0.05);border:1px solid rgba(255,77,109,0.12);border-radius:14px;padding:14px 16px;display:flex;gap:10px;align-items:flex-start;}
.cyber-tip p{font-size:12px;color:var(--muted);line-height:1.6;}
.cyber-tip strong{color:var(--red);font-family:'IBM Plex Mono',monospace;font-size:10px;text-transform:uppercase;display:block;margin-bottom:3px;}
</style>
</head>
<body>

<div class="auth-wrap">
    <div class="brand">
        <div class="brand-logo">
            <div class="shield-icon"><i data-lucide="shield-check" size="26" color="#000"></i></div>
            <div class="brand-name">Cyber<span>Shield</span></div>
        </div>
       
    </div>

    <div class="auth-card">
        <h2>Sign In</h2>
        <p class="sub">Access your secure examination portal.</p>

        <?php if ($signup_success): ?>
        <div class="success-msg"><i data-lucide="check-circle" size="16"></i> Account created! You can now sign in.</div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="error-msg"><i data-lucide="alert-triangle" size="16"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@example.com" required autocomplete="email">
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="input-wrap">
                    <input type="password" name="password" id="pass" placeholder="••••••••" required autocomplete="current-password">
                    <button type="button" class="eye-btn" onclick="togglePass()">
                        <i data-lucide="eye" size="16" id="eye-icon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" name="login" class="btn-main">Sign In →</button>
        </form>

        <p class="auth-footer">New here? <a href="signup.php">Create an account</a></p>
    </div>

    <div class="cyber-tip">
        <i data-lucide="alert-circle" size="18" style="color:var(--red);flex-shrink:0;margin-top:2px;"></i>
        <div>
           
            <p id="cyber-tip-text">Loading tip...</p>
        </div>
    </div>
</div>

<script>
lucide.createIcons();

// Password toggle
function togglePass() {
    const el = document.getElementById('pass');
    const ic = document.getElementById('eye-icon');
    el.type = el.type === 'password' ? 'text' : 'password';
    ic.setAttribute('data-lucide', el.type === 'password' ? 'eye' : 'eye-off');
    lucide.createIcons();
}

// Rotating cyber tips
const tips = [
    "Never share your OTP or password with anyone — not even bank officials.",
    "Always verify URLs before clicking. Phishing sites often look identical to real ones.",
    "Enable two-factor authentication (2FA) on all your important accounts.",
    "Public Wi-Fi is a hacker's playground. Use a VPN when on public networks.",
    "Regularly update your software. Most cyberattacks exploit known vulnerabilities.",
    "Use unique, strong passwords for each account. Consider a password manager.",
    "Cyber criminals use fear and urgency to trick you. Always pause before clicking."
];
document.getElementById('cyber-tip-text').textContent = tips[Math.floor(Math.random() * tips.length)];

// Floating particles
for(let i=0; i<12; i++) {
    const p = document.createElement('div');
    p.className = 'particle';
    const s = Math.random()*4+2;
    p.style.cssText = `width:${s}px;height:${s}px;left:${Math.random()*100}%;background:${Math.random()>0.5?'rgba(0,255,136,0.6)':'rgba(0,212,255,0.6)'};animation-duration:${Math.random()*15+10}s;animation-delay:${Math.random()*10}s;`;
    document.body.appendChild(p);
}
</script>
</body>
</html>