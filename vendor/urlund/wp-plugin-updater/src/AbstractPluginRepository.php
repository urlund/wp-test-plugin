<?php

namespace Urlund\WordPress\PluginUpdater;

/*
 * Abstract class for a plugin repository.
 * Defines the methods that any plugin repository implementation must provide.
 */
abstract class AbstractPluginRepository
{
    /**
     * Handle the plugins_api filter to provide plugin information.
     *
     * @param mixed  $result The result object or WP_Error.
     * @param string $action The type of information being requested. Default 'plugin_information'.
     * @param object $args   The plugin API arguments.
     * @return object The plugin information object or the original result on failure.
     */
    abstract public function plugins_api($result, $action, $args);

    /**
     * Handle the site_transient_update_plugins filter to add update information.
     *
     * @param mixed $value The current value of the transient.
     * @return mixed The modified transient value.
     */
    abstract public function site_transient_update_plugins($value);

    /**
     * Handle the upgrader_process_complete action to perform actions after a plugin update.
     *
     * @param object $upgrader The upgrader instance.
     * @param array  $options  The options passed to the upgrader.
     * @return void
     */
    abstract public function upgrader_process_complete($upgrader, $options);
}
