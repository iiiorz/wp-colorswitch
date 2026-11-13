# ColorSwitch

カラースキームを切り替えるボタンを設置できるWordPressプラグイン。
ダークモード切替ボタンとしても、まったく違う色合いへの切替ボタンとしても使えます。
英語と日本語対応。

A WordPress plugin that adds a button for switching color schemes.
It works as a dark mode toggle, or as a switch to a completely different color palette.
Available in English and Japanese.

<a href="https://ko-fi.com/tayura" target="_blank"><img height="36" style="border:0px;height:36px;" src="https://storage.ko-fi.com/cdn/kofi2.png?v=3" border="0" alt="Buy Me a Coffee at ko-fi.com" /></a>

## 特徴 / Features

- **自由度の高い設定**: ダークモードに限らず、「セレクタ × 背景色／文字色／枠線色」のルールリストで配色を設定。
- **例外指定**: 色を変えたくない要素をセレクタ指定で対象外に。
- **プリセット**: 現在のルール＋例外指定をプリセットとして保存。JSON でエクスポート／インポート可能。
- **切替ボタン**: 絵文字・記号・カスタム文字（絵文字やFont Awesome のクラス名も指定可）から選んでボタンに表示。ページの四隅フローティング or ショートコードで設置場所も選べます。文字スタイル、ボタンサイズ、形状（丸／角丸／四角）、位置オフセット（px）等のカスタマイズをボタンのライブプレビューで確認しながら設定可能。

- **Highly flexible configuration**: Not just dark mode — build your color scheme from a rule list of "selector × background color / text color / border color".
- **Exceptions**: Exclude elements you don't want recolored by specifying their selectors.
- **Presets**: Save the current rules and exceptions as a preset. Export and import as JSON.
- **Toggle button**: Choose what the button shows from emoji, symbols, or custom text (an emoji or a Font Awesome class name works too). Place it floating in any corner of the page, or via a shortcode. Text style, button size, shape (circle / rounded / square), position offset (px) and more can be customized while checking the live preview.

## インストール / Installation

1. zip を管理画面からアップロードするか、`color-switch.php` と `languages/` を `wp-content/plugins/color-switch/` に置く
2. 管理画面で有効化
3. 設定 > ColorSwitch から設定

1. Upload the zip from the admin screen, or place `color-switch.php` and `languages/` in `wp-content/plugins/color-switch/`
2. Activate it on the admin screen
3. Configure it under Settings > ColorSwitch

## 動作環境 / Requirements

- WordPress 6.5+ 推奨（日本語は `.l10n.php` 形式。それ以前の環境向けに `.mo` も同梱 — リポジトリにはテキスト形式の `.po` / `.l10n.php` のみ収録し、`.mo` は配布 zip に含まれます）

- WordPress 6.5+ recommended (Japanese translations use the `.l10n.php` format; a `.mo` file is also included for older environments — the repository contains only the text-format `.po` / `.l10n.php`, and the `.mo` is included in the distribution zip)

## リリースノート / Release Notes

- v1.1.1 — v1.0.0 からの更新後にボタンサイズが初期値へ戻ることがある問題を修正。セレクタの子結合子（`>`）や `:is()` などが正しく扱えない問題と、引用符付きフォント名がフロントで効かない問題もあわせて修正

- v1.1.1 — Fixed the button size possibly reverting to the default after upgrading from v1.0.0. Also fixed selectors using the child combinator (`>`) or `:is()` being mishandled, and quoted font-family names not applying on the front end

- v1.1.0 — ボタンの横幅・縦幅を個別に指定できるようにした（横長ボタン対応）

- v1.1.0 — Button width and height can now be set separately (wide buttons supported)

- v1.0.0 — 初版

- v1.0.0 — First release

## 画面イメージ / Screenshots

(1) ルール / Rules
<img width="1369" height="938" alt="Image" src="https://github.com/user-attachments/assets/55018266-37b0-47f0-b1ac-f94a833c08ba" />

[(2)ボタン / Button](https://github.com/user-attachments/assets/e0cec9a8-6c45-49a1-b38b-8e2b2e7964d7), [(3)プリセット / Presets](https://github.com/user-attachments/assets/e0cec9a8-6c45-49a1-b38b-8e2b2e7964d7)


## 支援 / Support

このプラグインが役に立ったら、[Ko-fi](https://ko-fi.com/tayura) で開発支援をいただけると嬉しいです。

If this plugin helps you, I'd appreciate your support on [Ko-fi](https://ko-fi.com/tayura).

## ライセンス / License

GPL-2.0-or-later
