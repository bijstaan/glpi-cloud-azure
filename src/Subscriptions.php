<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

/**
 * The scopes a sweep fans out over.
 *
 * A subscription is Azure's unit of enumeration, and — unlike AWS — region is
 * *not* one: a single Resource Graph query returns resources in every region at
 * once. That difference is the first place the core's model could quietly
 * acquire an AWS shape, which is why glpi-cloud's fixture provider exercises
 * both a single-scope and a multi-scope estate.
 *
 * **Disabled and expired subscriptions are returned, marked inactive.** A
 * subscription that lapsed last month is exactly the thing somebody is looking
 * for when they open this page, and dropping it would make its resources
 * disappear with no record of why.
 */
final class Subscriptions
{
    /**
     * @param array<mixed> $response one page of `GET /subscriptions`
     * @return array<int,array{key:string,name:string,is_active:bool}>
     */
    public static function listFrom(array $response): array
    {
        $out = [];

        foreach ((array) ($response['value'] ?? []) as $row) {
            $row = (array) $row;
            $id  = trim((string) ($row['subscriptionId'] ?? ''));

            if ($id === '') {
                continue;
            }

            $out[] = [
                'key'  => $id,
                'name' => trim((string) ($row['displayName'] ?? $id)),
                // Azure's states are Enabled, Warned, PastDue, Disabled,
                // Deleted. Only the first can be enumerated; the rest are kept
                // so their inventory does not vanish without explanation.
                'is_active' => strcasecmp((string) ($row['state'] ?? ''), 'Enabled') === 0,
            ];
        }

        return $out;
    }

    /**
     * Every subscription this app can see, following `nextLink`.
     *
     * @param array<string,mixed> $account the core's account context
     * @return array<int,array{key:string,name:string,is_active:bool}>
     */
    public static function scopes(array $account, ?Http $http = null): array
    {
        $http        = $http ?? new Http();
        $credentials = array_map(static fn($v): string => (string) $v, (array) ($account['credentials'] ?? []));

        $response = (new Client($credentials, $http))->subscriptions();
        $out      = self::listFrom($response);

        $guard = 0;

        while (($next = trim((string) ($response['nextLink'] ?? ''))) !== '' && $guard++ < 50) {
            $response = $http->getJson($next, ['Authorization' => 'Bearer ' . Auth::token($credentials, $http)]);
            $out      = array_merge($out, self::listFrom($response));
        }

        return $out;
    }

    /**
     * A subscription id is a GUID and nothing else.
     *
     * Checked because it reaches a query body: an id that came out of a
     * response should not be able to become anything but an id.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-fA-F-]{36}$/', $id) === 1;
    }
}
