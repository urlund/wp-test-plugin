<?php
/**
 * GitHub Release Publisher
 *
 * Publishes a plugin ZIP as a release asset to a draft GitHub release matching the version in plugin.json.
 */

namespace Urlund\WordPress\PluginUpdater\Tools;

class PluginGitHubPublisher
{
    private $options = [];

    public function __construct()
    {
        $this->parseCliOptions();
    }

    public function run()
    {
        try {
            $this->validateOptions();
            $jsonData = json_decode(file_get_contents($this->options['json']), true);
            $version = $jsonData['version'] ?? null;
            if (!$version) {
                throw new \Exception("No version found in plugin.json");
            }
            $tag = 'v' . $version;
            // If plugin.json contains sha256, validate it
            if (!empty($jsonData['sha256'])) {
                $actualSha = hash_file('sha256', $this->options['zip']);
                if (strtolower($jsonData['sha256']) !== strtolower($actualSha)) {
                    throw new \Exception("SHA-256 mismatch: plugin.json has {$jsonData['sha256']}, but zip file is $actualSha");
                }
                $this->info("SHA-256 validated for zip file");
            }
            $release = $this->findRelease($version);
            if (!$release) {
                if (isset($this->options['create'])) {
                    $this->info("Release not found for tag $tag, creating new release...");
                    $release = $this->createRelease($version, $tag);
                    $this->success("Created new release for tag $tag (ID: {$release['id']})");
                } else {
                    $this->error("No release found for tag $tag (use --create to create one)");
                    exit(1);
                }
            } else {
                $this->info("Found release for tag $tag (ID: {$release['id']})");
            }
            $this->info("Uploading ZIP asset to release $tag");
            $this->uploadAsset($release['id'], $this->options['zip']);
            $this->success("Uploaded ZIP asset to release $tag");
            $this->info("Uploading plugin.json to release $tag");
            $this->uploadAsset($release['id'], $this->options['json']);
            $this->success("Uploaded plugin.json to release $tag");
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            exit(1);
        }
    }

    private function parseCliOptions()
    {
        $longopts = [
            'repo:',   // owner/repo
            'zip:',    // path to zip file
            'json:',   // path to plugin.json
            'token::', // optional, else use env
            'create',  // create release if not found
            'help',
        ];
        $opts = getopt('h', $longopts);
        if (isset($opts['h']) || isset($opts['help'])) {
            $this->showHelp();
            exit(0);
        }
        $this->options = $opts;
    }

    private function validateOptions()
    {
        foreach ([ 'repo', 'zip', 'json' ] as $key) {
            if (empty($this->options[$key])) {
                throw new \Exception("--$key is required");
            }
        }
        if (!file_exists($this->options['zip'])) {
            throw new \Exception("ZIP file not found: {$this->options['zip']}");
        }
        if (!file_exists($this->options['json'])) {
            throw new \Exception("plugin.json not found: {$this->options['json']}");
        }
        if (empty($this->options['token']) && getenv('GITHUB_TOKEN') === false) {
            throw new \Exception("GitHub token required via --token or GITHUB_TOKEN env var");
        }
    }

    private function getToken()
    {
        return $this->options['token'] ?? getenv('GITHUB_TOKEN');
    }

    private function findRelease($version)
    {
        $url = "https://api.github.com/repos/{$this->options['repo']}/releases";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GitHubReleasePublisher');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $this->getToken(),
            'Accept: application/vnd.github+json',
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            throw new \Exception("Failed to fetch releases: " . curl_error($ch));
        }
        $releases = json_decode($result, true);
        foreach ($releases as $release) {
            if (($release['tag_name'] === $version || $release['name'] === 'v' . $version)) {
                return $release;
            }
        }
        return null;
    }

    private function createRelease($version, $tag)
    {
        $url = "https://api.github.com/repos/{$this->options['repo']}/releases";
        $data = [
            'tag_name' => $tag,
            'name' => $tag,
            'body' => "Release $version",
            'draft' => false,
            'prerelease' => false
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GitHubReleasePublisher');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $this->getToken(),
            'Accept: application/vnd.github+json',
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($result === false) {
            throw new \Exception("Failed to create release: " . curl_error($ch));
        }
        
        if ($httpCode >= 300) {
            throw new \Exception("Failed to create release: HTTP $httpCode\n" . $result);
        }
        
        $release = json_decode($result, true);
        return $release;
    }

    private function uploadAsset($releaseId, $zipPath)
    {
        $repo = $this->options['repo'];
        $url = "https://uploads.github.com/repos/$repo/releases/$releaseId/assets?name=" . urlencode(basename($zipPath));
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GitHubReleasePublisher');

        // Determine Content-Type based on file extension
        $ext = strtolower(pathinfo($zipPath, PATHINFO_EXTENSION));
        switch ($ext) {
            case 'zip':
                $contentType = 'application/zip';
                break;
            case 'json':
                $contentType = 'application/json';
                break;
            default:
                $contentType = 'application/octet-stream';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: token ' . $this->getToken(),
            'Content-Type: ' . $contentType,
            'Accept: application/vnd.github+json',
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($zipPath));
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode >= 300) {
            $msg = "Failed to upload asset: HTTP $httpCode\n" . $result;
            throw new \Exception($msg);
        }
    }

    private function showHelp()
    {
        echo "GitHub Release Publisher\n";
        echo "========================\n\n";
        echo "Usage:\n";
        echo "  php src/GitHubReleasePublisher.php --repo=owner/repo --zip=dist/plugin.zip --json=dist/plugin.json [--token=ghp_xxx] [--create]\n\n";
        echo "Options:\n";
        echo "  --repo=owner/repo   GitHub repository (required)\n";
        echo "  --zip=FILE         Path to plugin ZIP file (required)\n";
        echo "  --json=FILE        Path to plugin.json (required, for version)\n";
        echo "  --token=TOKEN      GitHub token (optional, else use GITHUB_TOKEN env)\n";
        echo "  --create           Create release if it doesn't exist\n";
        echo "  --help             Show this help\n";
    }

    private function info($msg)    { echo "\033[34mℹ $msg\033[0m\n"; }
    private function success($msg) { echo "\033[32m✓ $msg\033[0m\n"; }
    private function error($msg)   { echo "\033[31m✗ $msg\033[0m\n"; }
}
