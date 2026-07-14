# Prime Cache

WordPress を高速かつ安定して動かすページキャッシュプラグイン。

Prime Cache は、静的なページキャッシュ、ファイル最適化、遅延読み込み、最新の画像形式への変換で WordPress サイトを高速化します。安定性と「そのままで使える初期値」を重視した設計です。共用サーバーでも動作し、**`wp-config.php` を自動で書き換えることは一切ありません**。

- **WordPress.org**: https://wordpress.org/plugins/prime-cache/
- **プラグイン紹介ページ**: https://raplsworks.com/plugins/prime-cache/

## 無料版の機能

- **ページキャッシュ** — 静的な HTML キャッシュ。任意の `.htaccess` ファストパスを使うと、PHP を起動する前に Apache が直接キャッシュを配信します。
- **ブラウザーキャッシュヘッダー** — CSS、JavaScript、画像、フォントに `Cache-Control` と `Expires` を付与します。
- **圧縮** — Gzip は標準で有効。`mod_brotli` が使える環境では Brotli も利用できます。
- **ファイル最適化** — HTML / CSS / JavaScript の最小化、JavaScript の defer と delay、CSS の非同期読み込み、クエリ文字列の削除。
- **遅延読み込み** — 画像、iframe、動画に対応。LCP を守るため、先頭の画像を何枚スキップするかを設定できます。
- **WebP 変換** — 対応ブラウザーに、より軽い画像を配信します。
- **キャッシュのプリロード** — バックグラウンドの非ブロッキングなリクエストで、あらかじめキャッシュを温めます。
- **パフォーマンスチューニング** — 絵文字、埋め込み、フロントエンドの Dashicons、jQuery Migrate、XML-RPC などを無効化できます。
- **自動パージ** — 投稿、コメント、メニュー、テーマが変更されたときに、関連するキャッシュだけを自動的にクリアします。

## Pro 版

Prime Cache Pro は、本番サイト向けの高度な最適化を追加します。

- Critical CSS の生成と、未使用 CSS の削除
- 永続オブジェクトキャッシュ (Redis、Memcached、APCu)
- AVIF 変換 (WebP に追加)
- 外部キャッシュのパージ (Cloudflare、Sucuri、Varnish)
- サイトマップやリソースのプリロード、DNS プリフェッチ、preconnect
- データベースクリーンアップの定期実行 (リビジョン、transient、オーバーヘッド)

詳細: https://raplsworks.com/plugins/prime-cache/

## 動作要件

- WordPress 5.8以降
- PHP 7.4以降
- ファストパスを使う場合は `.htaccess` が利用できる Apache (任意。標準モードはどの環境でも動作します)

## インストール

1. WordPress のプラグインディレクトリーから **Prime Cache** をインストールするか、プラグインのフォルダーを `/wp-content/plugins/` にアップロードします。
2. 「プラグイン」メニューから有効化します。
3. 管理メニューの **Prime Cache** を開き、キャッシュを有効化します。初期設定のまま使い始めても安全です。

任意のドロップインモード (WordPress の読み込み前にキャッシュ済みのページを配信する方式) については、管理画面に手順が表示されます。Prime Cache が `wp-config.php` を書き換えることはありません。

## 翻訳

日本語翻訳は作者が管理しています。他の言語への翻訳は [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/prime-cache/) から歓迎します。

## ライセンス

GPL-2.0-or-later。[LICENSE](LICENSE) を参照してください。

---

制作: [Rapls Works](https://raplsworks.com/)
