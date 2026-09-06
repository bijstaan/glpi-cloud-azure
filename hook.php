<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Install and uninstall.
 *
 * Both are deliberately empty of schema. This plugin owns no tables: every row
 * it produces belongs to glpi-cloud, stamped with the `azure` provider key, and
 * uninstalling this plugin leaves that inventory in place — marked as having no
 * provider rather than deleted. A year of a customer's cloud history is not
 * something a plugin removal should quietly take with it.
 */
function plugin_glpicloudazure_install()
{
    return true;
}

function plugin_glpicloudazure_uninstall()
{
    return true;
}
