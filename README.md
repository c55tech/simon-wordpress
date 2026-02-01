# SiMon WordPress Plugin

The SiMon WordPress plugin integrates WordPress sites with the SiMon monitoring
platform for intake submissions and site metadata collection.

## Requirements

- WordPress 6.x
- PHP 8.1+
- Access to a SiMon API instance

## Manual Installation

1. Download or clone the repository.
2. Copy the `simon` plugin folder into `wp-content/plugins/` so it becomes
   `wp-content/plugins/simon/`.
3. In the WordPress admin, go to **Plugins → Installed Plugins** and activate
   **SIMON Integration**.

## Configuration

1. Go to **Settings → SIMON**.
2. Configure:
   - **SIMON API URL**
   - **Auth Key**
   - **Client ID** / **Site ID**
3. Save settings and submit a snapshot (manual submit or cron).

## Composer Installation (VCS)

If your WordPress project uses Composer (Bedrock or similar), add the repo and
require the plugin:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/c55tech/simon-wordpress"
    }
  ],
  "require": {
    "c55tech/simon-wordpress": "v1.0.1"
  }
}
```

Then run:

```
composer update c55tech/simon-wordpress
```

Ensure `composer/installers` is available in your project (it is required by the
plugin, but some setups pin installers).

## Support

Issues and requests: https://github.com/c55tech/simon-wordpress/issues
