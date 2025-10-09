<?php
/**
 * PluginVersionBump CLI
 * Usage:
 *   php src/PluginVersionBump.php --plugin=plugin.php patch
 *   php src/PluginVersionBump.php --plugin=plugin.php minor
 *   php src/PluginVersionBump.php --plugin=plugin.php major
 *   php src/PluginVersionBump.php --plugin=plugin.php 1.2.3
 *   php src/PluginVersionBump.php --composer=composer.json patch
 *   php src/PluginVersionBump.php --plugin=plugin.php --composer=composer.json patch
 */

namespace Urlund\WordPress\PluginUpdater;

class PluginVersionBump
{
    private $options = [];
    private $bumpType = null;

    public function __construct()
    {
        $this->parseCliOptions();
    }

    public function run()
    {
        $composerFile = $this->options['composer'] ?? null;
        if (!$composerFile && file_exists('composer.json')) {
            $composerFile = 'composer.json';
        }
        $newVersion = null;
        if ($composerFile) {
            $newVersion = $this->bumpComposerJson($composerFile, $this->bumpType);
        }
        // Always align plugin file version to composer.json version if both are present
        if (isset($this->options['plugin'])) {
            if ($newVersion) {
                $this->setPluginFileVersion($this->options['plugin'], $newVersion);
            } else {
                $newVersion = $this->bumpPluginFile($this->options['plugin'], $this->bumpType);
            }
        }
        // If plugin file is git managed, create a tag for the new version
        if ($newVersion && $this->isGitManaged($this->options['plugin'])) {
            $this->commitVersionChanges($newVersion);
            $this->createGitTag($newVersion);
        }
    }

    private function parseCliOptions()
    {
        $opts = getopt('', ['plugin:', 'composer::', 'push', 'help']);
        global $argv;
        $args = array_slice($argv, 1);
        $bump = null;
        foreach ($args as $arg) {
            if (in_array($arg, ['patch', 'minor', 'major']) || preg_match('/^\d+\.\d+\.\d+$/', $arg)) {
                $bump = $arg;
                break;
            }
        }
        if (isset($opts['help'])) {
            $this->showHelp();
            exit(0);
        }
        if (empty($opts['plugin'])) {
            $this->showHelp("Missing required --plugin argument");
            exit(1);
        }
        if (!$bump) {
            $this->showHelp("Missing bump type (patch|minor|major|<version>)");
            exit(1);
        }
        $this->options = $opts;
        $this->bumpType = $bump;
    }

    private function bumpComposerJson($composerFile, $bumpType)
    {
        if (!file_exists($composerFile)) {
            $this->error("composer.json not found: $composerFile");
            exit(1);
        }
        $data = json_decode(file_get_contents($composerFile), true);
        if (empty($data['version'])) {
            $this->error("No version found in $composerFile");
            exit(1);
        }
        $oldVersion = $data['version'];
        $newVersion = $this->getBumpedVersion($oldVersion, $bumpType);
        $data['version'] = $newVersion;
        file_put_contents($composerFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $this->success("composer.json version updated: $oldVersion → $newVersion");
        return $newVersion;
    }

    private function setPluginFileVersion($pluginFile, $version)
    {
        $ext = strtolower(pathinfo($pluginFile, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $data = json_decode(file_get_contents($pluginFile), true);
            $oldVersion = $data['version'] ?? null;
            $data['version'] = $version;
            file_put_contents($pluginFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->success("$pluginFile version aligned to composer.json: $oldVersion → $version");
        } elseif ($ext === 'php') {
            $lines = file($pluginFile);
            $found = false;
            $oldVersion = null;
            foreach ($lines as $i => $line) {
                if (preg_match('/^(\s*\*?\s*Version:\s*)(.+)$/i', $line, $m)) {
                    $oldVersion = trim($m[2]);
                    // Replace the line with the prefix + new version
                    $lines[$i] = $m[1] . $version . "\n";
                    $found = true;
                    break;
                }
            }
            if ($found) {
                file_put_contents($pluginFile, implode('', $lines));
                $this->success("$pluginFile version aligned to composer.json: $oldVersion → $version");
            } else {
                $this->error("No Version: header found in $pluginFile");
            }
        } else {
            $this->error("Unsupported plugin file type: $pluginFile");
        }
    }

    private function bumpPluginFile($pluginFile, $bumpType)
    {
        if (!file_exists($pluginFile)) {
            $this->error("Plugin file not found: $pluginFile");
            exit(1);
        }
        $ext = strtolower(pathinfo($pluginFile, PATHINFO_EXTENSION));
        if ($ext === 'json') {
            $data = json_decode(file_get_contents($pluginFile), true);
            if (empty($data['version'])) {
                $this->error("No version found in $pluginFile");
                exit(1);
            }
            $oldVersion = $data['version'];
            $newVersion = $this->getBumpedVersion($oldVersion, $bumpType);
            $data['version'] = $newVersion;
            file_put_contents($pluginFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $this->success("$pluginFile version updated: $oldVersion → $newVersion");
            return $newVersion;
        } elseif ($ext === 'php') {
            $lines = file($pluginFile);
            $found = false;
            $oldVersion = null;
            $newVersion = null;
            foreach ($lines as $i => $line) {
                if (preg_match('/^\s*\*?\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/i', $line, $m)) {
                    $oldVersion = $m[1];
                    $newVersion = $this->getBumpedVersion($oldVersion, $bumpType);
                    // Replace the full version number after 'Version:'
                    $lines[$i] = preg_replace('/(Version:\s*)([0-9]+\.[0-9]+\.[0-9]+)/i', '$1' . $newVersion, $line);
                    $found = true;
                    break;
                }
            }
            if (!$found || !$oldVersion || !$newVersion) {
                $this->error("No Version: header found in $pluginFile");
                exit(1);
            }
            file_put_contents($pluginFile, implode('', $lines));
            $this->success("$pluginFile version updated: $oldVersion → $newVersion");
            return $newVersion;
        } else {
            $this->error("Unsupported plugin file type: $pluginFile");
            exit(1);
        }
        return null;
    }

    // Check if the plugin file is in a git repo
    private function isGitManaged($file)
    {
        $dir = dirname(realpath($file));
        while ($dir && $dir !== '/' && $dir !== '.') {
            if (is_dir($dir . '/.git')) {
                return true;
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
        return false;
    }

    // Commit version changes to git
    private function commitVersionChanges($version)
    {
        $files = [];
        
        // Add plugin file to commit
        if (isset($this->options['plugin'])) {
            $files[] = $this->options['plugin'];
        }
        
        // Add composer.json if it was updated
        $composerFile = $this->options['composer'] ?? null;
        if (!$composerFile && file_exists('composer.json')) {
            $composerFile = 'composer.json';
        }
        if ($composerFile) {
            $files[] = $composerFile;
        }
        
        if (!empty($files)) {
            $addCmd = "git add " . implode(' ', array_map('escapeshellarg', $files));
            exec($addCmd, $out, $code);
            
            if ($code === 0) {
                $commitMsg = "Bump version to $version";
                $commitCmd = "git commit -m " . escapeshellarg($commitMsg);
                exec($commitCmd, $commitOut, $commitCode);
                
                if ($commitCode === 0) {
                    $this->success("Version changes committed: $commitMsg");
                } else {
                    $this->error("Failed to commit version changes");
                }
            } else {
                $this->error("Failed to add files to git");
            }
        }
    }

    // Create a git tag for the new version
    private function createGitTag($version)
    {
        $tag = 'v' . $version;
        $cmd = "git tag $tag";
        exec($cmd, $out, $code);
        if ($code === 0) {
            $this->success("Git tag $tag created");
            
            // Push the tag if --push flag is provided
            if (isset($this->options['push'])) {
                $pushCmd = "git push origin $tag";
                exec($pushCmd, $pushOut, $pushCode);
                if ($pushCode === 0) {
                    $this->success("Git tag $tag pushed to origin");
                } else {
                    $this->error("Failed to push git tag $tag to origin");
                }
            } else {
                $this->info("To push the tag to origin: git push origin $tag");
            }
        } else {
            $this->error("Failed to create git tag $tag (maybe it already exists?)");
        }
    }

    private function info($msg) { echo "\033[34mℹ $msg\033[0m\n"; }

    private function getBumpedVersion($oldVersion, $bumpType)
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $bumpType)) {
            return $bumpType;
        }
        list($major, $minor, $patch) = explode('.', $oldVersion);
        switch ($bumpType) {
            case 'patch':
                $patch++;
                break;
            case 'minor':
                $minor++;
                $patch = 0;
                break;
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;
            default:
                $this->error("Unknown bump type: $bumpType");
                exit(1);
        }
        return "$major.$minor.$patch";
    }

    private function showHelp($msg = null)
    {
        if ($msg) echo "\033[31m$msg\033[0m\n\n";
        echo "PluginVersionBump CLI\n";
        echo "====================\n\n";
        echo "Usage:\n";
        echo "  php src/PluginVersionBump.php --plugin=plugin.php patch\n";
        echo "  php src/PluginVersionBump.php --plugin=plugin.php minor\n";
        echo "  php src/PluginVersionBump.php --plugin=plugin.php major\n";
        echo "  php src/PluginVersionBump.php --plugin=plugin.php 1.2.3\n";
        echo "  php src/PluginVersionBump.php --composer=composer.json patch\n";
        echo "  php src/PluginVersionBump.php --plugin=plugin.php --composer=composer.json patch --push\n";
        echo "\nOptions:\n";
        echo "  --plugin=FILE      Path to plugin file (plugin.php or plugin.json)\n";
        echo "  --composer=FILE    Path to composer.json (default: composer.json)\n";
        echo "  --push             Push git tag to origin after creating it\n";
        echo "  patch|minor|major  Bump type, or explicit version (e.g., 1.2.3)\n";
        echo "  --help             Show this help\n";
    }

    private function success($msg) { echo "\033[32m✓ $msg\033[0m\n"; }
    private function error($msg)   { echo "\033[31m✗ $msg\033[0m\n"; }
}
