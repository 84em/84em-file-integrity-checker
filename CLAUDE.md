# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

84EM File Integrity Checker is a WordPress plugin for monitoring file integrity across WordPress installations. It detects unauthorized changes, tracks file modifications, and maintains security compliance with automated scanning and comprehensive reporting.

## Key Commands

### Development Commands

```bash
# Install dependencies (required after clone)
composer install
npm install

# Run tests
composer test                          # Run all tests
composer test:unit                      # Run unit tests only
composer test:integration               # Run integration tests
vendor/bin/phpunit tests/Unit/Scanner/ChecksumGeneratorTest.php  # Run specific test

# Build assets (minify CSS/JS)
npm run build                          # Minify all assets
npm run minify:css                     # Minify CSS only
npm run minify:js                      # Minify JavaScript only
npm run minify:admin-js                # Minify admin.js specifically
npm run minify:modal-js                # Minify modal.js specifically
npm run watch                          # Watch for changes and auto-minify

# Create production build
./build.sh                             # Standard build with locked dependencies
UPDATE_DEPS=true ./build.sh            # Build with updated dependencies
```

### WP-CLI Commands (when installed in WordPress)

```bash
# Scanning operations
wp 84em integrity scan                 # Run manual scan
wp 84em integrity scan --type=scheduled --format=json
wp 84em integrity results              # List recent scan results
wp 84em integrity results 123          # View specific scan details

# Schedule management
wp 84em integrity schedules list
wp 84em integrity schedules create --name="Daily Scan" --frequency=daily --time=02:00 --active
wp 84em integrity schedules enable --id=1
wp 84em integrity schedules disable --id=1
wp 84em integrity schedules delete --id=1
wp 84em integrity recalculate_schedules  # Recalculate all schedule next run times

# Configuration
wp 84em integrity config list
wp 84em integrity config get notification_email
wp 84em integrity config set notification_email admin@example.com
```

## Architecture Overview

The plugin follows a modern PHP architecture with dependency injection and service layer patterns:

### Core Components

1. **Container System** (`src/Container.php`): Dependency injection container managing service instances
2. **Plugin Bootstrap** (`src/Plugin.php`): Main entry point, service registration, and initialization
3. **Service Layer** (`src/Services/`): Business logic implementations
   - `IntegrityService`: Core scanning and checksum validation logic
   - `SchedulerService`: Action Scheduler integration for background processing
   - `NotificationService`: Email and Slack webhook notifications
   - `SettingsService`: Configuration management
   - `LoggerService`: System logging with levels and retention
   - `FileViewerService`: Secure file viewing with access controls

### Database Architecture

The plugin uses a repository pattern with these **5 tables**:
- `eightyfourem_integrity_scan_results`: Scan metadata and statistics
- `eightyfourem_integrity_file_records`: Individual file checksums, change tracking, and diff content
- `eightyfourem_integrity_scan_schedules`: Multiple scan schedules with configurations
- `eightyfourem_integrity_file_content`: Stores file content for diff generation (keyed by checksum)
- `eightyfourem_integrity_logs`: System activity logging with context and levels

Repositories (`src/Database/`):
- `ScanResultsRepository`: CRUD operations for scan results
- `FileRecordRepository`: File checksum storage and comparison
- `ScanSchedulesRepository`: Schedule management
- `FileContentRepository`: File content storage for diff generation
- `LogRepository`: System log persistence with retention management
- `DatabaseManager`: Schema creation and upgrades

### Scanner System

The scanning system (`src/Scanner/`) processes files in batches:
- `FileScanner`: Discovers and filters files based on configuration
- `ChecksumGenerator`: SHA-256 hash generation with memory-efficient streaming

### Admin Interface

WordPress admin integration (`src/Admin/`):
- `AdminPages`: Settings, schedules, scan results, and logs pages
- `DashboardWidget`: At-a-glance monitoring widget
- **AJAX Endpoints**:
  - `file_integrity_start_scan`: Initiate scan
  - `file_integrity_check_progress`: Monitor scan progress
  - `file_integrity_cleanup_old_scans`: Remove old scan data
  - `file_integrity_cancel_scan`: Stop running scan
  - `file_integrity_delete_scan`: Delete scan result
  - `file_integrity_test_slack`: Test Slack webhook
  - `file_integrity_bulk_delete_scans`: Delete multiple scans
  - `file_integrity_resend_email`: Resend email notification
  - `file_integrity_resend_slack`: Resend Slack notification

### Security Layer

Security implementations (`src/Security/`):
- `FileAccessSecurity`: File access validation, path traversal prevention, and sensitive file protection

### Utility Classes

Helper utilities (`src/Utils/`):
- `Security`: Nonce verification and sanitization helpers
- `DiffGenerator`: Generate file content diffs between versions

## Important Dependencies

- **Action Scheduler**: Required for background processing and scheduled scans. The plugin checks for Action Scheduler availability and won't enable scheduling features without it.
- **Composer Autoloader**: PSR-4 autoloading is required. The plugin will show an error if vendor/autoload.php is missing.

## Testing Approach

The plugin uses PHPUnit for testing with separate unit and integration test suites. Tests are located in `tests/` with bootstrap configuration in `tests/bootstrap.php`. The `phpunit.xml` file configures test execution and coverage reporting.

## Build Process

The `build.sh` script creates production-ready ZIP files:
1. Validates Action Scheduler is in composer.json require section
2. Minifies CSS/JS assets using npm packages (terser, clean-css-cli)
3. Installs production Composer dependencies (excludes dev dependencies)
4. Removes unnecessary files (tests, docs, etc.)
5. Creates optimized autoloader
6. Verifies all required files are included
7. Packages everything into `dist/84em-file-integrity-checker-{version}.zip`

## Development Guidelines

- PHP 8.0+ with type declarations throughout
- Follow WordPress coding standards with modern PHP practices
- Use dependency injection via the Container class
- Repository pattern for all database operations
- Service layer for business logic
- All admin actions require `manage_options` capability
- CSRF protection with nonce verification on all forms
- Prepared statements for all database queries
- File access validation through FileAccessSecurity service

## Additional Features Not in README

- **File Content Storage**: Stores file content for generating diffs between versions
- **File Viewer**: Secure file viewing through admin interface with access controls
- **Comprehensive Logging**: Detailed activity logging with contexts, levels, and user tracking
- **Diff Generation**: Shows actual file changes between scans
- **Progress Tracking**: Real-time scan progress monitoring via AJAX
- **Bulk Operations**: Delete multiple scan results at once
- **Notification Resending**: Ability to resend email/Slack notifications for past scans
- **Schedule Recalculation**: Command to recalculate all schedule next run times