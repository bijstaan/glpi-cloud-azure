<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The descriptor, validated by the thing that will actually validate it.
 *
 * Asserting the shape of our own array here would prove nothing: the registry
 * on the other side of the seam is what decides whether this provider is
 * usable, and it drops what it cannot use with a warning that an administrator
 * has to go looking for. So this suite loads **glpi-cloud's own Registry** and
 * requires that it accepts every part of what this plugin offers.
 *
 * It is skipped, rather than failed, when the core plugin is not checked out
 * beside this one — that is a workspace layout, not a defect.
 */

require_once __DIR__ . '/bootstrap.php';

// Both layouts this plugin lives in: the repo, where the core is a sibling
// directory named glpi-cloud, and a GLPI installation, where it is a sibling
// plugin named glpicloud. Skipping in one of them would mean the suite quietly
// stopped covering anything the moment it was run where it matters most.
$core = null;

foreach (['/glpi-cloud/src', '/glpicloud/src'] as $candidate) {
    $path = dirname(__DIR__, 2) . $candidate;

    if (is_file($path . '/Registry.php')) {
        $core = $path;
        break;
    }
}

if ($core === null) {
    printf("%-14s skipped (the glpi-cloud core is not beside this plugin)\n", 'descriptor');
    exit(0);
}

require_once $core . '/Provider.php';
require_once $core . '/Registry.php';
require_once AZ_SRC . '/Provider.php';

use GlpiPlugin\Glpicloud\Registry;
use GlpiPlugin\Glpicloudazure\Provider;
use GlpiPlugin\Glpicloudazure\Types;

$warnings = [];
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

$GLOBALS['PLUGIN_HOOKS']['glpicloud_providers'] = ['glpicloudazure' => [Provider::class, 'describe']];
Registry::reset();
$providers = Registry::providers();

restore_error_handler();

T::is($warnings, [], 'the core accepts the descriptor without complaint');
T::is(array_keys($providers), ['azure'], 'and registers it as azure');

$azure = $providers['azure'] ?? null;

if ($azure === null) {
    exit(T::done('descriptor'));
}

T::is($azure->name(), 'Microsoft Azure', 'under its display name');
T::is($azure->supplier(), 'Microsoft', 'with the supplier the contract will point at');
T::is($azure->plugin(), 'glpicloudazure', 'attributed to this plugin');
T::ok($azure->hasCosts(), 'offering cost');

// Every service survived validation — a service dropped here is inventory that
// silently never gets collected.
T::is(
    array_column($azure->services(), 'key'),
    array_column(Types::services(), 'key'),
    'every service this plugin claims survived the core validation'
);

T::ok($azure->hasService(Types::OTHER), 'including the one that catches everything unclaimed');

// Credentials: three fields, exactly one of them secret.
$fields = $azure->credentialFields();
$secret = array_values(array_filter($fields, static fn(array $f): bool => $f['secret']));

T::is(count($fields), 3, 'three credential fields');
T::is(count($secret), 1, 'exactly one of which is a secret');
T::is($secret[0]['key'], 'client_secret', 'and it is the client secret');

foreach ($fields as $field) {
    T::ok($field['label'] !== '', sprintf('%s has a label an administrator can read', $field['key']));
}

// The help on the client id is where the least-privilege promise is made, so it
// is worth a test: this plugin is read-only and says so where somebody is
// pasting a credential in.
$help = implode(' ', array_column($fields, 'help'));

T::ok(str_contains($help, 'Reader'), 'the form names the read-only roles to grant');
T::ok(str_contains($help, 'never writes'), 'and says the plugin never writes to Azure');

exit(T::done('descriptor'));
