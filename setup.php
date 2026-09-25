<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */
/**
 * GLPI Cloud — Microsoft Azure.
 *
 * Everything here speaks Azure and nothing else. No tables, no menu, no rights,
 * no opinion about entities, projection or money — those belong to glpi-cloud,
 * and if any of them appear in this plugin the seam has been broken.
 */

use GlpiPlugin\Glpicloudazure\Provider;

define('PLUGIN_GLPICLOUDAZURE_VERSION', '0.1.0');
define('PLUGIN_GLPICLOUDAZURE_MIN_GLPI', '12.0');

function plugin_init_glpicloudazure()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpicloudazure'] = true;

    // Guarded on the *core's* class, not on one of ours — ours is always
    // loadable, so guarding on it would guard nothing. GLPI registers a
    // plugin's namespace only for an active plugin, so this is exactly the
    // question "is glpi-cloud loaded right now", asked without depending on
    // plugin initialisation order.
    //
    // With glpi-cloud absent or switched off this registers nothing and no
    // class of ours is ever autoloaded. Nothing here extends a class of that
    // plugin's, so there is no autoload-time dependency to fail either.
    if (!class_exists(\GlpiPlugin\Glpicloud\Registry::class)) {
        return;
    }

    $PLUGIN_HOOKS['glpicloud_providers']['glpicloudazure'] = [Provider::class, 'describe'];
}

function plugin_version_glpicloudazure()
{
    return [
        'name'         => 'GLPI Cloud — Azure',
        'version'      => PLUGIN_GLPICLOUDAZURE_VERSION,
        'author'       => 'Bijstaan',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/bijstaan/glpi-cloud-azure',
        'requirements' => ['glpi' => ['min' => PLUGIN_GLPICLOUDAZURE_MIN_GLPI]],
    ];
}

/**
 * The core plugin must be installed and active.
 *
 * Reported rather than assumed: a provider plugin that installs happily against
 * no core registers a provider nobody can use, and the administrator's only
 * clue is that no cloud account form ever offers Azure.
 */
function plugin_glpicloudazure_check_prerequisites()
{
    if (!Plugin::isPluginActive('glpicloud')) {
        echo __s('GLPI Cloud (glpicloud) must be installed and activated first.', 'glpicloudazure');

        return false;
    }

    return true;
}

function plugin_glpicloudazure_check_config($verbose = false)
{
    return true;
}
