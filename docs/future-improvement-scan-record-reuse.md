# Future Improvement: Scan Record Reuse for Background Processing

## Issue Description

When implementing background scan processing via Action Scheduler, the current implementation creates a "queued" scan record, then creates a new scan record when the scan actually runs, and finally deletes the queued record. This approach, while functional, involves unnecessary database operations.

**Current Flow:**
1. AJAX handler creates scan record with status='queued'
2. Action Scheduler triggers background job
3. SchedulerService::executeScan() runs IntegrityService::runScan()
4. IntegrityService creates a NEW scan record
5. Original queued record is deleted

## Identified by

GitHub Copilot code review on PR #8 (2025-09-26)

## Current Implementation

```php
// src/Services/SchedulerService.php (lines 500-514)
if ( $scan_id && $queued ) {
    // The scan record already exists with status 'queued'
    // Just run the normal scan which will create its own scan record
    // We'll delete the queued one to avoid duplicates
    $scan_result = $this->integrityService->runScan( $scan_type, null, $schedule_id );

    // Delete the queued scan record since we created a new one
    if ( $scan_result && isset( $scan_result['scan_id'] ) ) {
        $this->scanResultsRepository->delete( $scan_id );
    }
}
```

## Proposed Improvement

Modify the system to reuse the existing queued scan record instead of creating a new one:

### Option 1: Add runScanWithExistingId() Method

Create a new method in IntegrityService that accepts an existing scan ID:

```php
// src/Services/IntegrityService.php
public function runScanWithExistingId(
    int $scan_id,
    string $scan_type = 'manual',
    ?callable $progress_callback = null,
    ?int $schedule_id = null
): array|false {
    $start_time = time();
    $start_memory = memory_get_usage();

    // Update existing record instead of creating new one
    $this->scanResultsRepository->update( $scan_id, [
        'status' => 'running',
        'scan_date' => current_time( 'mysql' ),
        'notes' => "Scan started at " . current_time( 'mysql' ),
    ] );

    // Continue with normal scan logic...
    // (rest of the scanning code)
}
```

### Option 2: Modify runScan() to Accept Optional Scan ID

Enhance the existing runScan() method to optionally accept a scan ID:

```php
// src/Services/IntegrityService.php
public function runScan(
    string $scan_type = 'manual',
    ?callable $progress_callback = null,
    ?int $schedule_id = null,
    ?int $existing_scan_id = null  // New parameter
): array|false {
    $start_time = time();
    $start_memory = memory_get_usage();

    if ( $existing_scan_id ) {
        // Update existing record
        $scan_id = $existing_scan_id;
        $this->scanResultsRepository->update( $scan_id, [
            'status' => 'running',
            'scan_date' => current_time( 'mysql' ),
            'notes' => "Scan started at " . current_time( 'mysql' ),
        ] );
    } else {
        // Create new record (current behavior)
        $scan_data = [
            'scan_date' => current_time( 'mysql' ),
            'status' => 'running',
            'scan_type' => $scan_type,
            'notes' => "Scan started at " . current_time( 'mysql' ),
        ];

        if ( $schedule_id !== null ) {
            $scan_data['schedule_id'] = $schedule_id;
        }

        $scan_id = $this->scanResultsRepository->create( $scan_data );
    }

    // Continue with normal scan logic...
}
```

### Option 3: Status-Based Approach

Keep the architecture cleaner by having SchedulerService handle all status updates:

```php
// src/Services/SchedulerService.php
if ( $scan_id && $queued ) {
    // Update status from 'queued' to 'running'
    $this->scanResultsRepository->update( $scan_id, [
        'status' => 'running',
        'notes' => 'Scan started at ' . current_time( 'mysql' ),
    ] );

    // Run scan operations without creating a new record
    $scan_operations = $this->integrityService->performScanOperations();

    // Update the existing record with results
    $this->scanResultsRepository->update( $scan_id, [
        'status' => $scan_operations['success'] ? 'completed' : 'failed',
        'total_files' => $scan_operations['total_files'],
        'changed_files' => $scan_operations['changed_files'],
        // ... other fields
    ] );

    $scan_result = array_merge(['scan_id' => $scan_id], $scan_operations);
}
```

## Benefits

1. **Fewer Database Operations**: Eliminates unnecessary DELETE operation
2. **Better Data Integrity**: Maintains single record throughout scan lifecycle
3. **Improved Traceability**: Can track scan from queue to completion
4. **Cleaner Architecture**: More logical flow of scan states

## Considerations

1. **Backward Compatibility**: Need to ensure existing code paths still work
2. **Error Handling**: Must handle cases where queued record might be missing
3. **Testing Requirements**: Will need comprehensive tests for all scan initiation paths
4. **Migration Path**: Consider how to handle existing queued records during upgrade

## Implementation Complexity

- **Estimated Effort**: 4-6 hours
- **Risk Level**: Medium (modifies core scanning logic)
- **Testing Required**: Unit tests, integration tests, manual testing

## Decision Criteria

Implement this improvement when:
1. Performance optimization becomes a priority
2. Doing a major version update with other IntegrityService changes
3. Adding more complex scan state management features
4. Database operation count becomes a bottleneck

## Related Files

- `src/Services/IntegrityService.php`
- `src/Services/SchedulerService.php`
- `src/Admin/AdminPages.php` (AJAX handler)
- `src/Database/ScanResultsRepository.php`
- Tests: `tests/Unit/Services/IntegrityServiceTest.php`

## Notes

While this improvement would make the code more elegant and efficient, the current implementation is functional and the performance impact is minimal (one extra INSERT and one DELETE per background scan). This optimization should be bundled with other IntegrityService enhancements for maximum benefit.