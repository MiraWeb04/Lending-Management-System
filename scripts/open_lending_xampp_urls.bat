@echo off
REM Adjust BASE if your folder under htdocs is different
set "BASE=http://localhost/Lending-Management-System/lending_system"
start "" "%BASE%/login_lending.php"
start "" "%BASE%/payments_lending.php"
echo Opened staff login and payments. Update BASE in this file if URLs 404.
