<?php
/**
 * Plugin JSON Generator
 * 
 * A class to generate plugin.json metadata files from WordPress plugin headers.
 * 
 * @package Urlund\WordPress\PluginUpdater
 * @author Henrik Urlund
 * @version 1.0.0
 */

namespace Urlund\WordPress\PluginUpdater;

use Exception;

class PluginJsonGenerator
{
    /**
     * Default configuration values
     */
    private $defaults = array(
        'sections' => array(
            'description' => '',
            'installation' => '',
            'faq' => '',
            'changelog' => '',
            'screenshots' => '',
            'other_notes' => ''
        ),
        'banners' => array(),
        'icons' => array(),
        'trunk' => '',
        'upgrade_notice' => ''
    );

    /**
     * CLI options
     */
    private $options = array();

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->parseCliOptions();
    }

    /**
     * Main execution method
     */
    public function run()
    {
        try {
            $this->validateOptions();
            $metadata = $this->generateMetadata();
            $this->writeJsonFile($metadata);
            $this->success("plugin.json generated successfully!");
        } catch (Exception $e) {
            $this->error("Error: " . $e->getMessage());
            exit(1);
        }
    }

    /**
     * Parse command line options
     */
    private function parseCliOptions()
    {
        $longopts = array(
            "plugin:",          // Required: Plugin file path
            "output:",          // Optional: Output file path or directory
            "slug:",            // Optional: Plugin slug
            "download-url:",    // Optional: Download URL
            "tested:",          // Optional: Tested up to WordPress version
            "requires-php:",    // Optional: Minimum PHP version
            "sections-dir:",    // Optional: Directory containing section files
            "config:",          // Optional: JSON config file
            "version:",         // Optional: Version to append to filename
            "help",             // Show help
        );

        $opts = getopt("h", $longopts);
        
        if (isset($opts['h']) || isset($opts['help'])) {
            $this->showHelp();
            exit(0);
        }

        $this->options = $opts;
    }

    /**
     * Validate required options
     */
    private function validateOptions()
    {
        if (!isset($this->options['plugin'])) {
            throw new Exception("--plugin option is required");
        }

        if (!file_exists($this->options['plugin'])) {
            throw new Exception("Plugin file does not exist: " . $this->options['plugin']);
        }

        if (!is_readable($this->options['plugin'])) {
            throw new Exception("Plugin file is not readable: " . $this->options['plugin']);
        }
    }

    /**
     * Generate plugin metadata
     */
    private function generateMetadata()
    {
        $pluginData = $this->parsePluginHeader($this->options['plugin']);
        $sections = $this->loadSections();
        $config = $this->loadConfig();

        // Build base metadata from plugin header
        $metadata = array(
            'name' => $pluginData['Name'],
            'slug' => $this->getSlug($pluginData),
            'version' => $pluginData['Version'],
            'tested' => $this->options['tested'] ?? $pluginData['Tested up to'] ?? '',
            'requires' => $pluginData['Requires at least'] ?? '',
            'requires_php' => $this->options['requires-php'] ?? $pluginData['Requires PHP'] ?? '',
            'author' => $pluginData['Author'],
            'author_profile' => $pluginData['Author URI'] ?? $pluginData['Plugin URI'] ?? '',
            'last_updated' => date('Y-m-d H:i:s'),
            'download_link' => $this->options['download-url'] ?? '',
            'trunk' => $this->defaults['trunk'],
            'sections' => array_merge($this->defaults['sections'], $sections),
            'banners' => $config['banners'] ?? $this->defaults['banners'],
            'icons' => $config['icons'] ?? $this->defaults['icons'],
            'upgrade_notice' => $config['upgrade_notice'] ?? $this->defaults['upgrade_notice']
        );

        // Remove empty values to keep JSON clean
        $metadata = $this->removeEmptyValues($metadata);

        return $metadata;
    }

    /**
     * Parse WordPress plugin header
     */
    private function parsePluginHeader($pluginFile)
    {
        $pluginData = array();
        $content = file_get_contents($pluginFile);

        if ($content === false) {
            throw new Exception("Could not read plugin file");
        }

        // Define headers to extract
        $headers = array(
            'Name' => 'Plugin Name',
            'Plugin URI' => 'Plugin URI',
            'Description' => 'Description',
            'Author' => 'Author',
            'Author URI' => 'Author URI', 
            'Version' => 'Version',
            'Text Domain' => 'Text Domain',
            'Domain Path' => 'Domain Path',
            'Requires at least' => 'Requires at least',
            'Tested up to' => 'Tested up to',
            'Requires PHP' => 'Requires PHP',
            'Network' => 'Network',
            'License' => 'License',
            'License URI' => 'License URI',
            'Update URI' => 'Update URI'
        );

        // Extract headers using regex
        foreach ($headers as $key => $header) {
            $pattern = '/^[\s\*]*' . preg_quote($header) . ':\s*(.*)$/mi';
            if (preg_match($pattern, $content, $matches)) {
                $pluginData[$key] = trim($matches[1]);
            } else {
                $pluginData[$key] = '';
            }
        }

        // Validate required fields
        if (empty($pluginData['Name'])) {
            throw new Exception("Plugin Name header is required");
        }

        if (empty($pluginData['Version'])) {
            throw new Exception("Version header is required");
        }

        return $pluginData;
    }

    /**
     * Get plugin slug
     */
    private function getSlug($pluginData)
    {
        if (isset($this->options['slug'])) {
            return $this->options['slug'];
        }

        // Extract from plugin file path
        $pluginPath = $this->options['plugin'];
        $pluginDir = dirname($pluginPath);
        
        if (basename($pluginDir) !== '.') {
            return basename($pluginDir);
        }

        // Fallback: generate from plugin name
        return $this->sanitize_title($pluginData['Name']);
    }

    /**
     * Load sections from files
     */
    private function loadSections()
    {
        $sections = array();
        $sectionsDir = $this->options['sections-dir'] ?? dirname($this->options['plugin']);

        if (!is_dir($sectionsDir)) {
            return $sections;
        }

        $sectionFiles = array(
            'description' => array('description.md', 'description.txt', 'README.md'),
            'installation' => array('installation.md', 'installation.txt', 'INSTALL.md'),
            'faq' => array('faq.md', 'faq.txt', 'FAQ.md'),
            'changelog' => array('changelog.md', 'changelog.txt', 'CHANGELOG.md', 'CHANGES.md'),
            'screenshots' => array('screenshots.md', 'screenshots.txt'),
            'other_notes' => array('notes.md', 'notes.txt', 'NOTES.md')
        );

        foreach ($sectionFiles as $section => $filenames) {
            foreach ($filenames as $filename) {
                $filepath = $sectionsDir . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($filepath) && is_readable($filepath)) {
                    $content = file_get_contents($filepath);
                    if ($content !== false && !empty(trim($content))) {
                        $sections[$section] = $this->processMarkdown($content);
                        break; // Use first found file for this section
                    }
                }
            }
        }

        return $sections;
    }

    /**
     * Load additional configuration from JSON file
     */
    private function loadConfig()
    {
        if (!isset($this->options['config'])) {
            return array();
        }

        $configFile = $this->options['config'];
        if (!file_exists($configFile)) {
            throw new Exception("Config file does not exist: " . $configFile);
        }

        $content = file_get_contents($configFile);
        if ($content === false) {
            throw new Exception("Could not read config file: " . $configFile);
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON in config file: " . json_last_error_msg());
        }

        return $config;
    }

    /**
     * Process markdown content (basic conversion)
     */
    private function processMarkdown($content)
    {
        // Basic markdown to HTML conversion
        $content = trim($content);
        
        // Convert headers
        $content = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $content);
        $content = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $content);
        $content = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $content);
        
        // Convert bold and italic
        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
        $content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);
        
        // Convert line breaks
        $content = nl2br($content);
        
        return $content;
    }

    /**
     * Remove empty values from array recursively
     */
    private function removeEmptyValues($array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->removeEmptyValues($value);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            } elseif (empty($value) && $value !== '0') {
                unset($array[$key]);
            }
        }
        return $array;
    }

    /**
     * Write JSON file
     */
    private function writeJsonFile($metadata)
    {
        $outputFile = $this->generateOutputFilename($metadata);
        
        // Ensure output directory exists
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new Exception("Cannot create output directory: " . $outputDir);
            }
        }
        
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new Exception("Failed to encode JSON: " . json_last_error_msg());
        }

        $result = file_put_contents($outputFile, $json);
        if ($result === false) {
            throw new Exception("Failed to write file: " . $outputFile);
        }

        $this->info("Written " . strlen($json) . " bytes to " . $outputFile);
    }

    /**
     * Generate output filename
     */
    private function generateOutputFilename($metadata)
    {
        if (isset($this->options['output'])) {
            $outputPath = $this->options['output'];
            
            // If output path ends with .json, use it as complete file path
            if (strtolower(substr($outputPath, -5)) === '.json') {
                return $outputPath;
            }
            
            // Otherwise, treat it as a directory and generate filename inside it
            $filename = 'plugin';
            $version = $this->options['version'] ?? '';
            
            if (!empty($version)) {
                $filename .= '-' . $version;
            }
            $filename .= '.json';
            
            // Ensure directory path ends with separator
            $outputPath = rtrim($outputPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            
            return $outputPath . $filename;
        }

        // Default behavior when no output specified
        $filename = 'plugin';
        $version = $this->options['version'] ?? '';
        
        if (!empty($version)) {
            $filename .= '-' . $version;
        }
        
        return $filename . '.json';
    }

    /**
     * Get plugin slug from metadata
     */
    private function getPluginSlug($metadata)
    {
        // Use provided slug, otherwise derive from plugin name
        if (isset($this->options['slug'])) {
            return $this->options['slug'];
        }
        
        if (isset($metadata['slug'])) {
            return $metadata['slug'];
        }
        
        if (isset($metadata['name'])) {
            return $this->sanitize_title($metadata['name']);
        }
        
        // Fallback to plugin filename without extension
        $pluginFile = basename($this->options['plugin'], '.php');
        return $this->sanitize_title($pluginFile);
    }

    /**
     * Sanitize title (simplified version of WordPress function)
     */
    private function sanitize_title($title)
    {
        $title = strtolower($title);
        $title = preg_replace('/[^a-z0-9\-_]/', '-', $title);
        $title = preg_replace('/-+/', '-', $title);
        $title = trim($title, '-');
        return $title;
    }

    /**
     * Show help information
     */
    private function showHelp()
    {
        echo "Plugin JSON Generator\n";
        echo "=====================\n\n";
        echo "Usage:\n";
        echo "  generate-plugin-json --plugin=/path/to/plugin.php [options]\n\n";
        echo "Required Options:\n";
        echo "  --plugin=FILE          Path to the main plugin PHP file\n\n";
        echo "Optional Options:\n";
        echo "  --output=PATH          Output file path or directory (default: plugin.json)\n";
        echo "                         If ends with .json: complete file path\n";
        echo "                         Otherwise: directory to place generated file\n";
        echo "  --version=STRING       Version to append to filename (when using directory output)\n";
        echo "  --slug=STRING          Plugin slug (default: auto-detected)\n";
        echo "  --download-url=URL     Download URL for the plugin\n";
        echo "  --tested=VERSION       WordPress version tested up to\n";
        echo "  --requires-php=VERSION Minimum PHP version required\n";
        echo "  --sections-dir=DIR     Directory containing section files (default: plugin directory)\n";
        echo "  --config=FILE          JSON configuration file for banners, icons, etc.\n";
        echo "  --help, -h             Show this help message\n\n";
        echo "Section Files (searched in sections-dir):\n";
        echo "  description.md         Plugin description\n";
        echo "  installation.md        Installation instructions\n";
        echo "  faq.md                 Frequently asked questions\n";
        echo "  changelog.md           Change log\n";
        echo "  screenshots.md         Screenshots description\n";
        echo "  notes.md               Other notes\n\n";
        echo "Config File Format:\n";
        echo "  {\n";
        echo "    \"banners\": {\n";
        echo "      \"low\": \"https://example.com/banner-772x250.jpg\",\n";
        echo "      \"high\": \"https://example.com/banner-1544x500.jpg\"\n";
        echo "    },\n";
        echo "    \"icons\": {\n";
        echo "      \"1x\": \"https://example.com/icon-128x128.png\",\n";
        echo "      \"2x\": \"https://example.com/icon-256x256.png\"\n";
        echo "    },\n";
        echo "    \"upgrade_notice\": \"Important security update\"\n";
        echo "  }\n\n";
        echo "Examples:\n";
        echo "  generate-plugin-json --plugin=my-plugin.php\n";
        echo "  generate-plugin-json --plugin=src/my-plugin.php --output=dist/plugin.json\n";
        echo "  generate-plugin-json --plugin=my-plugin.php --output=dist --version=1.2.0\n";
        echo "  generate-plugin-json --plugin=my-plugin.php --output=releases/ --version=1.0.0\n";
        echo "  generate-plugin-json --plugin=my-plugin.php --config=assets.json --tested=6.4\n";
    }

    /**
     * Output success message
     */
    private function success($message)
    {
        echo "\033[32m✓ " . $message . "\033[0m\n";
    }

    /**
     * Output info message
     */
    private function info($message)
    {
        echo "\033[34mℹ " . $message . "\033[0m\n";
    }

    /**
     * Output error message
     */
    private function error($message)
    {
        echo "\033[31m✗ " . $message . "\033[0m\n";
    }
}
