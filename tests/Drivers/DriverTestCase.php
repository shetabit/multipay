<?php

namespace Shetabit\Multipay\Tests\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\Request;
use Shetabit\Multipay\Tests\Support\StubServer;
use Shetabit\Multipay\Tests\TestCase;
use ReflectionProperty;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

/**
 * Base class of the driver tests.
 *
 * The drivers talk to their gateway over an HTTP client that they keep in their
 * `$client` property. The tests replace that client with one that answers from a
 * queue of prepared responses, which makes it possible to run the whole
 * purchase/pay/verify flow of a driver without touching the network.
 *
 * The settings a driver receives are the ones from `config/payment.php`, so the
 * tests also notice when a driver and its shipped configuration drift apart.
 */
abstract class DriverTestCase extends TestCase
{
    /**
     * The requests the driver has sent.
     *
     * @var array<int, array{request: RequestInterface, response: mixed}>
     */
    protected array $httpHistory = [];

    /**
     * Name of the driver in `config/payment.php`.
     */
    abstract protected function driverName() : string;

    /**
     * Class of the driver under test.
     */
    abstract protected function driverClass() : string;

    /**
     * Create the driver under test.
     */
    protected function driver(array $settings = [], Invoice|null $invoice = null) : Driver
    {
        $class = $this->driverClass();

        return new $class($invoice ?? new Invoice, $this->settings($settings));
    }

    /**
     * The settings of the driver, as shipped in `config/payment.php`.
     */
    protected function settings(array $overrides = []) : array
    {
        $settings = $this->config()['drivers'][$this->driverName()] ?? [];

        return array_merge($settings, $overrides);
    }

    /**
     * Answer the requests of the given driver from a queue of prepared responses.
     *
     * @param array<int, Response|\Throwable> $responses
     * @param array<string, mixed>            $clientOptions options the driver's own client is created with
     */
    protected function fakeHttp(Driver $driver, array $responses, array $clientOptions = []) : void
    {
        $this->httpHistory = [];

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->httpHistory));

        $property = new ReflectionProperty($driver, 'client');
        $property->setValue($driver, new Client($clientOptions + ['handler' => $stack]));
    }

    /**
     * The stub http server, for the drivers whose client can not be replaced.
     */
    protected function stubServer() : StubServer
    {
        $server = StubServer::instance();
        $server->reset();

        return $server;
    }

    /**
     * A response for the queue of the stub http server.
     *
     * @param array|string $body
     */
    protected function stubResponse($body, int $status = 200) : array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($body),
        ];
    }

    /**
     * A raw response for the queue of the stub http server.
     */
    protected function rawStubResponse(string $body, int $status = 200, array $headers = []) : array
    {
        return [
            'status' => $status,
            'headers' => $headers === [] ? ['Content-Type' => 'text/plain'] : $headers,
            'body' => $body,
        ];
    }

    /**
     * A response with the given JSON body.
     */
    protected function jsonResponse(array $body, int $status = 200) : Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    /**
     * A response with the given raw body.
     */
    protected function response(string $body, int $status = 200, array $headers = []) : Response
    {
        return new Response($status, $headers, $body);
    }

    /**
     * The number of requests the driver has sent.
     */
    protected function requestCount() : int
    {
        return count($this->httpHistory);
    }

    /**
     * The request the driver has sent at the given position.
     */
    protected function request(int $index = 0) : RequestInterface
    {
        $this->assertArrayHasKey($index, $this->httpHistory, "The driver did not send a request #$index.");

        return $this->httpHistory[$index]['request'];
    }

    /**
     * The URL of the request the driver has sent at the given position.
     */
    protected function requestUrl(int $index = 0) : string
    {
        return (string) $this->request($index)->getUri();
    }

    /**
     * The raw body of the request the driver has sent at the given position.
     */
    protected function requestBody(int $index = 0) : string
    {
        return (string) $this->request($index)->getBody();
    }

    /**
     * The decoded JSON body of the request the driver has sent at the given position.
     */
    protected function requestJson(int $index = 0) : array
    {
        $body = json_decode($this->requestBody($index), true);

        $this->assertIsArray($body, "The body of request #$index is not a JSON object.");

        return $body;
    }

    /**
     * The decoded form body of the request the driver has sent at the given position.
     */
    protected function requestForm(int $index = 0) : array
    {
        $form = [];
        parse_str($this->requestBody($index), $form);

        return $form;
    }

    /**
     * Pretend the gateway called back with the given data.
     */
    protected function fakeRequest(array $input) : void
    {
        $reader = fn ($name) => $input[$name] ?? null;

        Request::overwrite('input', $reader);
        Request::overwrite('get', $reader);
        Request::overwrite('post', $reader);
    }

    /**
     * Assert that the driver sent its request to the given URL.
     */
    protected function assertRequestedUrl(string $url, int $index = 0) : void
    {
        $this->assertSame($url, $this->requestUrl($index));
    }

    /**
     * Create a directory for the drivers that cache something on disk.
     */
    protected function makeTemporaryDirectory(string $name) : string
    {
        $directory = sys_get_temp_dir().'/multipay-'.$name.'-'.getmypid().'/';

        $this->removeDirectory($directory);
        mkdir($directory, 0777, true);

        return $directory;
    }

    /**
     * Remove a directory and everything in it.
     */
    protected function removeDirectory(string $directory) : void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
