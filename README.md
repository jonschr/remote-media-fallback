# Remote Media Fallback

A single-file must-use WordPress plugin for local development sites. Existing
uploads remain local; missing files are fetched from the configured production
site and streamed without being stored permanently.

## Install

Copy `remote-media-fallback.php` directly into `wp-content/mu-plugins`, then add
the production site URL to `wp-config.php`:

```php
define( 'REMOTE_MEDIA_FALLBACK_URL', 'https://example.com' );
```

The local web server must route missing upload requests through WordPress.

## Development

Symlink the main file into a test site's MU-plugin directory:

```sh
ln -s /path/to/remote-media-fallback.php /path/to/site/wp-content/mu-plugins/remote-media-fallback.php
```

Run the dependency-free checks with:

```sh
php tests/validate.php
```

## Distribution

Publish `remote-media-fallback.php` and its SHA-256 checksum as release assets.
The installation skill can compare the plugin version, verify the checksum,
and replace the file atomically even when WordPress is stopped.
