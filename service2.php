<?php 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use ZipkinGuzzle\Middleware;

use GuzzleHttp\HandlerStack;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/functions.php';

$tracing = create_tracing('frontend', '127.0.0.1');
$tracer = $tracing->getTracer();

$stack = HandlerStack::create();
$stack->push(Middleware\tracing($tracing));
$stack->push(createNoServerMiddleware($tracing));



$request = new \GuzzleHttp\Psr7\Request('GET', 'localhost:9004', []);
$httpClient = new \GuzzleHttp\Client([
    'handler' => $stack
]);

$response = $httpClient->send($request);
echo $response->getBody()." ";
register_shutdown_function(function () use ($tracer) {
    $tracer->flush();
});
?>