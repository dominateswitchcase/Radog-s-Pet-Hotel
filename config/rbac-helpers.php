<?php
/**
 * RBAC (Role-Based Access Control) Helper Functions
 * 
 * Centralized location for all role-checking logic used across shared pages.
 * Include this at the top of every shared page that needs role-based rendering.
 * 
 * Usage:
 *   require_once '../config/rbac-helpers.php';
 *   if (can('Admin')) { ... }
 */

/**
 * Check if the logged-in user has a specific role.
 * 
 * @param string $requiredRole 'Admin' or 'Staff'
 * @return bool True if user's role matches, false otherwise
 * 
 * Example:
 *   <?php if (can('Admin')): ?>
 *       <button>Delete Booking</button>
 *   <?php endif; ?>
 */
function can(string $requiredRole): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === $requiredRole;
}

/**
 * Alias for can() — checks if user has Admin role.
 * 
 * @return bool
 */
function isAdmin(): bool {
    return can('Admin');
}

/**
 * Alias for can() — checks if user has Staff role.
 * 
 * @return bool
 */
function isStaff(): bool {
    return can('Staff');
}

/**
 * Get the logged-in user's role (or null if not logged in).
 * 
 * @return string|null 'Admin', 'Staff', or null
 */
function getUserRole(): ?string {
    return $_SESSION['role'] ?? null;
}

/**
 * Enforce role access — redirect to index.php if not logged in.
 * Call this at the top of every shared page.
 * 
 * Usage:
 *   requireLogin();
 */
function requireLogin(): void {
    if (!isset($_SESSION['account_id'], $_SESSION['role'])) {
        header('Location: ../index.php');
        exit;
    }
}

/**
 * Enforce role access — redirect if user doesn't have one of the allowed roles.
 * 
 * @param array $allowedRoles e.g., ['Admin', 'Staff'] or ['Admin']
 * @param string $redirectTo e.g., '../index.php'
 * 
 * Usage:
 *   requireRole(['Admin', 'Staff']); // Both roles allowed
 *   requireRole(['Admin']);          // Admin only
 */
function requireRole(array $allowedRoles, string $redirectTo = '../index.php'): void {
    requireLogin();
    
    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        header('Location: ' . $redirectTo);
        exit;
    }
}
