<?php
/**
 * RBAC (Role-Based Access Control) Helper Functions
 * config/rbac-helpers.php
 */

// ── Internal normaliser ─────────────────────────────────────────────────────
function _getRole(): string {
    return strtolower(trim($_SESSION['role'] ?? ''));
}

// ── Boolean role checks (for conditional UI rendering) ──────────────────────
function can(string $requiredRole): bool {
    return isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) === strtolower(trim($requiredRole));
}

function isAdmin(): bool { return can('Admin'); }
function isStaff(): bool { return can('Staff'); }

function getUserRole(): ?string {
    return $_SESSION['role'] ?? null;
}

function currentRole(): string {
    return match(_getRole()) {
        'admin' => 'Admin',
        'staff' => 'Staff',
        default => htmlspecialchars($_SESSION['role'] ?? 'Unknown'),
    };
}

// ── Access guards (call at the top of pages, redirects on failure) ──────────

/**
 * Any logged-in user. Redirects guests to login.
 * Use on shared pages (calendar, owners, pets, etc.)
 */
function requireLogin(): void {
    if (!isset($_SESSION['account_id'], $_SESSION['role'])) {
        header('Location: ../index.php');
        exit;
    }
}

/**
 * Admin only. Redirects Staff to their dashboard, guests to login.
 * Use on: admin_dashboard.php, user_management.php
 */
function requireAdmin(): void {
    if (!isset($_SESSION['account_id'], $_SESSION['role'])) {
        header('Location: ../index.php');
        exit;
    }
    if (_getRole() !== 'admin') {
        header('Location: staff_dashboard.php');
        exit;
    }
}

/**
 * Staff only. Redirects Admin to their dashboard, guests to login.
 * Use on: staff_dashboard.php
 */
function requireStaff(): void {
    if (!isset($_SESSION['account_id'], $_SESSION['role'])) {
        header('Location: ../index.php');
        exit;
    }
    if (_getRole() !== 'staff') {
        header('Location: admin_dashboard.php');
        exit;
    }
}

/**
 * Generic role guard. Kept for backwards compatibility.
 * Prefer requireAdmin() or requireStaff() for clarity.
 */
function requireRole(array $allowedRoles, string $redirectTo = '../index.php'): void {
    requireLogin();
    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        header('Location: ' . $redirectTo);
        exit;
    }
}