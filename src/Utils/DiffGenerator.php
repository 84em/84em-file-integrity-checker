<?php
/**
 * DiffGenerator - Secure PHP-native diff generation
 * 
 * @package EightyFourEM\FileIntegrityChecker\Utils
 */

namespace EightyFourEM\FileIntegrityChecker\Utils;

/**
 * Generates unified diffs using PHP-native implementation
 * Replaces shell command usage for security
 */
class DiffGenerator {
    
    /**
     * Generate a unified diff between two strings
     *
     * @param string $old_content Old content
     * @param string $new_content New content
     * @param string $file_path File path for context
     * @param int $context_lines Number of context lines to show
     * @return string Unified diff
     */
    public function generateUnifiedDiff( string $old_content, string $new_content, string $file_path = '', int $context_lines = 3 ): string {
        $old_lines = explode( "\n", $old_content );
        $new_lines = explode( "\n", $new_content );
        
        // Calculate the longest common subsequence
        $lcs = $this->longestCommonSubsequence( $old_lines, $new_lines );
        
        // Generate unified diff format
        $diff_output = [];
        
        // Add header if file path provided
        if ( ! empty( $file_path ) ) {
            $diff_output[] = '--- ' . $file_path . ' (previous)';
            $diff_output[] = '+++ ' . $file_path . ' (current)';
        }
        
        // Generate hunks
        $hunks = $this->generateHunks( $old_lines, $new_lines, $lcs, $context_lines );
        
        foreach ( $hunks as $hunk ) {
            $diff_output[] = $this->formatHunkHeader( $hunk );
            $diff_output = array_merge( $diff_output, $hunk['lines'] );
        }
        
        return implode( "\n", $diff_output );
    }
    
    /**
     * Calculate longest common subsequence using dynamic programming
     *
     * @param array $old_lines Old content lines
     * @param array $new_lines New content lines
     * @return array LCS matrix
     */
    private function longestCommonSubsequence( array $old_lines, array $new_lines ): array {
        $m = count( $old_lines );
        $n = count( $new_lines );
        
        // Initialize LCS matrix
        $lcs = array_fill( 0, $m + 1, array_fill( 0, $n + 1, 0 ) );
        
        // Build LCS matrix
        for ( $i = 1; $i <= $m; $i++ ) {
            for ( $j = 1; $j <= $n; $j++ ) {
                if ( $old_lines[$i - 1] === $new_lines[$j - 1] ) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max( $lcs[$i - 1][$j], $lcs[$i][$j - 1] );
                }
            }
        }
        
        return $lcs;
    }
    
    /**
     * Generate diff hunks from LCS
     *
     * @param array $old_lines Old content lines
     * @param array $new_lines New content lines
     * @param array $lcs LCS matrix
     * @param int $context_lines Number of context lines
     * @return array Array of hunks
     */
    private function generateHunks( array $old_lines, array $new_lines, array $lcs, int $context_lines ): array {
        $hunks = [];
        $current_hunk = null;
        
        $i = count( $old_lines );
        $j = count( $new_lines );
        $changes = [];
        
        // Trace back through LCS to find changes
        while ( $i > 0 || $j > 0 ) {
            if ( $i > 0 && $j > 0 && $old_lines[$i - 1] === $new_lines[$j - 1] ) {
                // Lines are equal
                $changes[] = [
                    'type' => 'equal',
                    'old_line' => $i - 1,
                    'new_line' => $j - 1,
                    'content' => $old_lines[$i - 1]
                ];
                $i--;
                $j--;
            } elseif ( $j > 0 && ( $i == 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j] ) ) {
                // Line added
                $changes[] = [
                    'type' => 'add',
                    'new_line' => $j - 1,
                    'content' => $new_lines[$j - 1]
                ];
                $j--;
            } else {
                // Line deleted
                $changes[] = [
                    'type' => 'delete',
                    'old_line' => $i - 1,
                    'content' => $old_lines[$i - 1]
                ];
                $i--;
            }
        }
        
        // Reverse changes to get correct order
        $changes = array_reverse( $changes );
        
        // Group changes into hunks with context
        $current_hunk = null;
        $last_change_index = -1;
        
        foreach ( $changes as $index => $change ) {
            if ( $change['type'] !== 'equal' ) {
                // Found a change
                if ( $current_hunk === null || $index - $last_change_index > $context_lines * 2 ) {
                    // Start new hunk
                    if ( $current_hunk !== null ) {
                        $hunks[] = $this->finalizeHunk( $current_hunk, $changes, $last_change_index, $context_lines );
                    }
                    
                    $current_hunk = [
                        'old_start' => $change['old_line'] ?? $changes[$index - 1]['old_line'] ?? 0,
                        'new_start' => $change['new_line'] ?? $changes[$index - 1]['new_line'] ?? 0,
                        'start_index' => max( 0, $index - $context_lines ),
                        'lines' => []
                    ];
                }
                
                $last_change_index = $index;
            }
        }
        
        // Finalize last hunk
        if ( $current_hunk !== null ) {
            $hunks[] = $this->finalizeHunk( $current_hunk, $changes, $last_change_index, $context_lines );
        }
        
        return $hunks;
    }
    
    /**
     * Finalize a hunk by adding context and formatting lines
     *
     * @param array $hunk Current hunk data
     * @param array $changes All changes
     * @param int $last_change_index Index of last change
     * @param int $context_lines Number of context lines
     * @return array Finalized hunk
     */
    private function finalizeHunk( array $hunk, array $changes, int $last_change_index, int $context_lines ): array {
        $end_index = min( count( $changes ) - 1, $last_change_index + $context_lines );
        
        $old_count = 0;
        $new_count = 0;
        
        for ( $i = $hunk['start_index']; $i <= $end_index; $i++ ) {
            $change = $changes[$i];
            
            switch ( $change['type'] ) {
                case 'equal':
                    $hunk['lines'][] = ' ' . $change['content'];
                    $old_count++;
                    $new_count++;
                    break;
                    
                case 'delete':
                    $hunk['lines'][] = '-' . $change['content'];
                    $old_count++;
                    break;
                    
                case 'add':
                    $hunk['lines'][] = '+' . $change['content'];
                    $new_count++;
                    break;
            }
        }
        
        $hunk['old_count'] = $old_count;
        $hunk['new_count'] = $new_count;
        
        return $hunk;
    }
    
    /**
     * Format hunk header in unified diff format
     *
     * @param array $hunk Hunk data
     * @return string Formatted hunk header
     */
    private function formatHunkHeader( array $hunk ): string {
        return sprintf(
            '@@ -%d,%d +%d,%d @@',
            $hunk['old_start'] + 1,
            $hunk['old_count'],
            $hunk['new_start'] + 1,
            $hunk['new_count']
        );
    }
    
    /**
     * Generate a simple line-by-line diff (fallback method)
     *
     * @param string $old_content Old content
     * @param string $new_content New content
     * @return string Simple diff
     */
    public function simpleLineDiff( string $old_content, string $new_content ): string {
        $old_lines = explode( "\n", $old_content );
        $new_lines = explode( "\n", $new_content );
        
        $diff = [];
        $max_lines = max( count( $old_lines ), count( $new_lines ) );
        
        for ( $i = 0; $i < $max_lines; $i++ ) {
            $old_line = $old_lines[$i] ?? null;
            $new_line = $new_lines[$i] ?? null;
            
            if ( $old_line === $new_line ) {
                // Unchanged line - show limited context
                if ( $i < 3 || $i >= $max_lines - 3 ) {
                    $diff[] = ' ' . $old_line;
                } elseif ( count( $diff ) > 0 && $diff[count( $diff ) - 1] !== '...' ) {
                    $diff[] = '...';
                }
            } elseif ( $old_line === null ) {
                // Added line
                $diff[] = '+' . $new_line;
            } elseif ( $new_line === null ) {
                // Removed line
                $diff[] = '-' . $old_line;
            } else {
                // Changed line
                $diff[] = '-' . $old_line;
                $diff[] = '+' . $new_line;
            }
        }
        
        return implode( "\n", $diff );
    }
}