<?php
/**
 * Plugin Name: Featured Image Dominant Colors
 * Description: Detects the 2 dominant colors of a featured image and saves them to ACF fields.
 * Version: 1.1
 */

// ─── CONFIG ──────────────────────────────────────────────────────────────────
// Change these to match your exact ACF field keys
define( 'DIFC_PRIMARY_FIELD',   'custom_colour' );
define( 'DIFC_SECONDARY_FIELD', 'custom_colour_secondary' );

// Post type to process (only events have these ACF fields)
define( 'DIFC_POST_TYPE', 'event' );

// How many pixels to sample (higher = more accurate but slower; 1000 is a safe sweet spot)
define( 'DIFC_SAMPLE_SIZE', 1000 );

// Colour similarity threshold for clustering (0-255; 30 works well)
define( 'DIFC_CLUSTER_THRESHOLD', 30 );

// Salience configuration
define( 'DIFC_MIN_SATURATION', 0.15 );      // Minimum saturation (0-1) to consider a color salient
define( 'DIFC_CENTER_WEIGHT', 2.0 );        // Multiplier for center region pixels (2x more important)
define( 'DIFC_SATURATION_WEIGHT', 1.5 );    // How much to boost highly saturated colors
define( 'DIFC_LUMINANCE_PREFERENCE', 0.5 );  // Preferred luminance (0-1, 0.5 = middle gray)

// Enable debug logging (set to true for troubleshooting)

// Contrast configuration for secondary color
define( 'DIFC_MIN_HUE_DIFFERENCE', 0.15 );   // Minimum hue difference (0-0.5) for good contrast (0.15 = ~54°)
define( 'DIFC_MIN_LIGHTNESS_DIFF', 0.25 );   // Minimum lightness difference if hue contrast insufficient
define( 'DIFC_MIN_SATURATION_DIFF', 0.3 );   // Minimum saturation difference if hue contrast insufficient
define( 'DIFC_CONTRAST_WEIGHT', 2.0 );       // How much to boost contrast when selecting secondary color

define( 'DIFC_SATURATION_BOOST', 6.0 );      // Increased to strongly prefer vibrant colors
define( 'DIFC_LEGIBILITY_WEIGHT', 2.5 );     // How much to weight legibility (lightness contrast) for text/background use
define( 'DIFC_MIN_CONTRAST_RATIO', 4.5 );    // Minimum WCAG contrast ratio (4.5:1 = AA standard) - below this, use white/black fallback
define( 'DIFC_CHROMA_CONTRAST_WEIGHT', 4.0 );  // How much to boost excellent chroma contrast (saturation + hue difference)
define( 'DIFC_DEBUG', false );
// ─────────────────────────────────────────────────────────────────────────────


// Hook into multiple events to catch thumbnail changes
add_action( 'updated_post_meta', 'difc_on_thumbnail_change', 5, 4 ); // Higher priority
add_action( 'added_post_meta',   'difc_on_thumbnail_change', 5, 4 ); // Higher priority
add_action( 'set_post_thumbnail', 'difc_on_set_thumbnail', 5, 3 ); // Higher priority
add_action( 'delete_post_thumbnail', 'difc_on_delete_thumbnail', 5, 1 ); // Track thumbnail removal
add_action( 'save_post', 'difc_on_save_post', 20, 2 ); // Priority 20 to run after ACF processes

// Also hook into attachment updates in case image is replaced
add_action( 'edit_attachment', 'difc_on_attachment_edit', 10, 1 );

function difc_log( $message ) {
    if ( DIFC_DEBUG && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[DIFC] ' . $message );
    }
}

/**
 * Clear all caches related to a post to ensure fresh ACF field data
 * 
 * @param int $post_id Post ID
 */
function difc_clear_post_cache( $post_id ) {
    // Clear WordPress post cache
    clean_post_cache( $post_id );
    
    // Clear object cache for this post
    wp_cache_delete( $post_id, 'posts' );
    wp_cache_delete( $post_id, 'post_meta' );
    
    // Clear ACF cache if function exists (ACF 5.7+)
    if ( function_exists( 'acf_get_store' ) ) {
        try {
            $store = acf_get_store( 'values' );
            if ( $store ) {
                $store->remove( $post_id );
            }
        } catch ( Exception $e ) {
            // ACF store might not be available, continue silently
        }
    }
    
    difc_log( "Cleared cache for post {$post_id}" );
}

function difc_on_thumbnail_change( $meta_id, $post_id, $meta_key, $meta_value ) {
    try {
        if ( '_thumbnail_id' !== $meta_key ) {
            return;
        }
        // This hook only fires when thumbnail meta changes, so always force update
        difc_log( "Thumbnail meta changed for post {$post_id}, attachment: {$meta_value}" );
        difc_extract_and_save( $post_id, (int) $meta_value, true ); // Force update when thumbnail changes
    } catch ( Exception $e ) {
        difc_log( "Error in difc_on_thumbnail_change: " . $e->getMessage() );
    } catch ( Error $e ) {
        difc_log( "Fatal error in difc_on_thumbnail_change: " . $e->getMessage() );
    }
}

function difc_on_delete_thumbnail( $post_id ) {
    difc_log( "Thumbnail deleted for post {$post_id}" );
    // Clear the previous thumbnail tracking
    delete_post_meta( $post_id, '_previous_thumbnail_id' );
    // Clear color fields when thumbnail is removed
    if ( function_exists( 'update_field' ) ) {
        update_field( DIFC_PRIMARY_FIELD, '', $post_id );
        update_field( DIFC_SECONDARY_FIELD, '', $post_id );
    }
}

function difc_on_set_thumbnail( $post_id, $thumbnail_id, $prev_thumbnail_id ) {
    try {
        difc_log( "Thumbnail set for post {$post_id}, attachment: {$thumbnail_id} (previous: {$prev_thumbnail_id})" );
        // Store previous thumbnail for comparison in save_post hook
        if ( $prev_thumbnail_id ) {
            update_post_meta( $post_id, '_previous_thumbnail_id', $prev_thumbnail_id );
        }
        difc_extract_and_save( $post_id, (int) $thumbnail_id, true ); // Force update when thumbnail changes
    } catch ( Exception $e ) {
        difc_log( "Error in difc_on_set_thumbnail: " . $e->getMessage() );
    } catch ( Error $e ) {
        difc_log( "Fatal error in difc_on_set_thumbnail: " . $e->getMessage() );
    }
}

function difc_on_save_post( $post_id, $post ) {
    try {
        // Only process event posts
        if ( ! $post || get_post_type( $post_id ) !== DIFC_POST_TYPE ) {
            return;
        }
        
        // Skip autosaves and revisions
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }
        
        // Check if post has a featured image
        $thumbnail_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumbnail_id ) {
            return;
        }
        
        // Check if thumbnail changed by comparing with previous value
        // If thumbnail changed, we should force update colors even if manual colors exist
        $previous_thumbnail = get_post_meta( $post_id, '_previous_thumbnail_id', true );
        $thumbnail_changed = ( $previous_thumbnail != $thumbnail_id );
        
        // Update the previous thumbnail ID for next comparison
        update_post_meta( $post_id, '_previous_thumbnail_id', $thumbnail_id );
        
        // If thumbnail changed, force update (new image = new colors)
        if ( $thumbnail_changed ) {
            difc_log( "Post {$post_id} thumbnail changed from {$previous_thumbnail} to {$thumbnail_id}, forcing color update" );
            difc_extract_and_save( $post_id, $thumbnail_id, true ); // true = force update
            return;
        }
        
        // Check if manual colors were recently set (prevents processing when user manually sets colors)
        $manual_color_flag = get_post_meta( $post_id, '_manual_color_set', true );
        if ( $manual_color_flag ) {
            // If flag was set within last 5 minutes, skip processing to prevent memory issues
            if ( ( time() - $manual_color_flag ) < 300 ) {
                difc_log( "Post {$post_id} has recent manual color flag, skipping auto-discovery to prevent memory issues" );
                return;
            }
        }
        
        // If thumbnail didn't change, check if manual colors are already set
        if ( function_exists( 'get_field' ) ) {
            $existing_primary = get_field( DIFC_PRIMARY_FIELD, $post_id );
            $existing_secondary = get_field( DIFC_SECONDARY_FIELD, $post_id );
            
            // If both colors are set, skip auto-discovery (user has manually set colors)
            if ( ! empty( $existing_primary ) && ! empty( $existing_secondary ) ) {
                difc_log( "Post {$post_id} has manual colors set, skipping auto-discovery" );
                return;
            }
        }
        
        // Thumbnail didn't change and colors not fully set, proceed with update
        difc_log( "Post {$post_id} saved with thumbnail {$thumbnail_id}" );
        difc_extract_and_save( $post_id, $thumbnail_id, false ); // false = respect existing colors
    } catch ( Exception $e ) {
        difc_log( "Error in difc_on_save_post: " . $e->getMessage() );
    } catch ( Error $e ) {
        difc_log( "Fatal error in difc_on_save_post: " . $e->getMessage() );
    }
}

function difc_on_attachment_edit( $attachment_id ) {
    // When an attachment is edited, check if it's used as a featured image for any event
    $posts = get_posts( [
        'post_type'      => DIFC_POST_TYPE,
        'posts_per_page' => -1,
        'meta_key'       => '_thumbnail_id',
        'meta_value'     => $attachment_id,
        'fields'         => 'ids',
    ] );
    
    foreach ( $posts as $post_id ) {
        difc_log( "Attachment {$attachment_id} edited, updating post {$post_id}" );
        difc_extract_and_save( $post_id, $attachment_id, true ); // Force update when attachment is edited
    }
}

function difc_extract_and_save( $post_id, $attachment_id, $force_update = true ) {
    // Wrap everything in try-catch to prevent JSON errors in admin
    try {
        // Verify post type
        $post_type = get_post_type( $post_id );
        if ( $post_type !== DIFC_POST_TYPE ) {
            difc_log( "Skipping post {$post_id} - wrong post type: {$post_type}" );
            return false;
        }
        
        // Verify ACF is available
        if ( ! function_exists( 'update_field' ) ) {
            difc_log( "ACF not available for post {$post_id}" );
            return false;
        }
        
        // Check if manual colors were recently set (prevents processing when user manually sets colors)
        $manual_color_flag = get_post_meta( $post_id, '_manual_color_set', true );
        if ( $manual_color_flag && ! $force_update ) {
            // If flag was set within last 5 minutes, skip processing to prevent memory issues
            if ( ( time() - $manual_color_flag ) < 300 ) {
                difc_log( "Post {$post_id} has recent manual color flag, skipping auto-discovery to prevent memory issues" );
                return false;
            }
        }
        
        // If not forcing update, check if manual colors are already set
        if ( ! $force_update && function_exists( 'get_field' ) ) {
            $existing_primary = get_field( DIFC_PRIMARY_FIELD, $post_id );
            $existing_secondary = get_field( DIFC_SECONDARY_FIELD, $post_id );
            
            // If both colors are set, skip auto-discovery (user has manually set colors)
            if ( ! empty( $existing_primary ) && ! empty( $existing_secondary ) ) {
                difc_log( "Post {$post_id} has manual colors set, skipping auto-discovery" );
                return false;
            }
        }
        
        // Get the path to the image file on disk
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            difc_log( "Image file not found for attachment {$attachment_id}: " . ( $path ?: 'no path' ) );
            return false;
        }
        
        // Check file size to prevent processing huge images that might timeout
        $file_size = @filesize( $path );
        if ( $file_size && $file_size > 50 * 1024 * 1024 ) { // 50MB limit
            difc_log( "Image file too large ({$file_size} bytes) for attachment {$attachment_id}, skipping" );
            return false;
        }
        
        // Increase memory limit temporarily for large image processing
        $current_memory_limit = ini_get( 'memory_limit' );
        $memory_limit_bytes = wp_convert_hr_to_bytes( $current_memory_limit );
        if ( $memory_limit_bytes < 256 * 1024 * 1024 ) { // Less than 256MB
            @ini_set( 'memory_limit', '256M' );
            difc_log( "Temporarily increased memory limit from {$current_memory_limit} to 256M for image processing" );
        }
        
        difc_log( "Processing image: {$path}" );
        
        // Optional: Convert to sRGB if ImageMagick is available (graceful fallback if not)
        $processed_path = difc_convert_to_srgb( $path );
        $is_temp_file = ( $processed_path !== $path );
        
        if ( $is_temp_file ) {
            difc_log( "Using ImageMagick-converted image for color extraction" );
        } else {
            difc_log( "Using original image for color extraction (GD only)" );
        }
        
        // Wrap color extraction in error handling
        $colors = null;
        try {
            $colors = difc_get_dominant_colors( $processed_path );
        } catch ( Exception $e ) {
            difc_log( "Error extracting colors from attachment {$attachment_id}: " . $e->getMessage() );
            // Clean up temp file if created
            if ( $is_temp_file && file_exists( $processed_path ) ) {
                @unlink( $processed_path );
            }
            return false;
        } catch ( Error $e ) {
            difc_log( "Fatal error extracting colors from attachment {$attachment_id}: " . $e->getMessage() );
            // Clean up temp file if created
            if ( $is_temp_file && file_exists( $processed_path ) ) {
                @unlink( $processed_path );
            }
            return false;
        }
        
        // Clean up temporary file if ImageMagick conversion was used
        if ( $is_temp_file && file_exists( $processed_path ) ) {
            @unlink( $processed_path );
            difc_log( "Cleaned up temporary converted image file" );
        }
        
        // Validate colors array
        if ( ! is_array( $colors ) || empty( $colors ) ) {
            difc_log( "No valid colors detected for attachment {$attachment_id}" );
            return false;
        }
        
        // Validate color format (should be hex strings like #ffffff)
        $validated_colors = [];
        foreach ( $colors as $color ) {
            if ( is_string( $color ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
                $validated_colors[] = $color;
            } else {
                difc_log( "Invalid color format detected: " . var_export( $color, true ) );
            }
        }
        
        if ( empty( $validated_colors ) ) {
            difc_log( "No valid color formats detected for attachment {$attachment_id}" );
            return false;
        }
        
        // Ensure we have at least one color, pad with empty string for secondary if needed
        $colors = array_pad( $validated_colors, 2, '' );
        
        difc_log( "Detected colors: " . implode( ', ', $colors ) );
        
        // Only update fields that are empty (if not forcing update)
        $primary_to_save = $colors[0] ?? '';
        $secondary_to_save = $colors[1] ?? '';
    
        if ( ! $force_update && function_exists( 'get_field' ) ) {
            $existing_primary = get_field( DIFC_PRIMARY_FIELD, $post_id );
            $existing_secondary = get_field( DIFC_SECONDARY_FIELD, $post_id );
            
            // Only update if field is empty
            if ( ! empty( $existing_primary ) ) {
                $primary_to_save = $existing_primary;
                difc_log( "Preserving existing primary color: {$primary_to_save}" );
            }
            if ( ! empty( $existing_secondary ) ) {
                $secondary_to_save = $existing_secondary;
                difc_log( "Preserving existing secondary color: {$secondary_to_save}" );
            }
        }
        
        // Validate colors before saving
        if ( ! empty( $primary_to_save ) && ! preg_match( '/^#[0-9a-fA-F]{6}$/', $primary_to_save ) ) {
            difc_log( "Invalid primary color format: {$primary_to_save}, skipping save" );
            return false;
        }
        if ( ! empty( $secondary_to_save ) && ! preg_match( '/^#[0-9a-fA-F]{6}$/', $secondary_to_save ) ) {
            difc_log( "Invalid secondary color format: {$secondary_to_save}, skipping save" );
            return false;
        }
        
        // Save as HEX strings to your ACF fields
        $primary_saved = false;
        $secondary_saved = false;
        
        try {
            if ( ! empty( $primary_to_save ) ) {
                $primary_saved = update_field( DIFC_PRIMARY_FIELD, $primary_to_save, $post_id );
            }
            if ( ! empty( $secondary_to_save ) ) {
                $secondary_saved = update_field( DIFC_SECONDARY_FIELD, $secondary_to_save, $post_id );
            }
        } catch ( Exception $e ) {
            difc_log( "Error saving colors to post {$post_id}: " . $e->getMessage() );
            return false;
        } catch ( Error $e ) {
            difc_log( "Fatal error saving colors to post {$post_id}: " . $e->getMessage() );
            return false;
        }
        
        difc_log( "Saved colors to post {$post_id} - Primary: " . ( $primary_saved ? 'success' : 'failed' ) . ", Secondary: " . ( $secondary_saved ? 'success' : 'failed' ) );
        
        // Clear all caches to ensure fresh data is available
        difc_clear_post_cache( $post_id );
        
        // Verify fields were saved correctly (bypass cache by using format_value = false)
        if ( function_exists( 'get_field' ) ) {
            $verify_primary = get_field( DIFC_PRIMARY_FIELD, $post_id, false ); // false = no formatting, bypass cache
            $verify_secondary = get_field( DIFC_SECONDARY_FIELD, $post_id, false );
            
            difc_log( "Verification - Expected primary: {$primary_to_save}, Got: " . ( $verify_primary ?: 'empty' ) );
            difc_log( "Verification - Expected secondary: {$secondary_to_save}, Got: " . ( $verify_secondary ?: 'empty' ) );
            
            // If verification fails, retry save
            if ( ( ! empty( $primary_to_save ) && $verify_primary !== $primary_to_save ) || 
                 ( ! empty( $secondary_to_save ) && $verify_secondary !== $secondary_to_save ) ) {
                difc_log( "Field verification failed - retrying save for post {$post_id}" );
                
                // Retry save
                if ( ! empty( $primary_to_save ) && $verify_primary !== $primary_to_save ) {
                    update_field( DIFC_PRIMARY_FIELD, $primary_to_save, $post_id );
                    difc_log( "Retried saving primary color: {$primary_to_save}" );
                }
                if ( ! empty( $secondary_to_save ) && $verify_secondary !== $secondary_to_save ) {
                    update_field( DIFC_SECONDARY_FIELD, $secondary_to_save, $post_id );
                    difc_log( "Retried saving secondary color: {$secondary_to_save}" );
                }
                
                // Clear cache again after retry
                difc_clear_post_cache( $post_id );
            } else {
                difc_log( "Field verification successful for post {$post_id}" );
            }
        }
        
        return $primary_saved !== false && $secondary_saved !== false;
    } catch ( Exception $e ) {
        // Catch any unexpected errors to prevent breaking the save process
        difc_log( "Unexpected error in difc_extract_and_save for post {$post_id}: " . $e->getMessage() );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[DIFC] Unexpected error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }
        return false;
    } catch ( Error $e ) {
        // Catch fatal errors (PHP 7+)
        difc_log( "Fatal error in difc_extract_and_save for post {$post_id}: " . $e->getMessage() );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[DIFC] Fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }
        return false;
    }
}

/**
 * Optional: Convert image to sRGB color space using ImageMagick CLI
 * Returns original path if ImageMagick is not available (graceful fallback)
 * 
 * @param string $path Original image path
 * @return string Path to converted image or original if conversion not possible
 */
function difc_convert_to_srgb( $path ) {
    // Check if ImageMagick CLI is available (silent check, no errors if missing)
    $magick_check = @shell_exec( 'which magick 2>/dev/null' );
    if ( empty( $magick_check ) || trim( $magick_check ) === '' ) {
        // ImageMagick not available, return original
        difc_log( "ImageMagick CLI not available, using original image" );
        return $path;
    }
    
    // Check for color profile
    $profile_check = @shell_exec( "magick identify -format '%[profiles:icc]' " . escapeshellarg( $path ) . " 2>/dev/null" );
    if ( empty( $profile_check ) || trim( $profile_check ) === '' ) {
        difc_log( "No color profile detected in image" );
        return $path;
    }
    
    difc_log( "Color profile detected: {$profile_check}, converting to sRGB" );
    
    // Create temporary file for converted image
    $temp_path = sys_get_temp_dir() . '/difc_' . md5( $path ) . '_' . time() . '.jpg';
    
    // Convert to sRGB using ImageMagick
    // Use -colorspace sRGB to convert without needing sRGB.icc file
    $command = "magick convert " . escapeshellarg( $path ) . " -colorspace sRGB " . escapeshellarg( $temp_path ) . " 2>/dev/null";
    $output = @shell_exec( $command );
    
    if ( file_exists( $temp_path ) && filesize( $temp_path ) > 0 ) {
        difc_log( "ImageMagick: Successfully converted to sRGB: {$temp_path}" );
        return $temp_path;
    } else {
        difc_log( "ImageMagick conversion failed, using original image" );
        return $path; // Fallback to original
    }
}

function difc_get_dominant_colors( $path ) {
    // ── 1. Load the image into a GD resource ──────────────────────────────
    $info = @getimagesize( $path );
    if ( ! $info ) {
        difc_log( "Could not get image size for: {$path}" );
        return [];
    }

    switch ( $info['mime'] ) {
        case 'image/jpeg': $img = @imagecreatefromjpeg( $path ); break;
        case 'image/png':  $img = @imagecreatefrompng( $path );  break;
        case 'image/gif':  $img = @imagecreatefromgif( $path );  break;
        case 'image/webp': $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp( $path ) : false; break;
        default: 
            difc_log( "Unsupported image type: {$info['mime']}" );
            return [];
    }

    if ( ! $img ) {
        difc_log( "Could not create image resource from: {$path}" );
        return [];
    }

    $width  = imagesx( $img );
    $height = imagesy( $img );
    $total  = $width * $height;

    if ( $total === 0 ) {
        imagedestroy( $img );
        return [];
    }

    // ── 1.5.1 Corner-First Uniform Background Detection (GD Only) ───────────
    // Strategy: Sample the 4 corners first - if they're uniform, that's the background color
    // Corners are least likely to have text/content, most likely to be pure background
    
    $corner_size = min( 50, (int) ( $width / 10 ), (int) ( $height / 10 ) ); // 10% or 50px, whichever is smaller
    $corner_samples = [];
    
    // Sample all 4 corners densely
    $corners = [
        [0, 0, $corner_size, $corner_size], // Top-left
        [$width - $corner_size, 0, $width, $corner_size], // Top-right
        [0, $height - $corner_size, $corner_size, $height], // Bottom-left
        [$width - $corner_size, $height - $corner_size, $width, $height], // Bottom-right
    ];
    
    foreach ( $corners as $corner ) {
        for ( $y = $corner[1]; $y < $corner[3]; $y++ ) {
            for ( $x = $corner[0]; $x < $corner[2]; $x++ ) {
                $rgb = imagecolorat( $img, $x, $y );
                if ( $rgb !== false ) {
                    $r = ( $rgb >> 16 ) & 0xFF;
                    $g = ( $rgb >> 8  ) & 0xFF;
                    $b =   $rgb        & 0xFF;
                    // Skip pure white/black (likely text/foreground)
                    if ( ! ( $r > 250 && $g > 250 && $b > 250 ) && ! ( $r < 5 && $g < 5 && $b < 5 ) ) {
                        $corner_samples[] = [ $r, $g, $b ];
                    }
                }
            }
        }
    }
    
    difc_log( "Corner-first detection: sampled " . count( $corner_samples ) . " corner pixels" );
    
    if ( count( $corner_samples ) >= 100 ) {
        // Check if corners are uniform (use exact mode with 2-unit tolerance)
        $color_groups = [];
        foreach ( $corner_samples as $sample ) {
            $matched = false;
            foreach ( $color_groups as $key => &$group ) {
                $group_color = $group['color'];
                $diff = abs( $sample[0] - $group_color[0] ) + 
                        abs( $sample[1] - $group_color[1] ) + 
                        abs( $sample[2] - $group_color[2] );
                if ( $diff <= 6 ) { // 2 units per channel = 6 total
                    $group['count']++;
                    $group['samples'][] = $sample;
                    $matched = true;
                    break;
                }
            }
            unset( $group );
            if ( ! $matched ) {
                $color_groups[] = [
                    'color' => $sample,
                    'count' => 1,
                    'samples' => [ $sample ]
                ];
            }
        }
        
        // Sort by frequency
        usort( $color_groups, function( $a, $b ) {
            return $b['count'] <=> $a['count'];
        } );
        
        $most_common = reset( $color_groups );
        $most_common_pct = ( $most_common['count'] / count( $corner_samples ) ) * 100;
        
        difc_log( "Corner-first: most common color group represents {$most_common_pct}% of corner pixels" );
        
        // If 60%+ of corner pixels match, use that color
        if ( $most_common_pct >= 60 ) {
            // Use exact mode from the most common group
            $exact_samples = $most_common['samples'];
            $color_counts = [];
            foreach ( $exact_samples as $sample ) {
                $key = sprintf( '%03d-%03d-%03d', $sample[0], $sample[1], $sample[2] );
                if ( ! isset( $color_counts[ $key ] ) ) {
                    $color_counts[ $key ] = [ 'count' => 0, 'color' => $sample ];
                }
                $color_counts[ $key ]['count']++;
            }
            arsort( $color_counts );
            $exact_color = reset( $color_counts );
            
            $primary_r = $exact_color['color'][0];
            $primary_g = $exact_color['color'][1];
            $primary_b = $exact_color['color'][2];
            
            $primary_color = sprintf( '#%02x%02x%02x', $primary_r, $primary_g, $primary_b );
            difc_log( "Corner-first detection: {$primary_color} (from {$most_common_pct}% of corner pixels)" );
            
            $primary_hsl = difc_rgb_to_hsl( $primary_r, $primary_g, $primary_b );
            $secondary_color = difc_get_contrast_fallback( $primary_hsl );
            
            imagedestroy( $img );
            return [ $primary_color, $secondary_color ];
        }
    }

    // ── 1.5.2 Check for uniform background (posters, solid colors) ───────────
    // This helps with images that have a uniform background color
    // Strategy: Sample ONLY from edges/corners first, check for uniformity
    // If uniform, sample more from edges only (avoid center where text/content is)
    
    $edge_only_samples = [];
    $edge_margin = max( 10, min( $width, $height ) * 0.1 ); // 10% margin or 10px, whichever is larger
    
    // Sample corners and edges more densely
    $corner_size = min( 50, $width / 4, $height / 4 );
    for ( $y = 0; $y < $height; $y++ ) {
        for ( $x = 0; $x < $width; $x++ ) {
            // Only sample from edges (corners and perimeter)
            $is_corner = ( $x < $corner_size && $y < $corner_size ) ||
                         ( $x >= $width - $corner_size && $y < $corner_size ) ||
                         ( $x < $corner_size && $y >= $height - $corner_size ) ||
                         ( $x >= $width - $corner_size && $y >= $height - $corner_size );
            $is_edge = ( $x < $edge_margin || $x >= $width - $edge_margin || 
                        $y < $edge_margin || $y >= $height - $edge_margin );
            
            if ( $is_corner || ( $is_edge && ( $x % 5 == 0 || $y % 5 == 0 ) ) ) {
                $rgb = imagecolorat( $img, $x, $y );
                if ( $rgb !== false ) {
                    $r = ( $rgb >> 16 ) & 0xFF;
                    $g = ( $rgb >> 8  ) & 0xFF;
                    $b =   $rgb        & 0xFF;
                    // Skip pure white and pure black (likely text/foreground)
                    if ( ! ( $r > 250 && $g > 250 && $b > 250 ) && ! ( $r < 5 && $g < 5 && $b < 5 ) ) {
                        $edge_only_samples[] = [ $r, $g, $b ];
                    }
                }
            }
        }
    }
    
    difc_log( "Sampled " . count( $edge_only_samples ) . " edge pixels for uniform detection" );
    
    // Check if we have enough edge samples
    if ( count( $edge_only_samples ) >= 20 ) {
        // Use mode (most frequent color) for uniform backgrounds - more accurate than mean/median
        // Group similar colors together with a small tolerance (3 RGB units) to handle compression artifacts
        $color_counts = [];
        foreach ( $edge_only_samples as $sample ) {
            // Round to nearest 3 to handle slight variations from compression, but keep more precision
            $r_rounded = (int) ( round( $sample[0] / 3 ) * 3 );
            $g_rounded = (int) ( round( $sample[1] / 3 ) * 3 );
            $b_rounded = (int) ( round( $sample[2] / 3 ) * 3 );
            $key = sprintf( '%03d-%03d-%03d', $r_rounded, $g_rounded, $b_rounded );
            
            if ( ! isset( $color_counts[ $key ] ) ) {
                $color_counts[ $key ] = [
                    'count' => 0,
                    'r' => $r_rounded,
                    'g' => $g_rounded,
                    'b' => $b_rounded,
                    'samples' => []
                ];
            }
            $color_counts[ $key ]['count']++;
            $color_counts[ $key ]['samples'][] = $sample;
        }
        
        // Sort by frequency
        uasort( $color_counts, function( $a, $b ) {
            return $b['count'] <=> $a['count'];
        } );
        
        $most_common = reset( $color_counts );
        $most_common_pct = ( $most_common['count'] / count( $edge_only_samples ) ) * 100;
        
        difc_log( "Most common edge color: RGB({$most_common['r']}, {$most_common['g']}, {$most_common['b']}) - {$most_common_pct}% of samples" );
        
        // If the most common color represents 30%+ of edge samples, treat as uniform background
        // Lower threshold to catch more uniform backgrounds
        if ( $most_common_pct >= 30 ) {
            // Calculate exact color from the most common color group (use mean of that group)
            $exact_samples = $most_common['samples'];
            $sum_r = 0;
            $sum_g = 0;
            $sum_b = 0;
            foreach ( $exact_samples as $sample ) {
                $sum_r += $sample[0];
                $sum_g += $sample[1];
                $sum_b += $sample[2];
            }
            $primary_r = (int) ( $sum_r / count( $exact_samples ) );
            $primary_g = (int) ( $sum_g / count( $exact_samples ) );
            $primary_b = (int) ( $sum_b / count( $exact_samples ) );
            
            $primary_color = sprintf( '#%02x%02x%02x', $primary_r, $primary_g, $primary_b );
            difc_log( "Detected uniform background color: {$primary_color} (from {$most_common_pct}% of edge samples)" );
            
            // For uniform backgrounds, secondary color is less important
            // Use a lighter/darker version or white/black for contrast
            $primary_hsl = difc_rgb_to_hsl( $primary_r, $primary_g, $primary_b );
            $secondary_color = difc_get_contrast_fallback( $primary_hsl );
            
            imagedestroy( $img );
            return [ $primary_color, $secondary_color ];
        }
    }
    
    // Calculate center region bounds (inner 60% of image)
    $center_x_min = $width * 0.2;
    $center_x_max = $width * 0.8;
    $center_y_min = $height * 0.2;
    $center_y_max = $height * 0.8;

    // ── 2. Sample pixels with salience weighting ─────────────────────────
    $step   = max( 1, (int) sqrt( $total / DIFC_SAMPLE_SIZE ) );
    $weighted_pixels = [];

    for ( $y = 0; $y < $height; $y += $step ) {
        for ( $x = 0; $x < $width; $x += $step ) {
            $rgb = imagecolorat( $img, $x, $y );
            if ( $rgb === false ) {
                continue;
            }
            
            $r = ( $rgb >> 16 ) & 0xFF;
            $g = ( $rgb >> 8  ) & 0xFF;
            $b =   $rgb        & 0xFF;

            // Skip near-white and near-black pixels (usually backgrounds)
            if ( $r > 240 && $g > 240 && $b > 240 ) continue; // white-ish
            if ( $r < 15  && $g < 15  && $b < 15  ) continue; // black-ish

            // Convert to HSL for perceptual analysis
            $hsl = difc_rgb_to_hsl( $r, $g, $b );
            $h = $hsl[0];
            $s = $hsl[1];
            $l = $hsl[2];

            // Filter out low-saturation colors (grays) - not perceptually salient
            if ( $s < DIFC_MIN_SATURATION ) {
                continue;
            }

            // Calculate salience score
            $salience = difc_calculate_salience( $s, $l, $x, $y, $center_x_min, $center_x_max, $center_y_min, $center_y_max );

            // Store pixel with its salience weight
            $weighted_pixels[] = [
                'rgb' => [ $r, $g, $b ],
                'hsl' => $hsl,
                'salience' => $salience,
            ];
        }
    }

    imagedestroy( $img );

    if ( empty( $weighted_pixels ) ) {
        difc_log( "No valid pixels sampled after salience filtering" );
        return [];
    }

    difc_log( "Sampled " . count( $weighted_pixels ) . " salient pixels" );

    // ── 3. Weighted color clustering with salience ───────────────────────
    $clusters = [];

    foreach ( $weighted_pixels as $pixel_data ) {
        $px = $pixel_data['rgb'];
        $weight = $pixel_data['salience'];
        $matched = false;

        foreach ( $clusters as $i => &$cluster ) {
            // Use perceptual distance (HSL-based) for clustering
            if ( difc_perceptual_color_distance( $pixel_data['hsl'], $cluster['hsl_center'] ) < DIFC_CLUSTER_THRESHOLD ) {
                // Weighted merge: salience affects contribution
                $total_weight = $cluster['total_weight'] + $weight;
                $ratio = $weight / $total_weight;
                
                $cluster['rgb_center'] = [
                    (int) ( $cluster['rgb_center'][0] * ( 1 - $ratio ) + $px[0] * $ratio ),
                    (int) ( $cluster['rgb_center'][1] * ( 1 - $ratio ) + $px[1] * $ratio ),
                    (int) ( $cluster['rgb_center'][2] * ( 1 - $ratio ) + $px[2] * $ratio ),
                ];
                
                // Update HSL center (weighted average)
                $cluster['hsl_center'] = [
                    difc_weighted_hue_average( $cluster['hsl_center'][0], $pixel_data['hsl'][0], $cluster['total_weight'], $weight ),
                    ( $cluster['hsl_center'][1] * $cluster['total_weight'] + $pixel_data['hsl'][1] * $weight ) / $total_weight,
                    ( $cluster['hsl_center'][2] * $cluster['total_weight'] + $pixel_data['hsl'][2] * $weight ) / $total_weight,
                ];
                
                $cluster['total_weight'] = $total_weight;
                $cluster['count']++;
                $matched = true;
                break;
            }
        }
        unset( $cluster );

        if ( ! $matched ) {
            $clusters[] = [
                'rgb_center' => $px,
                'hsl_center' => $pixel_data['hsl'],
                'total_weight' => $weight,
                'count' => 1,
            ];
        }
    }

    if ( empty( $clusters ) ) {
        difc_log( "No clusters formed" );
        return [];
    }

    // ── 4. Sort by salience-weighted dominance ───────────────────────────
    usort( $clusters, function( $a, $b ) {
        // Sort by total salience weight (more salient = more important)
        return $b['total_weight'] <=> $a['total_weight'];
    } );

    if ( empty( $clusters ) ) {
        return [];
    }

    // ── 5. Select primary and secondary with contrast requirement ─────────
    $primary = $clusters[0];
    $results = [];
    
    // Primary color (most salient)
    $results[] = sprintf(
        '#%02x%02x%02x',
        $primary['rgb_center'][0],
        $primary['rgb_center'][1],
        $primary['rgb_center'][2]
    );

    // Secondary color: find the most salient color that contrasts well with primary
    if ( count( $clusters ) > 1 ) {
        $primary_hsl = $primary['hsl_center'];
        $best_secondary = null;
        $best_score = -1;

        // Evaluate remaining clusters for contrast, saturation, and legibility
        foreach ( array_slice( $clusters, 1 ) as $cluster ) {
            $cluster_hsl = $cluster['hsl_center'];
            
            // Calculate contrast score (emphasizes legibility for text/background)
            $contrast_score = difc_calculate_legibility_contrast( $primary_hsl, $cluster_hsl );
            
            // Calculate chroma contrast (saturation + hue difference - the "brilliant contrast")
            $saturation = $cluster_hsl[1]; // 0-1
            $h_diff = abs( $primary_hsl[0] - $cluster_hsl[0] );
            if ( $h_diff > 0.5 ) {
                $h_diff = 1.0 - $h_diff; // Wrap around color wheel
            }
            $hue_contrast = $h_diff * 2.0; // Normalize 0-0.5 to 0-1
            
            // Chroma contrast = saturation × hue difference (brilliant colors with different hues)
            $chroma_contrast = $saturation * $hue_contrast;
            
            // Strong chroma contrast boost - rewards vibrant colors with excellent hue separation
            // This makes a small amount of vibrant blue beat a large amount of similar brown/orange
            $chroma_boost = 1.0 + ( $chroma_contrast * ( DIFC_CHROMA_CONTRAST_WEIGHT - 1.0 ) );
            
            // Saturation boost - prefer vibrant over dull (additional boost)
            $saturation_boost = 1.0 + ( $saturation * ( DIFC_SATURATION_BOOST - 1.0 ) );
            
            // Penalize similar hues more strongly (but chroma boost can overcome this)
            $hue_penalty = $h_diff < DIFC_MIN_HUE_DIFFERENCE ? 0.5 : 1.0; // Less harsh penalty since chroma handles it
            
            // Base salience (pixel count weight) - but reduce its importance when chroma contrast is excellent
            $salience_score = $cluster['total_weight'];
            $salience_modifier = $chroma_contrast > 0.5 ? 0.7 : 1.0; // Reduce salience importance for excellent chroma
            
            // Combined score: chroma-boosted, saturation-boosted, contrast-weighted
            // Chroma contrast can dramatically boost small amounts of brilliant contrasting colors
            $combined_score = ( $salience_score * $salience_modifier )
                * $chroma_boost 
                * $saturation_boost 
                * ( 1.0 + $contrast_score * ( DIFC_LEGIBILITY_WEIGHT - 1.0 ) )
                * $hue_penalty;
            
            if ( $combined_score > $best_score ) {
                $best_score = $combined_score;
                $best_secondary = $cluster;
            }
        }

        // If we found a good secondary, check contrast and apply fallback if needed
        if ( $best_secondary ) {
            $secondary_rgb = $best_secondary['rgb_center'];
            $secondary_hsl = $best_secondary['hsl_center'];
            
            // Check if contrast meets minimum legibility requirement
            $contrast_ratio = difc_calculate_wcag_contrast_ratio( $primary_hsl, $secondary_hsl );
            
            // Check hue contrast - if sufficient, keep the color even if lightness contrast is low
            $h_diff = abs( $primary_hsl[0] - $secondary_hsl[0] );
            if ( $h_diff > 0.5 ) {
                $h_diff = 1.0 - $h_diff; // Wrap around color wheel
            }
            $has_good_hue_contrast = $h_diff >= DIFC_MIN_HUE_DIFFERENCE;
            
            if ( $contrast_ratio >= DIFC_MIN_CONTRAST_RATIO || $has_good_hue_contrast ) {
                // Good contrast OR good hue contrast - use the selected color
                $results[] = sprintf(
                    '#%02x%02x%02x',
                    $secondary_rgb[0],
                    $secondary_rgb[1],
                    $secondary_rgb[2]
                );
                if ( $contrast_ratio < DIFC_MIN_CONTRAST_RATIO && $has_good_hue_contrast ) {
                    difc_log( "Using secondary color despite low contrast ({$contrast_ratio}:1) due to good hue contrast ({$h_diff})" );
                }
            } else {
                // Insufficient contrast AND insufficient hue contrast - use white or black fallback
                $fallback_color = difc_get_contrast_fallback( $primary_hsl );
                $results[] = $fallback_color;
                difc_log( "Secondary color contrast insufficient ({$contrast_ratio}:1) and hue contrast insufficient ({$h_diff}), using fallback: {$fallback_color}" );
            }
        } elseif ( isset( $clusters[1] ) ) {
            // Fallback: check second most salient for contrast
            $fallback_cluster = $clusters[1];
            $fallback_hsl = $fallback_cluster['hsl_center'];
            $contrast_ratio = difc_calculate_wcag_contrast_ratio( $primary_hsl, $fallback_hsl );
            
            // Check hue contrast
            $h_diff = abs( $primary_hsl[0] - $fallback_hsl[0] );
            if ( $h_diff > 0.5 ) {
                $h_diff = 1.0 - $h_diff;
            }
            $has_good_hue_contrast = $h_diff >= DIFC_MIN_HUE_DIFFERENCE;
            
            if ( $contrast_ratio >= DIFC_MIN_CONTRAST_RATIO || $has_good_hue_contrast ) {
                $results[] = sprintf(
                    '#%02x%02x%02x',
                    $fallback_cluster['rgb_center'][0],
                    $fallback_cluster['rgb_center'][1],
                    $fallback_cluster['rgb_center'][2]
                );
                if ( $contrast_ratio < DIFC_MIN_CONTRAST_RATIO && $has_good_hue_contrast ) {
                    difc_log( "Using fallback cluster despite low contrast ({$contrast_ratio}:1) due to good hue contrast ({$h_diff})" );
                }
            } else {
                // Use white/black fallback
                $fallback_color = difc_get_contrast_fallback( $primary_hsl );
                $results[] = $fallback_color;
                difc_log( "Fallback cluster contrast insufficient ({$contrast_ratio}:1) and hue contrast insufficient ({$h_diff}), using white/black: {$fallback_color}" );
            }
        } else {
            // No secondary color found at all - use white/black fallback
            $fallback_color = difc_get_contrast_fallback( $primary_hsl );
            $results[] = $fallback_color;
            difc_log( "No secondary color found, using fallback: {$fallback_color}" );
        }
    }

    return $results;
}


/**
 * Convert RGB (0-255) to HSL (0-1, 0-1, 0-1)
 */
function difc_rgb_to_hsl( $r, $g, $b ) {
    $r /= 255;
    $g /= 255;
    $b /= 255;

    $max = max( $r, $g, $b );
    $min = min( $r, $g, $b );
    $delta = $max - $min;

    $l = ( $max + $min ) / 2;

    if ( $delta == 0 ) {
        $h = 0;
        $s = 0;
    } else {
        $s = $l > 0.5 ? $delta / ( 2 - $max - $min ) : $delta / ( $max + $min );

        switch ( $max ) {
            case $r:
                $h = ( ( $g - $b ) / $delta ) + ( $g < $b ? 6 : 0 );
                break;
            case $g:
                $h = ( $b - $r ) / $delta + 2;
                break;
            case $b:
                $h = ( $r - $g ) / $delta + 4;
                break;
            default:
                $h = 0;
        }
        $h /= 6;
    }

    return [ $h, $s, $l ];
}

/**
 * Calculate color salience score based on saturation, luminance, and position
 */
function difc_calculate_salience( $saturation, $luminance, $x, $y, $center_x_min, $center_x_max, $center_y_min, $center_y_max ) {
    $salience = 1.0;

    // Boost highly saturated colors (more perceptually prominent)
    $salience *= 1.0 + ( $saturation * ( DIFC_SATURATION_WEIGHT - 1.0 ) );

    // Prefer mid-range luminance (avoid too dark/too light)
    // Create a bell curve preference around DIFC_LUMINANCE_PREFERENCE
    $luminance_distance = abs( $luminance - DIFC_LUMINANCE_PREFERENCE );
    $luminance_score = 1.0 - ( $luminance_distance * 1.5 ); // Penalize extreme luminance
    $luminance_score = max( 0.3, $luminance_score ); // Don't penalize too harshly
    $salience *= $luminance_score;

    // Boost center region (where important content usually is)
    if ( $x >= $center_x_min && $x <= $center_x_max && 
         $y >= $center_y_min && $y <= $center_y_max ) {
        $salience *= DIFC_CENTER_WEIGHT;
    }

    return $salience;
}

/**
 * Perceptual color distance in HSL space (more accurate than RGB)
 */
function difc_perceptual_color_distance( $hsl1, $hsl2 ) {
    // Convert to approximate perceptual distance
    // Weight hue difference by saturation (less saturated = hue matters less)
    $h_diff = abs( $hsl1[0] - $hsl2[0] );
    if ( $h_diff > 0.5 ) {
        $h_diff = 1.0 - $h_diff; // Wrap around the color wheel
    }
    
    // Scale hue difference by average saturation
    $avg_sat = ( $hsl1[1] + $hsl2[1] ) / 2;
    $hue_distance = $h_diff * $avg_sat * 255;
    
    // Saturation and luminance differences
    $sat_distance = abs( $hsl1[1] - $hsl2[1] ) * 255;
    $lum_distance = abs( $hsl1[2] - $hsl2[2] ) * 255;
    
    // Weighted combination (hue matters more when colors are saturated)
    return sqrt(
        $hue_distance * $hue_distance * 2 +
        $sat_distance * $sat_distance +
        $lum_distance * $lum_distance
    );
}

/**
 * Weighted average for hue (handles circular nature of hue)
 */
function difc_weighted_hue_average( $h1, $h2, $w1, $w2 ) {
    // Convert to angles
    $a1 = $h1 * 360;
    $a2 = $h2 * 360;
    
    // Calculate circular mean
    $sin_sum = sin( deg2rad( $a1 ) ) * $w1 + sin( deg2rad( $a2 ) ) * $w2;
    $cos_sum = cos( deg2rad( $a1 ) ) * $w1 + cos( deg2rad( $a2 ) ) * $w2;
    
    $angle = rad2deg( atan2( $sin_sum, $cos_sum ) );
    if ( $angle < 0 ) {
        $angle += 360;
    }
    
    return $angle / 360;
}

/**
 * Calculate contrast score between two colors (0-1, higher = better contrast)
 * Prioritizes hue difference, then lightness/saturation differences
 */
function difc_calculate_contrast_score( $hsl1, $hsl2 ) {
    // Legacy function - redirects to legibility-focused version
    return difc_calculate_legibility_contrast( $hsl1, $hsl2 );
}

/**
 * Calculate legibility-focused contrast score for text/background use
 * Emphasizes lightness difference (most important for readability)
 */
function difc_calculate_legibility_contrast( $hsl1, $hsl2 ) {
    $score = 0.0;
    
    // 1. Lightness contrast (CRITICAL for text legibility - WCAG standard)
    $l1 = $hsl1[2];
    $l2 = $hsl2[2];
    $l_diff = abs( $l1 - $l2 );
    
    // Calculate relative luminance (WCAG formula approximation)
    $rel_lum1 = difc_relative_luminance_from_hsl( $hsl1 );
    $rel_lum2 = difc_relative_luminance_from_hsl( $hsl2 );
    
    // WCAG contrast ratio (simplified)
    $lighter = max( $rel_lum1, $rel_lum2 );
    $darker = min( $rel_lum1, $rel_lum2 );
    $contrast_ratio = ( $lighter + 0.05 ) / ( $darker + 0.05 );
    
    // Normalize contrast ratio to 0-1 score (4.5:1 = AA, 7:1 = AAA)
    $contrast_score = min( 1.0, ( $contrast_ratio - 1.0 ) / 6.0 ); // 7:1 = perfect score
    
    // Lightness difference is the primary factor (60% weight)
    if ( $l_diff >= DIFC_MIN_LIGHTNESS_DIFF ) {
        $score += $contrast_score * 0.6;
    } else {
        // Penalize insufficient lightness contrast
        $score += ( $l_diff / DIFC_MIN_LIGHTNESS_DIFF ) * $contrast_score * 0.3;
    }
    
    // 2. Hue contrast (important to avoid similar colors)
    $h_diff = abs( $hsl1[0] - $hsl2[0] );
    if ( $h_diff > 0.5 ) {
        $h_diff = 1.0 - $h_diff;
    }
    $hue_contrast = $h_diff * 2.0; // Normalize 0-0.5 to 0-1
    
    // Weight hue contrast more when colors are saturated (chroma contrast matters more)
    $avg_saturation = ( $hsl1[1] + $hsl2[1] ) / 2.0;
    $hue_weight = 0.3 + ( $avg_saturation * 0.2 ); // 30-50% weight based on saturation
    
    if ( $h_diff >= DIFC_MIN_HUE_DIFFERENCE ) {
        // Good hue contrast - weight it more when saturated
        $score += $hue_contrast * $hue_weight;
    } else {
        // Similar hues - still give some credit but less
        $score += $hue_contrast * ( $hue_weight * 0.33 );
    }
    
    // 3. Saturation boost (prefer vibrant over dull - 10% weight)
    $s1 = $hsl1[1];
    $s2 = $hsl2[1];
    $avg_saturation = ( $s1 + $s2 ) / 2.0;
    $score += $avg_saturation * 0.1;
    
    // Ensure score is 0-1
    $score = min( 1.0, max( 0.0, $score ) );
    
    // Bonus: excellent lightness contrast gets extra boost
    if ( $l_diff >= 0.4 && $contrast_ratio >= 4.5 ) {
        $score = min( 1.0, $score * 1.15 ); // 15% boost for WCAG AA compliance
    }
    
    return $score;
}

/**
 * Calculate relative luminance from HSL (approximation for WCAG contrast)
 */
function difc_relative_luminance_from_hsl( $hsl ) {
    // Convert HSL to RGB first
    $h = $hsl[0];
    $s = $hsl[1];
    $l = $hsl[2];
    
    if ( $s == 0 ) {
        // Grayscale
        $r = $g = $b = $l;
    } else {
        $q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        
        $r = difc_hue_to_rgb( $p, $q, $h + 1/3 );
        $g = difc_hue_to_rgb( $p, $q, $h );
        $b = difc_hue_to_rgb( $p, $q, $h - 1/3 );
    }
    
    // Convert to 0-255 range
    $r = round( $r * 255 );
    $g = round( $g * 255 );
    $b = round( $b * 255 );
    
    // Calculate relative luminance (WCAG formula)
    $r = $r / 255;
    $g = $g / 255;
    $b = $b / 255;
    
    $r = $r <= 0.03928 ? $r / 12.92 : pow( ( $r + 0.055 ) / 1.055, 2.4 );
    $g = $g <= 0.03928 ? $g / 12.92 : pow( ( $g + 0.055 ) / 1.055, 2.4 );
    $b = $b <= 0.03928 ? $b / 12.92 : pow( ( $b + 0.055 ) / 1.055, 2.4 );
    
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/**
 * Helper function for HSL to RGB conversion
 */
function difc_hue_to_rgb( $p, $q, $t ) {
    if ( $t < 0 ) $t += 1;
    if ( $t > 1 ) $t -= 1;
    if ( $t < 1/6 ) return $p + ( $q - $p ) * 6 * $t;
    if ( $t < 1/2 ) return $q;
    if ( $t < 2/3 ) return $p + ( $q - $p ) * ( 2/3 - $t ) * 6;
    return $p;
}


/**
 * Calculate WCAG contrast ratio between two colors
 * Returns ratio (e.g., 4.5 means 4.5:1)
 */
function difc_calculate_wcag_contrast_ratio( $hsl1, $hsl2 ) {
    $lum1 = difc_relative_luminance_from_hsl( $hsl1 );
    $lum2 = difc_relative_luminance_from_hsl( $hsl2 );
    
    $lighter = max( $lum1, $lum2 );
    $darker = min( $lum1, $lum2 );
    
    return ( $lighter + 0.05 ) / ( $darker + 0.05 );
}

/**
 * Get white or black fallback color based on which provides better contrast
 * Returns HEX color string (#ffffff or #000000)
 */
function difc_get_contrast_fallback( $primary_hsl ) {
    // White HSL: [0, 0, 1.0]
    $white_hsl = [ 0, 0, 1.0 ];
    // Black HSL: [0, 0, 0.0]
    $black_hsl = [ 0, 0, 0.0 ];
    
    $white_contrast = difc_calculate_wcag_contrast_ratio( $primary_hsl, $white_hsl );
    $black_contrast = difc_calculate_wcag_contrast_ratio( $primary_hsl, $black_hsl );
    
    // Return whichever provides better contrast
    if ( $white_contrast >= $black_contrast ) {
        return '#ffffff';
    } else {
        return '#000000';
    }
}

/**
 * Manual trigger function - can be called programmatically or via WP-CLI
 * Usage: difc_process_all_events() or difc_process_event( $post_id )
 */
function difc_process_event( $post_id ) {
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    if ( ! $thumbnail_id ) {
        difc_log( "No thumbnail found for post {$post_id}" );
        return false;
    }
    return difc_extract_and_save( $post_id, $thumbnail_id );
}

function difc_process_all_events() {
    $posts = get_posts( [
        'post_type'      => DIFC_POST_TYPE,
        'posts_per_page' => -1,
        'meta_query'     => [
            [
                'key'     => '_thumbnail_id',
                'compare' => 'EXISTS',
            ],
        ],
        'fields' => 'ids',
    ] );
    
    $processed = 0;
    foreach ( $posts as $post_id ) {
        if ( difc_process_event( $post_id ) ) {
            $processed++;
        }
    }
    
    difc_log( "Processed {$processed} of " . count( $posts ) . " events" );
    return $processed;
}

// Add admin action for manual processing (optional - can be removed if not needed)
if ( is_admin() ) {
    add_action( 'admin_init', function() {
        if ( isset( $_GET['difc_process_all'] ) && current_user_can( 'manage_options' ) ) {
            check_admin_referer( 'difc_process_all' );
            $count = difc_process_all_events();
            wp_die( "Processed {$count} events. <a href='" . admin_url() . "'>Go back</a>" );
        }
    } );
}