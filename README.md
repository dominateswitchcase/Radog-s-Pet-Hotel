# Radog’s Pet Hotel Management System

A centralized, internal-only Pet Hotel Management System for Radog’s Kennel, implementing Reservation, Registration, and Record-keeping (the 3R's) with a 2-tier client/server architecture.

## Features

- **Role-Based Access Control (RBAC)**: Owner (full access) and Employee (limited operations)
- **Modules**: Login, Dashboard, Registration, Booking, Financial Reports
- **Business Rules**: Species intake limits, feeding preferences, occupancy checks, mandatory verifications
- **Automated Pricing**: Tier-based fees and service add-ons

## Technical Stack

- **Frontend**: HTML5, Bootstrap 5, Vanilla JavaScript
- **Backend**: PHP 7.4+, Oracle Database (PDO_OCI)
- **Security**: PHP Sessions, SQL Binding

## Project Structure

```
├── public/          # Web-accessible files (HTML, JS, CSS)
├── src/             # PHP source files
├── config/          # Database configuration
├── sql/             # Database schema and scripts
├── assets/          # Static assets (CSS, JS, Bootstrap)
├── composer.json    # PHP dependencies
└── README.md
```

## Setup Instructions

### Prerequisites

- PHP 7.4 or higher with OCI8 extension
- Oracle Database (local or remote)
- Composer (for PHP dependencies)

### 1. Database Setup

1. Create a new Oracle database/schema for the application.
2. Run the SQL script in `sql/schema.sql` to create tables, sequences, and triggers.
3. Note the database connection details (host, port, SID, username, password).

### 2. Configuration

1. Edit `config/db.php` and update the database constants with your Oracle DB details.
2. Install PHP dependencies: `composer install`

### 3. Initial Data

- The schema includes sample data for USER_GROUP, PET_CATEGORY, TIER, ACCOMMODATION, SERVICE, PAYMENT_METHOD.
- Create initial users:
  - Insert into EMPLOYEE/OWNER as needed.
  - Create USER_ACCOUNT with hashed passwords (use `password_hash()` in PHP).

Example SQL for initial Owner user:
```sql
INSERT INTO OWNER (first_name, last_name, email, phone, address) VALUES ('John', 'Doe', 'owner@example.com', '1234567890', '123 Main St');
INSERT INTO USER_ACCOUNT (username, password_hash, group_id, owner_id) VALUES ('owner', '$2y$10$hashedpassword', 1, 1);
```

### 4. Running the Application

1. Start a PHP development server from the `public/` directory:
   ```
   cd public
   php -S localhost:8000
   ```
2. Open `http://localhost:8000/login.html` in your browser.

### 5. Usage

- **Login**: Use created user accounts.
- **Dashboard**: View metrics and access modules.
- **Registration**: Add new owners and pets.
- **Booking**: Create bookings with occupancy checks and verifications.
- **Financial**: View reports (Owner only).

## Business Rules Implementation

- **Species Limit**: Only Canine, Feline, Avian allowed (enforced in registration).
- **Feeding**: Recorded as owner-provided or kennel food.
- **Occupancy**: Checked before booking confirmation.
- **Verifications**: Vetcard and Consent required for confirmation.
- **Pricing**: Automatic tier assignment based on weight.

## Security Notes

- All SQL uses prepared statements with binding.
- Passwords are hashed using PHP's `password_hash()`.
- Sessions used for authentication and RBAC.

## Development Notes

- Bootstrap 5 loaded via CDN.
- Custom CSS in `assets/css/custom.css`.
- JavaScript validations in `assets/js/validation.js`.
- For production, configure a proper web server (Apache/Nginx) with PHP-FPM.

## License

Internal use only.