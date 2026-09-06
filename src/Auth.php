<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

/**
 * Getting a bearer token, and keeping it only as long as it is useful.
 *
 * Client credentials against Entra ID: the app registration's tenant, client id
 * and secret, exchanged for a token scoped to ARM. There is no user, no device
 * code and no interactive step, which is the whole reason this works from a
 * cron.
 *
 * **Tokens are cached in memory for the process and never written down.**
 * Persisting one buys nothing — they last an hour and a fresh one is a single
 * request — and it would create a second class of secret at rest, in a plugin
 * whose entire security story is "the only credential is a read-only one,
 * encrypted, in one column".
 *
 * The cache key includes a hash of the secret, so rotating a client secret
 * takes effect on the next request rather than after a PHP restart.
 */
final class Auth
{
    /** @var array<string,array{token:string,expires:int}> */
    private static array $cache = [];

    /** Seconds before expiry at which a token is considered spent. */
    private const SKEW = 60;

    /**
     * @param array<string,string> $credentials
     */
    public static function token(array $credentials, Http $http, ?int $now = null): string
    {
        $now = $now ?? time();

        [$tenant, $client, $secret] = self::parts($credentials);

        $key = hash('sha256', $tenant . '|' . $client . '|' . $secret);

        if (isset(self::$cache[$key]) && self::$cache[$key]['expires'] > $now + self::SKEW) {
            return self::$cache[$key]['token'];
        }

        try {
            $response = $http->postForm(Endpoints::token($tenant), [
                'grant_type'    => 'client_credentials',
                'client_id'     => $client,
                'client_secret' => $secret,
                'scope'         => Endpoints::SCOPE,
            ]);
        } catch (AzureException $e) {
            // Entra answers bad credentials with 400 and an error_description,
            // not with 401. Left as a RESPONSE failure it would look like a
            // bug in this plugin rather than an expired secret.
            if ($e->kind === AzureException::RESPONSE && $e->status === 400) {
                throw new AzureException(AzureException::AUTH, 'Entra ID refused the credentials: ' . $e->getMessage(), 400);
            }

            throw $e;
        }

        $token = trim((string) ($response['access_token'] ?? ''));

        if ($token === '') {
            throw new AzureException(AzureException::AUTH, 'Entra ID returned no access token.');
        }

        $expires = (int) ($response['expires_in'] ?? 0);

        self::$cache[$key] = [
            'token'   => $token,
            'expires' => $now + ($expires > 0 ? $expires : 3600),
        ];

        return $token;
    }

    /** For tests, and for a secret rotated inside one long-running process. */
    public static function forget(): void
    {
        self::$cache = [];
    }

    /**
     * Prove the credentials work, and say what they turned out to be.
     *
     * Deliberately more than a token: a token proves the app exists, and the
     * failure this check is for is the one where it exists and has been given
     * no role assignment, which only shows up when you ask it to list
     * something.
     *
     * @param array<string,mixed> $account the core's account context
     * @return array{ok:bool,message:string,identity:string}
     */
    public static function verify(array $account, ?Http $http = null): array
    {
        $credentials = (array) ($account['credentials'] ?? []);
        $http        = $http ?? new Http();

        try {
            [$tenant] = self::parts($credentials);

            $client = new Client($credentials, $http);
            $scopes = Subscriptions::listFrom($client->subscriptions());
        } catch (AzureException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'identity' => ''];
        }

        if ($scopes === []) {
            return [
                'ok'      => false,
                'message' => 'Authenticated, but this app can see no subscriptions. Assign it Reader at the subscription or management-group scope.',
                'identity' => '',
            ];
        }

        return [
            'ok'       => true,
            'message'  => 'ok',
            'identity' => sprintf('tenant %s, %d subscription(s)', $tenant, count($scopes)),
        ];
    }

    /**
     * @param array<string,string> $credentials
     * @return array{0:string,1:string,2:string}
     */
    private static function parts(array $credentials): array
    {
        $tenant = trim((string) ($credentials['tenant_id'] ?? ''));
        $client = trim((string) ($credentials['client_id'] ?? ''));
        $secret = (string) ($credentials['client_secret'] ?? '');

        if ($tenant === '' || $client === '' || $secret === '') {
            throw new AzureException(AzureException::AUTH, 'This account is missing its tenant id, client id or client secret.');
        }

        return [$tenant, $client, $secret];
    }
}
