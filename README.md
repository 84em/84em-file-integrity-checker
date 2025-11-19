# 84EM File Integrity Checker

A modern WordPress plugin for monitoring file integrity across your WordPress installation. Detect unauthorized changes, track file modifications, and maintain security compliance with automated scanning and comprehensive reporting.

## ⚠️ Important: What This Plugin Is (And Isn't)

### This is a SPECIALIZED File Integrity Monitor, NOT a Complete Security Plugin

**✅ What This Plugin DOES:**
- **Monitors file changes** using SHA-256 checksums to detect any modifications
- **Tracks exactly what changed** by storing encrypted file content and generating diffs
- **Provides forensic analysis** showing line-by-line changes in modified files
- **Maintains compliance audit trails** with comprehensive logging and history
- **Alerts on unauthorized changes** via email and Slack notifications
- **Schedules automated scans** using reliable Action Scheduler (not WP Cron)

**❌ What This Plugin DOES NOT Do:**
- **Does NOT block attacks** - No firewall or traffic filtering
- **Does NOT scan for malware** - No virus/malware pattern detection
- **Does NOT fix vulnerabilities** - No automatic security patching
- **Does NOT harden WordPress** - No login protection, 2FA, or security headers
- **Does NOT prevent intrusions** - No brute force protection or IP blocking
- **Does NOT monitor user activity** - No login tracking or user auditing
- **Does NOT clean infections** - No automatic malware removal
- **Does NOT check plugin/theme vulnerabilities** - No vulnerability database checks
- **Does NOT protect against SQL injection** - No query filtering
- **Does NOT implement WAF** - No Web Application Firewall features

### 📋 When to Use This Plugin

**Use this plugin when you need:**
- Regulatory compliance requiring file integrity monitoring (PCI DSS, HIPAA, etc.)
- Forensic capabilities to investigate what changed in your files
- Detection of unauthorized file modifications
- Detailed audit trails for security compliance
- To complement your existing security plugin with deep file monitoring

**This plugin works best ALONGSIDE traditional security plugins** like Wordfence, Sucuri, or iThemes Security that handle active threat prevention. Think of it as your "security camera" that records what happened, not your "security guard" that stops threats.

---

## Features

### 🔍 Comprehensive File Scanning
- SHA-256 checksum generation for all files
- Configurable file type scanning (PHP, JS, CSS, HTML by default)
- Smart exclusion patterns for cache and temporary directories
- Memory-efficient scanning with real-time progress tracking
- Batch processing for large installations
- Pre-generated diffs for better performance
- Sensitive file protection (wp-config.php, .env, etc.)

### 🎯 Priority Monitoring (Coming Soon)
- **Three-tier Priority System**: Critical, High, Normal classifications
- **Pattern Matching**: Exact, prefix, suffix, contains, glob, and regex patterns
- **Change Velocity Tracking**: Detect files changing too frequently
- **Maintenance Windows**: Suppress alerts during scheduled maintenance
- **Immediate Notifications**: Instant alerts for critical file changes
- **Rule Inheritance**: Directory-level priority cascading
- **Throttling**: Prevent notification spam with time-based limits

### 🔐 Security & Data Protection
- AES-256-GCM encryption for all stored file content
- Authenticated encryption with tamper detection
- Secure key derivation from WordPress salts
- Automatic encryption of cached file data
- Protection of sensitive file content at rest

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
- Compare checksums between scans with diff viewing
- Dashboard widget for at-a-glance monitoring
- Comprehensive system logging with levels and contexts
- Bulk operations for scan management

### 🛠️ Developer-Friendly Architecture
- Modern PHP 8.0+ with type declarations
- PSR-4 autoloading via Composer
- Dependency injection container
- Repository pattern for data access
- Service layer architecture
- Comprehensive PHPUnit test suite

### 🔔 Notification System
- Email notifications for detected changes
- Slack webhook integration with testing
- Customizable notification templates
- Configure which changes trigger alerts
- Resend notifications for past scans

### 🎨 WordPress Integration
- Native WordPress admin UI
- Inherits WordPress admin color schemes
- WP-CLI command support
- Translation ready

## Requirements

- PHP 8.0 or higher with OpenSSL extension
- WordPress 5.8 or higher
- MySQL 5.7+ or MariaDB 10.2+
- Action Scheduler (provided by WooCommerce, Action Scheduler plugin, or similar)
- Properly configured WordPress salts (AUTH_KEY, SECURE_AUTH_KEY, etc.)
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
- **Slack Integration**: Configure webhook for Slack notifications with test button
- **Logging**: Configure system log levels (debug, info, warning, error) and retention

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

# Recalculate all schedule next run times
wp 84em integrity recalculate_schedules
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

## Baseline Management

### What is a Baseline Scan?

The baseline scan is your reference point for file integrity monitoring. It represents a known-good state of your WordPress installation that all future scans are compared against.

### How Baselines Work

1. **Automatic Creation**: Your first completed scan is automatically marked as the baseline
2. **Storage Optimization**: Only the baseline contains all files. Subsequent scans only store changed, new, and deleted files (98% storage reduction)
3. **Protected Retention**: Baseline scans are exempt from automatic deletion and retention policies
4. **Hybrid Comparison**: New scans compare against the most recent scan, falling back to the baseline for complete file history

### When to Refresh Your Baseline

Consider creating a new baseline when:

- WordPress core has been updated to a new major version
- Multiple plugins have been installed, updated, or removed
- You've verified that all file changes detected are legitimate
- Your current baseline is older than 6 months
- You receive a suggestion notice after WordPress or plugin updates

### Baseline Management Commands

**View current baseline:**
```bash
wp 84em integrity baseline show
```

**Set a specific scan as baseline:**
```bash
wp 84em integrity baseline mark <scan-id>

# Example: Set scan #42 as baseline
wp 84em integrity baseline mark 42
```

**Create new baseline (run scan and set as baseline):**
```bash
wp 84em integrity baseline refresh

# Skip confirmation prompt
wp 84em integrity baseline refresh --yes
```

**Clear baseline designation:**
```bash
wp 84em integrity baseline clear
```

**View baseline details:**
```bash
# Show baseline scan information
wp 84em integrity baseline show

# View full baseline scan results
wp 84em integrity results <baseline-scan-id>
```

### Baseline Protection Features

Baseline scans are automatically protected:

- **Cannot be deleted** through the admin interface or bulk operations
- **Exempt from retention policies** - never automatically cleaned up
- **Preserved during cleanup** operations via WP-CLI
- **Included in database optimization** but file records are never removed

To delete a baseline scan, you must first clear the baseline designation using the Settings page or WP-CLI command.

### Automated Suggestions

The plugin will automatically suggest creating a new baseline when:

- **WordPress core is updated** - A notice appears for 7 days after version change
- **Plugins are activated/deactivated** - Cumulative notice for plugin file changes
- **Baseline age exceeds 6 months** - Warning shown in Settings page

These suggestions can be dismissed and will automatically expire after 7 days.

### Best Practices

1. **Review before baseline creation**: Always review scan results before marking as baseline to ensure all changes are legitimate
2. **Regular baseline updates**: Refresh your baseline after major WordPress or plugin updates
3. **Keep baselines relevant**: If your baseline is very old, comparison accuracy decreases
4. **Use WP-CLI for automation**: Integrate baseline refresh into your deployment workflow:
   ```bash
   # After WordPress update in staging
   wp 84em integrity baseline refresh --yes
   ```

### Database Storage Impact

The baseline system dramatically reduces database storage requirements:

- **Before optimization**: ~9.4 million rows for 30 days of hourly scans
- **After optimization**: ~188,000 rows for 30 days of hourly scans
- **Reduction**: 98% storage savings

Only the baseline scan stores all file records. Subsequent scans only store files that have changed, are new, or were deleted.

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
3. **View Diffs**: See the pre-generated diffs for modified files
4. **Investigate**: Determine if changes are authorized
5. **Take Action**: Address any unauthorized modifications
6. **Document**: Keep notes on legitimate changes
7. **Manage History**: Use bulk operations to clean up old scan results

### Best Practices

- **Regular Scans**: Schedule daily scans during low-traffic periods
- **Baseline After Updates**: Run manual scan after plugin/theme updates
- **Monitor Critical Files**: Focus on PHP files in wp-admin and wp-includes
- **Retention Policy**: Keep 30-90 days of history for compliance
- **Email Alerts**: Configure notifications for production sites
- **Exclude Dynamic**: Skip cache, uploads, and backup directories

## Database Schema

The plugin creates five custom tables:

### eightyfourem_integrity_scan_results
Stores scan metadata and statistics including scan duration, memory usage, and type

### eightyfourem_integrity_file_records
Contains individual file checksums, change tracking, pre-generated diff content, and sensitive file flags

### eightyfourem_integrity_scan_schedules
Manages multiple scan schedules with configurations and Action Scheduler integration

### eightyfourem_integrity_checksum_cache
Temporary cache for file content (48-hour TTL) used during diff generation at scan time

### eightyfourem_integrity_logs
System activity logging with levels (debug, info, warning, error), contexts, and user tracking

## Security Considerations

- **Capability Checks**: All admin actions require `manage_options` capability
- **Nonce Verification**: CSRF protection on all forms and AJAX endpoints
- **Data Sanitization**: Input validation and output escaping
- **SQL Injection Prevention**: Prepared statements for all queries
- **File Access Validation**: Path traversal prevention and sensitive file protection
- **Sensitive File Protection**: No content storage or diffs for wp-config.php, .env, keys, etc.

## Performance

- **Batch Processing**: Handles large file sets efficiently
- **Memory Management**: Streaming for large files
- **Database Indexing**: Optimized queries for fast retrieval
- **Progress Tracking**: Real-time feedback during scans via AJAX
- **Configurable Limits**: Set max file size to skip large files
- **Efficient Diff Generation**: Diffs generated at scan time, not on-demand
- **Temporary Cache**: 48-hour content cache with automatic cleanup
- **Background Processing**: Uses Action Scheduler for non-blocking operations

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
├── package.json                      # NPM dependencies for build tools
├── build.sh                          # Production build script
├── phpunit.xml                      # Test configuration
├── src/                             # Source code (PSR-4)
│   ├── Admin/                       # Admin interface classes
│   ├── CLI/                         # WP-CLI commands
│   ├── Container.php                # Dependency injection
│   ├── Core/                        # Core functionality
│   ├── Database/                    # Data access layer
│   ├── Plugin.php                   # Bootstrap class
│   ├── Scanner/                     # File scanning logic
│   ├── Security/                    # Security implementations
│   ├── Services/                    # Business logic
│   └── Utils/                       # Helper utilities
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

This plugin is licensed under the MIT License.

## Credits

Developed by [84EM](https://84em.com) - Remote WordPress Development Agency

### Technologies Used

- **Action Scheduler** - Reliable background processing
- **Composer** - Dependency management
- **PHPUnit** - Testing framework
- **WordPress** - Content management system

## Contributing

We welcome contributions! Please feel free to submit pull requests, report bugs, and suggest features through the GitHub repository.

---

**84EM File Integrity Checker** - Enterprise-grade file monitoring for WordPress
