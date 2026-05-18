## Role-Based Page Refactoring: Complete Guide

**Status:** Implementation Pattern Ready  
**Created:** May 17, 2026  
**Applies to:** All shared pages with Admin/Staff role variants  

---

## 1. Overview

This guide explains how to refactor duplicate role-based pages into single unified files using PHP conditionals and session-based role detection.

### Before (Current State)
- `calendar.php` (Admin version)
- `staff_calendar.php` (Staff version)
- `checkout.php` & `staff_checkout.php`
- `owner.php` & `staff_owner.php`
- `pets.php` & `staff_pets.php`
- `encode_reservation.php` & `staff_encode_reservation.php`
- **Result:** 12+ files with 90%+ code duplication

### After (Refactored)
- `calendar.php` (unified, serves both roles)
- `checkout.php` (unified)
- `owner.php` (unified)
- `pets.php` (unified)
- `encode_reservation.php` (unified)
- **Result:** 5 files, zero duplication, role enforcement via `$_SESSION['role']`

---

## 2. Session Guard & Helper Functions

### Location
```
config/rbac-helpers.php
```

### Functions Available

```php
// Check if user has specific role
can(string $requiredRole): bool
can('Admin')  // Returns true if user is Admin
can('Staff')  // Returns true if user is Staff

// Aliases
isAdmin(): bool   // Equivalent to can('Admin')
isStaff(): bool   // Equivalent to can('Staff')

// Get current role
getUserRole(): ?string  // Returns 'Admin', 'Staff', or null

// Enforce login (redirect if not logged in)
requireLogin(): void

// Enforce role access (redirect if user doesn't have allowed role)
requireRole(['Admin', 'Staff']): void  // Both roles allowed
requireRole(['Admin']): void            // Admin only
```

### Usage Pattern

At the **top of every shared page**, include:

```php
<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// SESSION GUARD
requireLogin();

$role = $_SESSION['role']; // 'Admin' or 'Staff'

// ... rest of page logic
?>
```

---

## 3. Conditional Rendering in HTML/UI

### Single Button/Action (Conditional Show/Hide)

```php
<!-- Visible to Admin only -->
<?php if (isAdmin()): ?>
    <button class="btn btn-danger" onclick="deleteBooking()">
        <i class="bi bi-trash"></i> Delete Booking
    </button>
<?php endif; ?>

<!-- Visible to Staff only -->
<?php if (isStaff()): ?>
    <button class="btn btn-primary" onclick="logDailyCare()">
        <i class="bi bi-journal-text"></i> Log Daily Care
    </button>
<?php endif; ?>

<!-- Visible to both roles -->
<?php if (can('Admin') || can('Staff')): ?>
    <button class="btn btn-secondary">
        <i class="bi bi-eye"></i> View Details
    </button>
<?php endif; ?>
```

### Entire Section (Role-Based Panel)

```php
<!-- Admin-only control panel -->
<?php if (isAdmin()): ?>
    <div class="panel">
        <div class="panel-title">
            <i class="bi bi-lock-fill"></i> Admin Controls
        </div>
        <p>Manage availability, pricing, and system settings.</p>
        <div class="btn-group">
            <button class="btn btn-primary">Block Dates</button>
            <button class="btn btn-primary">Edit Pricing</button>
            <button class="btn btn-danger">Delete Record</button>
        </div>
    </div>
<?php endif; ?>

<!-- Staff-only section -->
<?php if (isStaff()): ?>
    <div class="panel">
        <div class="panel-title">
            <i class="bi bi-clipboard-check"></i> Daily Tasks
        </div>
        <p>Log pet check-ins and care activities.</p>
        <div class="btn-group">
            <button class="btn btn-primary">Check-In</button>
            <button class="btn btn-primary">Check-Out</button>
        </div>
    </div>
<?php endif; ?>
```

### Role-Aware Navigation Menu

```php
<nav class="nav nav-pills">
    <?php if (isAdmin()): ?>
        <!-- Admin links -->
        <a href="admin_dashboard.php" class="nav-link">Dashboard</a>
        <a href="encode_reservation.php" class="nav-link">Schedule</a>
        <a href="user_management.php" class="nav-link">User Management</a>
    <?php elseif (isStaff()): ?>
        <!-- Staff links -->
        <a href="staff_dashboard.php" class="nav-link">Dashboard</a>
        <a href="staff_encode_reservation.php" class="nav-link">Schedule</a>
    <?php endif; ?>
</nav>
```

---

## 4. Design System Consistency

All refactored pages must use this exact design system (from `index.php`):

### Typography
```css
/* Headings: Bebas Neue */
h1, h2, h3, h4, h5, h6 {
    font-family: 'Bebas Neue', cursive;
    letter-spacing: 0.05em;
}

/* Body text: DM Sans */
body, p, a, button, input, label {
    font-family: 'DM Sans', sans-serif;
}
```

### Color Palette
```
--orange:       #FA8112  (primary accent, CTAs, highlights)
--orange-dark:  #d96a08  (hover state for orange)
--black:        #222222  (primary buttons, text, dark surfaces)
--beige-warm:   #FAF3E1  (page background)
--beige-gold:   #F5E7C6  (sidebar background, secondary surfaces)
--white:        #ffffff  (card backgrounds, inputs)
```

### Components

**Primary Button**
```css
.btn-primary {
    background: #222222;
    color: #ffffff;
    font-family: 'Bebas Neue', cursive;
    letter-spacing: 0.12em;
    border-radius: 12px;
    padding: 0.75rem 1.5rem;
    transition: all 0.2s;
    border: none;
}

.btn-primary:hover {
    background: #FA8112;
}
```

**Form Input**
```css
input, textarea, select {
    border: 1.5px solid #e2d9ce;
    border-radius: 12px;
    background: #ffffff;
    padding: 0.75rem 1rem;
    font-family: 'DM Sans', sans-serif;
}

input:focus, textarea:focus, select:focus {
    border-color: #FA8112;
    box-shadow: 0 0 0 3px rgba(250, 129, 18, 0.15);
    outline: none;
}
```

**Card/Panel**
```css
.panel {
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.16);
    border-radius: 20px;
    padding: 1.5rem;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.08);
}
```

**Section Label (Eyebrow)**
```css
.content-eyebrow {
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: #FA8112;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 1rem;
}

.content-eyebrow::before {
    content: '';
    width: 20px;
    height: 2px;
    background: #FA8112;
    display: inline-block;
}
```

### Google Fonts Import
Every page must include:
```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
```

---

## 5. Pages to Refactor & Role Assignments

### Module: RESERVATION

**Unified File:** `encode_reservation.php`  
**Replaces:** `encode_reservation.php` + `staff_encode_reservation.php`

| Action | Admin | Staff | Notes |
|--------|-------|-------|-------|
| View Reservations | ✓ | ✓ | Both can view scheduled bookings |
| Create Reservation | ✓ | ✓ | Both can encode new bookings |
| Edit Reservation (details) | ✓ | ✓ | Both roles, but differ on date/time changes |
| Change Check-In/Check-Out Dates | ✓ | ✗ | Admin only (impacts pricing/availability) |
| Adjust Pricing/Rate | ✓ | ✗ | Admin only (rate management) |
| Add Special Notes | ✓ | ✓ | Both can add internal notes |
| Mark Pending/Confirmed | ✓ | ✓ | Both manage status transitions |
| Delete Reservation | ✓ | ✗ | Admin only |
| View Booking History | ✓ | ✓ | View read-only history |

---

### Module: REGISTRATION / PET PROFILES

**Unified File:** `register_owner_pet.php` (if shared) or split pattern  
**Replaces:** Variants if they exist

| Action | Admin | Staff | Notes |
|--------|-------|-------|-------|
| View Owner Profile | ✓ | ✓ | Common read access |
| Edit Owner Contact Info | ✓ | ✓ | Common edit |
| Register New Owner | ✓ | ✓ | Both can register |
| View Pet Profiles | ✓ | ✓ | Common read access |
| Register New Pet | ✓ | ✓ | Both can register |
| Edit Pet Dietary/Behavioral Notes | ✓ | ✓ | Common edit |
| Upload Pet Documents (vaccines, etc.) | ✓ | ✓ | Common |
| Delete Pet Profile | ✓ | ✗ | Admin only (system management) |
| Archive Owner | ✓ | ✗ | Admin only |

**Files to refactor:**
- `owner.php` (unified: show both `owner.php` + `staff_owner.php` logic)
- `pets.php` (unified: show both `pets.php` + `staff_pets.php` logic)
- `pet_profile.php` (if duplicated)
- `owner_profile.php` (if duplicated)

---

### Module: DAILY CARE LOGGING

**Current State:** Pages like `calendar.php` with check-in/check-out buttons  
**Unified File:** Incorporate into `calendar.php` (already done in example)

| Action | Admin | Staff | Notes |
|--------|-------|-------|-------|
| View Calendar/Schedule | ✓ | ✓ | Both see bookings |
| Block Dates | ✓ | ✗ | Admin controls availability |
| Mark Pet Check-In | ✓ | ✓ | Both can mark arrival |
| Mark Pet Check-Out | ✓ | ✓ | Both can mark departure |
| Log Daily Care (feeding, notes) | ✓ | ✓ | Both log activities |
| View Care Logs | ✓ | ✓ | Both can read logs |
| Edit Past Care Logs | ✓ | ✗ | Admin only (prevents data tampering) |
| Assign Staff to Pet | ✓ | ✗ | Admin only (scheduling) |

---

### Module: TRANSACTION / PAYMENT

**Unified File:** `checkout.php`  
**Replaces:** `checkout.php` + `staff_checkout.php`

| Action | Admin | Staff | Notes |
|--------|-------|-------|-------|
| View Booking Invoice | ✓ | ✓ | Both see charges |
| Calculate Final Cost | ✓ | ✓ | Both can generate totals |
| Apply Discount/Promo | ✓ | ✗ | Admin only (business rules) |
| Process Payment | ✓ | ✓ | Both can accept payment |
| Refund/Cancel Payment | ✓ | ✗ | Admin only (financial control) |
| Generate Receipt | ✓ | ✓ | Both can print/email |
| View Transaction History | ✓ | ✓ | Both can audit |
| Export Payment Report | ✓ | ✗ | Admin only (financial reporting) |

---

### Module: REPORTS

**Unified File:** `booking_verification.php` (if contains reports) or new shared page  
**Current State:** Likely Admin-only; expand to shared view with role-based export

| Action | Admin | Staff | Notes |
|--------|-------|-------|-------|
| View Booking Details | ✓ | ✓ | Both see booking info |
| Generate Booking Report | ✓ | ✓ | Both can view summary |
| Export to CSV/PDF | ✓ | ✗ | Admin only (data governance) |
| View Revenue Report | ✓ | ✗ | Admin only |
| View Occupancy Stats | ✓ | ✓ | Both see kennel utilization |
| View Staff Activity Log | ✓ | ✗ | Admin only |

---

## 6. Migration Checklist

For each page being refactored:

- [ ] **Create new unified file** (e.g., `calendar-unified.php`)
- [ ] **Add session guard** at top:
  ```php
  require_once '../config/rbac-helpers.php';
  requireLogin();
  $role = $_SESSION['role'];
  ```
- [ ] **Fetch shared data** (queries that work for both roles)
- [ ] **Add role-aware sidebar** with conditional `if (isAdmin())` / `if (isStaff())`
- [ ] **Wrap role-specific buttons** in `<?php if (isAdmin()): ?>` blocks
- [ ] **Wrap role-specific panels** in conditional divs
- [ ] **Apply design system CSS** (Google Fonts, color vars, border-radius)
- [ ] **Test both role paths** (login as Admin, then as Staff)
- [ ] **Verify navigation links** point to unified file, not split files
- [ ] **Keep old files** temporarily for reference, then delete
- [ ] **Update index.php and dashboards** to link to unified file only
- [ ] **Test all role-based buttons** to ensure they hide/show correctly

---

## 7. Code Template: Refactored Page Skeleton

Use this as a starting point for any shared page:

```php
<?php
session_start();
require_once '../config/db.php';
require_once '../config/rbac-helpers.php';

// ════════════════════════════════════════════════════════════════════════════════
// SESSION GUARD
// ════════════════════════════════════════════════════════════════════════════════
requireLogin();
$role = $_SESSION['role']; // 'Admin' or 'Staff'

// ════════════════════════════════════════════════════════════════════════════════
// FETCH DATA (same for both roles)
// ════════════════════════════════════════════════════════════════════════════════
$query = "SELECT * FROM TABLE WHERE ...";
$stmt = $pdo->query($query);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Title - Radog's Pet Hotel</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    
    <style>
        /* Include design system colors and components */
        :root {
            --orange: #FA8112;
            --black: #222222;
            --beige-warm: #FAF3E1;
            --beige-gold: #F5E7C6;
            --radius-btn: 12px;
            --radius-card: 20px;
        }
        /* ... more CSS here ... */
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <!-- SIDEBAR (role-aware) -->
        <aside class="sidebar">
            <nav class="nav">
                <?php if (isAdmin()): ?>
                    <a href="admin_dashboard.php" class="nav-link">Dashboard</a>
                <?php elseif (isStaff()): ?>
                    <a href="staff_dashboard.php" class="nav-link">Dashboard</a>
                <?php endif; ?>
            </nav>
        </aside>

        <!-- MAIN CONTENT -->
        <main class="main-content">
            <!-- Shared content for both roles -->
            <h1>Page Title</h1>
            <p>Shared description here.</p>

            <!-- Admin-only section -->
            <?php if (isAdmin()): ?>
                <div class="panel">
                    <h2>Admin-Only Controls</h2>
                    <button class="btn btn-primary">Admin Action</button>
                </div>
            <?php endif; ?>

            <!-- Staff-only section -->
            <?php if (isStaff()): ?>
                <div class="panel">
                    <h2>Staff-Only Operations</h2>
                    <button class="btn btn-primary">Staff Action</button>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
```

---

## 8. Testing Checklist

### For Each Refactored Page:

#### As Admin:
- [ ] All shared content displays
- [ ] Admin-only buttons/sections visible
- [ ] Staff-only buttons/sections hidden
- [ ] Navigation shows admin links (admin_dashboard, user_management, etc.)
- [ ] All admin buttons are functional

#### As Staff:
- [ ] All shared content displays
- [ ] Staff-only buttons/sections visible
- [ ] Admin-only buttons/sections hidden
- [ ] Navigation shows staff links (staff_dashboard, no user_management, etc.)
- [ ] All staff buttons are functional

#### Security:
- [ ] Logged-out users redirect to index.php
- [ ] Direct URL access without session redirects to index.php
- [ ] Role-based actions can't be invoked via URL manipulation

---

## 9. Example: Before & After

### BEFORE (Two Files)

**calendar.php (Admin)**
```php
// ... identical code repeated ...
<a href="admin_dashboard.php" class="nav-link">Dashboard</a>
<a href="user_management.php" class="nav-link">User Management</a>
<button onclick="deleteBooking()">Delete Booking</button>
```

**staff_calendar.php (Staff)**
```php
// ... identical code repeated ...
<a href="staff_dashboard.php" class="nav-link">Dashboard</a>
<!-- no user_management link -->
<!-- no delete button -->
```

### AFTER (One File)

**calendar.php (Unified)**
```php
<?php if (isAdmin()): ?>
    <a href="admin_dashboard.php" class="nav-link">Dashboard</a>
    <a href="user_management.php" class="nav-link">User Management</a>
<?php elseif (isStaff()): ?>
    <a href="staff_dashboard.php" class="nav-link">Dashboard</a>
<?php endif; ?>

<?php if (isAdmin()): ?>
    <button onclick="deleteBooking()">Delete Booking</button>
<?php endif; ?>
```

**Result:** 50% code reduction, single source of truth, easier maintenance.

---

## 10. Future Maintenance

### Adding a New Shared Page:
1. Copy the template skeleton above
2. Add your data queries (same for both roles)
3. Wrap role-specific UI in `<?php if (isAdmin()): ?>` blocks
4. Test as both roles
5. Deploy and remove old variant files

### Modifying Existing Shared Pages:
1. Edit the unified file only
2. Changes apply to both roles automatically
3. No need to edit two separate files

### Adding New Role-Specific Buttons:
1. Open unified file
2. Find the appropriate `<?php if (isAdmin()): ?>` or `<?php if (isStaff()): ?>` block
3. Add button inside that block
4. No new page files needed

---

## Summary

This refactoring pattern:
- ✅ Eliminates code duplication (50-90% reduction)
- ✅ Enforces role-based access control server-side via session
- ✅ Maintains consistent UI design system from index.php
- ✅ Makes feature additions/changes simple (edit one file, not two)
- ✅ Reduces maintenance burden and bug surface area
- ✅ Improves security through centralized access control

**Next Steps:**
1. Use `config/rbac-helpers.php` in all shared pages
2. Refactor pages in order: Calendar → Checkout → Owner → Pets → Reservations
3. Delete old variant files once fully migrated
4. Update all navigation links to point to unified files
