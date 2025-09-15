# Changelog

All notable changes to the 84EM File Integrity Checker plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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