<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

require_once dirname(__DIR__) . '/includes/borrower_registration_helpers.php';

$checks = [
    ['Juan', borrowerRegistrationPersonNameError('Juan', 'first name') === ''],
    ['Mary-Jane', borrowerRegistrationPersonNameError('Mary-Jane', 'first name') === ''],
    ["O'Brien", borrowerRegistrationPersonNameError("O'Brien", 'last name') === ''],
    ['John#', borrowerRegistrationPersonNameError('John#', 'first name') !== ''],
    ['Ann$', borrowerRegistrationPersonNameError('Ann$', 'first name') !== ''],
    ['Sam%', borrowerRegistrationPersonNameError('Sam%', 'first name') !== ''],
    ['Lee^', borrowerRegistrationPersonNameError('Lee^', 'first name') !== ''],
    ['Kim&', borrowerRegistrationPersonNameError('Kim&', 'first name') !== ''],
    ['middle blank', borrowerRegistrationPersonNameError('', 'middle name', false) === ''],
    ['09171234567', borrowerRegistrationMobileError('09171234567') === ''],
    ['+63 917 123 4567', borrowerRegistrationMobileError('+63 917 123 4567') === ''],
    ['0917#1234567', borrowerRegistrationMobileError('0917#1234567') !== ''],
    ['user@example.com', borrowerRegistrationEmailError('user@example.com') === ''],
    ['user#me@example.com', borrowerRegistrationEmailError('user#me@example.com') !== ''],
    ['12 Rizal St., Manila', borrowerRegistrationAddressError('12 Rizal St., Manila') === ''],
    ['#12 Rizal Street', borrowerRegistrationAddressError('#12 Rizal Street') !== ''],
];

$failed = 0;
foreach ($checks as [$label, $ok]) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}
exit($failed === 0 ? 0 : 1);
