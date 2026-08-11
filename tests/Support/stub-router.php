<?php

/**
 * Router of the stub http server, see StubServer.
 *
 * It records the incoming request and answers with the next response of the queue.
 * Both are kept in files, because the server runs in its own process.
 */

$stateDirectory = getenv('MULTIPAY_STUB_STATE');

if ($stateDirectory === false) {
    http_response_code(500);
    echo 'The stub http server was started without a state directory.';

    return true;
}

$queueFile = $stateDirectory.'/queue.json';
$requestsFile = $stateDirectory.'/requests.json';

$headers = [];

foreach ($_SERVER as $name => $value) {
    if (str_starts_with((string) $name, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr((string) $name, 5)))] = $value;
    }
}

if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

$requests = json_decode((string) @file_get_contents($requestsFile), true) ?: [];
$requests[] = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'uri' => $_SERVER['REQUEST_URI'] ?? '/',
    'headers' => $headers,
    'body' => (string) file_get_contents('php://input'),
];
file_put_contents($requestsFile, json_encode($requests));

$queue = json_decode((string) @file_get_contents($queueFile), true) ?: [];
$response = array_shift($queue);
file_put_contents($queueFile, json_encode($queue));

if ($response === null) {
    http_response_code(599);
    echo 'The stub http server has no response left in its queue.';

    return true;
}

http_response_code($response['status'] ?? 200);

foreach ($response['headers'] ?? ['Content-Type' => 'application/json'] as $name => $value) {
    header($name.': '.$value);
}

echo $response['body'] ?? '';

return true;
