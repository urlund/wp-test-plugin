<?php

namespace Urlund\WordPress\PluginUpdater;

/*
 * Abstract class for a plugin repository.
 * Defines the methods that any plugin repository implementation must provide.
 */
abstract class AbstractRepository
{
    /**
     * Multiton instances keyed by unique ID (e.g., plugin slug)
     */
    protected static $instances = array();

    /**
     * Protected constructor to prevent direct instantiation
     */
    protected function __construct() {}

    /**
     * Get (or create) an instance for a given key
     *
     * @param string $key Unique key (e.g., plugin slug)
     * @param mixed ...$args Arguments to pass to subclass constructor (if needed)
     * @return static
     */
    public static function getInstance($plugin, ...$args)
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = array();
        }

        if (!isset(self::$instances[$cls][$plugin])) {
            // If subclass needs args, override constructor and pass them
            self::$instances[$cls][$plugin] = new static($plugin, ...$args);
        }

        return self::$instances[$cls][$plugin];
    }

    /**
     * Optionally, allow resetting/clearing instances (for testing or reloading)
     */
    public static function clearInstances()
    {
        $cls = static::class;
        self::$instances[$cls] = array();
    }

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
