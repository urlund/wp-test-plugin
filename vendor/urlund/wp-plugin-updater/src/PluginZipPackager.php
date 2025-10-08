<?php
/**
 * Plugin ZIP Packager
 * 
 * A class to package WordPress plugins into ZIP files with proper folder structure.
 * 
 * @package Urlund\WordPress\PluginUpdater
 * @author Henrik Urlund
 * @version 1.0.0
 */

namespace Urlund\WordPress\PluginUpdater;

use Exception;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PluginZipPackager
{
    /**
     * CLI options
     */
    private $options = array();

    /**
     * Default exclusion patterns
     */
    private $defaultExclusions = array(
        // Version control
        '.git',
        '.gitignore',
        '.gitattributes',
        '.github',
        '.gitlab-ci.yml',
        
        // Development files
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'yarn.lock',
        'webpack.config.js',
        'gulpfile.js',
        'Gruntfile.js',
        '.babelrc',
        'tsconfig.json',
        
        // Development directories
        'node_modules',
        '.github',
        
        // IDE files
        '.vscode',
        '.idea',
        '.phpstorm.meta.php',
        '*.sublime-project',
        '*.sublime-workspace',
        
        // OS files
        '.DS_Store',
        'Thumbs.db',
        'desktop.ini',
        
        // Build artifacts
        'dist',
        'build',
        '*.zip',
        '*.tar.gz',
        '*.rar',
        
        // Logs and temporary files
        '*.log',
        '*.tmp',
        '*.temp',
        '.cache',
        
        // Development configs
        '.env',
        '.env.local',
        '.env.example',
        'phpunit.xml',
        'phpcs.xml',
        '.phpcs.xml.dist',
        '.editorconfig',
        '.stylelintrc',
        '.eslintrc',
    );

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
            $zipFile = $this->createZipPackage();
            $this->success("Plugin ZIP package created: " . $zipFile);
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
            "name:",            // Required: Plugin folder name
            "output:",          // Optional: Output ZIP filename
            "source:",          // Optional: Source directory
            "include:",         // Optional: Additional files to include (comma-separated)
            "exclude:",         // Optional: Additional files to exclude (comma-separated)
            "no-defaults",      // Optional: Don't use default exclusions
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
        // Set default name from current directory if not provided
        if (!isset($this->options['name'])) {
            $sourceDir = $this->options['source'] ?? getcwd();
            $this->options['name'] = basename($sourceDir);
        }

        $sourceDir = $this->options['source'] ?? getcwd();
        if (!is_dir($sourceDir)) {
            throw new Exception("Source directory does not exist: " . $sourceDir);
        }

        if (!is_readable($sourceDir)) {
            throw new Exception("Source directory is not readable: " . $sourceDir);
        }
    }

    /**
     * Create ZIP package
     */
    private function createZipPackage()
    {
        $pluginName = $this->options['name'];
        $sourceDir = $this->options['source'] ?? getcwd();
        $version = $this->options['version'] ?? '';
        
        // Generate output filename
        $outputFile = $this->generateOutputFilename($pluginName, $version);
        
        // Ensure output directory exists
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            if (!mkdir($outputDir, 0755, true)) {
                throw new Exception("Cannot create output directory: " . $outputDir);
            }
        }
        
        // Get list of files to include
        $filesToInclude = $this->getFilesToInclude($sourceDir);
        
        $this->info("Creating ZIP package with " . count($filesToInclude) . " files...");
        
        // Create ZIP archive
        $zip = new ZipArchive();
        $result = $zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        if ($result !== TRUE) {
            throw new Exception("Cannot create ZIP file: " . $this->getZipError($result));
        }

        // Add files to ZIP with proper folder structure
        foreach ($filesToInclude as $file) {
            $relativePath = $this->getRelativePath($sourceDir, $file);
            $zipPath = $pluginName . '/' . $relativePath;
            
            if (is_dir($file)) {
                $zip->addEmptyDir($zipPath);
            } else {
                $zip->addFile($file, $zipPath);
            }
        }

        $zip->close();
        
        $this->info("Package size: " . $this->formatBytes(filesize($outputFile)));
        
        return $outputFile;
    }

    /**
     * Generate output filename
     */
    private function generateOutputFilename($pluginName, $version = '')
    {
        if (isset($this->options['output'])) {
            $outputPath = $this->options['output'];
            
            // If output path ends with .zip, use it as complete file path
            if (strtolower(substr($outputPath, -4)) === '.zip') {
                return $outputPath;
            }
            
            // Otherwise, treat it as a directory and generate filename inside it
            $filename = $pluginName;
            if (!empty($version)) {
                $filename .= '-' . $version;
            }
            $filename .= '.zip';
            
            // Ensure directory path ends with separator
            $outputPath = rtrim($outputPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            
            return $outputPath . $filename;
        }

        // Default behavior when no output specified
        $filename = $pluginName;
        if (!empty($version)) {
            $filename .= '-' . $version;
        }
        
        return $filename . '.zip';
    }

    /**
     * Get files to include in the package
     */
    private function getFilesToInclude($sourceDir)
    {
        $files = array();
        $exclusions = $this->getExclusionPatterns();
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $relativePath = $this->getRelativePath($sourceDir, $file->getPathname());
            
            if (!$this->shouldExcludeFile($relativePath, $exclusions)) {
                $files[] = $file->getPathname();
            }
        }

        // Include additional files if specified
        if (isset($this->options['include'])) {
            $additionalFiles = explode(',', $this->options['include']);
            foreach ($additionalFiles as $additionalFile) {
                $additionalFile = trim($additionalFile);
                $fullPath = $sourceDir . DIRECTORY_SEPARATOR . $additionalFile;
                if (file_exists($fullPath)) {
                    $files[] = $fullPath;
                }
            }
        }

        return $files;
    }

    /**
     * Get exclusion patterns
     */
    private function getExclusionPatterns()
    {
        $exclusions = array();
        
        // Add default exclusions unless disabled
        if (!isset($this->options['no-defaults'])) {
            $exclusions = array_merge($exclusions, $this->defaultExclusions);
        }

        // Add custom exclusions
        if (isset($this->options['exclude'])) {
            $customExclusions = explode(',', $this->options['exclude']);
            $exclusions = array_merge($exclusions, array_map('trim', $customExclusions));
        }

        return $exclusions;
    }

    /**
     * Check if file should be excluded
     */
    private function shouldExcludeFile($relativePath, $exclusions)
    {
        foreach ($exclusions as $pattern) {
            // Direct name match
            if (basename($relativePath) === $pattern) {
                return true;
            }
            
            // Path contains pattern
            if (strpos($relativePath, $pattern) !== false) {
                return true;
            }
            
            // Pattern matching
            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($relativePath))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get relative path
     */
    private function getRelativePath($from, $to)
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to = str_replace('\\', '/', $to);
        
        $relativePath = substr($to, strlen($from) + 1);
        
        return $relativePath;
    }

    /**
     * Format bytes for human reading
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get ZIP error message
     */
    private function getZipError($code)
    {
        switch($code) {
            case ZipArchive::ER_OK: return 'No error';
            case ZipArchive::ER_MULTIDISK: return 'Multi-disk zip archives not supported';
            case ZipArchive::ER_RENAME: return 'Renaming temporary file failed';
            case ZipArchive::ER_CLOSE: return 'Closing zip archive failed';
            case ZipArchive::ER_SEEK: return 'Seek error';
            case ZipArchive::ER_READ: return 'Read error';
            case ZipArchive::ER_WRITE: return 'Write error';
            case ZipArchive::ER_CRC: return 'CRC error';
            case ZipArchive::ER_ZIPCLOSED: return 'Containing zip archive was closed';
            case ZipArchive::ER_NOENT: return 'No such file';
            case ZipArchive::ER_EXISTS: return 'File already exists';
            case ZipArchive::ER_OPEN: return 'Can not open file';
            case ZipArchive::ER_TMPOPEN: return 'Failure to create temporary file';
            case ZipArchive::ER_ZLIB: return 'Zlib error';
            case ZipArchive::ER_MEMORY: return 'Memory allocation failure';
            case ZipArchive::ER_CHANGED: return 'Entry has been changed';
            case ZipArchive::ER_COMPNOTSUPP: return 'Compression method not supported';
            case ZipArchive::ER_EOF: return 'Premature EOF';
            case ZipArchive::ER_INVAL: return 'Invalid argument';
            case ZipArchive::ER_NOZIP: return 'Not a zip archive';
            case ZipArchive::ER_INTERNAL: return 'Internal error';
            case ZipArchive::ER_INCONS: return 'Zip archive inconsistent';
            case ZipArchive::ER_REMOVE: return 'Can not remove file';
            case ZipArchive::ER_DELETED: return 'Entry has been deleted';
            default: return 'Unknown error code: ' . $code;
        }
    }

    /**
     * Show help information
     */
    private function showHelp()
    {
        echo "Plugin ZIP Packager\n";
        echo "===================\n\n";
        echo "Usage:\n";
        echo "  plugin-zip [options]\n\n";
        echo "Options:\n";
        echo "  --name=STRING          Plugin folder name inside ZIP (default: current directory name)\n";
        echo "  --output=FILE          Output ZIP filename (default: plugin-name.zip)\n";
        echo "  --source=DIR           Source directory (default: current directory)\n";
        echo "  --version=STRING       Version to append to filename\n";
        echo "  --include=FILES        Additional files to include (comma-separated)\n";
        echo "  --exclude=PATTERNS     Additional exclusion patterns (comma-separated)\n";
        echo "  --no-defaults          Don't use default exclusion patterns\n";
        echo "  --help, -h             Show this help message\n\n";
        echo "Default Exclusions:\n";
        echo "  - Version control: .git, .gitignore, .github\n";
        echo "  - Development: composer.json, package.json, node_modules, vendor\n";
        echo "  - IDE files: .vscode, .idea, .phpstorm.meta.php\n";
        echo "  - Build artifacts: dist, build, *.zip, *.tar.gz\n";
        echo "  - OS files: .DS_Store, Thumbs.db\n";
        echo "  - Logs and temporary files: *.log, *.tmp, .cache\n\n";
        echo "Examples:\n";
        echo "  plugin-zip                                                       # Use current directory name\n";
        echo "  plugin-zip --name=my-plugin\n";
        echo "  plugin-zip --name=my-plugin --version=1.2.0 --output=releases/my-plugin-1.2.0.zip\n";
        echo "  plugin-zip --exclude=\"*.md,docs\" --include=\"important.txt\"    # Use current dir name with custom rules\n";
        echo "  plugin-zip --name=my-plugin --source=/path/to/plugin --no-defaults\n";
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
