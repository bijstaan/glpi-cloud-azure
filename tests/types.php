<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * The type map.
 *
 * Two properties matter more than the contents. **Nothing may be claimed
 * twice**, or a resource appears in two services and the sweep stores it under
 * whichever ran last. And **the `other` filter has to be the exact complement
 * of everything claimed**, or resources fall down the gap between the two — the
 * failure that makes an inventory quietly incomplete rather than visibly
 * broken.
 */

require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Glpicloudazure\Types;

$claimed = Types::claimed();

T::is(count($claimed), count(array_unique($claimed)), 'no Azure type is claimed by two services');
T::ok(count($claimed) > 20, 'the map covers a useful slice of Azure');

foreach ($claimed as $type) {
    T::is($type, strtolower($type), sprintf('%s is stored lowercase, because the filter compares that way', $type));
}

// Every claimed type resolves to its own service and a core name.
foreach (Types::SERVICES as $service => $definition) {
    foreach ($definition['types'] as $azure => $core) {
        T::is(Types::serviceFor($azure), $service, sprintf('%s belongs to %s', $azure, $service));
        T::is(Types::coreType($azure), $core, sprintf('%s is called %s', $azure, $core));
    }
}

// Resource Graph returns mixed case; the map must not care.
T::is(Types::serviceFor('Microsoft.Compute/virtualMachines'), 'compute', 'a mixed-case type still finds its service');
T::is(Types::coreType('MICROSOFT.STORAGE/STORAGEACCOUNTS'), 'storageaccount', 'and its core type');
T::is(Types::serviceFor('  microsoft.compute/disks  '), 'compute', 'and whitespace is not a new type');

// The long tail.
T::is(Types::serviceFor('microsoft.signalrservice/SignalR'), Types::OTHER, 'an unclaimed type falls into other');
T::is(Types::coreType('microsoft.signalrservice/SignalR'), 'signalr', 'and keeps the last segment of its Azure type as a name');
T::is(Types::coreType('nonsense'), 'nonsense', 'a type with no slash is still a name');
T::is(Types::coreType(''), 'unknown', 'and nothing at all is unknown');

// The descriptor shape the core validates.
$services = Types::services();
$keys     = array_column($services, 'key');

T::is(array_slice($keys, -1), [Types::OTHER], 'other is offered last, after every claimed service');
T::is(count($keys), count(array_unique($keys)), 'service keys are unique');

foreach ($services as $service) {
    T::ok($service['name'] !== '', sprintf('%s has a display name', $service['key']));
    T::is(preg_match('/^[a-z][a-z0-9_]{0,31}$/', $service['key']), 1, sprintf('%s is a key the core will accept', $service['key']));
}

foreach (array_keys(Types::SERVICES) as $service) {
    T::ok(Types::azureTypesFor($service) !== [], sprintf('%s claims at least one Azure type', $service));
}

T::is(Types::azureTypesFor(Types::OTHER), [], 'other claims none — it is the complement');

exit(T::done('types'));
