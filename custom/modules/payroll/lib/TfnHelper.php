<?php
// TFN encryption helpers used by payroll/tfn.php and payroll/employee_payroll.php.
// Key: TFN_KEY in .env (base64-encoded 32-byte AES-256 key).

function tfn_load_key() {
    $envFile = DOL_DOCUMENT_ROOT . '/../.env';
    if (!file_exists($envFile)) return '';
    foreach (file($envFile) as $line) {
        if (preg_match('/^TFN_KEY\s*=\s*(.+)$/', trim($line), $m)) {
            return base64_decode(trim($m[1]));
        }
    }
    return '';
}

function tfn_encrypt($plain, $key) {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function tfn_decrypt($blob, $key) {
    $raw = base64_decode($blob);
    if (strlen($raw) < 17) return false;
    return openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
}
