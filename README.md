# 84EM File Integrity Checker

A modern WordPress plugin for monitoring file integrity across your WordPress installation. Detect unauthorized changes, track file modifications, and maintain security compliance with automated scanning and comprehensive reporting.

## Features

### 🔍 Comprehensive File Scanning
- SHA-256 checksum generation for all files
- Configurable file type scanning (PHP, JS, CSS, HTML by default)
- Smart exclusion patterns for cache and temporary directories
- Memory-efficient scanning with progress tracking
- Batch processing for large installations

### ⏰ Advanced Scheduling System
- Multiple independent scan schedules
- Flexible frequency options:
  - **Hourly**: Run at specific minutes past the hour
  - **Daily**: Run at any time of day
  - **Weekly**: Choose day and time
- Timezone-aware scheduling
- Action Scheduler integration (not WP Cron) for reliability
- Enable/disable schedules without deletion

### 📊 Detailed Reporting
- Track file changes, additions, and deletions
- View scan history with complete audit trail
- Compare checksums between scans
- Dashboard widget for at-a-glance monitoring
- System logging with detailed activity tracking

### 🛠️ Developer-Friendly Architecture
- Modern PHP 8.0+ with type declarations
- PSR-4 autoloading via Composer
- Dependency injection container
- Repository pattern for data access
- Service layer architecture
- Comprehensive PHPUnit test suite

### 🔔 Notification System
- Email notifications for detected changes
- Slack webhook integration
- Customizable notification templates
- Configure which changes trigger alerts

### 🎨 WordPress Integration
- Native WordPress admin UI
- Inherits WordPress admin color schemes
- WP-CLI command support
- Translation ready

## Requirements

- PHP 8.0 or higher
- WordPress 5.8 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Action Scheduler (provided by WooCommerce, Action Scheduler plugin, or similar)
- Composer (for development)

## Installation

### From WordPress Admin

1. Download the plugin ZIP file
2. Navigate to **Plugins → Add New** in WordPress admin
3. Click **Upload Plugin** and select the ZIP file
4. Click **Install Now** and then **Activate**

### Manual Installation

1. Upload the `84em-file-integrity-checker` folder to `/wp-content/plugins/`
2. Run `composer install` in the plugin directory
3. Activate the plugin through the WordPress admin

### Development Installation

```bash
cd wp-content/plugins
git clone [repository-url] 84em-file-integrity-checker
cd 84em-file-integrity-checker
composer install
```

## Configuration

### Basic Settings

Navigate to **File Integrity → Settings** to configure:

- **File Extensions**: Choose which file types to scan
- **Excluded Directories**: Paths to skip during scanning
- **Max File Size**: Skip files larger than this size
- **Email Notifications**: Get alerts when changes are detected
- **Retention Period**: How long to keep scan history
- **Slack Integration**: Configure webhook for Slack notifications
- **Logging**: Configure system log levels and retention

### Creating Scan Schedules

1. Go to **File Integrity → Schedules**
2. Click **Create New Schedule**
3. Configure:
   - **Name**: Descriptive name for the schedule
   - **Frequency**: Hourly, daily, or weekly
   - **Time**: When to run the scan
   - **Active**: Enable immediately or keep disabled

### Example Schedule Configurations

**Daily Security Scan**
- Frequency: Daily
- Time: 2:00 AM
- Purpose: Regular overnight integrity check

**Hourly Quick Scan**
- Frequency: Hourly
- Time: :15 (15 minutes past each hour)
- Purpose: Frequent monitoring for critical sites

**Weekly Deep Scan**
- Frequency: Weekly
- Day: Sunday
- Time: 3:00 AM
- Purpose: Comprehensive weekly audit

## WP-CLI Commands

The plugin provides comprehensive CLI support for automation and scripting.

### Running Scans

```bash
# Run a manual scan
wp 84em integrity scan

# Run with specific type
wp 84em integrity scan --type=scheduled

# Output in different formats
wp 84em integrity scan --format=json
```

### Viewing Results

```bash
# List recent scan results
wp 84em integrity results

# View specific scan details
wp 84em integrity results 123
```

### Managing Schedules

```bash
# List all schedules
wp 84em integrity schedules list

# Create a new schedule
wp 84em integrity schedules create \
  --name="Daily Scan" \
  --frequency=daily \
  --time=02:00 \
  --active

# Enable/disable schedules
wp 84em integrity schedules enable --id=1
wp 84em integrity schedules disable --id=1

# Delete a schedule
wp 84em integrity schedules delete --id=1
```

### Configuration Management

```bash
# List all settings
wp 84em integrity config list

# Get specific setting
wp 84em integrity config get notification_email

# Update settings
wp 84em integrity config set notification_email admin@example.com
wp 84em integrity config set max_file_size 10485760
```

## Usage Guide

### First Time Setup

1. **Activate the plugin** - Database tables are created automatically
2. **Configure settings** - Set file types and exclusions
3. **Run initial scan** - Establish baseline checksums
4. **Create schedules** - Set up automated scanning
5. **Configure notifications** - Get alerts for changes

### Monitoring Workflow

1. **Dashboard Overview**: Check the dashboard widget for quick status
2. **Review Changes**: When changes are detected, review the scan details
3. **Investigate**: Determine if changes are authorized
4. **Take Action**: Address any unauthorized modifications
5. **Document**: Keep notes on legitimate changes

### Best Practices

- **Regular Scans**: Schedule daily scans during low-traffic periods
- **Baseline After Updates**: Run manual scan after plugin/theme updates
- **Monitor Critical Files**: Focus on PHP files in wp-admin and wp-includes
- **Retention Policy**: Keep 30-90 days of history for compliance
- **Email Alerts**: Configure notifications for production sites
- **Exclude Dynamic**: Skip cache, uploads, and backup directories

## Database Schema

The plugin creates three custom tables:

### eightyfourem_integrity_scan_results
Stores scan metadata and statistics

### eightyfourem_integrity_file_records
Contains individual file checksums and change tracking

### eightyfourem_integrity_scan_schedules
Manages multiple scan schedules with configurations

## Security Considerations

- **Capability Checks**: All admin actions require `manage_options` capability
- **Nonce Verification**: CSRF protection on all forms
- **Data Sanitization**: Input validation and output escaping
- **SQL Injection Prevention**: Prepared statements for all queries
- **File Access Validation**: Checks before generating checksums

## Performance

- **Batch Processing**: Handles large file sets efficiently
- **Memory Management**: Streaming for large files
- **Database Indexing**: Optimized queries for fast retrieval
- **Progress Tracking**: Real-time feedback during scans
- **Configurable Limits**: Set max file size to skip large files

## Troubleshooting

### Common Issues

**"Action Scheduler not available"**
- Install WooCommerce or Action Scheduler plugin
- Scheduling features require Action Scheduler library

**Scan times out**
- Increase PHP max_execution_time
- Reduce max file size setting
- Add more directories to exclusion list

**Memory errors**
- Increase PHP memory_limit
- Exclude large directories like uploads
- Reduce file size limit

**No changes detected**
- Verify file permissions are readable
- Check excluded directories settings
- Ensure scan completed successfully

### Debug Mode

Enable WordPress debug mode for detailed logging:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

Check logs at: `wp-content/debug.log`

## Development

### Project Structure

```
84em-file-integrity-checker/
├── 84em-file-integrity-checker.php  # Main plugin file
├── composer.json                     # Dependencies and autoloading
├── phpunit.xml                      # Test configuration
├── src/                             # Source code (PSR-4)
│   ├── Admin/                       # Admin interface classes
│   ├── CLI/                         # WP-CLI commands
│   ├── Container.php                # Dependency injection
│   ├── Core/                        # Core functionality
│   ├── Database/                    # Data access layer
│   ├── Plugin.php                   # Bootstrap class
│   ├── Scanner/                     # File scanning logic
│   └── Services/                    # Business logic
├── tests/                           # PHPUnit tests
│   ├── Unit/                        # Unit tests
│   └── Integration/                 # Integration tests
├── views/                           # Admin templates
│   └── admin/                       # Admin page views
└── assets/                          # Frontend resources
    ├── css/                         # Stylesheets
    └── js/                          # JavaScript
```

### Running Tests

```bash
# Run all tests
composer test

# Run unit tests only
composer test:unit

# Run integration tests
composer test:integration

# Run specific test file
vendor/bin/phpunit tests/Unit/Scanner/ChecksumGeneratorTest.php
```

### Coding Standards

The plugin follows WordPress coding standards with modern PHP practices:

- PSR-4 autoloading
- Type declarations
- Dependency injection
- Repository pattern
- Service layer pattern

### Extending the Plugin

The plugin uses a container-based architecture for easy extension:

```php
// Register a custom service
$container->register( CustomService::class, function( $container ) {
    return new CustomService(
        $container->get( IntegrityService::class )
    );
});
```

## Support

For bug reports and feature requests, please use the GitHub issues tracker.

## License

This plugin is proprietary software owned by 84EM.

## Credits

Developed by [84EM](https://84em.com) - Remote WordPress Development Agency

### Technologies Used

- **Action Scheduler** - Reliable background processing
- **Composer** - Dependency management
- **PHPUnit** - Testing framework
- **WordPress** - Content management system

## Contributing

While this is proprietary software, we welcome feedback and suggestions. Please submit issues through the appropriate channels.

---

**84EM File Integrity Checker** - Enterprise-grade file monitoring for WordPress