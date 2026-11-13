<?php
/**
 * ColorSwitch uninstall: remove all data the plugin stored.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('csw_settings');
delete_option('csw_presets');
