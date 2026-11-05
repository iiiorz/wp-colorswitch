# ColorSwitch

テーマに依存しないカラースキーム（ダークモード）切替プラグイン for WordPress。
単一ファイル構成（`color-switch.php`）＋言語ファイルで動作します。

A theme-independent color scheme (dark mode) toggle plugin for WordPress.
A single PHP file (`color-switch.php`) plus language files.

## 特徴 / Features

- **すべてがルール**: 基本カラーという概念はなく、「セレクタ × 背景色／文字色／枠線色」のルールリストで配色を組み立てる
- **コアルール**: 背景色・文字色・リンク・リンクホバーの4ルールは初期状態で用意され、削除できない（色やセレクタは変更可）
- **メモ欄**: 各ルールに「このセレクタが何を指すか」のメモを付けられる
- **例外指定**: 色を変えたくない要素をセレクタ指定で対象外に。`code //コードブロック` のように `//` でメモ（コメント）を書ける。初期値は pre / code
- **ユーザープリセット**: 現在のルール＋例外指定を名前付きで保存（上限10件）・適用・削除（確認付き）。JSON でエクスポート／インポート可能（インポートは一覧への追加のみ、上限超過時は警告して何も取り込まない）
- **切替ボタン**: 絵文字・記号・テキスト・カスタム文字列、四隅フローティング or ショートコード `[color_switch]`（旧 `[dark_mode_toggle]` も可）
- **タブ分け設定画面**: ルール / ボタン / プリセット
- 初期モード auto / light / dark、localStorage 永続化、FOUC 防止スクリプト付き
- **UI は英語ベース＋日本語訳**（`languages/color-switch-ja.*`）。日本語環境では自動で日本語表示

## インストール / Installation

1. zip を管理画面からアップロードするか、`color-switch.php` と `languages/` を `wp-content/plugins/color-switch/` に置く
2. 管理画面で有効化
3. 設定 > ColorSwitch からルールを調整

## 動作環境 / Requirements

- WordPress 6.5+ 推奨（日本語訳は `.l10n.php` 形式。それ以前の環境向けに `.mo` も同梱 — リポジトリにはテキスト形式の `.po` / `.l10n.php` のみ収録し、`.mo` は配布 zip に含まれます）

## ステータス / Status

- v1.1.0 — ルール統合（基本カラー廃止）、コアルール＋メモ欄、例外指定のコメント対応、ユーザープリセット（保存/適用/Export・Import）、英語UI＋日本語訳
- v1.0.0 — Phase 1（ルールエンジン化）
- 今後の予定: ボタン拡張（FontAwesome・形・サイズ）、Cocoon 向けルールセットの見た目等価チェック

## ライセンス / License

GPL-2.0-or-later
