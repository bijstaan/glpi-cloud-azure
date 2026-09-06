<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Normalising a Resource Graph row.
 *
 * The state ladder is the part worth the most tests. Azure has no single "is it
 * on" field, and the one that is nearly universal — `provisioningState` —
 * answers a different question: a stopped VM's is `Succeeded`. A plugin that
 * reads it as the state reports an estate where everything is fine, forever,
 * and looks like it is working.
 */

require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Glpicloudazure\Rows;

$rows = fixture('graph-rows.json');

T::is(Rows::fromGraph($rows['no_id']), null, 'a row with no id is not a resource');

// ------------------------------------------------------------------ a VM

$vm = Rows::fromGraph($rows['running_vm']);

T::is($vm['native_id'], $rows['running_vm']['id'], 'the ARM resource id is the identity');
T::is($vm['name'], 'web-01', 'the name is the name');
T::is($vm['type'], 'virtualmachine', 'the type is the core vocabulary');
T::is($vm['state'], 'running', 'PowerState/running reads as running');
T::is($vm['attributes']['azure_type'], 'microsoft.compute/virtualMachines', 'the Azure type is kept verbatim, case and all');
T::is($vm['attributes']['location'], 'uksouth', 'location is lifted out');
T::is($vm['attributes']['resource_group'], 'rg-prod', 'so is the resource group');
T::is($vm['attributes']['subscription_id'], '11111111-2222-3333-4444-555555555555', 'and the subscription');
T::is($vm['attributes']['sku'], ['name' => 'Standard_D2s_v5'], 'sku is kept as the structure it is');
T::ok(isset($vm['attributes']['properties']['hardwareProfile']), 'the payload is kept whole, not summarised');
T::is($vm['tags'], ['client' => 'acme', 'env' => 'prod', 'cost-centre' => '4021'], 'tags are flattened to strings');

$stopped = Rows::fromGraph($rows['stopped_vm']);

T::is($stopped['state'], 'deallocated', 'a deallocated VM is not "succeeded"');
T::is($stopped['tags'], [], 'a resource with no tags has no tags');

// ------------------------------------------------------- the rest of the ladder

T::is(Rows::fromGraph($rows['disk'])['state'], 'attached', 'a disk reports its own disk state');
T::is(Rows::fromGraph($rows['storage_account'])['state'], 'available', 'a storage account reports its primary status');
T::is(Rows::fromGraph($rows['app_service'])['state'], 'running', 'an app service reports its state');

T::is(
    Rows::fromGraph($rows['unclaimed'])['state'],
    'succeeded',
    'and a resource with nothing better falls through to provisioningState, in Azure own word'
);

T::is(Rows::state([]), '', 'a resource with no properties at all has no state, rather than a made-up one');
T::is(Rows::state(['provisioningState' => 'Failed']), 'failed', 'a failed deployment is visible');

// ------------------------------------------------------------- the long tail

$unclaimed = Rows::fromGraph($rows['unclaimed']);

T::is($unclaimed['type'], 'signalr', 'an unmapped type is stored, named after its last segment');
T::is($unclaimed['attributes']['azure_type'], 'microsoft.signalrservice/SignalR', 'with its exact Azure type kept');

// ------------------------------------------------------------------- tags

T::is(Rows::tags(['a' => 1, 'b' => true, 'c' => null, 'd' => ['nested']]), ['a' => '1', 'b' => '1', 'c' => ''], 'a structured tag value is dropped rather than stored as "Array"');
T::is(Rows::tags(null), [], 'no tags is not an error');

exit(T::done('rows'));
