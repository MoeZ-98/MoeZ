<?php
/**
 * MoeZ - Moe个人主页程序 安装向导
 * 环境要求：PHP 7.2+ / MySQL 5.6+ / PDO
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('MOE_ROOT', __DIR__);
set_time_limit(0);
header('Content-Type: text/html; charset=utf-8');

$lockFile   = MOE_ROOT . '/install.lock';
$configFile = MOE_ROOT . '/config.php';
$installed  = is_file($configFile) && is_file($lockFile);
$step       = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors     = array();
$done       = false;

if ($installed && $step < 9) {
    $step = 9;
}

/* ---------- 环境检测 ---------- */
$checks = array(
    'PHP 版本 >= 7.2'            => version_compare(PHP_VERSION, '7.2.0', '>='),
    'PDO 扩展'                   => extension_loaded('pdo_mysql'),
    'mbstring 扩展'              => extension_loaded('mbstring'),
    'fileinfo 扩展'              => extension_loaded('fileinfo'),
    'config.php 可写'            => (is_file($configFile) && is_writable($configFile)) || is_writable(MOE_ROOT),
    'uploads/ 目录可写'          => is_dir(MOE_ROOT . '/uploads') && is_writable(MOE_ROOT . '/uploads'),
);
$passAll = !in_array(false, $checks, true);

/* ---------- 执行安装 ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $dbPref = trim($_POST['db_prefix'] ?? 'moe_');
    $admName = trim($_POST['adm_name'] ?? '');
    $admPass = (string)($_POST['adm_pass'] ?? '');
    $admMail = trim($_POST['adm_mail'] ?? '');
    $siteName = trim($_POST['site_name'] ?? 'MoeZ');

    if ($dbName === '') $errors[] = '请填写数据库名';
    if ($dbUser === '') $errors[] = '请填写数据库用户名';
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,20}$/', $dbPref)) $errors[] = '表前缀格式不正确（字母开头，仅允许字母数字下划线）';
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $admName)) $errors[] = '管理员用户名需为 3-32 位字母数字下划线';
    if (strlen($admPass) < 6) $errors[] = '管理员密码至少 6 位';

    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                $dbUser, $dbPass,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
            );
            $pdo->exec("SET NAMES utf8mb4");

            // 检测残留数据（上次安装中断/失败会留下旧表数据，导致重复键错误）
            $reinstall = !empty($_POST['reinstall']);
            $sql = moe_schema($dbPref);
            $hasData = false;
            try { $hasData = (bool)$pdo->query("SELECT COUNT(*) FROM {$dbPref}users")->fetchColumn(); }
            catch (PDOException $e) { /* 表尚不存在，首次安装 */ }

            if ($hasData && !$reinstall) {
                throw new RuntimeException('数据库中已存在 MoeZ 数据（可能是上次安装的残留）。'
                    . '如需重新安装，请返回并勾选「清空旧数据后重新安装」；'
                    . '或换一个管理员用户名 / 数据库后再试。');
            }
            if ($reinstall) {
                // 重装：先删旧表，保证使用最新表结构
                foreach (array('settings', 'users', 'categories', 'posts', 'comments', 'links') as $t) {
                    $pdo->exec("DROP TABLE IF EXISTS {$dbPref}{$t}");
                }
            }

            try { $pdo->exec($sql); }
            catch (PDOException $e) {
                // 某些环境下多语句执行受限，逐条执行
                foreach (preg_split('/;\s*[\r\n]+/', trim($sql)) as $one) {
                    $one = trim($one);
                    if ($one !== '') $pdo->exec($one);
                }
            }

            // 管理员
            $hash = password_hash($admPass, PASSWORD_DEFAULT);
            $st = $pdo->prepare("INSERT INTO {$dbPref}users (username,password,email,nickname,role,status,created_at) VALUES (?,?,?,?,?,?,NOW())");
            $st->execute(array($admName, $hash, $admMail, $admName, 'admin', 'active'));

            // 默认分类
            $pdo->exec("INSERT INTO {$dbPref}categories (name,slug,description,sort) VALUES ('碎碎念','essay','日常随笔与心情',1),('技术宅','tech','学习笔记与折腾记录',2),('二次元','acg','番剧·游戏·安利',3)");
            $pdo->exec("INSERT INTO {$dbPref}categories (name,slug,description,sort) VALUES ('生活','life','柴米油盐与诗和远方',4)");
            $cid = (int)$pdo->query("SELECT id FROM {$dbPref}categories ORDER BY id LIMIT 1")->fetchColumn();

            // 默认数据：欢迎文章使用 Markdown 编写，演示编辑器能力
            $welcome = <<<MD
欢迎来到 **MoeZ**（Moe 个人主页程序）！这是安装成功后自动生成的第一篇文章。

它现在内置了 **Markdown 编辑器**：简洁、快速、专注内容创作。左侧写作，右侧实时预览。

## 写作即排版

- **加粗**、*斜体*、~~删除线~~、`行内代码`
- 无序 / 有序 / 任务清单
- > 引用：把喜欢的话留在这里
- 表格、分割线、图片、链接都能直接写

- [x] 已完成的事项
- [ ] 待办事项

## 代码也能很漂亮

```php
echo 'Hello, Moe World!';
```

## SEO 已经准备好了

| 能力 | 说明 |
| --- | --- |
| 静态 URL | 两种链接样式：`/分类别名/1.html`、`/1.html` |
| 分类编号 | 每个分类独立编号，从 1 开始，自动分配 |
| TKD 自定义 | 站点与每篇文章独立的标题 / 关键词 / 描述 |
| 结构化数据 | 自动输出 canonical、Open Graph、JSON-LD |
| 站点地图 | 动态生成 sitemap.xml 与 robots.txt |

去后台 `admin/` 编辑或删除这篇文章，然后写下属于你的第一篇文字吧 ✨
MD;
            $about = <<<MD
这是 MoeZ 的默认「关于页面」，你可以在后台【独立页面】中编辑它。

## 关于我

写点什么好呢？自我介绍、联系方式、本站历程……都交给你啦。

- 昵称：
- 坐标：
- 正在做的事：
MD;
            $st = $pdo->prepare("INSERT INTO {$dbPref}posts (user_id,category_id,type,title,slug,excerpt,content,content_format,cover,status,is_top,seo_title,seo_keywords,seo_desc,views,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),NOW())");
            $st->execute(array(1, $cid, 'post', 'Hello, Moe World！', 'hello-moe-world',
                '欢迎来到 MoeZ，这是安装成功后自动生成的第一篇文章。', $welcome, 'markdown', '', 'published', 1,
                '', 'MoeZ,Markdown,个人博客', '欢迎来到 MoeZ，一个内置 Markdown 编辑器与完整 SEO 能力的个人博客程序。'));
            $st->execute(array(1, 0, 'page', '关于我', 'about', '关于本站与站长的二三事。', $about, 'markdown', '', 'published', 0,
                '', '', ''));
            // 文章编号：示例文章在默认分类里是第 1 篇
            $pdo->exec("UPDATE {$dbPref}posts SET url_num = 1 WHERE type='post'");

            $pdo->exec("INSERT INTO {$dbPref}links (name,url,logo,sort,created_at) VALUES ('MoeZ 官方','https://www.moezzz.com','',1,NOW())");
            $pdo->exec("INSERT INTO {$dbPref}comments (post_id,author,email,content,status,ip,created_at) VALUES (1,'小萌','moe@example.com','前排围观，博客好可爱！','approved','127.0.0.1',NOW())");

            $settings = array(
                'site_name'      => $siteName,
                'site_sub'       => '保持热爱，奔赴山海',
                'site_url'       => '',
                'site_keywords'  => '个人主页,博客,MoeZ',
                'site_desc'      => '一个可爱又实用的个人主页',
                'site_author'    => $admName,
                'site_avatar'    => 'templates/default/assets/avatar.svg',
                'site_signature' => '把日子过成诗 ✨',
                // 1.8.3：预置常用社交链接（安装后可在后台随意增删改）
                'social_links'   => json_encode(array(
                    array('name' => 'GitHub',   'url' => 'https://github.com/',              'icon' => '🐱'),
                    array('name' => '哔哩哔哩', 'url' => 'https://space.bilibili.com/',      'icon' => '📺'),
                    array('name' => '微博',     'url' => 'https://weibo.com/',               'icon' => '🔥'),
                    array('name' => '邮箱',     'url' => 'mailto:' . $admMail,               'icon' => '✉️'),
                ), JSON_UNESCAPED_UNICODE),
                'social_github'  => '', 'social_bilibili' => '', 'social_weibo' => '', 'social_twitter' => '',
                'social_mail'    => $admMail, 'social_rss' => '1',
                'footer_text'    => '© ' . date('Y') . ' ' . $siteName . ' · Powered by MoeZ',
                'posts_per_page' => '6',
                'comment_enabled'=> '1',
                'theme'          => 'moezzz',
                'site_status'    => 'open',
                // SEO
                'url_style'          => 'cat_num',
                'browser_title'      => '',
                'title_mode'         => 'browser',
                'seo_canonical'      => '1',
                'seo_og'             => '1',
                'seo_jsonld'         => '1',
                'seo_sitemap'        => '1',
                'seo_noindex_search' => '1',
                // 编辑器（固定 Markdown）
                'editor_default'     => 'markdown',
                'upload_max_mb'      => '50',
                // 1.9.0：页面缓存（访客静态化，默认开启）
                'page_cache'         => '1',
                'page_cache_ttl'     => '120',
                'redis_enabled'      => '0',
                'redis_host'         => '127.0.0.1',
                'redis_port'         => '6379',
                'redis_auth'         => '',
                'redis_db'           => '0',
                'db_version'         => '1.9.0',
            );
            $st = $pdo->prepare("REPLACE INTO {$dbPref}settings (skey,svalue) VALUES (?,?)");
            foreach ($settings as $k => $v) $st->execute(array($k, $v));

            // 写入 config.php
            $cfg = "<?php\n// MoeZ 配置文件（由安装器生成）\nreturn array(\n"
                 . "  'db' => array(\n"
                 . "    'host'    => " . var_export($dbHost, true) . ",\n"
                 . "    'port'    => " . var_export($dbPort, true) . ",\n"
                 . "    'name'    => " . var_export($dbName, true) . ",\n"
                 . "    'user'    => " . var_export($dbUser, true) . ",\n"
                 . "    'pass'    => " . var_export($dbPass, true) . ",\n"
                 . "    'prefix'  => " . var_export($dbPref, true) . ",\n"
                 . "  ),\n"
                 . "  'key' => " . var_export(bin2hex(random_bytes(16)), true) . ",\n"
                 . ");\n";
            if (@file_put_contents($configFile, $cfg) === false) $errors[] = '无法写入 config.php，请手动赋予该文件写权限';
            else {
                @file_put_contents($lockFile, 'installed ' . date('Y-m-d H:i:s'));
                $done = true;
            }
        } catch (Exception $e) {
            $errors[] = $e instanceof PDOException
                ? '数据库连接/操作失败：' . $e->getMessage()
                : $e->getMessage();
        }
    }
    if ($done) $step = 9;
    else $step = 2;
}

function moe_schema($p) {
    // 说明：created_at/updated_at 均带 DEFAULT CURRENT_TIMESTAMP（MySQL 5.6.5+ 支持），
    // 兼容严格模式（STRICT_TRANS_TABLES），未显式赋值时不再触发 1364 错误。
    return "
CREATE TABLE IF NOT EXISTS {$p}settings (
  skey VARCHAR(64) NOT NULL,
  svalue TEXT,
  PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {$p}users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(32) NOT NULL,
  password VARCHAR(255) NOT NULL,
  email VARCHAR(128) DEFAULT '',
  nickname VARCHAR(64) DEFAULT '',
  avatar VARCHAR(255) DEFAULT '',
  role VARCHAR(20) NOT NULL DEFAULT 'member',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  bio VARCHAR(255) NOT NULL DEFAULT '',
  qq VARCHAR(20) NOT NULL DEFAULT '',
  `site` VARCHAR(255) NOT NULL DEFAULT '',
  last_login DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY username (username),
  KEY role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {$p}categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  slug VARCHAR(64) NOT NULL,
  description VARCHAR(255) DEFAULT '',
  seo_title VARCHAR(200) DEFAULT '',
  seo_keywords VARCHAR(255) DEFAULT '',
  seo_desc VARCHAR(500) DEFAULT '',
  link VARCHAR(255) DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {$p}posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL DEFAULT 1,
  category_id INT UNSIGNED NOT NULL DEFAULT 0,
  type VARCHAR(20) NOT NULL DEFAULT 'post',
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(200) DEFAULT '',
  excerpt VARCHAR(500) DEFAULT '',
  content MEDIUMTEXT,
  content_format VARCHAR(10) NOT NULL DEFAULT 'markdown',
  cover VARCHAR(255) DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  views INT UNSIGNED NOT NULL DEFAULT 0,
  is_top TINYINT(1) NOT NULL DEFAULT 0,
  seo_title VARCHAR(200) DEFAULT '',
  seo_keywords VARCHAR(255) DEFAULT '',
  seo_desc VARCHAR(500) DEFAULT '',
  url_num INT UNSIGNED NOT NULL DEFAULT 0,
  draft_of INT UNSIGNED NOT NULL DEFAULT 0,
  tags VARCHAR(255) NOT NULL DEFAULT '',
  auto_draft TINYINT UNSIGNED NOT NULL DEFAULT 0,
  src VARCHAR(20) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY type_status (type, status),
  KEY category (category_id),
  KEY cat_num (category_id, url_num),
  KEY created (created_at),
  KEY type_status_created (type, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {$p}comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id INT UNSIGNED NOT NULL,
  author VARCHAR(64) NOT NULL,
  email VARCHAR(128) DEFAULT '',
  content TEXT,
  status VARCHAR(20) NOT NULL DEFAULT 'approved',
  ip VARCHAR(46) DEFAULT '',
  ua VARCHAR(255) DEFAULT '',
  location VARCHAR(150) DEFAULT '',
  user_id INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY post (post_id),
  KEY post_status (post_id, status),
  KEY user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {$p}links (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(64) NOT NULL,
  url VARCHAR(255) NOT NULL,
  logo VARCHAR(255) DEFAULT '',
  description VARCHAR(255) DEFAULT '',
  contact VARCHAR(128) DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'approved',
  check_result VARCHAR(255) DEFAULT '',
  last_check DATETIME NULL DEFAULT NULL,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {$p}spiders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  spider VARCHAR(30) NOT NULL DEFAULT '',
  path VARCHAR(255) NOT NULL DEFAULT '',
  ip VARCHAR(46) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY spider (spider),
  KEY created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS {$p}spider_check (
  ip VARCHAR(46) NOT NULL DEFAULT '',
  engine VARCHAR(30) NOT NULL DEFAULT '',
  state VARCHAR(10) NOT NULL DEFAULT 'unknown',
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (ip, engine),
  KEY checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MoeZ 安装向导</title>
<style>
:root{--p:#ff7eb3;--p2:#a18cd1;--text:#3a3547;--muted:#9b93ab;--err:#e0566b;--ok:#3fb984}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"PingFang SC","Microsoft YaHei",system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#fff1f4 0%,#f3f0ff 50%,#eef6ff 100%);color:var(--text);padding:30px 16px}
.wrap{width:100%;max-width:640px}
.card{background:rgba(255,255,255,.8);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,.9);
  border-radius:22px;padding:38px 36px;box-shadow:0 20px 60px rgba(161,140,209,.25)}
h1{font-size:26px;margin-bottom:6px;background:linear-gradient(135deg,var(--p),var(--p2));-webkit-background-clip:text;background-clip:text;color:transparent}
.sub{color:var(--muted);font-size:13px;margin-bottom:26px}
.steps{display:flex;gap:8px;margin-bottom:28px}
.steps i{flex:1;height:6px;border-radius:6px;background:#eadff2}
.steps i.on{background:linear-gradient(90deg,var(--p),var(--p2))}
h2{font-size:16px;margin:18px 0 10px}
.item{display:flex;justify-content:space-between;padding:9px 4px;font-size:14px;border-bottom:1px dashed #eee}
.item b{font-weight:600}
.ok{color:var(--ok)} .bad{color:var(--err)}
.f{margin-bottom:16px}
.f label{display:block;font-size:13px;color:var(--muted);margin-bottom:6px}
.f input{width:100%;padding:12px 14px;border:1.5px solid #e8e2f0;border-radius:12px;font-size:14px;background:#fff;outline:none;transition:.2s}
.f input:focus{border-color:var(--p2);box-shadow:0 0 0 3px rgba(161,140,209,.15)}
.f2{display:flex;gap:14px}.f2 .f{flex:1}
button{width:100%;padding:13px;border:0;border-radius:12px;color:#fff;font-size:15px;cursor:pointer;margin-top:10px;
  background:linear-gradient(135deg,var(--p),var(--p2));box-shadow:0 8px 20px rgba(255,126,179,.35);transition:.25s}
button:hover{transform:translateY(-2px);box-shadow:0 12px 26px rgba(255,126,179,.45)}
.err{background:#fff2f4;color:var(--err);border-radius:12px;padding:12px 14px;font-size:13px;margin-bottom:16px;line-height:1.8}
.done{text-align:center;padding:16px 0}
.done .big{font-size:52px}
.done p{color:var(--muted);font-size:14px;margin:10px 0 20px}
.links{display:flex;gap:12px}
.links a{flex:1;text-decoration:none;padding:12px;border-radius:12px;font-size:14px;transition:.2s;border:1.5px solid transparent}
.links a.main{background:linear-gradient(135deg,var(--p),var(--p2));color:#fff}
.links a.sub2{border-color:#e8e2f0;color:var(--text)}
.links a:hover{transform:translateY(-2px)}
.hint{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.7}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>🌸 MoeZ 安装向导</h1>
    <div class="sub">Moe 个人主页程序 · PHP 7.2+ · MySQL 5.6+</div>

    <div class="steps">
      <i class="<?php echo $step>=1?'on':''; ?>"></i><i class="<?php echo $step>=2?'on':''; ?>"></i><i class="<?php echo $step>=3?'on':''; ?>"></i>
    </div>

    <?php if ($step === 1): ?>
      <h2>环境检测</h2>
      <?php foreach ($checks as $name => $ok): ?>
        <div class="item"><span><?php echo htmlspecialchars($name); ?></span>
          <b class="<?php echo $ok?'ok':'bad'; ?>"><?php echo $ok?'通过 ✔':'未通过 ✘'; ?></b></div>
      <?php endforeach; ?>
      <div class="hint">若 config.php 不可写，请为项目根目录添加写权限后刷新本页再安装。</div>
      <?php if ($passAll): ?>
        <form method="get"><input type="hidden" name="step" value="2"><button type="submit">下一步 · 填写数据库信息</button></form>
      <?php else: ?>
        <button onclick="location.reload()" style="opacity:.9">刷新重新检测</button>
      <?php endif; ?>

    <?php elseif ($step === 2): ?>
      <?php if ($errors): ?><div class="err"><?php foreach($errors as $e2) echo '• '.htmlspecialchars($e2).'<br>'; ?></div><?php endif; ?>
      <form method="post" action="install.php?step=3">
        <h2>数据库信息</h2>
        <div class="f2">
          <div class="f"><label>数据库主机</label><input name="db_host" value="127.0.0.1"></div>
          <div class="f"><label>端口</label><input name="db_port" value="3306"></div>
        </div>
        <div class="f"><label>数据库名 *</label><input name="db_name" placeholder="例如 moez" required></div>
        <div class="f2">
          <div class="f"><label>数据库用户名 *</label><input name="db_user" value="root" required></div>
          <div class="f"><label>数据库密码</label><input name="db_pass" type="password"></div>
        </div>
        <div class="f"><label>表前缀</label><input name="db_prefix" value="moe_"></div>

        <h2>管理员账号</h2>
        <div class="f2">
          <div class="f"><label>用户名 *</label><input name="adm_name" placeholder="admin" required></div>
          <div class="f"><label>密码 *（至少6位）</label><input name="adm_pass" type="password" required></div>
        </div>
        <div class="f"><label>邮箱</label><input name="adm_mail" type="email"></div>

        <h2>站点信息</h2>
        <div class="f"><label>站点名称</label><input name="site_name" value="MoeZ"></div>
        <div class="f" style="background:#fdf5e7;border-radius:12px;padding:12px 14px;font-size:13px;color:#b47a24">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:inherit;margin:0">
            <input type="checkbox" name="reinstall" value="1" style="width:auto">
            清空旧数据后重新安装（若数据库里已有 MoeZ 残留数据请勾选）
          </label>
        </div>
        <button type="submit">🚀 开始安装</button>
      </form>

    <?php elseif ($step === 3): ?>
      <?php if ($errors): ?><div class="err"><?php foreach($errors as $e2) echo '• '.htmlspecialchars($e2).'<br>'; ?></div>
      <a href="install.php?step=2" style="color:#a18cd1">← 返回重新填写</a><?php endif; ?>

    <?php elseif ($step === 9): ?>
      <div class="done">
        <div class="big">🎉</div>
        <h2 style="margin-top:0">安装完成！</h2>
        <?php if (!$installed): ?>
        <p>你的小站已经准备就绪，去写下第一篇文章吧～</p>
        <div class="links">
          <a class="main" href="index.php">进入首页</a>
          <a class="sub2" href="admin/">登录后台</a>
        </div>
        <div class="hint">⚠️ 出于安全考虑，请立即删除服务器上的 install.php 文件。</div>
        <div class="hint" style="margin-top:10px">
          若首页提示 404，说明伪静态未生效：请在宝塔「网站 → 设置 → 伪静态」粘贴规则
          （后台「站点设置 → URL 样式」里有现成片段）。
        </div>
        <?php else: ?>
        <p>MoeZ 已经安装过了哦～</p>
        <div class="links">
          <a class="main" href="index.php">进入首页</a>
          <a class="sub2" href="admin/">登录后台</a>
        </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
