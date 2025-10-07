# WP Test Plugin

A simple WordPress plugin boilerplate for testing and development.

## Description

This is a minimal WordPress plugin boilerplate that includes:
- Plugin activation and deactivation hooks
- Text domain for internationalization
- CSS and JavaScript enqueuing
- A simple shortcode example
- WordPress coding standards structure

## Installation

1. Download or clone this repository
2. Upload the `wp-test-plugin` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

### Shortcode Example

The plugin includes a simple shortcode that displays a greeting message:

```
[wp_test_hello name="World"]
```

You can customize the name parameter:

```
[wp_test_hello name="WordPress"]
```

## File Structure

```
wp-test-plugin/
├── css/
│   └── wp-test-plugin.css    # Plugin styles
├── js/
│   └── wp-test-plugin.js     # Plugin scripts
├── languages/                 # Translation files directory
├── LICENSE                    # MIT License
├── README.md                  # This file
└── wp-test-plugin.php        # Main plugin file
```

## Development

This plugin follows WordPress coding standards and best practices:
- Security: All output is escaped using `esc_html()`
- Direct access prevention: Checks for `WPINC` constant
- Proper hooks: Uses activation/deactivation hooks
- Asset loading: Properly enqueues CSS and JavaScript files
- Internationalization ready: Uses text domain for translations

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Author

Henrik Urlund
- GitHub: [@urlund](https://github.com/urlund)
