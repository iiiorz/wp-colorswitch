<?php
/**
 * Plugin Name: ColorSwitch
 * Description: A theme-independent color scheme (dark mode) toggle plugin. Everything is a rule: selector × colors, with exceptions, memos and user presets.
 * Version: 1.2.0
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
 * - v1.1: base colors were folded into the rule list.
 * - v1.2: no special "core" rules — every rule can be edited, reordered or
 *   deleted. The list order is the priority order (top wins on ties), and
 *   exceptions outrank every rule.
 */

if (!defined('ABSPATH')) exit;

define('CSW_OPTION', 'csw_settings');
define('CSW_PRESETS_OPTION', 'csw_presets');
define('CSW_PRESET_MAX', 10);

add_action('init', function () {
    load_plugin_textdomain('color-switch', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/* ---------------- Text encoding (utf8mb3-safe) ---------------- */

function csw_txt_encode($v) { return 'b64:' . base64_encode((string) $v); }
function csw_txt_decode($v) {
    $guard = 0;
    while (is_string($v) && strpos($v, 'b64:') === 0 && $guard < 3) {
        $d = base64_decode(substr($v, 4), true);
        if ($d === false || $d === '') break;
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
        'icon_preset' => 'emoji_auto', // emoji_auto|emoji_moon|unicode|text|custom
        'icon_custom_light' => '🌙',
        'icon_custom_dark' => '☀️',
        'position' => 'bottom_right', // bottom_right|bottom_left|top_right|top_left|shortcode
        'default_mode' => 'auto',     // auto|light|dark
    );
}

/* ---------------- Settings access ---------------- */

function csw_rules_decode($rules) {
    if (!is_array($rules)) return array();
    foreach ($rules as &$r) {
        if (isset($r['memo'])) $r['memo'] = csw_txt_decode($r['memo']);
        unset($r['core']); // v1.1 core flags are meaningless now; drop them
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

/* Convert a v1.0 settings array (base colors) into the rule structure. */
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
    $s = array_merge(csw_defaults(), $saved);
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
                'memo' => csw_txt_encode(isset($r['memo']) ? sanitize_text_field($r['memo']) : ''),
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

    if (isset($input['rules_present'])) {
        $out['rules'] = csw_sanitize_rules(isset($input['rules']) ? $input['rules'] : array());
    }
    if (isset($input['exclude_selectors'])) {
        $out['exclude_selectors'] = sanitize_textarea_field($input['exclude_selectors']);
    }
    if (isset($input['img_dim_present'])) {
        $out['img_dim'] = !empty($input['img_dim']) ? 1 : 0;
    }
    foreach (array('button_bg', 'button_color') as $k) {
        if (isset($input[$k])) {
            $c = sanitize_hex_color($input[$k]);
            if ($c) $out[$k] = $c;
        }
    }
    if (isset($input['icon_preset']) && in_array($input['icon_preset'], array('emoji_auto', 'emoji_moon', 'unicode', 'text', 'custom'), true)) {
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
    if (isset($input['default_mode']) && in_array($input['default_mode'], array('auto', 'light', 'dark'), true)) {
        $out['default_mode'] = $input['default_mode'];
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

/* Apply a preset to the current settings (rules + exceptions only). */
function csw_preset_apply($id) {
    $list = csw_presets_raw();
    $i = csw_preset_find($list, $id);
    if ($i < 0) return false;
    $s = csw_settings();
    $s['rules'] = csw_rules_decode($list[$i]['rules']);
    $s['exclude_selectors'] = isset($list[$i]['exclude_selectors']) ? $list[$i]['exclude_selectors'] : '';
    csw_store_settings($s);
    return true;
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
    <span class="description"><?php echo esc_html(sprintf(__('Default: %s', 'color-switch'), $default)); ?></span>
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
        <form method="post" action="options.php">
            <?php settings_fields('csw_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html(__('Icon', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[icon_preset]" id="csw-icon-preset">
                            <option value="emoji_auto" <?php selected($s['icon_preset'], 'emoji_auto'); ?>>🌙 / ☀️ <?php echo esc_html(__('Auto (emoji)', 'color-switch')); ?></option>
                            <option value="emoji_moon" <?php selected($s['icon_preset'], 'emoji_moon'); ?>>🌙 <?php echo esc_html(__('Fixed (emoji)', 'color-switch')); ?></option>
                            <option value="unicode" <?php selected($s['icon_preset'], 'unicode'); ?>>☾ / ☀ <?php echo esc_html(__('Auto (symbols)', 'color-switch')); ?></option>
                            <option value="text" <?php selected($s['icon_preset'], 'text'); ?>><?php echo esc_html(__('Text', 'color-switch')); ?></option>
                            <option value="custom" <?php selected($s['icon_preset'], 'custom'); ?>><?php echo esc_html(__('Custom', 'color-switch')); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Custom icon', 'color-switch')); ?></th>
                    <td>
                        <div id="csw-custom-box"<?php echo $s['icon_preset'] === 'custom' ? '' : ' style="display:none"'; ?>>
                            <p>
                                <label><?php echo esc_html(__('Shown in light mode', 'color-switch')); ?><br>
                                <input type="text" name="<?php echo CSW_OPTION; ?>[icon_custom_light]" value="<?php echo esc_attr($s['icon_custom_light']); ?>" class="regular-text" style="width:160px"></label><br>
                                <span class="description"><?php echo esc_html(__('Appears on the button that switches to dark mode.', 'color-switch')); ?></span>
                            </p>
                            <p>
                                <label><?php echo esc_html(__('Shown in dark mode', 'color-switch')); ?><br>
                                <input type="text" name="<?php echo CSW_OPTION; ?>[icon_custom_dark]" value="<?php echo esc_attr($s['icon_custom_dark']); ?>" class="regular-text" style="width:160px"></label><br>
                                <span class="description"><?php echo esc_html(__('Appears on the button that switches to light mode.', 'color-switch')); ?></span>
                            </p>
                        </div>
                        <p class="description"><?php echo esc_html(__('Used only when the icon type is "Custom".', 'color-switch')); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Button background', 'color-switch')); ?></th>
                    <td><?php csw_color_field(CSW_OPTION . '[button_bg]', $s['button_bg'], csw_defaults()['button_bg']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Button icon color', 'color-switch')); ?></th>
                    <td><?php csw_color_field(CSW_OPTION . '[button_color]', $s['button_color'], csw_defaults()['button_color']); ?></td>
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
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('Initial mode', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[default_mode]">
                            <option value="auto" <?php selected($s['default_mode'], 'auto'); ?>><?php echo esc_html(__('Auto (follow the OS setting)', 'color-switch')); ?></option>
                            <option value="light" <?php selected($s['default_mode'], 'light'); ?>><?php echo esc_html(__('Light', 'color-switch')); ?></option>
                            <option value="dark" <?php selected($s['default_mode'], 'dark'); ?>><?php echo esc_html(__('Dark', 'color-switch')); ?></option>
                        </select>
                        <p class="description"><?php echo esc_html(__('Once a visitor switches the scheme, their choice is stored in the browser and takes precedence.', 'color-switch')); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php csw_admin_color_script(); ?>
        <script>
        (function () {
            var sel = document.getElementById('csw-icon-preset');
            var box = document.getElementById('csw-custom-box');
            sel.addEventListener('change', function () {
                box.style.display = sel.value === 'custom' ? '' : 'none';
            });
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
        );
        if ($notice !== '' && isset($messages[$notice])): ?>
        <div class="notice notice-<?php echo in_array($notice, array('saved', 'applied', 'deleted', 'imported'), true) ? 'success' : 'warning'; ?> is-dismissible"><p><?php echo esc_html($messages[$notice]); ?></p></div>
        <?php endif; ?>
        <h2><?php echo esc_html(__('Save the current rules as a preset', 'color-switch')); ?></h2>
        <p class="description"><?php echo esc_html(__('A preset stores the current rules and exceptions. Button settings are not included.', 'color-switch')); ?></p>
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
        $id = isset($_POST['preset_id']) ? sanitize_text_field(wp_unslash($_POST['preset_id'])) : '';
        $ok = ($op === 'apply') ? csw_preset_apply($id) : csw_preset_delete($id);
        wp_safe_redirect($back . '&csw_notice=' . ($ok ? ($op === 'apply' ? 'applied' : 'deleted') : 'notfound'));
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
        case 'text':       return array(__('Dark', 'color-switch'), __('Light', 'color-switch'));
        case 'custom':     return array($s['icon_custom_light'], $s['icon_custom_dark']);
        default:           return array('🌙', '☀️'); // emoji_auto
    }
}

function csw_position_css($s) {
    switch ($s['position']) {
        case 'bottom_left': return 'left:16px;bottom:16px;';
        case 'top_right':   return 'right:16px;top:16px;';
        case 'top_left':    return 'left:16px;top:16px;';
        default:            return 'right:16px;bottom:16px;';
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
            var mode = saved || <?php echo wp_json_encode($s['default_mode']); ?>;
            var dark = mode === 'dark' || (mode === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
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
        --csw-btn-bg: <?php echo esc_html($s['button_bg']); ?>;
        --csw-btn-color: <?php echo esc_html($s['button_color']); ?>;
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
        min-width: 48px; height: 48px; padding: 0 10px; border-radius: 9999px;
        border: 1px solid rgba(0,0,0,.2); cursor: pointer;
        background: var(--csw-btn-bg); color: var(--csw-btn-color);
        font-size: 22px; line-height: 1;
        display: inline-flex; align-items: center; justify-content: center;
    }
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
    return '<button' . $idAttr . ' type="button" class="csw-toggle-btn" aria-label="' . esc_attr(__('Color scheme toggle', 'color-switch')) . '" title="' . esc_attr(__('Color scheme toggle', 'color-switch')) . '" data-icon-light="' . esc_attr($iconLight) . '" data-icon-dark="' . esc_attr($iconDark) . '">' . esc_html($iconLight) . '</button>';
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
                b.textContent = isDark ? b.dataset.iconDark : b.dataset.iconLight;
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
