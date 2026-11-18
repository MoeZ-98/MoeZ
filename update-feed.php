<?php
/**
 * MoeZ 自动更新源端点（2.2.23+）
 *
 * 作用：让站长「永远不用手动改 version.json」——
 *   以后每次发新版，只需把程序包（如 moe-home-v2.2.24.zip）上传到本站 update/ 目录，
 *   本端点会自动扫描目录、解析文件名里的版本号，把版本最高的包广播为最新版本。
 *
 * 更新源地址填：https://你的域名/update-feed.php
 *
 * 广播格式（与 version.json 完全兼容，检测端只要求 version 字段）：
 *   { "version": "2.2.24", "date": "2026-11-13", "download": "https://.../update/moe-home-v2.2.24.zip", "notes": [...], "changelog": "..." }
 *
 * 可选增强（都不需要也能用）：
 *   - update/notes.txt          每行一条更新说明，会作为 notes 数组输出
 *   - update/feed.json          手动兜底清单：{"version":"x.y.z","download":"...","notes":[...]}
 *                                 （当 update/ 里没有任何 zip、又想临时广播某版本时用）
 *   - update/ 目录里没有任何包时，自动回退输出站点根目录的 version.json（旧用法依旧可用）
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/* ---------- 站点根与 update 目录 ---------- */
$root = __DIR__;
$dir  = $root . '/update';

/* ---------- 输出绝对地址用 ---------- */
function moe_uf_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (int)(isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0) === 443
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && stripos((string)$_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false);
    $scheme = $https ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') $host = 'localhost';
    // 去掉默认端口，保持地址干净
    if (($https && substr($host, -4) === ':443') || (!$https && substr($host, -3) === ':80')) {
        $host = substr($host, 0, strrpos($host, ':'));
    }
    // 端点在站点根：脚本所在目录即站点根的 URL 路径
    $path = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . $path;
}

/* ---------- 从文件名解析版本号 ----------
 * 两条规则，防止把 backup-2026-11-13.zip 之类的日期备份误判成 v2026.11 大版本：
 *   1. 文件名含 moe-home → 直接取数字版本（moe-home-2.2.24.zip 也认）
 *   2. 其他文件名 → 必须是 v 前缀才认（xxx-v2.2.24.zip） */
function moe_uf_version_of($name)
{
    $name = (string)$name;
    if (stripos($name, 'moe-home') !== false) {
        if (preg_match('/(\d+(?:\.\d+){1,3})/', $name, $m)) return $m[1];
        return '';
    }
    if (preg_match('/\bv(\d+(?:\.\d+){1,3})/i', $name, $m)) return $m[1];
    return '';
}

/* ---------- 扫描 update/ 目录，取版本最高的 zip ---------- */
function moe_uf_latest_zip($dir)
{
    if (!is_dir($dir)) return null;
    $best = null; $bestV = '';
    $files = @scandir($dir);
    if (!is_array($files)) return null;
    foreach ($files as $f) {
        if (substr($f, -4) !== '.zip') continue;
        if (strpos($f, '.') === 0) continue;              // 跳过隐藏/临时文件
        $v = moe_uf_version_of($f);
        if ($v === '') continue;                          // 文件名里没有版本号的不广播
        if ($bestV === '' || version_compare($v, $bestV, '>')) {
            $bestV = $v;
            $best = $f;
        }
    }
    return $best === null ? null : array('file' => $best, 'version' => $bestV);
}

/* ---------- update/notes.txt → notes 数组 ---------- */
function moe_uf_notes($dir, $fallbackVer)
{
    $f = $dir . '/notes.txt';
    if (is_file($f)) {
        $lines = array();
        foreach (preg_split('/\r\n|\r|\n/', (string)@file_get_contents($f)) as $ln) {
            $ln = trim($ln);
            if ($ln !== '' && $ln[0] !== '#') $lines[] = $ln;   // # 开头当注释
        }
        if ($lines) return $lines;
    }
    return array('检测到新版本 v' . $fallbackVer . '，请在后台查看更新说明并下载升级。');
}

/* ---------- 组装清单 ---------- */
$feed = null;

// ① 手动兜底清单优先级最高（想临时广播某个尚未上传的版本时用）
$manual = is_file($dir . '/feed.json') ? json_decode((string)@file_get_contents($dir . '/feed.json'), true) : null;
if (is_array($manual) && !empty($manual['version'])) {
    $feed = array(
        'version'   => (string)$manual['version'],
        'date'      => (string)($manual['date'] ?? date('Y-m-d')),
        'download'  => (string)($manual['download'] ?? ''),
        'notes'     => is_array($manual['notes'] ?? null) ? $manual['notes'] : array(),
        'changelog' => (string)($manual['changelog'] ?? ''),
    );
}

// ② update/ 目录里的程序包（常规路径：丢 zip 即发版）
if ($feed === null) {
    $zip = moe_uf_latest_zip($dir);
    if ($zip !== null) {
        $v = $zip['version'];
        $feed = array(
            'version'   => $v,
            'date'      => date('Y-m-d', (int)@filemtime($dir . '/' . $zip['file'])),
            'download'  => moe_uf_base_url() . '/update/' . rawurlencode($zip['file']),
            'notes'     => moe_uf_notes($dir, $v),
            'changelog' => '',
        );
    }
}

// ③ 都没有 → 回退站点根目录的 version.json（与旧版行为一致）
if ($feed === null && is_file($root . '/version.json')) {
    $j = json_decode((string)@file_get_contents($root . '/version.json'), true);
    if (is_array($j) && !empty($j['version'])) {
        $feed = array(
            'version'   => (string)$j['version'],
            'date'      => (string)($j['date'] ?? ''),
            'download'  => (string)($j['download'] ?? ''),
            'notes'     => is_array($j['notes'] ?? null) ? $j['notes'] : array(),
            'changelog' => (string)($j['changelog'] ?? ''),
        );
        // version.json 里的 download 若是相对地址也补成绝对
        if ($feed['download'] !== '' && !preg_match('#^https?://#i', $feed['download'])) {
            $feed['download'] = moe_uf_base_url() . '/' . ltrim($feed['download'], '/');
        }
    }
}

// ④ 仍旧没有 → 明确的空清单（检测端会提示格式不正确，便于排查）
if ($feed === null) {
    $feed = array('version' => '', 'date' => '', 'download' => '', 'notes' => array(), 'changelog' => '未发现可广播的版本：请将程序包上传到 update/ 目录');
}

echo json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
