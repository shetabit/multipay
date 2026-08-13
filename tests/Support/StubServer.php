<?php

namespace Shetabit\Multipay\Tests\Support;

use RuntimeException;

/**
 * A tiny HTTP server that answers from a queue of prepared responses.
 *
 * Most drivers talk to their gateway through the HTTP client in their `$client`
 * property, which the tests can simply replace. The remaining ones build their
 * client inside the method that uses it, use cURL directly or already talk to the
 * gateway while they are being constructed. Those drivers are pointed at this
 * server instead, by overwriting the gateway URLs in their settings.
 *
 * The server runs in a separate process, so the queue and the recorded requests
 * are exchanged through files.
 */
final class StubServer
{
    private static self|null $instance = null;

    /**
     * @var resource|null
     */
    private $process;

    private string $host = '127.0.0.1';

    private readonly int $port;

    private readonly string $stateDirectory;

    private function __construct()
    {
        $this->stateDirectory = sys_get_temp_dir().'/multipay-stub-server-'.getmypid();

        if (!is_dir($this->stateDirectory)) {
            mkdir($this->stateDirectory, 0777, true);
        }

        $this->port = $this->findFreePort();
        $this->boot();
    }

    /**
     * The running server, started on first use and shut down when the tests end.
     */
    public static function instance() : self
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self;

            register_shutdown_function(static function (): void {
                self::$instance?->stop();
            });
        }

        return self::$instance;
    }

    /**
     * The base url of the server.
     */
    public function url(string $path = '') : string
    {
        return 'http://'.$this->host.':'.$this->port.'/'.ltrim($path, '/');
    }

    /**
     * Forget the queued responses and the recorded requests.
     */
    public function reset() : void
    {
        file_put_contents($this->queueFile(), '[]');
        file_put_contents($this->requestsFile(), '[]');
    }

    /**
     * Queue the responses that the server answers with, in order.
     *
     * @param array<int, array{status?: int, headers?: array<string, string>, body?: string}> $responses
     */
    public function queue(array $responses) : void
    {
        file_put_contents($this->queueFile(), json_encode($responses));
    }

    /**
     * The requests the server has received.
     *
     * @return array<int, array{method: string, uri: string, headers: array<string, string>, body: string}>
     */
    public function requests() : array
    {
        $requests = json_decode((string) file_get_contents($this->requestsFile()), true);

        return is_array($requests) ? $requests : [];
    }

    public function stop() : void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        foreach (glob($this->stateDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->stateDirectory)) {
            rmdir($this->stateDirectory);
        }
    }

    private function boot() : void
    {
        $this->reset();

        $command = [
            PHP_BINARY,
            '-S',
            $this->host.':'.$this->port,
            '-t',
            __DIR__,
            __DIR__.'/stub-router.php',
        ];

        $this->process = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['MULTIPAY_STUB_STATE' => $this->stateDirectory]
        );

        if (!is_resource($this->process)) {
            throw new RuntimeException('The stub http server could not be started.');
        }

        $this->waitUntilReachable();
    }

    private function waitUntilReachable() : void
    {
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen($this->host, $this->port, $errorCode, $errorMessage, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException("The stub http server did not start on {$this->host}:{$this->port}.");
    }

    private function findFreePort() : int
    {
        $socket = stream_socket_server($this->host.':0', $errorCode, $errorMessage);

        if ($socket === false) {
            throw new RuntimeException('No free port could be found for the stub http server.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function queueFile() : string
    {
        return $this->stateDirectory.'/queue.json';
    }

    private function requestsFile() : string
    {
        return $this->stateDirectory.'/requests.json';
    }
}
