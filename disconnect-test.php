<?php
/**
 * Test client disconnection
 */

// ERROR LOGGING:

ini_set('error_log', '/tmp/php_errors.log');

// invent some ID for the logs to make life easier
$id = uniqid();

// required to respond to process signals
declare(ticks = 1);

function handleSignal($signal)
{
    global $id;
    error_log($id . " killed via signal {$signal}.");
}

// only try to do this if pcntl_signal extension installed
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGHUP, 'handleSignal');
    pcntl_signal(SIGINT, 'handleSignal');
    pcntl_signal(SIGQUIT, 'handleSignal');
    pcntl_signal(SIGTERM, 'handleSignal');
}

register_shutdown_function('shutdownFunction');
function shutDownFunction()
{
    global $id;
    error_log($id . " shutting down.");
    error_log($id . ' abort detected: ' . (connection_aborted() ? 'YES' : 'NO'));
}

// --

// make some content
$data = array();
for ($i=0; $i<=1000; $i++) {
    $data[md5(microtime(TRUE).'foo'.rand(1,100000))] = md5(microtime(TRUE).'foo'.rand(1,100000));
}
$content = json_encode($data);
$contentLength = strlen($content);


/**
 * Test to see if we can measure bytes sent
 */

error_log($id . ' sending '. $contentLength);
error_log($id . ' abort detected: ' . (connection_aborted() ? 'YES' : 'NO'));

header('Content-Length: ' . $contentLength);
$buffer = 1024;
$sent = 0;
while (!connection_aborted() && $sent < $contentLength) {
    echo substr($content, $sent, $buffer);
    $sent += $buffer;
    usleep(30000);
}

error_log($id . ' completed process; sent: ' . $sent);
error_log($id . ' abort detected: ' . (connection_aborted() ? 'YES' : 'NO'));
