<?php
const TARGET_HOST = 'https://your-main-subscription-domain-here.com';
const ERROR_LOG_FILE = __DIR__ . '/errors.log';

// Initialize error logging
function logError($message) {
    $logMessage = "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    error_log($logMessage, 3, ERROR_LOG_FILE);
}

try {
    // Get the full requested URI
    $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $queryString = $_SERVER['QUERY_STRING'] ? '?'.$_SERVER['QUERY_STRING'] : '';
    $targetUrl = rtrim(TARGET_HOST, '/') . $requestUri . $queryString;

    // Initialize cURL
    $ch = curl_init($targetUrl);
    if ($ch === false) {
        throw new Exception("Failed to initialize cURL");
    }

    // Set cURL options
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    // Forward headers
    $headers = [];
    foreach (getallheaders() as $name => $value) {
        if (!in_array(strtolower($name), ['host', 'accept-encoding'])) {
            $headers[] = "$name: $value";
        }
    }
    $headers[] = 'X-Forwarded-For: ' . $_SERVER['REMOTE_ADDR'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Forward request body
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'])) {
        $input = file_get_contents('php://input');
        if ($input === false) {
            throw new Exception("Failed to read request body");
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
    }

    // Execute request
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception("cURL error: " . curl_error($ch));
    }

    // Process response
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $responseHeaders = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Forward headers to client
    $headerLines = explode("\r\n", $responseHeaders);
    foreach ($headerLines as $headerLine) {
        if (!empty($headerLine) && !preg_match('/^(Transfer-Encoding|Content-Encoding|Connection):/i', $headerLine)) {
            header($headerLine);
        }
    }

    http_response_code($httpCode);
    echo $body;

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal Server Error']);
    
    // Log the error with additional context
    $errorMessage = $e->getMessage();
    $requestInfo = json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'ip' => $_SERVER['REMOTE_ADDR'],
        'time' => date('Y-m-d H:i:s')
    ]);
    logError("$errorMessage | Request: $requestInfo");
    
} finally {
    if (isset($ch)) {
        curl_close($ch);
    }
}