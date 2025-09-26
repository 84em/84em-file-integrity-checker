# Priority File Monitoring Feature Plan

## Executive Summary

This feature will allow users to designate critical files and directories for priority monitoring, ensuring that changes to sensitive files (like `wp-config.php`, `.htaccess`, or core WordPress files) are highlighted prominently in scan results and notifications, even when other legitimate changes occur during plugin/theme updates.

## Problem Statement

Currently, all file changes are reported with equal weight in notifications and scan results. When users perform legitimate updates (plugins, themes, WordPress core), the large number of expected changes can obscure critical security-relevant changes to sensitive files. For example:
- A hacked `wp-config.php` might be overlooked among hundreds of plugin update changes
- Unauthorized modifications to `.htaccess` could be buried in scan results
- Backdoors added to rarely-changed files might not receive appropriate attention

## Solution Overview

Implement a **Priority File Monitoring System** that allows users to:
1. Mark specific files and directories as high-priority
2. Set monitoring levels (Critical, High, Normal)
3. Receive enhanced notifications for priority file changes
4. View priority changes separately in scan results

## Feature Components

### 1. Priority Levels

#### **Critical Priority** (Red Alert)
- **Use Cases**: Core security files that should rarely/never change
- **Examples**: `wp-config.php`, `.htaccess`, `wp-settings.php`
- **Behavior**:
  - Immediate separate notification
  - Top placement in all reports
  - Red highlighting in UI
  - Separate email/Slack alert even if no other changes

#### **High Priority** (Orange Alert)
- **Use Cases**: Important files that change infrequently
- **Examples**: Core WordPress files, mu-plugins, critical theme functions
- **Behavior**:
  - Prominent placement in notifications
  - Orange highlighting in UI
  - Grouped at top of change reports

#### **Normal Priority** (Default)
- **Use Cases**: Regular files (plugins, themes, uploads)
- **Behavior**: Current default behavior

### 2. Database Schema Changes

#### New Table: `eightyfourem_integrity_priority_rules`
```sql
CREATE TABLE {prefix}eightyfourem_integrity_priority_rules (
    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    path varchar(500) NOT NULL,
    path_type enum('file', 'directory', 'pattern') NOT NULL DEFAULT 'file',
    priority_level enum('critical', 'high', 'normal') NOT NULL DEFAULT 'high',
    match_type enum('exact', 'prefix', 'regex') NOT NULL DEFAULT 'exact',
    reason text DEFAULT NULL,
    notify_immediately tinyint(1) NOT NULL DEFAULT 0,
    ignore_in_bulk_changes tinyint(1) NOT NULL DEFAULT 0,
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by bigint(20) UNSIGNED DEFAULT NULL,
    updated_at datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_path_type (path_type),
    KEY idx_priority (priority_level),
    KEY idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Modifications to existing tables:
- **eightyfourem_integrity_file_records**: Add `priority_level` column
- **eightyfourem_integrity_scan_results**: Add JSON columns for priority statistics

### 3. User Interface Components

#### Settings Page - Priority Rules Tab
```
[Priority File Monitoring]
├── Add Priority Rule
│   ├── Path/Pattern: [_______________] [Browse]
│   ├── Type: ( ) File (•) Directory ( ) Pattern
│   ├── Priority: [Critical ▼]
│   ├── Match Type: [Exact ▼]
│   ├── Reason/Note: [_______________]
│   ├── [ ] Send immediate notification
│   ├── [ ] Highlight even during bulk updates
│   └── [Add Rule]
│
├── Default Priority Rules (Recommended)
│   ├── [ ] Enable WordPress Core Protection (wp-config.php, .htaccess, etc.)
│   ├── [ ] Enable Admin Directory Protection (/wp-admin/*)
│   ├── [ ] Enable MU-Plugins Protection
│   └── [Apply Defaults]
│
└── Current Priority Rules
    ├── [Table with rules, edit/delete actions]
    └── [Bulk Actions: Enable/Disable/Delete]
```

#### Scan Results Enhancement
```
Scan Results - Priority Changes Detected!
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️ CRITICAL CHANGES (1)
├── /wp-config.php - MODIFIED [View Diff]
│   Last Changed: 2025-09-26 10:30:15
│   Reason: Core configuration file
│
⚠️ HIGH PRIORITY CHANGES (3)
├── /.htaccess - MODIFIED
├── /wp-admin/admin-ajax.php - MODIFIED
└── /wp-includes/functions.php - NEW FILE

📋 NORMAL CHANGES (247)
└── [Show All] [Collapse]
```

### 4. Notification Enhancements

#### Email Template Structure
```
Subject: [CRITICAL] Security File Changes Detected - example.com

CRITICAL SECURITY ALERT
━━━━━━━━━━━━━━━━━━━━━━
The following critical files have been modified:

• wp-config.php - Configuration file modified
  Last changed: 2025-09-26 10:30:15
  [View Full Report]

HIGH PRIORITY CHANGES
━━━━━━━━━━━━━━━━━━━━
3 high-priority files changed
[View Details]

OTHER CHANGES
━━━━━━━━━━━━━
247 files changed (likely from plugin updates)
[View Full List]
```

#### Slack Notification Format
```json
{
  "attachments": [
    {
      "color": "danger",
      "title": "🚨 CRITICAL: Security Files Modified",
      "fields": [
        {
          "title": "Critical Changes",
          "value": "• wp-config.php (modified)\n• .htaccess (modified)",
          "short": false
        }
      ],
      "footer": "84EM File Integrity Checker",
      "ts": 1234567890
    }
  ]
}
```

### 5. Implementation Classes

#### New Classes to Create

1. **PriorityRulesRepository** (`src/Database/`)
   - CRUD operations for priority rules
   - Pattern matching logic
   - Bulk rule management

2. **PriorityMonitoringService** (`src/Services/`)
   - Evaluate file paths against rules
   - Calculate priority levels
   - Generate priority-aware reports

3. **PriorityRulesMatcher** (`src/Utils/`)
   - Path matching algorithms
   - Pattern compilation and caching
   - Performance optimization for large rule sets

4. **PriorityNotificationFormatter** (`src/Utils/`)
   - Format priority-aware notifications
   - Generate sectioned reports
   - Create urgency indicators

#### Modified Classes

1. **IntegrityService**
   - Integrate priority checking during scans
   - Separate priority statistics collection
   - Enhanced reporting methods

2. **NotificationService**
   - Priority-aware notification logic
   - Immediate notification triggers
   - Sectioned notification content

3. **AdminPages**
   - New priority rules management UI
   - Enhanced scan results display
   - Priority filtering options

4. **FileRecordRepository**
   - Store priority levels with file records
   - Priority-based queries
   - Statistics by priority level

## Default Priority Rules

### Recommended WordPress Core Files (Critical)
```php
[
    '/wp-config.php' => 'WordPress configuration file',
    '/.htaccess' => 'Server configuration file',
    '/index.php' => 'WordPress bootstrap file',
    '/wp-settings.php' => 'WordPress settings loader',
    '/.user.ini' => 'PHP configuration file',
    '/php.ini' => 'PHP configuration file',
]
```

### Recommended High Priority Patterns
```php
[
    '/wp-admin/*.php' => 'WordPress admin files',
    '/wp-includes/*.php' => 'WordPress core includes',
    '/wp-content/mu-plugins/*.php' => 'Must-use plugins',
    '*/wp-load.php' => 'WordPress loader',
    '*/wp-blog-header.php' => 'WordPress blog header',
]
```

## WP-CLI Commands

```bash
# Manage priority rules
wp 84em integrity priority add --path="/wp-config.php" --level=critical
wp 84em integrity priority list
wp 84em integrity priority remove --id=1
wp 84em integrity priority import --file=rules.json
wp 84em integrity priority export --file=rules.json

# Apply default rules
wp 84em integrity priority apply-defaults
wp 84em integrity priority reset

# Test rules
wp 84em integrity priority test --path="/some/file.php"
wp 84em integrity priority stats
```

## Technical Considerations

### Performance Impact
- **Rule Evaluation**: Use compiled patterns and caching
- **Database Queries**: Add appropriate indexes
- **Notification Generation**: Process priority items first
- **Memory Usage**: Stream processing for large rule sets

### Security Considerations
- **Path Validation**: Prevent directory traversal in rules
- **Permission Checks**: Only admins can manage rules
- **Rule Sanitization**: Validate regex patterns
- **Audit Logging**: Track rule changes

### Backward Compatibility
- Feature is opt-in (no rules by default)
- Existing scans continue to work normally
- Database migrations are non-destructive
- Settings preserved during updates

## Implementation Phases

### Phase 1: Core Infrastructure (Week 1)
- [ ] Create database schema
- [ ] Implement PriorityRulesRepository
- [ ] Build PriorityMonitoringService
- [ ] Add priority level to file records

### Phase 2: Rule Management (Week 1-2)
- [ ] Admin UI for rule management
- [ ] Default rules system
- [ ] WP-CLI commands
- [ ] Import/export functionality

### Phase 3: Scanning Integration (Week 2)
- [ ] Integrate with IntegrityService
- [ ] Update FileScanner for priority detection
- [ ] Modify scan results storage
- [ ] Add priority statistics

### Phase 4: Notification System (Week 3)
- [ ] Enhanced email templates
- [ ] Slack notification formatting
- [ ] Immediate notification triggers
- [ ] Priority-based alert levels

### Phase 5: UI Enhancements (Week 3-4)
- [ ] Scan results priority display
- [ ] Dashboard widget updates
- [ ] Priority filtering/sorting
- [ ] Visual indicators (colors, icons)

### Phase 6: Testing & Documentation (Week 4)
- [ ] Unit tests for new components
- [ ] Integration tests
- [ ] Performance testing
- [ ] User documentation
- [ ] Update README and CHANGELOG

## Success Metrics

1. **Security Impact**
   - Reduction in missed critical file changes
   - Faster detection of security breaches
   - Improved admin response time

2. **User Experience**
   - Clear separation of critical vs normal changes
   - Reduced notification fatigue
   - Easier identification of threats

3. **Performance**
   - Rule evaluation < 0.1ms per file
   - No significant scan time increase (<5%)
   - Efficient notification generation

## Alternative Approaches Considered

1. **Immutability Flags**: Mark files as "should never change"
   - Pros: Simpler concept
   - Cons: Too rigid for real-world use

2. **Machine Learning Detection**: Auto-detect unusual changes
   - Pros: No configuration needed
   - Cons: Complex, requires training data

3. **Time-based Priority**: Recent changes = higher priority
   - Pros: Automatic
   - Cons: Doesn't address the core problem

## Open Questions

1. Should priority rules support wildcards or only regex?
2. Should we auto-detect WordPress core version and adjust rules?
3. How many priority levels are optimal? (Current proposal: 3)
4. Should priority rules be exportable/shareable between sites?
5. Should we integrate with security plugins for rule suggestions?

## Conclusion

This Priority File Monitoring feature addresses a critical gap in the current file integrity checker by ensuring security-relevant changes are never overlooked. The implementation is designed to be flexible, performant, and user-friendly while maintaining backward compatibility.

The phased approach allows for iterative development and testing, with each phase delivering functional value. The feature will significantly enhance the plugin's ability to detect and alert on security threats while reducing alert fatigue from routine updates.