<?php
/**
 * MoeZ - 前端入口 / 路由
 *
 * 静态 URL 样式（后台「站点设置 → URL 样式」切换）：
 *   cat_num  /分类别名/编号.html   → /fenlei/1.html（编号按分类独立，每个分类从 1 开始）
 *   id_html  /编号.html            → /1.html
 * 分类首页 /分类别名/、独立页 /别名.html（1.8.4 起不再带 /page/ 前缀，旧链接仍兼容）、搜索 /search/关键词、RSS /rss、站点地图 /sitemap.xml
 * 旧的 /post/xxx、/archives/3.html、/2026/11/08/xxx.html、index.php?p=... 均保持可访问
 */
require __DIR__ . '/init.php';

// 蜘蛛来访记录（普通访客自动跳过；表未就绪时静默忽略，不影响访问）
if (function_exists('moe_spider_log')) {
    moe_spider_log();
}

// 兼容：init.php 为旧版时补载新增模块（覆盖上传不完整也不至于白屏）
if (!function_exists('content_words') && is_file(__DIR__ . '/includes/markdown.php')) {
    require_once __DIR__ . '/includes/markdown.php';
}
if (!function_exists('post_url') && is_file(__DIR__ . '/includes/seo.php')) {
    require_once __DIR__ . '/includes/seo.php';
}

/* ---------- 解析路由 ---------- */
$p      = 'index';
$id     = '';
$kw     = '';
$catkey = '';
$path   = '';   // 当前路由路径（供「独立页面优先」判断，1.8.2）
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if (isset($_GET['p']) && $_GET['p'] !== '') {
    // 兼容旧链接与动态参数
    $p  = (string)$_GET['p'];
    $id = isset($_GET['id']) ? (string)$_GET['id'] : '';
    $kw = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
} else {
    $path = moe_request_path();   // 已去掉 .html 后缀与首尾斜杠
    if ($path === '') {
        $p = 'index';
    } elseif (preg_match('#^(rss|feed)(\.xml)?$#i', $path)) {
        $p = 'rss';
    } elseif (preg_match('#^sitemap(\.xml)?$#i', $path)) {
        $p = 'sitemap';
    } elseif (preg_match('#^robots\.txt$#i', $path)) {
        $p = 'robots';
    } elseif (preg_match('#^category/([^/]+)$#u', $path, $m)) {
        $p = 'category'; $id = rawurldecode($m[1]);
    } elseif (preg_match('#^page/([^/]+)$#u', $path, $m)) {
        $p = 'page';  $id = rawurldecode($m[1]);
    } elseif (preg_match('#^search/?(.*)$#u', $path, $m)) {
        $p = 'search'; $kw = rawurldecode(trim($m[1], '/'));
    } elseif (preg_match('#^tag/([^/]+)$#u', $path, $m)) {
        $p = 'tag';   $id = rawurldecode($m[1]);      // /tag/关键词 标签聚合页（1.8.3）
    } elseif (preg_match('#^user(?:/([^/]*))?$#u', $path, $m)) {
        $p = 'user';  $id = isset($m[1]) ? trim($m[1], '/') : '';   // 2.0.0 /user、/user/profile
    } elseif (preg_match('#^author/(\d+)$#u', $path, $m)) {
        $p = 'author'; $id = $m[1];                                  // 2.0.0 公开作者主页
    } elseif (preg_match('#^shop$#i', $path)) {
        $p = 'shop';                                                 // 2.1.0 积分商城
    } elseif (preg_match('#^download/(\d+)$#', $path, $m)) {
        $p = 'download'; $id = $m[1];                                // 2.1.0 资源下载（校验兑换记录）
    } elseif (preg_match('#^links?$#i', $path)) {
        $p = 'links';                                  // /link 友链页（模板提供）
    } elseif (preg_match('#^archives?$#i', $path)) {
        $p = 'archive';                                // /archives 归档时间线（模板提供）
    } elseif (preg_match('#^about$#i', $path)) {
        $p = 'about';                                  // /about 关于页（模板提供）
    } elseif (preg_match('#^blog/article$#i', $path)) {
        $p = 'article';                                // /blog/article 写作列表（模板提供）
    } elseif (preg_match('#^life$#i', $path)) {
        $p = 'life';                                   // /life 点滴时间线（模板提供）
    } elseif (preg_match('#^companion$#i', $path)) {
        $p = 'companion';                              // /companion 看板娘小屋：用量统计（模板提供，2.1.23）
    } elseif (preg_match('#^post/([^/]+)$#u', $path, $m)) {
        $p = 'post';  $id = rawurldecode($m[1]);      // 旧链接兼容
    } elseif (preg_match('#^archives?/([^/]+)$#u', $path, $m)) {
        $p = 'post';  $id = rawurldecode($m[1]);      // 旧链接兼容
    } elseif (preg_match('#^(\d{4})/(\d{1,2})/(?:(\d{1,2})/)?(.+)$#u', $path, $m)) {
        $p = 'post';  $id = rawurldecode($m[4]);      // 旧日期目录兼容
    } elseif (preg_match('#^(\d+)$#', $path, $m)) {
        $p = 'post';  $id = $m[1];                    // 根目录 / 编号.html
    } elseif (preg_match('#^([a-zA-Z0-9_-]+)/(\d+)$#', $path, $m)) {
        $p = 'post';  $catkey = $m[1]; $id = $m[2];   // 分类目录 / 编号.html
    } elseif (preg_match('#^([^/]+)$#u', $path, $m)) {
        $p = 'category'; $id = rawurldecode($m[1]);   // /分类别名/ 或 /独立页面别名（支持中文）
    } else {
        $p = '404';
    }
}

/* ---------- 独立页面优先（1.8.2） ---------- */
// 站内存在同名「已发布独立页面」时，/about、/links、/intro、/中文别名 一律优先渲染该页面；
// 没有命中才回落到模板内置页（关于 / 友链 / 归档 / 写作 / 点滴），老站点行为不变。
if ($path !== '' && in_array($p, array('about', 'links', 'archive', 'article', 'life', 'companion'), true)) {
    $__pg = moe_find_page($path);
    if ($__pg) { $p = 'page'; $id = (string)$__pg['slug']; }
}

/* ---------- 站点地图 / robots ---------- */
if ($p === 'sitemap') {
    if (setting('seo_sitemap', '1') !== '1') { http_response_code(404); exit; }
    seo_sitemap();
}
if ($p === 'robots') seo_robots();

/* ---------- 维护模式 ---------- */
if (setting('site_status', 'open') !== 'open' && !in_array($p, array('rss', 'sitemap', 'robots'), true)) {
    http_response_code(503);
    header('X-Robots-Tag: noindex');
    render('maintenance');
    exit;
}

/* ---------- 用户中心 / 作者主页（2.0.0） ----------
 * 完全独立的页面，与登录状态相关，绝不进入页面缓存；也不依赖任何一套前台模板。
 */
if ($p === 'user' || $p === 'author') {
    if (function_exists('moe_user_center_page')) moe_user_center_page($p, $id);
    http_response_code(500);
    exit('用户中心模块缺失：请重新上传 includes/user.php');
}

/* ---------- 积分商城 / 资源下载（2.1.0） ----------
 * 同样不进页面缓存：商城要显示当前余额与兑换状态，下载要校验订单。
 */
if ($p === 'shop' || $p === 'download') {
    if (!function_exists('moe_pt_shop_page')) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        exit('积分模块缺失：请重新上传 <b>includes/points.php</b>');
    }
    if ($p === 'download') moe_pt_download_serve((int)$id);
    moe_pt_shop_page();
}

/* ---------- 页面缓存（1.9.0） ----------
 * 访客 GET 命中缓存直接输出（0 查询 / 0 渲染）；后台登录用户、搜索页不缓存。
 * 内容变更（发文章 / 审核评论 / 改设置）会切换缓存版本号，全站缓存立即失效。
 */
// 2.0.2：有启用中的插件时关闭前台页面缓存，避免插件输出被静态缓存固化
$__pluginsOn = function_exists('moe_plugins_active') && moe_plugins_active();
if (!$__pluginsOn && function_exists('moe_pcache_begin')) moe_pcache_begin($p);

$posts_per_page = max(1, (int)setting('posts_per_page', 6));
$site = array(
    'name'      => setting('site_name', 'MoeZ'),
    'sub'       => setting('site_sub', ''),
    'desc'      => setting('site_desc', ''),
    'keywords'  => setting('site_keywords', ''),
    'author'    => setting('site_author', ''),
    'avatar'    => setting('site_avatar', 'templates/default/assets/avatar.svg'),
    'signature' => setting('site_signature', ''),
    'footer'    => setting('footer_text', ''),
    'mail'      => setting('social_mail', ''),
    'github'    => setting('social_github', ''),
    'bilibili'  => setting('social_bilibili', ''),
    'weibo'     => setting('social_weibo', ''),
    'twitter'   => setting('social_twitter', ''),
    'rss'       => setting('social_rss', ''),
    'socials'   => function_exists('moe_social_list') ? moe_social_list() : array(),
    'stats'     => site_stats(),
    'version'   => MOE_VERSION,
);
$nav_cats = nav_categories();
$GLOBALS['moe_view_common'] = compact('site', 'nav_cats');

/** 文章 SEO 字段（兼容未升级的老数据） */
function moe_post_seo($post, $cat = null)
{
    $title = !empty($post['seo_title']) ? $post['seo_title'] : $post['title'];
    $keys  = !empty($post['seo_keywords']) ? $post['seo_keywords'] : '';
    if ($keys === '') {
        $parts = array();
        if ($cat) $parts[] = $cat['name'];
        $parts[] = $post['title'];
        // 1.8.3：文章标签补充进关键词（有 SEO 自定义时仍以自定义为准）
        foreach (post_tags($post) as $t) $parts[] = $t;
        $base = trim(setting('site_keywords', ''));
        if ($base !== '') $parts[] = $base;
        $keys = implode(',', array_unique(array_filter($parts)));
    }
    $desc = !empty($post['seo_desc']) ? $post['seo_desc'] : post_excerpt($post, 160);

    seo_set(array(
        'title'     => $title,
        'keywords'  => $keys,
        'desc'      => $desc,
        'canonical' => post_url($post),
        'type'      => 'article',
        'image'     => function_exists('post_cover') ? post_cover($post) : (isset($post['cover']) ? $post['cover'] : ''),
        'published' => $post['created_at'],
        'modified'  => !empty($post['updated_at']) ? $post['updated_at'] : $post['created_at'],
    ));
}

/**
 * 渲染独立页面（统一 SEO / 模板），渲染后结束请求
 * 1.8.2：/page/别名.html、/别名、/中文别名 都收敛到这里
 */
function moe_page_view($pg)
{
    seo_set(array(
        'title'     => !empty($pg['seo_title']) ? $pg['seo_title'] : $pg['title'],
        'keywords'  => !empty($pg['seo_keywords']) ? $pg['seo_keywords'] : setting('site_keywords', ''),
        'desc'      => !empty($pg['seo_desc']) ? $pg['seo_desc'] : post_excerpt($pg, 160),
        'canonical' => page_url($pg),
        'type'      => 'article',
        'modified'  => !empty($pg['updated_at']) ? $pg['updated_at'] : $pg['created_at'],
    ));
    try {
        moe_add_view((int)$pg['id']);
    } catch (Throwable $e) { /* 浏览量失败不影响访问 */ }
    render('page', array('page' => $pg));
    exit;
}

/* ---------- 各路由 ---------- */
switch ($p) {
    case 'index': {
        /* 2.1.4 修复：精选大卡文章改为「从首页列表查询中整体排除」。
           旧方案（第 1 页多取一篇、第 2 页起偏移 +1）有个漏洞：当大卡文章
           （随机 / 热门 / 自定义模式）不在所取窗口内时，窗口末尾一篇会被模板截掉，
           而下一页的偏移又跳过了它 —— 这篇文章在首页列表里就永远不会显示。
           排除式方案：核心先通过模板的 fo_home_featured_id() 拿到大卡文章 ID，
           列表查询直接排除它，分页回归标准的「每页 N 篇」——
           任何模式下每页卡片数都严格 = 后台「每页文章数」，不漏文章、不跨页重复。 */
        $home_feat_id = 0;
        if (theme() === 'fomalhaut') {
            $fo_lib = MOE_ROOT . '/templates/fomalhaut/_lib.php';
            if (is_file($fo_lib)) {
                require_once $fo_lib;
                if (function_exists('fo_home_featured_id')) {
                    $home_feat_id = max(0, (int)fo_home_featured_id());
                }
            }
        }
        $home_not_feat = $home_feat_id > 0 ? ' AND p.id <> ' . $home_feat_id : '';
        $total_all = (int)one('SELECT COUNT(*) FROM ' . moe_table('posts') . " WHERE type='post' AND status='published'");
        $total_list = max(0, $total_all - ($home_feat_id > 0 ? 1 : 0));   // 列表分页用（不含大卡文章）
        $offset = ($page - 1) * $posts_per_page;
        $posts  = rows(
            'SELECT p.*, c.name AS cat_name, c.slug AS cat_slug, c.id AS cat_id FROM ' . moe_table('posts') . ' p
             LEFT JOIN ' . moe_table('categories') . " c ON c.id = p.category_id
             WHERE p.type='post' AND p.status='published'" . $home_not_feat . '
             ORDER BY p.is_top DESC, p.created_at DESC, p.id DESC LIMIT ' . max(0, (int)$offset) . ', ' . (int)$posts_per_page
        );

        // 首页标题：与后台「站点设置 → SEO · 浏览器标题」预览共用同一逻辑（含自定义分隔符与 {site} 占位）
        $homeTitle = seo_home_title($page);

        seo_set(array(
            'title_full' => $homeTitle,
            'keywords'   => setting('site_keywords', ''),
            'desc'       => setting('site_desc', ''),
            'canonical'  => home_url($page),
            'type'       => 'website',
            'prev'       => $page > 1 ? home_url($page - 1) : '',
            'next'       => ($offset + $posts_per_page < $total_list) ? home_url($page + 1) : '',
        ));

        $pager = paginate($total_list, $posts_per_page, $page, page_base(home_url()));
        $total = $total_all;   // 模板里「全站共 N 篇」显示真实总数（含大卡文章）
        render('index', compact('posts', 'pager', 'page', 'total'));
        break;
    }

    case 'post': {
        $post = null;
        if ($catkey !== '') {
            // 分类目录 / 编号.html
            $post = moe_post_by_catnum($catkey, (int)$id);
        }
        if (!$post && $id !== '') {
            $post = ctype_digit((string)$id)
                ? row('SELECT * FROM ' . moe_table('posts') . " WHERE type='post' AND id = ?", array((int)$id))
                : row('SELECT * FROM ' . moe_table('posts') . " WHERE type='post' AND slug = ?", array((string)$id));
        }
        if (!$post || $post['status'] !== 'published') { moe_404(); }

        // 1.9.0：同一会话同一篇只记一次浏览，减少写库
        if (moe_add_view($post['id'])) $post['views'] += 1;

        $cat = $post['category_id'] ? row('SELECT * FROM ' . moe_table('categories') . ' WHERE id = ?', array($post['category_id'])) : null;
        $prev = row('SELECT id, slug, title, created_at FROM ' . moe_table('posts') . " WHERE type='post' AND status='published' AND id < ? ORDER BY id DESC LIMIT 1", array($post['id']));
        $next = row('SELECT id, slug, title, created_at FROM ' . moe_table('posts') . " WHERE type='post' AND status='published' AND id > ? ORDER BY id ASC LIMIT 1", array($post['id']));
        $comments = rows('SELECT * FROM ' . moe_table('comments') . " WHERE post_id = ? AND status='approved' ORDER BY id DESC", array($post['id']));

        moe_post_seo($post, $cat);
        render('post', compact('post', 'cat', 'prev', 'next', 'comments'));
        break;
    }

    case 'category': {
        $cat = ctype_digit((string)$id)
            ? row('SELECT * FROM ' . moe_table('categories') . ' WHERE id = ?', array((int)$id))
            : row('SELECT * FROM ' . moe_table('categories') . ' WHERE slug = ?', array((string)$id));
        if (!$cat) {
            // 1.8.2：不是分类 → 再看有没有同名的独立页面（支持 /别名 自定义路径）
            $pgOnly = moe_find_page((string)$id);
            if ($pgOnly) moe_page_view($pgOnly);
            moe_404();
        }

        $total  = (int)one('SELECT COUNT(*) FROM ' . moe_table('posts') . " WHERE category_id = ? AND type='post' AND status='published'", array($cat['id']));
        $offset = ($page - 1) * $posts_per_page;
        $posts  = rows(
            'SELECT * FROM ' . moe_table('posts') . " WHERE category_id = ? AND type='post' AND status='published'
             ORDER BY created_at DESC LIMIT $offset, $posts_per_page", array($cat['id'])
        );

        $catTitle = !empty($cat['seo_title']) ? $cat['seo_title'] : $cat['name'];
        $catDesc  = !empty($cat['seo_desc']) ? $cat['seo_desc']
                  : ($cat['description'] !== '' ? $cat['description'] : '「' . $cat['name'] . '」分类下共 ' . $total . ' 篇文章');
        $catKeys  = !empty($cat['seo_keywords']) ? $cat['seo_keywords'] : $cat['name'] . ',' . setting('site_keywords', '');
        seo_set(array(
            'title'     => $page > 1 ? $catTitle . ' · 第 ' . $page . ' 页' : $catTitle,
            'keywords'  => trim($catKeys, ','),
            'desc'      => $catDesc,
            'canonical' => cat_url($cat) . ($page > 1 ? '?page=' . $page : ''),
            'type'      => 'website',
        ));

        $pager = paginate($total, $posts_per_page, $page, page_base(cat_url($cat)));
        render('category', compact('cat', 'posts', 'pager', 'total', 'page'));
        break;
    }

    case 'page': {
        $pg = ctype_digit((string)$id)
            ? row('SELECT * FROM ' . moe_table('posts') . " WHERE type='page' AND id = ?", array((int)$id))
            : row('SELECT * FROM ' . moe_table('posts') . " WHERE type='page' AND slug = ?", array((string)$id));
        if (!$pg || $pg['status'] !== 'published') { moe_404(); }
        moe_page_view($pg);
        break;
    }

    case 'tag': {
        // 标签聚合页（1.8.3）：复用搜索模板展示同标签文章
        $tag = trim((string)$id);
        if ($tag === '') { moe_404(); }
        $like = '%' . addcslashes($tag, '%_\\') . '%';
        $total = (int)one('SELECT COUNT(*) FROM ' . moe_table('posts') . " WHERE type='post' AND status='published' AND tags LIKE ?", array($like));
        $offset = ($page - 1) * $posts_per_page;
        $posts = rows(
            'SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM ' . moe_table('posts') . ' p
             LEFT JOIN ' . moe_table('categories') . ' c ON c.id = p.category_id
             WHERE p.type=\'post\' AND p.status=\'published\' AND p.tags LIKE ?
             ORDER BY p.created_at DESC LIMIT ' . $offset . ', ' . $posts_per_page,
            array($like)
        );
        seo_set(array(
            'title'     => '标签：' . $tag,
            'desc'      => $site['name'] . ' 中标签为「' . $tag . '」的文章，共 ' . $total . ' 篇。',
            'keywords'  => $tag . ',' . setting('site_keywords', ''),
            'canonical' => post_tag_url($tag),
            'type'      => 'website',
        ));
        $pager = paginate($total, $posts_per_page, $page, page_base(post_tag_url($tag)));
        render('search', array('posts' => $posts, 'kw' => $tag, 'pager' => $pager, 'total' => $total));
        break;
    }

    case 'search': {
        $kw = $kw !== '' ? $kw : (string)$id;
        $posts = array(); $total = 0;
        if ($kw !== '') {
            $like = '%' . addcslashes($kw, '%_\\') . '%';
            $total = (int)one('SELECT COUNT(*) FROM ' . moe_table('posts') . " WHERE type='post' AND status='published' AND (title LIKE ? OR content LIKE ? OR excerpt LIKE ?)",
                array($like, $like, $like));
            $offset = ($page - 1) * $posts_per_page;
            $posts = rows('SELECT * FROM ' . moe_table('posts') . " WHERE type='post' AND status='published' AND (title LIKE ? OR content LIKE ? OR excerpt LIKE ?)
                ORDER BY created_at DESC LIMIT $offset, $posts_per_page", array($like, $like, $like));
        }
        seo_set(array(
            'title'     => '搜索：' . $kw,
            'desc'      => '站内搜索「' . $kw . '」的结果，共 ' . $total . ' 篇。',
            'keywords'  => $kw,
            'canonical' => search_url($kw),
            'is_search' => 1,
        ));
        $pager = paginate($total, $posts_per_page, $page, page_base(search_url($kw)));
        render('search', compact('posts', 'kw', 'pager', 'total'));
        break;
    }

    case 'archive': {
        // 归档时间线：全部已发布文章按年份分组（含 content 供字数统计）
        $items = rows('SELECT id, title, slug, type, category_id, url_num, content, created_at FROM ' . moe_table('posts')
            . " WHERE type='post' AND status='published' ORDER BY created_at DESC, id DESC");
        $groups = array();
        foreach ((array)$items as $it) {
            $groups[substr((string)$it['created_at'], 0, 4)][] = $it;
        }
        krsort($groups);
        $total = count($items);
        seo_set(array(
            'title'     => '归档',
            'desc'      => $site['name'] . ' 的全部文章归档，共 ' . $total . ' 篇。',
            'keywords'  => setting('site_keywords', ''),
            'canonical' => base_url() . 'archives',
            'type'      => 'website',
        ));
        render('archive', compact('groups', 'total'));
        break;
    }

    case 'links': {
        // 友链页：展示已通过审核的友链 + 申请入口
        try {
            $links = rows('SELECT * FROM ' . moe_table('links') . ' ORDER BY sort, id');
        } catch (Throwable $e) {
            $links = array();
        }
        $links = array_values(array_filter((array)$links, function ($l) {
            return !isset($l['status']) || $l['status'] === 'approved';
        }));
        seo_set(array(
            'title'     => '友情链接',
            'desc'      => $site['name'] . ' 收录的朋友站点链接，共 ' . count($links) . ' 个。',
            'keywords'  => '友情链接,' . setting('site_keywords', ''),
            'canonical' => base_url() . 'link',
            'type'      => 'website',
        ));
        render('link', compact('links'));
        break;
    }

    case 'companion': {
        // 看板娘小屋：AI 对话用量统计（不进页面缓存，数据实时）
        $cp_stats = function_exists('moe_ai_usage_stats') ? moe_ai_usage_stats() : array(
            'total' => array('req' => 0, 'in' => 0, 'out' => 0),
            'today' => array('req' => 0, 'in' => 0, 'out' => 0),
            'cost_total' => 0.0, 'cost_today' => 0.0, 'price_in' => 0.0, 'price_out' => 0.0,
        );
        $cp_name = '小星';
        // 2.2.13：名字统一走 moe_chat_name()（主题设置 mascot_name → ai_bot_name → 小星），与聊天窗、台词自称完全一致
        if (function_exists('moe_chat_name')) {
            $cp_name = moe_chat_name();
        } else {
            $n = trim((string)setting('mascot_name', ''));
            if ($n !== '') $cp_name = $n;
        }
        seo_set(array(
            'title'     => $cp_name . '的小屋',
            'desc'      => $site['name'] . ' 看板娘「' . $cp_name . '」的使用情况：陪聊次数与 AI 用量统计。',
            'keywords'  => setting('site_keywords', ''),
            'canonical' => base_url() . 'companion',
            'type'      => 'website',
        ));
        render('companion', array('cp_stats' => $cp_stats, 'cp_name' => $cp_name));
        break;
    }

    case 'article': {
        // 写作页：全部已发布文章列表 + 分类筛选
        $sel_cat = '';
        $cats = rows('SELECT c.*, (SELECT COUNT(*) FROM ' . moe_table('posts') . " p WHERE p.category_id = c.id AND p.type='post' AND p.status='published') AS count FROM " . moe_table('categories') . ' c ORDER BY c.sort, c.id');
        $total  = (int)one('SELECT COUNT(*) FROM ' . moe_table('posts') . " WHERE type='post' AND status='published'");
        $offset = ($page - 1) * $posts_per_page;
        $posts  = rows(
            'SELECT p.*, c.name AS cat_name, c.slug AS cat_slug FROM ' . moe_table('posts') . ' p
             LEFT JOIN ' . moe_table('categories') . ' c ON c.id = p.category_id
             WHERE p.type=\'post\' AND p.status=\'published\'
             ORDER BY p.is_top DESC, p.created_at DESC LIMIT ' . $offset . ', ' . $posts_per_page
        );
        seo_set(array(
            'title'     => '写作',
            'desc'      => $site['name'] . ' 的全部文章，共 ' . $total . ' 篇。',
            'keywords'  => setting('site_keywords', ''),
            'canonical' => base_url() . 'blog/article',
            'type'      => 'website',
        ));
        $pager = paginate($total, $posts_per_page, $page, page_base(base_url() . 'blog/article'));
        render('article', compact('posts', 'pager', 'total', 'page', 'cats', 'sel_cat'));
        break;
    }

    case 'life': {
        // 点滴时间线：指定分类（模板设置「点滴分类别名」）下的文章按月分组
        $life_slug = trim(theme_opt('life_slug', 'life'));
        $life_cat = $life_slug !== ''
            ? row('SELECT * FROM ' . moe_table('categories') . ' WHERE slug = ?', array($life_slug))
            : null;
        if (!$life_cat) { moe_404(); }

        $items = rows('SELECT p.*, (SELECT COUNT(*) FROM ' . moe_table('comments') . " cm WHERE cm.post_id = p.id AND cm.status='approved') AS comment_count FROM " . moe_table('posts') . " p WHERE p.category_id = ? AND p.type='post' AND p.status='published' ORDER BY p.created_at DESC, p.id DESC", array($life_cat['id']));
        $groups = array();
        foreach ((array)$items as $it) {
            $key = substr((string)$it['created_at'], 0, 7); // yyyy-MM
            $groups[$key][] = $it;
        }
        krsort($groups);
        $total = count($items);
        seo_set(array(
            'title'     => '点滴',
            'desc'      => $site['name'] . ' 的日常点滴记录，共 ' . $total . ' 条。',
            'keywords'  => '点滴,' . setting('site_keywords', ''),
            'canonical' => base_url() . 'life',
            'type'      => 'website',
        ));
        render('life', compact('groups', 'total', 'life_cat'));
        break;
    }

    case 'about': {
        // 关于页：模板设置「关于页内容」驱动
        seo_set(array(
            'title'     => '关于',
            'desc'      => '关于 ' . $site['name'] . '：' . ($site['desc'] !== '' ? $site['desc'] : $site['sub']),
            'keywords'  => '关于,' . setting('site_keywords', ''),
            'canonical' => base_url() . 'about',
            'type'      => 'website',
        ));
        render('about');
        break;
    }

    case 'rss': {
        header('Content-Type: application/rss+xml; charset=utf-8');
        $items = rows('SELECT * FROM ' . moe_table('posts') . " WHERE type='post' AND status='published' ORDER BY created_at DESC LIMIT 20");
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n";
        echo '<title>' . e($site['name']) . '</title><link>' . e(base_url()) . '</link><description>' . e($site['desc']) . '</description>' . "\n";
        echo '<language>zh-CN</language><lastBuildDate>' . date('r') . '</lastBuildDate>' . "\n";
        echo '<atom:link href="' . e(rss_url()) . '" rel="self" type="application/rss+xml"/>' . "\n";
        foreach ($items as $it) {
            echo '<item><title>' . e($it['title']) . '</title><link>' . e(post_url($it)) . '</link>'
               . '<guid isPermaLink="true">' . e(post_url($it)) . '</guid>'
               . '<description>' . e(post_excerpt($it, 300)) . '</description>'
               . '<pubDate>' . date('r', strtotime($it['created_at'])) . '</pubDate></item>' . "\n";
        }
        echo '</channel></rss>';
        break;
    }

    default:
        moe_404();
}

function moe_404()
{
    http_response_code(404);
    seo_set(array(
        'title'     => '页面不存在 404',
        'robots'    => 'noindex,follow',
        'canonical' => current_url(),
    ));
    render('404');
    exit;
}
