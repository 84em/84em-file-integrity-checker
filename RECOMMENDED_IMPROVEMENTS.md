# Recommended Improvements for Diff Creation and Storage

## Current Implementation Analysis

### Strengths
1. **Deduplication via checksums** - File content is stored by checksum, preventing duplicate storage
2. **Compression** - Using `gzcompress()` at level 9 achieving ~70-80% space savings
3. **Size limits** - Only stores text files under 1MB
4. **Selective storage** - Only stores common text file types (php, js, css, html, etc.)
5. **Retention management** - Automatic cleanup of old content based on configured limits

### Identified Inefficiencies

1. **Redundant Diff Storage**: Diffs are stored as `LONGTEXT` in `file_records.diff_content` column, which can be very large for significant changes. Since full file content is already stored, this essentially stores data twice.

2. **LCS Algorithm Memory Usage**: The `longestCommonSubsequence()` method creates a 2D array of size m×n where m and n are line counts. For a 1000-line file, this uses ~4MB of memory just for the matrix.

3. **Diff Generation Timing**: Diffs are generated during scanning, which slows down the scan process. This could be deferred to when actually needed.

4. **No Diff Caching**: Diffs are regenerated each time they're viewed if not stored in the database.

5. **Storing Unchanged Files**: Content is stored for all scanned files, even when they haven't changed, leading to unnecessary storage usage.

## Recommended Improvements

### 1. Remove diff_content Column (High Priority)
- Generate diffs on-demand instead of storing them
- Saves significant database space
- Since both versions are available via checksums, diffs can be reconstructed
- Consider memory/Redis caching for frequently accessed diffs

### 2. Use Myers' Diff Algorithm (High Priority)
- Replace LCS with Myers' diff algorithm
- O(ND) complexity vs O(mn) for LCS
- Much lower memory usage
- Used by git and other efficient diff tools
- Better performance for similar files

### 3. Store Only Changed Files' Content (High Priority)
- Only store content when files are new or modified
- Skip storing content for unchanged files
- Dramatically reduces storage requirements
- Maintains full diff capability for changed files

### 4. Implement Chunked Diff Generation (Medium Priority)
For large files:
- Process files in chunks to reduce memory usage
- Stream processing instead of loading entire files into memory
- Set reasonable chunk sizes (e.g., 1000 lines per chunk)

### 5. Add Diff Size Limits (Medium Priority)
- Skip diff generation for files over certain size threshold
- Truncate very large diffs with a note indicating truncation
- Provide option to download full diff if needed

### 6. Consider External Diff Storage (Low Priority)
- For frequently accessed diffs, use object storage (S3/filesystem)
- Keep database lean
- Implement with proper caching strategy

### 7. Optimize Content Retrieval (Low Priority)
- Implement batch content retrieval for multiple files
- Add content prefetching for likely-to-be-viewed files
- Consider lazy loading strategies in the UI

### 8. Add Diff Caching Layer (Low Priority)
- Implement memory cache for recently generated diffs
- Use WordPress transients or object cache if available
- Set appropriate TTL based on usage patterns

## Implementation Priority

**Phase 1 (Immediate):**
- Remove diff_content column from database
- Implement on-demand diff generation
- Use Myers' algorithm for better performance
- Store content only for changed files

**Phase 2 (Near-term):**
- Add chunked processing for large files
- Implement diff size limits and truncation
- Add basic caching for generated diffs

**Phase 3 (Long-term):**
- External storage integration
- Advanced caching strategies
- Performance monitoring and optimization

## Expected Benefits

1. **Storage Reduction**: 60-80% reduction in database size
2. **Performance**: Faster scan times without inline diff generation
3. **Scalability**: Better handling of large codebases
4. **Memory Efficiency**: Lower memory footprint with Myers' algorithm
5. **Flexibility**: Easier to add new diff formats or visualization options

## Migration Considerations

- Existing diff_content data can be dropped after verifying content storage
- No data loss since diffs can be regenerated from stored content
- Consider running cleanup to remove content for unchanged files
- Update any existing reports or exports that rely on stored diffs