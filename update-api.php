<?php
/**
 * MoeZ · 官方授权 / 增量更新服务端点（2.2.0）
 * ------------------------------------------------------------
 * 放在「授权站」（官方主站）根目录使用 —— 主站本身就运行本程序，
 * 因此不需要任何额外的授权管理站点，也不需要存放任何更新包：
 *   · act=verify    授权码验证 + 程序/主题最新版本信息
 *   · act=manifest  官方文件清单（本站程序目录 / 模板目录实时扫描，带 sha1）
 *   · act=file      按清单路径下载单个文件（仅限安全白名单路径）
 *
 * 授权码在授权站后台仪表盘「🎫 授权发放」卡片生成（HMAC 自校验，
 * 绑定域名 + 有效期；密钥 update_hub_secret 仅存本站设置表）。
 *
 * 安全边界：
 *   · 所有 act 都要求有效授权码（域名绑定 + 未过期 + 未被多域名锁定）；
 *   · 文件下载与清单扫描复用 moe_update_safe_path() 白名单
 *     （禁路径穿越 / 禁 config.php、content/ 等受保护项 / 禁危险类型）；
 *   · 主题模式只允许访问 templates/<slug>/ 下的文件；
 *   · 同一授权码出现在 3 个及以上不同域名时自动锁定（防扩散），
 *     授权站删除 content/updates/auth-usage.json 即可重置。
 */
define('MOE_API', true);
require __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array('ok' => 0, 'msg' => '仅支持 GET 请求'), JSON_UNESCAPED_UNICODE);
    exit;
}

function api_die($msg)
{
    echo json_encode(array('ok' => 0, 'msg' => $msg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!function_exists('moe_auth_code_verify')) api_die('授权模块缺失：请更新 includes/auth.php');
if (!function_exists('moe_update_safe_path')) api_die('系统模块缺失：请更新 includes/system.php');

$secret = trim((string)setting('update_hub_secret', ''));
if ($secret === '') api_die('本站尚未开启授权服务（后台仪表盘「授权发放」初始化后才会对外开放）');

/* ---------------- 授权码校验（所有 act 共用） ---------------- */
$code   = trim((string)($_GET['code'] ?? ''));
$domain = moe_auth_normalize_domain((string)($_GET['domain'] ?? ''));
if ($code === '' || $domain === '') api_die('缺少授权码或域名参数');

$vres = moe_auth_code_verify($code, $domain, $secret);
if (empty($vres['ok'])) api_die($vres['msg']);

/* 防扩散：同一授权码出现 3 个及以上不同域名时锁定 */
$usageFile = MOE_ROOT . '/content/updates/auth-usage.json';
$ukey = substr(md5($code), 0, 8);
$usage = array();
if (is_file($usageFile)) {
    $j = json_decode((string)@file_get_contents($usageFile), true);
    if (is_array($j)) $usage = $j;
}
$domains = isset($usage[$ukey]) && is_array($usage[$ukey]) ? $usage[$ukey] : array();
if (!isset($domains[$domain]) && count($domains) >= 3) {
    api_die('该授权码已在多个域名使用，已被锁定。请联系官方重置（授权站删除 content/updates/auth-usage.json）');
}
$domains[$domain] = time();
$usage[$ukey] = $domains;
if (!is_dir(dirname($usageFile))) @mkdir(dirname($usageFile), 0755, true);
@file_put_contents($usageFile, json_encode($usage), LOCK_EX);

/* 站点状态管控：违规下架 → 拒绝更新；待整改 → 透出警告（按授权码尾号匹配站点档案） */
$siteStatus = 'ok';
$siteNote = '';
$siteTail = strtolower(substr($code, -8));
$sitesFile = MOE_ROOT . '/content/updates/auth-sites.json';
if (is_file($sitesFile)) {
    $sites = json_decode((string)@file_get_contents($sitesFile), true);
    if (is_array($sites)) {
        foreach ($sites as $s) {
            if (is_array($s) && isset($s['tail']) && strtolower((string)$s['tail']) === $siteTail) {
                $siteStatus = (string)($s['status'] ?? 'ok');
                $siteNote = (string)($s['status_note'] ?? '');
                break;
            }
        }
    }
}
if ($siteStatus === 'banned') {
    api_die('该站点授权已被官方下架' . ($siteNote !== '' ? '：' . $siteNote : '')
        . '。如有疑问请通过官方邮箱申诉整改，整改通过后可恢复。');
}

/* ---------------- act = verify：授权 + 最新版本信息 ---------------- */
$act = (string)($_GET['act'] ?? '');

if ($act === 'verify') {
    $date = ''; $changelog = ''; $download = '';
    $vj = is_file(MOE_ROOT . '/version.json') ? json_decode((string)@file_get_contents(MOE_ROOT . '/version.json'), true) : array();
    if (is_array($vj)) {
        $date = (string)($vj['date'] ?? '');
        $changelog = (string)($vj['changelog'] ?? '');
        $download = (string)($vj['download'] ?? '');   // 网盘 / 对象存储直链的整包 zip（可选）
    }
    $themes = array();
    foreach (themes_list() as $t) {
        $themes[] = array(
            'dir'     => $t['dir'],
            'name'    => (string)$t['name'],
            'version' => (string)$t['version'],
            'desc'    => (string)$t['desc'],
        );
    }
    // 官方插件清单：official/plugins/<dir>/plugin.json（站长把要分发的插件放这里）
    $plugins = array();
    $pjFiles = glob(MOE_ROOT . '/official/plugins/*/plugin.json');
    foreach (is_array($pjFiles) ? $pjFiles : array() as $pf) {
        $j = json_decode((string)@file_get_contents($pf), true);
        if (!is_array($j)) continue;
        $plugins[] = array(
            'dir'     => basename(dirname($pf)),
            'name'    => (string)($j['name'] ?? basename(dirname($pf))),
            'version' => (string)($j['version'] ?? '1.0.0'),
            'desc'    => (string)($j['description'] ?? ''),
        );
    }

    // 2.2.2：主题 / 插件的整包更新直链取自「积分商城 → 资源」里填的下载地址（download_url）。
    // 2.2.17：同时下发资源的「下载密码」字段（download_pwd）——网盘分享有密码时客户端自动带上，
    //         站长只需把密码填进密码框，无需再拼进下载地址。
    // 匹配规则：资源名称 / 简介 / 下载地址里包含主题目录名或主题名即视为同一资源。
    $goodsDl = array();
    try {
        if (function_exists('moe_pt_table') && function_exists('rows')) {
            $goodsDl = (array)rows('SELECT title, summary, cat, download_url, download_pwd FROM ' . moe_pt_table('goods')
                . " WHERE status = 'on' AND download_url <> ''");
        }
    } catch (Throwable $e) { $goodsDl = array(); }
    $goodsDlMatch = function ($slug, $name) use ($goodsDl) {
        $slug = strtolower(trim((string)$slug));
        $name = strtolower(trim((string)$name));
        if ($slug === '' && $name === '') return array('url' => '', 'pwd' => '');
        foreach ($goodsDl as $g) {
            $hay = strtolower((string)$g['title'] . ' ' . (string)$g['summary'] . ' ' . (string)$g['download_url']);
            if (($slug !== '' && strpos($hay, $slug) !== false)
                || ($name !== '' && mb_strlen($name, 'UTF-8') >= 4 && strpos($hay, $name) !== false)) {
                return array('url' => (string)$g['download_url'], 'pwd' => (string)($g['download_pwd'] ?? ''));
            }
        }
        return array('url' => '', 'pwd' => '');
    };
    // 2.2.5：程序整包直链同样取自资源列表 —— version.json 没填 download 时，
    // 自动匹配分类为「程序」或名称 / 简介含 "moe home" 的资源下载地址
    $downloadPwd = '';
    if ($download === '' && function_exists('moe_pt_table') && function_exists('rows')) {
        try {
            foreach ($goodsDl as $g) {
                $isProgram = strtolower(trim((string)($g['cat'] ?? ''))) === '程序'
                    || strpos(strtolower((string)$g['title'] . ' ' . (string)$g['summary']), 'moe home') !== false;
                if ($isProgram) { $download = (string)$g['download_url']; $downloadPwd = (string)($g['download_pwd'] ?? ''); break; }
            }
        } catch (Throwable $e) { /* 忽略 */ }
    }
    foreach ($themes as $i => $t) {
        $dl = $goodsDlMatch($t['dir'], $t['name']);
        if ($dl['url'] !== '') { $themes[$i]['download'] = $dl['url']; $themes[$i]['download_pwd'] = $dl['pwd']; }
    }
    foreach ($plugins as $i => $p2) {
        $dl = $goodsDlMatch($p2['dir'], $p2['name']);
        if ($dl['url'] !== '') { $plugins[$i]['download'] = $dl['url']; $plugins[$i]['download_pwd'] = $dl['pwd']; }
    }
    $out = array(
        'ok' => 1,
        'auth' => array('domain' => $domain, 'expire' => $vres['expire'], 'note' => ''),
        'program' => array('version' => MOE_VERSION, 'date' => $date, 'changelog' => $changelog,
                           'download' => $download, 'download_pwd' => $downloadPwd),
        'themes'  => $themes,
        'plugins' => $plugins,
    );
    if ($siteStatus === 'warn') {
        $out['notice'] = '⚠️ 官方提醒：你的站点被举报或检测到违规内容' . ($siteNote !== '' ? '（' . $siteNote . '）' : '')
            . '，请立即整改。未在期限内整改将被下架授权、停止更新服务。';
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------- act = manifest：官方文件清单 ---------------- */
if ($act === 'manifest') {
    $type = (string)($_GET['type'] ?? 'program');
    if ($type === 'theme') {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['slug'] ?? ''));
        $root = MOE_ROOT . '/templates/' . $slug;
        if ($slug === '' || !is_file($root . '/info.php')) api_die('官方没有该模板：' . $slug);
        $info = include $root . '/info.php';
        $version = is_array($info) && isset($info['version']) ? (string)$info['version'] : '';
    } elseif ($type === 'plugin') {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['slug'] ?? ''));
        $root = MOE_ROOT . '/official/plugins/' . $slug;
        if ($slug === '' || !is_file($root . '/plugin.json')) api_die('官方没有该插件：' . $slug);
        $j = json_decode((string)@file_get_contents($root . '/plugin.json'), true);
        $version = is_array($j) ? (string)($j['version'] ?? '1.0.0') : '1.0.0';
    } else {
        $type = 'program';
        $slug = '';
        $root = MOE_ROOT;
        $version = MOE_VERSION;
    }

    // 排除目录：用户数据 / 缓存 / 上传 / 官方分发源 / 测试与开发目录
    $skipDirs = array('content', 'uploads', 'cache', 'data', 'backup', 'backups', 'vendor',
                      'official', '.git', '.workbuddy', 'node_modules', 'tests', 'docs1.html');

    $files = array();
    $stack = array(array('', $root));
    while ($stack) {
        list($rel, $abs) = array_pop($stack);
        $items = @scandir($abs);
        foreach (is_array($items) ? $items : array() as $it) {
            if ($it === '.' || $it === '..') continue;
            $rp = $rel === '' ? $it : $rel . '/' . $it;
            $ap = $abs . '/' . $it;
            if (is_dir($ap)) {
                if (in_array(strtolower($it), $skipDirs, true)) continue;
                if ($it[0] === '.' || strpos($it, '_tmp') === 0 || strpos($it, '_admin-patch') === 0) continue;
                $stack[] = array($rp, $ap);
                continue;
            }
            $err = '';
            if (!moe_update_safe_path($rp, $err)) continue;   // 与客户端覆盖时同一套规则
            if (strpos($rp, '_admin-patch/') === 0) continue; // 本站内部补丁不对外分发
            $h = @hash_file('sha1', $ap);
            if ($h === false) continue;
            $files[] = array($rp, $h, (int)@filesize($ap));
        }
    }
    echo json_encode(array('ok' => 1, 'version' => $version, 'files' => $files),
                     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------- act = file：下载单个官方文件 ---------------- */
if ($act === 'file') {
    $type = (string)($_GET['type'] ?? 'program');
    $path = str_replace('\\', '/', trim((string)($_GET['path'] ?? '')));
    $err  = '';
    if (!moe_update_safe_path($path, $err)) api_die('路径不合法：' . $err);
    if ($type === 'theme') {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['slug'] ?? ''));
        if ($slug === '' || strpos($path, $slug . '/') !== 0) api_die('主题文件路径必须位于 templates/' . $slug . '/ 下');
        $abs = MOE_ROOT . '/templates/' . $path;
        $rootReal = realpath(MOE_ROOT . '/templates/' . $slug);
    } elseif ($type === 'plugin') {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['slug'] ?? ''));
        if ($slug === '' || strpos($path, $slug . '/') !== 0) api_die('插件文件路径必须位于 official/plugins/' . $slug . '/ 下');
        $abs = MOE_ROOT . '/official/plugins/' . $path;
        $rootReal = realpath(MOE_ROOT . '/official/plugins/' . $slug);
        if ($rootReal === false || !is_file(MOE_ROOT . '/official/plugins/' . $slug . '/plugin.json')) api_die('官方没有该插件：' . $slug);
    } else {
        $type = 'program';
        $abs = MOE_ROOT . '/' . $path;
        // 程序模式不允许拉取本站内部补丁目录
        if (strpos($path, '_admin-patch/') === 0) api_die('路径不合法：内部补丁目录');
        $rootReal = realpath(MOE_ROOT);
    }
    $real = realpath($abs);
    if ($real === false || !is_file($real)) api_die('官方不存在该文件：' . $path);
    // 双保险：确认解析后的真实路径仍在允许的根目录内
    if ($rootReal === false || strpos($real, $rootReal) !== 0) api_die('路径越界，已拒绝');

    header('Content-Type: application/octet-stream');
    header('X-File-Path: ' . $path);
    header('Content-Length: ' . filesize($real));
    readfile($real);
    exit;
}

api_die('未知操作，可用 act：verify / manifest / file');
