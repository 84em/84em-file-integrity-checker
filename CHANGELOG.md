# Changelog

All notable changes to the 84EM File Integrity Checker plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.2] - 2025-09-16

### Changed
- Eliminated all hardcoded configurations in FileScanner to respect user settings
- File type extensions for scanning now exclusively use configured settings instead of hardcoded arrays
- Exclude patterns now exclusively use configured settings, removed hardcoded WordPress exclusions
- Maximum file size limits now consistently use configured settings throughout the scanner
- Settings UI is now the single source of truth for all scanner configurations

### Improved
- Refactored text file detection to use configured scan types
- Simplified file exclusion logic to use only user-configured patterns
- Consolidated all file size checks to use the configured maximum file size setting
- Removed redundant MIME type checking for better performance
- Enhanced PHP code detection in non-PHP files with focused security checks

### Fixed
- Fixed inconsistency where hardcoded text extensions didn't match configured scan types
- Fixed issue where hardcoded exclusion patterns overrode user settings
- Fixed multiple hardcoded 1MB file size limits that ignored user configuration
- Updated default exclude patterns in SettingsService to include commonly excluded directories
- Corrected maximum file size validation to properly enforce 1MB limit

### Security
- Improved containsPhpCodeInNonPhpFile() method to detect PHP code in text files
- Added comprehensive PHP backdoor pattern detection
- Maintained security checks while removing unnecessary MIME type validation

## [2.0.1] - 2025-09-15

### Fixed
- Fixed bug where resending email notifications would also trigger Slack notifications
- Added separate methods for resending individual notification channels (email/Slack)

## [2.0.0] - 2025-09-15

### Changed
- Major architecture refactor for improved performance and maintainability
- Refactored FileScanner to generate diffs at scan time for better efficiency
- Simplified database schema with removal of backward compatibility code
- Updated build script for improved dependency management
- Enhanced uninstall.php to use DatabaseManager and handle all tables properly

### Added
- ChecksumCacheRepository for improved diff storage and performance
- ScheduledCacheCleanup for automatic cache management
- Improved database schema for more efficient diff storage

### Removed
- File viewer functionality from UI for security and simplification
- FileViewerService class (functionality integrated elsewhere)
- FileContentRepository class (replaced with improved caching system)
- SecurityHeaders class (no longer needed)
- Legacy hardening methods
- Backward compatibility code from database schema

### Fixed
- Whitespace issues in various files
- FileAccessSecurity comments to reflect current architecture

## [1.0.0] - 2025-09-06
- Initial release