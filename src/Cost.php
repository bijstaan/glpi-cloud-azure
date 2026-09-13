<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

use Generator;

/**
 * What Azure billed, per resource, per month.
 *
 * Cost Management's query API, one call per subscription per period, grouped by
 * `ResourceId`. Three things about it are not obvious and all three are the
 * difference between a cost feature that reconciles with the invoice and one
 * that quietly does not.
 *
 * **Columns are named, not positional.** The response carries
 * `properties.columns` and `properties.rows`, and the column *order* is not
 * contractual — it varies with the aggregation and grouping asked for, and has
 * changed between API versions. Reading `row[0]` as the cost is the kind of bug
 * that produces plausible numbers on an entity's invoice, so everything here
 * is looked up by column name.
 *
 * **A month is re-queried until it stops changing.** Azure restates a closed
 * month for days afterwards. The core's cost table upserts on (account,
 * resource, period, currency), so re-querying is free; what matters is that the
 * *current* month is flagged provisional, because glpi-cloud refuses to roll a
 * provisional period onto a contract and a mid-month figure on a contract is
 * read as a bill by everything downstream of it.
 *
 * **Charges with no resource id are kept.** Marketplace purchases, support
 * plans and reservation charges have none. They are yielded with an empty
 * `native_id`, which the core stores against the account itself — an account
 * total that does not add up to the invoice will not be trusted twice.
 */
final class Cost
{
    /** Periods fetched per run: the current month, plus this many before it. */
    public const MONTHS_BACK = 1;

    /** Pages of one period's rows before giving up on a runaway response. */
    private const MAX_PAGES = 50;

    /**
     * The months to ask about, newest last, with whether each is provisional.
     *
     * @return array<string,bool> period => is_provisional
     */
    public static function periods(?int $now = null, int $months_back = self::MONTHS_BACK): array
    {
        $now     = $now ?? time();
        $current = date('Y-m', $now);
        $out     = [];

        for ($i = $months_back; $i >= 1; $i--) {
            $out[date('Y-m', (int) strtotime(sprintf('%s-01 -%d month', $current, $i)))] = false;
        }

        // The month in progress. Real spend, not yet a bill.
        $out[$current] = true;

        return $out;
    }

    /** @return array{from:string,to:string} the inclusive day range of a period */
    public static function range(string $period): array
    {
        $first = $period . '-01';

        return [
            'from' => $first . 'T00:00:00+00:00',
            'to'   => date('Y-m-t', (int) strtotime($first)) . 'T23:59:59+00:00',
        ];
    }

    /** @return array<string,mixed> the query body for one period */
    public static function body(string $period): array
    {
        $range = self::range($period);

        return [
            'type'      => 'ActualCost',
            'timeframe' => 'Custom',
            'timePeriod' => [
                'from' => $range['from'],
                'to'   => $range['to'],
            ],
            'dataset' => [
                // One number per resource for the whole month: this is an
                // inventory's cost column, not a time series.
                'granularity' => 'None',
                'aggregation' => [
                    'totalCost' => ['name' => 'Cost', 'function' => 'Sum'],
                ],
                'grouping' => [
                    ['type' => 'Dimension', 'name' => 'ResourceId'],
                ],
            ],
        ];
    }

    /**
     * Turn one response into cost rows.
     *
     * @param array<mixed> $response
     * @return array<int,array<string,mixed>>
     */
    public static function rowsFrom(array $response, string $period, bool $provisional): array
    {
        $properties = (array) ($response['properties'] ?? []);
        $columns    = (array) ($properties['columns'] ?? []);
        $rows       = (array) ($properties['rows'] ?? []);

        $index = [];

        foreach ($columns as $position => $column) {
            $name = strtolower(trim((string) (((array) $column)['name'] ?? '')));

            if ($name !== '') {
                $index[$name] = (int) $position;
            }
        }

        // The cost column is named for what was asked for and for the currency
        // it is in; all three spellings appear in the wild.
        $cost_at = null;
        foreach (['cost', 'pretaxcost', 'costusd'] as $candidate) {
            if (isset($index[$candidate])) {
                $cost_at = $index[$candidate];
                break;
            }
        }

        if ($cost_at === null) {
            throw new AzureException(
                AzureException::RESPONSE,
                'Cost Management returned no cost column; got: ' . implode(', ', array_keys($index))
            );
        }

        $resource_at = $index['resourceid'] ?? null;
        $currency_at = $index['currency'] ?? ($index['billingcurrency'] ?? null);

        $out = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            $out[] = [
                'native_id'      => $resource_at === null ? '' : trim((string) ($row[$resource_at] ?? '')),
                'period'         => $period,
                'currency'       => $currency_at === null ? '' : strtoupper(trim((string) ($row[$currency_at] ?? ''))),
                'amount'         => (float) ($row[$cost_at] ?? 0),
                'source'         => 'costmanagement',
                'is_provisional' => $provisional,
            ];
        }

        return $out;
    }

    /**
     * Every cost row for every scope this account can see.
     *
     * @param array<string,mixed>                          $ctx
     * @param callable(array<mixed>,?string):array<mixed>  $query body, or a nextLink
     * @param array<int,string>                            $subscriptions
     * @return Generator<int,array<string,mixed>>
     */
    public static function walk(array $ctx, callable $query, array $subscriptions, ?int $now = null): Generator
    {
        foreach ($subscriptions as $subscription) {
            foreach (self::periods($now) as $period => $provisional) {
                $body = self::body((string) $period);
                $next = null;
                $page = 0;

                do {
                    if (isset($ctx['request']) && !($ctx['request'])()) {
                        return;
                    }

                    $response = $query(['subscription' => $subscription] + $body, $next);

                    foreach (self::rowsFrom($response, (string) $period, (bool) $provisional) as $row) {
                        yield $row;
                    }

                    $next = trim((string) (((array) ($response['properties'] ?? []))['nextLink'] ?? ''));
                    $next = $next === '' ? null : $next;
                } while ($next !== null && ++$page < self::MAX_PAGES);
            }
        }
    }
}
