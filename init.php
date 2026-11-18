<?php
/**
 * MoeZ - Moe 个人主页程序
 * 引导文件：加载配置、函数库、数据库与会话
 * PHP 7.2+ / MySQL 5.6+
 */
define('MOE_ROOT', __DIR__);
define('MOE_VERSION', '2.2.29');
// 2.2.29：Redis「连接失败」误报第二阶段修复 —— 连接尝试本身也支持强制重置：
//         保存设置的请求里，页面加载阶段（Redis 尚未启用）已消耗掉 static 缓存的
//         唯一一次连接尝试，保存后的状态检测直接拿到 null 旧结果导致误报；现
//         moe_cache_client($fresh) / moe_cache_status($fresh) 支持强制重置，
//         保存后用「刚保存的新配置 + 全新连接」实测，如实给出连接成功/失败结论。
// 2.2.28：修复「保存设置后立即误报 Redis 连接失败」—— moe_cache_cfg 的配置缓存
//         从函数 static 改存 $GLOBALS（可在同请求内作废）；setting_set 保存 redis_*
//         配置时同步 unset，保存后的连接检测拿到的是新配置；连接失败时还会用强制
//         刷新的新配置重试一次（覆盖改密码后文件缓存旧值的窗口期）。
// 2.2.27：缓存策略调整（站长拍板「拒绝文件兜底」）—— 数据缓存只走 Redis：
//         Redis 启用且连接正常 → 走 Redis；未启用 / 扩展缺失 / 连接失败 →
//         一律不缓存、直接查库，绝不再落文件兜底。文件缓存仅保留内部配置读取
//         与独立开关的「页面缓存」（cache/pc）。设置页文案同步更新。
// 2.2.26：后台仪表盘「🎟 授权发放」卡整体移除（含初始化按钮与其 JS）——
//         授权码签发/管理统一在用户中心「🔑 我的授权码」页；update-api.php 的 hub
//         接口与后端 hub_init 逻辑保留，已初始化的授权站不受影响。
// 2.2.25：在线更新「文件不可写」修复 —— 整包 / 授权增量 / 主题插件在线更新覆盖旧文件前
//         先尝试 chmod 自修复（文件 0644、目录 0755）再复检，宝塔下文件属主非 PHP 运行用户
//         （root 解压 / FTP 上传所致）不再直接中止；仍不可写时报错文案附带手工修权限指引。
// 2.2.24：版娘移动端语音修复（iOS 手势同步启动 / 跳过有害的 pause-resume 保活 / 预热句改真实汉字小音量 /
//         微信等无语音引擎浏览器进面板时明示一次）；仪表盘「授权与主题更新」「授权发放」两张卡在授权站本站隐藏
//         （客户站保留验证入口，签发统一走用户中心「我的授权码」）；「保存并验证」增加加载态与醒目结果提示，
//         授权码验证兼容早期版本签发的更长签名段。
// 2.2.23：主题 / 插件上传修复 —— zip 内 GBK 中文文件名自动转 UTF-8（不再乱码落盘），
//         主题 / 插件目录名支持中文（直接用主题 / 插件名称创建目录），上传时自动清理历史遗留的 stage 临时目录；
//         后台编辑器移除「📎 附件」媒体库按钮（封面处仍可上传），恢复「预览 / 分栏」实时预览；
//         后台编辑器新增「上架资源」卡片：勾选即把文章挂到积分商城，封面自动取文章封面，双币定价 / 下载地址一步到位；
//         已兑换资源点击「下载」：网盘直链文件改为服务器代理 + attachment 头，浏览器直接弹保存框自动下载（失败自动回退跳转）。
// 2.2.22：用户中心全面升级 —— 二级导航（我的主页 / 内容 / 创作 / 商城 / 账号）、新增「投稿作品」与「友链申请」；
//         投稿编辑器跟随后台设置（UEditorPlus / Markdown），可上传封面与附件、可同时上架资源（双币定价），
//         投稿以 pending 状态入库（前台不渲染），站长在「文章管理」通过后自动发布 + 上架资源，并通过邮件通知作者（投稿成功 / 已通过 / 已退回）；
//         用户中心自动读取当前主题 :root 主色与底色，生成配套的 --uc-* 配色，换主题即换风格；
//         积分商城支持「授权码」类商品：购买时填写绑定域名，付款成功自动签发永久授权码并写入授权站点档案，同时引导下载程序；
//         后台编辑器移除「📚 上传媒体」「预览 / 分栏」按钮，新增 UEditorPlus 富文本编辑器（站点设置 → 功能设置可切换）。
// 2.2.21：仪表盘新增「⚙ 更新源」设置入口（此前只有保存接口没有界面，导致一直显示"未配置在线更新源"）；填写指向官方 version.json 的直链即可在线检测新版，留空清除恢复离线核对；保存成功立即按新源重新检测。
// 2.2.13：编辑器「自动生成标签」接入大模型（后台 AI 就绪时优先 AI 提炼语义标签，未配置/失败自动回退原关键词提取）；
//         后台「AI 助手」新增运行状态监控卡（配置状态 / 最近连通检测 / 今日配额 / 累计 tokens 与花费），测试连接结果落盘可查；
//         看板娘小屋统计页下沉为核心页（templates/default/companion.php），任意主题未适配也能用，前台监控不再依赖主题挂载；
//         修复看板娘「语音与名称不匹配」：本地引擎台词自称统一跟随后台设置的名字（moe_chat_name），新增日常闲聊与情绪陪伴话术（难过/累/失眠/无聊/讲笑话/加油等），AI 关闭也能陪聊；
//         程序根目录文件分类整理：前台接口迁入 api/（chat/comment/comment_upload/like/link/download），支付回调迁入 pay/，诊断工具迁入 tools/，原地址保留兼容桩，旧 URL 与主题模板零破坏。
// 2.1.16：看板娘升级为立体仿真人（半写实 3D），固定在视口左下/右下角（页面滚动不跟随）；新增站内聊天（chat.php + includes/chat.php，最新/热门/分类/搜索等本地问答，CSRF + 频率限制）。
// 2.1.14：全站正文基准字号 16px；加载优化（封面原生懒加载、Hero 图 preload、静态资源长缓存、移动端取消 fixed 背景）；文章图片灯箱。
// 2.1.4：修复首页部分文章不显示——精选大卡文章改为从首页列表查询中整体排除（旧方案窗口截取+偏移在随机/热门/自定义模式下会跳过文章），每页卡片数严格=后台篇数、翻页不漏不重。
// 2.1.3：首页三大区块（精选大卡/索引区/文章卡片）完全独立：大卡单独查询不占列表数量、索引区数量可自定义、文章卡片数量固定跟随后台「每页文章数」；模板设置删除已迁移的旧积分项并新增搜索与分组图标。
// 2.1.2：首页文章数量按后台「每页文章数」显示（大卡不再占用一篇）；评论 IP 归属地与设备信息改为图标徽标；
//        新增文章点赞（like.php，Cookie 防重复可取消）；会员可在用户中心编辑自己的评论（后台可开关）；页脚友链随机展示。
// 2.1.1：新增签到排行榜 —— 用户中心「签到积分」页可切本周/本月/总榜，Fomalhaut 侧栏新增排行榜卡片
//        （模板设置可开关、选周期与人数）；纯查询逻辑，无数据库变更。
// 2.1.0：积分 / 签到 / 积分商城 / 下载中心移入核心（includes/points.php），任何模板都可用；
//        用户中心新增「签到积分 / 积分商城 / 我的下载」三个页签；评论、签到自动得分，积分兑换后下载；
//        新增 /shop 与 /download/{id} 路由、后台「积分商城」管理页。表结构自动创建，无破坏性变更。
// 2.0.4：编辑器附件上传改为资源媒体库弹窗（上传/搜索/插入/设封面/删除）+ 修复附件类型限制；无数据库变更。
// 2.0.3：仪表盘精简（删四组卡片及对应 SQL）、进页面自动检测更新、一键在线更新 / 上传包更新（自动备份 + 失败回滚）；无数据库变更。
// 2.0.2：用户中心胶囊页签、后台第二种布局、插件系统、仪表盘软件信息与版本检测；无数据库变更。
// 2.0.1：站点设置精简，个人资料 / 社交与展示 / 账户安全 统一并入用户中心；无数据库变更。
// 2.0.0 用户体系不提高 DB_VERSION：字段由 moe_ensure_schema() 自愈补齐，
// 账号角色迁移由 moe_users_upgrade() 独立完成，避免重跑历史数据迁移脚本。
define('MOE_DB_VERSION', '1.9.0');

// 未安装则跳转到安装器
if (!is_file(MOE_ROOT . '/config.php')) {
    if (is_file(MOE_ROOT . '/install.php')) {
        header('Location: install.php');
        exit;
    }
    die('缺少 config.php，请先运行 install.php 安装。');
}

$GLOBALS['moe_config'] = require MOE_ROOT . '/config.php';

// 核心文件加载：逐个检查，缺失时给出可读提示（避免白屏 Fatal）
$__moe_incs = array('functions', 'markdown', 'seo', 'ipinfo', 'linkcheck', 'spider', 'db', 'cache', 'mail', 'upgrade', 'points', 'recharge', 'user', 'plugins', 'system', 'auth', 'chat', 'ai', 'chat_ai',
    // 2.2.30 工程化：主题资源路径适配层（目录整理后统一处理图标 / 跨主题资源 / 附件路径）
    'theme-assets');
foreach ($__moe_incs as $__moe_inc) {
    $__moe_file = MOE_ROOT . '/includes/' . $__moe_inc . '.php';
    if (!is_file($__moe_file)) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        die('程序文件缺失：<b>includes/' . $__moe_inc . '.php</b><br>'
            . '请重新上传完整的 MoeZ 程序包，确认 <b>init.php</b> 与 <b>includes/</b> 目录都已覆盖。');
    }
    require $__moe_file;
}
unset($__moe_incs, $__moe_inc, $__moe_file);

// 老站点自动升级（表结构 + 新增设置项）
moe_upgrade();

// 表结构自愈：即使升级因版本号门槛或异常被跳过，也在这里补齐缺失字段（失败会下次重试）
if (function_exists('moe_ensure_schema')) moe_ensure_schema();

// 2.0.0：用户体系一次性数据迁移（老站点唯一账号 → 站长；幂等，成功只跑一次）
if (function_exists('moe_users_upgrade')) moe_users_upgrade();

if (session_status() === PHP_SESSION_NONE) {
    session_name('MOESESSID');
    // 2.2.30 安全加固：HttpOnly 阻止脚本读取会话 Cookie；HTTPS 下加 Secure。
    // 刻意不加 SameSite：第三方支付回调可能是跨站 POST，加 Lax 会让回调不带会话。
    // PHP 7.3+ 用数组参数，7.2 用位置参数（本项目兼容 PHP 7.2+）。
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => function_exists('is_ssl') && is_ssl(),
        ));
    } else {
        session_set_cookie_params(0, '/', '', function_exists('is_ssl') && is_ssl(), true);
    }
    session_start();
}

// 1.9.0：输出压缩 —— 服务器（Nginx/Apache）未开启 gzip 时由 PHP 兜底，
// HTML/CSS/JS 体积通常能压到 1/4，弱网下首屏明显更快。
// 二进制输出（验证码图片）不做压缩，避免无谓的 CPU 开销。
$__moe_script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$__moe_binary = (strpos($__moe_script, 'captcha.php') !== false);
if (!$__moe_binary && !headers_sent() && !ini_get('zlib.output_compression') && function_exists('ob_gzhandler')) {
    $__moe_ae = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
    if (strpos($__moe_ae, 'gzip') !== false) { @ob_start('ob_gzhandler'); }
    unset($__moe_ae);
}
unset($__moe_script, $__moe_binary);

// 2.0.2：加载已启用插件（会话已就绪，插件里可安全使用 current_user / setting 等）
if (function_exists('moe_plugins_load')) moe_plugins_load();

// 时区
if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set('Asia/Shanghai');
}
