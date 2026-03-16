<?php
/**
 * Plugin Name: Featured Image Dominant Colors
 * Description: Detects the 2 dominant colors of a featured image and saves them to ACF fields.
 * Version: 1.1
 */

// Log available image libraries (one-time check on plugin load)
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    $has_imagick_ext = extension_loaded('imagick');
    $has_gd = extension_loaded('gd');
    $magick_check = @shell_exec( 'which magick 2>/dev/null' );
    $has_imagick_cli = ! empty( $magick_check ) && trim( $magick_check ) !== '';
    error_log( '[DIFC] Image libraries - PHP Imagick: ' . ( $has_imagick_ext ? 'yes' : 'no' ) . ', GD: ' . ( $has_gd ? 'yes' : 'no' ) . ', ImageMagick CLI: ' . ( $has_imagick_cli ? 'yes' : 'no' ) );
}

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
define( 'DIFC_SATURATION_WEIGHT', 2.5 );    // How much to boost highly saturated colors (increased to prioritize vibrant colors like pink)
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
define( 'DIFC_CHROMA_SORT_BOOST', 0.5 );        // How much to boost clusters with high chroma/saturation in sorting (0.5 = 50% boost, increased to prioritize vibrant colors)
define( 'DIFC_MAX_CLUSTERS', 10 );              // Maximum number of color clusters (increased to preserve more distinct colors in palette)
define( 'DIFC_UNIFORM_THRESHOLD', 20 );        // RGB difference threshold for uniform background detection (higher = more lenient)
define( 'DIFC_UNIFORM_MIN_SAMPLES', 0.7 );     // Minimum percentage of samples that must be uniform (0.7 = 70%)
define( 'DIFC_DEBUG', true ); // Enable for debugging color extraction
// ─────────────────────────────────────────────────────────────────────────────


// Hook into multiple events to catch thumbnail changes
add_action( 'updated_post_meta', 'difc_on_thumbnail_change', 5, 4 ); // Higher priority
add_action( 'added_post_meta',   'difc_on_thumbnail_change', 5, 4 ); // Higher priority
add_action( 'set_post_thumbnail', 'difc_on_set_thumbnail', 5, 3 ); // Higher priority
add_action( 'delete_post_thumbnail', 'difc_on_delete_thumbnail', 5, 1 ); // Track thumbnail removal
add_action( 'save_post', 'difc_on_save_post', 20, 2 ); // Priority 20 to run after ACF processes

// Also hook into attachment updates in case image is replaced
add_action( 'edit_attachment', 'difc_on_attachment_edit', 10, 1 );

// Add meta box for manual color re-analysis
add_action( 'add_meta_boxes', 'difc_add_meta_box' );
add_action( 'admin_enqueue_scripts', 'difc_enqueue_admin_scripts' );
add_action( 'wp_ajax_difc_reanalyze_colors', 'difc_ajax_reanalyze_colors' );

// Add button for attachment color extraction
add_action( 'attachment_fields_to_edit', 'difc_add_attachment_color_button', 10, 2 );
add_action( 'admin_enqueue_scripts', 'difc_enqueue_attachment_scripts' );
add_action( 'wp_ajax_difc_extract_attachment_colors', 'difc_ajax_extract_attachment_colors' );

// New: Automatically extract palettes for image attachments on upload
add_action( 'add_attachment', 'difc_on_add_attachment' );

// AJAX endpoint for JavaScript to check if colors were updated server-side
add_action( 'wp_ajax_difc_check_color_update', 'difc_ajax_check_color_update' );

function difc_log( $message ) {
    // Always log if DIFC_DEBUG is true, or if WP_DEBUG is enabled
    if ( DIFC_DEBUG || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
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
    
    // Clear specific ACF field caches
    if ( function_exists( 'acf_flush_value_cache' ) ) {
        acf_flush_value_cache( $post_id, DIFC_PRIMARY_FIELD );
        acf_flush_value_cache( $post_id, DIFC_SECONDARY_FIELD );
        // Clear palette fields for attachments
        for ( $i = 1; $i <= 10; $i++ ) {
            acf_flush_value_cache( $post_id, "image_col_{$i}_hex" );
            acf_flush_value_cache( $post_id, "image_col_{$i}_name" );
        }
    }
    
    // Clear WordPress object cache more thoroughly
    wp_cache_flush_group( 'post_meta' );
    
    // Clear opcache if available (for PHP opcache)
    if ( function_exists( 'opcache_reset' ) && ini_get( 'opcache.enable' ) ) {
        @opcache_reset();
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
        
        // Extract and save colors
        $result = difc_extract_and_save( $post_id, (int) $meta_value, true ); // Force update when thumbnail changes
        
        // Set a timestamp flag that JavaScript can poll to detect server-side updates
        // This helps when the hook fires but JavaScript hasn't detected the change yet
        update_post_meta( $post_id, '_difc_colors_updated_timestamp', time() );
        update_post_meta( $post_id, '_difc_colors_updated_image_id', (int) $meta_value );
        
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

/**
 * New: Attachment-centric extraction wrapper
 * Generates a 10-color palette for any image attachment, without requiring an event post.
 *
 * @param int  $attachment_id
 * @param bool $force_update If true, ignore debounce/previous palette checks.
 *
 * @return bool
 */
function difc_extract_for_attachment( $attachment_id, $force_update = false ) {
    try {
        // Only process image attachments
        if ( ! wp_attachment_is_image( $attachment_id ) ) {
            difc_log( "Skipping attachment {$attachment_id} - not an image" );
            return false;
        }

        // Optional debounce: skip if we've processed recently, unless forcing
        if ( ! $force_update ) {
            $last_processed = get_post_meta( $attachment_id, '_difc_attachment_processed', true );
            if ( $last_processed && ( time() - (int) $last_processed ) < 60 ) { // 60 second guard
                difc_log( "Attachment {$attachment_id} processed recently, skipping" );
                return false;
            }
        }

        // Get image path
        $path = get_attached_file( $attachment_id );
        if ( ! $path || ! file_exists( $path ) ) {
            difc_log( "Image file not found for attachment {$attachment_id}: " . ( $path ?: 'no path' ) );
            return false;
        }

        // Size / memory guards (reuse logic from difc_extract_and_save)
        $file_size = @filesize( $path );
        if ( $file_size && $file_size > 50 * 1024 * 1024 ) { // 50MB limit
            difc_log( "Image file too large ({$file_size} bytes) for attachment {$attachment_id}, skipping" );
            return false;
        }

        $current_memory_limit = ini_get( 'memory_limit' );
        $memory_limit_bytes   = wp_convert_hr_to_bytes( $current_memory_limit );
        if ( $memory_limit_bytes < 512 * 1024 * 1024 ) {
            @ini_set( 'memory_limit', '512M' );
            difc_log( "Temporarily increased memory limit from {$current_memory_limit} to 512M for attachment {$attachment_id}" );
        }

        // Convert to sRGB / resize
        $processed_path = difc_convert_to_srgb( $path, 1000 );
        $is_temp_file   = ( $processed_path !== $path );

        // Extract colors using existing pipeline
        try {
            difc_log( "[ATTACHMENT] Extracting colors from: {$processed_path}" );
            $colors = difc_get_dominant_colors( $processed_path );
            difc_log( "[ATTACHMENT] Extracted " . ( is_array( $colors ) ? count( $colors ) : 0 ) . " colors" );
        } catch ( Exception $e ) {
            difc_log( "[ATTACHMENT] Exception extracting colors: " . $e->getMessage() );
            if ( $is_temp_file && file_exists( $processed_path ) ) {
                @unlink( $processed_path );
            }
            return false;
        } catch ( Error $e ) {
            difc_log( "[ATTACHMENT] Fatal error extracting colors: " . $e->getMessage() );
            if ( $is_temp_file && file_exists( $processed_path ) ) {
                @unlink( $processed_path );
            }
            return false;
        }

        // Clean up temp file
        if ( $is_temp_file && file_exists( $processed_path ) ) {
            @unlink( $processed_path );
        }

        if ( ! is_array( $colors ) || empty( $colors ) ) {
            difc_log( "[ATTACHMENT] No colors detected for attachment {$attachment_id}" );
            return false;
        }

        // Validate and pad colors
        $validated_colors = [];
        foreach ( $colors as $color ) {
            if ( is_string( $color ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
                $validated_colors[] = $color;
            }
        }

        if ( empty( $validated_colors ) ) {
            difc_log( "[ATTACHMENT] No valid hex colors detected for attachment {$attachment_id}" );
            return false;
        }

        $colors = array_pad( $validated_colors, 10, '' );

        // Ensure ACF is available for palette fields
        if ( ! function_exists( 'update_field' ) ) {
            difc_log( "[ATTACHMENT] ACF not available, cannot save palette for attachment {$attachment_id}" );
            return false;
        }

        // Optionally warm ACF field groups for attachments
        if ( function_exists( 'acf_get_field_groups' ) ) {
            acf_get_field_groups( [ 'post_type' => 'attachment' ] );
        }

        // Save 10-color palette to attachment (same structure as difc_extract_and_save)
        try {
            for ( $i = 0; $i < 10; $i++ ) {
                $color_hex   = $colors[ $i ] ?? '';
                $color_index = $i + 1;
                $group_name  = "color_{$color_index}";
                $field_hex   = "image_col_{$color_index}_hex";
                $field_name  = "image_col_{$color_index}_name";

                if ( ! empty( $color_hex ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $color_hex ) ) {
                    $existing_name = function_exists( 'get_field' ) ? get_field( $field_name, $attachment_id ) : '';
                    $color_name    = empty( $existing_name ) ? difc_get_color_name( $color_hex ) : $existing_name;

                    $group_data = [
                        $field_hex  => $color_hex,
                        $field_name => $color_name,
                    ];

                    $group_result = update_field( $group_name, $group_data, $attachment_id );

                    if ( ! $group_result ) {
                        update_field( $field_hex, $color_hex, $attachment_id );
                        if ( empty( $existing_name ) ) {
                            update_field( $field_name, $color_name, $attachment_id );
                        }
                    }
                }
            }

            difc_log( "[ATTACHMENT] Saved 10-color palette to attachment {$attachment_id}" );
        } catch ( Exception $e ) {
            difc_log( "[ATTACHMENT] Error saving palette to attachment {$attachment_id}: " . $e->getMessage() );
        } catch ( Error $e ) {
            difc_log( "[ATTACHMENT] Fatal error saving palette to attachment {$attachment_id}: " . $e->getMessage() );
        }

        // Mark as processed
        update_post_meta( $attachment_id, '_difc_attachment_processed', time() );

        // Clear caches for this attachment
        difc_clear_post_cache( $attachment_id );

        return true;
    } catch ( Exception $e ) {
        difc_log( "[ATTACHMENT] Unexpected error for attachment {$attachment_id}: " . $e->getMessage() );
        return false;
    } catch ( Error $e ) {
        difc_log( "[ATTACHMENT] Unexpected fatal error for attachment {$attachment_id}: " . $e->getMessage() );
        return false;
    }
}

/**
 * New: Hook run when a new attachment is added (e.g., Media Library → Add New)
 *
 * @param int $attachment_id
 */
function difc_on_add_attachment( $attachment_id ) {
    // Only process images
    if ( ! wp_attachment_is_image( $attachment_id ) ) {
        return;
    }

    difc_log( "[ATTACHMENT] add_attachment hook for {$attachment_id}" );
    difc_extract_for_attachment( $attachment_id, false );
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
        if ( $memory_limit_bytes < 512 * 1024 * 1024 ) { // Less than 512MB
            @ini_set( 'memory_limit', '512M' );
            difc_log( "Temporarily increased memory limit from {$current_memory_limit} to 512M for image processing" );
        }
        
        difc_log( "Processing image: {$path}" );
        
        // Optional: Convert to sRGB and resize if ImageMagick is available (graceful fallback if not)
        // This preserves color profiles better than GD resizing
        $processed_path = difc_convert_to_srgb( $path, 1000 );
        $is_temp_file = ( $processed_path !== $path );
        
        if ( $is_temp_file ) {
            difc_log( "Using ImageMagick-processed image (color profile converted and/or resized)" );
        } else {
            difc_log( "Using original image, will resize with GD if needed" );
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
        
        // Ensure we have at least 10 colors (pad if needed)
        $colors = array_pad( $validated_colors, 10, '' );
        
        difc_log( "Detected colors: " . implode( ', ', array_slice( $colors, 0, 10 ) ) );
        
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
        
        // Save primary and secondary colors to post
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
        
        // Save 10-color palette to media attachment (fields are in groups: color_1, color_2, etc.)
        try {
            for ( $i = 0; $i < 10; $i++ ) {
                $color_hex = $colors[ $i ] ?? '';
                $color_index = $i + 1;
                $group_name = "color_{$color_index}";
                $field_hex = "image_col_{$color_index}_hex";
                $field_name = "image_col_{$color_index}_name";
                
                if ( ! empty( $color_hex ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $color_hex ) ) {
                    // Generate color name if not already set
                    $existing_name = get_field( $field_name, $attachment_id );
                    $color_name = empty( $existing_name ) ? difc_get_color_name( $color_hex ) : $existing_name;
                    
                    // Update group field with both hex and name
                    $group_data = [
                        $field_hex => $color_hex,
                        $field_name => $color_name,
                    ];
                    
                    $group_result = update_field( $group_name, $group_data, $attachment_id );
                    
                    // Fallback: try updating sub-fields directly
                    if ( ! $group_result ) {
                        update_field( $field_hex, $color_hex, $attachment_id );
                        if ( empty( $existing_name ) ) {
                            update_field( $field_name, $color_name, $attachment_id );
                        }
                    }
                }
            }
            difc_log( "Saved 10-color palette to attachment {$attachment_id}" );
        } catch ( Exception $e ) {
            difc_log( "Error saving palette to attachment {$attachment_id}: " . $e->getMessage() );
            // Don't fail the whole operation if palette save fails
        }
        
        // Clear all caches to ensure fresh data is available
        difc_clear_post_cache( $post_id );
        difc_clear_post_cache( $attachment_id ); // Also clear cache for attachment
        
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
 * Optional: Convert image to sRGB color space and resize using ImageMagick CLI
 * This preserves color profiles better than GD resizing
 * Returns original path if ImageMagick is not available (graceful fallback)
 * 
 * @param string $path Original image path
 * @param int $max_dimension Maximum width or height for resizing (default 1000px)
 * @return string Path to converted image or original if conversion not possible
 */
function difc_convert_to_srgb( $path, $max_dimension = 1000 ) {
    // Check if ImageMagick CLI is available (silent check, no errors if missing)
    $magick_check = @shell_exec( 'which magick 2>/dev/null' );
    if ( empty( $magick_check ) || trim( $magick_check ) === '' ) {
        // ImageMagick not available, return original (will be resized by GD later)
        difc_log( "ImageMagick CLI not available, will use GD for resizing" );
        return $path;
    }
    
    // Get image dimensions to check if resizing is needed
    $dimensions = @shell_exec( "magick identify -format '%wx%h' " . escapeshellarg( $path ) . " 2>/dev/null" );
    if ( empty( $dimensions ) ) {
        difc_log( "Could not get image dimensions, using original" );
        return $path;
    }
    
    list( $width, $height ) = explode( 'x', trim( $dimensions ) );
    $width = (int) $width;
    $height = (int) $height;
    $needs_resize = ( $width > $max_dimension || $height > $max_dimension );
    
    // Check for color profile
    $profile_check = @shell_exec( "magick identify -format '%[profiles:icc]' " . escapeshellarg( $path ) . " 2>/dev/null" );
    $has_profile = ! empty( $profile_check ) && trim( $profile_check ) !== '';
    
    // If no color profile and no resize needed, return original
    if ( ! $has_profile && ! $needs_resize ) {
        difc_log( "No color profile and image is already small enough, using original" );
        return $path;
    }
    
    // Create temporary file for converted image
    $temp_path = sys_get_temp_dir() . '/difc_' . md5( $path ) . '_' . time() . '.jpg';
    
    // Build ImageMagick command
    $command_parts = [ "magick convert", escapeshellarg( $path ) ];
    
    // Convert to sRGB if color profile exists
    if ( $has_profile ) {
        $command_parts[] = "-colorspace sRGB";
        difc_log( "Color profile detected: {$profile_check}, converting to sRGB" );
    }
    
    // Resize if needed (preserves color space)
    if ( $needs_resize ) {
        // Calculate new dimensions maintaining aspect ratio
        if ( $width > $height ) {
            $new_size = $max_dimension . 'x';
        } else {
            $new_size = 'x' . $max_dimension;
        }
        $command_parts[] = "-resize {$new_size}";
        difc_log( "Resizing from {$width}x{$height} to max {$max_dimension}px using ImageMagick (preserves color profile)" );
    }
    
    $command_parts[] = escapeshellarg( $temp_path );
    $command = implode( ' ', $command_parts ) . " 2>/dev/null";
    
    $output = @shell_exec( $command );
    
    if ( file_exists( $temp_path ) && filesize( $temp_path ) > 0 ) {
        $action = [];
        if ( $has_profile ) $action[] = "converted to sRGB";
        if ( $needs_resize ) $action[] = "resized";
        difc_log( "ImageMagick: Successfully " . implode( " and ", $action ) . ": {$temp_path}" );
        return $temp_path;
    } else {
        difc_log( "ImageMagick conversion failed, using original image" );
        return $path; // Fallback to original
    }
}

/**
 * Resize image to a maximum dimension to reduce memory usage
 * Color extraction doesn't need full resolution, so we can safely resize large images
 * 
 * @param resource $img GD image resource
 * @param int $max_dimension Maximum width or height (default 1000px)
 * @return resource|false Resized image resource or false on failure
 */
function difc_resize_image_for_processing( $img, $max_dimension = 1000 ) {
    $width  = imagesx( $img );
    $height = imagesy( $img );
    
    // If image is already smaller than max dimension, return as-is
    if ( $width <= $max_dimension && $height <= $max_dimension ) {
        return $img;
    }
    
    // Calculate new dimensions maintaining aspect ratio
    if ( $width > $height ) {
        $new_width  = $max_dimension;
        $new_height = (int) ( $height * ( $max_dimension / $width ) );
    } else {
        $new_height = $max_dimension;
        $new_width  = (int) ( $width * ( $max_dimension / $height ) );
    }
    
    difc_log( "Resizing image from {$width}x{$height} to {$new_width}x{$new_height} to reduce memory usage" );
    
    // Create resized image
    $resized = imagecreatetruecolor( $new_width, $new_height );
    if ( ! $resized ) {
        difc_log( "Failed to create resized image resource" );
        return $img; // Return original on failure
    }
    
    // Preserve transparency for PNG/GIF
    imagealphablending( $resized, false );
    imagesavealpha( $resized, true );
    $transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
    imagefill( $resized, 0, 0, $transparent );
    imagealphablending( $resized, true );
    
    // Resize with high quality
    $success = imagecopyresampled( $resized, $img, 0, 0, 0, 0, $new_width, $new_height, $width, $height );
    
    if ( ! $success ) {
        difc_log( "Failed to resize image" );
        imagedestroy( $resized );
        return $img; // Return original on failure
    }
    
    // Destroy original to free memory
    imagedestroy( $img );
    
    return $resized;
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

    // Resize large images to reduce memory usage if not already resized by ImageMagick
    // (ImageMagick resizing preserves color profiles better, but GD fallback is fine)
    $img = difc_resize_image_for_processing( $img, 1000 );
    if ( ! $img ) {
        difc_log( "Failed to resize image for processing" );
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
        
        // If 60%+ of corner pixels match, we detected a uniform background
        // But DON'T return early - continue with full extraction to get foreground colors too
        if ( $most_common_pct >= 60 ) {
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
            
            $bg_r = $exact_color['color'][0];
            $bg_g = $exact_color['color'][1];
            $bg_b = $exact_color['color'][2];
            
            $bg_color = sprintf( '#%02x%02x%02x', $bg_r, $bg_g, $bg_b );
            difc_log( "Detected uniform background: {$bg_color} (from {$most_common_pct}% of corner pixels) - continuing with full extraction" );
            // Continue to full extraction instead of returning early
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
        
        // If the most common color represents 30%+ of edge samples, we detected a uniform background
        // But DON'T return early - continue with full extraction to get foreground colors too
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
            $bg_r = (int) ( $sum_r / count( $exact_samples ) );
            $bg_g = (int) ( $sum_g / count( $exact_samples ) );
            $bg_b = (int) ( $sum_b / count( $exact_samples ) );
            
            $bg_color = sprintf( '#%02x%02x%02x', $bg_r, $bg_g, $bg_b );
            difc_log( "Detected uniform background color: {$bg_color} (from {$most_common_pct}% of edge samples) - continuing with full extraction" );
            // Continue to full extraction instead of returning early
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
            
            // Debug: Log pink-like colors (hue around 0.9-1.0 or 0.0-0.1, high saturation)
            if ( DIFC_DEBUG && ( ( $h >= 0.9 || $h <= 0.1 ) && $s > 0.5 ) ) {
                $hex = sprintf( '#%02x%02x%02x', $r, $g, $b );
                $h_str = number_format( $h, 2 );
                $s_str = number_format( $s, 2 );
                $l_str = number_format( $l, 2 );
                $sal_str = number_format( $salience, 2 );
                difc_log( "Sampled pink/magenta-like color: {$hex} at ({$x}, {$y}), HSL: H={$h_str}, S={$s_str}, L={$l_str}, salience={$sal_str}" );
            }
        }
    }

    imagedestroy( $img );

    if ( empty( $weighted_pixels ) ) {
        difc_log( "No valid pixels sampled after salience filtering" );
        return [];
    }

    difc_log( "Sampled " . count( $weighted_pixels ) . " salient pixels" );
    
    // Debug: Count pink-like pixels
    $pink_count = 0;
    foreach ( $weighted_pixels as $px ) {
        $h = $px['hsl'][0];
        $s = $px['hsl'][1];
        if ( ( $h >= 0.85 || $h <= 0.15 ) && $s > 0.5 ) {
            $pink_count++;
        }
    }
    error_log( '[DIFC] Pink/magenta-like pixels sampled: ' . $pink_count . ' out of ' . count( $weighted_pixels ) );

    // ── 3. Weighted color clustering with salience ───────────────────────
    // Preserve distinct colors by checking hue difference before clustering
    $clusters = [];

    foreach ( $weighted_pixels as $pixel_data ) {
        $px = $pixel_data['rgb'];
        $weight = $pixel_data['salience'];
        $matched = false;

        foreach ( $clusters as $i => &$cluster ) {
            $pixel_hsl = $pixel_data['hsl'];
            $cluster_hsl = $cluster['hsl_center'];
            
            // Calculate hue difference first - don't cluster very distinct hues
            $h_diff = abs( $pixel_hsl[0] - $cluster_hsl[0] );
            if ( $h_diff > 0.5 ) {
                $h_diff = 1.0 - $h_diff; // Wrap around the color wheel
            }
            
            // If hues are very different (>0.15 = ~54 degrees), don't cluster them
            // This preserves distinct colors like pink vs lavender
            if ( $h_diff > 0.15 ) {
                continue; // Skip this cluster, try next one
            }
            
            // Use perceptual distance (HSL-based) for clustering
            $distance = difc_perceptual_color_distance( $pixel_hsl, $cluster_hsl );
            if ( $distance < DIFC_CLUSTER_THRESHOLD ) {
                // Weighted merge: salience affects contribution
                $total_weight = $cluster['total_weight'] + $weight;
                
                // Update HSL center first (weighted average in perceptual space)
                $cluster['hsl_center'] = [
                    difc_weighted_hue_average( $cluster_hsl[0], $pixel_hsl[0], $cluster['total_weight'], $weight ),
                    ( $cluster_hsl[1] * $cluster['total_weight'] + $pixel_hsl[1] * $weight ) / $total_weight,
                    ( $cluster_hsl[2] * $cluster['total_weight'] + $pixel_hsl[2] * $weight ) / $total_weight,
                ];
                
                // Convert HSL center back to RGB (perceptual averaging avoids muddy colors)
                $rgb_from_hsl = difc_hsl_to_rgb( $cluster['hsl_center'][0], $cluster['hsl_center'][1], $cluster['hsl_center'][2] );
                $cluster['rgb_center'] = $rgb_from_hsl;
                
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
    
    // Debug: Log all clusters before merging
    difc_log( "Formed " . count( $clusters ) . " initial clusters:" );
    $pink_clusters = [];
    foreach ( $clusters as $idx => $cluster ) {
        $rgb = $cluster['rgb_center'];
        $hsl = $cluster['hsl_center'];
        $hex = sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
        $h = number_format( $hsl[0], 2 );
        $s = number_format( $hsl[1], 2 );
        $l = number_format( $hsl[2], 2 );
        $w = number_format( $cluster['total_weight'], 2 );
        difc_log( "  Cluster {$idx}: {$hex} (HSL: H={$h}, S={$s}, L={$l}), weight={$w}, count={$cluster['count']}" );
        
        // Track pink/magenta clusters
        if ( ( $hsl[0] >= 0.85 || $hsl[0] <= 0.15 ) && $hsl[1] > 0.5 ) {
            $pink_clusters[] = [ 'idx' => $idx, 'hex' => $hex, 'weight' => $cluster['total_weight'], 'sat' => $hsl[1] ];
        }
    }
    if ( ! empty( $pink_clusters ) ) {
        error_log( '[DIFC] Found ' . count( $pink_clusters ) . ' pink/magenta clusters: ' . json_encode( $pink_clusters ) );
    } else {
        error_log( '[DIFC] WARNING: No pink/magenta clusters found!' );
    }

    // ── 3.5. Limit clusters and merge closest if needed (K-means-like approach) ─────────────
    // When limit is exceeded, merge the two closest clusters, but preserve distinct colors
    // Distinct colors (high hue difference) should not be merged even if close in other dimensions
    while ( count( $clusters ) > DIFC_MAX_CLUSTERS ) {
        $min_distance = PHP_FLOAT_MAX;
        $merge_i = -1;
        $merge_j = -1;
        
        // Find the two closest clusters, but avoid merging very distinct colors
        for ( $i = 0; $i < count( $clusters ); $i++ ) {
            for ( $j = $i + 1; $j < count( $clusters ); $j++ ) {
                $hsl1 = $clusters[$i]['hsl_center'];
                $hsl2 = $clusters[$j]['hsl_center'];
                
                // Calculate hue difference
                $h_diff = abs( $hsl1[0] - $hsl2[0] );
                if ( $h_diff > 0.5 ) {
                    $h_diff = 1.0 - $h_diff; // Wrap around the color wheel
                }
                
                // Don't merge colors with very different hues (more than 0.2 = ~72 degrees)
                // This preserves distinct colors like pink vs lavender
                if ( $h_diff > 0.2 ) {
                    continue; // Skip merging very distinct hues
                }
                
                $distance = difc_perceptual_color_distance( $hsl1, $hsl2 );
                if ( $distance < $min_distance ) {
                    $min_distance = $distance;
                    $merge_i = $i;
                    $merge_j = $j;
                }
            }
        }
        
        // If no similar colors found (all are distinct), merge the two smallest clusters instead
        if ( $merge_i < 0 || $merge_j < 0 ) {
            // Find the two clusters with lowest total_weight (smallest/least important)
            $min_weight = PHP_FLOAT_MAX;
            $min_weight_i = -1;
            $min_weight_j = -1;
            
            for ( $i = 0; $i < count( $clusters ); $i++ ) {
                for ( $j = $i + 1; $j < count( $clusters ); $j++ ) {
                    $combined_weight = $clusters[$i]['total_weight'] + $clusters[$j]['total_weight'];
                    if ( $combined_weight < $min_weight ) {
                        $min_weight = $combined_weight;
                        $min_weight_i = $i;
                        $min_weight_j = $j;
                    }
                }
            }
            
            if ( $min_weight_i >= 0 && $min_weight_j >= 0 ) {
                $merge_i = $min_weight_i;
                $merge_j = $min_weight_j;
                difc_log( "Merging two smallest clusters (all colors are distinct)" );
            } else {
                // Fallback: just merge first two if we can't find anything
                if ( count( $clusters ) > 1 ) {
                    $merge_i = 0;
                    $merge_j = 1;
                } else {
                    break;
                }
            }
        }
        
        if ( $merge_i >= 0 && $merge_j >= 0 ) {
            // Merge cluster $merge_j into cluster $merge_i
            $cluster_i = &$clusters[$merge_i];
            $cluster_j = $clusters[$merge_j];
            
            $total_weight = $cluster_i['total_weight'] + $cluster_j['total_weight'];
            $weight_i = $cluster_i['total_weight'] / $total_weight;
            $weight_j = $cluster_j['total_weight'] / $total_weight;
            
            // Merge HSL centers (weighted average in perceptual space)
            $cluster_i['hsl_center'] = [
                difc_weighted_hue_average( $cluster_i['hsl_center'][0], $cluster_j['hsl_center'][0], $cluster_i['total_weight'], $cluster_j['total_weight'] ),
                $cluster_i['hsl_center'][1] * $weight_i + $cluster_j['hsl_center'][1] * $weight_j,
                $cluster_i['hsl_center'][2] * $weight_i + $cluster_j['hsl_center'][2] * $weight_j,
            ];
            
            // Convert HSL center back to RGB (perceptual averaging)
            $rgb_from_hsl = difc_hsl_to_rgb( $cluster_i['hsl_center'][0], $cluster_i['hsl_center'][1], $cluster_i['hsl_center'][2] );
            $cluster_i['rgb_center'] = $rgb_from_hsl;
            
            $cluster_i['total_weight'] = $total_weight;
            $cluster_i['count'] += $cluster_j['count'];
            
            // Remove merged cluster
            array_splice( $clusters, $merge_j, 1 );
            unset( $cluster_i );
            
            difc_log( "Merged clusters (now " . count( $clusters ) . " clusters)" );
        } else {
            // Should not happen, but break to avoid infinite loop
            break;
        }
    }

    // ── 4. Sort by salience-weighted dominance with chroma boost ───────────────────────────
    // Calculate combined score: total_weight * (1 + avg_chroma * boost_factor)
    // This matches Okmain's approach of considering chroma (saturation) as a prominence factor
    foreach ( $clusters as &$cluster ) {
        $avg_chroma = $cluster['hsl_center'][1]; // Saturation is chroma in HSL
        $hue = $cluster['hsl_center'][0];
        
        // Extra boost for vibrant colors (pink/magenta, red, blue, etc.)
        // Pink/magenta hues are around 0.9-1.0 or 0.0-0.1
        $hue_boost = 1.0;
        if ( $avg_chroma > 0.5 ) { // Only boost if already saturated
            // Boost pink/magenta/red hues (visually prominent)
            if ( ( $hue >= 0.85 || $hue <= 0.15 ) && $avg_chroma > 0.6 ) {
                $hue_boost = 1.3; // 30% extra boost for vibrant pinks/magentas
            }
        }
        
        $cluster['prominence_score'] = $cluster['total_weight'] * ( 1.0 + $avg_chroma * DIFC_CHROMA_SORT_BOOST ) * $hue_boost;
    }
    unset( $cluster );
    
    usort( $clusters, function( $a, $b ) {
        // Sort by prominence score (salience weight + chroma boost)
        // Higher chroma (saturation) gets a boost, matching Okmain's approach
        $score_diff = $b['prominence_score'] <=> $a['prominence_score'];
        
        // If scores are close (within 20%), prefer higher saturation
        if ( abs( $a['prominence_score'] - $b['prominence_score'] ) < ( max( $a['prominence_score'], $b['prominence_score'] ) * 0.2 ) ) {
            $sat_diff = $b['hsl_center'][1] <=> $a['hsl_center'][1];
            if ( $sat_diff !== 0 ) {
                return $sat_diff; // Prefer higher saturation when scores are close
            }
        }
        
        return $score_diff;
    } );

    if ( empty( $clusters ) ) {
        return [];
    }
    
    // Debug: Log final clusters after sorting
    difc_log( "Final clusters after sorting (top 10):" );
    foreach ( array_slice( $clusters, 0, 10 ) as $idx => $cluster ) {
        $rgb = $cluster['rgb_center'];
        $hsl = $cluster['hsl_center'];
        $hex = sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
        $prom = number_format( $cluster['prominence_score'], 2 );
        $w = number_format( $cluster['total_weight'], 2 );
        difc_log( "  #{$idx}: {$hex} (prominence={$prom}, weight={$w})" );
    }

    // ── 5. Select primary and secondary colors ─────────
    $primary = $clusters[0];
    $results = [];
    
    // Primary color (most salient/dominant color from image)
    // This is the first color in the results array and will be saved to custom_colour field
    $results[] = sprintf(
        '#%02x%02x%02x',
        $primary['rgb_center'][0],
        $primary['rgb_center'][1],
        $primary['rgb_center'][2]
    );

    // Secondary color: Always black or white based on maximum contrast with primary
    // This ensures optimal legibility for text/background use cases
    $primary_hsl = $primary['hsl_center'];
    $secondary_color = difc_get_contrast_fallback( $primary_hsl );
    $results[] = $secondary_color;
    difc_log( "Secondary color set to {$secondary_color} for maximum contrast with primary" );

    // Extract up to 10 colors for palette (extend results array)
    // Colors 1 and 2 are already primary and secondary
    
    // First, add top clusters by prominence score
    $palette_colors = array_slice( $clusters, 0, 10 );
    $added_colors = [];
    
    for ( $i = 2; $i < count( $palette_colors ); $i++ ) {
        $cluster = $palette_colors[ $i ];
        $hex = sprintf( '#%02x%02x%02x', $cluster['rgb_center'][0], $cluster['rgb_center'][1], $cluster['rgb_center'][2] );
        $results[] = $hex;
        $added_colors[] = $hex;
    }
    
    // Ensure we include high-saturation colors even if they have lower total weight
    // This catches visually prominent colors that might be in smaller areas
    if ( count( $results ) < 10 ) {
        // Sort remaining clusters by saturation (descending) to find vibrant colors
        $remaining_clusters = array_slice( $clusters, count( $palette_colors ) );
        usort( $remaining_clusters, function( $a, $b ) {
            // Sort by saturation first, then by total weight
            $sat_diff = $b['hsl_center'][1] <=> $a['hsl_center'][1];
            if ( $sat_diff !== 0 ) {
                return $sat_diff;
            }
            return $b['total_weight'] <=> $a['total_weight'];
        } );
        
        // Add high-saturation colors that aren't already included
        foreach ( $remaining_clusters as $cluster ) {
            if ( count( $results ) >= 10 ) {
                break;
            }
            
            $hex = sprintf( '#%02x%02x%02x', $cluster['rgb_center'][0], $cluster['rgb_center'][1], $cluster['rgb_center'][2] );
            
            // Only add if not already in results and has good saturation
            if ( ! in_array( $hex, $added_colors ) && $cluster['hsl_center'][1] > 0.4 ) {
                $results[] = $hex;
                $added_colors[] = $hex;
                difc_log( "Added high-saturation color to palette: {$hex} (saturation: " . number_format( $cluster['hsl_center'][1], 2 ) . ", weight: " . number_format( $cluster['total_weight'], 2 ) . ")" );
            }
        }
    }
    
    // Pad to 10 colors if we have fewer
    while ( count( $results ) < 10 ) {
        // Use empty string for missing colors (will be handled by validation)
        $results[] = '';
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
 * Convert HSL (0-1, 0-1, 0-1) to RGB (0-255, 0-255, 0-255)
 * This ensures perceptual color averaging in HSL space before converting back to RGB
 */
function difc_hsl_to_rgb( $h, $s, $l ) {
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
    
    // Convert to 0-255 range and clamp
    $r = max( 0, min( 255, round( $r * 255 ) ) );
    $g = max( 0, min( 255, round( $g * 255 ) ) );
    $b = max( 0, min( 255, round( $b * 255 ) ) );
    
    return [ $r, $g, $b ];
}

/**
 * Calculate color salience score based on saturation, luminance, and position
 * Uses distance-based position weighting (like Okmain) instead of binary center region
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

    // Distance-based position weighting (like Okmain's center-weighted mask)
    // Calculate distance from center of image
    $center_x = ( $center_x_min + $center_x_max ) / 2;
    $center_y = ( $center_y_min + $center_y_max ) / 2;
    $max_distance = sqrt( pow( $center_x_max - $center_x_min, 2 ) + pow( $center_y_max - $center_y_min, 2 ) ) / 2;
    
    if ( $max_distance > 0 ) {
        $distance_from_center = sqrt( pow( $x - $center_x, 2 ) + pow( $y - $center_y, 2 ) );
        $normalized_distance = min( 1.0, $distance_from_center / $max_distance );
        
        // Create smooth gradient: pixels close to center get full weight, weight decreases with distance
        // Use a smooth curve that plateaus for central pixels (like Okmain's mask)
        // Formula: weight = 1 + (max_weight - 1) * (1 - normalized_distance)^2
        // This gives full weight at center, smoothly decreasing to 1.0 at edges
        $position_weight = 1.0 + ( DIFC_CENTER_WEIGHT - 1.0 ) * pow( 1.0 - $normalized_distance, 2 );
        $salience *= $position_weight;
    } else {
        // Fallback: if max_distance is 0, use binary center check
        if ( $x >= $center_x_min && $x <= $center_x_max && 
             $y >= $center_y_min && $y <= $center_y_max ) {
            $salience *= DIFC_CENTER_WEIGHT;
        }
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

/**
 * Add meta box for color re-analysis
 */
function difc_add_meta_box() {
    add_meta_box(
        'difc_reanalyze_colors',
        __( 'Featured Image Colors', 'difc' ),
        'difc_reanalyze_meta_box_callback',
        DIFC_POST_TYPE,
        'side',
        'default'
    );
}

/**
 * Meta box callback - displays current colors and re-analyze button
 */
function difc_reanalyze_meta_box_callback( $post ) {
    $thumbnail_id = get_post_thumbnail_id( $post->ID );
    $primary_color = function_exists( 'get_field' ) ? get_field( DIFC_PRIMARY_FIELD, $post->ID ) : '';
    $secondary_color = function_exists( 'get_field' ) ? get_field( DIFC_SECONDARY_FIELD, $post->ID ) : '';
    
    wp_nonce_field( 'difc_reanalyze_' . $post->ID, 'difc_reanalyze_nonce' );
    ?>
    <div id="difc-reanalyze-container">
        <?php if ( $thumbnail_id ) : ?>
            <p>
                <strong><?php _e( 'Current Colors:', 'difc' ); ?></strong>
            </p>
            <div style="margin: 10px 0;">
                <?php if ( $primary_color ) : ?>
                    <p>
                        <label><?php _e( 'Primary:', 'difc' ); ?></label><br>
                        <span style="display: inline-block; width: 30px; height: 30px; background-color: <?php echo esc_attr( $primary_color ); ?>; border: 1px solid #ccc; vertical-align: middle; margin-right: 5px;"></span>
                        <code><?php echo esc_html( $primary_color ); ?></code>
                    </p>
                <?php else : ?>
                    <p style="color: #d63638;"><?php _e( 'Primary color not set', 'difc' ); ?></p>
                <?php endif; ?>
                
                <?php if ( $secondary_color ) : ?>
                    <p>
                        <label><?php _e( 'Secondary:', 'difc' ); ?></label><br>
                        <span style="display: inline-block; width: 30px; height: 30px; background-color: <?php echo esc_attr( $secondary_color ); ?>; border: 1px solid #ccc; vertical-align: middle; margin-right: 5px;"></span>
                        <code><?php echo esc_html( $secondary_color ); ?></code>
                    </p>
                <?php else : ?>
                    <p style="color: #d63638;"><?php _e( 'Secondary color not set', 'difc' ); ?></p>
                <?php endif; ?>
            </div>
            
            <p>
                <button type="button" id="difc-reanalyze-btn" class="button button-secondary" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    <?php _e( 'Re-analyze Colors', 'difc' ); ?>
                </button>
            </p>
            <div id="difc-reanalyze-message" style="margin-top: 10px; display: none;"></div>
        <?php else : ?>
            <p style="color: #d63638;">
                <?php _e( 'Please set a featured image first.', 'difc' ); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Enqueue admin scripts for the re-analyze button
 */
function difc_enqueue_admin_scripts( $hook ) {
    // Only load on post edit screens for event post type
    if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ] ) ) {
        return;
    }
    
    global $post;
    if ( ! $post || get_post_type( $post->ID ) !== DIFC_POST_TYPE ) {
        return;
    }
    
    // Add CSS for spinner animation
    wp_add_inline_style( 'wp-admin', '
        @keyframes difc-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .dashicons.difc-spinning {
            animation: difc-spin 1s linear infinite;
        }
    ' );
    
    // Add script in footer to ensure jQuery is loaded
    add_action( 'admin_footer', 'difc_add_reanalyze_script' );
}

/**
 * Add inline script for re-analyze button (called in admin_footer)
 */
function difc_add_reanalyze_script() {
    global $post;
    if ( ! $post || get_post_type( $post->ID ) !== DIFC_POST_TYPE ) {
        return;
    }
    
    $nonce = wp_create_nonce( 'difc_reanalyze_ajax' );
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('#difc-reanalyze-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $msg = $('#difc-reanalyze-message');
            var postId = $btn.data('post-id');
            
            // Disable button and show loading
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update difc-spinning" style="vertical-align: middle;"></span> <?php echo esc_js( __( 'Analyzing...', 'difc' ) ); ?>');
            $msg.hide().removeClass('notice-error notice-success').html('');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'difc_reanalyze_colors',
                    post_id: postId,
                    nonce: '<?php echo esc_js( $nonce ); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $msg.addClass('notice notice-success is-dismissible').html('<p>' + response.data.message + '</p>').show();
                        // Reload page after 1.5 seconds to show updated colors
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $msg.addClass('notice notice-error is-dismissible').html('<p>' + (response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'An error occurred.', 'difc' ) ); ?>') + '</p>').show();
                        $btn.prop('disabled', false);
                        $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> <?php echo esc_js( __( 'Re-analyze Colors', 'difc' ) ); ?>');
                    }
                },
                error: function() {
                    $msg.addClass('notice notice-error is-dismissible').html('<p><?php echo esc_js( __( 'Network error. Please try again.', 'difc' ) ); ?></p>').show();
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> <?php echo esc_js( __( 'Re-analyze Colors', 'difc' ) ); ?>');
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Generate a descriptive color name from hex value
 * 
 * @param string $hex Hex color value (e.g., #FF5733)
 * @return string Color name (e.g., "Sunset Orange", "Sky Blue")
 */
function difc_get_color_name( $hex ) {
    if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $hex ) ) {
        return '';
    }
    
    // Remove # and convert to RGB
    $hex = str_replace( '#', '', $hex );
    $r = hexdec( substr( $hex, 0, 2 ) );
    $g = hexdec( substr( $hex, 2, 2 ) );
    $b = hexdec( substr( $hex, 4, 2 ) );
    
    // Convert to HSL for better color naming
    $hsl = difc_rgb_to_hsl( $r, $g, $b );
    $h = $hsl[0]; // 0-1
    $s = $hsl[1]; // 0-1
    $l = $hsl[2]; // 0-1
    
    // Determine base hue name
    $hue_names = [
        [ 0.0, 0.04, 'Red' ],
        [ 0.04, 0.12, 'Orange' ],
        [ 0.12, 0.20, 'Yellow' ],
        [ 0.20, 0.35, 'Green' ],
        [ 0.35, 0.55, 'Cyan' ],
        [ 0.55, 0.70, 'Blue' ],
        [ 0.70, 0.85, 'Purple' ],
        [ 0.85, 0.95, 'Pink' ],
        [ 0.95, 1.0, 'Red' ],
    ];
    
    $base_hue = 'Gray';
    foreach ( $hue_names as $range ) {
        if ( $h >= $range[0] && $h < $range[1] ) {
            $base_hue = $range[2];
            break;
        }
    }
    
    // If saturation is very low, it's a gray/neutral
    if ( $s < 0.1 ) {
        if ( $l < 0.2 ) {
            return 'Charcoal';
        } elseif ( $l < 0.4 ) {
            return 'Dark Gray';
        } elseif ( $l < 0.6 ) {
            return 'Gray';
        } elseif ( $l < 0.8 ) {
            return 'Light Gray';
        } else {
            return 'Off White';
        }
    }
    
    // Determine lightness modifier
    $lightness_modifier = '';
    if ( $l < 0.2 ) {
        $lightness_modifier = 'Dark ';
    } elseif ( $l < 0.35 ) {
        $lightness_modifier = 'Deep ';
    } elseif ( $l > 0.8 ) {
        $lightness_modifier = 'Light ';
    } elseif ( $l > 0.65 ) {
        $lightness_modifier = 'Bright ';
    }
    
    // Determine saturation modifier
    $saturation_modifier = '';
    if ( $s > 0.7 ) {
        $saturation_modifier = 'Vivid ';
    } elseif ( $s < 0.3 ) {
        $saturation_modifier = 'Muted ';
    }
    
    // Special color names for common combinations
    $special_names = [
        // Blues
        [ 0.55, 0.70, 0.4, 0.7, 0.3, 0.6, 'Sky Blue' ],
        [ 0.55, 0.70, 0.5, 0.9, 0.2, 0.4, 'Ocean Blue' ],
        [ 0.55, 0.70, 0.6, 0.9, 0.1, 0.3, 'Navy Blue' ],
        // Greens
        [ 0.20, 0.35, 0.4, 0.7, 0.3, 0.5, 'Forest Green' ],
        [ 0.20, 0.35, 0.5, 0.8, 0.4, 0.7, 'Emerald Green' ],
        [ 0.20, 0.35, 0.6, 0.9, 0.5, 0.8, 'Lime Green' ],
        // Reds/Oranges
        [ 0.0, 0.12, 0.4, 0.8, 0.4, 0.7, 'Sunset Orange' ],
        [ 0.0, 0.04, 0.4, 0.8, 0.3, 0.6, 'Crimson Red' ],
        [ 0.04, 0.12, 0.5, 0.9, 0.5, 0.8, 'Golden Yellow' ],
        // Purples/Pinks
        [ 0.70, 0.85, 0.4, 0.7, 0.3, 0.6, 'Royal Purple' ],
        [ 0.85, 0.95, 0.5, 0.8, 0.5, 0.8, 'Rose Pink' ],
    ];
    
    foreach ( $special_names as $special ) {
        if ( $h >= $special[0] && $h < $special[1] && 
             $s >= $special[2] && $s < $special[3] && 
             $l >= $special[4] && $l < $special[5] ) {
            return $special[6];
        }
    }
    
    // Build descriptive name
    $name = $saturation_modifier . $lightness_modifier . $base_hue;
    
    return trim( $name );
}

/**
 * AJAX handler for re-analyzing colors
 */
function difc_ajax_reanalyze_colors() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'difc_reanalyze_ajax' ) ) {
        wp_send_json_error( [ 'message' => __( 'Security check failed.', 'difc' ) ] );
        return;
    }
    
    // Check permissions
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'difc' ) ] );
        return;
    }
    
    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    
    if ( ! $post_id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'difc' ) ] );
        return;
    }
    
    // Verify post type
    if ( get_post_type( $post_id ) !== DIFC_POST_TYPE ) {
        wp_send_json_error( [ 'message' => __( 'Invalid post type.', 'difc' ) ] );
        return;
    }
    
    // Get thumbnail ID
    $thumbnail_id = get_post_thumbnail_id( $post_id );
    if ( ! $thumbnail_id ) {
        wp_send_json_error( [ 'message' => __( 'No featured image set for this post.', 'difc' ) ] );
        return;
    }
    
    // Force re-analysis
    $result = difc_extract_and_save( $post_id, $thumbnail_id, true );
    
    if ( $result ) {
        // Clear caches to ensure fresh data
        difc_clear_post_cache( $post_id );
        
        // Get the new colors
        $primary_color = function_exists( 'get_field' ) ? get_field( DIFC_PRIMARY_FIELD, $post_id ) : '';
        $secondary_color = function_exists( 'get_field' ) ? get_field( DIFC_SECONDARY_FIELD, $post_id ) : '';
        
        $message = __( 'Colors successfully re-analyzed!', 'difc' );
        if ( $primary_color && $secondary_color ) {
            $message .= ' ' . sprintf( __( 'Primary: %s, Secondary: %s', 'difc' ), $primary_color, $secondary_color );
        }
        
        wp_send_json_success( [ 'message' => $message ] );
    } else {
        wp_send_json_error( [ 'message' => __( 'Failed to extract colors. Please check the error logs.', 'difc' ) ] );
    }
}

/**
 * Add re-analyze button to attachment edit screen
 */
function difc_add_attachment_color_button( $fields, $post ) {
    // Only show for image attachments
    if ( ! wp_attachment_is_image( $post->ID ) ) {
        return $fields;
    }
    
    $attachment_id = $post->ID;
    $fields['difc_extract_colors'] = [
        'label' => __( 'Color Palette', 'difc' ),
        'input' => 'html',
        'html' => sprintf(
            '<div id="difc-attachment-color-container" style="margin: 10px 0;">
                <button type="button" id="difc-extract-attachment-colors-btn" class="button button-secondary" data-attachment-id="%d" onclick="console.log(\'DIFC: Direct click on button, ID: %d\'); return false;">
                    <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                    %s
                </button>
                <div id="difc-attachment-color-message" style="margin-top: 10px; display: none;"></div>
            </div>',
            $attachment_id,
            $attachment_id,
            esc_html__( 'Extract Color Palette', 'difc' )
        ),
    ];
    
    return $fields;
}

/**
 * Enqueue scripts for attachment color extraction
 */
function difc_enqueue_attachment_scripts( $hook ) {
    // Load on all admin pages (media modals can be opened from anywhere)
    if ( ! is_admin() ) {
        return;
    }
    
    // Add CSS for spinner animation
    wp_add_inline_style( 'wp-admin', '
        @keyframes difc-spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .dashicons.difc-spinning {
            animation: difc-spin 1s linear infinite;
        }
    ' );
    
    // Add script in footer (always, so it works in media modals)
    add_action( 'admin_footer', 'difc_add_attachment_extract_script' );
}

/**
 * Add inline script for attachment color extraction button
 */
function difc_add_attachment_extract_script() {
    $nonce = wp_create_nonce( 'difc_extract_attachment_colors' );
    ?>
    <script type="text/javascript">
    console.log('DIFC: Script loaded');
    (function($) {
        'use strict';
        
        var nonce = '<?php echo esc_js( $nonce ); ?>';
        console.log('DIFC: Nonce created:', nonce ? 'yes' : 'no');
        
        // Handler for button click
        function handleExtractColors(attachmentId, $btn, $msg) {
            // Disable button and show loading
            $btn.prop('disabled', true);
            $btn.html('<span class="dashicons dashicons-update difc-spinning" style="vertical-align: middle;"></span> <?php echo esc_js( __( 'Extracting colors...', 'difc' ) ); ?>');
            if ($msg) {
                $msg.hide().removeClass('notice-error notice-success').html('');
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'difc_extract_attachment_colors',
                    attachment_id: attachmentId,
                    nonce: nonce
                },
                success: function(response) {
                    console.log('DIFC AJAX Response:', response);
                    if (response.success) {
                        var successMsg = response.data.message || '<?php echo esc_js( __( 'Colors extracted successfully!', 'difc' ) ); ?>';
                        if ($msg) {
                            $msg.addClass('notice notice-success is-dismissible').html('<p>' + successMsg + '</p>').show();
                        } else {
                            alert(successMsg);
                        }
                        // Reload page after 1.5 seconds to show updated colors
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        var errorMsg = response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'An error occurred.', 'difc' ) ); ?>';
                        console.error('DIFC Error:', errorMsg);
                        if ($msg) {
                            $msg.addClass('notice notice-error is-dismissible').html('<p>' + errorMsg + '</p>').show();
                        } else {
                            alert(errorMsg);
                        }
                        $btn.prop('disabled', false);
                        $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> <?php echo esc_js( __( 'Extract Color Palette', 'difc' ) ); ?>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('DIFC AJAX Error:', status, error, xhr);
                    var errorMsg = '<?php echo esc_js( __( 'Network error. Please try again.', 'difc' ) ); ?>';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    }
                    if ($msg) {
                        $msg.addClass('notice notice-error is-dismissible').html('<p>' + errorMsg + '</p>').show();
                    } else {
                        alert(errorMsg);
                    }
                    $btn.prop('disabled', false);
                    $btn.html('<span class="dashicons dashicons-update" style="vertical-align: middle;"></span> <?php echo esc_js( __( 'Extract Color Palette', 'difc' ) ); ?>');
                }
            });
        }
        
        // Handle button on attachment edit page - use event delegation
        $(document).on('click', '#difc-extract-attachment-colors-btn, #difc-extract-attachment-colors-btn-modal', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('DIFC: Button clicked!', this.id);
            var $btn = $(this);
            var attachmentId = $btn.data('attachment-id') || $btn.attr('data-attachment-id');
            console.log('DIFC: Attachment ID:', attachmentId);
            
            if (!attachmentId) {
                console.error('DIFC: No attachment ID found!');
                alert('Error: No attachment ID found');
                return;
            }
            
            // Find message container (different for edit page vs modal)
            var $msg = $btn.closest('.difc-color-extraction-section, #difc-reanalyze-container').find('[id*="message"]').first();
            if ($msg.length === 0) {
                $msg = $('#difc-attachment-color-message, #difc-attachment-color-message-modal').first();
            }
            
            console.log('DIFC: Message container found:', $msg.length > 0);
            handleExtractColors(attachmentId, $btn, $msg.length > 0 ? $msg : null);
        });
        
        console.log('DIFC: Event handlers attached');
        
        // Add button to media library modal attachment details
        if (typeof wp !== 'undefined' && wp.media) {
            wp.media.view.Attachment.Details = wp.media.view.Attachment.Details.extend({
                render: function() {
                    wp.media.view.Attachment.Details.prototype.render.apply(this, arguments);
                    
                    var attachment = this.model;
                    if (attachment.get('type') === 'image') {
                        var $details = this.$el.find('.attachment-details');
                        var attachmentId = attachment.get('id');
                        
                        // Check if button already exists
                        if ($details.find('#difc-extract-attachment-colors-btn-modal').length === 0) {
                            var $colorSection = $('<div class="difc-color-extraction-section" style="margin: 15px 0; padding: 15px; border-top: 1px solid #ddd;"></div>');
                            $colorSection.append('<h3 style="margin-top: 0;"><?php echo esc_js( __( 'Color Palette', 'difc' ) ); ?></h3>');
                            $colorSection.append('<button type="button" id="difc-extract-attachment-colors-btn-modal" class="button button-secondary" data-attachment-id="' + attachmentId + '"><span class="dashicons dashicons-update" style="vertical-align: middle;"></span> <?php echo esc_js( __( 'Extract Color Palette', 'difc' ) ); ?></button>');
                            $colorSection.append('<div id="difc-attachment-color-message-modal" style="margin-top: 10px; display: none;"></div>');
                            $details.append($colorSection);
                            
                            // Button click is handled by document-level delegation above
                            console.log('DIFC: Button added to modal for attachment', attachmentId);
                        }
                    }
                    
                    return this;
                }
            });
        }
    })(jQuery);
    </script>
    <?php
}

/**
 * AJAX handler for extracting colors from attachment
 */
function difc_ajax_extract_attachment_colors() {
    // Enable error logging for debugging
    error_log( '[DIFC] AJAX handler called' );
    
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'difc_extract_attachment_colors' ) ) {
        error_log( '[DIFC] Nonce verification failed' );
        wp_send_json_error( [ 'message' => __( 'Security check failed.', 'difc' ) ] );
        return;
    }
    
    // Check permissions
    if ( ! current_user_can( 'edit_posts' ) ) {
        error_log( '[DIFC] Permission check failed' );
        wp_send_json_error( [ 'message' => __( 'You do not have permission to perform this action.', 'difc' ) ] );
        return;
    }
    
    $attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
    error_log( "[DIFC] Processing attachment ID: {$attachment_id}" );
    
    if ( ! $attachment_id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'difc' ) ] );
        return;
    }
    
    // Verify it's an image
    if ( ! wp_attachment_is_image( $attachment_id ) ) {
        wp_send_json_error( [ 'message' => __( 'This is not an image attachment.', 'difc' ) ] );
        return;
    }
    
    // Extract colors directly for attachment (not tied to a post)
    $path = get_attached_file( $attachment_id );
    if ( ! $path || ! file_exists( $path ) ) {
        wp_send_json_error( [ 'message' => __( 'Image file not found.', 'difc' ) ] );
        return;
    }
    
    // Increase memory limit temporarily
    $current_memory_limit = ini_get( 'memory_limit' );
    $memory_limit_bytes = wp_convert_hr_to_bytes( $current_memory_limit );
    if ( $memory_limit_bytes < 512 * 1024 * 1024 ) {
        @ini_set( 'memory_limit', '512M' );
    }
    
    // Convert to sRGB and resize if needed
    $processed_path = difc_convert_to_srgb( $path, 1000 );
    
    // Extract colors
    try {
        error_log( "[DIFC] Extracting colors from: {$processed_path}" );
        $colors = difc_get_dominant_colors( $processed_path );
        error_log( "[DIFC] Extracted " . count( $colors ) . " colors" );
    } catch ( Exception $e ) {
        error_log( "[DIFC] Exception extracting colors: " . $e->getMessage() );
        wp_send_json_error( [ 'message' => sprintf( __( 'Error extracting colors: %s', 'difc' ), $e->getMessage() ) ] );
        return;
    } catch ( Error $e ) {
        error_log( "[DIFC] Fatal error extracting colors: " . $e->getMessage() );
        wp_send_json_error( [ 'message' => sprintf( __( 'Fatal error extracting colors: %s', 'difc' ), $e->getMessage() ) ] );
        return;
    }
    
    // Clean up temp file if created
    if ( $processed_path !== $path && file_exists( $processed_path ) ) {
        @unlink( $processed_path );
    }
    
    if ( ! is_array( $colors ) || empty( $colors ) ) {
        wp_send_json_error( [ 'message' => __( 'No colors detected in image.', 'difc' ) ] );
        return;
    }
    
    // Validate and pad colors
    $validated_colors = [];
    foreach ( $colors as $color ) {
        if ( is_string( $color ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $color ) ) {
            $validated_colors[] = $color;
        }
    }
    
    if ( empty( $validated_colors ) ) {
        wp_send_json_error( [ 'message' => __( 'No valid colors detected.', 'difc' ) ] );
        return;
    }
    
    // Pad to 10 colors
    $colors = array_pad( $validated_colors, 10, '' );
    
    // Save colors to attachment fields (fields are in groups: color_1, color_2, etc.)
    // Use the same approach as difc_extract_and_save() which works correctly
    $saved_count = 0;
    $errors = [];
    
    // Ensure ACF field definitions are loaded for attachments
    if ( function_exists( 'acf_get_field_groups' ) ) {
        acf_get_field_groups( [ 'post_type' => 'attachment' ] );
    }
    
    try {
        for ( $i = 0; $i < 10; $i++ ) {
            $color_hex = $colors[ $i ] ?? '';
            $color_index = $i + 1;
            $group_name = "color_{$color_index}";
            $field_hex = "image_col_{$color_index}_hex";
            $field_name = "image_col_{$color_index}_name";
            
            if ( ! empty( $color_hex ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $color_hex ) ) {
                // Generate color name if not already set (same as working code)
                $existing_name = get_field( $field_name, $attachment_id );
                $color_name = empty( $existing_name ) ? difc_get_color_name( $color_hex ) : $existing_name;
                
                // Update group field with both hex and name (exact same as working code)
                $group_data = [
                    $field_hex => $color_hex,
                    $field_name => $color_name,
                ];
                
                $group_result = update_field( $group_name, $group_data, $attachment_id );
                
                // Fallback: try updating sub-fields directly (same as working code)
                if ( ! $group_result ) {
                    update_field( $field_hex, $color_hex, $attachment_id );
                    if ( empty( $existing_name ) ) {
                        update_field( $field_name, $color_name, $attachment_id );
                    }
                }
                
                // Clear cache for this specific field before verification
                if ( function_exists( 'acf_flush_value_cache' ) ) {
                    acf_flush_value_cache( $attachment_id, $field_hex );
                    acf_flush_value_cache( $attachment_id, $field_name );
                }
                
                // Verify the field was actually saved by reading it back
                $verify_hex = get_field( $field_hex, $attachment_id, false ); // false = no formatting, bypass cache
                $verify_name = get_field( $field_name, $attachment_id, false );
                
                if ( $verify_hex === $color_hex || $verify_name === $color_name ) {
                    $saved_count++;
                    difc_log( "Successfully saved color {$color_index}: {$color_hex}" );
                } else {
                    // If verification failed, try one more time with direct post_meta
                    $meta_hex_result = update_post_meta( $attachment_id, $field_hex, $color_hex );
                    $meta_name_result = update_post_meta( $attachment_id, $field_name, $color_name );
                    
                    // Clear cache again
                    if ( function_exists( 'acf_flush_value_cache' ) ) {
                        acf_flush_value_cache( $attachment_id, $field_hex );
                        acf_flush_value_cache( $attachment_id, $field_name );
                    }
                    
                    // Verify again
                    $verify_hex = get_field( $field_hex, $attachment_id, false );
                    $verify_name = get_field( $field_name, $attachment_id, false );
                    
                    if ( $verify_hex === $color_hex || $verify_name === $color_name ) {
                        $saved_count++;
                        difc_log( "Successfully saved color {$color_index} using post_meta fallback" );
                    } else {
                        $errors[] = "Failed to save color {$color_index}";
                        difc_log( "Failed to save color {$color_index} - expected hex: {$color_hex}, got: " . ( $verify_hex ?: 'empty' ) );
                    }
                }
            }
        }
        
        // Clear cache
        difc_clear_post_cache( $attachment_id );
        
        // Also clear ACF cache specifically
        if ( function_exists( 'acf_get_store' ) ) {
            try {
                $store = acf_get_store( 'values' );
                if ( $store ) {
                    $store->remove( $attachment_id );
                }
            } catch ( Exception $e ) {
                // Ignore cache errors
            }
        }
        
        error_log( "[DIFC] Saved {$saved_count} colors to attachment {$attachment_id}" );
        if ( ! empty( $errors ) ) {
            error_log( "[DIFC] Errors: " . implode( ', ', $errors ) );
        }
        
        if ( $saved_count > 0 ) {
            $message = sprintf( __( 'Successfully extracted and saved %d colors with names!', 'difc' ), $saved_count );
            if ( ! empty( $errors ) ) {
                $message .= ' ' . __( 'Some colors failed to save.', 'difc' );
            }
            wp_send_json_success( [ 'message' => $message, 'saved_count' => $saved_count ] );
        } else {
            $error_msg = __( 'No colors were saved.', 'difc' );
            if ( ! empty( $errors ) ) {
                $error_msg .= ' ' . implode( ', ', $errors );
            }
            wp_send_json_error( [ 'message' => $error_msg, 'errors' => $errors ] );
        }
    } catch ( Exception $e ) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Error saving colors: %s', 'difc' ), $e->getMessage() ) ] );
    } catch ( Error $e ) {
        wp_send_json_error( [ 'message' => sprintf( __( 'Fatal error saving colors: %s', 'difc' ), $e->getMessage() ) ] );
    }
}

/**
 * AJAX handler to check if colors were updated server-side (for JavaScript polling)
 */
function difc_ajax_check_color_update() {
    // Check permissions
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'difc' ) ] );
        return;
    }
    
    $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
    
    if ( ! $post_id ) {
        wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'difc' ) ] );
        return;
    }
    
    // Get the timestamp and image ID from post meta
    $timestamp = get_post_meta( $post_id, '_difc_colors_updated_timestamp', true );
    $image_id = get_post_meta( $post_id, '_difc_colors_updated_image_id', true );
    
    if ( $timestamp && $image_id ) {
        wp_send_json_success( [
            'timestamp' => $timestamp,
            'image_id' => $image_id
        ] );
    } else {
        wp_send_json_success( [
            'timestamp' => null,
            'image_id' => null
        ] );
    }
}