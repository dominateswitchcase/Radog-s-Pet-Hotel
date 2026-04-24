// validation.js - Vanilla JS for form validation

document.addEventListener('DOMContentLoaded', function() {
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();

            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields.');
                return;
            }

            if (username.length < 3) {
                e.preventDefault();
                alert('Username must be at least 3 characters.');
                return;
            }

            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters.');
                return;
            }
        });
    }

    const bookingForm = document.getElementById('bookingForm');
    if (bookingForm) {
        bookingForm.addEventListener('submit', function(e) {
            const checkIn = new Date(document.getElementById('check_in').value);
            const checkOut = new Date(document.getElementById('check_out').value);
            const today = new Date();
            today.setHours(0,0,0,0);

            if (checkIn < today) {
                e.preventDefault();
                alert('Check-in date cannot be in the past.');
                return;
            }

            if (checkOut <= checkIn) {
                e.preventDefault();
                alert('Check-out date must be after check-in date.');
                return;
            }
        });
    }

    // Add more validations as needed
});