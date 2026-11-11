<?php
/**
 * Plugin Name: Tabaix Visual Editor
 * Description: Edit any WordPress theme visually without breaking it. Click any text to change it, swap images, style elements visually, insert Gutenberg-like blocks, hide elements, custom CSS — applied as a safe overlay layer with undo history. Launch it from the admin bar, block editor, post lists, or Customizer.
 * Version: 1.6.1
 * Author: Tabaix Enterprises
 * Author URI: https://tabaix.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tabaix-visual-editor
 */

if (!defined('ABSPATH')) exit;

define('TVE_VERSION', '1.6.1');
define('TVE_URL', plugin_dir_url(__FILE__));

/* ---------------- context ---------------- */

function tve_can_edit() {
    return current_user_can('edit_pages');
}

function tve_is_edit_mode() {
    return tve_can_edit() && !is_admin() && tve_overlay_enabled() && isset($_GET['tve_edit']) && $_GET['tve_edit'] === '1';
}

/* Current frontend path, used as the per-page storage key. */
function tve_current_path() {
    static $path = null;
    if ($path === null) {
        $path = '/';
        if (isset($_SERVER['REQUEST_URI'])) {
            $p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            if (is_string($p) && $p !== '') $path = $p;
        }
        $path = untrailingslashit($path);
        if ($path === '') $path = '/';
    }
    return $path;
}

function tve_page_key() {
    return md5(tve_current_path());
}

/* ---------------- storage ---------------- */

function tve_uid() {
    return 'tve' . uniqid();
}

/* Find a section entry by uid; falls back to numeric index for old callers. */
function tve_find_section($page, $uid) {
    if (!isset($page['sections']) || !is_array($page['sections'])) return -1;
    foreach ($page['sections'] as $i => $sec) {
        if (isset($sec['uid']) && $sec['uid'] === $uid) return $i;
    }
    if (ctype_digit((string) $uid)) {
        $i = intval($uid);
        if (isset($page['sections'][$i])) return $i;
    }
    return -1;
}

function tve_sec_pos($sec) {
    $p = isset($sec['pos']) ? $sec['pos'] : 'bottom';
    return in_array($p, array('top', 'bottom', 'before_footer'), true) ? $p : 'bottom';
}

function tve_default_page() {
    return array(
        'texts' => array(),
        'hides' => array(),
        'css' => '',
        'styles' => array(),
        'images' => array(),
        'sections' => array(),
        'revisions' => array(),
    );
}

function tve_get_page() {
    return tve_get_page_by_key(tve_page_key());
}

/* Load a page's stored data by its md5 storage key (admin/history use). */
function tve_get_page_by_key($key) {
    $all = get_option('tve_data', array());
    if (!is_array($all)) $all = array();
    if (!isset($all[$key]) || !is_array($all[$key])) return tve_default_page();
    $page = array_merge(tve_default_page(), $all[$key]);
    // v1.4: backfill stable uids for blocks saved before uids existed
    $changed = false;
    if (isset($page['sections']) && is_array($page['sections'])) {
        foreach ($page['sections'] as $i => $sec) {
            if (!is_array($sec) || !empty($sec['uid'])) continue;
            $page['sections'][$i]['uid'] = tve_uid();
            $changed = true;
        }
    }
    if ($changed) tve_save_page_by_key($key, $page);
    return $page;
}

function tve_save_page($page) {
    tve_save_page_by_key(tve_page_key(), $page, tve_current_path());
}

/* Save a page's stored data by key. Pass $path to record its human-readable URL label. */
function tve_save_page_by_key($key, $page, $path = null) {
    $all = get_option('tve_data', array());
    if (!is_array($all)) $all = array();
    $all[$key] = $page;
    update_option('tve_data', $all, false);
    if ($path !== null) {
        $paths = get_option('tve_page_paths', array());
        if (!is_array($paths)) $paths = array();
        if (!isset($paths[$key]) || $paths[$key] !== $path) {
            $paths[$key] = $path;
            update_option('tve_page_paths', $paths, false);
        }
    }
}

function tve_block_ids() {
    return array(
        'heading' => 'Heading',
        'paragraph' => 'Paragraph',
        'image' => 'Image',
        'button' => 'Button',
        'quote' => 'Quote',
        'list' => 'List',
        'spacer' => 'Spacer',
        'divider' => 'Divider',
        'columns-2' => '2 Columns',
        'hero-simple' => 'Hero',
        'features-3' => '3 Features',
        'cta-banner' => 'CTA Banner',
        'testimonial' => 'Testimonial',
        'stats-4' => 'Stats Row',
        'faq' => 'FAQ',
        'newsletter' => 'Newsletter',
        'header-bar' => 'Header: Bar',
        'header-center' => 'Header: Centered',
        'header-minimal' => 'Header: Minimal',
        'faq-plus' => 'FAQ: Accordion',
        'faq-cards' => 'FAQ: Cards',
        'pricing-table' => 'Pricing Table',
        'gallery-grid' => 'Gallery Grid',
        'video-embed' => 'Video',
        'team-3' => 'Team',
        'social-icons' => 'Social Icons',
        'contact-info' => 'Contact Info',
    );
}

function tve_section_ids() {
    return array_keys(tve_block_ids());
}

/* Push a snapshot of the current page state for undo/history. */
function tve_push_revision(&$page) {
    $snap = $page;
    unset($snap['revisions']);
    $revs = (isset($page['revisions']) && is_array($page['revisions'])) ? $page['revisions'] : array();
    $last = reset($revs);
    if ($last && isset($last['data']) && serialize($last['data']) === serialize($snap)) return;
    array_unshift($revs, array('time' => time(), 'data' => $snap));
    $page['revisions'] = array_slice($revs, 0, tve_history_limit());
}

/* Sanitize visual style props into a CSS declaration string. */
function tve_style_decl($props) {
    if (!is_array($props)) return '';
    $out = '';
    if (!empty($props['color'])) {
        $c = sanitize_hex_color($props['color']);
        if ($c) $out .= 'color:' . $c . ';';
    }
    if (!empty($props['bg'])) {
        $c = sanitize_hex_color($props['bg']);
        if ($c) $out .= 'background-color:' . $c . ';';
    }
    $sizes = array('font-size' => 'font-size', 'padding' => 'padding', 'radius' => 'border-radius');
    foreach ($sizes as $k => $cssp) {
        if (isset($props[$k]) && $props[$k] !== '') {
            $v = absint($props[$k]);
            if ($v <= 600) $out .= $cssp . ':' . $v . 'px;';
        }
    }
    if (!empty($props['align']) && in_array($props['align'], array('left', 'center', 'right', 'justify'), true)) {
        $out .= 'text-align:' . $props['align'] . ';';
    }
    return $out;
}

/* ---------------- entry points: post lists, customizer, block editor ---------------- */

function tve_launch_url($url) {
    return add_query_arg('tve_edit', '1', $url);
}

function tve_row_actions($actions, $post) {
    if (!tve_can_edit() || !tve_overlay_enabled() || !in_array($post->post_type, array('post', 'page'), true)) return $actions;
    $url = ($post->post_status === 'publish') ? get_permalink($post) : get_preview_post_link($post);
    if ($url) {
        $actions['tve_visual_edit'] = '<a href="' . esc_url(tve_launch_url($url)) . '" target="_blank" rel="noopener">Visual Edit</a>';
    }
    return $actions;
}
add_filter('post_row_actions', 'tve_row_actions', 10, 2);
add_filter('page_row_actions', 'tve_row_actions', 10, 2);

if (class_exists('WP_Customize_Control')) {
    class TVE_Launch_Control extends WP_Customize_Control {
        public $type = 'tve_launch';
        public function render_content() {
            $url = esc_url(tve_launch_url(home_url('/')));
            echo '<p style="font-size:13px;line-height:1.6;">Edit your site visually — click any text, image, or section right on the real page. No code, works with any theme.</p>';
            echo '<a class="button button-primary" target="_blank" rel="noopener" href="' . $url . '" style="margin-top:4px;">Open Visual Editor</a>';
        }
    }
}

add_action('customize_register', function ($wp_customize) {
    if (!tve_can_edit() || !tve_overlay_enabled() || !class_exists('TVE_Launch_Control')) return;
    $wp_customize->add_section('tve_visual', array('title' => 'Visual Editor', 'priority' => 1));
    $wp_customize->add_setting('tve_visual_launch', array('sanitize_callback' => '__return_empty_string'));
    $wp_customize->add_control(new TVE_Launch_Control($wp_customize, 'tve_visual_launch', array(
        'section' => 'tve_visual',
        'settings' => 'tve_visual_launch',
    )));
});

add_action('enqueue_block_editor_assets', function () {
    if (!tve_can_edit() || !tve_overlay_enabled()) return;
    wp_enqueue_script('tve-block-editor', TVE_URL . 'assets/block-editor.js', array(), TVE_VERSION, true);
});

/* ---------------- admin bar ---------------- */

add_action('admin_bar_menu', function ($bar) {
    if (!tve_can_edit() || !tve_overlay_enabled() || is_admin()) return;
    if (tve_is_edit_mode()) {
        $bar->add_node(array(
            'id' => 'tve-exit',
            'title' => 'Exit Visual Edit',
            'href' => remove_query_arg('tve_edit'),
        ));
    } else {
        $bar->add_node(array(
            'id' => 'tve-edit',
            'title' => 'Visual Edit',
            'href' => add_query_arg('tve_edit', '1'),
        ));
    }
}, 100);

/* ---------------- editor assets ---------------- */

add_action('wp_enqueue_scripts', function () {
    if (!tve_is_edit_mode()) return;
    wp_enqueue_media();
    wp_enqueue_style('tve-editor', TVE_URL . 'assets/editor.css', array(), TVE_VERSION);
    wp_enqueue_script('tve-editor', TVE_URL . 'assets/editor.js', array('jquery'), TVE_VERSION, true);
    wp_localize_script('tve-editor', 'TVE', array(
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tve_nonce'),
        'page' => tve_get_page(),
        'exitUrl' => remove_query_arg('tve_edit'),
        'sections' => tve_enabled_block_ids(),
        'defaultDevice' => tve_get_settings()['default_device'],
    ));
});

/* ---------------- ajax ---------------- */

add_action('wp_ajax_tve_save', function () {
    check_ajax_referer('tve_nonce', 'nonce');
    if (!tve_can_edit()) wp_send_json_error(array('message' => 'forbidden'), 403);

    $page = tve_get_page();
    $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';

    // Snapshot for undo/history before any mutation (except restore flows, handled below).
    $mutating = array('text', 'untext', 'hide', 'unhide', 'css', 'style_save', 'style_clear', 'image', 'image_remove', 'section_add', 'section_del', 'section_move', 'section_dupe', 'section_style', 'reset');
    if (in_array($op, $mutating, true)) tve_push_revision($page);

    if ($op === 'text') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        $html = isset($_POST['html']) ? wp_kses_post(wp_unslash($_POST['html'])) : '';
        $href = isset($_POST['href']) ? esc_url_raw(wp_unslash($_POST['href'])) : '';
        if ($sel === '') wp_send_json_error(array('message' => 'empty selector'), 400);
        $entry = array('sel' => $sel, 'html' => $html);
        if ($href !== '') $entry['href'] = $href;
        $page['texts'] = array_values(array_filter($page['texts'], function ($t) use ($sel) {
            return !isset($t['sel']) || $t['sel'] !== $sel;
        }));
        $page['texts'][] = $entry;
    } elseif ($op === 'untext') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        $page['texts'] = array_values(array_filter($page['texts'], function ($t) use ($sel) {
            return !isset($t['sel']) || $t['sel'] !== $sel;
        }));
    } elseif ($op === 'hide') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        if ($sel !== '' && !in_array($sel, $page['hides'], true)) $page['hides'][] = $sel;
    } elseif ($op === 'unhide') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        $page['hides'] = array_values(array_diff($page['hides'], array($sel)));
    } elseif ($op === 'css') {
        $css = isset($_POST['css']) ? wp_unslash($_POST['css']) : '';
        $css = preg_replace('/<\/style\s*>/i', '', $css);
        $page['css'] = $css;
    } elseif ($op === 'section_add') {
        $id = isset($_POST['section_id']) ? sanitize_key($_POST['section_id']) : '';
        $pos = isset($_POST['pos']) ? sanitize_key($_POST['pos']) : 'bottom';
        $anchor = isset($_POST['anchor']) ? sanitize_text_field(wp_unslash($_POST['anchor'])) : '';
        if (!in_array($id, array_keys(tve_enabled_block_ids()), true)) wp_send_json_error(array('message' => 'bad section'), 400);
        if (!in_array($pos, array('top', 'bottom', 'before_footer', 'anchor'), true)) $pos = 'bottom';
        if ($pos === 'anchor' && $anchor === '') $pos = 'bottom';
        $uid = tve_uid();
        $page['sections'][] = array('id' => $id, 'pos' => $pos, 'anchor' => $anchor, 'uid' => $uid);
        tve_save_page($page);
        wp_send_json_success(array(
            'page' => $page,
            'html' => tve_section_html($id, $uid),
            'index' => count($page['sections']) - 1,
            'uid' => $uid,
        ));
    } elseif ($op === 'image') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        $att_id = isset($_POST['att_id']) ? absint($_POST['att_id']) : 0;
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if ($sel === '' || ($att_id === 0 && $url === '')) wp_send_json_error(array('message' => 'bad image'), 400);
        $page['images'] = array_values(array_filter($page['images'], function ($im) use ($sel) {
            return !isset($im['sel']) || $im['sel'] !== $sel;
        }));
        $page['images'][] = array('sel' => $sel, 'id' => $att_id, 'url' => $url);
    } elseif ($op === 'image_remove') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        $page['images'] = array_values(array_filter($page['images'], function ($im) use ($sel) {
            return !isset($im['sel']) || $im['sel'] !== $sel;
        }));
    } elseif ($op === 'style_save') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        $raw = isset($_POST['props']) ? wp_unslash($_POST['props']) : '';
        $props_arr = is_string($raw) ? json_decode($raw, true) : $raw;
        if ($sel === '' || !is_array($props_arr)) wp_send_json_error(array('message' => 'bad style'), 400);
        $props = array();
        foreach (array('color', 'bg', 'font-size', 'padding', 'radius', 'align') as $k) {
            if (isset($props_arr[$k])) $props[$k] = sanitize_text_field($props_arr[$k]);
        }
        if (tve_style_decl($props) === '') unset($page['styles'][$sel]);
        else $page['styles'][$sel] = $props;
    } elseif ($op === 'style_clear') {
        $sel = isset($_POST['sel']) ? sanitize_text_field(wp_unslash($_POST['sel'])) : '';
        unset($page['styles'][$sel]);
    } elseif ($op === 'undo') {
        $revs = isset($page['revisions']) && is_array($page['revisions']) ? $page['revisions'] : array();
        if (empty($revs)) wp_send_json_error(array('message' => 'nothing to undo'), 400);
        $snap = array_shift($revs);
        $page = array_merge(tve_default_page(), $snap['data']);
        $page['revisions'] = $revs;
    } elseif ($op === 'revision_restore') {
        $idx = isset($_POST['index']) ? intval($_POST['index']) : -1;
        $revs = isset($page['revisions']) && is_array($page['revisions']) ? $page['revisions'] : array();
        if (!isset($revs[$idx])) wp_send_json_error(array('message' => 'bad revision'), 400);
        $snap = $revs[$idx];
        tve_push_revision($page); // keep current state undoable
        $revs = $page['revisions'];
        $found = null; // locate the snapshot again (push may have shifted indexes)
        foreach ($revs as $k => $r) {
            if (isset($r['time'], $r['data']) && $r['time'] === $snap['time']
                && serialize($r['data']) === serialize($snap['data'])) { $found = $k; break; }
        }
        $page = array_merge(tve_default_page(), $snap['data']);
        if ($found !== null) unset($revs[$found]);
        $page['revisions'] = array_values($revs);
    } elseif ($op === 'section_del') {
        $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
        $idx = ($uid !== '') ? tve_find_section($page, $uid)
            : (isset($_POST['index']) ? intval($_POST['index']) : -1);
        if ($idx !== -1 && isset($page['sections'][$idx])) {
            array_splice($page['sections'], $idx, 1);
        }
    } elseif ($op === 'section_move') {
        $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
        $swap_uid = isset($_POST['swap_uid']) ? sanitize_text_field(wp_unslash($_POST['swap_uid'])) : '';
        $dir = isset($_POST['dir']) ? intval($_POST['dir']) : 0;
        $i = tve_find_section($page, $uid);
        if ($i === -1) wp_send_json_error(array('message' => 'bad block'), 400);
        if ($swap_uid !== '') {
            // placed blocks: exchange anchors (or array spots when anchors match)
            $j = tve_find_section($page, $swap_uid);
            if ($j === -1 || $j === $i) wp_send_json_error(array('message' => 'bad block'), 400);
            $ai = isset($page['sections'][$i]['anchor']) ? $page['sections'][$i]['anchor'] : '';
            $aj = isset($page['sections'][$j]['anchor']) ? $page['sections'][$j]['anchor'] : '';
            if ($ai === '' || $aj === '') wp_send_json_error(array('message' => 'move mismatch'), 400);
            if ($ai === $aj) {
                $tmp = $page['sections'][$i];
                $page['sections'][$i] = $page['sections'][$j];
                $page['sections'][$j] = $tmp;
            } else {
                $page['sections'][$i]['anchor'] = $aj;
                $page['sections'][$j]['anchor'] = $ai;
            }
        } else {
            // bucket blocks: swap with nearest same-bucket neighbor (array order = render order)
            if ($dir !== 1 && $dir !== -1) wp_send_json_error(array('message' => 'bad direction'), 400);
            if (!empty($page['sections'][$i]['anchor'])) wp_send_json_error(array('message' => 'placed blocks move by swap'), 400);
            $pos = tve_sec_pos($page['sections'][$i]);
            $n = count($page['sections']);
            $j = -1;
            if ($dir < 0) {
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (tve_sec_pos($page['sections'][$k]) === $pos && empty($page['sections'][$k]['anchor'])) { $j = $k; break; }
                }
            } else {
                for ($k = $i + 1; $k < $n; $k++) {
                    if (tve_sec_pos($page['sections'][$k]) === $pos && empty($page['sections'][$k]['anchor'])) { $j = $k; break; }
                }
            }
            if ($j === -1) wp_send_json_error(array('message' => 'nothing to move past'), 400);
            $tmp = $page['sections'][$i];
            $page['sections'][$i] = $page['sections'][$j];
            $page['sections'][$j] = $tmp;
        }
    } elseif ($op === 'section_dupe') {
        $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
        $i = tve_find_section($page, $uid);
        if ($i === -1) wp_send_json_error(array('message' => 'bad block'), 400);
        $copy = $page['sections'][$i];
        $new_uid = tve_uid();
        $copy['uid'] = $new_uid;
        if (!empty($copy['anchor'])) {
            // land the copy directly after the original
            $copy['anchor'] = '[data-tve-section="' . $page['sections'][$i]['uid'] . '"]';
        }
        array_splice($page['sections'], $i + 1, 0, array($copy));
        tve_save_page($page);
        wp_send_json_success(array(
            'page' => $page,
            'html' => tve_section_html($copy['id'], $new_uid, $copy),
            'uid' => $new_uid,
        ));
    } elseif ($op === 'section_style') {
        $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
        $i = tve_find_section($page, $uid);
        if ($i === -1) wp_send_json_error(array('message' => 'bad block'), 400);
        $raw = isset($_POST['props']) ? wp_unslash($_POST['props']) : '';
        $props_arr = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($props_arr)) $props_arr = array();
        $style = array();
        foreach (array('font-size', 'align', 'bg', 'pad') as $k) {
            if (isset($props_arr[$k]) && $props_arr[$k] !== '') $style[$k] = sanitize_text_field($props_arr[$k]);
        }
        if (empty($style)) unset($page['sections'][$i]['style']);
        else $page['sections'][$i]['style'] = $style;
        if (isset($_POST['video'])) {
            $video = tve_sanitize_video_url(wp_unslash($_POST['video']));
            if ($video !== '') $page['sections'][$i]['video'] = $video;
            else unset($page['sections'][$i]['video']);
        }
        tve_save_page($page);
        wp_send_json_success(array('page' => $page, 'video_html' => tve_video_embed_html(isset($page['sections'][$i]['video']) ? $page['sections'][$i]['video'] : '')));
    } elseif ($op === 'reset') {
        $page = tve_default_page();
    } else {
        wp_send_json_error(array('message' => 'unknown op'), 400);
    }

    tve_save_page($page);
    wp_send_json_success(array('page' => $page));
});

/* ---------------- frontend overlay (output buffer) ---------------- */

add_action('template_redirect', function () {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() || is_trackback()) return;
    if (is_customize_preview()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (!class_exists('DOMDocument')) return;
    ob_start('tve_buffer_filter');
}, 0);

function tve_buffer_filter($html) {
    if (!tve_overlay_enabled()) return $html;
    if (stripos($html, '<html') === false) return $html;
    $page = tve_get_page();
    $has_work = !empty($page['texts']) || !empty($page['hides']) || !empty($page['sections'])
        || !empty($page['styles']) || !empty($page['images']) || trim($page['css']) !== '';
    if (!$has_work) return $html;

    $dom = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $ok = $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    if (!$ok) return $html;
    $xp = new DOMXPath($dom);

    // 1. text overrides
    foreach ($page['texts'] as $t) {
        if (!isset($t['sel']) || !isset($t['html'])) continue;
        $expr = tve_css_to_xpath($t['sel']);
        if (!$expr) continue;
        $nodes = $xp->query($expr);
        if ($nodes === false || $nodes->length === 0) continue;
        $node = $nodes->item(0);
        while ($node->firstChild) $node->removeChild($node->firstChild);
        $tmp = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        @$tmp->loadHTML(mb_convert_encoding('<div>' . $t['html'] . '</div>', 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
        $divs = $tmp->getElementsByTagName('div');
        if ($divs->length) {
            foreach ($divs->item(0)->childNodes as $ch) {
                $node->appendChild($dom->importNode($ch, true));
            }
        }
        if (!empty($t['href']) && $node->nodeName === 'a') {
            $node->setAttribute('href', esc_url_raw($t['href']));
        }
    }

    // 2. image swaps
    foreach ($page['images'] as $im) {
        if (!isset($im['sel'])) continue;
        $expr = tve_css_to_xpath($im['sel']);
        if (!$expr) continue;
        $nodes = $xp->query($expr);
        if ($nodes === false || $nodes->length === 0) continue;
        $node = $nodes->item(0);
        if ($node->nodeName !== 'img') continue;
        $url = '';
        if (!empty($im['id'])) $url = wp_get_attachment_image_url(intval($im['id']), 'full');
        if (!$url && !empty($im['url'])) $url = esc_url_raw($im['url']);
        if ($url) {
            $node->setAttribute('src', $url);
            $node->removeAttribute('srcset');
            $node->removeAttribute('sizes');
        }
    }

    // 3. sections (anchor-placed first, then positional)
    $bodies = $dom->getElementsByTagName('body');
    if ($bodies->length) {
        $body = $bodies->item(0);
        $by_pos = array('top' => '', 'bottom' => '', 'before_footer' => '');
        foreach ($page['sections'] as $idx => $s) {
            if (!isset($s['id'])) continue;
            $bhtml = tve_section_html($s['id'], isset($s['uid']) ? $s['uid'] : 'tve' . $idx, $s);
            if ($bhtml === '') continue;
            $placed = false;
            if (!empty($s['anchor'])) {
                $aexpr = tve_css_to_xpath($s['anchor']);
                if ($aexpr) {
                    $anodes = $xp->query($aexpr);
                    if ($anodes && $anodes->length) {
                        $frag = tve_html_fragment($dom, $bhtml);
                        if ($frag) {
                            $a = $anodes->item(0);
                            $a->parentNode->insertBefore($frag, $a->nextSibling);
                            $placed = true;
                        }
                    }
                }
            }
            if (!$placed) {
                $pos = isset($s['pos']) ? $s['pos'] : 'bottom';
                if (!isset($by_pos[$pos])) $pos = 'bottom';
                $by_pos[$pos] .= $bhtml;
            }
        }
        if ($by_pos['top'] !== '') {
            $frag = tve_html_fragment($dom, $by_pos['top']);
            if ($frag) $body->insertBefore($frag, $body->firstChild);
        }
        if ($by_pos['before_footer'] !== '') {
            $frag = tve_html_fragment($dom, $by_pos['before_footer']);
            if ($frag) {
                $footers = $dom->getElementsByTagName('footer');
                if ($footers->length) $body->insertBefore($frag, $footers->item(0));
                else $body->appendChild($frag);
            }
        }
        if ($by_pos['bottom'] !== '') {
            $frag = tve_html_fragment($dom, $by_pos['bottom']);
            if ($frag) $body->appendChild($frag);
        }
    }

    $out = $dom->saveHTML();

    // 4. hide rules + visual styles + custom css (string inject keeps CSS intact)
    $css = '';
    foreach ($page['hides'] as $sel) {
        $sel = trim($sel);
        if ($sel === '') continue;
        if (tve_is_edit_mode()) {
            $css .= $sel . "{outline:2px dashed #d63638 !important;outline-offset:2px;opacity:.35 !important;}\n";
        } else {
            $css .= $sel . "{display:none !important;}\n";
        }
    }
    if (!empty($page['styles']) && is_array($page['styles'])) {
        foreach ($page['styles'] as $sel => $props) {
            $decl = tve_style_decl($props);
            if ($decl !== '') $css .= $sel . '{' . $decl . "}\n";
        }
    }
    if (trim($page['css']) !== '') $css .= "\n" . $page['css'];
    if ($css !== '') {
        $out = preg_replace('/<\/head\s*>/i', '<style class="tve-style">' . $css . '</style></head>', $out, 1);
    }

    return $out;
}

function tve_html_fragment($dom, $html) {
    $tmp = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $ok = @$tmp->loadHTML(mb_convert_encoding('<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    if (!$ok) return null;
    $divs = $tmp->getElementsByTagName('div');
    if (!$divs->length) return null;
    $frag = $dom->createDocumentFragment();
    foreach ($divs->item(0)->childNodes as $ch) {
        $frag->appendChild($dom->importNode($ch, true));
    }
    return $frag;
}

/**
 * Convert the small selector grammar the editor generates into XPath.
 * Supports: #id  |  body > tag:nth-of-type(n) > ...
 */
function tve_css_to_xpath($sel) {
    $sel = trim($sel);
    if (preg_match('/^\[data-tve-section="([A-Za-z0-9_-]+)"\]$/', $sel, $m)) {
        return "//*[@data-tve-section='" . $m[1] . "']";
    }
    if (preg_match('/^#([A-Za-z0-9_-]+)$/', $sel, $m)) {
        return "//*[@id='" . $m[1] . "']";
    }
    $parts = preg_split('/\s*>\s*/', $sel);
    if (!$parts) return false;
    $xp = array();
    foreach ($parts as $p) {
        if ($p === 'body') {
            $xp[] = 'body';
            continue;
        }
        if (preg_match('/^([a-z0-9]+):nth-of-type\((\d+)\)$/i', $p, $m)) {
            $tag = strtolower($m[1]);
            $n = intval($m[2]);
            $xp[] = $tag . '[count(preceding-sibling::' . $tag . ')+1=' . $n . ']';
        } elseif (preg_match('/^[a-z0-9]+$/i', $p)) {
            $xp[] = strtolower($p);
        } else {
            return false;
        }
    }
    return '//' . implode('/', $xp);
}

/* ---------------- section library ---------------- */

/* Sanitize per-block design props into an inline style attribute string. */
function tve_block_style_attr($style) {
    if (!is_array($style)) return '';
    $out = '';
    if (isset($style['font-size']) && $style['font-size'] !== '') {
        $v = absint($style['font-size']);
        if ($v >= 8 && $v <= 120) $out .= 'font-size:' . $v . 'px;';
    }
    if (!empty($style['align']) && in_array($style['align'], array('left', 'center', 'right'), true)) {
        $out .= 'text-align:' . $style['align'] . ';';
    }
    if (!empty($style['bg'])) {
        $c = sanitize_hex_color($style['bg']);
        if ($c) $out .= 'background-color:' . $c . ';';
    }
    if (!empty($style['pad']) && in_array($style['pad'], array('s', 'm', 'l'), true)) {
        $map = array('s' => 16, 'm' => 32, 'l' => 56);
        $out .= 'padding:' . $map[$style['pad']] . 'px;';
    }
    return $out === '' ? '' : ' style="' . esc_attr($out) . '"';
}

/* Allow only YouTube / Vimeo / direct-mp4 video URLs. Returns a safe URL or ''. */
function tve_sanitize_video_url($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) return '';
    $url = esc_url_raw($url);
    if ($url === '') return '';
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (preg_match('#(^|\.)youtube\.com$#', $host)) {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        if (!empty($q['v'])) return 'https://www.youtube.com/embed/' . rawurlencode($q['v']);
        return '';
    }
    if (preg_match('#(^|\.)youtu\.be$#', $host)) {
        $vid = trim($path, '/');
        if (preg_match('#^[A-Za-z0-9_-]{6,20}$#', $vid)) return 'https://www.youtube.com/embed/' . $vid;
        return '';
    }
    if (preg_match('#(^|\.)vimeo\.com$#', $host)) {
        $vid = trim($path, '/');
        if (preg_match('#^[0-9]{5,12}$#', $vid)) return 'https://player.vimeo.com/video/' . $vid;
        return '';
    }
    if (preg_match('#\.mp4($|\?)#i', $path)) return $url;
    return '';
}

/* Responsive 16:9 embed for a sanitized video URL (or a placeholder slot). */
function tve_video_embed_html($url) {
    $wrap = '<div class="tve-video-slot" style="position:relative;width:100%;padding-bottom:56.25%;height:0;overflow:hidden;background:#0b0e14;">';
    if ($url === '') {
        return $wrap . '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:#e5e7eb;">'
            . '<span style="font-size:40px;">&#9654;</span>'
            . '<span style="font-size:14px;opacity:.8;">Video block &mdash; open the block&rsquo;s Design panel to paste a YouTube, Vimeo or MP4 link</span>'
            . '</div></div>';
    }
    if (strpos($url, 'youtube.com/embed/') !== false || strpos($url, 'player.vimeo.com/') !== false) {
        return $wrap . '<iframe src="' . esc_url($url) . '" style="position:absolute;inset:0;width:100%;height:100%;border:0;" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe></div>';
    }
    return $wrap . '<video src="' . esc_url($url) . '" controls playsinline preload="metadata" style="position:absolute;inset:0;width:100%;height:100%;"></video></div>';
}

function tve_section_html($id, $uid, $s = array()) {
    $style = (is_array($s) && isset($s['style']) && is_array($s['style'])) ? $s['style'] : array();
    $open = '<div class="tve-section" data-tve-section="' . esc_attr($uid) . '"' . tve_block_style_attr($style) . '>';
    switch ($id) {
        case 'hero-simple':
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:72px 24px;text-align:center;">'
                . '<p style="font-size:12px;letter-spacing:2px;text-transform:uppercase;opacity:.6;margin:0 0 12px;">Welcome</p>'
                . '<h2 style="font-size:clamp(28px,5vw,52px);line-height:1.15;margin:0 0 16px;">A headline you can click to edit</h2>'
                . '<p style="font-size:17px;line-height:1.6;opacity:.75;max-width:640px;margin:0 auto 24px;">Replace this text with your own message. Everything inside this section is editable with one click.</p>'
                . '<a href="#" style="display:inline-block;padding:14px 32px;background:#111111;color:#ffffff;text-decoration:none;font-size:15px;">Get Started</a>'
                . '</div></div>';

        case 'features-3':
            $col = function ($t, $d) {
                return '<div style="flex:1;min-width:220px;padding:24px;border:1px solid rgba(0,0,0,.12);">'
                    . '<h3 style="font-size:20px;margin:0 0 10px;">' . $t . '</h3>'
                    . '<p style="font-size:15px;line-height:1.6;opacity:.75;margin:0;">' . $d . '</p></div>';
            };
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:64px 24px;">'
                . '<div style="display:flex;gap:20px;flex-wrap:wrap;">'
                . $col('Feature One', 'Describe your first benefit here. Click to edit this text.')
                . $col('Feature Two', 'Describe your second benefit here. Click to edit this text.')
                . $col('Feature Three', 'Describe your third benefit here. Click to edit this text.')
                . '</div></div></div>';

        case 'cta-banner':
            return $open . '<div style="background:#111111;color:#ffffff;padding:64px 24px;text-align:center;">'
                . '<div style="max-width:800px;margin:0 auto;">'
                . '<h2 style="font-size:clamp(24px,4vw,40px);margin:0 0 12px;color:#ffffff;">Ready to take the next step?</h2>'
                . '<p style="font-size:16px;opacity:.75;margin:0 0 24px;">A short supporting sentence goes here. Click to edit.</p>'
                . '<a href="#" style="display:inline-block;padding:14px 32px;background:#ffffff;color:#111111;text-decoration:none;font-size:15px;">Contact Us</a>'
                . '</div></div></div>';

        case 'testimonial':
            return $open . '<div style="max-width:800px;margin:0 auto;padding:64px 24px;text-align:center;">'
                . '<p style="font-size:clamp(20px,3vw,30px);font-style:italic;line-height:1.5;margin:0 0 16px;">&ldquo;This is a sample testimonial. Click to replace it with a real quote from your customer.&rdquo;</p>'
                . '<p style="font-size:14px;letter-spacing:1px;text-transform:uppercase;opacity:.6;margin:0;">Customer Name</p>'
                . '</div></div>';

        case 'stats-4':
            $stat = function ($n, $l) {
                return '<div style="flex:1;min-width:140px;text-align:center;padding:16px;">'
                    . '<p style="font-size:clamp(28px,4vw,44px);font-weight:700;margin:0;">' . $n . '</p>'
                    . '<p style="font-size:13px;letter-spacing:1px;text-transform:uppercase;opacity:.6;margin:8px 0 0;">' . $l . '</p></div>';
            };
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:56px 24px;">'
                . '<div style="display:flex;gap:16px;flex-wrap:wrap;">'
                . $stat('120+', 'Projects')
                . $stat('98%', 'Happy Clients')
                . $stat('15', 'Awards')
                . $stat('24/7', 'Support')
                . '</div></div></div>';
        case 'heading':
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:32px 24px 8px;">'
                . '<h2 style="font-size:clamp(26px,4vw,44px);line-height:1.2;margin:0;">Click to edit this heading</h2>'
                . '</div></div>';

        case 'paragraph':
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:8px 24px 32px;">'
                . '<p style="font-size:17px;line-height:1.7;margin:0;">Click to edit this paragraph. Write whatever this section needs to say — it supports multiple sentences and stays readable on phones.</p>'
                . '</div></div>';

        case 'image':
            $ph = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450"><rect width="800" height="450" fill="#e5e7eb"/><text x="400" y="225" font-family="sans-serif" font-size="26" fill="#9ca3af" text-anchor="middle" dominant-baseline="middle">Click to add image</text></svg>');
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:32px 24px;">'
                . '<img src="' . $ph . '" alt="Replace this image" style="width:100%;height:auto;display:block;">'
                . '</div></div>';

        case 'button':
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:24px;text-align:center;">'
                . '<a href="#" style="display:inline-block;padding:14px 36px;background:#2271b1;color:#ffffff;text-decoration:none;font-size:16px;">Click to edit button</a>'
                . '</div></div>';

        case 'quote':
            return $open . '<div style="max-width:800px;margin:0 auto;padding:40px 24px;">'
                . '<blockquote style="border-left:4px solid #2271b1;margin:0;padding:8px 0 8px 24px;font-size:20px;font-style:italic;line-height:1.6;">Click to edit this quote. It stands out from the body text with a colored border.</blockquote>'
                . '</div></div>';

        case 'list':
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:24px;">'
                . '<ul style="font-size:17px;line-height:2;margin:0;padding-left:24px;">'
                . '<li>First list item — click to edit</li>'
                . '<li>Second list item — click to edit</li>'
                . '<li>Third list item — click to edit</li>'
                . '</ul></div></div>';

        case 'spacer':
            return $open . '<div style="height:48px;" aria-hidden="true"></div></div>';

        case 'divider':
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:24px;">'
                . '<hr style="border:none;border-top:1px solid rgba(0,0,0,.15);margin:0;">'
                . '</div></div>';

        case 'columns-2':
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:32px 24px;">'
                . '<div style="display:flex;gap:32px;flex-wrap:wrap;">'
                . '<div style="flex:1;min-width:240px;"><h3 style="font-size:22px;margin:0 0 10px;">Left column</h3><p style="font-size:16px;line-height:1.7;margin:0;opacity:.8;">Click to edit the left column text.</p></div>'
                . '<div style="flex:1;min-width:240px;"><h3 style="font-size:22px;margin:0 0 10px;">Right column</h3><p style="font-size:16px;line-height:1.7;margin:0;opacity:.8;">Click to edit the right column text.</p></div>'
                . '</div></div></div>';

        case 'faq':
            $qa = function ($q, $a) {
                return '<details style="border:1px solid rgba(0,0,0,.12);margin-bottom:12px;padding:16px 20px;">'
                    . '<summary style="font-size:17px;font-weight:600;cursor:pointer;">' . $q . '</summary>'
                    . '<p style="font-size:15px;line-height:1.7;margin:12px 0 0;opacity:.8;">' . $a . '</p></details>';
            };
            return $open . '<div style="max-width:800px;margin:0 auto;padding:48px 24px;">'
                . '<h2 style="font-size:clamp(24px,4vw,36px);margin:0 0 24px;text-align:center;">Frequently asked questions</h2>'
                . $qa('First question — click to edit', 'First answer — click to edit this text.')
                . $qa('Second question — click to edit', 'Second answer — click to edit this text.')
                . $qa('Third question — click to edit', 'Third answer — click to edit this text.')
                . '</div></div>';

        case 'newsletter':
            return $open . '<div style="max-width:640px;margin:0 auto;padding:56px 24px;text-align:center;">'
                . '<h2 style="font-size:clamp(24px,4vw,36px);margin:0 0 12px;">Stay in the loop</h2>'
                . '<p style="font-size:16px;opacity:.75;margin:0 0 24px;">Click to edit this text. Connect this form to your email tool to collect subscribers.</p>'
                . '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;">'
                . '<input type="email" placeholder="Your email address" style="flex:1;min-width:200px;max-width:320px;padding:14px 16px;font-size:15px;border:1px solid rgba(0,0,0,.2);">'
                . '<button type="button" style="padding:14px 28px;background:#111111;color:#ffffff;border:none;font-size:15px;cursor:pointer;">Subscribe</button>'
                . '</div></div></div>';

        case 'header-bar':
            return $open . '<div style="background:#ffffff;">'
                . '<div style="background:#111111;color:#ffffff;text-align:center;font-size:13px;letter-spacing:.3px;padding:10px 16px;">Announcement text &mdash; click to edit this message</div>'
                . '<div style="max-width:1200px;margin:0 auto;padding:18px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;">'
                . '<a href="#" style="font-size:22px;font-weight:800;letter-spacing:-.5px;color:#111111;text-decoration:none;">BrandName</a>'
                . '<nav style="display:flex;gap:26px;flex-wrap:wrap;">'
                . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Home</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">About</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Services</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Contact</a>'
                . '</nav>'
                . '<a href="#" style="display:inline-block;padding:12px 28px;background:#111111;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;border-radius:999px;">Get Started</a>'
                . '</div><div style="border-top:1px solid rgba(0,0,0,.08);"></div>'
                . '</div></div>';

        case 'header-center':
            return $open . '<div style="background:#ffffff;text-align:center;padding:40px 24px 0;">'
                . '<a href="#" style="font-size:32px;font-weight:800;letter-spacing:-1px;color:#111111;text-decoration:none;">BrandName</a>'
                . '<p style="font-size:12px;color:#888888;margin:10px 0 0;letter-spacing:3px;text-transform:uppercase;">Your tagline here</p>'
                . '<nav style="display:flex;gap:34px;justify-content:center;flex-wrap:wrap;margin-top:28px;padding:18px 0;border-top:1px solid rgba(0,0,0,.07);">'
                . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Home</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">About</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Services</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Contact</a>'
                . '</nav>'
                . '</div></div>';

        case 'header-minimal':
            return $open . '<div style="background:#ffffff;border-bottom:1px solid rgba(0,0,0,.08);">'
                . '<div style="max-width:1200px;margin:0 auto;padding:22px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;">'
                . '<a href="#" style="font-size:20px;font-weight:700;color:#111111;text-decoration:none;letter-spacing:-.3px;">BrandName</a>'
                . '<nav style="display:flex;gap:26px;flex-wrap:wrap;">'
                . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Home</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">About</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Services</a>' . '<a href="#" style="font-size:15px;color:#333333;text-decoration:none;">Contact</a>'
                . '</nav>'
                . '</div></div></div>';

        case 'faq-plus':
            $tvex_qa = function ($q, $a) {
                return '<details class="tvex-faq" style="border-bottom:1px solid rgba(0,0,0,.1);">'
                    . '<summary class="tvex-faq-q" style="list-style:none;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:20px 4px;font-size:17px;font-weight:600;color:#111111;">'
                    . '<span>' . $q . '</span><span class="tvex-faq-ic" aria-hidden="true"></span></summary>'
                    . '<div style="padding:0 4px 24px;"><p style="font-size:15.5px;line-height:1.7;color:#555555;margin:0;">' . $a . '</p></div>'
                    . '</details>';
            };
            return $open . '<style>'
                . '.tvex-faq > summary::-webkit-details-marker{display:none;}'
                . '.tvex-faq > summary::marker{content:"";}'
                . '.tvex-faq-ic{pointer-events:none;position:relative;flex:0 0 auto;width:34px;height:34px;border:1px solid rgba(0,0,0,.15);border-radius:50%;}'
                . '.tvex-faq-ic::before,.tvex-faq-ic::after{content:"";position:absolute;background:#111111;left:50%;top:50%;transform:translate(-50%,-50%);}'
                . '.tvex-faq-ic::before{width:14px;height:2px;}'
                . '.tvex-faq-ic::after{width:2px;height:14px;transition:transform .25s ease;}'
                . '.tvex-faq[open] .tvex-faq-ic::after{transform:translate(-50%,-50%) scaleY(0);}'
                . '</style>'
                . '<div style="max-width:820px;margin:0 auto;padding:72px 24px;">'
                . '<p style="font-size:12px;letter-spacing:2.5px;text-transform:uppercase;color:#888888;margin:0 0 12px;text-align:center;">FAQ</p>'
                . '<h2 style="font-size:clamp(28px,4.5vw,44px);font-weight:700;letter-spacing:-1px;text-align:center;margin:0 0 14px;color:#111111;">Questions, answered</h2>'
                . '<p style="text-align:center;font-size:16px;color:#666666;margin:0 0 44px;">Click any question to edit it. On your live site, visitors tap to expand.</p>'
                . '<div style="border-top:1px solid rgba(0,0,0,.1);">'
                . $tvex_qa('How do I edit this text?', 'Click any question or answer and type &mdash; your changes save automatically and appear on the live page exactly as you see them here.')
                . $tvex_qa('Will this work with my theme?', 'Yes. These blocks are added as a safe layer on top of your theme. Deactivating the plugin restores your original site untouched.')
                . $tvex_qa('Can I change the colors and spacing?', 'Select any element and use the Style tab to change colors, backgrounds, font sizes and padding &mdash; no code needed.')
                . $tvex_qa('Does this slow down my site?', 'No. Visitors see clean, lightweight HTML. The editing tools only load for you while edit mode is on.')
                . '</div></div></div>';

        case 'faq-cards':
            $tvex_card = function ($q, $a) {
                return '<div style="background:#f7f7f8;border:1px solid rgba(0,0,0,.06);border-radius:14px;padding:28px;">'
                    . '<h3 style="font-size:18px;font-weight:700;margin:0 0 10px;color:#111111;">' . $q . '</h3>'
                    . '<p style="font-size:15px;line-height:1.7;color:#555555;margin:0;">' . $a . '</p></div>';
            };
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:72px 24px;">'
                . '<p style="font-size:12px;letter-spacing:2.5px;text-transform:uppercase;color:#888888;margin:0 0 12px;text-align:center;">FAQ</p>'
                . '<h2 style="font-size:clamp(28px,4.5vw,44px);font-weight:700;letter-spacing:-1px;text-align:center;margin:0 0 44px;color:#111111;">Questions, answered</h2>'
                . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px;">'
                . $tvex_card('How do I edit this text?', 'Click any card and type &mdash; changes save automatically and show on the live page.')
                . $tvex_card('Will this work with my theme?', 'Yes. Blocks sit on top of your theme as a safe layer; deactivating restores the original site.')
                . $tvex_card('Can I restyle these cards?', 'Select a card, open the Style tab, and change colors, backgrounds, padding and corners visually.')
                . $tvex_card('Does this slow down my site?', 'No &mdash; visitors get clean lightweight HTML. Editing tools load only in edit mode.')
                . '</div></div></div>';

        case 'pricing-table':
            $tier = function ($name, $price, $period, $feats, $hi) {
                $fl = '';
                foreach (explode("\n", $feats) as $fline) {
                    $fline = trim($fline);
                    if ($fline === '') continue;
                    $fl .= '<li style="list-style:none;padding:8px 0;border-bottom:1px solid rgba(0,0,0,.07);font-size:14.5px;">&#10003; ' . esc_html($fline) . '</li>';
                }
                $box = $hi
                    ? 'background:#111111;color:#ffffff;border:2px solid #111111;transform:scale(1.04);'
                    : 'background:#ffffff;color:#111111;border:1px solid rgba(0,0,0,.12);';
                $badge = $hi ? '<span style="display:inline-block;font-size:11px;letter-spacing:2px;text-transform:uppercase;background:#ffffff;color:#111111;padding:6px 14px;border-radius:999px;margin-bottom:16px;">Most popular</span>' : '';
                $btn = $hi ? 'background:#ffffff;color:#111111;' : 'background:#111111;color:#ffffff;';
                return '<div style="flex:1;min-width:230px;border-radius:16px;padding:36px 28px;text-align:center;' . $box . '">'
                    . $badge
                    . '<h3 style="font-size:19px;font-weight:700;margin:0 0 8px;">' . esc_html($name) . '</h3>'
                    . '<p style="font-size:clamp(30px,4vw,44px);font-weight:800;margin:0 0 4px;">' . esc_html($price) . '</p>'
                    . '<p style="font-size:13px;opacity:.65;margin:0 0 20px;">' . esc_html($period) . '</p>'
                    . '<ul style="margin:0 0 24px;padding:0;text-align:left;">' . $fl . '</ul>'
                    . '<a href="#" style="display:inline-block;padding:13px 34px;font-size:15px;font-weight:600;text-decoration:none;border-radius:999px;' . $btn . '">Choose plan</a>'
                    . '</div>';
            };
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:72px 24px;">'
                . '<h2 style="font-size:clamp(26px,4vw,40px);text-align:center;margin:0 0 12px;">Simple, honest pricing</h2>'
                . '<p style="text-align:center;font-size:16px;opacity:.7;margin:0 0 44px;">Click any text to edit. The middle plan is highlighted.</p>'
                . '<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:stretch;">'
                . $tier('Starter', 'Free', 'forever', "1 project\nBasic blocks\nCommunity support", false)
                . $tier('Pro', '$9/mo', 'per site', "Unlimited projects\nAll blocks + designs\nPriority support", true)
                . $tier('Agency', '$29/mo', 'per team', "Everything in Pro\nWhite-label mode\nTeam seats", false)
                . '</div></div></div>';

        case 'gallery-grid':
            $ph2 = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400" fill="#e5e7eb"/><text x="300" y="200" font-family="sans-serif" font-size="22" fill="#9ca3af" text-anchor="middle" dominant-baseline="middle">Click to add image</text></svg>');
            $cell = function ($cap) use ($ph2) {
                return '<figure style="margin:0;">'
                    . '<img src="' . $ph2 . '" alt="' . esc_attr($cap) . '" style="width:100%;height:220px;object-fit:cover;display:block;border-radius:10px;">'
                    . '<figcaption style="font-size:13.5px;opacity:.7;margin-top:8px;">' . esc_html($cap) . '</figcaption>'
                    . '</figure>';
            };
            return $open . '<div style="max-width:1100px;margin:0 auto;padding:64px 24px;">'
                . '<h2 style="font-size:clamp(24px,4vw,36px);margin:0 0 28px;text-align:center;">Gallery</h2>'
                . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;">'
                . $cell('First image — click the image to swap it')
                . $cell('Second image — click the image to swap it')
                . $cell('Third image — click the image to swap it')
                . $cell('Fourth image — click the image to swap it')
                . '</div></div></div>';

        case 'video-embed':
            $vurl = (is_array($s) && !empty($s['video'])) ? tve_sanitize_video_url($s['video']) : '';
            return $open . '<div style="max-width:960px;margin:0 auto;padding:56px 24px;">'
                . '<h2 style="font-size:clamp(24px,4vw,36px);margin:0 0 24px;text-align:center;">Watch this</h2>'
                . tve_video_embed_html($vurl)
                . '</div></div>';

        case 'team-3':
            $ph3 = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><rect width="300" height="300" fill="#e5e7eb"/><circle cx="150" cy="120" r="55" fill="#cbd5e1"/><rect x="75" y="195" width="150" height="70" rx="35" fill="#cbd5e1"/></svg>');
            $member = function ($name, $role) use ($ph3) {
                return '<div style="flex:1;min-width:200px;text-align:center;padding:12px;">'
                    . '<img src="' . $ph3 . '" alt="' . esc_attr($name) . '" style="width:150px;height:150px;object-fit:cover;border-radius:50%;display:block;margin:0 auto 16px;">'
                    . '<h3 style="font-size:19px;font-weight:700;margin:0 0 6px;">' . esc_html($name) . '</h3>'
                    . '<p style="font-size:14px;opacity:.65;margin:0;">' . esc_html($role) . '</p>'
                    . '</div>';
            };
            return $open . '<div style="max-width:1000px;margin:0 auto;padding:72px 24px;">'
                . '<h2 style="font-size:clamp(26px,4vw,40px);text-align:center;margin:0 0 12px;">Meet the team</h2>'
                . '<p style="text-align:center;font-size:16px;opacity:.7;margin:0 0 40px;">Click a photo to swap it, click names to edit.</p>'
                . '<div style="display:flex;gap:24px;flex-wrap:wrap;justify-content:center;">'
                . $member('Jane Doe', 'Founder & CEO')
                . $member('John Smith', 'Lead Designer')
                . $member('Sara Khan', 'Developer')
                . '</div></div></div>';

        case 'social-icons':
            $soc = function ($label, $url) {
                return '<a href="' . esc_url($url) . '" style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border:1px solid rgba(0,0,0,.15);border-radius:999px;font-size:14.5px;font-weight:600;color:inherit;text-decoration:none;">' . esc_html($label) . '</a>';
            };
            return $open . '<div style="max-width:800px;margin:0 auto;padding:48px 24px;text-align:center;">'
                . '<p style="font-size:13px;letter-spacing:2px;text-transform:uppercase;opacity:.6;margin:0 0 16px;">Follow us</p>'
                . '<div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:center;">'
                . $soc('Facebook', 'https://facebook.com/')
                . $soc('Instagram', 'https://instagram.com/')
                . $soc('X', 'https://x.com/')
                . $soc('YouTube', 'https://youtube.com/')
                . $soc('LinkedIn', 'https://linkedin.com/')
                . '</div></div></div>';

        case 'contact-info':
            $row = function ($label, $val) {
                return '<div style="display:flex;gap:16px;padding:14px 0;border-bottom:1px solid rgba(0,0,0,.08);">'
                    . '<span style="flex:0 0 130px;font-size:13px;letter-spacing:1px;text-transform:uppercase;opacity:.6;font-weight:600;">' . esc_html($label) . '</span>'
                    . '<span style="font-size:16px;">' . esc_html($val) . '</span>'
                    . '</div>';
            };
            return $open . '<div style="max-width:720px;margin:0 auto;padding:64px 24px;">'
                . '<h2 style="font-size:clamp(24px,4vw,36px);margin:0 0 24px;text-align:center;">Get in touch</h2>'
                . $row('Address', '123 Main Street, Your City')
                . $row('Phone', '+1 (555) 123-4567')
                . $row('Email', 'hello@example.com')
                . $row('Hours', 'Mon–Fri, 9am–6pm')
                . '</div></div>';
    }
    return '';
}

/* ---------------- settings & block toggles (v1.5) ---------------- */

function tve_get_settings() {
    $defaults = array(
        'overlay_enabled' => 1,
        'default_device' => 'desktop',
        'history_limit' => 15,
    );
    $s = get_option('tve_settings', array());
    if (!is_array($s)) $s = array();
    $s = array_merge($defaults, $s);
    if (!in_array($s['default_device'], array('desktop', 'tablet', 'phone'), true)) {
        $s['default_device'] = 'desktop';
    }
    $s['history_limit'] = max(1, min(100, intval($s['history_limit'])));
    return $s;
}

function tve_overlay_enabled() {
    $s = tve_get_settings();
    return !empty($s['overlay_enabled']);
}

function tve_history_limit() {
    $s = tve_get_settings();
    return $s['history_limit'];
}

/* Disabled block ids, stored as a single option array. */
function tve_disabled_blocks() {
    $d = get_option('tve_disabled_blocks', array());
    if (!is_array($d)) return array();
    $all = array_keys(tve_block_ids());
    return array_values(array_intersect(array_map('sanitize_key', $d), $all));
}

/* Blocks shown in the frontend overlay block panel (disabled ones excluded). */
function tve_enabled_block_ids() {
    $all = tve_block_ids();
    foreach (tve_disabled_blocks() as $id) {
        unset($all[$id]);
    }
    return $all;
}

/*
 * Block groups for the dashboard. Mirrors the frontend panel grouping in
 * assets/editor.js (BLOCK_GROUPS): Headers / FAQ / Content blocks.
 */
function tve_block_groups() {
    $names = tve_block_ids();
    $listed = array();
    $groups = array(
        array('title' => __('Headers', 'tabaix-visual-editor'), 'ids' => array('header-bar', 'header-center', 'header-minimal')),
        array('title' => __('FAQ', 'tabaix-visual-editor'), 'ids' => array('faq-plus', 'faq-cards')),
        array('title' => __('Content blocks', 'tabaix-visual-editor'), 'ids' => null),
    );
    $out = array();
    foreach ($groups as $g) {
        $ids = $g['ids'];
        if ($ids === null) {
            $ids = array();
            foreach ($names as $id => $label) {
                if (!in_array($id, $listed, true)) $ids[] = $id;
            }
        }
        $ids = array_values(array_filter($ids, function ($id) use ($names, $listed) {
            return isset($names[$id]) && !in_array($id, $listed, true);
        }));
        foreach ($ids as $id) $listed[] = $id;
        if (!empty($ids)) $out[] = array('title' => $g['title'], 'ids' => $ids);
    }
    return $out;
}

/* Trim every page's revision stack to $limit (used when the setting changes). */
function tve_trim_all_revisions($limit) {
    $limit = max(1, min(100, intval($limit)));
    $all = get_option('tve_data', array());
    if (!is_array($all)) return;
    $changed = false;
    foreach ($all as $key => $page) {
        if (!is_array($page) || empty($page['revisions']) || !is_array($page['revisions'])) continue;
        $trimmed = array_slice($page['revisions'], 0, $limit);
        if (count($trimmed) !== count($page['revisions'])) {
            $all[$key]['revisions'] = $trimmed;
            $changed = true;
        }
    }
    if ($changed) update_option('tve_data', $all, false);
}

/* ---------------- admin dashboard ---------------- */

add_action('admin_menu', function () {
    add_menu_page(
        __('Visual Editor', 'tabaix-visual-editor'),
        __('Visual Editor', 'tabaix-visual-editor'),
        'manage_options',
        'tve-dashboard',
        'tve_render_admin_page',
        'dashicons-admin-customizer',
        61
    );
});

function tve_admin_notice($message, $type = 'success') {
    return '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
}

function tve_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'tabaix-visual-editor'));
    }
    $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'blocks';
    $tabs = array(
        'blocks' => __('Blocks Library', 'tabaix-visual-editor'),
        'settings' => __('Settings', 'tabaix-visual-editor'),
        'history' => __('History', 'tabaix-visual-editor'),
    );
    if (!isset($tabs[$tab])) $tab = 'blocks';

    $notice = '';
    if ($tab === 'blocks') $notice = tve_admin_handle_blocks();
    elseif ($tab === 'settings') $notice = tve_admin_handle_settings();
    elseif ($tab === 'history') $notice = tve_admin_handle_history();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Visual Editor', 'tabaix-visual-editor'); ?></h1>
        <?php
        if ($notice !== '') {
            echo $notice; // built by tve_admin_notice(), already escaped
        }
        ?>
        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $slug => $label) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tve-dashboard&tab=' . $slug)); ?>"
                   class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php
        if ($tab === 'blocks') tve_admin_blocks_tab();
        elseif ($tab === 'settings') tve_admin_settings_tab();
        else tve_admin_history_tab();
        ?>
    </div>
    <?php
}

/* ----- Blocks Library tab ----- */

function tve_admin_handle_blocks() {
    if (!isset($_POST['tve_blocks_save'])) return '';
    if (!current_user_can('manage_options')) {
        return tve_admin_notice(__('Permission denied.', 'tabaix-visual-editor'), 'error');
    }
    check_admin_referer('tve_blocks_save', 'tve_blocks_nonce');
    $enabled = array();
    if (isset($_POST['tve_blocks']) && is_array($_POST['tve_blocks'])) {
        $enabled = array_map('sanitize_key', array_keys($_POST['tve_blocks']));
    }
    $disabled = array_values(array_diff(array_keys(tve_block_ids()), $enabled));
    update_option('tve_disabled_blocks', $disabled, false);
    return tve_admin_notice(__('Block library updated. Disabled blocks no longer appear in the editor block panel.', 'tabaix-visual-editor'), 'success');
}

function tve_admin_blocks_tab() {
    $names = tve_block_ids();
    $disabled = tve_disabled_blocks();
    $groups = tve_block_groups();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=tve-dashboard&tab=blocks')); ?>">
        <?php wp_nonce_field('tve_blocks_save', 'tve_blocks_nonce'); ?>
        <p class="description">
            <?php esc_html_e('Choose which blocks are available in the "Add a block" panel of the visual editor. Disabled blocks are hidden from the panel (blocks already placed on pages keep working).', 'tabaix-visual-editor'); ?>
        </p>
        <?php foreach ($groups as $g) : ?>
            <h2><?php echo esc_html($g['title']); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:60px;"><?php esc_html_e('Enabled', 'tabaix-visual-editor'); ?></th>
                        <th><?php esc_html_e('Block', 'tabaix-visual-editor'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($g['ids'] as $id) : ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="tve_blocks[<?php echo esc_attr($id); ?>]" value="1"
                                    <?php checked(!in_array($id, $disabled, true)); ?> />
                            </td>
                            <td>
                                <strong><?php echo esc_html($names[$id]); ?></strong>
                                <code style="margin-left:8px;"><?php echo esc_html($id); ?></code>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
        <p class="submit">
            <input type="submit" name="tve_blocks_save" class="button button-primary"
                value="<?php echo esc_attr__('Save block library', 'tabaix-visual-editor'); ?>" />
        </p>
    </form>
    <?php
}

/* ----- Settings tab ----- */

function tve_admin_handle_settings() {
    if (!isset($_POST['tve_settings_save'])) return '';
    if (!current_user_can('manage_options')) {
        return tve_admin_notice(__('Permission denied.', 'tabaix-visual-editor'), 'error');
    }
    check_admin_referer('tve_settings_save', 'tve_settings_nonce');
    $overlay = isset($_POST['tve_overlay_enabled']) ? 1 : 0;
    $device = isset($_POST['tve_default_device']) ? sanitize_key(wp_unslash($_POST['tve_default_device'])) : 'desktop';
    if (!in_array($device, array('desktop', 'tablet', 'phone'), true)) $device = 'desktop';
    $limit = isset($_POST['tve_history_limit']) ? intval($_POST['tve_history_limit']) : 15;
    $limit = max(1, min(100, $limit));
    update_option('tve_settings', array(
        'overlay_enabled' => $overlay,
        'default_device' => $device,
        'history_limit' => $limit,
    ), false);
    tve_trim_all_revisions($limit);
    return tve_admin_notice(__('Settings saved.', 'tabaix-visual-editor'), 'success');
}

function tve_admin_settings_tab() {
    $s = tve_get_settings();
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=tve-dashboard&tab=settings')); ?>">
        <?php wp_nonce_field('tve_settings_save', 'tve_settings_nonce'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Frontend overlay', 'tabaix-visual-editor'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="tve_overlay_enabled" value="1" <?php checked(!empty($s['overlay_enabled'])); ?> />
                        <?php esc_html_e('Enable the visual editing overlay', 'tabaix-visual-editor'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('When disabled, Visual Edit cannot be launched from the admin bar, post lists, block editor or Customizer, and previously saved overlay edits are not applied to the frontend.', 'tabaix-visual-editor'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="tve_default_device"><?php esc_html_e('Default device preview', 'tabaix-visual-editor'); ?></label>
                </th>
                <td>
                    <select name="tve_default_device" id="tve_default_device">
                        <option value="desktop" <?php selected($s['default_device'], 'desktop'); ?>><?php esc_html_e('Desktop', 'tabaix-visual-editor'); ?></option>
                        <option value="tablet" <?php selected($s['default_device'], 'tablet'); ?>><?php esc_html_e('Tablet', 'tabaix-visual-editor'); ?></option>
                        <option value="phone" <?php selected($s['default_device'], 'phone'); ?>><?php esc_html_e('Phone', 'tabaix-visual-editor'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Which device preview the editor starts with when opening edit mode (a per-browser saved choice still overrides this).', 'tabaix-visual-editor'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="tve_history_limit"><?php esc_html_e('History snapshot limit', 'tabaix-visual-editor'); ?></label>
                </th>
                <td>
                    <input type="number" name="tve_history_limit" id="tve_history_limit"
                        value="<?php echo esc_attr($s['history_limit']); ?>" min="1" max="100" step="1" class="small-text" />
                    <p class="description">
                        <?php esc_html_e('How many undo/history snapshots are kept per page (1–100). Lowering this trims older snapshots immediately.', 'tabaix-visual-editor'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <p class="submit">
            <input type="submit" name="tve_settings_save" class="button button-primary"
                value="<?php echo esc_attr__('Save settings', 'tabaix-visual-editor'); ?>" />
        </p>
    </form>
    <?php
}

/* ----- History tab ----- */

function tve_admin_history_rows() {
    $all = get_option('tve_data', array());
    if (!is_array($all)) $all = array();
    $paths = get_option('tve_page_paths', array());
    if (!is_array($paths)) $paths = array();
    $rows = array();
    foreach ($all as $key => $page) {
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $key)) continue;
        if (!is_array($page)) continue;
        $revs = (isset($page['revisions']) && is_array($page['revisions'])) ? $page['revisions'] : array();
        if (empty($revs)) continue;
        $path = isset($paths[$key]) ? $paths[$key] : '';
        $title = '';
        $url = '';
        if ($path !== '' && $path[0] === '/') {
            $url = home_url($path);
            $post_id = url_to_postid($url);
            if ($post_id) $title = get_the_title($post_id);
        }
        if ($title === '') $title = $path !== '' ? $path : sprintf(__('Page %s', 'tabaix-visual-editor'), substr($key, 0, 8));
        $rows[] = array(
            'key' => $key,
            'title' => $title,
            'url' => $url,
            'count' => count($revs),
            'time' => isset($revs[0]['time']) ? intval($revs[0]['time']) : 0,
        );
    }
    usort($rows, function ($a, $b) { return $b['time'] - $a['time']; });
    return $rows;
}

/* Restore the snapshot at $index for the page identified by $key. */
function tve_admin_restore_revision($key, $index) {
    $page = tve_get_page_by_key($key);
    $revs = (isset($page['revisions']) && is_array($page['revisions'])) ? $page['revisions'] : array();
    if (!isset($revs[$index])) return false;
    $snap = $revs[$index];
    tve_push_revision($page); // keep the current state undoable
    $revs = $page['revisions'];
    $found = null; // locate the snapshot again (the push may have shifted indexes)
    foreach ($revs as $k => $r) {
        if (isset($r['time'], $r['data']) && $r['time'] === $snap['time']
            && serialize($r['data']) === serialize($snap['data'])) { $found = $k; break; }
    }
    $page = array_merge(tve_default_page(), $snap['data']);
    if ($found !== null) unset($revs[$found]);
    $page['revisions'] = array_values($revs);
    tve_save_page_by_key($key, $page);
    return true;
}

function tve_admin_handle_history() {
    if (!isset($_GET['tve_action'])) return '';
    if (!current_user_can('manage_options')) {
        return tve_admin_notice(__('Permission denied.', 'tabaix-visual-editor'), 'error');
    }
    $action = sanitize_key($_GET['tve_action']);
    $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $key)) {
        return tve_admin_notice(__('Invalid page.', 'tabaix-visual-editor'), 'error');
    }
    check_admin_referer('tve_history_' . $key);
    if ($action === 'restore') {
        $index = isset($_GET['index']) ? intval($_GET['index']) : 0;
        if (tve_admin_restore_revision($key, $index)) {
            return tve_admin_notice(__('Snapshot restored. The previous state was kept as a new snapshot, so you can undo this restore.', 'tabaix-visual-editor'), 'success');
        }
        return tve_admin_notice(__('Snapshot not found.', 'tabaix-visual-editor'), 'error');
    }
    if ($action === 'delete') {
        $page = tve_get_page_by_key($key);
        $page['revisions'] = array();
        tve_save_page_by_key($key, $page);
        return tve_admin_notice(__('Snapshots deleted for this page.', 'tabaix-visual-editor'), 'success');
    }
    return '';
}

function tve_admin_history_tab() {
    $rows = tve_admin_history_rows();
    if (empty($rows)) {
        echo '<p>' . esc_html__('No edit snapshots yet. Snapshots are created automatically as you edit pages with the visual editor.', 'tabaix-visual-editor') . '</p>';
        return;
    }
    ?>
    <p class="description">
        <?php esc_html_e('Restore reverts a page to its most recent snapshot (the current state is kept, so you can undo the restore). Delete removes all snapshots for the page.', 'tabaix-visual-editor'); ?>
    </p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e('Page', 'tabaix-visual-editor'); ?></th>
                <th><?php esc_html_e('Last snapshot', 'tabaix-visual-editor'); ?></th>
                <th><?php esc_html_e('Snapshots', 'tabaix-visual-editor'); ?></th>
                <th><?php esc_html_e('Actions', 'tabaix-visual-editor'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row) :
                $restore_url = wp_nonce_url(
                    admin_url('admin.php?page=tve-dashboard&tab=history&tve_action=restore&key=' . $row['key']),
                    'tve_history_' . $row['key']
                );
                $delete_url = wp_nonce_url(
                    admin_url('admin.php?page=tve-dashboard&tab=history&tve_action=delete&key=' . $row['key']),
                    'tve_history_' . $row['key']
                );
                ?>
                <tr>
                    <td>
                        <?php if ($row['url'] !== '') : ?>
                            <a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($row['title']); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($row['title']); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo $row['time'] ? esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $row['time'])) : '&mdash;'; ?>
                    </td>
                    <td><?php echo esc_html(number_format_i18n($row['count'])); ?></td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url($restore_url); ?>">
                            <?php esc_html_e('Restore', 'tabaix-visual-editor'); ?>
                        </a>
                        <a class="button button-small button-link-delete" href="<?php echo esc_url($delete_url); ?>"
                           onclick="return confirm('<?php echo esc_js(__('Delete all snapshots for this page? This cannot be undone.', 'tabaix-visual-editor')); ?>');">
                            <?php esc_html_e('Delete', 'tabaix-visual-editor'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
