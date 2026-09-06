<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

namespace GlpiPlugin\Glpicloudazure;

/**
 * Which Azure resource types this plugin groups into which service, and what
 * each is called in the core's vocabulary.
 *
 * Two rules shape the table.
 *
 * **Every type is claimed by exactly one service, and whatever is left over
 * goes into `other`.** A provider that only records the types it has a mapping
 * for produces an inventory that is quietly wrong, and wrong precisely where
 * somebody is looking: the odd resource nobody remembered creating. The `other`
 * sweep is a `!in~` of everything below, so adding a type here moves it out of
 * `other` and nothing is ever in both.
 *
 * **The Azure type string is preserved verbatim on the resource** (see
 * {@see Rows}), so a mapping we get wrong is recoverable without re-collecting
 * a customer's estate.
 *
 * Comparisons are case-insensitive because Resource Graph returns
 * `microsoft.compute/virtualMachines` while the type filter is written
 * lowercase, and `in~`/`=~` are Kusto's case-insensitive operators for the same
 * reason.
 */
final class Types
{
    /** The service every unclaimed type falls into. */
    public const OTHER = 'other';

    /**
     * service => [name, types => azure type => core type]
     *
     * @var array<string,array{name:string,types:array<string,string>}>
     */
    public const SERVICES = [
        'compute' => [
            'name'  => 'Compute',
            'types' => [
                'microsoft.compute/virtualmachines'          => 'virtualmachine',
                'microsoft.compute/virtualmachinescalesets'  => 'scaleset',
                'microsoft.compute/disks'                    => 'disk',
                'microsoft.compute/snapshots'                => 'snapshot',
                'microsoft.compute/images'                   => 'image',
                'microsoft.compute/availabilitysets'         => 'availabilityset',
            ],
        ],
        'network' => [
            'name'  => 'Network',
            'types' => [
                'microsoft.network/virtualnetworks'      => 'virtualnetwork',
                'microsoft.network/networkinterfaces'    => 'networkinterface',
                'microsoft.network/publicipaddresses'    => 'publicip',
                'microsoft.network/networksecuritygroups' => 'securitygroup',
                'microsoft.network/loadbalancers'        => 'loadbalancer',
                'microsoft.network/applicationgateways'  => 'applicationgateway',
                'microsoft.network/azurefirewalls'       => 'firewall',
                'microsoft.network/privateendpoints'     => 'privateendpoint',
                'microsoft.network/dnszones'             => 'dnszone',
            ],
        ],
        'storage' => [
            'name'  => 'Storage',
            'types' => [
                'microsoft.storage/storageaccounts' => 'storageaccount',
                'microsoft.recoveryservices/vaults' => 'recoveryvault',
            ],
        ],
        'database' => [
            'name'  => 'Database',
            'types' => [
                'microsoft.sql/servers'                        => 'sqlserver',
                'microsoft.sql/servers/databases'              => 'sqldatabase',
                'microsoft.dbformysql/flexibleservers'         => 'mysqlserver',
                'microsoft.dbforpostgresql/flexibleservers'    => 'postgresqlserver',
                'microsoft.documentdb/databaseaccounts'        => 'cosmosaccount',
                'microsoft.cache/redis'                        => 'redis',
            ],
        ],
        'containers' => [
            'name'  => 'Containers',
            'types' => [
                'microsoft.containerservice/managedclusters' => 'kubernetescluster',
                'microsoft.containerregistry/registries'     => 'containerregistry',
                'microsoft.containerinstance/containergroups' => 'containergroup',
            ],
        ],
        'web' => [
            'name'  => 'Web and functions',
            'types' => [
                'microsoft.web/sites'       => 'appservice',
                'microsoft.web/serverfarms' => 'appserviceplan',
                'microsoft.apimanagement/service' => 'apimanagement',
            ],
        ],
        'identity' => [
            'name'  => 'Identity and secrets',
            'types' => [
                'microsoft.keyvault/vaults' => 'keyvault',
                'microsoft.managedidentity/userassignedidentities' => 'managedidentity',
            ],
        ],
    ];

    /**
     * The service descriptor entries, in the shape glpi-cloud's registry wants.
     *
     * @return array<int,array{key:string,name:string,types:array<int,string>}>
     */
    public static function services(): array
    {
        $out = [];

        foreach (self::SERVICES as $key => $service) {
            $out[] = [
                'key'   => $key,
                'name'  => $service['name'],
                'types' => array_values(array_unique(array_values($service['types']))),
            ];
        }

        $out[] = [
            'key'   => self::OTHER,
            'name'  => 'Everything else',
            'types' => [],
        ];

        return $out;
    }

    /** Every Azure type string any service claims, lowercase. @return array<int,string> */
    public static function claimed(): array
    {
        $out = [];

        foreach (self::SERVICES as $service) {
            foreach (array_keys($service['types']) as $type) {
                $out[] = $type;
            }
        }

        sort($out);

        return $out;
    }

    /** The Azure types one service claims, lowercase. @return array<int,string> */
    public static function azureTypesFor(string $service): array
    {
        return array_keys(self::SERVICES[$service]['types'] ?? []);
    }

    /** Which service a type belongs to. */
    public static function serviceFor(string $azure_type): string
    {
        $needle = strtolower(trim($azure_type));

        foreach (self::SERVICES as $key => $service) {
            if (isset($service['types'][$needle])) {
                return $key;
            }
        }

        return self::OTHER;
    }

    /**
     * The core's word for a type.
     *
     * An unmapped type keeps the last segment of its Azure type — `vaults`,
     * `flexibleServers` — rather than becoming "unknown". It is a worse name
     * than a curated one and a far better one than nothing: it groups, filters
     * and sorts, and the exact Azure string is on the resource either way.
     */
    public static function coreType(string $azure_type): string
    {
        $needle = strtolower(trim($azure_type));

        foreach (self::SERVICES as $service) {
            if (isset($service['types'][$needle])) {
                return $service['types'][$needle];
            }
        }

        $segments = explode('/', $needle);
        $last     = (string) end($segments);

        return $last === '' ? 'unknown' : $last;
    }
}
