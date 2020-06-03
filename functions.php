<?php

use Zipkin\Endpoint;
use Zipkin\Samplers\BinarySampler;
use Zipkin\TracingBuilder;


// plugin start
use ZipkinGuzzle\Middleware;
// plugin end
use Zipkin\Propagation\Map;
use Zipkin\Timestamp;

use GuzzleHttp\HandlerStack;
use App\Http\Middleware\NoServerMiddleware;

//use Throwable;
use Zipkin\Tracing;
use GuzzleHttp\Promise;
use Zipkin\Propagation\Getter;
use Zipkin\Propagation\TraceContext;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;


// Getter for the `getallheaders` function and it is required to be able to access
// headers using that function
final class GetAllHeaders implements Getter
{
    public function get($carrier, $key)
    {
        if (array_key_exists($key, $carrier)) {
            return $carrier[$key][0];
        }
        
        return null;
    }
}

// Middleware for extracting the span from the headers and putting the span
// in the scope so the tracingMiddleware is going to create a child of it.
// THIS IS EXPERIMENTAL and it will create a child span of kind CLIENT from
// a CLIENT span.
function createNoServerMiddleware(Tracing $tracing): callable
{
    $tracer = $tracing->getTracer();
    $extractor = $tracing->getPropagation()->getExtractor(new GetAllHeaders());
    
    return function (callable $handler) use ($tracer, $extractor) {
        return function (RequestInterface $request, array $options) use ($handler, $tracer, $extractor) {
            $context = $extractor(getallheaders());
            //file_put_contents('backend headers.txt', print_r($context,1));
            if (!($context instanceof TraceContext)) {
                return $handler($request, $options);
            }
            
            $span = $tracer->joinSpan($context);
            // we do our best to make sure we close the scope no matter what.
            $scopeCloser = $tracer->openScope($span);
            
            try {
                return $handler($request, $options)->then(
                    function (ResponseInterface $response) use ($scopeCloser) {
                        $scopeCloser();
                        return $response;
                    },
                    function ($reason) use ($scopeCloser) {
                        $scopeCloser();
                        return Promise\rejection_for($reason);
                    }
                    );
            } catch (Throwable $e) {
                $scopeCloser();
                throw $e;
            }
        };
    };
}

/**
 * create_tracing function is a handy function that allows you to create a tracing
 * component by just passing the local service information. If you need to pass a 
 * custom zipkin server URL use the HTTP_REPORTER_URL env var.
 */
function create_tracing($localServiceName, $localServiceIPv4, $localServicePort = null)
{
    $httpReporterURL = getenv('HTTP_REPORTER_URL');
    if ($httpReporterURL === false) {
        $httpReporterURL = 'http://localhost:9411/api/v2/spans';
    }

    $endpoint = Endpoint::create($localServiceName, $localServiceIPv4, null, $localServicePort);

    /* Do not copy this logger into production.
     * Read https://github.com/Seldaek/monolog/blob/master/doc/01-usage.md#log-levels
     */
    $logger = new \Monolog\Logger('log');
    $logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

    $reporter = new Zipkin\Reporters\Http(
        \Zipkin\Reporters\Http\CurlFactory::create(),
        ['endpoint_url' => $httpReporterURL]
    );
    $sampler = BinarySampler::createAsAlwaysSample();
    $tracing = TracingBuilder::create()
        ->havingLocalEndpoint($endpoint)
        ->havingSampler($sampler)
        ->havingReporter($reporter)
        ->build();
    return $tracing;
}
