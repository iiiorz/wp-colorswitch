<?php
/**
 * Plugin Name: ColorSwitch
 * Description: A theme-independent color scheme (dark mode) toggle plugin. Everything is a rule: selector × colors, with exceptions, memos and user presets.
 * Version: 1.1.0
 * Author: iiiiorz
 * License: GPL-2.0-or-later
 * Text Domain: color-switch
 * Domain Path: /languages
 *
 * Design notes:
 * - Switching toggles the 'dark-mode' class on <html>. The FOUC guard script is
 *   printed at wp_head priority 1 and the CSS at priority 999 (a proven way to
 *   win over theme stylesheets).
 * - Settings live in a single option, csw_settings. Each settings tab posts a
 *   partial form, so the sanitizer merges submitted keys into the saved values.
 * - Free-text values (custom icons, rule memos, preset titles) are stored
 *   base64-encoded so that 4-byte characters (emoji) cannot silently fail to
 *   save on utf8mb3 databases.
 * - No special "core" rules: every rule can be edited, reordered or
 *   deleted. The list order is the priority order (top wins on ties),
 *   and exceptions outrank every rule.
 * - Button styling: font size/weight/family, size, shape, per-state
 *   background/text colors with transparency, numeric position
 *   offsets, and a live preview. Custom icons accept Font Awesome
 *   class names. "Initial display" is Normal/Switched plus an
 *   optional "follow the OS dark mode" checkbox.
 * - Preset apply verifies the write and lands on the Rules tab so the
 *   result is visible. Note: the registered sanitize callback also
 *   runs on update_option() inside admin requests, so it must accept
 *   programmatically stored full settings arrays, not just form
 *   posts — otherwise a preset apply would replace the exceptions
 *   but silently keep the old rules.
 */

if (!defined('ABSPATH')) exit;

define('CSW_OPTION', 'csw_settings');
define('CSW_PRESETS_OPTION', 'csw_presets');
define('CSW_PRESET_MAX', 10);

add_action('init', function () {
    load_plugin_textdomain('color-switch', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/* ---------------- Text encoding (utf8mb3-safe) ---------------- */

/* An empty value is stored as an empty string, never as a bare 'b64:'
   marker (which would otherwise show up in form fields as-is). */
function csw_txt_encode($v) {
    $v = (string) $v;
    if ($v === '') return '';
    return 'b64:' . base64_encode($v);
}
function csw_txt_decode($v) {
    $guard = 0;
    while (is_string($v) && strpos($v, 'b64:') === 0 && $guard < 3) {
        $rest = substr($v, 4);
        if ($rest === '') { $v = ''; break; } // a bare 'b64:' marker means an empty value
        $d = base64_decode($rest, true);
        if ($d === false) break;
        $v = $d;
        $guard++;
    }
    return $v;
}

/* ---------------- Rules ---------------- */

/* Initial rules for a fresh install. Every rule is an ordinary rule:
   it can be edited, reordered and deleted. */
function csw_initial_rules() {
    return array(
        array('selector' => 'body',
              'bg' => '#242424', 'color' => '#ffffff', 'border' => '', 'enabled' => 1, 'memo' => '背景色・文字色'),
        array('selector' => 'a',
              'bg' => '', 'color' => '#6cb2f5', 'border' => '', 'enabled' => 1, 'memo' => 'リンク'),
        array('selector' => 'a:hover',
              'bg' => '', 'color' => '#a8cfff', 'border' => '', 'enabled' => 1, 'memo' => 'リンクホバー'),
    );
}

function csw_default_excludes() {
    return "pre //コードブロック\ncode //コードブロック";
}

function csw_defaults() {
    return array(
        'ver' => 2,
        'rules' => csw_initial_rules(),
        // Exceptions, one per line. "// comment" after a selector is a memo.
        'exclude_selectors' => csw_default_excludes(),
        'img_dim' => 1, // Dim images slightly
        // Button
        'button_bg' => '#393939',
        'button_color' => '#ffffff',
        'button_bg_transparency' => 0, // 0 = opaque, 100 = fully transparent
        'button_color_transparency' => 0,
        // Optional separate colors for the switched state ('' = same as normal)
        'button_colors_separate' => 0,
        'button_bg_switched' => '',
        'button_color_switched' => '',
        'button_bg_transparency_switched' => 0,
        'button_color_transparency_switched' => 0,
        'button_font_size' => 22,      // px
        'button_font_weight' => '',    // '' = theme default, or 300..900 / normal / bold
        'button_font_family' => '',    // '' = theme default
        'button_size' => 48,           // px (legacy single size, kept as the migration source)
        'button_width' => 48,          // px
        'button_height' => 48,         // px
        'button_shape' => 'circle',    // circle|rounded|square
        'button_radius' => 12,         // px, used when shape = rounded
        'icon_preset' => 'emoji_auto', // emoji_auto|emoji_moon|unicode|custom
        'icon_custom_light' => '🌙',
        'icon_custom_dark' => '☀️',
        'position' => 'bottom_right', // bottom_right|bottom_left|top_right|top_left|shortcode
        'position_offset_x' => 16,    // px from the left/right edge (floating positions)
        'position_offset_y' => 16,    // px from the top/bottom edge (floating positions)
        'default_mode' => 'normal',   // normal|switched
        'os_dark_auto' => 0,          // 1 = auto-switch when the OS is in dark mode
    );
}

/* Map a legacy default_mode (auto|light|dark, from early development
   builds) onto the current pair (default_mode normal|switched +
   os_dark_auto). Returns null when the saved value is already a
   current value or absent. */
function csw_legacy_default_mode($saved) {
    if (!isset($saved['default_mode'])) return null;
    switch ($saved['default_mode']) {
        case 'auto':  return array('default_mode' => 'normal', 'os_dark_auto' => 1);
        case 'light': return array('default_mode' => 'normal', 'os_dark_auto' => 0);
        case 'dark':  return array('default_mode' => 'switched', 'os_dark_auto' => 0);
    }
    return null;
}

/* ---------------- Settings access ---------------- */

function csw_rules_decode($rules) {
    if (!is_array($rules)) return array();
    foreach ($rules as &$r) {
        if (isset($r['memo'])) $r['memo'] = csw_txt_decode($r['memo']);
        unset($r['core']); // legacy core flags are meaningless now; drop them
    }
    unset($r);
    return $rules;
}

function csw_rules_encode($rules) {
    if (!is_array($rules)) return array();
    foreach ($rules as &$r) {
        if (isset($r['memo'])) $r['memo'] = csw_txt_encode(csw_txt_decode($r['memo']));
    }
    unset($r);
    return $rules;
}

/* Convert a legacy settings array (base colors, from early
   development builds) into the rule structure. */
function csw_upgrade_v1($saved) {
    $out = csw_defaults();
    $rules = csw_initial_rules();
    if (!empty($saved['dark_bg'])) $rules[0]['bg'] = $saved['dark_bg'];
    if (!empty($saved['dark_text'])) $rules[0]['color'] = $saved['dark_text'];
    if (!empty($saved['dark_link'])) $rules[1]['color'] = $saved['dark_link'];
    if (!empty($saved['dark_link_hover'])) $rules[2]['color'] = $saved['dark_link_hover'];
    if (!empty($saved['rules']) && is_array($saved['rules'])) {
        foreach ($saved['rules'] as $r) {
            if (!is_array($r)) continue;
            $rules[] = array(
                'selector' => isset($r['selector']) ? $r['selector'] : '',
                'bg' => isset($r['bg']) ? $r['bg'] : '',
                'color' => isset($r['color']) ? $r['color'] : '',
                'border' => isset($r['border']) ? $r['border'] : '',
                'enabled' => !empty($r['enabled']) ? 1 : 0,
                'memo' => '',
            );
        }
    }
    $out['rules'] = $rules;
    if (isset($saved['exclude_selectors'])) $out['exclude_selectors'] = (string) $saved['exclude_selectors'];
    if (isset($saved['img_dim'])) $out['img_dim'] = !empty($saved['img_dim']) ? 1 : 0;
    foreach (array('button_bg', 'button_color', 'icon_preset', 'position', 'default_mode') as $k) {
        if (isset($saved[$k])) $out[$k] = $saved[$k];
    }
    foreach (array('icon_custom_light', 'icon_custom_dark') as $k) {
        if (isset($saved[$k])) $out[$k] = csw_txt_decode($saved[$k]);
    }
    $legacy = csw_legacy_default_mode($out);
    if ($legacy !== null) { $out['default_mode'] = $legacy['default_mode']; $out['os_dark_auto'] = $legacy['os_dark_auto']; }
    if ($out['icon_preset'] === 'text') $out['icon_preset'] = 'emoji_auto';
    $out['ver'] = 2;
    return $out;
}

function csw_settings() {
    $saved = get_option(CSW_OPTION, array());
    if (!is_array($saved)) $saved = array();
    if (isset($saved['dark_bg']) && !isset($saved['ver'])) {
        $s = csw_upgrade_v1($saved); // already in decoded form
        return $s;
    }
    $legacy = csw_legacy_default_mode($saved);
    if ($legacy !== null && !isset($saved['os_dark_auto'])) {
        $saved['default_mode'] = $legacy['default_mode'];
        $saved['os_dark_auto'] = $legacy['os_dark_auto'];
    }
    $s = array_merge(csw_defaults(), $saved);
    // Migrate the legacy single button_size to separate width/height.
    if (!isset($saved['button_width'])) $s['button_width'] = $s['button_size'];
    if (!isset($saved['button_height'])) $s['button_height'] = $s['button_size'];
    if ($s['icon_preset'] === 'text') $s['icon_preset'] = 'emoji_auto'; // legacy 'text' icon maps to emoji_auto
    $s['icon_custom_light'] = csw_txt_decode($s['icon_custom_light']);
    $s['icon_custom_dark'] = csw_txt_decode($s['icon_custom_dark']);
    $s['rules'] = csw_rules_decode($s['rules']);
    return $s;
}

/* Store a decoded settings array (encodes free-text values first). */
function csw_store_settings($s) {
    $s['icon_custom_light'] = csw_txt_encode($s['icon_custom_light']);
    $s['icon_custom_dark'] = csw_txt_encode($s['icon_custom_dark']);
    $s['rules'] = csw_rules_encode($s['rules']);
    $s['ver'] = 2;
    update_option(CSW_OPTION, $s);
}

/* ---------------- Sanitizing ---------------- */

/* Allowed CSS color values: #hex / transparent / var(--name) only */
function csw_css_color($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    $hex = sanitize_hex_color($v);
    if ($hex) return $hex;
    if (strtolower($v) === 'transparent') return 'transparent';
    if (preg_match('/^var\(--[a-zA-Z0-9\-_]+\)$/', $v)) return $v;
    return '';
}

/* Allowed selector text: strip characters usable for style escape / injection */
function csw_sanitize_selector($sel) {
    $sel = wp_strip_all_tags((string) $sel);
    $sel = str_replace(array('{', '}', '<', '>', ';', '@'), '', $sel);
    $sel = trim(preg_replace('/\s+/', ' ', $sel));
    return $sel;
}

/*
 * Parse the exceptions text into a selector list.
 * One selector per line; "// memo" after a selector (or a line starting
 * with //) is a comment and is not treated as part of the selector.
 */
function csw_parse_excludes($text) {
    $out = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/(^|\s)\/\//', $line, $m, PREG_OFFSET_CAPTURE)) {
            $line = trim(substr($line, 0, $m[0][1]));
        }
        if ($line === '') continue;
        foreach (explode(',', $line) as $p) {
            $p = csw_sanitize_selector($p);
            if ($p !== '') $out[] = $p;
        }
    }
    return array_values(array_unique($out));
}

/*
 * Sanitize raw rule rows. Memos are returned encoded.
 * Every row is an ordinary rule: nothing is restored or protected,
 * so any rule (including the initial ones) can be deleted.
 */
function csw_sanitize_rules($raw_rows) {
    $rows = array();
    if (is_array($raw_rows)) {
        foreach ($raw_rows as $r) {
            if (!is_array($r)) continue;
            $sel = isset($r['selector']) ? csw_sanitize_selector($r['selector']) : '';
            $row = array(
                'selector' => $sel,
                'bg' => isset($r['bg']) ? csw_css_color($r['bg']) : '',
                'color' => isset($r['color']) ? csw_css_color($r['color']) : '',
                'border' => isset($r['border']) ? csw_css_color($r['border']) : '',
                'enabled' => !empty($r['enabled']) ? 1 : 0,
                // Decode first so an already-encoded memo (when the stored
                // settings pass through this sanitizer again, see below)
                // is not wrapped in another encoding layer.
                'memo' => csw_txt_encode(csw_txt_decode(isset($r['memo']) ? sanitize_text_field($r['memo']) : '')),
            );
            if ($sel === '' && $row['bg'] === '' && $row['color'] === '' && $row['border'] === '') continue;
            $rows[] = $row;
        }
    }
    return $rows;
}

add_action('admin_init', function () {
    register_setting('csw_settings_group', CSW_OPTION, array('sanitize_callback' => 'csw_sanitize'));
});

function csw_sanitize($input) {
    if (!is_array($input)) $input = array();
    $saved = get_option(CSW_OPTION, array());
    if (!is_array($saved)) $saved = array();
    $current = (isset($saved['dark_bg']) && !isset($saved['ver'])) ? array() : $saved;
    $out = array_merge(csw_defaults(), $current); // merge over saved values (per-tab partial forms)

    // The Rules tab sends rules_present + rules. A programmatic store
    // (csw_store_settings, e.g. preset apply) sends the full settings
    // array including 'rules' but no rules_present marker — and because
    // this sanitizer also runs as the sanitize_option filter on every
    // update_option() in admin requests, it must accept that form too.
    // (Dropping them here would break preset apply: the exceptions
    // would be replaced but the rules never would.)
    if (isset($input['rules_present']) || isset($input['rules'])) {
        $out['rules'] = csw_sanitize_rules(isset($input['rules']) ? $input['rules'] : array());
    }
    if (isset($input['exclude_selectors'])) {
        $out['exclude_selectors'] = sanitize_textarea_field($input['exclude_selectors']);
    }
    if (isset($input['img_dim_present'])) {
        $out['img_dim'] = !empty($input['img_dim']) ? 1 : 0;
    }
    if (isset($input['button_bg'])) {
        $c = csw_css_color($input['button_bg']);
        if ($c !== '' && ($c[0] === '#' || $c === 'transparent')) $out['button_bg'] = $c;
    }
    if (isset($input['button_color'])) {
        $c = sanitize_hex_color($input['button_color']);
        if ($c) $out['button_color'] = $c;
    }
    if (isset($input['button_bg_transparency'])) {
        $out['button_bg_transparency'] = max(0, min(100, intval($input['button_bg_transparency'])));
    }
    if (isset($input['button_color_transparency'])) {
        $out['button_color_transparency'] = max(0, min(100, intval($input['button_color_transparency'])));
    }
    if (isset($input['button_colors_separate_present'])) {
        $out['button_colors_separate'] = !empty($input['button_colors_separate']) ? 1 : 0;
    }
    // Switched-state colors may be cleared back to '' (= same as normal),
    // so unlike the normal colors they are stored even when empty.
    if (isset($input['button_bg_switched'])) {
        $out['button_bg_switched'] = csw_css_color($input['button_bg_switched']);
    }
    if (isset($input['button_color_switched'])) {
        $c = sanitize_hex_color($input['button_color_switched']);
        $out['button_color_switched'] = $c ? $c : '';
    }
    if (isset($input['button_bg_transparency_switched'])) {
        $out['button_bg_transparency_switched'] = max(0, min(100, intval($input['button_bg_transparency_switched'])));
    }
    if (isset($input['button_color_transparency_switched'])) {
        $out['button_color_transparency_switched'] = max(0, min(100, intval($input['button_color_transparency_switched'])));
    }
    if (isset($input['button_font_size'])) {
        $out['button_font_size'] = max(8, min(64, intval($input['button_font_size'])));
    }
    if (isset($input['button_font_weight']) && in_array((string) $input['button_font_weight'], array('', '300', '400', '500', '600', '700', '800', '900', 'normal', 'bold'), true)) {
        $out['button_font_weight'] = (string) $input['button_font_weight'];
    }
    if (isset($input['button_font_family'])) {
        $ff = sanitize_text_field($input['button_font_family']);
        $out['button_font_family'] = str_replace(array('{', '}', '<', '>', ';'), '', $ff);
    }
    if (isset($input['button_size'])) {
        $out['button_size'] = max(24, min(120, intval($input['button_size'])));
    }
    if (isset($input['button_width'])) {
        $out['button_width'] = max(24, min(600, intval($input['button_width'])));
    }
    if (isset($input['button_height'])) {
        $out['button_height'] = max(24, min(200, intval($input['button_height'])));
    }
    if (isset($input['button_shape']) && in_array($input['button_shape'], array('circle', 'rounded', 'square'), true)) {
        $out['button_shape'] = $input['button_shape'];
    }
    if (isset($input['button_radius'])) {
        $out['button_radius'] = max(0, min(60, intval($input['button_radius'])));
    }
    if (isset($input['icon_preset']) && in_array($input['icon_preset'], array('emoji_auto', 'emoji_moon', 'unicode', 'custom'), true)) {
        $out['icon_preset'] = $input['icon_preset'];
    }
    foreach (array('icon_custom_light', 'icon_custom_dark') as $k) {
        if (isset($input[$k])) $out[$k] = csw_txt_encode(csw_txt_decode(sanitize_text_field($input[$k])));
    }
    foreach (array('icon_custom_light', 'icon_custom_dark') as $k) {
        if (strpos((string) $out[$k], 'b64:') !== 0) $out[$k] = csw_txt_encode((string) $out[$k]);
    }
    if (isset($input['position']) && in_array($input['position'], array('bottom_right', 'bottom_left', 'top_right', 'top_left', 'shortcode'), true)) {
        $out['position'] = $input['position'];
    }
    if (isset($input['position_offset_x'])) {
        $out['position_offset_x'] = max(0, min(200, intval($input['position_offset_x'])));
    }
    if (isset($input['position_offset_y'])) {
        $out['position_offset_y'] = max(0, min(200, intval($input['position_offset_y'])));
    }
    if (isset($input['default_mode'])) {
        if (in_array($input['default_mode'], array('normal', 'switched'), true)) {
            $out['default_mode'] = $input['default_mode'];
        } else {
            $legacy = csw_legacy_default_mode(array('default_mode' => $input['default_mode']));
            if ($legacy !== null) {
                $out['default_mode'] = $legacy['default_mode'];
                $out['os_dark_auto'] = $legacy['os_dark_auto'];
            }
        }
    }
    if (isset($input['os_dark_auto_present'])) {
        $out['os_dark_auto'] = !empty($input['os_dark_auto']) ? 1 : 0;
    }
    $out['ver'] = 2;
    return $out;
}

/* ---------------- User presets ---------------- */

/* Stored form: titles and rule memos are encoded. */
function csw_presets_raw() {
    $p = get_option(CSW_PRESETS_OPTION, array());
    return is_array($p) ? array_values($p) : array();
}

/* Decoded view used by the admin UI and export. */
function csw_presets() {
    $out = array();
    foreach (csw_presets_raw() as $p) {
        if (!is_array($p)) continue;
        $p['title'] = csw_txt_decode(isset($p['title']) ? $p['title'] : '');
        $p['rules'] = csw_rules_decode(isset($p['rules']) ? $p['rules'] : array());
        $out[] = $p;
    }
    return $out;
}

function csw_presets_save($list) {
    update_option(CSW_PRESETS_OPTION, array_values($list));
}

/* Save the current rules + exceptions as a new preset. Returns true or 'cap'. */
function csw_preset_save_current($title) {
    $list = csw_presets_raw();
    if (count($list) >= CSW_PRESET_MAX) return 'cap';
    $s = csw_settings();
    $list[] = array(
        'id' => uniqid('p', true),
        'title' => csw_txt_encode($title),
        'rules' => csw_rules_encode($s['rules']),
        'exclude_selectors' => $s['exclude_selectors'],
        'created' => time(),
    );
    csw_presets_save($list);
    return true;
}

function csw_preset_find($list, $id) {
    foreach ($list as $i => $p) {
        if (isset($p['id']) && $p['id'] === $id) return $i;
    }
    return -1;
}

/* Apply a preset to the current settings (rules + exceptions only).
   Returns true on success, 'notfound' when no preset has that id, and
   false when the write could not be verified. The stored option is read
   back and compared, so a silent write failure is reported instead of
   showing a false "applied" notice — and a verification failure is
   reported as such, not misreported as "preset not found". */
function csw_preset_apply($id) {
    $list = csw_presets_raw();
    $i = csw_preset_find($list, trim((string) $id));
    if ($i < 0) return 'notfound';
    $s = csw_settings();
    $s['rules'] = csw_rules_decode($list[$i]['rules']);
    $s['exclude_selectors'] = isset($list[$i]['exclude_selectors']) ? $list[$i]['exclude_selectors'] : '';
    csw_store_settings($s);
    $check = csw_settings();
    if (count($check['rules']) !== count($s['rules'])) return false;
    foreach ($s['rules'] as $j => $r) {
        if (!isset($check['rules'][$j]) || (string) $check['rules'][$j]['selector'] !== (string) $r['selector']
            || (int) $check['rules'][$j]['enabled'] !== (int) $r['enabled']) return false;
    }
    return (string) $check['exclude_selectors'] === (string) $s['exclude_selectors'];
}

function csw_preset_delete($id) {
    $list = csw_presets_raw();
    $i = csw_preset_find($list, $id);
    if ($i < 0) return false;
    array_splice($list, $i, 1);
    csw_presets_save($list);
    return true;
}

function csw_presets_export_data() {
    $presets = array();
    foreach (csw_presets() as $p) {
        $presets[] = array(
            'title' => $p['title'],
            'rules' => $p['rules'],
            'exclude_selectors' => isset($p['exclude_selectors']) ? $p['exclude_selectors'] : '',
        );
    }
    return array(
        'app' => 'ColorSwitch',
        'kind' => 'colorswitch-presets',
        'version' => 1,
        'presets' => $presets,
    );
}

/*
 * Validate and sanitize imported preset data.
 * Returns a list of raw (encoded) presets, or a string error code:
 * 'format' (unrecognized data) / 'cap' (would exceed the preset limit).
 */
function csw_presets_import_data($data) {
    if (!is_array($data)) return 'format';
    if (isset($data['presets']) && is_array($data['presets'])) {
        $items = $data['presets'];
    } elseif (isset($data['rules'])) {
        $items = array($data); // a single preset object
    } elseif (array_values($data) === $data) {
        $items = $data; // a bare list of presets
    } else {
        return 'format';
    }
    $existing = csw_presets_raw();
    if (count($existing) + count($items) > CSW_PRESET_MAX) return 'cap';
    $out = array();
    foreach ($items as $it) {
        if (!is_array($it) || !isset($it['rules']) || !is_array($it['rules'])) return 'format';
        $title = isset($it['title']) ? sanitize_text_field($it['title']) : '';
        if ($title === '') $title = 'Imported preset';
        $decoded_rules = array();
        foreach ($it['rules'] as $r) {
            if (!is_array($r)) continue;
            $r['memo'] = isset($r['memo']) ? (string) $r['memo'] : '';
            $decoded_rules[] = $r;
        }
        $out[] = array(
            'id' => uniqid('p', true),
            'title' => csw_txt_encode($title),
            'rules' => csw_sanitize_rules($decoded_rules),
            'exclude_selectors' => isset($it['exclude_selectors']) ? sanitize_textarea_field($it['exclude_selectors']) : '',
            'created' => time(),
        );
    }
    return $out;
}

/* ---------------- Admin UI ---------------- */

add_action('admin_menu', function () {
    add_options_page('ColorSwitch', 'ColorSwitch', 'manage_options', 'color-switch', 'csw_settings_page');
});

function csw_admin_styles() {
    ?>
    <style>
    .csw-del { color: #b32d2e !important; border-color: #b32d2e !important; }
    .csw-del:hover, .csw-del:focus { background: #b32d2e !important; color: #fff !important; border-color: #b32d2e !important; }
    input[type="color"].csw-empty { opacity: .35; filter: grayscale(100%); outline: 1px dashed #888; outline-offset: 1px; }
    .csw-rules-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .csw-rules-scroll table { min-width: 1150px; }
    .csw-move { padding: 0 6px !important; line-height: 1.6 !important; }
    #csw-custom-box { border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; max-width: 560px; background: #fff; }
    #csw-custom-box p { margin: 8px 0; }
    </style>
    <?php
}

/* Bind color picker <-> text input pairs, and mark pickers whose value is empty. */
function csw_admin_color_script() {
    ?>
    <script>
    window.cswBindColors = function (root) {
        (root || document).querySelectorAll('.csw-color').forEach(function (w) {
            if (w.dataset.bound) return;
            w.dataset.bound = '1';
            var p = w.querySelector('input[type="color"]');
            var t = w.querySelector('input[type="text"]');
            if (!p || !t) return;
            function sync() {
                var empty = t.value.trim() === '';
                p.classList.toggle('csw-empty', empty);
                if (!empty && /^#[0-9a-fA-F]{6}$/.test(t.value)) p.value = t.value;
            }
            p.addEventListener('input', function () { t.value = p.value; sync(); });
            t.addEventListener('input', sync);
            sync();
        });
    };
    document.addEventListener('DOMContentLoaded', function () { window.cswBindColors(document); });
    </script>
    <?php
}

function csw_color_field($name, $value, $default) {
    $picker = ($value !== '' && $value[0] === '#') ? $value : '#303030';
    ?>
    <span class="csw-color"><input type="color" value="<?php echo esc_attr($picker); ?>"> <input type="text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" style="width:110px"></span>
    <?php if ($default !== ''): ?><span class="description"><?php echo esc_html(sprintf(__('Default: %s', 'color-switch'), $default)); ?></span><?php endif; ?>
    <?php
}

function csw_rule_color($name, $value) {
    $picker = ($value !== '' && $value[0] === '#') ? $value : '#303030';
    ?>
    <span class="csw-color"><input type="color" value="<?php echo esc_attr($picker); ?>"> <input type="text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" style="width:90px" placeholder="#rrggbb"></span>
    <?php
}

function csw_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = csw_settings();
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'rules';
    if (!in_array($tab, array('rules', 'button', 'preset'), true)) $tab = 'rules';
    $base_url = admin_url('options-general.php?page=color-switch');
    csw_admin_styles();
    ?>
    <div class="wrap">
        <h1>ColorSwitch <?php echo esc_html(__('Settings', 'color-switch')); ?></h1>
        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url($base_url . '&tab=rules'); ?>" class="nav-tab <?php echo $tab === 'rules' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(__('Rules', 'color-switch')); ?></a>
            <a href="<?php echo esc_url($base_url . '&tab=button'); ?>" class="nav-tab <?php echo $tab === 'button' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(__('Button', 'color-switch')); ?></a>
            <a href="<?php echo esc_url($base_url . '&tab=preset'); ?>" class="nav-tab <?php echo $tab === 'preset' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(__('Presets', 'color-switch')); ?></a>
        </nav>

        <?php if ($tab === 'rules'): ?>
        <?php if (isset($_GET['csw_notice']) && sanitize_key($_GET['csw_notice']) === 'applied'): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html(__('Preset applied. The rules and exceptions were replaced.', 'color-switch')); ?></p></div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php settings_fields('csw_settings_group'); ?>
            <input type="hidden" name="<?php echo CSW_OPTION; ?>[rules_present]" value="1">
            <h2><?php echo esc_html(__('Rules', 'color-switch')); ?></h2>
            <p class="description"><?php echo esc_html(__('Each rule takes precedence over the theme color settings. An item with an empty color code is not applied. Multiple selectors can be given as a comma-separated list.', 'color-switch')); ?></p>
            <div class="csw-rules-scroll">
            <table class="widefat striped" id="csw-rules-table">
                <thead>
                    <tr>
                        <th style="width:46px"><?php echo esc_html(__('On', 'color-switch')); ?></th>
                        <th><?php echo esc_html(__('Selector', 'color-switch')); ?></th>
                        <th style="width:180px"><?php echo esc_html(__('Background', 'color-switch')); ?></th>
                        <th style="width:180px"><?php echo esc_html(__('Text color', 'color-switch')); ?></th>
                        <th style="width:180px"><?php echo esc_html(__('Border color', 'color-switch')); ?></th>
                        <th style="width:180px"><?php echo esc_html(__('Memo', 'color-switch')); ?></th>
                        <th style="width:170px"></th>
                    </tr>
                </thead>
                <tbody id="csw-rules-body">
                    <?php foreach (array_values($s['rules']) as $i => $r): ?>
                    <tr class="csw-rule-row">
                        <td><input type="checkbox" name="<?php echo CSW_OPTION; ?>[rules][<?php echo $i; ?>][enabled]" value="1" <?php checked($r['enabled'], 1); ?>></td>
                        <td><input type="text" name="<?php echo CSW_OPTION; ?>[rules][<?php echo $i; ?>][selector]" value="<?php echo esc_attr($r['selector']); ?>" class="large-text" placeholder=".site-header, #header"></td>
                        <td><?php csw_rule_color(CSW_OPTION . '[rules][' . $i . '][bg]', $r['bg']); ?></td>
                        <td><?php csw_rule_color(CSW_OPTION . '[rules][' . $i . '][color]', $r['color']); ?></td>
                        <td><?php csw_rule_color(CSW_OPTION . '[rules][' . $i . '][border]', $r['border']); ?></td>
                        <td><input type="text" name="<?php echo CSW_OPTION; ?>[rules][<?php echo $i; ?>][memo]" value="<?php echo esc_attr(isset($r['memo']) ? $r['memo'] : ''); ?>" class="large-text" placeholder="<?php echo esc_attr(__('Memo', 'color-switch')); ?>"></td>
                        <td>
                            <button type="button" class="button csw-move csw-rule-up" title="<?php echo esc_attr(__('Move up', 'color-switch')); ?>" aria-label="<?php echo esc_attr(__('Move up', 'color-switch')); ?>">↑</button>
                            <button type="button" class="button csw-move csw-rule-down" title="<?php echo esc_attr(__('Move down', 'color-switch')); ?>" aria-label="<?php echo esc_attr(__('Move down', 'color-switch')); ?>">↓</button>
                            <button type="button" class="button csw-rule-del csw-del"><?php echo esc_html(__('Delete', 'color-switch')); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <p><button type="button" class="button" id="csw-rule-add"><?php echo esc_html(__('Add row', 'color-switch')); ?></button></p>

            <h2><?php echo esc_html(__('Exceptions', 'color-switch')); ?></h2>
            <p class="description"><?php echo esc_html(__('Write one per line. You can add a memo after "//" (e.g. code //Code block).', 'color-switch')); ?></p>
            <textarea name="<?php echo CSW_OPTION; ?>[exclude_selectors]" rows="5" class="large-text code" placeholder=".sns-share-buttons"><?php echo esc_textarea($s['exclude_selectors']); ?></textarea>

            <h2><?php echo esc_html(__('Images', 'color-switch')); ?></h2>
            <p>
                <input type="hidden" name="<?php echo CSW_OPTION; ?>[img_dim_present]" value="1">
                <label><input type="checkbox" name="<?php echo CSW_OPTION; ?>[img_dim]" value="1" <?php checked($s['img_dim'], 1); ?>> <?php echo esc_html(__('Dim images slightly', 'color-switch')); ?></label>
            </p>
            <?php submit_button(); ?>
        </form>
        <?php csw_admin_color_script(); ?>
        <script>
        (function () {
            var body = document.getElementById('csw-rules-body');
            function bindDel(btn) {
                btn.addEventListener('click', function () { btn.closest('tr').remove(); });
            }
            function bindMove(row) {
                row.querySelector('.csw-rule-up').addEventListener('click', function () {
                    var prev = row.previousElementSibling;
                    if (prev) body.insertBefore(row, prev);
                });
                row.querySelector('.csw-rule-down').addEventListener('click', function () {
                    var next = row.nextElementSibling;
                    if (next) body.insertBefore(next, row);
                });
            }
            document.querySelectorAll('.csw-rule-del').forEach(bindDel);
            document.querySelectorAll('.csw-rule-row').forEach(bindMove);
            document.getElementById('csw-rule-add').addEventListener('click', function () {
                var i = Date.now();
                var tr = document.createElement('tr');
                tr.className = 'csw-rule-row';
                var name = '<?php echo CSW_OPTION; ?>[rules][' + i + ']';
                function colorCell(field) {
                    return '<td><span class="csw-color"><input type="color" value="#303030"> ' +
                        '<input type="text" name="' + name + '[' + field + ']" value="" style="width:90px" placeholder="#rrggbb"></span></td>';
                }
                tr.innerHTML = '<td><input type="checkbox" name="' + name + '[enabled]" value="1" checked></td>' +
                    '<td><input type="text" name="' + name + '[selector]" value="" class="large-text" placeholder=".site-header, #header"></td>' +
                    colorCell('bg') + colorCell('color') + colorCell('border') +
                    '<td><input type="text" name="' + name + '[memo]" value="" class="large-text" placeholder="<?php echo esc_js(__('Memo', 'color-switch')); ?>"></td>' +
                    '<td><button type="button" class="button csw-move csw-rule-up" title="<?php echo esc_js(__('Move up', 'color-switch')); ?>">↑</button> ' +
                    '<button type="button" class="button csw-move csw-rule-down" title="<?php echo esc_js(__('Move down', 'color-switch')); ?>">↓</button> ' +
                    '<button type="button" class="button csw-rule-del csw-del"><?php echo esc_js(__('Delete', 'color-switch')); ?></button></td>';
                body.appendChild(tr);
                bindDel(tr.querySelector('.csw-rule-del'));
                bindMove(tr);
                window.cswBindColors(tr);
            });
        })();
        </script>

        <?php elseif ($tab === 'button'): ?>
        <form method="post" action="options.php" id="csw-button-form">
            <?php settings_fields('csw_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html(__('Icon', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[icon_preset]" id="csw-icon-preset">
                            <option value="emoji_auto" <?php selected($s['icon_preset'], 'emoji_auto'); ?>>🌙 / ☀️ <?php echo esc_html(__('Auto (emoji)', 'color-switch')); ?></option>
                            <option value="emoji_moon" <?php selected($s['icon_preset'], 'emoji_moon'); ?>>🌙 <?php echo esc_html(__('Fixed (emoji)', 'color-switch')); ?></option>
                            <option value="unicode" <?php selected($s['icon_preset'], 'unicode'); ?>>☾ / ☀ <?php echo esc_html(__('Auto (symbols)', 'color-switch')); ?></option>
                            <option value="custom" <?php selected($s['icon_preset'], 'custom'); ?>><?php echo esc_html(__('Custom', 'color-switch')); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Custom', 'color-switch')); ?></th>
                    <td>
                        <div id="csw-custom-box"<?php echo $s['icon_preset'] === 'custom' ? '' : ' style="display:none"'; ?>>
                            <p>
                                <label><?php echo esc_html(__('Normal state', 'color-switch')); ?><br>
                                <input type="text" name="<?php echo CSW_OPTION; ?>[icon_custom_light]" id="csw-icon-normal" value="<?php echo esc_attr($s['icon_custom_light']); ?>" class="regular-text" style="width:220px"></label>
                            </p>
                            <p>
                                <label><?php echo esc_html(__('Switched state', 'color-switch')); ?><br>
                                <input type="text" name="<?php echo CSW_OPTION; ?>[icon_custom_dark]" id="csw-icon-switched" value="<?php echo esc_attr($s['icon_custom_dark']); ?>" class="regular-text" style="width:220px"></label>
                            </p>
                            <p class="description"><?php echo esc_html(__('For Font Awesome, enter class names such as "fa-solid fa-moon". Font Awesome must be loaded on the site, and Font Awesome must also be specified in the Font family field.', 'color-switch')); ?></p>
                        </div>
                        <p class="description"><?php echo esc_html(__('Used only when the icon type is "Custom".', 'color-switch')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Background color', 'color-switch')); ?></th>
                    <td>
                        <?php csw_color_field(CSW_OPTION . '[button_bg]', $s['button_bg'], csw_defaults()['button_bg']); ?>
                        <p style="margin-top:8px">
                            <label><?php echo esc_html(__('Transparency', 'color-switch')); ?>
                            <input type="number" name="<?php echo CSW_OPTION; ?>[button_bg_transparency]" id="csw-bg-transparency" value="<?php echo esc_attr($s['button_bg_transparency']); ?>" min="0" max="100" step="1" style="width:80px"> %</label>
                            <span class="description"><?php echo esc_html(__('(0 = opaque, 100 = fully transparent)', 'color-switch')); ?></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Text color', 'color-switch')); ?></th>
                    <td>
                        <?php csw_color_field(CSW_OPTION . '[button_color]', $s['button_color'], csw_defaults()['button_color']); ?>
                        <p style="margin-top:8px">
                            <label><?php echo esc_html(__('Transparency', 'color-switch')); ?>
                            <input type="number" name="<?php echo CSW_OPTION; ?>[button_color_transparency]" id="csw-color-transparency" value="<?php echo esc_attr($s['button_color_transparency']); ?>" min="0" max="100" step="1" style="width:80px"> %</label>
                            <span class="description"><?php echo esc_html(__('(0 = opaque, 100 = fully transparent)', 'color-switch')); ?></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Switched state colors', 'color-switch')); ?></th>
                    <td>
                        <input type="hidden" name="<?php echo CSW_OPTION; ?>[button_colors_separate_present]" value="1">
                        <label><input type="checkbox" name="<?php echo CSW_OPTION; ?>[button_colors_separate]" id="csw-colors-separate" value="1" <?php checked($s['button_colors_separate'], 1); ?>> <?php echo esc_html(__('Use separate colors for the switched state', 'color-switch')); ?></label>
                        <div id="csw-switched-colors"<?php echo $s['button_colors_separate'] ? '' : ' style="opacity:.45"'; ?>>
                            <p style="margin-top:10px">
                                <label style="display:inline-block; min-width:64px"><?php echo esc_html(__('Background color', 'color-switch')); ?></label>
                                <?php csw_color_field(CSW_OPTION . '[button_bg_switched]', $s['button_bg_switched'], ''); ?>
                            </p>
                            <p>
                                <label style="display:inline-block; min-width:64px"><?php echo esc_html(__('Transparency', 'color-switch')); ?></label>
                                <input type="number" name="<?php echo CSW_OPTION; ?>[button_bg_transparency_switched]" id="csw-bg-transparency-sw" value="<?php echo esc_attr($s['button_bg_transparency_switched']); ?>" min="0" max="100" step="1" style="width:80px"> %
                            </p>
                            <p>
                                <label style="display:inline-block; min-width:64px"><?php echo esc_html(__('Text color', 'color-switch')); ?></label>
                                <?php csw_color_field(CSW_OPTION . '[button_color_switched]', $s['button_color_switched'], ''); ?>
                            </p>
                            <p>
                                <label style="display:inline-block; min-width:64px"><?php echo esc_html(__('Transparency', 'color-switch')); ?></label>
                                <input type="number" name="<?php echo CSW_OPTION; ?>[button_color_transparency_switched]" id="csw-color-transparency-sw" value="<?php echo esc_attr($s['button_color_transparency_switched']); ?>" min="0" max="100" step="1" style="width:80px"> %
                            </p>
                            <p class="description"><?php echo esc_html(__('Leave a color empty to use the normal-state color.', 'color-switch')); ?></p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Font size', 'color-switch')); ?></th>
                    <td><input type="number" name="<?php echo CSW_OPTION; ?>[button_font_size]" id="csw-font-size" value="<?php echo esc_attr($s['button_font_size']); ?>" min="8" max="64" step="1" style="width:80px"> px</td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Font weight', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[button_font_weight]" id="csw-font-weight">
                            <option value="" <?php selected($s['button_font_weight'], ''); ?>><?php echo esc_html(__('Theme default', 'color-switch')); ?></option>
                            <?php foreach (array('300', '400', '500', '600', '700', '800', '900') as $w): ?>
                            <option value="<?php echo $w; ?>" <?php selected($s['button_font_weight'], $w); ?>><?php echo $w; ?></option>
                            <?php endforeach; ?>
                            <option value="normal" <?php selected($s['button_font_weight'], 'normal'); ?>>normal</option>
                            <option value="bold" <?php selected($s['button_font_weight'], 'bold'); ?>>bold</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Font family', 'color-switch')); ?></th>
                    <td>
                        <input type="text" name="<?php echo CSW_OPTION; ?>[button_font_family]" id="csw-font-family" value="<?php echo esc_attr($s['button_font_family']); ?>" class="regular-text" placeholder='"Font Awesome 6 Free", sans-serif'>
                        <p class="description"><?php echo esc_html(__('Leave empty to use the theme font. A CSS font-family value, e.g. "Font Awesome 6 Free", sans-serif.', 'color-switch')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Button width', 'color-switch')); ?></th>
                    <td><input type="number" name="<?php echo CSW_OPTION; ?>[button_width]" id="csw-btn-width" value="<?php echo esc_attr($s['button_width']); ?>" min="24" max="600" step="1" style="width:80px"> px</td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Button height', 'color-switch')); ?></th>
                    <td><input type="number" name="<?php echo CSW_OPTION; ?>[button_height]" id="csw-btn-height" value="<?php echo esc_attr($s['button_height']); ?>" min="24" max="200" step="1" style="width:80px"> px</td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Button shape', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[button_shape]" id="csw-btn-shape">
                            <option value="circle" <?php selected($s['button_shape'], 'circle'); ?>><?php echo esc_html(__('Circle', 'color-switch')); ?></option>
                            <option value="rounded" <?php selected($s['button_shape'], 'rounded'); ?>><?php echo esc_html(__('Rounded', 'color-switch')); ?></option>
                            <option value="square" <?php selected($s['button_shape'], 'square'); ?>><?php echo esc_html(__('Square', 'color-switch')); ?></option>
                        </select>
                        <span id="csw-radius-wrap"<?php echo $s['button_shape'] === 'rounded' ? '' : ' style="display:none"'; ?>>
                            <label><?php echo esc_html(__('Corner radius', 'color-switch')); ?>
                            <input type="number" name="<?php echo CSW_OPTION; ?>[button_radius]" id="csw-btn-radius" value="<?php echo esc_attr($s['button_radius']); ?>" min="0" max="60" step="1" style="width:80px"> px</label>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Position', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[position]">
                            <option value="bottom_right" <?php selected($s['position'], 'bottom_right'); ?>><?php echo esc_html(__('Bottom right (floating)', 'color-switch')); ?></option>
                            <option value="bottom_left" <?php selected($s['position'], 'bottom_left'); ?>><?php echo esc_html(__('Bottom left (floating)', 'color-switch')); ?></option>
                            <option value="top_right" <?php selected($s['position'], 'top_right'); ?>><?php echo esc_html(__('Top right (floating)', 'color-switch')); ?></option>
                            <option value="top_left" <?php selected($s['position'], 'top_left'); ?>><?php echo esc_html(__('Top left (floating)', 'color-switch')); ?></option>
                            <option value="shortcode" <?php selected($s['position'], 'shortcode'); ?>><?php echo esc_html(__('Do not show (only where [color_switch] is placed)', 'color-switch')); ?></option>
                        </select>
                        <p style="margin-top:8px">
                            <label><?php echo esc_html(__('Horizontal offset', 'color-switch')); ?>
                            <input type="number" name="<?php echo CSW_OPTION; ?>[position_offset_x]" value="<?php echo esc_attr($s['position_offset_x']); ?>" min="0" max="200" step="1" style="width:80px"> px</label>
                            <label style="margin-left:16px"><?php echo esc_html(__('Vertical offset', 'color-switch')); ?>
                            <input type="number" name="<?php echo CSW_OPTION; ?>[position_offset_y]" value="<?php echo esc_attr($s['position_offset_y']); ?>" min="0" max="200" step="1" style="width:80px"> px</label>
                        </p>
                        <p class="description"><?php echo esc_html(__('Distance from the edge of the screen. Applies to the floating positions.', 'color-switch')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Initial display', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[default_mode]">
                            <option value="normal" <?php selected($s['default_mode'], 'normal'); ?>><?php echo esc_html(__('Normal', 'color-switch')); ?></option>
                            <option value="switched" <?php selected($s['default_mode'], 'switched'); ?>><?php echo esc_html(__('Switched', 'color-switch')); ?></option>
                        </select>
                        <input type="hidden" name="<?php echo CSW_OPTION; ?>[os_dark_auto_present]" value="1">
                        <label style="margin-left:16px"><input type="checkbox" name="<?php echo CSW_OPTION; ?>[os_dark_auto]" value="1" <?php checked($s['os_dark_auto'], 1); ?>> <?php echo esc_html(__('Auto-switch when the OS is in dark mode', 'color-switch')); ?></label>
                        <p class="description"><?php echo esc_html(__('Once a visitor switches the scheme, their choice is stored in the browser and takes precedence.', 'color-switch')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Preview', 'color-switch')); ?></th>
                    <td>
                        <div style="display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap">
                            <div>
                                <div style="background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:18px 22px; text-align:center">
                                    <button type="button" class="csw-preview-btn" id="csw-preview-normal"></button>
                                </div>
                                <p class="description" style="text-align:center"><?php echo esc_html(__('Normal state', 'color-switch')); ?></p>
                            </div>
                            <div>
                                <div style="background:#2c3338; border:1px solid #2c3338; border-radius:6px; padding:18px 22px; text-align:center">
                                    <button type="button" class="csw-preview-btn" id="csw-preview-switched"></button>
                                </div>
                                <p class="description" style="text-align:center"><?php echo esc_html(__('Switched state', 'color-switch')); ?></p>
                            </div>
                        </div>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php csw_admin_color_script(); ?>
        <script>
        (function () {
            var form = document.getElementById('csw-button-form');
            var sel = document.getElementById('csw-icon-preset');
            var box = document.getElementById('csw-custom-box');
            function isFaClass(v) { return /(^|\s)fa[srldb]?\s/.test(v) || /(^|\s)fa-[a-z0-9-]+/.test(v); }
            function iconHtml(v) {
                v = (v || '').trim();
                if (isFaClass(v)) return '<i class="' + v.replace(/"/g, '') + '"></i>';
                return document.createTextNode(v).textContent === v ? v.replace(/</g, '&lt;') : v;
            }
            function icons() {
                switch (sel.value) {
                    case 'emoji_moon': return ['🌙', '🌙'];
                    case 'unicode': return ['☾', '☀'];
                    case 'custom': return [document.getElementById('csw-icon-normal').value, document.getElementById('csw-icon-switched').value];
                    default: return ['🌙', '☀️'];
                }
            }
            function fieldText(name) {
                var el = form.querySelector('[name$="[' + name + ']"]');
                return el ? el.value : '';
            }
            function rgba(hex, transparency) {
                if (!/^#[0-9a-fA-F]{6}$/.test(hex)) return hex;
                var r = parseInt(hex.substr(1, 2), 16), g = parseInt(hex.substr(3, 2), 16), b = parseInt(hex.substr(5, 2), 16);
                var a = 1 - Math.max(0, Math.min(100, transparency || 0)) / 100;
                return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
            }
            function update() {
                box.style.display = sel.value === 'custom' ? '' : 'none';
                var sep = document.getElementById('csw-colors-separate');
                var swBox = document.getElementById('csw-switched-colors');
                var separate = !!(sep && sep.checked);
                if (swBox) {
                    swBox.style.opacity = separate ? '' : '.45';
                    swBox.querySelectorAll('input').forEach(function (el) { el.disabled = !separate; });
                }
                var shape = document.getElementById('csw-btn-shape').value;
                document.getElementById('csw-radius-wrap').style.display = shape === 'rounded' ? '' : 'none';
                var radius = shape === 'circle' ? '9999px' : (shape === 'square' ? '0' : (document.getElementById('csw-btn-radius').value || 0) + 'px');
                var ic = icons();
                var bgN = rgba(fieldText('button_bg'), parseInt(document.getElementById('csw-bg-transparency').value, 10));
                var colN = rgba(fieldText('button_color'), parseInt(document.getElementById('csw-color-transparency').value, 10));
                var bgS = bgN, colS = colN;
                if (separate) {
                    var swBg = fieldText('button_bg_switched');
                    if (swBg) bgS = rgba(swBg, parseInt(document.getElementById('csw-bg-transparency-sw').value, 10));
                    var swCol = fieldText('button_color_switched');
                    if (swCol) colS = rgba(swCol, parseInt(document.getElementById('csw-color-transparency-sw').value, 10));
                }
                [['csw-preview-normal', ic[0], bgN, colN], ['csw-preview-switched', ic[1], bgS, colS]].forEach(function (pair) {
                    var b = document.getElementById(pair[0]);
                    b.innerHTML = iconHtml(pair[1]);
                    b.style.background = pair[2];
                    b.style.color = pair[3];
                    b.style.fontSize = (document.getElementById('csw-font-size').value || 22) + 'px';
                    b.style.fontWeight = document.getElementById('csw-font-weight').value || '';
                    b.style.fontFamily = document.getElementById('csw-font-family').value || '';
                    b.style.width = (document.getElementById('csw-btn-width').value || 48) + 'px';
                    b.style.height = (document.getElementById('csw-btn-height').value || 48) + 'px';
                    b.style.whiteSpace = 'nowrap';
                    b.style.borderRadius = radius;
                    b.style.border = '1px solid rgba(0,0,0,.2)';
                    b.style.cursor = 'pointer';
                    b.style.lineHeight = '1';
                    b.style.display = 'inline-flex';
                    b.style.alignItems = 'center';
                    b.style.justifyContent = 'center';
                    b.style.padding = '0 10px';
                });
            }
            form.addEventListener('input', update);
            form.addEventListener('change', update);
            update();
        })();
        </script>

        <?php elseif ($tab === 'preset'): ?>
        <?php
        $presets = csw_presets();
        $notice = isset($_GET['csw_notice']) ? sanitize_key($_GET['csw_notice']) : '';
        $messages = array(
            'saved' => __('Preset saved.', 'color-switch'),
            'applied' => __('Preset applied. The rules and exceptions were replaced.', 'color-switch'),
            'deleted' => __('Preset deleted.', 'color-switch'),
            'cap' => __('The preset limit has been reached. Please delete a preset first.', 'color-switch'),
            'imported' => __('Presets imported.', 'color-switch'),
            'import_cap' => __('Import cancelled: it would exceed the preset limit. Please delete some presets first.', 'color-switch'),
            'import_format' => __('Import failed: the file format was not recognized.', 'color-switch'),
            'notfound' => __('The preset was not found.', 'color-switch'),
            'apply_failed' => __('Applying the preset failed: the saved values could not be verified.', 'color-switch'),
        );
        if ($notice !== '' && isset($messages[$notice])): ?>
        <div class="notice notice-<?php echo in_array($notice, array('saved', 'applied', 'deleted', 'imported'), true) ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo esc_html($messages[$notice]); ?></p></div>
        <?php endif; ?>
        <h2><?php echo esc_html(__('Save the current rules as a preset', 'color-switch')); ?></h2>
        <p class="description"><?php echo esc_html(__('A preset stores the current rules (including their On/Off states) and exceptions. Button settings are not included.', 'color-switch')); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('csw_preset_action'); ?>
            <input type="hidden" name="action" value="csw_preset_action">
            <input type="hidden" name="op" value="save">
            <input type="text" name="preset_title" class="regular-text" required placeholder="<?php echo esc_attr(__('Preset title', 'color-switch')); ?>">
            <?php submit_button(__('Save preset', 'color-switch'), 'secondary', 'submit', false); ?>
            <span class="description"><?php echo esc_html(sprintf(__('You can save up to %d presets. (%d / %d)', 'color-switch'), CSW_PRESET_MAX, count($presets), CSW_PRESET_MAX)); ?></span>
        </form>

        <h2><?php echo esc_html(__('Saved presets', 'color-switch')); ?></h2>
        <?php if (!$presets): ?>
        <p><?php echo esc_html(__('No presets yet.', 'color-switch')); ?></p>
        <?php else: ?>
        <table class="widefat striped" style="max-width:820px">
            <thead><tr>
                <th><?php echo esc_html(__('Title', 'color-switch')); ?></th>
                <th style="width:80px"><?php echo esc_html(__('Rules', 'color-switch')); ?></th>
                <th style="width:150px"><?php echo esc_html(__('Saved on', 'color-switch')); ?></th>
                <th style="width:190px"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($presets as $p): ?>
                <tr>
                    <td><?php echo esc_html($p['title']); ?></td>
                    <td><?php echo esc_html(count((array) $p['rules'])); ?></td>
                    <td><?php echo esc_html(isset($p['created']) ? date_i18n(get_option('date_format'), (int) $p['created']) : ''); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('csw_preset_action'); ?>
                            <input type="hidden" name="action" value="csw_preset_action">
                            <input type="hidden" name="op" value="apply">
                            <input type="hidden" name="preset_id" value="<?php echo esc_attr($p['id']); ?>">
                            <?php submit_button(__('Apply', 'color-switch'), 'secondary small', 'submit', false); ?>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js(__('Delete this preset?', 'color-switch')); ?>')">
                            <?php wp_nonce_field('csw_preset_action'); ?>
                            <input type="hidden" name="action" value="csw_preset_action">
                            <input type="hidden" name="op" value="delete">
                            <input type="hidden" name="preset_id" value="<?php echo esc_attr($p['id']); ?>">
                            <?php submit_button(__('Delete', 'color-switch'), 'secondary small csw-del', 'submit', false); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h2><?php echo esc_html(__('Export / Import', 'color-switch')); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em">
            <?php wp_nonce_field('csw_preset_action'); ?>
            <input type="hidden" name="action" value="csw_preset_action">
            <input type="hidden" name="op" value="export">
            <?php submit_button(__('Export presets (JSON)', 'color-switch'), 'secondary', 'submit', false); ?>
            <span class="description"><?php echo esc_html(__('Downloads all saved presets as one JSON file.', 'color-switch')); ?></span>
        </form>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('csw_preset_action'); ?>
            <input type="hidden" name="action" value="csw_preset_action">
            <input type="hidden" name="op" value="import">
            <input type="file" name="csw_import_file" accept=".json,application/json" required>
            <?php submit_button(__('Import presets', 'color-switch'), 'secondary', 'submit', false); ?>
            <span class="description"><?php echo esc_html(__('Imported presets are added to the list above. Apply one to change the current rules. If the import would exceed the preset limit, nothing is imported.', 'color-switch')); ?></span>
        </form>
        <?php endif; ?>
    </div>
    <?php
}

/* ---------------- Preset actions ---------------- */

add_action('admin_post_csw_preset_action', function () {
    if (!current_user_can('manage_options')) wp_die('forbidden');
    check_admin_referer('csw_preset_action');
    $back = admin_url('options-general.php?page=color-switch&tab=preset');
    $op = isset($_POST['op']) ? sanitize_key($_POST['op']) : '';

    if ($op === 'save') {
        $title = isset($_POST['preset_title']) ? sanitize_text_field(wp_unslash($_POST['preset_title'])) : '';
        if ($title === '') $title = __('Untitled preset', 'color-switch');
        $r = csw_preset_save_current($title);
        wp_safe_redirect($back . '&csw_notice=' . ($r === 'cap' ? 'cap' : 'saved'));
        exit;
    }
    if ($op === 'apply' || $op === 'delete') {
        $id = isset($_POST['preset_id']) ? trim(sanitize_text_field(wp_unslash($_POST['preset_id']))) : '';
        if ($op === 'apply') {
            $r = csw_preset_apply($id);
            if ($r === true) {
                // Land on the Rules tab so the replaced rules (and their On/Off
                // states) are visible right away.
                wp_safe_redirect(admin_url('options-general.php?page=color-switch&tab=rules&csw_notice=applied'));
                exit;
            }
            wp_safe_redirect($back . '&csw_notice=' . ($r === 'notfound' ? 'notfound' : 'apply_failed'));
            exit;
        }
        $ok = csw_preset_delete($id);
        wp_safe_redirect($back . '&csw_notice=' . ($ok ? 'deleted' : 'notfound'));
        exit;
    }
    if ($op === 'export') {
        $json = wp_json_encode(csw_presets_export_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="colorswitch-presets.json"');
        echo $json;
        exit;
    }
    if ($op === 'import') {
        if (empty($_FILES['csw_import_file']['tmp_name']) || !is_uploaded_file($_FILES['csw_import_file']['tmp_name'])) {
            wp_safe_redirect($back . '&csw_notice=import_format');
            exit;
        }
        $raw = file_get_contents($_FILES['csw_import_file']['tmp_name']);
        $data = json_decode((string) $raw, true);
        $res = csw_presets_import_data($data);
        if ($res === 'cap') {
            wp_safe_redirect($back . '&csw_notice=import_cap');
            exit;
        }
        if (!is_array($res)) {
            wp_safe_redirect($back . '&csw_notice=import_format');
            exit;
        }
        csw_presets_save(array_merge(csw_presets_raw(), $res));
        wp_safe_redirect($back . '&csw_notice=imported');
        exit;
    }
    wp_safe_redirect($back);
    exit;
});

/* ---------------- Front end ---------------- */

function csw_icons($s) {
    switch ($s['icon_preset']) {
        case 'emoji_moon': return array('🌙', '🌙');
        case 'unicode':    return array('☾', '☀');
        case 'custom':     return array($s['icon_custom_light'], $s['icon_custom_dark']);
        default:           return array('🌙', '☀️'); // emoji_auto
    }
}

/* A custom icon value that looks like Font Awesome classes
   (e.g. "fa-solid fa-moon", "fas fa-sun", "fa fa-moon"). */
function csw_is_fa_class($v) {
    $v = trim((string) $v);
    return (bool) preg_match('/(^|\s)fa[srldb]?(\s|$)/', $v) || (bool) preg_match('/(^|\s)fa-[a-z0-9-]+/', $v);
}

function csw_icon_html($v) {
    $v = trim((string) $v);
    if (csw_is_fa_class($v)) return '<i class="' . esc_attr($v) . '" aria-hidden="true"></i>';
    return esc_html($v);
}

/* A color as CSS: hex + transparency, or the raw keyword/value. */
function csw_color_css($value, $transparency) {
    $v = (string) $value;
    $t = max(0, min(100, intval($transparency)));
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
        if ($t <= 0) return $v;
        $r = hexdec(substr($v, 1, 2)); $g = hexdec(substr($v, 3, 2)); $b = hexdec(substr($v, 5, 2));
        $a = 1 - $t / 100;
        return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . rtrim(rtrim(sprintf('%.2f', $a), '0'), '.') . ')';
    }
    return $v;
}

/* Button background as CSS: hex + transparency, or the raw keyword. */
function csw_button_bg_css($s) {
    return csw_color_css($s['button_bg'], $s['button_bg_transparency']);
}

/* Effective switched-state colors: the switched values when separate
   colors are enabled and set, otherwise the normal-state values. */
function csw_button_bg_switched_css($s) {
    if (!empty($s['button_colors_separate']) && (string) $s['button_bg_switched'] !== '') {
        return csw_color_css($s['button_bg_switched'], $s['button_bg_transparency_switched']);
    }
    return csw_button_bg_css($s);
}

function csw_button_color_css($s) {
    return csw_color_css($s['button_color'], $s['button_color_transparency']);
}

function csw_button_color_switched_css($s) {
    if (!empty($s['button_colors_separate']) && (string) $s['button_color_switched'] !== '') {
        return csw_color_css($s['button_color_switched'], $s['button_color_transparency_switched']);
    }
    return csw_button_color_css($s);
}

function csw_button_radius_css($s) {
    switch ($s['button_shape']) {
        case 'square':  return '0';
        case 'rounded': return max(0, min(60, intval($s['button_radius']))) . 'px';
        default:        return '9999px'; // circle
    }
}

function csw_position_css($s) {
    $x = max(0, min(200, intval($s['position_offset_x'])));
    $y = max(0, min(200, intval($s['position_offset_y'])));
    switch ($s['position']) {
        case 'bottom_left': return 'left:' . $x . 'px;bottom:' . $y . 'px;';
        case 'top_right':   return 'right:' . $x . 'px;top:' . $y . 'px;';
        case 'top_left':    return 'left:' . $x . 'px;top:' . $y . 'px;';
        default:            return 'right:' . $x . 'px;bottom:' . $y . 'px;';
    }
}

/*
 * Exceptions outrank every rule. A rule selector part is rewritten so it
 * never matches an exception element itself (:not(<ex>)) nor anything
 * inside one (:not(<ex> *)). The suffix is inserted before a trailing
 * pseudo-element, where appending it would be invalid.
 */
function csw_apply_exclusions($part, $excludes) {
    if (!$excludes) return $part;
    $suffix = '';
    foreach ($excludes as $ex) {
        $suffix .= ':not(' . $ex . '):not(' . $ex . ' *)';
    }
    if (preg_match('/^(.*?)(::[a-zA-Z][a-zA-Z-]*(\([^()]*\))?)$/', $part, $m)) {
        return $m[1] . $suffix . $m[2];
    }
    return $part . $suffix;
}

// The FOUC guard script comes first in <head>
add_action('wp_head', function () {
    $s = csw_settings();
    ?>
    <script>
    (function () {
        try {
            var saved = localStorage.getItem('csw-mode');
            var mode = saved;
            if (!mode) {
                if (<?php echo !empty($s['os_dark_auto']) ? 'true' : 'false'; ?> && window.matchMedia('(prefers-color-scheme: dark)').matches) mode = 'dark';
                else mode = <?php echo wp_json_encode($s['default_mode'] === 'switched' ? 'dark' : 'light'); ?>;
            }
            var dark = mode === 'dark';
            if (dark) document.documentElement.classList.add('dark-mode');
        } catch (e) {}
    })();
    </script>
    <?php
}, 1);

// The CSS itself is printed after theme stylesheets (priority 999)
add_action('wp_head', function () {
    $s = csw_settings();
    $excludes = csw_parse_excludes($s['exclude_selectors']);
    ?>
    <style id="color-switch-css">
    :root {
        --csw-btn-bg: <?php echo esc_html(csw_button_bg_css($s)); ?>;
        --csw-btn-color: <?php echo esc_html(csw_button_color_css($s)); ?>;
        --csw-btn-bg-sw: <?php echo esc_html(csw_button_bg_switched_css($s)); ?>;
        --csw-btn-color-sw: <?php echo esc_html(csw_button_color_switched_css($s)); ?>;
    }
    html.dark-mode { color-scheme: dark; }
    <?php // List order is priority order: emit in reverse so the top rule is printed last and wins ties (same specificity, both !important). ?>
    <?php foreach (array_reverse($s['rules']) as $r): ?>
    <?php
        if (empty($r['enabled']) || $r['selector'] === '') continue;
        $decls = array();
        if ($r['bg'] !== '') $decls[] = 'background-color: ' . $r['bg'] . ' !important;';
        if ($r['color'] !== '') $decls[] = 'color: ' . $r['color'] . ' !important;';
        if ($r['border'] !== '') $decls[] = 'border-color: ' . $r['border'] . ' !important;';
        if (!$decls) continue;
        $parts = array();
        foreach (explode(',', $r['selector']) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $part = csw_apply_exclusions($part, $excludes);
            if (strpos($part, 'html.dark-mode') !== 0) $part = 'html.dark-mode ' . $part;
            $parts[] = str_replace('</', '<\/', $part);
        }
        if (!$parts) continue;
    ?>
    <?php echo implode(', ', $parts); ?> { <?php echo implode(' ', $decls); ?> }
    <?php endforeach; ?>
    <?php if (!empty($s['img_dim'])): ?>
    html.dark-mode img { filter: brightness(.92) contrast(1.02); }
    <?php endif; ?>

    .csw-toggle-btn {
        width: <?php echo intval($s['button_width']); ?>px; height: <?php echo intval($s['button_height']); ?>px; padding: 0 10px; border-radius: <?php echo esc_html(csw_button_radius_css($s)); ?>;
        white-space: nowrap;
        border: 1px solid rgba(0,0,0,.2); cursor: pointer;
        background: var(--csw-btn-bg); color: var(--csw-btn-color);
        font-size: <?php echo intval($s['button_font_size']); ?>px; line-height: 1;
        <?php if ($s['button_font_weight'] !== ''): ?>font-weight: <?php echo esc_html($s['button_font_weight']); ?>;<?php endif; ?>
        <?php if ($s['button_font_family'] !== ''): ?>font-family: <?php echo esc_html($s['button_font_family']); ?>;<?php endif; ?>
        display: inline-flex; align-items: center; justify-content: center;
    }
    html.dark-mode .csw-toggle-btn { background: var(--csw-btn-bg-sw); color: var(--csw-btn-color-sw); }
    .csw-toggle-btn .csw-ic-switched { display: none; }
    html.dark-mode .csw-toggle-btn .csw-ic-switched { display: inline; }
    html.dark-mode .csw-toggle-btn .csw-ic-normal { display: none; }
    #csw-toggle {
        position: fixed; z-index: 99999; <?php echo csw_position_css($s); ?>
        box-shadow: 0 4px 16px rgba(0,0,0,.25);
    }
    </style>
    <?php
}, 999);

function csw_button_html($s, $id) {
    list($iconLight, $iconDark) = csw_icons($s);
    $idAttr = $id ? ' id="' . esc_attr($id) . '"' : '';
    return '<button' . $idAttr . ' type="button" class="csw-toggle-btn" aria-label="' . esc_attr(__('Color scheme toggle', 'color-switch')) . '" title="' . esc_attr(__('Color scheme toggle', 'color-switch')) . '">'
        . '<span class="csw-ic-normal">' . csw_icon_html($iconLight) . '</span>'
        . '<span class="csw-ic-switched">' . csw_icon_html($iconDark) . '</span>'
        . '</button>';
}

add_action('wp_footer', function () {
    $s = csw_settings();
    if ($s['position'] !== 'shortcode') {
        echo csw_button_html($s, 'csw-toggle');
    }
    ?>
    <script>
    (function () {
        var btns = document.querySelectorAll('.csw-toggle-btn');
        function sync() {
            var isDark = document.documentElement.classList.contains('dark-mode');
            btns.forEach(function (b) {
                b.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            });
        }
        btns.forEach(function (b) {
            b.addEventListener('click', function () {
                var isDark = document.documentElement.classList.toggle('dark-mode');
                try { localStorage.setItem('csw-mode', isDark ? 'dark' : 'light'); } catch (e) {}
                sync();
            });
        });
        sync();
    })();
    </script>
    <?php
});

add_shortcode('color_switch', function () {
    return csw_button_html(csw_settings(), '');
});
// Alias kept for compatibility with the previous plugin
add_shortcode('dark_mode_toggle', function () {
    return csw_button_html(csw_settings(), '');
});
