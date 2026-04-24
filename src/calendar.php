    <?php
    session_start();
    require_once '../config/db.php';

    // RBAC: Ensure authorized access [cite: 51, 100, 121]
    if (!isset($_SESSION['account_id'])) {
        header("Location: employee_login.php");
        exit();
    }

    // Fetch confirmed bookings for the calendar [cite: 53, 103, 117]
    // We use a JOIN to get the Pet name for the calendar label [cite: 317, 352, 382]
    $query = "SELECT B.BOOKING_ID, P.PET_NAME, B.CHECK_IN_DATE, B.CHECK_OUT_DATE, B.BOOKING_STATUS 
            FROM BOOKING B 
            JOIN PET P ON B.PET_ID = P.PET_ID 
            WHERE B.BOOKING_STATUS = 'Confirmed'";
    $stmt = $pdo->query($query);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $success_message = '';
    if (isset($_GET['booking_added']) && $_GET['booking_added'] === '1') {
        $success_message = 'Booking was added successfully and is now visible on the calendar.';
    }

    // Format bookings for FullCalendar JSON requirement [cite: 50, 110]
    $events = [];
    foreach ($bookings as $row) {
        /** * FullCalendar end dates are exclusive. 
         * To show a pet is still checked in on their check-out day, 
         * we add one day to the CHECK_OUT_DATE for visualization only.
         */
        $visual_end_date = date('Y-m-d', strtotime($row['CHECK_OUT_DATE'] . ' +1 day'));

        $events[] = [
            'id'    => $row['BOOKING_ID'],
            'title' => htmlspecialchars($row['PET_NAME']),
            'start' => date('Y-m-d', strtotime($row['CHECK_IN_DATE'])),
            'end'   => $visual_end_date,
            'color' => '#FA8112', // Radog's brand orange [cite: 2, 4]
            'url'   => 'booking_verification.php?booking_id=' . $row['BOOKING_ID'],
            'allDay' => true // Ensures the event blocks the entire date cell [cite: 117]
        ];
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Calendar - Radog's Pet Hotel</title>
        <link rel="stylesheet" href="../assets/bootstrap/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
        <link href="../assets/css/custom.css" rel="stylesheet">
        <style>
            body { display: flex; min-height: 100vh; background-color: #f8f9fa; }
            .sidebar { width: 280px; min-width: 280px; background: #fff; border-right: 1px solid rgba(0,0,0,.1); position: sticky; top: 0; height: 100vh; }
            .main-content { flex-grow: 1; }
            .fc-event { cursor: pointer; border: none; padding: 2px 5px; }
            .fc-toolbar-title { font-size: 1.25rem !important; font-weight: bold; }
            .fc-button-primary { background-color: #FA8112 !important; border-color: #FA8112 !important; }
        </style>
    </head>
    <body>
        <aside class="sidebar d-flex flex-column p-4" style="background-color: #F5E7C6;">
        <div class="sidebar-brand mb-5 text-center">
            <img src="../img/radog_logo.png" alt="Radog Logo" class="img-fluid mb-3" style="max-height: 90px; width: auto;">
            <div>
                <h2 class="h5 mb-1" style="color: #222222;">Radog's Kennel</h2>
                <p class="mb-1 text-muted-custom small"><?php echo htmlentities($_SESSION['username'] ?? 'Staff'); ?></p>
            </div>
        </div>
        <nav class="nav nav-pills flex-column mb-auto sidebar-nav">
            <a href="admin_dashboard.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-house-door-fill me-3"></i> Dashboard</a>
            <a href="encode_reservation.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-calendar-check me-3"></i> Schedule</a>
            <a href="calendar.php" class="nav-link d-flex align-items-center mb-2 active" style="background-color: #FA8112; color: white;"><i class="bi bi-calendar3 me-3"></i> Calendar</a>
            <a href="owner.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-people me-3"></i> Owners</a>
            <a href="pets.php" class="nav-link d-flex align-items-center mb-2"><i class="bi bi-paw me-3"></i> Pets</a>
            <a href="checkout.php" class="nav-link d-flex align-items-center mb-2 " ><i class="bi bi-cash-stack me-3"></i> Checkout/Payments</a>
              <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
    <a href="user_management.php" class="nav-link d-flex align-items-center mb-2">
        <i class="bi bi-gear-fill me-3"></i> User Management
    </a>
<?php endif; ?>
        </nav>
        <div class="mt-auto">
            <a href="../index.php" class="btn btn-link logout-link d-flex align-items-center gap-2 text-decoration-none" style="color: #222222;">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </div>
    </aside>
        <main class="main-content p-4 p-md-5">
            <div class="mb-4">
                <h1 class="h3 mb-1">Time-Blocking Calendar</h1>
               <p class="text-muted-custom">Real-time monitoring of kennel availability</p>
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success mt-3 mb-0">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-panel p-4 shadow-sm">
                <div id="calendar"></div>
            </div>
        </main>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var calendarEl = document.getElementById('calendar');
                var calendar = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek'
                    },
                    themeSystem: 'bootstrap5',
                    events: <?php echo json_encode($events); ?>,
                    eventClick: function(info) {
                        if (info.event.url) {
                            window.location.href = info.event.url;
                            return false;
                        }
                    }
                });
                calendar.render();
            });
        </script>
    </body>
    </html>