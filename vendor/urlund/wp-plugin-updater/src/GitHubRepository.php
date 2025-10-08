<?php

namespace Urlund\WordPress\PluginUpdater;

/*
 * Class for a GitHub-based plugin repository.
 * Extends the AbstractRepository to provide GitHub-specific implementations.
 */
class GitHubRepository extends AbstractRepository
{
    /**
     * GitHub personal access token.
     *
     * @var string|null
     */
    protected $auth;

    /**
     * The plugin file path relative to the plugins directory (e.g., 'my-plugin/my-plugin.php').
     *
     * @var string
     */
    protected $plugin;

    /**
     * The GitHub repository in the format 'owner/repo' (e.g., 'username/repository').
     *
     * @var string
     */
    protected $repository;

    /**
     * The plugin slug, usually the directory name of the plugin.
     *
     * @var string
     */
    protected $slug;

    /**
     * The sections to retrieve from the plugin's README.
     *
     * @var array
     */
    protected $sections = array(
        'description',
        'installation',
        'faq',
        'screenshots',
        'changelog',
        'reviews'
    );

    /**
     * Configuration options for the repository.
     *
     * @var array
     */
    protected $config;

    /**
     * Constructor to initialize the GitHubPluginRepository.
     *
     * @param string $plugin     Required. The plugin file path relative to the plugins directory (e.g., 'my-plugin/my-plugin.php').
     * @param string $repository Required. The GitHub repository in the format 'owner/repo' (e.g., 'username/repository').
     * @param array $config {
     *     Optional. Arguments to configure the repository.
     *
     *     @type string $auth           Optional. GitHub personal access token for private repositories or higher rate limits.
     *     @type string $slug           Optional. The plugin slug. Defaults to the directory name of the plugin file.
     *     @type bool   $prefer_json    Optional. Whether to prefer JSON metadata over ZIP parsing. Default true.
     *     @type int    $cache_duration Optional. Cache duration in seconds. Default 21600 (6 hours).
     *     @type int    $timeout        Optional. HTTP request timeout in seconds. Default 30.
     *     @type int    $max_file_size  Optional. Maximum ZIP file size in bytes. Default 52428800 (50MB).
     * }
     */
    protected function __construct($plugin, $repository, $config = array())
    {
        $this->plugin     = $plugin;
        $this->repository = $repository;

        if (empty($this->plugin) || empty($this->repository)) {
            throw new InvalidArgumentException('Both $plugin and $repository parameters are required.');
        }

        // Set defaults if not provided
        $this->config = wp_parse_args($config, array(
            'auth' => null,
            'slug' => dirname($this->plugin),
            'prefer_json' => true,
            'cache_duration' => 21600, // 6 hours
            'timeout' => 30,
            'max_file_size' => 52428800, // 50MB
        ));

        if (!file_exists(WP_PLUGIN_DIR . '/' . $this->plugin)) {
            return;
        }
        
        $this->init_hooks();
    }

    /**
     * Initialize hooks for the plugin repository.
     * 
     * @return void
     */
    protected function init_hooks()
    {
        add_filter('plugins_api', array($this, 'plugins_api'), 20, 3);
        add_filter('site_transient_update_plugins', array($this, 'site_transient_update_plugins'));
        #add_action('upgrader_process_complete', array($this, 'upgrader_process_complete'), 10, 2);
    }

    /**
     * Fetch and parse the plugin release from the given download link.
     *
     * @param string $download_link The URL to download the plugin zip file.
     * @return object|null The release object or null on failure.
     */
    private function get_release_metadata($download_link)
    {
        global $wp_filesystem;

        $cache_key   = 'upgrade_plugin_github_api_' . $this->config['slug'] . '_release';
        $transient   = get_transient($cache_key);
        if (!empty($transient)) {
            return (object) $transient;
        }

        // make sure we have the file system
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        // Initialize the WordPress filesystem
        if (!function_exists('WP_Filesystem') || !WP_Filesystem()) {
            return false;
        }

        $temp_file = download_url($download_link);
        if (is_wp_error($temp_file)) {
            $this->log_error('Failed to download plugin ZIP file', array(
                'download_link' => $download_link,
                'error' => $temp_file->get_error_message()
            ));
            return false;
        }

        // Validate the ZIP file before processing
        $validation_result = $this->validate_zip_file($temp_file);
        if (is_wp_error($validation_result)) {
            // Clean up the downloaded file
            unlink($temp_file);
            $this->log_error('ZIP file validation failed', array(
                'download_link' => $download_link,
                'validation_error' => $validation_result->get_error_message()
            ));
            return false;
        }

        // Create a unique temp directory path for extraction
        $temp_dir = trailingslashit(WP_CONTENT_DIR) . 'plugin_extract_' . uniqid();

        // Create the directory
        $wp_filesystem->mkdir($temp_dir);

        // Extract the zip file to the temp directory
        $result = unzip_file($temp_file, $temp_dir);
        if (is_wp_error($result)) {
            // Clean up the downloaded zip file
            unlink($temp_file);
            // Removes directory and all contents recursively
            $wp_filesystem->rmdir($temp_dir, true);

            $this->log_error('Failed to extract ZIP file', array(
                'download_link' => $download_link,
                'temp_dir' => $temp_dir,
                'error' => $result->get_error_message()
            ));
            return false;
        }

        // Clean up the downloaded zip file
        unlink($temp_file);

        // Plugin file not found in the extracted contents
        if (!file_exists($temp_dir . '/' . $this->plugin)) {
            // Removes directory and all contents recursively
            $wp_filesystem->rmdir($temp_dir, true);

            $this->log_error('Plugin file not found in extracted ZIP', array(
                'expected_plugin_file' => $this->plugin,
                'temp_dir' => $temp_dir,
                'extracted_files' => is_dir($temp_dir) ? scandir($temp_dir) : array()
            ));
            return false;
        }

        // Read the plugin file data
        $plugin_data = get_plugin_data($temp_dir . '/' . $this->plugin);
        $release      = array(
            'slug'           => $this->config['slug'],
            'name'           => $plugin_data['Name'] ?? '',
            'version'        => $plugin_data['Version'] ?? '',
            'tested'         => $plugin_data['Tested up to'] ?? '',
            'requires'       => $plugin_data['RequiresWP'] ?? '',
            'author'         => $plugin_data['Author'] ?? '',
            'author_profile' => $plugin_data['PluginURI'] ?? '',
            'last_updated'   => '',
            'download_link'  => $plugin_data->package ?? '',
            'trunk'          => '',
            'sections'       => array(),
        );

        // try to locate section files
        $files = scandir($temp_dir . '/' . dirname($this->plugin));
        foreach ($this->sections as $section) {
            if (($index = array_search($section . '.md', array_map('strtolower', $files))) === false) {
                continue;
            }

            $content = file_get_contents($temp_dir . '/' . dirname($this->plugin) . '/' . $files[$index]);
            if (!empty($content)) {
                // TODO: format markdown to HTML
                $release['sections'][$section] = wp_kses_post($content);
            }
        }

        // Removes directory and all contents recursively
        $wp_filesystem->rmdir($temp_dir, true);

        set_transient($cache_key, $release, $this->config['cache_duration']);

        return (object) $release;
    }

    /**
     * Validate ZIP file for security and integrity.
     *
     * @param string $file_path Path to the ZIP file to validate.
     * @return bool|WP_Error True if valid, WP_Error on failure.
     */
    private function validate_zip_file($file_path)
    {
        // Check if file exists
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', 'ZIP file not found');
        }

        // Check file size (limit to 50MB by default, configurable)
        $max_size = isset($this->config['max_file_size']) ? $this->config['max_file_size'] : 50 * 1024 * 1024;
        if (filesize($file_path) > $max_size) {
            return new WP_Error('file_too_large', sprintf('Plugin file exceeds size limit of %s MB', $max_size / 1024 / 1024));
        }

        // Verify it's actually a ZIP file using file info if available
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime_type = finfo_file($finfo, $file_path);
                finfo_close($finfo);

                if ($mime_type !== 'application/zip' && $mime_type !== 'application/x-zip-compressed') {
                    return new WP_Error('invalid_file_type', 'File is not a valid ZIP archive');
                }
            }
        }

        // Fallback: Check ZIP file signature (magic bytes)
        $file_handle = fopen($file_path, 'rb');
        if (!$file_handle) {
            return new WP_Error('file_read_error', 'Could not read ZIP file');
        }

        $signature = fread($file_handle, 4);
        fclose($file_handle);

        // ZIP file signatures: PK\x03\x04 (normal), PK\x05\x06 (empty), PK\x07\x08 (spanned)
        $valid_signatures = array(
            "\x50\x4B\x03\x04", // Normal ZIP
            "\x50\x4B\x05\x06", // Empty ZIP
            "\x50\x4B\x07\x08"  // Spanned ZIP
        );

        if (!in_array($signature, $valid_signatures)) {
            return new WP_Error('invalid_zip_signature', 'File does not have a valid ZIP signature');
        }

        // Basic ZIP integrity check using ZipArchive if available
        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $result = $zip->open($file_path, \ZipArchive::CHECKCONS);

            if ($result !== TRUE) {
                $zip->close();
                return new WP_Error('zip_integrity_failed', 'ZIP file integrity check failed');
            }

            $zip->close();
        }

        return true;
    }

    /**
     * Log error messages for debugging purposes.
     *
     * @param string $message The error message to log.
     * @param array  $context Additional context information.
     * @param string $level   Log level (error, warning, info, debug).
     * @return void
     */
    private function log_error($message, $context = array(), $level = 'error')
    {
        // Only log if WP_DEBUG is enabled
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $log_message = sprintf(
            '[GitHubPluginRepository] [%s] %s',
            strtoupper($level),
            $message
        );

        if (!empty($context)) {
            $log_message .= ' - Context: ' . json_encode($context);
        }

        error_log($log_message);
    }

    /**
     * Handle API errors with proper logging and user-friendly messages.
     *
     * @param mixed  $response The HTTP response or WP_Error.
     * @param string $context  Additional context for the error.
     * @return null
     */
    private function handle_api_error($response, $context = '')
    {
        $error_message = 'GitHub API request failed';
        $log_context = array(
            'repository' => $this->repository,
            'plugin' => $this->plugin,
            'context' => $context
        );

        if (is_wp_error($response)) {
            $error_message .= ': ' . $response->get_error_message();
            $log_context['wp_error_code'] = $response->get_error_code();
        } elseif (isset($response['response']['code'])) {
            $code = $response['response']['code'];
            $error_message .= ': HTTP ' . $code;
            $log_context['http_code'] = $code;

            // Handle specific GitHub API errors
            switch ($code) {
                case 403:
                    $error_message .= ' (Rate limit exceeded or insufficient permissions)';
                    if (isset($response['headers']['x-ratelimit-remaining'])) {
                        $log_context['rate_limit_remaining'] = $response['headers']['x-ratelimit-remaining'];
                    }
                    break;
                case 404:
                    $error_message .= ' (Repository not found, private, or release does not exist)';
                    break;
                case 401:
                    $error_message .= ' (Authentication failed - check your GitHub token)';
                    break;
                case 500:
                case 502:
                case 503:
                    $error_message .= ' (GitHub server error - temporary issue)';
                    break;
            }
        }

        $this->log_error($error_message, $log_context);
        return null;
    }

    /**
     * Get plugin metadata using JSON-first approach with ZIP fallback.
     *
     * @return object|null The plugin metadata object or null on failure.
     */
    private function get_plugin_metadata()
    {
        $data = $this->get_data();
        if (empty($data)) {
            $this->log_error('No GitHub release data available', array(
                'repository' => $this->repository
            ));
            return null;
        }

        // Try JSON metadata first if preferred
        if ($this->config['prefer_json']) {
            $this->log_error('Attempting to fetch JSON metadata', array(), 'debug');
            $json_metadata = $this->get_json_metadata($data);
            if ($json_metadata) {
                $this->log_error('Successfully retrieved JSON metadata', array(
                    'version' => $json_metadata->version
                ), 'info');
                return $json_metadata;
            }
            $this->log_error('JSON metadata not available, falling back to ZIP parsing', array(), 'info');
        }

        // Fallback to ZIP parsing
        $download_link = $this->get_download_link();
        if ($download_link) {
            $this->log_error('Attempting ZIP parsing fallback', array(
                'download_link' => $download_link
            ), 'debug');
            $zip_metadata = $this->get_release_metadata($download_link);
            if ($zip_metadata) {
                // Add GitHub data to ZIP metadata
                $zip_metadata->last_updated = $data['published_at'] ?? '';
                $this->log_error('Successfully retrieved ZIP metadata', array(
                    'version' => $zip_metadata->version
                ), 'info');
                return $zip_metadata;
            }
            $this->log_error('ZIP parsing failed', array(
                'download_link' => $download_link
            ));
        } else {
            $this->log_error('No download link found in GitHub release', array(
                'available_assets' => isset($data['assets']) ? array_column($data['assets'], 'name') : array()
            ));
        }

        // If JSON wasn't preferred, try it as last resort
        if (!$this->config['prefer_json']) {
            $this->log_error('Attempting JSON metadata as last resort', array(), 'debug');
            $json_metadata = $this->get_json_metadata($data);
            if ($json_metadata) {
                $this->log_error('Successfully retrieved JSON metadata on fallback', array(
                    'version' => $json_metadata->version
                ), 'info');
                return $json_metadata;
            }
        }

        $this->log_error('All metadata retrieval methods failed', array(
            'repository' => $this->repository,
            'prefer_json' => $this->config['prefer_json']
        ));
        return null;
    }

    /**
     * Fetch plugin metadata from plugin.json file in GitHub release assets.
     *
     * @param array $data The GitHub release data.
     * @return object|null The JSON metadata object or null on failure.
     */
    private function get_json_metadata($data)
    {
        if (empty($data['assets'])) {
            return null;
        }

        $cache_key = 'upgrade_plugin_github_api_' . $this->config['slug'] . '_json';
        $transient = get_transient($cache_key);
        if (!empty($transient)) {
            return (object) $transient;
        }

        // Look for plugin.json in release assets
        foreach ($data['assets'] as $asset) {
            if (strtolower($asset['name']) === 'plugin.json') {
                $headers = array(
                    'Accept' => 'application/json'
                );

                if (!empty($this->config['auth'])) {
                    $headers['Authorization'] = 'Bearer ' . $this->config['auth'];
                }

                $response = wp_remote_get(
                    $asset['browser_download_url'],
                    array(
                        'timeout' => $this->config['timeout'],
                        'headers' => $headers
                    )
                );

                if (is_wp_error($response) || !isset($response['response']['code']) || $response['response']['code'] != 200) {
                    $this->log_error('Failed to fetch plugin.json from GitHub', array(
                        'asset_url' => $asset['browser_download_url'],
                        'response_code' => isset($response['response']['code']) ? $response['response']['code'] : 'unknown',
                        'error' => is_wp_error($response) ? $response->get_error_message() : 'HTTP error'
                    ));
                    continue;
                }

                $json_data = json_decode(wp_remote_retrieve_body($response), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->log_error('Invalid JSON format in plugin.json', array(
                        'asset_url' => $asset['browser_download_url'],
                        'json_error' => json_last_error_msg(),
                        'raw_content' => substr(wp_remote_retrieve_body($response), 0, 500) // First 500 chars for debugging
                    ));
                    continue;
                }

                if (empty($json_data)) {
                    $this->log_error('Empty JSON data in plugin.json', array(
                        'asset_url' => $asset['browser_download_url']
                    ));
                    continue;
                }

                // Validate required fields
                $required_fields = array('name', 'version', 'slug');
                $missing_fields = array();
                foreach ($required_fields as $field) {
                    if (empty($json_data[$field])) {
                        $missing_fields[] = $field;
                    }
                }

                if (!empty($missing_fields)) {
                    $this->log_error('Missing required fields in plugin.json', array(
                        'asset_url' => $asset['browser_download_url'],
                        'missing_fields' => $missing_fields,
                        'available_fields' => array_keys($json_data)
                    ));
                    continue;
                }

                // Set defaults for missing optional fields
                $defaults = array(
                    'tested' => '',
                    'requires' => '',
                    'author' => '',
                    'author_profile' => '',
                    'last_updated' => $data['published_at'] ?? '',
                    'download_link' => $this->get_download_link(),
                    'trunk' => '',
                    'sections' => array()
                );

                $json_data = array_merge($defaults, $json_data);

                set_transient($cache_key, $json_data, $this->config['cache_duration']);

                $this->log_error('Successfully loaded metadata from plugin.json', array(
                    'asset_url' => $asset['browser_download_url'],
                    'plugin_version' => $json_data['version']
                ), 'info');

                return (object) $json_data;
            }
        }

        $this->log_error('No valid plugin.json found in release assets', array(
            'available_assets' => array_column($data['assets'], 'name')
        ), 'warning');

        return null;
    }

    /**
     * Handle the plugins_api filter to provide plugin information.
     *
     * @param mixed  $result The result object or WP_Error.
     * @param string $action The type of information being requested. Default 'plugin_information'.
     * @param object $args   The plugin API arguments.
     * @return object The plugin information object or the original result on failure.
     */
    public function plugins_api($result, $action, $args)
    {
        if ($action !== 'plugin_information' || $args->slug !== $this->config['slug']) {
            return $result;
        }

        $metadata = $this->get_plugin_metadata();
        if (empty($metadata)) {
            return $result;
        }

        $data = $this->get_data();
        if (!empty($data['body'])) {
            if (!isset($metadata->sections)) {
                $metadata->sections = array();
            }
            $metadata->sections['other_notes'] = wp_kses_post($data['body']);
        }

        return (object) array(
            'slug'           => $this->config['slug'],
            'name'           => $metadata->name,
            'version'        => $metadata->version,
            'tested'         => $metadata->tested,
            'requires'       => $metadata->requires,
            'author'         => $metadata->author,
            'author_profile' => $metadata->author_profile,
            'last_updated'   => $metadata->last_updated,
            'download_link'  => $metadata->download_link,
            'trunk'          => $metadata->trunk ?? '',
            'sections'       => $metadata->sections ?? array(),
            'banners'        => $metadata->banners ?? array(),
            'icons'          => $metadata->icons ?? array(),
        );
    }

    /**
     * Fetch the latest release data from GitHub API.
     *
     * @return array|null The release data as an associative array or null on failure.
     */
    private function get_data()
    {
        $cache_key = 'upgrade_plugin_github_api_' . $this->config['slug'];
        $transient = get_transient($cache_key);
        if (empty($transient)) {
            $headers = array(
                'Accept' => 'application/json'
            );

            if (!empty($this->config['auth'])) {
                $headers['Authorization'] = 'Bearer ' . $this->config['auth'];
            }

            $transient = wp_remote_get(
                'https://api.github.com/repos/' . $this->repository . '/releases/latest',
                array(
                    'timeout' => $this->config['timeout'],
                    'headers' => $headers
                )
            );

            if (is_wp_error($transient) || !isset($transient['response']['code']) || $transient['response']['code'] != 200 || empty($transient['body'])) {
                return $this->handle_api_error($transient, 'fetching latest release data');
            }

            set_transient($cache_key, $transient, $this->config['cache_duration']);
        }

        $response_body = wp_remote_retrieve_body($transient);
        $decoded_data = json_decode($response_body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log_error('Invalid JSON in GitHub API response', [
                'json_error' => json_last_error_msg(),
                'response_excerpt' => substr($response_body, 0, 500)
            ]);
            return null;
        }

        if (empty($decoded_data)) {
            $this->log_error('Empty response from GitHub API', array(
                'repository' => $this->repository
            ));
            return null;
        }

        return $decoded_data;
    }

    /**
     * Get the download link for the plugin from GitHub releases.
     *
     * @return string The download link URL or an empty string if not found.
     */
    private function get_download_link()
    {
        $data = $this->get_data();
        if (empty($data) || empty($data['assets'])) {
            return '';
        }

        $assets_names = array_map(function ($asset) {
            return strtolower($asset['name']);
        }, $data['assets']);

        $search_names = array(
            $this->config['slug'] . '.zip',
            'latest.zip',
            'plugin.zip',
        );

        foreach ($search_names as $name) {
            $index = array_search($name, $assets_names);
            if ($index !== false) {
                return $data['assets'][$index]['browser_download_url'];
            }
        }

        // try to guess the version from tag name
        $version      = preg_match('/\d+(\.\d+)+/', $data['tag_name'], $matches) ? $matches[0] : '';
        $search_names = array(
            $this->config['slug'] . '-' . $version . '.zip',
            $version . '.zip',
        );

        foreach ($search_names as $name) {
            $index = array_search($name, $assets_names);
            if ($index !== false) {
                return $data['assets'][$index]['browser_download_url'];
            }
        }

        return '';
    }

    /**
     * Modify the plugin update transient to include our custom plugin update information.
     *
     * @param object $value The site transient object containing plugin update information.
     * @return object Modified site transient object.
     */
    public function site_transient_update_plugins($value)
    {
        if (empty($value->checked)) {
            return $value;
        }

        $metadata = $this->get_plugin_metadata();
        if (empty($metadata)) {
            return $value;
        }

        // Check if the current plugin version is less than the release version
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin);
        if (version_compare($plugin_data['Version'], $metadata->version, '>=')) {
            return $value;
        }

        // Check if the current WordPress version meets the plugin's requirements
        if (!empty($metadata->requires)) {
            if (version_compare($metadata->requires, get_bloginfo('version'), '<')) {
                return $value;
            }
        }

        $value->response[$this->plugin] = (object) array(
            'id'             => 'github.com/' . $this->repository,
            'slug'           => $this->config['slug'],
            'plugin'         => $this->plugin,
            'new_version'    => $metadata->version,
            'tested'         => $metadata->tested,
            'package'        => $metadata->download_link,
            'url'            => $metadata->author_profile ?? '',
            'requires'       => $metadata->requires,
        );

        return $value;
    }

    /**
     * Perform actions after the plugin has been updated.
     *
     * @param [type] $upgrader
     * @param [type] $options
     * @return void
     */
    public function upgrader_process_complete($upgrader, $options)
    {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            foreach ($options['plugins'] as $plugin) {
                if ($plugin === $this->plugin) {
                    delete_transient('upgrade_plugin_github_api_' . $this->config['slug']);
                    delete_transient('upgrade_plugin_github_api_' . $this->config['slug'] . '_json');
                    delete_transient('upgrade_plugin_github_api_' . $this->config['slug'] . '_release');
                    
                    $this->log_error('Cleared plugin update cache after successful update', array(
                        'plugin' => $this->plugin,
                        'repository' => $this->repository
                    ), 'info');
                }
            }
        }
    }
}
