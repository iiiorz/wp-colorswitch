# ColorSwitch

テーマに依存しないカラースキーム（ダークモード）切替プラグイン for WordPress。
単一ファイル構成（`color-switch.php`）で動作します。

A theme-independent color scheme (dark mode) toggle plugin for WordPress.
It works as a single file: `color-switch.php`.

## 特徴 / Features

- **基本カラー**: 背景・面・文字・リンク・リンクホバー・アクセントの6色を役割ベースで設定し、汎用要素セレクタに適用
- **カスタムルール**: 「セレクタ × 背景色／文字色／枠線色」の行を自由に追加できるルールエンジン
- **除外リスト**: 色を変えたくない要素（SNSボタン、商品リンクなど）をセレクタ指定で対象外に
- **プリセット**: 汎用プリセット（既定）と Cocoon プリセット（ルール20行＋除外5件）をワンクリックで読み込み
- **切替ボタン**: 絵文字・記号・テキスト・カスタム文字列、四隅フローティング or ショートコード `[color_switch]`（旧 `[dark_mode_toggle]` も可）
- **タブ分け設定画面**: 基本カラー / カスタムルール / ボタン / プリセット
- 初期モード auto / light / dark、localStorage 永続化、FOUC 防止スクリプト付き

## インストール / Installation

1. `color-switch.php` を `wp-content/plugins/color-switch/` に置く（または zip 化して管理画面からアップロード）
2. 管理画面で有効化
3. 設定 > ColorSwitch から色やルールを調整

## 動作環境 / Requirements

- WordPress（検証はローカル環境で実施）
- FontAwesome は同梱しません（将来のボタン拡張で、読み込み済みの FA を使う想定）

## ステータス / Status

- v1.0.0 — Phase 1（ルールエンジン化）完了
- 今後の予定: ボタン拡張（FontAwesome・形・サイズ）、Cocoon プリセットの等価検証、旧プラグイン設定の移行、公開整備

## ライセンス / License

GPL-2.0-or-later
