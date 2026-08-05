<?php
// auth_common.php が読み込む設定。kappstore は認証以外に共有設定を必要としない。
//
// kurage.exbridge.jp の config.php には kfreqai / rqdb4ai の API 認証情報が
// 入っている。kappstore には不要なので、あれをコピーせずここを空で置く
// （使わない認証情報を、置く理由のないドキュメントルートに増やさない）。
//
// auth_common.php 側の設定は AIGM_* 定数だが、すべて defined() ガード付きで
// 既定値を持つため、ここで定義しなくてよい。
