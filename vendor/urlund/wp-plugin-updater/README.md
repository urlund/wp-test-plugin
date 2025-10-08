# WP Plugin Updater with CLI Tools

A WordPress plugin updater with GitHub integration and CLI tools for generating plugin metadata.

## Installation

```bash
composer require urlund/wp-plugin-updater
```

Or for development:

```bash
git clone <repository>
cd wp-plugin-updater
composer install
```

## Usage

### CLI Tool: Plugin JSON Generator

Generate `plugin.json` metadata files from WordPress plugin headers:

#### Via Composer Scripts

```bash
# Using the plugin-json script
composer run plugin-json -- --plugin=path/to/plugin.php

# Using the generate-json script (alias)
composer run generate-json -- --plugin=path/to/plugin.php --output=dist/plugin.json
```

#### Direct Binary Execution

```bash
# If installed via Composer globally or locally
./vendor/bin/generate-plugin-json --plugin=my-plugin.php

# From project root
./bin/generate-plugin-json --plugin=my-plugin.php
```

#### Common Examples

```bash
# Basic usage
composer run plugin-json -- --plugin=my-plugin.php

# With custom output and download URL
composer run plugin-json -- \
  --plugin=src/my-plugin.php \
  --output=dist/plugin.json \
  --download-url=https://github.com/user/repo/releases/latest/download/plugin.zip

# With configuration file for banners/icons
composer run plugin-json -- \
  --plugin=my-plugin.php \
  --config=config.json \
  --tested=6.4 \
  --requires-php=8.0
```

### CLI Options

| Option | Description | Example |
|--------|-------------|---------|
| `--plugin` | *(Required)* Path to main plugin PHP file | `--plugin=my-plugin.php` |
| `--output` | Output file path | `--output=dist/plugin.json` |
| `--slug` | Plugin slug (auto-detected if not provided) | `--slug=my-custom-slug` |
| `--download-url` | Download URL for plugin ZIP | `--download-url=https://...` |
| `--tested` | WordPress version tested up to | `--tested=6.4` |
| `--requires-php` | Minimum PHP version | `--requires-php=8.0` |
| `--sections-dir` | Directory with section files | `--sections-dir=docs` |
| `--config` | JSON config for banners/icons | `--config=assets.json` |
| `--help` | Show help message | `--help` |

### Configuration File Format

Create a JSON file for banners, icons, and upgrade notices:

```json
{
    "banners": {
        "low": "https://example.com/banner-772x250.jpg",
        "high": "https://example.com/banner-1544x500.jpg"
    },
    "icons": {
        "1x": "https://example.com/icon-128x128.png",
        "2x": "https://example.com/icon-256x256.png",
        "svg": "https://example.com/icon.svg"
    },
    "upgrade_notice": "Important security update available."
}
```

### Section Files

The tool automatically searches for these files in the plugin directory or `--sections-dir`:

- **description**: `description.md`, `description.txt`, `README.md`
- **installation**: `installation.md`, `installation.txt`, `INSTALL.md`
- **faq**: `faq.md`, `faq.txt`, `FAQ.md`
- **changelog**: `changelog.md`, `changelog.txt`, `CHANGELOG.md`
- **screenshots**: `screenshots.md`, `screenshots.txt`
- **other_notes**: `notes.md`, `notes.txt`, `NOTES.md`

### Integration Examples

#### GitHub Actions

```yaml
name: Generate Plugin JSON
on:
  push:
    tags: ['v*']

jobs:
  generate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.0'
          
      - name: Install dependencies
        run: composer install --no-dev --optimize-autoloader
        
      - name: Generate plugin.json
        run: |
          composer run plugin-json -- \
            --plugin=my-plugin.php \
            --download-url=https://github.com/${{ github.repository }}/releases/latest/download/plugin.zip \
            --tested=6.4
```

#### Package.json Integration

If you also use npm/yarn in your project:

```json
{
  "scripts": {
    "build:metadata": "composer run plugin-json -- --plugin=my-plugin.php --config=assets.json",
    "build": "npm run build:js && npm run build:metadata"
  }
}
```

## PHP Classes

### GitHubPluginRepository

Main updater class for handling GitHub-based WordPress plugin updates with JSON-first approach.

```php
use Urlund\WpPluginUpdater\GitHubPluginRepository;

$updater = new GitHubPluginRepository([
    'plugin_file' => __FILE__,
    'slug' => 'my-plugin',
    'github_repo' => 'username/repository',
    'github_token' => 'your-token', // Optional but recommended
    'json_url' => 'https://example.com/plugin.json', // Optional
]);
```

### PluginJsonGenerator

Programmatic access to the JSON generator:

```php
use Urlund\WpPluginUpdater\PluginJsonGenerator;

// This class is primarily designed for CLI usage
// but can be extended for programmatic use
```

## Requirements

- PHP 7.4 or higher
- WordPress 5.0 or higher (for the updater classes)
- Composer

## License

GPL v2 or later
