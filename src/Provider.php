<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

/**
 * What this plugin tells glpi-cloud it can do.
 *
 * The whole contract is this one descriptor: scalars, plus callables. Nothing
 * here extends or implements a class of the core's, because a class whose
 * parent lives in another plugin is a fatal at autoload the moment that plugin
 * is deactivated or half-upgraded — and it takes out every page that touches
 * it, not just this one.
 *
 * Read `collect()` and `costs()` as the worked example: the collectors take a
 * plain callable rather than a client, so the parts worth testing — paging,
 * resumption, column mapping — are tested with no HTTP, no Guzzle and no
 * tenant. This class is the wiring that makes those callables real.
 */
final class Provider
{
    /** Seconds before one Azure request is abandoned. */
    private const TIMEOUT = 20;

    /** @return array<string,mixed> */
    public static function describe(): array
    {
        return [
            'key'      => 'azure',
            'name'     => 'Microsoft Azure',
            'icon'     => 'ti ti-brand-azure',
            'supplier' => 'Microsoft',

            'credentials' => [
                [
                    'key'      => 'tenant_id',
                    'label'    => __('Directory (tenant) ID', 'glpicloudazure'),
                    'required' => true,
                    'help'     => __('From the app registration overview in Entra ID.', 'glpicloudazure'),
                ],
                [
                    'key'      => 'client_id',
                    'label'    => __('Application (client) ID', 'glpicloudazure'),
                    'required' => true,
                    'help'     => __('The app registration this GLPI uses. Give it Reader, and Cost Management Reader if you want spend — both are read-only roles. This plugin never writes to Azure.', 'glpicloudazure'),
                ],
                [
                    'key'      => 'client_secret',
                    'label'    => __('Client secret', 'glpicloudazure'),
                    'secret'   => true,
                    'required' => true,
                    'help'     => __('az role assignment create --assignee <client-id> --role Reader --scope /subscriptions/<subscription-id>', 'glpicloudazure'),
                ],
            ],

            'check'  => [self::class, 'check'],
            'scopes' => [self::class, 'scopes'],

            'services' => array_map(
                static function (array $service): array {
                    $key = $service['key'];

                    return $service + [
                        'collect' => static fn(array $ctx): iterable => self::collect($key, $ctx),
                    ];
                },
                Types::services()
            ),

            'costs' => [self::class, 'costs'],
        ];
    }

    /**
     * @param array<string,mixed> $account
     * @return array{ok:bool,message:string,identity:string}
     */
    public static function check(array $account): array
    {
        return Auth::verify($account, self::http($account));
    }

    /**
     * @param array<string,mixed> $account
     * @return array<int,array{key:string,name:string,is_active:bool}>
     */
    public static function scopes(array $account): array
    {
        return Subscriptions::scopes($account, self::http($account));
    }

    /**
     * @param array<string,mixed> $ctx
     */
    public static function collect(string $service, array $ctx): iterable
    {
        $client = self::client($ctx);

        return Graph::walk(
            $ctx,
            static fn(array $body): array => $client->graph($body),
            $service
        );
    }

    /**
     * @param array<string,mixed> $ctx
     */
    public static function costs(array $ctx): iterable
    {
        $http   = self::http($ctx);
        $client = self::client($ctx);

        // Cost is asked for per account rather than per scope, so the
        // subscription list is ours to fetch. Only enabled subscriptions are
        // billed against; a disabled one answers with an empty result and a
        // wasted request.
        $subscriptions = [];

        foreach (Subscriptions::scopes($ctx, $http) as $scope) {
            if ($scope['is_active']) {
                $subscriptions[] = $scope['key'];
            }
        }

        return Cost::walk(
            $ctx,
            static function (array $body, ?string $next) use ($client, $http, $ctx): array {
                $subscription = (string) $body['subscription'];
                unset($body['subscription']);

                if ($next !== null) {
                    // A continuation URL is followed as given; rebuilding it
                    // from parts is how a paged cost query silently restarts.
                    return $http->postJson($next, $body, [
                        'Authorization' => 'Bearer ' . Auth::token(self::credentials($ctx), $http),
                    ]);
                }

                return $client->costQuery($subscription, $body);
            },
            $subscriptions
        );
    }

    /** @param array<string,mixed> $ctx */
    private static function client(array $ctx): Client
    {
        return new Client(self::credentials($ctx), self::http($ctx));
    }

    /**
     * An HTTP client that knows when the sweep has to stop.
     *
     * The deadline is what turns "honour Retry-After" into something safe: a
     * throttle that would be waited out past the end of the run is thrown
     * instead, the cursor is already stored, and the next run picks it up.
     *
     * @param array<string,mixed> $ctx
     */
    private static function http(array $ctx): Http
    {
        $deadline = isset($ctx['deadline']) ? (int) $ctx['deadline'] : null;

        return new Http(self::TIMEOUT, $deadline);
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<string,string>
     */
    private static function credentials(array $ctx): array
    {
        return array_map(static fn($v): string => (string) $v, (array) ($ctx['credentials'] ?? []));
    }
}
