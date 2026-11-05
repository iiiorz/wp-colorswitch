# ColorSwitch

カラースキームを切り替えるボタンを設置できるWordPressプラグイン。
ダークモード切替ボタンとしても、まったく違う色合いへの切替ボタンとしても使えます。
英語と日本語対応。

A theme-independent color scheme (dark mode) toggle plugin for WordPress.
A single PHP file (`color-switch.php`) plus language files.

## 特徴 / Features

- **自由度の高い設定**: ダークモードに限らず、「セレクタ × 背景色／文字色／枠線色」のルールリストで配色を設定。
- **例外指定**: 色を変えたくない要素をセレクタ指定で対象外に。
- **プリセット**: 現在のルール＋例外指定をプリセットとして保存。JSON でエクスポート／インポート可能。
- **切替ボタン**: 絵文字・記号・カスタム文字（絵文字やFont Awesome のクラス名も指定可）から選んでボタンに表示。ページの四隅フローティング or ショートコードで設置場所も選べます。文字スタイル、ボタンサイズ、形状（丸／角丸／四角）、位置オフセット（px）等のカスタマイズをボタンのライブプレビューで確認しながら設定可能。

## インストール / Installation

1. zip を管理画面からアップロードするか、`color-switch.php` と `languages/` を `wp-content/plugins/color-switch/` に置く
2. 管理画面で有効化
3. 設定 > ColorSwitch から設定

## 動作環境 / Requirements

- WordPress 6.5+ 推奨（日本語は `.l10n.php` 形式。それ以前の環境向けに `.mo` も同梱 — リポジトリにはテキスト形式の `.po` / `.l10n.php` のみ収録し、`.mo` は配布 zip に含まれます）

## リリースノート / Status

- v1.0.0 — 初版

## 画面イメージ
(1) ルール
<img width="1369" height="938" alt="Image" src="https://github.com/user-attachments/assets/55018266-37b0-47f0-b1ac-f94a833c08ba" />

[(2)ボタン](https://github.com/user-attachments/assets/e0cec9a8-6c45-49a1-b38b-8e2b2e7964d7), [(3)プリセット](https://github.com/user-attachments/assets/e0cec9a8-6c45-49a1-b38b-8e2b2e7964d7)


## ライセンス / License

GPL-2.0-or-later
