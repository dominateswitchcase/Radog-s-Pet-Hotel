<?php
session_start();
require_once '../config/db.php';

// RBAC: Ensure authorized access
if (!isset($_SESSION['account_id'])) {
    header("Location: employee_login.php");
    exit();
}

// Get Pet ID from URL
$pet_id = $_GET['id'] ?? null;

if (!$pet_id) {
    header("Location: pets.php");
    exit();
}

// Fetch Pet details with Owner and Category information
$query = "SELECT P.PET_NAME, P.WEIGHT, P.SEX, P.FEEDING_TIME, P.FEEDING_PORTION, 
                 P.BEHAVIORAL_NOTES, O.FIRST_NAME, O.LAST_NAME, C.CATEGORY_NAME
          FROM PET P
          JOIN OWNER O ON P.OWNER_ID = O.OWNER_ID
          JOIN PET_CATEGORY C ON P.CATEGORY_ID = C.CATEGORY_ID
          WHERE P.PET_ID = :pid";

$stmt = $pdo->prepare($query);
$stmt->execute(['pid' => $pet_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet) {
    die("Pet profile not found.");
}

// Logic for Pricing Tier based on weight
$weight = (float)$pet['WEIGHT'];
if ($weight <= 10) $tier = "Small";
elseif ($weight <= 26) $tier = "Medium";
elseif ($weight <= 45) $tier = "Large";
else $tier = "Giant";

$tier_colors = [
    'Small'  => ['bg' => 'rgba(34,197,94,0.1)',  'color' => '#15803d'],
    'Medium' => ['bg' => 'rgba(59,130,246,0.1)',  'color' => '#1d4ed8'],
    'Large'  => ['bg' => 'rgba(250,129,18,0.1)',  'color' => '#c05f00'],
    'Giant'  => ['bg' => 'rgba(168,85,247,0.1)',  'color' => '#7e22ce'],
];
$tier_style = $tier_colors[$tier] ?? $tier_colors['Small'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pet['PET_NAME']); ?>'s Profile – Radog's Pet Hotel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <style>
        /* ── CSS Variables ───────────────────────────────────── */
        :root {
            --orange:      #FA8112;
            --orange-dk:   #d96a08;
            --black:       #222222;
            --beige:       #FAF3E1;
            --gold:        #F5E7C6;
            --white:       #ffffff;
            --radius-card:  20px;
            --radius-input: 12px;
            --radius-btn:   12px;
            --shadow-card:  0 24px 70px rgba(15, 23, 42, 0.08);
            --border-soft:  1px solid rgba(34, 34, 34, 0.08);
        }

        /* ── Reset & Base ────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--beige);
            min-height: 100vh;
            color: var(--black);
        }
        h1, h2, h3, h4, h5 { font-family: 'Bebas Neue', sans-serif; letter-spacing: 0.05em; }
        a { text-decoration: none; }

        /* ── Animation ───────────────────────────────────────── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Top Bar ─────────────────────────────────────────── */
        .top-bar {
            background-color: var(--black);
            background-image: repeating-linear-gradient(
                -55deg, transparent, transparent 18px,
                rgba(250,129,18,0.04) 18px, rgba(250,129,18,0.04) 19px
            );
            padding: 14px 44px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            border-bottom: 1px solid rgba(250,129,18,0.12);
        }
        .top-bar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .top-bar-brand img {
            width: 38px;
            height: 38px;
            object-fit: contain;
            filter: drop-shadow(0 0 8px rgba(250,129,18,0.5));
        }
        .top-bar-wordmark {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.25rem;
            color: var(--orange);
            letter-spacing: 0.06em;
            text-shadow: 0 0 18px rgba(250,129,18,0.3);
        }
        .top-bar-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 18px;
            border-radius: var(--radius-btn);
            border: 1.5px solid rgba(245,231,198,0.2);
            color: rgba(245,231,198,0.8);
            font-size: 0.88rem;
            font-weight: 500;
            transition: border-color 0.18s, color 0.18s, background 0.18s;
        }
        .back-link svg { width: 15px; height: 15px; }
        .back-link:hover { border-color: var(--orange); color: var(--white); background: rgba(250,129,18,0.1); }

        /* ── Page Wrapper ────────────────────────────────────── */
        .page-wrapper {
            max-width: 980px;
            margin: 0 auto;
            padding: 48px 24px 64px;
            animation: fadeUp 0.8s cubic-bezier(0.16, 1, 0.3, 1) 0.1s both;
        }

        /* ── Hero Header ─────────────────────────────────────── */
        .profile-hero {
            display: flex;
            align-items: flex-end;
            gap: 28px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .hero-avatar {
            width: 96px;
            height: 96px;
            border-radius: 24px;
            background: var(--orange);
            background-image: repeating-linear-gradient(
                -55deg, transparent, transparent 10px,
                rgba(255,255,255,0.08) 10px, rgba(255,255,255,0.08) 11px
            );
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 12px 40px rgba(250,129,18,0.3);
        }
        .hero-avatar svg { width: 44px; height: 44px; color: rgba(255,255,255,0.9); }
        .hero-meta { flex-grow: 1; }
        .page-eyebrow {
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: var(--orange);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }
        .page-eyebrow::before {
            content: '';
            display: block;
            width: 20px;
            height: 2px;
            background: var(--orange);
            border-radius: 99px;
        }
        .page-title {
            font-size: 2.8rem;
            color: var(--black);
            line-height: 1;
            margin-bottom: 8px;
        }
        .page-subtitle {
            font-size: 0.95rem;
            color: rgba(34,34,34,0.5);
        }
        .tier-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 99px;
            font-size: 0.82rem;
            font-weight: 600;
            margin-top: 10px;
        }
        .tier-dot { width: 7px; height: 7px; border-radius: 50%; }

        /* ── Grid ────────────────────────────────────────────── */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        /* ── Panel ───────────────────────────────────────────── */
        .panel {
            background: var(--white);
            border: var(--border-soft);
            border-radius: var(--radius-card);
            padding: 28px;
            box-shadow: var(--shadow-card);
        }
        .panel-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(34,34,34,0.06);
        }
        .panel-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(250,129,18,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .panel-icon svg { width: 18px; height: 18px; color: var(--orange); }
        .panel-heading {
            font-family: 'Bebas Neue', sans-serif;
            font-size: 1.2rem;
            color: var(--black);
            letter-spacing: 0.05em;
        }

        /* Info rows */
        .info-row {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-bottom: 16px;
        }
        .info-row:last-child { margin-bottom: 0; }
        .info-label {
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: rgba(34,34,34,0.4);
        }
        .info-value {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--black);
        }

        /* Compliance panel */
        .doc-list { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
        .doc-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--beige);
            border: 1px solid rgba(34,34,34,0.06);
            font-size: 0.88rem;
            color: rgba(34,34,34,0.7);
            transition: background 0.18s;
        }
        .doc-item svg { width: 16px; height: 16px; color: rgba(34,34,34,0.3); flex-shrink: 0; }

        /* Custom checkbox */
        .doc-checkbox {
            width: 18px;
            height: 18px;
            border-radius: 5px;
            border: 1.5px solid #e2d9ce;
            appearance: none;
            cursor: not-allowed;
            flex-shrink: 0;
            background: var(--white);
            transition: background 0.15s, border-color 0.15s;
        }
        .doc-checkbox:checked {
            background: var(--orange);
            border-color: var(--orange);
        }

        /* View docs button */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            border: none;
            border-radius: var(--radius-btn);
            font-family: 'Bebas Neue', sans-serif;
            font-size: 0.95rem;
            letter-spacing: 0.12em;
            cursor: pointer;
            transition: background 0.18s, transform 0.15s, box-shadow 0.15s;
        }
        .btn:hover { transform: translateY(-1px); }
        .btn svg { width: 16px; height: 16px; }
        .btn-primary { background: var(--black); color: var(--white); width: 100%; justify-content: center; }
        .btn-primary:hover { background: var(--orange); box-shadow: 0 6px 20px rgba(250,129,18,0.3); }

        /* Behavioral notes textarea */
        .notes-textarea {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #e2d9ce;
            border-radius: var(--radius-input);
            background: var(--beige);
            color: var(--black);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.95rem;
            resize: none;
            cursor: default;
            outline: none;
            line-height: 1.6;
        }

        /* Compliance note */
        .compliance-note {
            font-size: 0.82rem;
            color: rgba(34,34,34,0.45);
            margin-bottom: 14px;
            line-height: 1.5;
        }

        /* Divider between docs toggle and list */
        .doc-toggle-wrap { display: flex; flex-direction: column; gap: 0; }
        #documentCheckboxes { animation: fadeUp 0.25s ease both; }

        /* ── Responsive ──────────────────────────────────────── */
        @media (max-width: 900px) {
            .top-bar { padding: 12px 20px; }
            .page-wrapper { padding: 32px 16px 48px; }
            .cards-grid { grid-template-columns: 1fr; }
            .profile-hero { gap: 16px; }
            .page-title { font-size: 2rem; }
        }
        @media (max-width: 600px) {
            .cards-grid { grid-template-columns: 1fr; }
            .hero-avatar { width: 72px; height: 72px; border-radius: 18px; }
            .hero-avatar svg { width: 32px; height: 32px; }
        }
    </style>
</head>
<body>

<!-- ══════════════════════════════════════════════════════
     TOP BAR
══════════════════════════════════════════════════════ -->
<header class="top-bar">
    <div class="top-bar-brand">
        <img src="../img/radog_logo.png" alt="Radog's Kennel">
        <span class="top-bar-wordmark">Radog's Kennel</span>
    </div>
    <div class="top-bar-right">
        <a href="pets.php" class="back-link">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            Back to Pets
        </a>
    </div>
</header>

<!-- ══════════════════════════════════════════════════════
     PAGE CONTENT
══════════════════════════════════════════════════════ -->
<div class="page-wrapper">

    <!-- Hero Header -->
    <div class="profile-hero">
        <div class="hero-avatar">
            <?php if (strtolower($pet['CATEGORY_NAME']) === 'cat'): ?>
            <!-- Cat icon -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5c-3.87 0-7 3.13-7 7 0 2.76 1.59 5.14 3.93 6.35C9.59 18.79 10.78 19 12 19s2.41-.21 3.07-.65C17.41 17.14 19 14.76 19 12c0-3.87-3.13-7-7-7z"/><path d="M5 5 3 3"/><path d="M19 5l2-2"/><path d="M9 12h.01M15 12h.01"/><path d="M9.5 16s.83.5 2.5.5 2.5-.5 2.5-.5"/></svg>
            <?php else: ?>
            <!-- Paw / dog icon -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="4" r="2"/><circle cx="18" cy="8" r="2"/><circle cx="20" cy="16" r="2"/><path d="M9 10a5 5 0 0 1 5 5v3.5a3.5 3.5 0 0 1-6.84 1.045Q6.52 17.48 4.46 16.84A3.5 3.5 0 0 1 5.5 10Z"/></svg>
            <?php endif; ?>
        </div>
        <div class="hero-meta">
            <div class="page-eyebrow">Pet Profile</div>
            <h1 class="page-title"><?php echo htmlspecialchars($pet['PET_NAME']); ?></h1>
            <p class="page-subtitle">
                <?php echo htmlspecialchars($pet['CATEGORY_NAME']); ?> &mdash;
                Owner: <strong><?php echo htmlspecialchars($pet['FIRST_NAME'] . ' ' . $pet['LAST_NAME']); ?></strong>
            </p>
            <span class="tier-pill" style="background:<?php echo $tier_style['bg']; ?>; color:<?php echo $tier_style['color']; ?>;">
                <span class="tier-dot" style="background:<?php echo $tier_style['color']; ?>;"></span>
                <?php echo $tier; ?> Tier
            </span>
        </div>
    </div>

    <!-- Cards Grid -->
    <div class="cards-grid">

        <!-- Basic Information -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <span class="panel-heading">Basic Info</span>
            </div>
            <div class="info-row">
                <span class="info-label">Sex</span>
                <span class="info-value"><?php echo htmlspecialchars($pet['SEX'] ?: '—'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Weight</span>
                <span class="info-value"><?php echo htmlspecialchars($pet['WEIGHT']); ?> kg</span>
            </div>
            <div class="info-row">
                <span class="info-label">Pricing Tier</span>
                <span class="info-value">
                    <span class="tier-pill" style="background:<?php echo $tier_style['bg']; ?>; color:<?php echo $tier_style['color']; ?>; padding:4px 10px; font-size:0.78rem;">
                        <span class="tier-dot" style="background:<?php echo $tier_style['color']; ?>;"></span>
                        <?php echo $tier; ?>
                    </span>
                </span>
            </div>
            <div class="info-row">
                <span class="info-label">Owner</span>
                <span class="info-value"><?php echo htmlspecialchars($pet['FIRST_NAME'] . ' ' . $pet['LAST_NAME']); ?></span>
            </div>
        </div>

        <!-- Feeding Instructions -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                </div>
                <span class="panel-heading">Feeding</span>
            </div>
            <div class="info-row">
                <span class="info-label">Preferred Time</span>
                <span class="info-value"><?php echo htmlspecialchars($pet['FEEDING_TIME'] ?: '—'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Portion Size</span>
                <span class="info-value"><?php echo htmlspecialchars($pet['FEEDING_PORTION'] ?: '—'); ?></span>
            </div>
        </div>

        <!-- Compliance Vault -->
        <div class="panel">
            <div class="panel-header">
                <div class="panel-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <span class="panel-heading">Compliance Vault</span>
            </div>
            <p class="compliance-note">Verify vet cards and vaccination status before confirming bookings.</p>
            <div class="doc-toggle-wrap">
                <button class="btn btn-primary" id="viewDocumentsBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    View Documents
                </button>
                <div id="documentCheckboxes" class="doc-list" style="display: none;">
                    <label class="doc-item">
                        <input class="doc-checkbox" type="checkbox" id="regForm" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h4"/></svg>
                        Pet Physical Registration Form
                    </label>
                    <label class="doc-item">
                        <input class="doc-checkbox" type="checkbox" id="waiverForm" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                        Waiver and Consent Form
                    </label>
                    <label class="doc-item">
                        <input class="doc-checkbox" type="checkbox" id="vaccCard" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        Complete &amp; Updated Vaccination Card (Vet Card)
                    </label>
                </div>
            </div>
        </div>

    </div><!-- /cards-grid -->

    <!-- Behavioral Notes (full width) -->
    <div class="panel">
        <div class="panel-header">
            <div class="panel-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </div>
            <span class="panel-heading">Behavioral &amp; Handling Notes</span>
        </div>
        <textarea class="notes-textarea" rows="4" readonly><?php echo htmlspecialchars($pet['BEHAVIORAL_NOTES'] ?? 'No behavioral notes recorded.'); ?></textarea>
    </div>

</div><!-- /page-wrapper -->

<script>
document.getElementById('viewDocumentsBtn').addEventListener('click', function () {
    const checkboxes = document.getElementById('documentCheckboxes');
    const isHidden = checkboxes.style.display === 'none';
    checkboxes.style.display = isHidden ? 'flex' : 'none';
    checkboxes.style.flexDirection = 'column';
    this.innerHTML = isHidden
        ? `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg> Hide Documents`
        : `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg> View Documents`;
});
</script>

</body>
</html>
