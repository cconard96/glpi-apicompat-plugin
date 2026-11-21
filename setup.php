<?php

/**
 * SPDX-FileCopyrightText: 2026 Curtis Conard
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Glpi\Api\HL\Router;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Apicompat\TagController;

function plugin_init_apicompat()
{
    global $PLUGIN_HOOKS, $GLPI_CACHE, $CFG_GLPI;

    if (Plugin::isPluginActive('apicompat') && isAPI()) {
        // Global plugin lifecycle hooks to clear caches as needed when plugins are activated/deactivated.
        $fn_clearAPICaches = static function () use ($GLPI_CACHE) {
            $api_versions = array_column(Router::getAPIVersions(), 'version');
            foreach ($api_versions as $version) {
                $GLPI_CACHE->delete('hlapi_controller_schema_hints_' . $version);
                $GLPI_CACHE->delete('graphql_query_schema_info_' . $version);
            }
            $GLPI_CACHE->delete('hlapi_routes');
        };
        $PLUGIN_HOOKS[Hooks::POST_PLUGIN_ENABLE]['apicompat'] = $fn_clearAPICaches;
        $PLUGIN_HOOKS[Hooks::POST_PLUGIN_DISABLE]['apicompat'] = $fn_clearAPICaches;
        $PLUGIN_HOOKS[Hooks::POST_PLUGIN_UNINSTALL]['apicompat'] = $fn_clearAPICaches;

        // Check if the plugin added any API integration hooks. If so, we can skip adding any here.
        // In the future, we could rename/change things from the plugin's official API integration to keep compatibility with what this meta-plugin did until the users are ready to drop this plugin.
        $fn_pluginIntegrationDetected = static function ($plugin) use ($PLUGIN_HOOKS) {
            return !empty($PLUGIN_HOOKS[Hooks::API_CONTROLLERS][$plugin]) || !empty($PLUGIN_HOOKS[Hooks::REDEFINE_API_SCHEMAS][$plugin]);
        };

        $supported_plugins = [
            'tag' => TagController::class,
        ];
        $PLUGIN_HOOKS[Hooks::POST_INIT]['apicompat'] = static function () use ($fn_pluginIntegrationDetected, $supported_plugins, &$PLUGIN_HOOKS, $CFG_GLPI) {
            foreach ($supported_plugins as $plugin => $controller) {
                if (Plugin::isPluginActive($plugin) && !$fn_pluginIntegrationDetected($plugin)) {
                    $PLUGIN_HOOKS[Hooks::API_CONTROLLERS]['apicompat'][] = $controller;

                    // Extra/modified hooks needed for API compatibility
                    $add_hooks = [];
                    $update_hooks = [];
                    $prepare_hooks = [];
                    if ($plugin === 'tag') {
                        $supported_itemtypes = [];
                        foreach ($CFG_GLPI['plugin_tag_itemtypes'] as $itemtypes) {
                            $supported_itemtypes = [...$supported_itemtypes, ...$itemtypes];
                        }

                        foreach ($supported_itemtypes as $itemtype) {
                            // Need to add hooks for this meta-plugin rather than overriding the 'tag' plugin hooks
                            // because it only adds them in the post-init phase and only after trying to guess the current itemtype from the request URL
                            // We also need to ensure our hooks are first in the list
                            $add_hooks[$itemtype] = static function (CommonDBTM $item) {
                                $item->input['_plugin_tag_tag_process_form'] = 1;
                                $item->input['__plugin_tag_tag_values_defined'] = 1;
                            };
                            // CommonDBTM::update() skips updateInDB() and ITEM_UPDATE when no actual field changed (e.g. PATCH with only "tags").
                            // Force a change on date_mod (after prepareInputForUpdate) so the normal post-update path runs.
                            // This way tags are only touched once the update has succeeded.
                            $prepare_hooks[$itemtype] = static function (CommonDBTM $item) {
                                if (is_array($item->input) && array_key_exists('_plugin_tag_tag_values', $item->input)) {
                                    $item->input['date_mod'] = $_SESSION['glpi_currenttime'];
                                }
                            };
                            $update_hooks[$itemtype] = static function (CommonDBTM $item) {
                                if (array_key_exists('_plugin_tag_tag_values', $item->input ?? [])) {
                                    $item->input['_plugin_tag_tag_process_form'] = 1;
                                    $item->input['__plugin_tag_tag_values_defined'] = 1;
                                }
                            };
                        }
                        $PLUGIN_HOOKS[Hooks::ITEM_ADD] = ['apicompat' => $add_hooks] + ($PLUGIN_HOOKS[Hooks::ITEM_ADD] ?? []);
                        $PLUGIN_HOOKS[Hooks::POST_PREPAREUPDATE] = ['apicompat' => $prepare_hooks] + ($PLUGIN_HOOKS[Hooks::POST_PREPAREUPDATE] ?? []);
                        $PLUGIN_HOOKS[Hooks::ITEM_UPDATE] = ['apicompat' => $update_hooks] + ($PLUGIN_HOOKS[Hooks::ITEM_UPDATE] ?? []);
                    }
                }
            }
        };

        $PLUGIN_HOOKS[Hooks::REDEFINE_API_SCHEMAS]['apicompat'] = static function ($data) use ($fn_pluginIntegrationDetected, $supported_plugins) {
            foreach ($supported_plugins as $plugin => $controller) {
                if (Plugin::isPluginActive($plugin) && !$fn_pluginIntegrationDetected($plugin)) {
                    $data = $controller::redefineAPISchemas($data);
                }
            }
            return $data;
        };
    }
}

function plugin_version_apicompat()
{
    return [
        'name'         => __('Plugin API Integration', 'apicompat'),
        'version'      => '1.0.0',
        'author'       => 'Curtis Conard',
        'license'      => 'GPLv3+',
        'homepage'     =>'https://github.com/cconard96/glpi-apicompat-plugin',
        'requirements' => [
            'glpi'   => [
                'min' => '12.0.0',
                'max' => '13.0.0'
            ]
        ]
    ];
}
