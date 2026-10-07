<?php

namespace FabricatorForms\Tests\Integration\Support;

/**
 * A throwaway HTTP server on 127.0.0.1 that answers every request with a 404 and writes its path to a log. Given a
 * document root, it serves the files there instead, as the site's own web server would, and 404s the rest.
 *
 * For code that fetches URLs outside WordPress's HTTP API (mPDF uses its own curl calls), where pre_http_request sees
 * nothing. It answers at once, so a regression that does fetch fails the test instead of hanging it: a silent listener
 * would leave curl waiting forever, as mPDF sets no overall timeout.
 */
final class RequestRecorder
{
    /** @var resource */
    private $process;

    private string $log;
    private string $router;

    public readonly string $host;

    public function __construct(?string $docroot = null)
    {
        // A free port: bind to 0, read the port back, release it for the server.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->host = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $this->log    = tempnam(sys_get_temp_dir(), 'ff-requests');
        $this->router = tempnam(sys_get_temp_dir(), 'ff-router') . '.php';
        // Returning false hands the request to the built-in server, which serves the file from the document root.
        $serve = $docroot === null ? '' : 'if (is_file(' . var_export(rtrim($docroot, '/\\'), true)
            . ' . parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH))) { return false; } ';
        file_put_contents($this->router, '<?php file_put_contents(' . var_export($this->log, true)
            . ', ($_SERVER["REQUEST_URI"] ?? "?") . "\n", FILE_APPEND); ' . $serve . 'http_response_code(404); return true;');

        $null          = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command       = [PHP_BINARY, '-S', $this->host, ...($docroot === null ? [] : ['-t', $docroot]), $this->router];
        $this->process = proc_open($command, [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('could not start the request recorder');
        }
        $this->waitUntilListening();
    }

    /**
     * Paths requested so far.
     *
     * @return string[]
     */
    public function requests(): array
    {
        clearstatcache();
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->log);
        @unlink($this->router);
        @unlink(substr($this->router, 0, -4));
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://' . $this->host, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('the request recorder did not start listening on ' . $this->host);
    }
}
