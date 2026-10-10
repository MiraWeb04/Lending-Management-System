# Lending Management System

## Overview
A comprehensive web-based application for managing lending operations, including client management, loan tracking, payment collection, expense tracking, and reporting.

## Features

### User Management
- Secure login/logout functionality
- Role-based access control (admin/staff)
- User profile management
- Password change functionality

### Client Management
- Add, edit, and delete clients
- View client details and history
- Search and filter clients
- Client loan summary

### Loan Management
- Create new loans with customizable terms
- Track loan status (Active, Overdue, Paid)
- Calculate interest and payment schedules
- View loan details and payment history

### Payment Collection
- Record payments against loans
- Track payment history
- Daily collection reports
- Payment receipt generation

### Expense Tracking
- Record business expenses
- Categorize expenses
- Track daily/monthly expenses
- Calculate net income

### Reporting
- Daily collection reports
- Monthly performance reports
- Loan status reports
- Client reports
- Export data to CSV
- Print reports

### Dashboard
- Overview of key metrics
- Active loans summary
- Overdue loans alerts
- Today's collection summary
- Monthly collection charts

## Technical Details

### Technologies Used
- PHP 7.4+
- MySQL 5.7+
- HTML5, CSS3, JavaScript
- Bootstrap 5
- Chart.js for data visualization
- Font Awesome for icons

### Database Structure
- clients: Stores client information
- loans: Tracks loan details and status
- payments: Records payment transactions
- expenses: Logs business expenses
- users: Manages system users and authentication

## Installation

1. **Database Setup**
   - Create a MySQL database named `lending_management`
   - Import the SQL schema from `database/lending_setup.sql`

2. **Configuration**
   - Update database connection details in `includes/db_lending.php`

3. **Web Server**
   - Deploy files to a PHP-enabled web server
   - Ensure the web server has write permissions for the application directory

4. **Access**
   - Navigate to `index_lending.php` in your web browser
   - Default admin credentials:
     - Username: admin
     - Password: admin123
   - **Important:** Change the default password immediately after first login

## Security Considerations

- All passwords are hashed using PHP's password_hash() function
- Input validation and sanitization throughout the application
- Session-based authentication with timeout
- CSRF protection for forms
- Role-based access control

## Usage

1. **Login** using your credentials
2. Navigate through the system using the top navigation bar
3. Add clients before creating loans
4. Record payments against active loans
5. Generate reports as needed

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Support

For support and inquiries, please contact the system administrator.