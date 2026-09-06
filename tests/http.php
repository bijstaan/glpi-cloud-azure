<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Transport behaviour: throttles, refusals, and the token cache.
 *
 * Needs Guzzle, which comes from GLPI's vendor tree, so this one runs in the
 * container rather than in the pure suites:
 *
 *   docker compose -p glpi exec glpi php /var/www/glpi/plugins/glpicloudazure/tests/http.php
 *
 * No network: Guzzle's own MockHandler answers every request. What is being
 * tested is the decision each answer produces — whether to wait, how long, and
 * whether to bother — because getting those wrong is how a plugin reports "your
 * firewall is blocking Azure" when Azure said "slow down", and how a sweep
 * spends its whole budget retrying an expired client secret.
 */

$autoload = '/var/www/glpi/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "This has to run inside the GLPI container (it needs Guzzle from GLPI's vendor tree).\n");
    exit(2);
}

require_once $autoload;
require_once __DIR__ . '/bootstrap.php';
require_once AZ_SRC . '/Http.php';
require_once AZ_SRC . '/Auth.php';
require_once AZ_SRC . '/Client.php';

use GlpiPlugin\Glpicloudazure\Auth;
use GlpiPlugin\Glpicloudazure\AzureException;
use GlpiPlugin\Glpicloudazure\Http;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Where the waits go instead of into sleep().
 *
 * A static rather than a by-reference parameter, because the cases below build
 * their client inside an arrow function — which captures by value, so a
 * `&$slept` handed in there would record into a copy and every assertion about
 * waiting would pass by accident.
 */
final class Slept
{
    /** @var float[] */
    public static array $waits = [];

    public static function reset(): void
    {
        self::$waits = [];
    }
}

/**
 * An Http wired to canned answers, with the sleeps recorded rather than slept.
 *
 * @param array<int,mixed> $answers
 */
function wired(array $answers, ?int $deadline = null, int $retries = 2): Http
{
    $handler = HandlerStack::create(new MockHandler($answers));

    return new Http(5, $deadline, $retries, static function (float $seconds): void {
        Slept::$waits[] = $seconds;
    }, new GuzzleClient(['handler' => $handler]));
}

$json = static fn(array $body, int $status = 200, array $headers = []): Response
    => new Response($status, $headers + ['Content-Type' => 'application/json'], (string) json_encode($body));

// ------------------------------------------------------------ Retry-After

Slept::reset();
$http  = wired([
    new Response(429, ['Retry-After' => '7'], 'slow down'),
    $json(['ok' => true]),
]);

T::is($http->getJson('https://management.azure.com/x'), ['ok' => true], 'a throttled request is retried and succeeds');
T::is(Slept::$waits, [7.0], 'having waited exactly as long as Azure asked');

Slept::reset();
$http  = wired([
    new Response(429, [], 'slow down'),
    $json(['ok' => true]),
]);

$http->getJson('https://management.azure.com/x');
T::is(Slept::$waits, [2.0], 'a throttle with no Retry-After falls back to a small backoff');

// A wait that would run past the end of the sweep is not worth taking: the
// cursor is stored and the next run costs nothing.
Slept::reset();
T::throws(
    static fn() => wired([new Response(429, ['Retry-After' => '120'], '')], time() + 10)->getJson('https://management.azure.com/x'),
    AzureException::THROTTLED,
    'a wait that runs past the deadline is refused rather than taken'
);
T::is(Slept::$waits, [], 'and nothing is slept');

// Retries are bounded.
Slept::reset();
T::throws(
    static fn() => wired(array_fill(0, 5, new Response(429, ['Retry-After' => '1'], '')), null, 2)->getJson('https://management.azure.com/x'),
    AzureException::THROTTLED,
    'a service that keeps throttling eventually gives up'
);
T::is(count(Slept::$waits), 2, 'after the configured number of retries');

// ------------------------------------------------------------- Retry-After parsing

T::is(Http::retryAfter(new Response(429, ['Retry-After' => '30'])), 30, 'a number of seconds is read');
T::is(Http::retryAfter(new Response(429, ['Retry-After' => '99999'])), 300, 'an absurd one is capped');
T::is(Http::retryAfter(new Response(429)), 0, 'a missing header is no wait');
T::ok(Http::retryAfter(new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 20)])) > 0, 'an HTTP date is read too, rather than silently becoming zero');

// ------------------------------------------------------- refusals and rubbish

Slept::reset();
T::throws(
    static fn() => wired([new Response(401, [], 'expired'), $json(['ok' => true])])->getJson('https://management.azure.com/x'),
    AzureException::AUTH,
    'a 401 is the administrator\'s problem'
);
T::is(Slept::$waits, [], 'and is not retried — retrying an expired secret only burns the budget');

T::throws(
    static fn() => wired([new Response(403, [], 'no role assignment')])->getJson('https://management.azure.com/x'),
    AzureException::AUTH,
    'so is a 403, which is what a missing role assignment looks like'
);

T::throws(
    static fn() => wired([new Response(500, [], 'boom')])->getJson('https://management.azure.com/x'),
    AzureException::RESPONSE,
    'a server error is a response failure'
);

T::throws(
    static fn() => wired([new Response(200, ['Content-Type' => 'text/html'], '<html>hello</html>')])->getJson('https://management.azure.com/x'),
    AzureException::RESPONSE,
    'a 200 that is not JSON is unusable'
);

// Two failures against one retry: the first is retried, the second is reported.
Slept::reset();
$unreachable = static fn(): ConnectException => new ConnectException('dns failed', new Request('GET', 'https://management.azure.com/x'));

T::throws(
    static fn() => wired([$unreachable(), $unreachable()], null, 1)->getJson('https://management.azure.com/x'),
    AzureException::TRANSPORT,
    'an unreachable endpoint is transport, not credentials'
);
T::is(count(Slept::$waits), 1, 'and is retried once before being reported');

// Error text must never carry a query string: tokens and continuation tokens
// travel in those, and this text ends up in a run log an operator reads.
try {
    wired([new Response(500, [], 'boom')])->getJson('https://management.azure.com/x?code=SECRET-VALUE');
} catch (AzureException $e) {
    T::ok(!str_contains($e->getMessage(), 'SECRET-VALUE'), 'an error names the host and never the query string');
}

// ------------------------------------------------------------------ the token

Auth::forget();
$credentials = ['tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'shh'];

$http = wired([
    $json(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
    $json(['access_token' => 'TOKEN-B', 'expires_in' => 3600]),
]);

T::is(Auth::token($credentials, $http), 'TOKEN-A', 'a token is fetched');
T::is(Auth::token($credentials, $http), 'TOKEN-A', 'and reused, rather than fetched again for every request');

// A token near its expiry is spent: using it would fail mid-sweep for no reason.
Auth::forget();
$http = wired([
    $json(['access_token' => 'TOKEN-A', 'expires_in' => 30]),
    $json(['access_token' => 'TOKEN-B', 'expires_in' => 3600]),
]);

T::is(Auth::token($credentials, $http), 'TOKEN-A', 'a short-lived token is used');
T::is(Auth::token($credentials, $http), 'TOKEN-B', 'and replaced before it expires, not after');

// Rotating the secret must take effect immediately.
Auth::forget();
$http = wired([
    $json(['access_token' => 'TOKEN-A', 'expires_in' => 3600]),
    $json(['access_token' => 'TOKEN-C', 'expires_in' => 3600]),
]);

Auth::token($credentials, $http);
T::is(Auth::token(['tenant_id' => 'tenant-1', 'client_id' => 'client-1', 'client_secret' => 'rotated'], $http), 'TOKEN-C', 'a rotated secret is not answered from the cache');

Auth::forget();
T::throws(
    static fn() => Auth::token($credentials, wired([new Response(400, [], (string) json_encode(['error' => 'invalid_client', 'error_description' => 'secret expired']))])),
    AzureException::AUTH,
    'Entra answers a bad secret with 400, and it is reported as a credential problem'
);

Auth::forget();
T::throws(
    static fn() => Auth::token(['tenant_id' => 'tenant-1'], wired([])),
    AzureException::AUTH,
    'and missing credentials never reach the network at all'
);

Auth::forget();
T::throws(
    static fn() => Auth::token($credentials, wired([$json(['token_type' => 'Bearer'])])),
    AzureException::AUTH,
    'a token response with no token is a credential problem, not a parse error'
);

Auth::forget();

exit(T::done('http'));
