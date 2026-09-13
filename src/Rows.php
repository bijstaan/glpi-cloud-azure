<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

/**
 * One Resource Graph row, in the shape glpi-cloud stores.
 *
 * Pure: no HTTP, no GLPI, no tenant. This is where a mistake is silent — a
 * state read from the wrong field looks like a working plugin that says every
 * machine is "succeeded" — so it is the part with the most tests.
 *
 * ### The state ladder
 *
 * Azure has no single "is it on" field. Each family populates its own, and
 * `provisioningState` — the only near-universal one — answers a different
 * question: whether the last *deployment* worked, not whether the thing is
 * running. A stopped VM's provisioningState is `Succeeded`.
 *
 * So the ladder is ordered most-specific first, and `provisioningState` is last
 * and deliberately kept as its own word rather than translated into something
 * that would read as a runtime state.
 */
final class Rows
{
    /**
     * @param array<string,mixed> $row a Resource Graph row (objectArray format)
     * @return array<string,mixed>|null null if the row has no id
     */
    public static function fromGraph(array $row): ?array
    {
        $native_id = trim((string) ($row['id'] ?? ''));

        if ($native_id === '') {
            return null;
        }

        $azure_type = (string) ($row['type'] ?? '');
        $properties = is_array($row['properties'] ?? null) ? $row['properties'] : [];

        $attributes = [
            // Always verbatim: a type mapping we get wrong has to be
            // recoverable without re-collecting an entity's estate.
            'azure_type'      => $azure_type,
            'location'        => (string) ($row['location'] ?? ''),
            'resource_group'  => (string) ($row['resourceGroup'] ?? ''),
            'subscription_id' => (string) ($row['subscriptionId'] ?? ''),
        ];

        foreach (['sku', 'kind', 'identity', 'zones', 'managedBy'] as $extra) {
            if (isset($row[$extra]) && $row[$extra] !== null && $row[$extra] !== '') {
                $attributes[self::snake($extra)] = $row[$extra];
            }
        }

        if ($properties !== []) {
            $attributes['properties'] = $properties;
        }

        return [
            'native_id'  => $native_id,
            'name'       => (string) ($row['name'] ?? $native_id),
            'type'       => Types::coreType($azure_type),
            'state'      => self::state($properties),
            'tags'       => self::tags($row['tags'] ?? []),
            'attributes' => $attributes,
        ];
    }

    /**
     * What Azure says this resource is doing, from whichever field its family
     * populates.
     *
     * @param array<string,mixed> $properties
     */
    public static function state(array $properties): string
    {
        // Virtual machines, when the query asked Resource Graph for the
        // extended instance view. `PowerState/running` → `running`.
        $power = self::dig($properties, ['extended', 'instanceView', 'powerState', 'code']);

        if ($power !== null) {
            $parts = explode('/', (string) $power);

            return strtolower(trim((string) end($parts)));
        }

        // Disks: Attached, Unattached, Reserved.
        $disk = $properties['diskState'] ?? null;

        if (is_string($disk) && $disk !== '') {
            return strtolower($disk);
        }

        // Storage accounts: available / unavailable, per replica.
        $primary = $properties['statusOfPrimary'] ?? null;

        if (is_string($primary) && $primary !== '') {
            return strtolower($primary);
        }

        // App Service and a few others carry a real runtime state here.
        $state = $properties['state'] ?? null;

        if (is_string($state) && $state !== '') {
            return strtolower($state);
        }

        // Last, and left as Azure's own word: this says the last deployment
        // succeeded, not that anything is running.
        $provisioning = $properties['provisioningState'] ?? null;

        return is_string($provisioning) ? strtolower($provisioning) : '';
    }

    /**
     * @param mixed $tags
     * @return array<string,string>
     */
    public static function tags(mixed $tags): array
    {
        $out = [];

        foreach ((array) $tags as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>   $path
     */
    private static function dig(array $data, array $path): mixed
    {
        $node = $data;

        foreach ($path as $step) {
            if (!is_array($node) || !array_key_exists($step, $node)) {
                return null;
            }

            $node = $node[$step];
        }

        return $node;
    }

    private static function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }
}
