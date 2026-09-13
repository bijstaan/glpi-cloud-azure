<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Cost Management: the periods asked for, and the columns read back.
 *
 * The column mapping is the one that would produce plausible wrong numbers on a
 * entity's invoice. Cost Management returns `columns` and `rows` separately,
 * and the order of the columns is not contractual — it varies with the
 * aggregation and grouping asked for. Reading `row[0]` as the cost works right
 * up until it does not, and nothing about the result looks wrong.
 */

require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Glpicloudazure\AzureException;
use GlpiPlugin\Glpicloudazure\Cost;

// ------------------------------------------------------------------ periods

$mid_august = (int) strtotime('2026-08-15 12:00:00');

T::is(Cost::periods($mid_august), ['2026-07' => false, '2026-08' => true], 'the current month is provisional, the one before it is not');
T::is(array_keys(Cost::periods($mid_august, 3)), ['2026-05', '2026-06', '2026-07', '2026-08'], 'a longer backfill walks back month by month');

// January is where a naive "month - 1" arithmetic falls over.
T::is(array_keys(Cost::periods((int) strtotime('2026-01-05'), 2)), ['2025-11', '2025-12', '2026-01'], 'and crosses a year boundary');

T::is(
    Cost::range('2026-02'),
    ['from' => '2026-02-01T00:00:00+00:00', 'to' => '2026-02-28T23:59:59+00:00'],
    'a period covers its whole month, short ones included'
);

T::is(Cost::range('2024-02')['to'], '2024-02-29T23:59:59+00:00', 'and a leap year is a day longer');

// --------------------------------------------------------------------- body

$body = Cost::body('2026-07');

T::is($body['type'], 'ActualCost', 'we ask what was actually billed, not what was forecast');
T::is($body['timeframe'], 'Custom', 'over a period we name');
T::is($body['dataset']['granularity'], 'None', 'one number per resource for the month — this is an inventory column, not a time series');
T::is($body['dataset']['grouping'][0]['name'], 'ResourceId', 'grouped by the id that ties it to a resource');
T::is($body['timePeriod']['from'], '2026-07-01T00:00:00+00:00', 'from the first of the month');

// ----------------------------------------------------------- column mapping

$response = [
    'properties' => [
        // Deliberately not the order anybody would guess.
        'columns' => [
            ['name' => 'Currency', 'type' => 'String'],
            ['name' => 'ResourceId', 'type' => 'String'],
            ['name' => 'Cost', 'type' => 'Number'],
        ],
        'rows' => [
            ['gbp', '/subscriptions/x/vm/web-01', 41.5],
            ['gbp', '', 9.99],
        ],
    ],
];

$rows = Cost::rowsFrom($response, '2026-07', false);

T::is(count($rows), 2, 'every row is read');
T::is($rows[0]['amount'], 41.5, 'the cost comes from the column called Cost, wherever it sits');
T::is($rows[0]['native_id'], '/subscriptions/x/vm/web-01', 'and the resource id from its own');
T::is($rows[0]['currency'], 'GBP', 'currency is upper-cased for the core');
T::is($rows[0]['period'], '2026-07', 'the period is the one asked for');
T::is($rows[0]['is_provisional'], false, 'a closed month is not provisional');
T::is($rows[1]['native_id'], '', 'a charge with no resource id is kept, not dropped — the account total has to match the invoice');

$pretax = Cost::rowsFrom([
    'properties' => [
        'columns' => [['name' => 'PreTaxCost'], ['name' => 'ResourceId'], ['name' => 'BillingCurrency']],
        'rows'    => [[12.0, '/subscriptions/x/vm/web-02', 'usd']],
    ],
], '2026-08', true);

T::is($pretax[0]['amount'], 12.0, 'PreTaxCost is the same column under another name');
T::is($pretax[0]['currency'], 'USD', 'and BillingCurrency is too');
T::is($pretax[0]['is_provisional'], true, 'the month in progress is flagged, so it never reaches a contract');

T::throws(
    static fn() => Cost::rowsFrom(['properties' => ['columns' => [['name' => 'ResourceId']], 'rows' => [['x']]]], '2026-07', false),
    AzureException::RESPONSE,
    'a response with no cost column is refused rather than read as zero'
);

T::is(Cost::rowsFrom(['properties' => ['columns' => [['name' => 'Cost']], 'rows' => []]], '2026-07', false), [], 'a month with no spend is simply empty');

// --------------------------------------------------------------------- walk

$page = static fn(float $amount, ?string $next = null): array => [
    'properties' => array_filter([
        'columns'  => [['name' => 'Cost'], ['name' => 'ResourceId'], ['name' => 'Currency']],
        'rows'     => [[$amount, '/subscriptions/x/vm/web-01', 'gbp']],
        'nextLink' => $next,
    ]),
];

$asked = [];
$query = static function (array $body, ?string $next) use (&$asked, $page): array {
    $asked[] = [$body['subscription'], $body['timePeriod']['from'], $next];

    // One period comes back in two pages, to prove nextLink is followed.
    return count($asked) === 1 ? $page(1.0, 'https://management.azure.com/next') : $page(2.0);
};

$collected = iterator_to_array(Cost::walk(['request' => static fn(): bool => true], $query, ['sub-a', 'sub-b'], $mid_august), false);

// Two subscriptions x two periods is four queries, plus one for the page that
// carried a nextLink.
T::is(count($asked), 5, 'every subscription and period is asked about, and a paged one twice');
T::is($asked[1][2], 'https://management.azure.com/next', 'a continuation URL is followed as given');
T::is(count($collected), 5, 'and every page contributes its rows');
T::is($collected[0]['is_provisional'], false, 'the closed month comes first');
T::ok($collected[count($collected) - 1]['is_provisional'], 'and the provisional one last');

$calls = 0;
$stopped = iterator_to_array(Cost::walk(['request' => static fn(): bool => false], static function (array $b, ?string $n) use (&$calls): array {
    $calls++;

    return [];
}, ['sub-a'], $mid_august), false);

T::is($calls, 0, 'a spent request budget stops cost collection too');
T::is($stopped, [], 'yielding nothing');

exit(T::done('cost'));
