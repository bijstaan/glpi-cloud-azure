<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

/**
 * Every Azure URL and API version this plugin uses, in one file.
 *
 * One file because these are the things most likely to need changing without
 * any other change: Microsoft retires API versions on a published schedule, and
 * hunting them through five collectors is how a plugin ends up half-migrated.
 *
 * **None of these has been exercised against a live tenant from the environment
 * this was written in.** They are from the published API surface; the code
 * around them reads what the response actually contains rather than assuming
 * shapes, which is the only honest way to write against an API you cannot call
 * yet.
 */
final class Endpoints
{
    public const LOGIN = 'https://login.microsoftonline.com';
    public const ARM   = 'https://management.azure.com';

    /** The resource the token is for; ARM's own scope. */
    public const SCOPE = self::ARM . '/.default';

    public const API_SUBSCRIPTIONS  = '2022-12-01';
    public const API_RESOURCE_GRAPH = '2022-10-01';
    public const API_COST           = '2023-03-01';

    public static function token(string $tenant_id): string
    {
        return sprintf('%s/%s/oauth2/v2.0/token', self::LOGIN, rawurlencode($tenant_id));
    }

    public static function subscriptions(): string
    {
        return sprintf('%s/subscriptions?api-version=%s', self::ARM, self::API_SUBSCRIPTIONS);
    }

    public static function resourceGraph(): string
    {
        return sprintf(
            '%s/providers/Microsoft.ResourceGraph/resources?api-version=%s',
            self::ARM,
            self::API_RESOURCE_GRAPH
        );
    }

    /** Cost Management is queried per scope; a subscription is the scope here. */
    public static function costQuery(string $subscription_id): string
    {
        return sprintf(
            '%s/subscriptions/%s/providers/Microsoft.CostManagement/query?api-version=%s',
            self::ARM,
            rawurlencode($subscription_id),
            self::API_COST
        );
    }
}
