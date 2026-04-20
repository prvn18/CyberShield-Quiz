<?php
session_start();
include("config/db.php");

// Already logged in?
if (isset($_SESSION['role'])) {
    header("Location: " . ($_SESSION['role'] === 'admin' ? 'admin.php' : 'dashboard.php')); exit();
}

$msg = ""; $success = false;

if (isset($_POST['register'])) {
    $fullname         = trim($_POST['fullname']);
    $email            = trim($_POST['email']);
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($fullname) < 2 || strlen($fullname) > 80) {
        $msg = "Full name must be between 2 and 80 characters.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $msg = "Password must be at least 8 characters.";
    } elseif ($password !== $confirm_password) {
        $msg = "Passwords do not match.";
    } else {
        $fn_safe = mysqli_real_escape_string($conn, $fullname);
        $em_safe = mysqli_real_escape_string($conn, $email);

        $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check->bind_param("s", $em_safe);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $msg = "An account with this email already exists.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $ins = $conn->prepare("INSERT INTO users (fullname, email, password) VALUES (?, ?, ?)");
            $ins->bind_param("sss", $fn_safe, $em_safe, $hashed);
            if ($ins->execute()) {
                header("Location: login.php?signup=success"); exit();
            } else {
                $msg = "Registration failed. Please try again.";
            }
            $ins->close();
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CyberShield — Create Account</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root{--bg:#030b14;--surface:#0a1628;--border:rgba(0,255,136,0.15);--border2:rgba(255,255,255,0.07);--green:#00ff88;--cyan:#00d4ff;--red:#ff4d6d;--text:#e8f0fe;--muted:#4a6070;}
*{margin:0;padding:0;box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;}
body::before{content:'';position:fixed;inset:0;background-image:linear-gradient(rgba(0,255,136,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(0,255,136,0.025) 1px,transparent 1px);background-size:50px 50px;pointer-events:none;}
body::after{content:'';position:fixed;inset:0;background:radial-gradient(ellipse 80% 60% at 50% -10%,rgba(0,212,255,0.08),transparent);pointer-events:none;}

.auth-wrap{position:relative;z-index:1;width:100%;max-width:480px;padding:20px;}
.brand{text-align:center;margin-bottom:28px;}
.brand-logo{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:8px;}
.shield-icon{width:44px;height:44px;background:linear-gradient(135deg,var(--green),var(--cyan));border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 0 20px rgba(0,255,136,0.25);}
.brand-name{font-family:'Syne',sans-serif;font-size:26px;font-weight:800;}
.brand-name span{color:var(--green);}
.brand-sub{font-size:12px;color:var(--muted);font-family:'IBM Plex Mono',monospace;}

.auth-card{background:var(--surface);border:1px solid var(--border);border-radius:24px;padding:36px;box-shadow:0 24px 80px rgba(0,0,0,0.5);}
.auth-card h2{font-family:'Syne',sans-serif;font-size:22px;font-weight:700;margin-bottom:6px;}
.auth-card .sub{font-size:13px;color:var(--muted);margin-bottom:28px;}

.form-group{margin-bottom:16px;}
.form-group label{display:block;font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:8px;}
.input-wrap{position:relative;}
.form-group input{width:100%;background:rgba(255,255,255,0.04);border:1px solid var(--border2);border-radius:12px;padding:13px 16px;color:var(--text);font-size:14px;transition:.2s;outline:none;}
.form-group input:focus{border-color:var(--green);background:rgba(0,255,136,0.04);box-shadow:0 0 0 3px rgba(0,255,136,0.08);}
.eye-btn{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--muted);cursor:pointer;}

/* Strength bar */
.strength-bar{height:3px;background:rgba(255,255,255,0.06);border-radius:3px;margin-top:8px;overflow:hidden;}
.strength-fill{height:100%;border-radius:3px;transition:width .3s,background .3s;width:0%;}
.strength-label{font-family:'IBM Plex Mono',monospace;font-size:10px;color:var(--muted);margin-top:5px;}

/* Password match */
.match-indicator{font-family:'IBM Plex Mono',monospace;font-size:10px;margin-top:5px;transition:.2s;}

.error-msg{background:rgba(255,77,109,0.08);border:1px solid rgba(255,77,109,0.25);color:var(--red);padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.btn-main{width:100%;background:linear-gradient(135deg,var(--green),var(--cyan));color:#000;border:none;padding:14px;border-radius:12px;font-weight:700;font-size:15px;cursor:pointer;font-family:'Syne',sans-serif;transition:.25s;margin-top:8px;}
.btn-main:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,255,136,0.3);}
.btn-main:disabled{opacity:0.5;cursor:not-allowed;transform:none;}

.auth-footer{text-align:center;margin-top:20px;font-size:13px;color:var(--muted);}
.auth-footer a{color:var(--cyan);font-weight:600;text-decoration:none;}

/* Security checklist */
.security-checks{margin-top:10px;display:flex;flex-direction:column;gap:4px;}
.check-item{display:flex;align-items:center;gap:6px;font-size:11px;color:var(--muted);transition:.2s;}
.check-item.valid{color:var(--green);}
.check-dot{width:5px;height:5px;border-radius:50%;background:var(--muted);flex-shrink:0;transition:.2s;}
.check-item.valid .check-dot{background:var(--green);}
</style>
</head>
<body>
<div class="auth-wrap">
    <div class="brand">
        <div class="brand-logo">
            <div class="shield-icon"><i data-lucide="shield-check" size="22" color="#000"></i></div>
            <div class="brand-name">Cyber<span>Shield</span></div>
        </div>
        <p class="brand-sub">Stop Cyber Crime · Awareness Quiz Platform</p>
    </div>

    <div class="auth-card">
        <h2>Create Account</h2>
        <p class="sub">Join CyberShield and test your cyber awareness skills.</p>

        <?php if ($msg): ?>
        <div class="error-msg"><i data-lucide="alert-triangle" size="16"></i> <?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form method="POST" id="regForm">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="fullname" id="fullname" placeholder="John Doe" required minlength="2" maxlength="80">
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="you@example.com" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="input-wrap">
                    <input type="password" name="password" id="pass" placeholder="Min 8 characters" required oninput="checkStrength(this.value)">
                    <button type="button" class="eye-btn" onclick="togglePass('pass',this)"><i data-lucide="eye" size="16"></i></button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                <div class="strength-label" id="strength-label">Enter a password</div>
                <div class="security-checks">
                    <div class="check-item" id="check-len"><div class="check-dot"></div>At least 8 characters</div>
                    <div class="check-item" id="check-upper"><div class="check-dot"></div>Uppercase letter</div>
                    <div class="check-item" id="check-num"><div class="check-dot"></div>Number</div>
                    <div class="check-item" id="check-special"><div class="check-dot"></div>Special character</div>
                </div>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="input-wrap">
                    <input type="password" name="confirm_password" id="conf" placeholder="Repeat password" required oninput="checkMatch()">
                    <button type="button" class="eye-btn" onclick="togglePass('conf',this)"><i data-lucide="eye" size="16"></i></button>
                </div>
                <div class="match-indicator" id="match-indicator"></div>
            </div>
            <button type="submit" name="register" class="btn-main" id="submit-btn">Create Account →</button>
        </form>
        <p class="auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
    </div>
</div>
<script>
lucide.createIcons();

function togglePass(id, btn) {
    const el = document.getElementById(id);
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.querySelector('i').setAttribute('data-lucide', el.type === 'password' ? 'eye' : 'eye-off');
    lucide.createIcons();
}

function checkStrength(val) {
    const fill  = document.getElementById('strength-fill');
    const label = document.getElementById('strength-label');
    const checks = {
        len:     val.length >= 8,
        upper:   /[A-Z]/.test(val),
        num:     /[0-9]/.test(val),
        special: /[^A-Za-z0-9]/.test(val)
    };
    ['len','upper','num','special'].forEach(k => {
        const el = document.getElementById('check-' + k);
        el.classList.toggle('valid', checks[k]);
    });
    const score = Object.values(checks).filter(Boolean).length;
    const colors = ['#ff4d6d','#ff4d6d','#f5c842','#00ff88'];
    const labels = ['Too weak','Weak','Good','Strong'];
    fill.style.width = (score * 25) + '%';
    fill.style.background = colors[score - 1] || 'transparent';
    label.textContent = score > 0 ? labels[score - 1] : 'Enter a password';
    label.style.color = colors[score - 1] || 'var(--muted)';
}

function checkMatch() {
    const p1 = document.getElementById('pass').value;
    const p2 = document.getElementById('conf').value;
    const el = document.getElementById('match-indicator');
    if (!p2) { el.textContent = ''; return; }
    if (p1 === p2) { el.textContent = '✓ Passwords match'; el.style.color = 'var(--green)'; }
    else { el.textContent = '✗ Passwords do not match'; el.style.color = 'var(--red)'; }
}
</script>
</body>
</html>