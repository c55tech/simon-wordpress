# SIMON WordPress Plugin

WordPress plugin for integrating with the SIMON monitoring system.

## Installation

1. Copy this plugin to your WordPress site's `wp-content/plugins/` directory:
   ```bash
   cp -r simon-wordpress /path/to/wordpress/wp-content/plugins/simon
   ```

2. Activate the plugin:
   - Via Admin UI: **Plugins → Installed Plugins → Activate "SIMON Integration"**
   - Via WP-CLI: `wp plugin activate simon`

## Configuration

### Step 1: Configure API URL

1. Navigate to: **Settings → SIMON**
2. Enter the SIMON API base URL (e.g., `http://localhost:3000`)
3. Configure cron settings if desired
4. Save

### Step 2: Create Client

1. Navigate to: **Tools → SIMON Client**
2. Fill in:
   - Client Name (required)
   - Contact Name (optional)
   - Contact Email (optional)
3. Click **Create/Update Client**
4. The Client ID will be saved automatically

### Step 3: Create Site

1. Navigate to: **Tools → SIMON Site**
2. Fill in:
   - Site Name
   - Site URL
   - External ID (optional)
   - Auth Token (optional)
3. Click **Create/Update Site**
4. The Site ID will be saved automatically

### Step 4: Test Submission

1. On the Site Configuration page, click **Submit Data Now** to test
2. Or use WP-CLI: `wp simon submit`

## Cron Integration

If enabled in settings, the plugin will automatically submit site data when WordPress cron runs.

To configure:
1. Go to **Settings → SIMON**
2. Check "Enable Cron"
3. Select the desired interval
4. Save

WordPress cron runs when visitors visit your site. For more reliable scheduling, consider using a system cron job to trigger WordPress cron.

## WP-CLI Command

Submit site data manually via WP-CLI:

```bash
wp simon submit
```

## What Data is Collected

The plugin collects and submits:

- **Core**: WordPress version and update status
- **Log Summary**: Error/warning counts (simplified)
- **Environment**: PHP version, memory limits, database info, web server
- **Extensions**: All installed plugins with versions
- **Themes**: All installed themes with versions

## Troubleshooting

- Check WordPress debug log: `wp-content/debug.log`
- Verify API URL is accessible from your WordPress server
- Ensure Client ID and Site ID are configured
- Test with WP-CLI command: `wp simon submit`
- Check PHP error logs if submissions fail

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- cURL extension enabled
- JSON extension enabled



