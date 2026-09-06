<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

use Generator;

/**
 * The sweep: one Resource Graph query per service, paged.
 *
 * Resource Graph is why Azure is cheap to do first — one endpoint returns every
 * resource in every region of a subscription, rather than a per-service
 * enumeration each with its own pagination. What it does *not* do is partition
 * by service, so each of this plugin's services asks for its own slice with a
 * `type in~` filter, and `other` asks for everything the rest did not claim.
 *
 * That costs one query per service instead of one overall, and buys the two
 * things the core's contract is built on: a failing service cannot cost us the
 * inventory of the others, and the `service` column means something.
 *
 * ### Paging, and the trap in it
 *
 * Pages come back with a `$skipToken`, which is handed straight to the core's
 * checkpoint and never looked inside. **The query must be sorted for paging to
 * be sound** — an unsorted paged query can repeat or skip rows between pages,
 * which here would look like resources flickering in and out of an estate. So
 * every query ends `| order by id asc`.
 *
 * ### The bits that are unverified
 *
 * `properties.extended.instanceView.powerState.code` is where a VM's power
 * state appears in Resource Graph. It is projected as part of `properties`
 * rather than asked for specially, so if a tenant does not return it the state
 * ladder in {@see Rows} falls through to the next field instead of failing.
 */
final class Graph
{
    /** Rows per page. Resource Graph's documented ceiling is 1000. */
    public const PAGE = 1000;

    /** Pages one call to walk() will fetch before insisting on a fresh run. */
    private const MAX_PAGES = 200;

    /**
     * @param array<string,mixed>            $ctx   the core's collection context
     * @param callable(array<mixed>):array<mixed> $query one Resource Graph POST
     * @return Generator<int,array<string,mixed>>
     */
    public static function walk(array $ctx, callable $query, string $service): Generator
    {
        $subscription = (string) ($ctx['scope'] ?? '');

        if (!Subscriptions::isValidId($subscription)) {
            throw new AzureException(AzureException::RESPONSE, sprintf('"%s" is not a subscription id.', $subscription));
        }

        $cursor = $ctx['cursor'] ?? null;
        $kql    = self::kql($service);
        $pages  = 0;

        while ($pages++ < self::MAX_PAGES) {
            // The core's request budget. A provider that ignores it is the
            // reason the core keeps an outer bound of its own.
            if (isset($ctx['request']) && !($ctx['request'])()) {
                return;
            }

            $response = $query(self::body($subscription, $kql, is_string($cursor) ? $cursor : null));

            foreach (self::rowsFrom($response) as $row) {
                $normalised = Rows::fromGraph($row);

                if ($normalised !== null) {
                    yield $normalised;
                }
            }

            $cursor = self::skipTokenFrom($response);

            if ($cursor === null) {
                // The sweep of this service is complete. Clearing the cursor is
                // the core's business — it does that when the unit finishes
                // cleanly — but saying so costs nothing and makes a resumed run
                // that ends here leave no stale token behind.
                if (isset($ctx['checkpoint'])) {
                    ($ctx['checkpoint'])(null);
                }

                return;
            }

            if (isset($ctx['checkpoint'])) {
                ($ctx['checkpoint'])($cursor);
            }

            // The core stops consuming when its clock runs out; this is the
            // provider half of the same agreement, so a page is never fetched
            // that nobody will read.
            if (isset($ctx['deadline']) && time() >= (int) $ctx['deadline']) {
                return;
            }
        }
    }

    /**
     * The query for one service.
     *
     * Nothing but this plugin's own constants is interpolated: the subscription
     * goes in the request body, not the query text, so nothing that came out of
     * an API response is ever concatenated into Kusto.
     */
    public static function kql(string $service): string
    {
        $projection = 'project id, name, type, location, resourceGroup, subscriptionId, tags, properties, sku, kind, identity, zones, managedBy';

        if ($service === Types::OTHER) {
            return sprintf(
                "resources\n| where type !in~ (%s)\n| %s\n| order by id asc",
                self::quote(Types::claimed()),
                $projection
            );
        }

        $types = Types::azureTypesFor($service);

        if ($types === []) {
            throw new AzureException(AzureException::RESPONSE, sprintf('service "%s" claims no Azure types.', $service));
        }

        return sprintf(
            "resources\n| where type in~ (%s)\n| %s\n| order by id asc",
            self::quote($types),
            $projection
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function body(string $subscription, string $kql, ?string $cursor): array
    {
        $options = [
            '$top'         => self::PAGE,
            'resultFormat' => 'objectArray',
        ];

        if ($cursor !== null && $cursor !== '') {
            $options['$skipToken'] = $cursor;
        }

        return [
            'subscriptions' => [$subscription],
            'query'         => $kql,
            'options'       => $options,
        ];
    }

    /**
     * @param array<mixed> $response
     * @return array<int,array<string,mixed>>
     */
    public static function rowsFrom(array $response): array
    {
        $data = $response['data'] ?? [];

        if (!is_array($data)) {
            throw new AzureException(AzureException::RESPONSE, 'Resource Graph returned no usable data array.');
        }

        $out = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * The continuation token, wherever this API version puts it.
     *
     * Both spellings are accepted because the documented shape has moved
     * between API versions and a sweep that silently stopped after one page
     * would look exactly like a small estate.
     *
     * @param array<mixed> $response
     */
    public static function skipTokenFrom(array $response): ?string
    {
        foreach ([$response['$skipToken'] ?? null, $response['skipToken'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** @param array<int,string> $values */
    private static function quote(array $values): string
    {
        return implode(', ', array_map(
            static fn(string $value): string => "'" . str_replace("'", '', $value) . "'",
            $values
        ));
    }
}
