<?php
session_start();

if (isset($_SESSION['account_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'Admin') {
        header('Location: src/admin_dashboard.php');
        exit;
    }
    if ($_SESSION['role'] === 'Staff') {
        header('Location: src/staff_dashboard.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Radog's Kennel — Sign In</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">

    <style>
        :root {
            --orange:    #FA8112;
            --orange-dk: #d96a08;
            --orange-lt: #fca04a;
            --black:     #222222;
            --beige:     #FAF3E1;
            --gold:      #F5E7C6;
            --white:     #ffffff;

            --radius-card: 20px;
            --radius-input: 12px;
            --radius-btn:   12px;

            font-family: 'DM Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── PAGE LAYOUT ─────────────────────────────────── */
        body {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1fr 1fr;
            background: var(--black);
            overflow: hidden;
        }

        /* ── LEFT PANEL ──────────────────────────────────── */
        .left-panel {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 48px;
            overflow: hidden;
            background: var(--black);
        }

        /* diagonal stripe texture */
        .left-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: repeating-linear-gradient(
                -55deg,
                transparent,
                transparent 18px,
                rgba(250,129,18,0.045) 18px,
                rgba(250,129,18,0.045) 19px
            );
            pointer-events: none;
        }

        /* glowing orb behind logo */
        .glow {
            position: absolute;
            width: 480px;
            height: 480px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(250,129,18,0.22) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 0.7; transform: translate(-50%, -50%) scale(1); }
            50%       { opacity: 1;   transform: translate(-50%, -50%) scale(1.08); }
        }

        /* diamond border decorations */
        .diamond-ring {
            position: absolute;
            border: 1px solid rgba(250,129,18,0.12);
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            pointer-events: none;
        }
        .diamond-ring:nth-child(1) { width: 340px; height: 340px; animation: ringPulse 4s ease-in-out infinite 0s; }
        .diamond-ring:nth-child(2) { width: 460px; height: 460px; animation: ringPulse 4s ease-in-out infinite 0.5s; }
        .diamond-ring:nth-child(3) { width: 580px; height: 580px; animation: ringPulse 4s ease-in-out infinite 1s; }

        @keyframes ringPulse {
            0%, 100% { opacity: 0.4; }
            50%       { opacity: 0.15; }
        }

        .left-content {
            position: relative;
            z-index: 2;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 32px;
            animation: fadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        /* brand logo + wordmark row */
        .brand-row {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-wrap {
            width: 110px;
            height: 110px;
            flex-shrink: 0;
            filter: drop-shadow(0 0 24px rgba(250,129,18,0.55));
        }

        .logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .wordmark {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .wordmark-top {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 3.2rem;
            letter-spacing: 0.04em;
            color: var(--orange);
            line-height: 1;
            text-shadow: 0 0 40px rgba(250,129,18,0.4);
        }

        .wordmark-sub {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: 0.22em;
            text-transform: uppercase;
            color: var(--gold);
            opacity: 0.85;
        }

        /* divider */
        .panel-divider {
            width: 64px;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--orange), transparent);
            border-radius: 99px;
        }

        /* tagline block */
        .tagline {
            text-align: center;
        }

        .tagline h2 {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.9rem;
            letter-spacing: 0.06em;
            color: var(--white);
            line-height: 1.1;
            margin-bottom: 12px;
        }

        .tagline h2 span {
            color: var(--orange);
        }

        .tagline p {
            font-size: 0.92rem;
            color: rgba(245,231,198,0.65);
            line-height: 1.7;
            max-width: 300px;
            margin: 0 auto;
        }

        /* stat pills */
        .stats-row {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .stat-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: rgba(250,129,18,0.1);
            border: 1px solid rgba(250,129,18,0.2);
            border-radius: 99px;
        }

        .stat-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--orange);
            box-shadow: 0 0 8px var(--orange);
            animation: blink 2.2s ease-in-out infinite;
        }

        @keyframes blink {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.3; }
        }

        .stat-pill span {
            font-size: 0.8rem;
            font-weight: 500;
            color: var(--gold);
            letter-spacing: 0.05em;
        }

        /* ── RIGHT PANEL ─────────────────────────────────── */
        .right-panel {
            background: var(--beige);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 56px;
            position: relative;
            overflow: hidden;
        }

        /* subtle pattern on beige panel */
        .right-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                radial-gradient(circle at 90% 10%, rgba(250,129,18,0.08) 0%, transparent 50%),
                radial-gradient(circle at 10% 90%, rgba(34,34,34,0.05) 0%, transparent 50%);
            pointer-events: none;
        }

        /* top-left corner accent */
        .corner-accent {
            position: absolute;
            top: 0;
            right: 0;
            width: 160px;
            height: 160px;
            background: linear-gradient(225deg, rgba(250,129,18,0.15) 0%, transparent 60%);
            pointer-events: none;
        }

        .login-card {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 400px;
            animation: fadeUp 0.9s cubic-bezier(0.16, 1, 0.3, 1) 0.15s both;
        }

        .card-header {
            margin-bottom: 36px;
        }

        .card-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--orange);
            margin-bottom: 10px;
        }

        .card-eyebrow::before {
            content: '';
            display: block;
            width: 20px;
            height: 2px;
            background: var(--orange);
            border-radius: 99px;
        }

        .card-header h2 {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 2.6rem;
            letter-spacing: 0.04em;
            color: var(--black);
            line-height: 1.05;
            margin-bottom: 8px;
        }

        .card-header p {
            font-size: 0.9rem;
            color: #6b5e4e;
            line-height: 1.6;
        }

        /* form fields */
        .field-group {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 28px;
        }

        .field-wrap {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        label {
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #4a3f33;
        }

        .input-shell {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #b8a898;
            pointer-events: none;
            transition: color 0.2s;
        }

        input[type='text'],
        input[type='password'] {
            width: 100%;
            padding: 15px 16px 15px 46px;
            border: 1.5px solid #e2d9ce;
            border-radius: var(--radius-input);
            background: var(--white);
            color: var(--black);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.97rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input[type='text']::placeholder,
        input[type='password']::placeholder {
            color: #c4b8aa;
            font-weight: 300;
        }

        input[type='text']:focus,
        input[type='password']:focus {
            border-color: var(--orange);
            outline: none;
            box-shadow: 0 0 0 3px rgba(250,129,18,0.15);
        }

        input[type='text']:focus ~ .input-icon,
        input[type='password']:focus ~ .input-icon {
            color: var(--orange);
        }

        /* focus sibling trick — reverse order needed for CSS */
        .input-shell input:focus + svg { color: var(--orange); }

        /* password toggle */
        .toggle-pass {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #b8a898;
            padding: 4px;
            display: flex;
            align-items: center;
            width: auto;
            transition: color 0.2s;
        }

        .toggle-pass:hover { color: var(--orange); }

        /* error */
        .error-message {
            display: none;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 16px;
            border-radius: var(--radius-input);
            background: #fff1ee;
            border: 1px solid #fbbfb0;
            color: #c0392b;
            font-size: 0.88rem;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .error-message.visible { display: flex; }

        .error-icon { flex-shrink: 0; margin-top: 1px; }

        /* submit button */
        .btn-submit {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: var(--radius-btn);
            background: var(--black);
            color: var(--white);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.25rem;
            letter-spacing: 0.12em;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 0.15s, box-shadow 0.15s;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, var(--orange) 0%, var(--orange-dk) 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .btn-submit:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(34,34,34,0.25);
        }

        .btn-submit:not(:disabled):hover::before { opacity: 1; }

        .btn-submit:disabled { opacity: 0.65; cursor: wait; }

        .btn-inner {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* spinner */
        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.45);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* footer note */
        .login-footer {
            margin-top: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-line {
            flex: 1;
            height: 1px;
            background: #e2d9ce;
        }

        .footer-note {
            font-size: 0.78rem;
            color: #a8998a;
            text-align: center;
            line-height: 1.6;
            white-space: nowrap;
        }

        /* animations */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── RESPONSIVE ───────────────────────────────────── */
        @media (max-width: 820px) {
            body {
                grid-template-columns: 1fr;
                grid-template-rows: auto 1fr;
                overflow: auto;
            }

            .left-panel {
                padding: 40px 24px 32px;
                min-height: unset;
            }

            .diamond-ring:nth-child(3) { display: none; }

            .logo-wrap { width: 72px; height: 72px; }
            .wordmark-top { font-size: 2.2rem; }

            .tagline h2 { font-size: 1.4rem; }
            .tagline p  { font-size: 0.85rem; }

            .right-panel { padding: 40px 24px 48px; }
        }
    </style>
</head>
<body>

    <!-- ── LEFT PANEL ─────────────────────────────── -->
    <aside class="left-panel">
        <div class="glow"></div>
        <div class="diamond-ring"></div>
        <div class="diamond-ring"></div>
        <div class="diamond-ring"></div>

        <div class="left-content">

            <!-- logo + wordmark -->
            <div class="brand-row">
                <div class="logo-wrap">
    
                    <img src="./img/radog_logocutie.png" alt="Radog's Kennel Logo">
                </div>
                <div class="wordmark">
                    <div class="wordmark-top">Radog's Kennel</div>
                    <div class="wordmark-sub">Pet Hotel Management</div>
                </div>
            </div>

            <div class="panel-divider"></div>

           <div class="tagline">
    <h2>Where Pets Have Their <br><span>Paw-Some Staycation</span> While You're Away!</h2>
    <p>Sleepover in <span style="color: var(--orange);">Comfort.</span> Playover in <span style="color: var(--orange);">Joy.</span></p>
        </div>

            <div class="stats-row">
                <div class="stat-pill">
                    <div class="stat-dot"></div>
                    <span>Reservations</span>
                </div>
                <div class="stat-pill">
                    <div class="stat-dot"></div>
                    <span>Pet Profiles</span>
                </div>
                <div class="stat-pill">
                    <div class="stat-dot"></div>
                    <span>Daily Logs</span>
                </div>
            </div>

        </div>
    </aside>

    <!-- ── RIGHT PANEL ────────────────────────────── -->
    <main class="right-panel">
        <div class="corner-accent"></div>

        <div class="login-card">
            <div class="card-header">
                <div class="card-eyebrow">Staff Portal</div>
                <h2>Welcome Back</h2>
                <p>Sign in to access your dashboard. Your role and permissions are applied automatically.</p>
            </div>

            <form id="loginForm" autocomplete="off" novalidate>

                <div class="field-group">
                    <!-- Username -->
                    <div class="field-wrap">
                        <label for="username">Username</label>
                        <div class="input-shell">
                            <input id="username" name="username" type="text"
                                   autocomplete="username" placeholder="Enter your username" required>
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
                            </svg>
                        </div>
                    </div>

                    <!-- Password -->
                    <div class="field-wrap">
                        <label for="password">Password</label>
                        <div class="input-shell">
                            <input id="password" name="password" type="password"
                                   autocomplete="current-password" placeholder="Enter your password" required>
                            <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                            </svg>
                            <button type="button" class="toggle-pass" id="togglePass" aria-label="Toggle password visibility">
                                <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" fill="none"
                                     viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" width="18" height="18">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Error -->
                <div id="errorMessage" class="error-message" role="alert">
                    <svg class="error-icon" xmlns="http://www.w3.org/2000/svg" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" width="16" height="16">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                    </svg>
                    <span id="errorText"></span>
                </div>

                <!-- Submit -->
                <button class="btn-submit" id="submitButton" type="submit">
                    <span class="btn-inner" id="btnInner">
                        Sign In
                    </span>
                </button>

            </form>

            <div class="login-footer">
                <div class="footer-line"></div>
                <span class="footer-note">Role detected automatically upon sign in</span>
                <div class="footer-line"></div>
            </div>

        </div>
    </main>

    <script>
        const loginForm     = document.getElementById('loginForm');
        const errorMessage  = document.getElementById('errorMessage');
        const errorText     = document.getElementById('errorText');
        const submitButton  = document.getElementById('submitButton');
        const btnInner      = document.getElementById('btnInner');
        const togglePass    = document.getElementById('togglePass');
        const passwordInput = document.getElementById('password');
        const eyeIcon       = document.getElementById('eyeIcon');

        // ── Password visibility toggle ──────────────────
        const eyeOpen = `<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>`;
        const eyeSlash = `<path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/>`;

        togglePass.addEventListener('click', () => {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            eyeIcon.innerHTML  = isHidden ? eyeSlash : eyeOpen;
        });

        // ── Error display ───────────────────────────────
        function showError(msg) {
            errorText.textContent = msg;
            errorMessage.classList.add('visible');
        }

        function clearError() {
            errorText.textContent = '';
            errorMessage.classList.remove('visible');
        }

        // ── Loading state ───────────────────────────────
        function setLoading(isLoading) {
            submitButton.disabled = isLoading;
            btnInner.innerHTML = isLoading
                ? 'Signing In <span class="spinner" aria-hidden="true"></span>'
                : 'Sign In';
        }

        // ── Form submit ─────────────────────────────────
        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            clearError();

            const username = document.getElementById('username').value.trim();
            const password = passwordInput.value;

            if (!username || !password) {
                showError('Please enter both your username and password.');
                return;
            }

            setLoading(true);

            try {
                const response = await fetch('api/auth/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password }),
                });

                const result = await response.json();

                if (!result.success) {
                    showError(result.message || 'Invalid username or password.');
                    setLoading(false);
                    return;
                }

                if (result.role === 'Admin') {
                    window.location.href = 'src/admin_dashboard.php';
                    return;
                }

                if (result.role === 'Staff') {
                    window.location.href = 'src/staff_dashboard.php';
                    return;
                }

                showError('Unable to determine your access role. Please contact your administrator.');

            } catch (err) {
                showError('Unable to connect to the server. Please try again later.');
            } finally {
                setLoading(false);
            }
        });
    </script>
</body>
</html>