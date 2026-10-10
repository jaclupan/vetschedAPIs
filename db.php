<?php
$fileEnv = [];
$envFile = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES);
    if ($envLines === false) {
        error_log('Could not read Vetsched .env file.');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Could not read the database configuration file.',
        ]);
        exit;
    }

    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!preg_match('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            error_log('Invalid line in Vetsched .env file.');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'The database configuration file has an invalid line.',
            ]);
            exit;
        }

        $name = $matches[1];
        $value = trim($matches[2]);
        if (strlen($value) >= 2 &&
            (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
             ($value[0] === "'" && $value[strlen($value) - 1] === "'"))) {
            $value = substr($value, 1, -1);
        } elseif (isset($value[0]) && ($value[0] === '"' || $value[0] === "'")) {
            error_log('Unclosed quoted value in Vetsched .env file.');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'The database configuration file has an invalid value.',
            ]);
            exit;
        }

        $existingValue = getenv($name);
        if ($existingValue === false || $existingValue === '') {
            $fileEnv[$name] = $value;
            $_ENV[$name] = $value;
            if (function_exists('putenv')) {
                putenv($name . '=' . $value);
            }
        }
    }
}

$getConfig = static function (string $name) use (&$fileEnv): string {
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    return $fileEnv[$name] ?? '';
};

$config = [
    'VETSCHED_DB_HOST' => $getConfig('VETSCHED_DB_HOST'),
    'VETSCHED_DB_PORT' => $getConfig('VETSCHED_DB_PORT'),
    'VETSCHED_DB_NAME' => $getConfig('VETSCHED_DB_NAME'),
    'VETSCHED_DB_USER' => $getConfig('VETSCHED_DB_USER'),
    'VETSCHED_DB_PASSWORD' => $getConfig('VETSCHED_DB_PASSWORD'),
];
$missingConfig = array_keys(array_filter($config, static fn(string $value): bool => $value === ''));

if ($missingConfig !== []) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database configuration is missing: ' . implode(', ', $missingConfig) . '.',
    ]);
    exit;
}

$host = $config['VETSCHED_DB_HOST'];
$port = $config['VETSCHED_DB_PORT'];
$dbname = $config['VETSCHED_DB_NAME'];
$user = $config['VETSCHED_DB_USER'];
$password = $config['VETSCHED_DB_PASSWORD'];

$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;sslmode=require;connect_timeout=10;keepalives=1;keepalives_idle=30',
    $host,
    $port,
    $dbname
);

try {
    $conn = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true,
    ]);
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage(),
    ]);
    exit;
}
?>
