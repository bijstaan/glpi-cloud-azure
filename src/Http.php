<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

/**
 * The one place this plugin talks to Azure.
 *
 * Guzzle from GLPI's own vendor tree — no new dependency, and no cloud SDK. The
 * whole of Azure Resource Manager is HTTPS and JSON; vendoring tens of
 * megabytes to call five endpoints would buy nothing.
 *
 * Three behaviours are deliberate:
 *
 * **`http_errors` is off.** What a sweep needs is the difference between "could
 * not reach it", "was asked to slow down", "the credentials are wrong" and "the
 * body is unusable". That lives in the status code and the response, not in
 * Guzzle's exception hierarchy.
 *
 * **A 429 is answered with what Azure said, not with a guess.** ARM and
 * Resource Graph both send `Retry-After`; sleeping for a made-up backoff either
 * wastes a cron tick or gets throttled again. If the wait would run past the
 * sweep's deadline the request is abandoned instead, because the sweep is
 * checkpointed and coming back in an hour is free.
 *
 * **Nothing identifying is added to a request.** No hostname, no instance URL,
 * no telemetry. The User-Agent names the plugin and its version, and that is
 * all.
 */
final class Http
{
    private ?GuzzleClient $client;

    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param int           $timeout  seconds before a request is abandoned
     * @param int|null      $deadline unix time the sweep must stop by, if any
     * @param int           $retries  attempts after a retryable answer
     * @param callable|null $sleeper  test seam; sleeps for real by default
     */
    public function __construct(
        private readonly int $timeout = 20,
        private readonly ?int $deadline = null,
        private readonly int $retries = 2,
        ?callable $sleeper = null,
        ?GuzzleClient $client = null,
    ) {
        $this->client  = $client;
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * @param array<string,string> $headers
     * @return array<mixed>
     */
    public function getJson(string $url, array $headers = []): array
    {
        return $this->send('GET', $url, [], $headers);
    }

    /**
     * @param array<mixed>         $body
     * @param array<string,string> $headers
     * @return array<mixed>
     */
    public function postJson(string $url, array $body, array $headers = []): array
    {
        return $this->send('POST', $url, [
            'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ], $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * Form-encoded, which the token endpoint requires and nothing else uses.
     *
     * @param array<string,string> $form
     * @return array<mixed>
     */
    public function postForm(string $url, array $form): array
    {
        return $this->send('POST', $url, ['form_params' => $form], []);
    }

    /**
     * @param array<string,mixed>  $options
     * @param array<string,string> $headers
     * @return array<mixed>
     */
    private function send(string $method, string $url, array $options, array $headers): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->once($method, $url, $options, $headers);
            } catch (AzureException $e) {
                $attempt++;

                if (!$e->isRetryable() || $attempt > $this->retries) {
                    throw $e;
                }

                // Azure's own number, defaulting to a small one when it did not
                // say. Waiting past the sweep's deadline is pointless: the
                // cursor is stored and the next run costs nothing.
                $wait = $e->retryAfter > 0 ? $e->retryAfter : (2 ** $attempt);

                if ($this->deadline !== null && (time() + $wait) >= $this->deadline) {
                    throw $e;
                }

                ($this->sleeper)((float) $wait);
            }
        }
    }

    /**
     * @param array<string,mixed>  $options
     * @param array<string,string> $headers
     * @return array<mixed>
     */
    private function once(string $method, string $url, array $options, array $headers): array
    {
        try {
            $response = $this->client()->request($method, $url, $options + [
                'headers'     => $headers + ['Accept' => 'application/json', 'User-Agent' => self::userAgent()],
                'timeout'     => $this->timeout,
                'http_errors' => false,
            ]);
        } catch (ConnectException $e) {
            throw new AzureException(AzureException::TRANSPORT, 'Could not reach ' . self::host($url) . ': ' . $e->getMessage());
        } catch (TransferException $e) {
            // Read timeouts arrive here rather than as ConnectException. A slow
            // control plane is ordinary, so this is retryable.
            throw new AzureException(AzureException::TRANSPORT, self::host($url) . ' request failed: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();

        if ($status === 429 || $status === 503) {
            throw new AzureException(
                AzureException::THROTTLED,
                sprintf('%s asked us to slow down (HTTP %d)', self::host($url), $status),
                $status,
                self::retryAfter($response)
            );
        }

        // 401 and 403 are the administrator's problem, not ours and not a
        // transient one: an expired secret, or an app with no role assignment.
        // Retrying either just burns the budget.
        if ($status === 401 || $status === 403) {
            throw new AzureException(
                AzureException::AUTH,
                sprintf('%s refused the credentials (HTTP %d): %s', self::host($url), $status, self::clip($raw)),
                $status
            );
        }

        if ($status >= 400) {
            throw new AzureException(
                AzureException::RESPONSE,
                sprintf('%s returned HTTP %d: %s', self::host($url), $status, self::clip($raw)),
                $status
            );
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new AzureException(
                AzureException::RESPONSE,
                self::host($url) . ' returned a body that is not JSON: ' . self::clip($raw),
                $status
            );
        }

        return $decoded;
    }

    /**
     * Seconds Azure asked us to wait.
     *
     * `Retry-After` is defined as either a number of seconds or an HTTP date;
     * Azure sends the former, but reading only that would fail silently — as a
     * zero — the day something sends the latter.
     */
    public static function retryAfter(ResponseInterface $response): int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return min(300, (int) $value);
        }

        $when = strtotime($value);

        return $when === false ? 0 : max(0, min(300, $when - time()));
    }

    private function client(): GuzzleClient
    {
        return $this->client ??= new GuzzleClient();
    }

    private static function userAgent(): string
    {
        $version = defined('PLUGIN_GLPICLOUDAZURE_VERSION') ? PLUGIN_GLPICLOUDAZURE_VERSION : 'dev';

        return 'glpi-cloud-azure/' . $version . ' (GLPI plugin; read-only inventory)';
    }

    /** Only the host, so an error message never carries a token or a query. */
    private static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    private static function clip(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        return mb_strlen($body) <= 200 ? $body : mb_substr($body, 0, 199) . '…';
    }
}
