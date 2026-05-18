## Role-Based Pages: Quick Start Guide

**Status:** Ready to Implement  
**Created:** May 17, 2026  
**Author:** GitHub Copilot (Refactoring Recommendation)

---

## TL;DR: What You Have

### New Helper File
📄 `config/rbac-helpers.php` — Centralized role-checking functions

```php
require_once '../config/rbac-helpers.php';

requireLogin();                    // Ensures user is logged in
can('Admin')                       // Boolean check
isAdmin() / isStaff()              // Alias functions
requireRole(['Admin', 'Staff'])    // Enforce specific roles
```

### Example 1: Calendar (Shared UI)
📄 `src/calendar-unified.php` — Shows:
- ✅ Session guard at top
- ✅ Role-aware sidebar navigation
- ✅ Shared calendar view (both roles)
- ✅ Admin-only controls (delete, block dates)
- ✅ Staff-only controls (check-in, daily log)
- ✅ Design system CSS (Bebas Neue, DM Sans, #FA8112)

### Example 2: Checkout (Business Logic + UI)
📄 `src/checkout-unified-example.php` — Shows:
- ✅ Role-based data processing (e.g., Admin can apply discount)
- ✅ Conditional form rendering
- ✅ Permission-based buttons

### Comprehensive Documentation
📄 `REFACTORING_GUIDE.md` — Full reference including:
- ✅ All pages to refactor + role assignments by action
- ✅ Module-by-module breakdown (Reservation, Registration, Daily Care, Transactions, Reports)
- ✅ Migration checklist
- ✅ Testing procedures
- ✅ Code template

---

## Implementation Steps

### Step 1: Prepare Environment

✅ Already done:
- `config/rbac-helpers.php` created with 6 helper functions
- `src/calendar-unified.php` created as working example
- `src/checkout-unified-example.php` created as business-logic example
- `REFACTORING_GUIDE.md` created with full specifications

### Step 2: Update Current Page (calendar.php → unified)

Replace the old `calendar.php` and `staff_calendar.php` with `calendar-unified.php`:

```bash
# Option A: Use the pre-built version
cp src/calendar-unified.php src/calendar.php

# Option B: Update your existing calendar.php manually
# See REFACTORING_GUIDE.md for the pattern
```

**Test:**
- Login as Admin → verify Admin controls visible, staff controls hidden
- Login as Staff → verify Staff controls visible, admin controls hidden

### Step 3: Refactor Other Pages (in priority order)

#### Priority 1: Checkout
- Use `checkout-unified-example.php` as template
- Handles role-based business logic (Admin-only discount/refund)
- Test both role paths

#### Priority 2: Calendar (already done)

#### Priority 3: Owner / Pets
- Simpler pages (no complex business logic)
- Use same pattern as calendar example
- Actions: owner.php + staff_owner.php → owner.php
- Actions: pets.php + staff_pets.php → pets.php

#### Priority 4: Schedule / Reservations
- More complex logic
- Use checkout-unified-example.php as template for business logic
- Actions: encode_reservation.php + staff_encode_reservation.php → encode_reservation.php

---

## Code Pattern: Copy-Paste Template

Use this for any new shared page:

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
// FETCH DATA
// ════════════════════════════════════════════════════════════════════════════════
$query = "SELECT * FROM TABLE";
$data = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Handle POST actions (role-aware)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    
    // ✓ Both roles can do this
    if ($action === 'view_details') {
        // shared logic
    }
    
    // ✗ Admin only
    if ($action === 'delete_record' && isAdmin()) {
        // admin-only logic
    }
    
    // ✗ Staff only
    if ($action === 'log_activity' && isStaff()) {
        // staff-only logic
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Title - Radog's Pet Hotel</title>
    
    <!-- REQUIRED: Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="../assets/css/custom.css" rel="stylesheet">
    
    <style>
        /* Design System Colors */
        :root {
            --orange: #FA8112;
            --black: #222222;
            --beige-warm: #FAF3E1;
            --beige-gold: #F5E7C6;
        }
        
        h1, h2, h3 { font-family: 'Bebas Neue', cursive; letter-spacing: 0.05em; }
        body, p, button, input { font-family: 'DM Sans', sans-serif; }
    </style>
</head>
<body>
    <div class="dashboard-wrapper" style="display: flex; min-height: 100vh;">
        <!-- SIDEBAR: Role-aware nav -->
        <aside class="sidebar" style="width: 280px; background: var(--beige-gold); padding: 1.5rem;">
            <nav class="nav">
                <?php if (isAdmin()): ?>
                    <a href="admin_dashboard.php" class="nav-link">Dashboard</a>
                    <a href="user_management.php" class="nav-link">User Management</a>
                <?php elseif (isStaff()): ?>
                    <a href="staff_dashboard.php" class="nav-link">Dashboard</a>
                <?php endif; ?>
            </nav>
        </aside>

        <!-- MAIN CONTENT: Role-aware UI -->
        <main style="flex-grow: 1; padding: 2rem;">
            <h1>Page Title</h1>
            <p>Shared description here</p>
            
            <!-- ✓ SHARED: Both roles see this -->
            <div>
                <button>View Details</button>
            </div>
            
            <!-- ✗ ADMIN ONLY -->
            <?php if (isAdmin()): ?>
                <div style="margin-top: 2rem;">
                    <h2>Admin Controls</h2>
                    <button onclick="deleteRecord()">Delete</button>
                    <button onclick="applyDiscount()">Apply Discount</button>
                </div>
            <?php endif; ?>
            
            <!-- ✗ STAFF ONLY -->
            <?php if (isStaff()): ?>
                <div style="margin-top: 2rem;">
                    <h2>Staff Tasks</h2>
                    <button onclick="logActivity()">Log Activity</button>
                    <button onclick="checkIn()">Check-In</button>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
```

---

## Working Examples Reference

### Example 1: Simple Button Visibility
```php
<?php if (isAdmin()): ?>
    <button class="btn btn-danger">Delete Record</button>
<?php endif; ?>
```

### Example 2: Different Navigation Per Role
```php
<?php if (isAdmin()): ?>
    <a href="user_management.php">User Management</a>
<?php elseif (isStaff()): ?>
    <!-- Staff doesn't see this -->
<?php endif; ?>
```

### Example 3: Role-Based Form Processing
```php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    
    // Both roles
    if ($action === 'view') {
        $result = $pdo->query("SELECT * FROM bookings");
    }
    
    // Admin only
    if ($action === 'delete' && isAdmin()) {
        $pdo->query("DELETE FROM bookings WHERE id = ?");
    }
}
```

### Example 4: Entire Section for One Role
```php
<?php if (isAdmin()): ?>
    <div class="admin-panel">
        <h3>Admin Configuration</h3>
        <p>Change pricing, block dates, manage holidays</p>
        <button>Block Dates</button>
        <button>Edit Pricing</button>
    </div>
<?php endif; ?>
```

---

## Migration Checklist (Per Page)

- [ ] Copy template to page file
- [ ] Add `require_once '../config/rbac-helpers.php'` at top
- [ ] Add `requireLogin()` after session_start
- [ ] Split navigation: `if (isAdmin()) { ... } elseif (isStaff()) { ... }`
- [ ] Wrap Admin buttons: `<?php if (isAdmin()): ?> ... <?php endif; ?>`
- [ ] Wrap Staff buttons: `<?php if (isStaff()): ?> ... <?php endif; ?>`
- [ ] Split POST logic: `if ($action === 'x' && isAdmin()) { ... }`
- [ ] Add Google Fonts link to `<head>`
- [ ] Update CSS with design system colors
- [ ] Test as Admin (all controls visible)
- [ ] Test as Staff (only staff controls visible)
- [ ] Update navigation links to point to new unified file
- [ ] Delete old role-specific files (calendar.php, staff_calendar.php, etc.)

---

## File Locations & Descriptions

| File | Purpose | Status |
|------|---------|--------|
| `config/rbac-helpers.php` | Role-checking functions | ✅ Ready |
| `src/calendar-unified.php` | Shared UI example | ✅ Ready |
| `src/checkout-unified-example.php` | Business logic example | ✅ Ready |
| `REFACTORING_GUIDE.md` | Comprehensive guide | ✅ Ready |
| `README_RBAC.md` | This file (Quick start) | ✅ Ready |

---

## Common Mistakes to Avoid

❌ **Mistake 1:** Forgetting `requireLogin()` at top
```php
// Wrong
session_start();
$role = $_SESSION['role']; // Could be null!

// Right
requireLogin();
$role = $_SESSION['role']; // Safe
```

❌ **Mistake 2:** Not closing PHP conditionals
```php
// Wrong
<?php if (isAdmin()): ?>
    <button>Delete</button>
<?php // forgot endif; ?>

// Right
<?php if (isAdmin()): ?>
    <button>Delete</button>
<?php endif; ?>
```

❌ **Mistake 3:** Using wrong font families
```php
// Wrong
font-family: Arial, sans-serif;
h1 { font-family: 'Inter'; } /* Not in our system */

// Right
h1, h2, h3 { font-family: 'Bebas Neue', cursive; }
body { font-family: 'DM Sans', sans-serif; }
```

❌ **Mistake 4:** Using wrong colors
```css
/* Wrong */
.btn { background: #007bff; } /* Generic blue */
.btn:hover { background: #0056b3; }

/* Right */
.btn { background: #222222; }
.btn:hover { background: #FA8112; }
```

---

## Testing Script

Run this as Admin, then as Staff to verify implementation:

```
1. Navigate to refactored page
2. Check that navigation links are correct for your role
3. Look for Admin-only buttons (should be visible for Admin, hidden for Staff)
4. Look for Staff-only buttons (should be visible for Staff, hidden for Admin)
5. Click a shared button (should work for both roles)
6. Try to access admin-only functionality as Staff (should not work)
7. Verify styling matches index.php (fonts, colors, button styles)
8. Test on mobile (should still be readable)
```

---

## Next Steps

1. **Review** the examples: `calendar-unified.php` and `checkout-unified-example.php`
2. **Read** `REFACTORING_GUIDE.md` for comprehensive specifications
3. **Pick** a page to refactor (suggest: Calendar first, since it's already done)
4. **Test** both role paths thoroughly
5. **Deploy** and monitor for any issues
6. **Refactor** remaining pages in order of priority

---

## Support

For detailed info on:
- **Which pages to refactor:** See `REFACTORING_GUIDE.md` Section 5
- **What actions belong to each role:** See `REFACTORING_GUIDE.md` Module tables
- **How to implement business logic:** See `checkout-unified-example.php`
- **CSS/Design system:** See `index.php` and this file's Style sections
- **Helper functions:** See `config/rbac-helpers.php` docstrings

---

**Questions?** Refer to the comprehensive guide: `REFACTORING_GUIDE.md`
