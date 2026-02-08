<?php
/**
 * Plugin Name: The Future is Now!
 * Description: Sets future timestamped posts to "publish" rather than "future" upon publish (useful for Events listing sites).
 * Version: 3.3.8
 * Author: Ryan Boren and Andrew Nacin, maintained by Scot Hacker, updated by Jack Lin.
 * Plugin URI: https://wordpress.org/plugins/the-future-is-now/
 * Author URI: https://blog.birdhouse.org/
 * Contributors: rboren, nacen, shacker, xjlin0
 * Tags: publish, future, post
 * Requires at least: 5.6
 * Tested up to: 6.9.1
 * Stable tag: 3.3.8
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
**/

if (!defined('ABSPATH')) exit;

remove_action('future_post', '_future_post_hook');

global $fn_settings;
function futurenow_load_settings() {
    global $fn_settings;
    $fn_settings = array(
        'admin_p' => get_option('futurenow_show_admin_as_published', '1'),
        'arch_p'  => get_option('futurenow_show_future_in_archives', '1'),
        'cal_p'   => get_option('futurenow_show_future_in_calendar', '1'),
        'types'   => (array) get_option('futurenow_post_types', array('post'))
    );
}
add_action('init', 'futurenow_load_settings', 1);

/**
 * Newsletter Legacy Theme Compatibility - Frontend Requests
 * Handles both preview (?na=emails-preview) and creation (?na=emails-create)
 * Newsletter executes theme.php in frontend context for proper excerpt/thumbnail extraction
 */
add_action('init', function() {
    global $fn_settings;
    
    // Check if this is a Newsletter frontend request
    $na = $_GET['na'] ?? '';
    $is_newsletter_request = in_array($na, ['emails-preview', 'emails-create']);
    
    if ($is_newsletter_request) {
        if (!$fn_settings) {
            futurenow_load_settings();
        }
        
        if ($fn_settings && $fn_settings['admin_p'] === '1') {
            // Register pre_get_posts filter for Newsletter theme.php execution
            add_filter('pre_get_posts', function($query) {
                global $fn_settings;
                
                if ($query->is_main_query()) {
                    return;
                }
                
                $post_type = $query->get('post_type');
                if (empty($post_type)) {
                    $post_type = 'post';
                }
                
                if (in_array($post_type, $fn_settings['types'])) {
                    $query->set('post_status', array('publish', 'future'));
                }
            }, 1);
        }
    }
}, 0);

/**
 * Newsletter Plugin Compatibility - AJAX Hooks
 * Hooks into Newsletter's AJAX actions to ensure future posts are included in composer
 * Priority 0 ensures we run before Newsletter's own handlers
 */
add_action('wp_ajax_tnpc_get_preset', function() {
    global $fn_settings;
    if (!$fn_settings || $fn_settings['admin_p'] !== '1') return;
    
    add_action('pre_get_posts', function($query) {
        global $fn_settings;
        $post_type = $query->get('post_type') ?: 'post';
        if (in_array($post_type, $fn_settings['types'])) {
            $query->set('post_status', array('publish', 'future'));
        }
    }, 1);
}, 0);

add_action('wp_ajax_tnpc_render', function() {
    global $fn_settings;
    if (!$fn_settings || $fn_settings['admin_p'] !== '1') return;
    
    add_action('pre_get_posts', function($query) {
        global $fn_settings;
        $post_type = $query->get('post_type') ?: 'post';
        if (in_array($post_type, $fn_settings['types'])) {
            $query->set('post_status', array('publish', 'future'));
        }
    }, 1);
}, 0);

/**
 * Newsletter Legacy Theme Compatibility - Admin Pages
 * Hook into admin_init for newsletter edit pages (fallback mechanism)
 */
add_action('admin_init', function() {
    global $fn_settings;
    
    $page = $_GET['page'] ?? '';
    $is_newsletter_page = in_array($page, [
        'newsletter_emails_edit',
        'newsletter_emails_new'
    ]);
    
    if (!$is_newsletter_page) {
        return;
    }
    
    if (!$fn_settings || $fn_settings['admin_p'] !== '1') {
        return;
    }
    
    add_filter('pre_get_posts', function($query) {
        global $fn_settings;
        
        if ($query->is_main_query()) {
            return;
        }
        
        $post_type = $query->get('post_type');
        if (empty($post_type)) {
            $post_type = 'post';
        }
        
        if (in_array($post_type, $fn_settings['types'])) {
            $query->set('post_status', array('publish', 'future'));
        }
    }, 1);
}, 1);

// Frontend: Modify main query for category/tag/archive pages
add_action('pre_get_posts', function($query) {
    global $fn_settings;
    
    if (!$query->is_main_query()) return;
    if (is_admin()) return;
    if (!$fn_settings) return;
    
    if ($query->is_category() || $query->is_tag() || $query->is_archive() || $query->is_home()) {
        $post_type = $query->get('post_type');
        if (empty($post_type)) {
            $post_type = 'post';
        }
        
        if (in_array($post_type, $fn_settings['types'])) {
            $query->set('post_status', array('publish', 'future'));
        }
    }
}, 1);

// Core Filter 1: Intercept all get_post_status() calls
add_filter('get_post_status', function($status, $post) {
    global $fn_settings;
    
    if (!$fn_settings) return $status;
    if (is_admin() && $fn_settings['admin_p'] !== '1') return $status;
    
    if (is_numeric($post)) {
        $post = get_post($post);
    }
    
    if (!$post) return $status;
    
    if ($status === 'future' && in_array($post->post_type, $fn_settings['types'])) {
        return 'publish';
    }
    
    return $status;
}, 1, 2);

// Core Filter 2: Modify posts after SQL query
add_filter('posts_results', function($posts, $query) {
    global $fn_settings;
    if (!$fn_settings || empty($posts)) return $posts;
    
    if (is_admin() && $fn_settings['admin_p'] !== '1') return $posts;
    
    foreach ($posts as $post) {
        if ($post->post_status === 'future') {
            if (in_array($post->post_type, $fn_settings['types'])) {
                $post->post_status = 'publish';
            }
        }
    }
    
    return $posts;
}, 1, 2);

// Core Filter 3: the_posts filter
add_filter('the_posts', function($posts, $query) {
    global $fn_settings;
    if (!$fn_settings || empty($posts)) return $posts;
    if (is_admin() && $fn_settings['admin_p'] !== '1') return $posts;
    
    foreach ($posts as $post) {
        if ($post->post_status === 'future' && in_array($post->post_type, $fn_settings['types'])) {
            $post->post_status = 'publish';
        }
    }
    return $posts;
}, 1, 2);

// SQL WHERE clause modification
add_filter('posts_where', function($where, $query) {
    global $fn_settings, $wpdb;
    
    // Admin page special handling
    if (is_admin()) {
        if (!$fn_settings || $fn_settings['admin_p'] !== '1') {
            return $where;
        }
    } else {
        // Frontend: always process
        if (!$fn_settings) {
            return $where;
        }
    }
    
    // Get post_type from query
    $post_type = $query->get('post_type');
    
    // BUG FIX: Handle case where WordPress returns all post types array
    if (is_array($post_type) && count($post_type) > 5) {
        $post_type = '';
    }
    
    // If not explicitly specified, check WHERE clause for post_type
    if (empty($post_type)) {
        // Try to extract from SQL
        if (preg_match("/{$wpdb->posts}\.post_type\s*=\s*'([^']+)'/", $where, $matches)) {
            $post_type = $matches[1];
        } elseif (preg_match("/post_type\s*=\s*'([^']+)'/", $where, $matches)) {
            $post_type = $matches[1];
        } elseif (preg_match("/{$wpdb->posts}\.post_type\s+IN\s*\('([^']+)'\)/", $where, $matches)) {
            $post_type = $matches[1];
        } else {
            // Default to 'post' for get_posts() queries (Newsletter legacy themes)
            $post_type = 'post';
        }
    }
    
    $current_types = is_array($post_type) ? $post_type : array($post_type);
    
    // Check if we should process this query
    $should_process = false;
    foreach ($current_types as $type) {
        if (in_array($type, $fn_settings['types'])) {
            $should_process = true;
            break;
        }
    }
    
    if (!$should_process) {
        return $where;
    }
    
    // Core fix: Replace all forms of post_status restrictions
    $where = str_replace(
        "{$wpdb->posts}.post_status = 'publish'", 
        "{$wpdb->posts}.post_status IN ('publish', 'future')", 
        $where
    );
    
    $where = preg_replace(
        "/post_status\s*=\s*'publish'(?!\s*OR)/", 
        "post_status IN ('publish', 'future')", 
        $where
    );
    
    $where = preg_replace(
        "/post_status\s+IN\s*\(\s*'publish'\s*\)/i", 
        "post_status IN ('publish', 'future')", 
        $where
    );
    
    // Remove Tribe Events date restrictions
    $where = preg_replace('/AND\s+\(\s*mt\d*\.meta_key\s*=\s*[\'"]_EventStartDateUTC[\'"]\s+AND\s+CAST\(mt\d*\.meta_value AS DATETIME\)\s*>=\s*[\'"][^"\']+[\'"]\s*\)/', '', $where);
    $where = preg_replace('/AND\s+wp_tec_occurrences\.start_date_utc\s*>=\s*[\'"][^"\']+[\'"]/', '', $where);
    
    // Special handling for singular post queries
    if ($query->is_singular() && $query->is_main_query()) {
        $where = preg_replace(
            "/post_status\s*=\s*'publish'/", 
            "post_status IN ('publish', 'future')", 
            $where
        );
    }
    
    return $where;
}, 1, 2);

add_filter('getarchives_where', function($where, $r) {
    global $fn_settings;
    if (!$fn_settings || $fn_settings['arch_p'] !== '1') return $where;

    $target_type = isset($r['post_type']) ? $r['post_type'] : 'post';
    if (in_array($target_type, $fn_settings['types'])) {
        $where = str_replace("post_status = 'publish'", "post_status IN ('publish', 'future')", $where);
    }
    return $where;
}, 10, 2);

add_filter('query', function($query) {
    global $fn_settings;
    if (!$fn_settings || $fn_settings['cal_p'] !== '1' || is_admin()) return $query;
    
    if (strpos($query, "post_status = 'publish'") !== false) {
        if (strpos($query, 'DAYOFMONTH') !== false || strpos($query, "post_date > '") !== false) {
            
            if (preg_match("/post_type\s*=\s*'([^']+)'/", $query, $matches)) {
                $query_post_type = $matches[1];
                
                if (in_array($query_post_type, $fn_settings['types'])) {
                    return str_replace("post_status = 'publish'", "post_status IN ('publish', 'future')", $query);
                }
            }
        }
    }
    return $query;
}, 1);

add_action('admin_menu', function() {
    add_options_page('The Future is Now Settings', 'Future is Now', 'manage_options', 'future_is_now', 'futurenow_options_page');
});

function futurenow_options_page() {
    if (isset($_POST['fn_save'])) {
        check_admin_referer('futurenow_settings', 'futurenow_nonce');
        
        update_option('futurenow_show_admin_as_published', isset($_POST['admin_p']) ? '1' : '0');
        update_option('futurenow_show_future_in_archives', isset($_POST['arch_p']) ? '1' : '0');
        update_option('futurenow_show_future_in_calendar', isset($_POST['cal_p']) ? '1' : '0');
        
        $submitted_types = $_POST['post_types'] ?? array('post');
        $valid_types = array_keys(get_post_types(array('public'=>true), 'names'));
        $sanitized_types = array_intersect($submitted_types, $valid_types);
        
        update_option('futurenow_post_types', $sanitized_types);
        futurenow_load_settings();
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    global $fn_settings;
    ?>
    <div class="wrap">
        <h1>The Future is Now! Settings</h1>
        
        <div class="notice notice-warning">
            <p><strong>⚠️ Important:</strong> Future-dated posts will be publicly visible to all visitors immediately. Make sure your future posts are ready for publication!</p>
        </div>
        
        <form method="post">
            <?php wp_nonce_field('futurenow_settings', 'futurenow_nonce'); ?>
            <input type="hidden" name="fn_save" value="1">
            <table class="form-table">
                <tr>
                    <th scope="row">Admin Display</th>
                    <td>
                        <label>
                            <input type="checkbox" name="admin_p" value="1" <?php checked('1', $fn_settings['admin_p']); ?>>
                            Show future posts as "Published" in Admin page
                        </label>
                        <p class="description">
                            When enabled, future-dated posts will appear as published in both frontend AND admin pages. <strong>Required for Newsletter plugin compatibility.</strong>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Archives Widget</th>
                    <td>
                        <label>
                            <input type="checkbox" name="arch_p" value="1" <?php checked('1', $fn_settings['arch_p']); ?>>
                            Display future months in Archives widget
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Calendar Widget</th>
                    <td>
                        <label>
                            <input type="checkbox" name="cal_p" value="1" <?php checked('1', $fn_settings['cal_p']); ?>>
                            Show post links in calendar widget
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Post Types</th>
                    <td>
                        <?php foreach (get_post_types(array('public'=>true), 'objects') as $t) {
                            $c = in_array($t->name, $fn_settings['types']) ? 'checked' : '';
                            echo "<label style='display:block'><input type='checkbox' name='post_types[]' value='{$t->name}' $c> {$t->label} <code>({$t->name})</code></label>";
                        } ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <hr>
        
        <h2>Newsletter Plugin Compatibility</h2>
        
        <h3>Complete Support - All Workflows</h3>
        <p><strong>Version 3.3.8</strong> fully supports Newsletter plugin:</p>
        <ul>
            <li>✅ <strong>Legacy Themes</strong> - Preview (iframe) and "Proceed to edit" work correctly</li>
            <li>✅ <strong>Modern Composer</strong> - Posts blocks show future posts with automatic updates</li>
        </ul>
        
        <p><strong>Requirements:</strong></p>
        <ul>
            <li>✅ "Show future posts as 'Published' in Admin page" must be enabled (above)</li>
            <li>✅ The post type must be selected in "Post Types" (above)</li>
        </ul>
        
        <p><strong>Best Practice:</strong> Always preview newsletters before sending to ensure the latest posts are included.</p>
    </div>
    <?php
}
