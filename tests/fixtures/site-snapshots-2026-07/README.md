# サイトスナップショット (2026-07-17〜18)

docs/plan-adaptive-presets-ai-diagnosis.md §1 の地雷表に対応する実測 HTML。
環境適応型プリセット / 診断機能の回帰テストフィクスチャとして使用する。

| ファイル | 地雷 # | 特徴 |
|---|---|---|
| raplsworks-mobile-01-before.html | #1 #2 #3 | LCP 画像 lazy / delay 実質無効 / 444KB HTML (Cocoon inline 245KB) |
| raplsworks-mobile-02-async-css.html | #4 | 1.7.39 async CSS 適用状態 (preload+noscript 構造) |
| raplsworks-mobile-03-after-fixes.html | - | eager 修正 + picture 配信後 |
| gift-by-gifted-mobile-01-inline-css.html | #5 #7 #8 | 826KB HTML / 573KB 匿名 style / GA 二重 / 本文前広告 |
| gift-by-gifted-mobile-02-still-inline.html | #6 | Cocoon OFF 後もキャッシュ 2 段で残留した状態 |
| hashgk-mobile-01-divi-fonts.html | #9 #10 | Divi earlyaccess Noto 全ウェイト / FontAwesome CDN 同期 |

いずれも公開ページのモバイル UA 取得。個人情報なし。
