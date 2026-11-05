<?php
/**
 * Plugin Name: ColorSwitch
 * Description: テーマに依存しないカラースキーム（ダークモード）切替プラグイン。基本カラー＋カスタムルール（セレクタ×色）で、どのテーマにもダーク配色を適用できます。
 * Version: 1.0.0
 * Author: iiiiorz
 * License: GPL-2.0-or-later
 * Text Domain: color-switch
 *
 * 設計メモ:
 * - 切替は <html> への 'dark-mode' クラス付け外し。FOUC 防止スクリプトを wp_head 優先度1、
 *   CSS 本体を優先度999 で出力する (テーマ CSS より後に勝つための実績方式)。
 * - 設定は単一オプション csw_settings。タブごとに部分フォームを送るため、
 *   サニタイズは「現在の保存値に、送信されたキーだけを上書き」する方式。
 * - カスタムアイコンは base64 化して保存する。絵文字 (4バイト文字) を含む値は
 *   utf8mb3 の wp_options では保存自体が黙って失敗するため、その DB でも壊れないように。
 */

if (!defined('ABSPATH')) exit;

define('CSW_OPTION', 'csw_settings');

/* ---------------- 既定値 ---------------- */

function csw_defaults() {
    return array(
        // 基本カラー (汎用プリセット)
        'dark_bg'         => '#242424',
        'dark_surface'    => '#303030',
        'dark_text'       => '#ffffff',
        'dark_link'       => '#6cb2f5',
        'dark_link_hover' => '#a8cfff',
        'dark_accent'     => '#f5c542',
        'img_dim'         => 1, // 画像を少し暗くする
        // カスタムルール: array of {selector, bg, color, border, enabled}
        'rules'           => array(),
        // 色を変えないセレクタ (1行に1つ)。リンク色と pre/code の対象から外れる
        'exclude_selectors' => '',
        // ボタン
        'button_bg'       => '#393939',
        'button_color'    => '#ffffff',
        'icon_preset'     => 'emoji_auto', // emoji_auto|emoji_moon|unicode|text|custom
        'icon_custom_light' => '🌙',
        'icon_custom_dark'  => '☀️',
        'position'        => 'bottom_right', // bottom_right|bottom_left|top_right|top_left|shortcode
        'default_mode'    => 'auto',         // auto|light|dark
    );
}

function csw_base_color_keys() {
    return array('dark_bg', 'dark_surface', 'dark_text', 'dark_link', 'dark_link_hover', 'dark_accent', 'button_bg', 'button_color');
}

/* 絵文字など4バイト文字を DB 文字コードに依存せず保存するための符号化 */
function csw_icon_encode($v) { return 'b64:' . base64_encode($v); }
function csw_icon_decode($v) {
    $guard = 0;
    while (is_string($v) && strpos($v, 'b64:') === 0 && $guard < 3) {
        $d = base64_decode(substr($v, 4), true);
        if ($d === false || $d === '') break;
        $v = $d;
        $guard++;
    }
    return $v;
}

function csw_settings() {
    $saved = get_option(CSW_OPTION, array());
    if (!is_array($saved)) $saved = array();
    $s = array_merge(csw_defaults(), $saved);
    $s['icon_custom_light'] = csw_icon_decode($s['icon_custom_light']);
    $s['icon_custom_dark']  = csw_icon_decode($s['icon_custom_dark']);
    if (!is_array($s['rules'])) $s['rules'] = array();
    return $s;
}

/* ---------------- サニタイズ ---------------- */

/* CSS の色として許可する値: #hex / transparent / var(--name) のみ */
function csw_css_color($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    $hex = sanitize_hex_color($v);
    if ($hex) return $hex;
    if (strtolower($v) === 'transparent') return 'transparent';
    if (preg_match('/^var\(--[a-zA-Z0-9\-_]+\)$/', $v)) return $v;
    return '';
}

/* セレクタとして許可する文字列: style 脱出や宣言混入に使える文字を除去 */
function csw_sanitize_selector($sel) {
    $sel = wp_strip_all_tags((string) $sel);
    $sel = str_replace(array('{', '}', '<', '>', ';', '@'), '', $sel);
    $sel = trim(preg_replace('/\s+/', ' ', $sel));
    return $sel;
}

function csw_parse_excludes($text) {
    $parts = preg_split('/[\r\n,]+/', (string) $text);
    $out = array();
    foreach ($parts as $p) {
        $p = csw_sanitize_selector($p);
        if ($p !== '') $out[] = $p;
    }
    return array_values(array_unique($out));
}

add_action('admin_init', function () {
    register_setting('csw_settings_group', CSW_OPTION, array('sanitize_callback' => 'csw_sanitize'));
});

function csw_sanitize($input) {
    if (!is_array($input)) $input = array();
    $saved = get_option(CSW_OPTION, array());
    if (!is_array($saved)) $saved = array();
    $out = array_merge(csw_defaults(), $saved); // 現在値ベース (タブ単位の部分保存に対応)

    foreach (csw_base_color_keys() as $k) {
        if (isset($input[$k])) {
            $c = sanitize_hex_color($input[$k]);
            if ($c) $out[$k] = $c;
        }
    }
    if (isset($input['img_dim_present'])) {
        $out['img_dim'] = !empty($input['img_dim']) ? 1 : 0;
    }
    if (isset($input['rules_present'])) {
        $rows = array();
        if (isset($input['rules']) && is_array($input['rules'])) {
            foreach ($input['rules'] as $r) {
                if (!is_array($r)) continue;
                $sel = isset($r['selector']) ? csw_sanitize_selector($r['selector']) : '';
                $row = array(
                    'selector' => $sel,
                    'bg'       => isset($r['bg']) ? csw_css_color($r['bg']) : '',
                    'color'    => isset($r['color']) ? csw_css_color($r['color']) : '',
                    'border'   => isset($r['border']) ? csw_css_color($r['border']) : '',
                    'enabled'  => !empty($r['enabled']) ? 1 : 0,
                );
                if ($sel === '' && $row['bg'] === '' && $row['color'] === '' && $row['border'] === '') continue;
                $rows[] = $row;
            }
        }
        $out['rules'] = $rows;
    }
    if (isset($input['exclude_selectors'])) {
        $out['exclude_selectors'] = implode("\n", csw_parse_excludes($input['exclude_selectors']));
    }
    if (isset($input['icon_preset']) && in_array($input['icon_preset'], array('emoji_auto', 'emoji_moon', 'unicode', 'text', 'custom'), true)) {
        $out['icon_preset'] = $input['icon_preset'];
    }
    foreach (array('icon_custom_light', 'icon_custom_dark') as $k) {
        if (isset($input[$k])) $out[$k] = csw_icon_encode(csw_icon_decode(sanitize_text_field($input[$k])));
    }
    foreach (array('icon_custom_light', 'icon_custom_dark') as $k) {
        if (strpos((string) $out[$k], 'b64:') !== 0) $out[$k] = csw_icon_encode((string) $out[$k]);
    }
    if (isset($input['position']) && in_array($input['position'], array('bottom_right', 'bottom_left', 'top_right', 'top_left', 'shortcode'), true)) {
        $out['position'] = $input['position'];
    }
    if (isset($input['default_mode']) && in_array($input['default_mode'], array('auto', 'light', 'dark'), true)) {
        $out['default_mode'] = $input['default_mode'];
    }
    return $out;
}

/* ---------------- 設定画面 (タブ分け) ---------------- */

add_action('admin_menu', function () {
    add_options_page('ColorSwitch', 'ColorSwitch', 'manage_options', 'color-switch', 'csw_settings_page');
});

function csw_color_field($name, $value, $default) {
    $picker = $value !== '' ? $value : '#303030';
    ?>
    <input type="color" value="<?php echo esc_attr($picker); ?>" onchange="this.nextElementSibling.value=this.value">
    <input type="text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" style="width:110px" oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value))this.previousElementSibling.value=this.value">
    <span class="description"><?php echo esc_html(sprintf(__('初期値: %s', 'color-switch'), $default)); ?></span>
    <?php
}

function csw_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = csw_settings();
    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'base';
    if (!in_array($tab, array('base', 'rules', 'button', 'preset'), true)) $tab = 'base';
    $base_url = admin_url('options-general.php?page=color-switch');
    ?>
    <div class="wrap">
        <h1>ColorSwitch <?php echo esc_html(__('設定', 'color-switch')); ?></h1>
        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url($base_url . '&tab=base'); ?>" class="nav-tab <?php echo $tab === 'base' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(__('基本カラー', 'color-switch')); ?></a>
            <a href="<?php echo esc_url($base_url . '&tab=rules'); ?>" class="nav-tab <?php echo $tab === 'rules' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(__('カスタムルール', 'color-switch')); ?></a>
            <a href="<?php echo esc_url($base_url . '&tab=button'); ?>" class="nav-tab <?php echo $tab === 'button' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(__('ボタン', 'color-switch')); ?></a>
            <a href="<?php echo esc_url($base_url . '&tab=preset'); ?>" class="nav-tab <?php echo $tab === 'preset' ? 'nav-tab-active' : ''; ?>"><?php echo esc_html(__('プリセット', 'color-switch')); ?></a>
        </nav>

        <?php if ($tab === 'base'): ?>
        <form method="post" action="options.php">
            <?php settings_fields('csw_settings_group'); ?>
            <table class="form-table">
                <?php
                $labels = array(
                    'dark_bg' => __('ダーク時: 背景色', 'color-switch'),
                    'dark_surface' => __('ダーク時: 面の色 (ヘッダー/フッター/カード等)', 'color-switch'),
                    'dark_text' => __('ダーク時: 文字色', 'color-switch'),
                    'dark_link' => __('ダーク時: リンク色', 'color-switch'),
                    'dark_link_hover' => __('ダーク時: リンクホバー色', 'color-switch'),
                    'dark_accent' => __('ダーク時: アクセント色 (選択色など)', 'color-switch'),
                );
                foreach ($labels as $k => $label): ?>
                <tr>
                    <th scope="row"><?php echo esc_html($label); ?></th>
                    <td><?php csw_color_field(CSW_OPTION . '[' . $k . ']', $s[$k], csw_defaults()[$k]); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                    <th scope="row"><?php echo esc_html(__('画像', 'color-switch')); ?></th>
                    <td>
                        <input type="hidden" name="<?php echo CSW_OPTION; ?>[img_dim_present]" value="1">
                        <label><input type="checkbox" name="<?php echo CSW_OPTION; ?>[img_dim]" value="1" <?php checked($s['img_dim'], 1); ?>> <?php echo esc_html(__('ダーク時に画像を少し暗くする', 'color-switch')); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <?php elseif ($tab === 'rules'): ?>
        <form method="post" action="options.php">
            <?php settings_fields('csw_settings_group'); ?>
            <input type="hidden" name="<?php echo CSW_OPTION; ?>[rules_present]" value="1">
            <h2><?php echo esc_html(__('カスタムルール', 'color-switch')); ?></h2>
            <p class="description"><?php echo esc_html(__('セレクタごとに背景色・文字色・枠線色を指定できます。色欄が空の項目は適用されません。セレクタはカンマ区切りで複数指定できます。ダーク時のみ、テーマのスタイルより優先して適用されます。', 'color-switch')); ?></p>
            <table class="widefat striped" id="csw-rules-table">
                <thead>
                    <tr>
                        <th style="width:50px"><?php echo esc_html(__('有効', 'color-switch')); ?></th>
                        <th><?php echo esc_html(__('セレクタ', 'color-switch')); ?></th>
                        <th style="width:190px"><?php echo esc_html(__('背景色', 'color-switch')); ?></th>
                        <th style="width:190px"><?php echo esc_html(__('文字色', 'color-switch')); ?></th>
                        <th style="width:190px"><?php echo esc_html(__('枠線色', 'color-switch')); ?></th>
                        <th style="width:70px"></th>
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
                        <td><button type="button" class="button csw-rule-del"><?php echo esc_html(__('削除', 'color-switch')); ?></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button" id="csw-rule-add"><?php echo esc_html(__('行を追加', 'color-switch')); ?></button></p>

            <h2><?php echo esc_html(__('色を変えないセレクタ', 'color-switch')); ?></h2>
            <p class="description"><?php echo esc_html(__('1行に1つ。ここに書いた要素配下のリンクと pre / code は、基本カラーの対象から外れます（SNS ボタンや独自テーマを持つコードブロックなどに）。', 'color-switch')); ?></p>
            <textarea name="<?php echo CSW_OPTION; ?>[exclude_selectors]" rows="5" class="large-text code" placeholder=".sns-share-buttons"><?php echo esc_textarea($s['exclude_selectors']); ?></textarea>
            <?php submit_button(); ?>
        </form>
        <script>
        (function () {
            var body = document.getElementById('csw-rules-body');
            function bindDel(btn) {
                btn.addEventListener('click', function () { btn.closest('tr').remove(); });
            }
            document.querySelectorAll('.csw-rule-del').forEach(bindDel);
            document.getElementById('csw-rule-add').addEventListener('click', function () {
                var i = Date.now();
                var tr = document.createElement('tr');
                tr.className = 'csw-rule-row';
                var name = '<?php echo CSW_OPTION; ?>[rules][' + i + ']';
                function colorCell(field) {
                    return '<td><input type="color" value="#303030" onchange="this.nextElementSibling.value=this.value"> ' +
                        '<input type="text" name="' + name + '[' + field + ']" value="" style="width:90px"></td>';
                }
                tr.innerHTML = '<td><input type="checkbox" name="' + name + '[enabled]" value="1" checked></td>' +
                    '<td><input type="text" name="' + name + '[selector]" value="" class="large-text" placeholder=".site-header, #header"></td>' +
                    colorCell('bg') + colorCell('color') + colorCell('border') +
                    '<td><button type="button" class="button csw-rule-del"><?php echo esc_js(__('削除', 'color-switch')); ?></button></td>';
                body.appendChild(tr);
                bindDel(tr.querySelector('.csw-rule-del'));
            });
        })();
        </script>

        <?php elseif ($tab === 'button'): ?>
        <form method="post" action="options.php">
            <?php settings_fields('csw_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html(__('アイコン', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[icon_preset]">
                            <option value="emoji_auto" <?php selected($s['icon_preset'], 'emoji_auto'); ?>>🌙 / ☀️ 自動切替 (絵文字)</option>
                            <option value="emoji_moon" <?php selected($s['icon_preset'], 'emoji_moon'); ?>>🌙 固定 (絵文字)</option>
                            <option value="unicode" <?php selected($s['icon_preset'], 'unicode'); ?>>☾ / ☀ 自動切替 (記号)</option>
                            <option value="text" <?php selected($s['icon_preset'], 'text'); ?>>「ダーク」「ライト」テキスト</option>
                            <option value="custom" <?php selected($s['icon_preset'], 'custom'); ?>>カスタム (下の欄)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('カスタム: ライト時に表示', 'color-switch')); ?></th>
                    <td><input type="text" name="<?php echo CSW_OPTION; ?>[icon_custom_light]" value="<?php echo esc_attr($s['icon_custom_light']); ?>" class="regular-text" style="width:160px"> <span class="description"><?php echo esc_html(__('押すとダークモードになるボタンに表示', 'color-switch')); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('カスタム: ダーク時に表示', 'color-switch')); ?></th>
                    <td><input type="text" name="<?php echo CSW_OPTION; ?>[icon_custom_dark]" value="<?php echo esc_attr($s['icon_custom_dark']); ?>" class="regular-text" style="width:160px"> <span class="description"><?php echo esc_html(__('押すとライトモードになるボタンに表示', 'color-switch')); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('ボタンの背景色', 'color-switch')); ?></th>
                    <td><?php csw_color_field(CSW_OPTION . '[button_bg]', $s['button_bg'], csw_defaults()['button_bg']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('ボタンのアイコン色', 'color-switch')); ?></th>
                    <td><?php csw_color_field(CSW_OPTION . '[button_color]', $s['button_color'], csw_defaults()['button_color']); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('表示位置', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[position]">
                            <option value="bottom_right" <?php selected($s['position'], 'bottom_right'); ?>><?php echo esc_html(__('右下 (フローティング)', 'color-switch')); ?></option>
                            <option value="bottom_left" <?php selected($s['position'], 'bottom_left'); ?>><?php echo esc_html(__('左下 (フローティング)', 'color-switch')); ?></option>
                            <option value="top_right" <?php selected($s['position'], 'top_right'); ?>><?php echo esc_html(__('右上 (フローティング)', 'color-switch')); ?></option>
                            <option value="top_left" <?php selected($s['position'], 'top_left'); ?>><?php echo esc_html(__('左上 (フローティング)', 'color-switch')); ?></option>
                            <option value="shortcode" <?php selected($s['position'], 'shortcode'); ?>><?php echo esc_html(__('表示しない ([color_switch] 配置時のみ)', 'color-switch')); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html(__('初期モード', 'color-switch')); ?></th>
                    <td>
                        <select name="<?php echo CSW_OPTION; ?>[default_mode]">
                            <option value="auto" <?php selected($s['default_mode'], 'auto'); ?>><?php echo esc_html(__('自動 (OS の設定に従う)', 'color-switch')); ?></option>
                            <option value="light" <?php selected($s['default_mode'], 'light'); ?>>ライト</option>
                            <option value="dark" <?php selected($s['default_mode'], 'dark'); ?>>ダーク</option>
                        </select>
                        <p class="description"><?php echo esc_html(__('訪問者が一度切り替えた後は、その選択がブラウザに保存されて優先されます。', 'color-switch')); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <?php elseif ($tab === 'preset'): ?>
        <h2><?php echo esc_html(__('プリセット', 'color-switch')); ?></h2>
        <p><?php echo esc_html(__('プリセットを読み込むと、基本カラー・カスタムルール・除外リストが置き換わります（ボタンの設定は保持されます）。', 'color-switch')); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1em">
            <?php wp_nonce_field('csw_preset'); ?>
            <input type="hidden" name="action" value="csw_preset">
            <input type="hidden" name="preset" value="generic">
            <?php submit_button(__('汎用プリセットに戻す', 'color-switch'), 'secondary', 'submit', false); ?>
            <span class="description"><?php echo esc_html(__('基本カラーを初期値に戻し、カスタムルールと除外リストを空にします。', 'color-switch')); ?></span>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('csw_preset'); ?>
            <input type="hidden" name="action" value="csw_preset">
            <input type="hidden" name="preset" value="cocoon">
            <?php submit_button(__('Cocoon プリセットを読み込む', 'color-switch'), 'secondary', 'submit', false); ?>
            <span class="description"><?php echo esc_html(__('テーマ Cocoon 向けの基本カラーとルール一式を設定します。', 'color-switch')); ?></span>
        </form>
        <p class="description"><?php echo esc_html(__('従来プラグイン (chris-dark-mode-toggle) の設定引き継ぎは、今後のフェーズで対応予定です。', 'color-switch')); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function csw_rule_color($name, $value) {
    $picker = ($value !== '' && $value[0] === '#') ? $value : '#303030';
    ?>
    <input type="color" value="<?php echo esc_attr($picker); ?>" onchange="this.nextElementSibling.value=this.value">
    <input type="text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" style="width:90px" placeholder="#rrggbb">
    <?php
}

/* ---------------- プリセット ---------------- */

function csw_preset_generic() {
    $d = csw_defaults();
    return $d;
}

function csw_preset_cocoon() {
    $p = csw_defaults();
    // 基本カラー (Cocoon 検証で使っていたパレット)
    $p['dark_bg'] = '#242424';
    $p['dark_surface'] = '#303030';
    $p['dark_text'] = '#ffffff';
    $p['dark_link'] = '#22c7e8';
    $p['dark_link_hover'] = '#edb90e';
    $p['dark_accent'] = '#edb90e';
    $p['button_bg'] = '#393939';
    $p['button_color'] = '#ffffff';
    // 除外: SNS ボタン、商品リンク、Code Block Pro は元の色のまま
    $p['exclude_selectors'] = implode("\n", array(
        '.sns-share-buttons',
        '.sns-follow-buttons',
        '.sns-buttons',
        '[class*="shoplink"]',
        '.wp-block-kevinbatdorf-code-block-pro',
    ));
    $box24 = '.information-box, .information, .question-box, .question, .alert-box, .alert, '
        . '.memo-box, .comment-box, .ok-box, .ng-box, .profile-box, .good-box, .bad-box, '
        . '.is-style-information-box, .is-style-question-box, .is-style-alert-box, .is-style-memo-box, '
        . '.is-style-comment-box, .is-style-ok-box, .is-style-ng-box, .is-style-profile-box, '
        . '.is-style-good-box, .is-style-bad-box';
    $p['rules'] = array(
        array('selector' => '#header, .header', 'bg' => '#1b1b1b', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.header-in', 'bg' => 'transparent', 'color' => '', 'border' => '', 'enabled' => 1),
        array('selector' => '.header a, .site-name-text', 'bg' => '', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.navi, .navi-in, .menu-drawer', 'bg' => '#242424', 'color' => '', 'border' => '', 'enabled' => 1),
        array('selector' => '.navi a, .menu-drawer a', 'bg' => '', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.cat-label, .cat-label-2', 'bg' => '#22c7e8', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.sidebar, #sidebar', 'bg' => '#303030', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.footer-container, .footer, .footer-bottom', 'bg' => '#303030', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.main', 'bg' => '#242424', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.entry-card, .blogcard-wrap, .blogcard, .author-box, .widget, .popular-entry-card, .new-entry-card, .toc, .speech-balloon, .info-list, .a-wrap', 'bg' => '#303030', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '[class*="wp-block-cocoon-blocks-"]:not([class*="button"]):not([class*="btn"])', 'bg' => '#303030', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '[class*="wp-block-cocoon-blocks-"] .box-title, [class*="wp-block-cocoon-blocks-"] .icon-box-title, [class*="wp-block-cocoon-blocks-"] .label-box-label, [class*="wp-block-cocoon-blocks-"] .tab-label, [class*="wp-block-cocoon-blocks-"] p, [class*="wp-block-cocoon-blocks-"] li', 'bg' => '', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => $box24, 'bg' => '#303030', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.page-numbers, .post-page-numbers', 'bg' => '', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.page-numbers.dots', 'bg' => '#303030', 'color' => '', 'border' => '', 'enabled' => 1),
        array('selector' => '.pagination-next-link, .comment-btn', 'bg' => '#303030', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => '.footer-container a, .footer a, .navi-footer a, .navi-footer-in a, .footer-navi a, .menu-footer a, .footer-bottom a, .copyright a', 'bg' => '', 'color' => '#ffffff', 'border' => '', 'enabled' => 1),
        array('selector' => 'table tr, table tr:nth-child(odd), table tr:nth-child(even)', 'bg' => 'transparent', 'color' => '', 'border' => '', 'enabled' => 1),
        array('selector' => '.tab-label-group .tab-label:not(.is-active)', 'bg' => '', 'color' => 'var(--cocoon-text-color)', 'border' => '', 'enabled' => 1),
        array('selector' => '.wp-block-code, .wp-block-code code', 'bg' => '#303030', 'color' => '', 'border' => '', 'enabled' => 1),
    );
    return $p;
}

add_action('admin_post_csw_preset', function () {
    if (!current_user_can('manage_options')) wp_die('forbidden');
    check_admin_referer('csw_preset');
    $preset = isset($_POST['preset']) ? sanitize_key($_POST['preset']) : '';
    $current = csw_settings();
    if ($preset === 'generic') {
        $new = csw_preset_generic();
    } elseif ($preset === 'cocoon') {
        $new = csw_preset_cocoon();
    } else {
        wp_safe_redirect(admin_url('options-general.php?page=color-switch&tab=preset'));
        exit;
    }
    // ボタンの設定はプリセット対象外 (保持する)
    foreach (array('button_bg', 'button_color', 'icon_preset', 'icon_custom_light', 'icon_custom_dark', 'position', 'default_mode') as $k) {
        $new[$k] = $current[$k];
    }
    // アイコンは保存形式 (base64) にそろえる
    foreach (array('icon_custom_light', 'icon_custom_dark') as $k) {
        $new[$k] = csw_icon_encode(csw_icon_decode((string) $new[$k]));
    }
    update_option(CSW_OPTION, $new);
    wp_safe_redirect(admin_url('options-general.php?page=color-switch&tab=preset&settings-updated=true'));
    exit;
});

/* ---------------- フロント ---------------- */

function csw_icons($s) {
    switch ($s['icon_preset']) {
        case 'emoji_moon': return array('🌙', '🌙');
        case 'unicode':    return array('☾', '☀');
        case 'text':       return array('ダーク', 'ライト');
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

/* 除外リストから :not() の断片を作る (例: array('.a') → ':not(.a a)') */
function csw_not_chain($excludes, $inner) {
    $out = '';
    foreach ($excludes as $ex) {
        $out .= ':not(' . $ex . ' ' . $inner . ')';
    }
    return $out;
}

// FOUC 防止スクリプトだけは最優先 (head の先頭)
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

// CSS 本体はテーマのスタイルシートより後 (priority 999) に出す
add_action('wp_head', function () {
    $s = csw_settings();
    $excludes = csw_parse_excludes($s['exclude_selectors']);
    $a_not = csw_not_chain($excludes, 'a');
    $pre_not = '';
    $code_not = '';
    foreach ($excludes as $ex) {
        $pre_not .= ':not(' . $ex . '):not(' . $ex . ' pre)';
        $code_not .= ':not(' . $ex . ' code)';
    }
    ?>
    <style id="color-switch-css">
    :root {
        --csw-bg: <?php echo esc_html($s['dark_bg']); ?>;
        --csw-surface: <?php echo esc_html($s['dark_surface']); ?>;
        --csw-text: <?php echo esc_html($s['dark_text']); ?>;
        --csw-link: <?php echo esc_html($s['dark_link']); ?>;
        --csw-link-hover: <?php echo esc_html($s['dark_link_hover']); ?>;
        --csw-accent: <?php echo esc_html($s['dark_accent']); ?>;
        --csw-btn-bg: <?php echo esc_html($s['button_bg']); ?>;
        --csw-btn-color: <?php echo esc_html($s['button_color']); ?>;
    }
    html.dark-mode { color-scheme: dark; }
    html.dark-mode body { background-color: var(--csw-bg) !important; color: var(--csw-text) !important; }
    html.dark-mode ::selection { background: var(--csw-accent); color: #393939; }

    /* 汎用: ヘッダー/フッター/サイドバー相当 */
    html.dark-mode header, html.dark-mode footer, html.dark-mode aside {
        background-color: var(--csw-surface) !important; color: var(--csw-text) !important;
    }
    html.dark-mode header a, html.dark-mode footer a, html.dark-mode aside a {
        color: var(--csw-text) !important;
    }

    /* 汎用: 見出し・本文 */
    html.dark-mode h1, html.dark-mode h2, html.dark-mode h3, html.dark-mode h4,
    html.dark-mode h5, html.dark-mode h6, html.dark-mode p, html.dark-mode li,
    html.dark-mode dt, html.dark-mode dd, html.dark-mode label, html.dark-mode figcaption,
    html.dark-mode td, html.dark-mode th {
        color: var(--csw-text) !important;
    }

    /* 汎用: リンク (除外リスト配下は元の色のまま) */
    html.dark-mode a<?php echo $a_not; ?> { color: var(--csw-link) !important; }
    html.dark-mode a<?php echo $a_not; ?>:hover { color: var(--csw-link-hover) !important; }
    html.dark-mode header a, html.dark-mode footer a, html.dark-mode aside a { color: var(--csw-text) !important; }

    /* 汎用: 表 */
    html.dark-mode table { background-color: transparent !important; color: var(--csw-text) !important; border-color: #555 !important; }
    html.dark-mode th { background-color: var(--csw-surface) !important; color: var(--csw-text) !important; border-color: #555 !important; }
    html.dark-mode td { background-color: transparent !important; color: var(--csw-text) !important; border-color: #555 !important; }

    /* 汎用: フォームとコード (除外リスト配下の pre/code は触らない) */
    html.dark-mode input, html.dark-mode textarea, html.dark-mode select {
        background-color: var(--csw-surface) !important; color: var(--csw-text) !important; border-color: #555 !important;
    }
    html.dark-mode pre<?php echo $pre_not; ?>, html.dark-mode code<?php echo $code_not; ?> {
        background-color: var(--csw-surface) !important; color: var(--csw-text) !important; border-color: #555 !important;
    }
    <?php if (!empty($s['img_dim'])): ?>
    html.dark-mode img { filter: brightness(.92) contrast(1.02); }
    <?php endif; ?>

    /* カスタムルール */
    <?php foreach ($s['rules'] as $r): ?>
    <?php if (empty($r['enabled']) || $r['selector'] === '') continue; ?>
    <?php
        $decls = array();
        if ($r['bg'] !== '') $decls[] = 'background-color: ' . $r['bg'] . ' !important;';
        if ($r['color'] !== '') $decls[] = 'color: ' . $r['color'] . ' !important;';
        if ($r['border'] !== '') $decls[] = 'border-color: ' . $r['border'] . ' !important;';
        if (!$decls) continue;
        $parts = array();
        foreach (explode(',', $r['selector']) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (strpos($part, 'html.dark-mode') !== 0) $part = 'html.dark-mode ' . $part;
            $parts[] = str_replace('</', '<\/', $part);
        }
        if (!$parts) continue;
    ?>
    <?php echo implode(', ', $parts); ?> { <?php echo implode(' ', $decls); ?> }
    <?php endforeach; ?>

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
    return '<button' . $idAttr . ' type="button" class="csw-toggle-btn" aria-label="' . esc_attr(__('カラースキーム切替', 'color-switch')) . '" title="' . esc_attr(__('カラースキーム切替', 'color-switch')) . '" data-icon-light="' . esc_attr($iconLight) . '" data-icon-dark="' . esc_attr($iconDark) . '">' . esc_html($iconLight) . '</button>';
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
// 従来プラグインからの互換用エイリアス
add_shortcode('dark_mode_toggle', function () {
    return csw_button_html(csw_settings(), '');
});
