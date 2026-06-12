<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\AssemblesHarnessConfig;
use App\Robinhood\Mcp\OAuthBootstrapClient;
use App\Robinhood\Mcp\OAuthTokenStore;
use Illuminate\Console\Command;

class RobinhoodOAuthListenCommand extends Command
{
    use AssemblesHarnessConfig;

    protected $signature = 'theta:rh-oauth-listen
        {--host=0.0.0.0 : Callback listener host inside the container}
        {--port=8787 : Callback listener port}
        {--timeout=300 : Seconds to wait for the browser callback}';

    protected $description = 'Listen for the Robinhood OAuth callback and store the theta token grant';

    public function handle(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $timeout = (int) $this->option('timeout');

        $server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
        if ($server === false) {
            $this->error("oauth listener failed to bind {$host}:{$port}: {$errstr} ({$errno})");

            return self::FAILURE;
        }

        stream_set_timeout($server, $timeout);
        $this->info("OAuth listener waiting on http://127.0.0.1:{$port}/callback for {$timeout}s...");

        $conn = @stream_socket_accept($server, $timeout);
        if ($conn === false) {
            fclose($server);
            $this->error('oauth listener timed out waiting for callback');

            return self::FAILURE;
        }

        $requestLine = fgets($conn);
        while (($line = fgets($conn)) !== false && trim($line) !== '') {
            // Drain headers.
        }

        $path = '';
        if (is_string($requestLine) && preg_match('#^GET\s+(\S+)\s+HTTP/#', $requestLine, $match) === 1) {
            $path = $match[1];
        }

        $callbackUrl = "http://127.0.0.1:{$port}{$path}";
        $ok = false;
        $message = 'Theta did not store the Robinhood OAuth grant.';

        try {
            $cfg = $this->assembleConfig();
            $grant = OAuthBootstrapClient::fromConfig($cfg)->complete(
                callbackUrl: $callbackUrl,
                code: null,
                state: null,
            );
            OAuthTokenStore::fromConfig($cfg)->put($grant);
            $ok = true;
            $message = 'Theta stored the Robinhood OAuth grant. You can close this tab.';
        } catch (\Throwable $e) {
            $message = "Theta OAuth completion failed: {$e->getMessage()}";
        }

        $body = "<!doctype html><meta charset=\"utf-8\"><title>Theta OAuth</title><body><h1>{$message}</h1></body>";
        fwrite($conn, 'HTTP/1.1 '.($ok ? '200 OK' : '500 Internal Server Error')."\r\n");
        fwrite($conn, "Content-Type: text/html; charset=utf-8\r\n");
        fwrite($conn, 'Content-Length: '.strlen($body)."\r\n\r\n");
        fwrite($conn, $body);
        fclose($conn);
        fclose($server);

        if (! $ok) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->info($message);

        return self::SUCCESS;
    }
}
