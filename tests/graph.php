<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The Resource Graph sweep: the query it builds, and how it pages.
 *
 * Paging is where this plugin can be wrong in a way nobody notices. A sweep
 * that stops after the first page looks exactly like a small subscription; one
 * that loses its place restarts from the beginning every cron tick and never
 * finishes a large one; one that pages an unsorted query repeats and skips rows,
 * which surfaces as resources flickering in and out of an estate.
 */

require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Glpicloudazure\AzureException;
use GlpiPlugin\Glpicloudazure\Graph;
use GlpiPlugin\Glpicloudazure\Types;

const SUB = '11111111-2222-3333-4444-555555555555';

$fixtures = fixture('graph-rows.json');

/** A context of the shape glpi-cloud hands a provider. */
function ctx(array $overrides = []): array
{
    $recorded = [];

    return array_merge([
        'scope'       => SUB,
        'cursor'      => null,
        'deadline'    => time() + 300,
        'checkpoint'  => static function (?string $cursor) use (&$recorded): void {
        },
        'request'     => static fn(): bool => true,
    ], $overrides);
}

// ------------------------------------------------------------------- the query

$kql = Graph::kql('compute');

T::ok(str_contains($kql, 'order by id asc'), 'every query is sorted — an unsorted paged query repeats and skips rows');
T::ok(str_contains($kql, "type in~ ("), 'a service asks for the types it claims');
T::ok(str_contains($kql, "'microsoft.compute/virtualmachines'"), 'including virtual machines');
T::ok(!str_contains($kql, SUB), 'the subscription is not interpolated into Kusto — it travels in the body');
T::ok(str_contains($kql, 'properties'), 'the payload is projected, which is where a VM power state lives');

$other = Graph::kql(Types::OTHER);

T::ok(str_contains($other, 'type !in~ ('), 'other asks for the complement of everything claimed');

foreach (Types::claimed() as $claimed) {
    if (!str_contains($other, "'" . $claimed . "'")) {
        T::ok(false, sprintf('other excludes %s', $claimed));
        break;
    }
}
T::ok(true, 'other excludes every claimed type — nothing falls down the gap');

T::throws(static fn() => Graph::kql('nonsense'), AzureException::RESPONSE, 'a service claiming no types is a programming error, not an empty sweep');

// -------------------------------------------------------------------- the body

$body = Graph::body(SUB, 'resources', null);

T::is($body['subscriptions'], [SUB], 'the subscription travels in the body');
T::is($body['options']['$top'], Graph::PAGE, 'a full page is asked for');
T::is($body['options']['resultFormat'], 'objectArray', 'rows come back as objects, not positional arrays');
T::ok(!isset($body['options']['$skipToken']), 'a first page carries no continuation token');

T::is(Graph::body(SUB, 'resources', 'TOKEN-1')['options']['$skipToken'], 'TOKEN-1', 'a resumed page carries the one it was given');

// ---------------------------------------------------------------- the response

T::is(Graph::skipTokenFrom(['$skipToken' => 'a']), 'a', 'the documented spelling is read');
T::is(Graph::skipTokenFrom(['skipToken' => 'b']), 'b', 'and so is the other one seen in the wild');
T::is(Graph::skipTokenFrom(['$skipToken' => '  ']), null, 'an empty token means the end');
T::is(Graph::skipTokenFrom([]), null, 'so does no token at all');

T::is(count(Graph::rowsFrom(['data' => [['id' => 'a'], 'not a row', ['id' => 'b']]])), 2, 'a row that is not a row is skipped');
T::throws(static fn() => Graph::rowsFrom(['data' => 'nonsense']), AzureException::RESPONSE, 'a response with no data array is unusable');

// -------------------------------------------------------------------- paging

$pages = [
    ['data' => [$fixtures['running_vm'], $fixtures['stopped_vm']], '$skipToken' => 'TOKEN-1'],
    ['data' => [$fixtures['disk'], $fixtures['storage_account']], '$skipToken' => 'TOKEN-2'],
    ['data' => [$fixtures['app_service']]],
];

$asked       = [];
$checkpoints = [];

$context = ctx([
    'checkpoint' => static function (?string $cursor) use (&$checkpoints): void {
        $checkpoints[] = $cursor;
    },
]);

$query = static function (array $body) use (&$asked, $pages): array {
    $asked[] = $body['options']['$skipToken'] ?? null;

    return $pages[count($asked) - 1];
};

$collected = iterator_to_array(Graph::walk($context, $query, 'compute'), false);

T::is(count($collected), 5, 'every row of every page is yielded');
T::is($asked, [null, 'TOKEN-1', 'TOKEN-2'], 'each page is asked for with the token the last one gave');
T::is($checkpoints, ['TOKEN-1', 'TOKEN-2', null], 'each token is checkpointed, and the end clears it');
T::is($collected[0]['name'], 'web-01', 'and the rows arrive normalised');

// ---------------------------------------------------------------- resumption

$asked = [];
$resumed = iterator_to_array(Graph::walk(ctx(['cursor' => 'TOKEN-1']), static function (array $body) use (&$asked, $pages): array {
    $asked[] = $body['options']['$skipToken'] ?? null;

    return $pages[count($asked)];
}, 'compute'), false);

T::is($asked[0], 'TOKEN-1', 'a resumed sweep starts from the stored cursor, not from the beginning');
T::is(count($resumed), 3, 'and collects only what is left');

// ------------------------------------------------- the two ways to be stopped

$calls = 0;
$broke = iterator_to_array(Graph::walk(ctx(['request' => static fn(): bool => false]), static function (array $body) use (&$calls, $pages): array {
    $calls++;

    return $pages[0];
}, 'compute'), false);

T::is($calls, 0, 'a spent request budget stops the sweep before it asks for anything');
T::is($broke, [], 'and yields nothing');

$calls = 0;
$out_of_time = iterator_to_array(Graph::walk(ctx(['deadline' => time() - 1]), static function (array $body) use (&$calls, $pages): array {
    $calls++;

    return $pages[0];
}, 'compute'), false);

T::is($calls, 1, 'a sweep past its deadline finishes the page it fetched');
T::is(count($out_of_time), 2, 'yields what that page held');

// ------------------------------------------------------------- the subscription

T::throws(
    static fn() => Graph::walk(ctx(['scope' => 'not-a-subscription']), static fn(array $b): array => [], 'compute'),
    AzureException::RESPONSE,
    'a scope that is not a subscription id never reaches a request'
);

exit(T::done('graph'));
