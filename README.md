# MoeZ · 部署与文件组织说明

> 本次为**工程层改造**（参照 emlog Pro 2.6.36 的部署与附件做法），代码注释里以「2.2.30 工程化」标注。
> **所有业务功能、数据库结构、路由、后台设置、前台模板均未改动。**
> `init.php` 的 `MOE_VERSION` 与 `version.json` 仍保持 `2.2.29` 未动 —— 改版本号会触发在线更新比对
> 与授权校验，属于功能行为变更；本次补丁只改工程实现，不参与版本升级链路。

---

## 一、环境要求

| 项目 | 要求 |
|---|---|
| PHP | 7.2 及以上，推荐 8.0+ |
| 必需扩展 | pdo_mysql、mbstring、gd、zip、**fileinfo**（真实 MIME 校验新增依赖） |
| MySQL | 5.6+ / MariaDB 10.3+，字符集 utf8mb4 |
| Web 服务器 | Apache（.htaccess 自动生效）/ Nginx / Caddy |
| 浏览器 | Chrome、Edge |

`fileinfo` 缺失时统一上传服务会自动降级（跳过 MIME 探测），不会导致上传失败，但建议开启。

---

## 二、目录结构（本次重点整理说明）

```
站点根目录/
├── admin/                  后台页面 + 后台上传 / UEditor 服务端
├── api/                    前台接口：投稿上传、评论图上传、UEditor 服务端、点赞等
├── assets/                 公共静态资源（编辑器、社交图标）
├── cache/                  页面缓存（禁止 Web 直接访问）
├── content/                运行时数据（插件 / 更新备份 / 授权与推送 / 充值订单）
│   ├── plugins/            插件
│   ├── backups/            在线更新 / 主题删除前的自动备份（旧快照已定期清理）
│   └── updates/            授权与推送记录
├── docs/                   部署与说明文档（6 个，含「目录导航.md」「附件命名说明.md」）
├── includes/               核心函数库 ★ 新增 upload.php（统一附件上传）、theme-assets.php（主题路径适配）
├── pay/                    支付回调（根目录有兼容桩）
├── templates/              前台主题 7 套（历史 zip 已移入归档区；7 套均已接入路径适配层）
├── tools/                  诊断工具 diag / selfcheck
├── update/                 ★ 功能目录：版本包投放，update-feed.php 会扫描，勿删
├── uploads/                附件根目录（Web 可访问，但禁止执行任何脚本）
│   ├── image/YYYYMM/       图片
│   ├── video/YYYYMM/       视频
│   ├── audio/YYYYMM/       音频
│   └── file/YYYYMM/        文档 / 压缩包
├── config.php              安装器生成的数据库配置（**含明文密码，勿公开分发**）
├── config.sample.php       ★ 新增：不含任何真实凭据的配置模板
├── init.php                引导、自动加载、自动升级
├── index.php               前端路由（伪静态统一入口）
├── install.php             安装器
├── .htaccess               Apache 伪静态 / 缓存 / 安全头
├── nginx.conf              ★ 新增：Nginx 站点配置
├── Caddyfile               ★ 新增：Caddy v2 配置
├── docker-compose.yml      ★ 新增：可选容器部署
├── auto_install.json       ★ 新增：面板一键部署元数据
├── SECURITY.md             ★ 新增：安全漏洞报告指引
└── version.json            版本信息
```

> **目录整理做了什么？** 详见 `docs/目录导航.md`。
> 简单说：清掉 30 个无引用的乱码重复副本，把开发自测 / 历史主题包 / 旧文档旧图标集中到
> `content/archive/`（在线更新打包会整体跳过 `content/`，也不会被 Web 访问），
> 一级目录从 13 个减到 12 个。
> **2.2.30 更新**：该归档目录已整体删除（约 3.5MB，复核确认 0 运行时引用；
> `tools/strip-upload-timestamps.php` 运行时会自动重建目录）。`content/backups/`
> 里的旧 `au-*` 更新快照也已清理，目录与 `.htaccess` 保留（删主题 / 更新仍会写入）。
>
> **为什么不把 templates/、uploads/、cache/ 收进 content/？**
> emlog 把模板 / 插件 / 上传 / 缓存统一放在 `content/` 下，确实更整齐；但本站全站代码、主题、
> 在线更新包、备份回滚都按现有路径硬编码，迁移目录会直接破坏功能。
> 同理 `pay/`、`tools/` 虽各只有 2～3 个文件，但被 `init.php`、后台面板和多处兼容桩引用，
> 合并需要同步改 8 处路径，收益小风险大，保持原位并在导航文档里按职责分组说明。

---

## 三、部署方式

### 1. 宝塔 / Apache（当前线上环境）

把整包文件上传到 Web 根目录，`.htaccess` 自动生效；浏览器访问域名会跳转 `install.php`。
根目录 `.htaccess` 已包含伪静态、静态资源缓存、`uploads/` 禁止执行 PHP、`cache/` 禁止访问等规则，
**本次未改动它**，避免影响现有站点。

### 2. Nginx（推荐补齐）

Nginx **不读取 `.htaccess`**，伪静态与上传目录防护必须显式写进配置。本次新增 `nginx.conf`：

1. 改 `server_name`、`root`、`fastcgi_pass`（宝塔常见 `unix:/tmp/php-cgi-74.sock`）；
2. `client_max_body_size` 与后台「设置 → 上传设置 → upload_max_mb」对齐，同时调整 `php.ini` 的
   `upload_max_filesize` / `post_max_size` / `max_execution_time`；
3. `nginx -t && nginx -s reload`；
4. 可选 `include docs/nginx-performance.conf;` 开启 gzip（**只放全局指令，不会与 location 冲突**）。

> 关键：`location ~ ^/uploads/.*\.php$` 必须排在通用 `location ~ \.php$` **之前**，
> Nginx 正则 location 按配置顺序取第一个匹配。

### 3. Caddy / Docker

- `Caddyfile`：改域名、`root`、`php_fastcgi` 地址后 reload。
- `docker-compose.yml`：`docker compose up -d` → 访问 `http://localhost:8080` 走安装器。
  容器用的 Nginx 配置在 `docs/nginx-docker.conf`。示例数据库密码**必须修改**。

---

## 四、附件上传规范（本次统一）

### 统一命名

```
uploads/{image|video|audio|file}/YYYYMM/{原文件名 或 000001}.ext

例：uploads/image/202611/封面图.webp
    uploads/image/202611/封面图_2.webp      ← 同名自动加序号
    uploads/image/202611/000001.webp        ← numeric 模式
```

- **不再叠加时间串**：`YYYYMM/` 分月目录已经承担了时间信息，文件名再带一遍
  `20261109_180055_15228377` 只会让后台资源管理里看不出是什么图；
- **默认保留原名**（净化后），重名自动追加 `_2` / `_3`，并发上传也不会覆盖；
- `MOE_UP_NAME_MODE = 'numeric'` 可切换为 `000001.ext` 纯数字递增，适合中文名易乱码的服务器；
- 历史「时间串_随机_原名」文件由 `admin/resources.php` 的 `moe_res_display_name()` 正则还原显示名；
- 与 `includes/functions.php` 的 `moe_extract_upload_paths()` 引用提取正则
  `uploads/(image|video|audio|file)/\d{6}/` 保持一致，**历史附件、文章引用、删除时引用计数全部不受影响**。

改造前 5 个入口各写一套命名：后台与 UEditor 用「原名主干 + 可选随机尾缀」、投稿用 `ct{uid}_原名`、
评论图用 `时间串_随机_cmt`（丢原名），规则不一且中文名容易在跨系统解压时产生乱码副本。
现在全部收敛到 `includes/upload.php` 的 `moe_up_store()`。

> 投稿 / 评论的来源标记不再塞进文件名：全站无任何代码读取这些前缀，归属本就记录在数据库里。
> 完整规则、两种模式与存量处理办法见 `docs/附件命名说明.md`。

### 校验顺序（各入口原有规则不变，只做叠加）

| 层 | 位置 | 内容 |
|---|---|---|
| 鉴权 / CSRF / 限频 | 各业务入口 | **原样保留** |
| 扩展名白名单、大小上限 | 各业务入口 | **原样保留** |
| `getimagesize()` 图片内容校验 | 各业务入口 | **原样保留** |
| ★ 真实 MIME 探测（finfo） | `includes/upload.php` | 新增：拦截 HTML/PHP/脚本伪装、图片类非 image/ 内容 |
| ★ SVG 脚本扫描 | `includes/upload.php` | 新增：拦截 `<script>`、`on*=`、`javascript:`、外部实体（白名单仍保留 svg，不改动可上传类型） |
| ★ 文件名净化 + 命名 + 落盘 | `includes/upload.php` | 新增：去控制字符 / 路径字符 / 连续点、限长 40、0644 权限 |

### 可选开关

`includes/upload.php` 顶部有两个开关：

| 开关 | 默认 | 作用 |
|---|---|---|
| `MOE_UP_NAME_MODE` | `'origin'` | 改 `'numeric'` 后文件名变成 `000001.ext` 递增，彻底避开中文名编码问题 |
| `MOE_UP_ASCII_ONLY` | `false` | 改 `true` 后原名段退化为纯 ASCII（中文被剔除成 `file`，重名自动加序号） |

两种模式的原名信息仍通过 `display` / `stem` 返回给编辑器，前台显示不受影响。

### 存量附件与中文编码

`uploads/` 下历史存在的乱码副本是「同一中文原名在不同编码下被多次落盘」的遗留数据，
**本次一个都没有改名或删除**（可能仍被文章引用）。建议：

- 在 Linux / UTF-8 环境解压整包；Windows 下用支持 UTF-8 标志的解压工具（如 7-Zip、Bandizip）；
- 想把历史文件名也去掉时间串，用 `tools/strip-upload-timestamps.php`（命令行、默认只预览），
  它会输出旧名→新名映射表和 Apache / Nginx 301 兼容规则，详见 `docs/附件命名说明.md`；
- 清理历史重复文件请用后台「资源管理」，它会做引用计数，不会误删被引用的图片。

---

## 五、与 emlog Pro 2.6.36 的对照 · 可借鉴清单

### 本次已落地

| # | 借鉴点 | emlog 做法 | 本站落地 |
|---|---|---|---|
| 1 | 单一上传入口 | `common.php::upload()` | 新增 `includes/upload.php`，5 个入口共用命名与落盘 |
| 2 | 统一命名 + 按月分目录 | `md5(原名)前4位 + time()`、`content/uploadfile/YYYYMM/` | `uploads/类型/YYYYMM/` 分月目录 + 可读原名（重名加序号），文件名不再叠时间戳；可切纯数字名 |
| 3 | 真实 MIME 探测 | `get_mimetype()` 用 `finfo_file` | `moe_up_real_mime()`，并拦截 HTML/PHP/脚本类内容 |
| 4 | 校验集中、文案明确 | `Media::checkUpload()` | `moe_up_check()` 统一返回错误文案 |
| 5 | 多环境部署配置 | nginx.conf / Caddyfile / docker-compose.yml / README / robots.txt / SECURITY.md / auto_install.json | 全部补齐（robots.txt 由 `index.php` 动态生成，故**不放静态文件**，避免覆盖后台自定义内容） |
| 6 | 配置模板与安全披露 | `config.php` 由安装器生成、`SECURITY.md` | 新增 `config.sample.php`（无真实凭据）、`SECURITY.md` |
| 7 | 面板一键部署元数据 | `auto_install.json` | 新增，声明必需扩展与需可写目录 |
| 8 | 上传目录禁执行（Nginx 侧） | nginx.conf 显式规则 | 新增 nginx/Caddy 规则，补齐「Nginx 不读 .htaccess」的缺口 |

### 建议后续再做（本次未动，因为会改变功能或数据结构）

| # | 建议 | 说明 |
|---|---|---|
| A | **附件落库** | emlog 用 `media_model` / `mediasort_model` 记录原名、MIME、宽高、大小、上传者、时间与媒体分类；本站媒体库是**扫目录**，无附件表。落库后可支持媒体分类、搜索、按上传者筛选，但需要建表 + 迁移，属于功能变更。 |
| B | **缩略图与图片宽高** | emlog 上传时生成 `thum-` 缩略图并记录 width/height；本站前台已用原生懒加载，接入可省流量，但要改模板渲染。 |
| C | **附件下载鉴权** | emlog 有 `download_controller.php` 做下载计数 / 权限；本站积分商城已有自己的下载代理，暂不需要照搬。 |
| D | **统一错误码** | emlog 上传失败返回固定码（如 105 = 目录不可写）；本站各入口文案更友好但结构不一，可后续统一成 `{code,msg}`。 |
| E | **前台视觉 / SEO / GEO / 安全审计** | 站点已有 `includes/seo.php`、动态 sitemap/robots、结构化数据；可做一次带评分的体检与主题视觉升级。本轮 7 套主题**只做了路径适配**（图标、跨主题引用、附件判断），外观 / 布局 / 文案未改。 |

### 不建议照搬

- **把模板/插件/上传全塞进 `content/`**：emlog 的目录更整齐，但本站路径被主题、在线更新、备份回滚硬编码，迁移必然破坏功能。
- **静态 `robots.txt`**：emlog 用静态文件；本站由 `index.php` 按后台设置动态生成，加静态文件会被 `.htaccess` 的 `-f` 规则优先命中，反而让后台设置失效。
- **文件名完全丢弃原名**（emlog 用 `md5+time`）：本站媒体库靠文件名还原显示名，丢原名会让后台资源管理全部变成一串数字，体验倒退。

---

## 六、安全提示

1. `config.php` 含**明文数据库账号密码**，整包 zip / 备份请勿公开分发或提交到公开仓库；
   对外提供程序包时用 `config.sample.php` 顶替，并清理 `content/backups/`、`content/updates/`
   里的运行数据。
2. `update/` **不要加入 deny 规则**：`update-feed.php` 生成的 `download` 指向 `/update/{包名}.zip`，
   挡掉会导致版本包无法下载。本轮已从 nginx / Caddy 的 deny 列表里移除它。
3. 上传目录必须做到：目录可写、**其中任何脚本不可执行**。Apache 已由 `uploads/.htaccess` 保证，
   Nginx / Caddy 请用本次新增的配置。
4. `uploads/`、`cache/`、`content/`、`templates/` 需 PHP 运行用户可写（在线更新与上传用），
   其余文件建议 0644 / 目录 0755。
5. 如使用在线更新，注意它按**文件路径覆盖**，本次新增的文件不会被自动删除，但更新包若包含同名
   `admin/upload.php` 等文件会把补丁覆盖掉，更新后请重新核对统一命名是否仍生效。
6. 2.2.30 新增三处目录级 Web 拒绝规则：`content/updates/`（授权码 / 授权站点档案）、
   `content/recharge/`（订单数据）、`tools/`（诊断与自检脚本）；根目录 `_ping_tmp.php` /
   `diag.php` / `selfcheck.php` 兼容桩也一并禁止 Web 访问。这些文件只由 PHP 在服务端读写，
   封禁不影响任何前台 / 后台功能。Nginx / Caddy 已在随包配置里同步。

---

## 七、升级后自检

1. 后台「资源管理」能看到新上传文件，且显示的是**原始文件名**（不是一长串时间串）；
2. 后台编辑器上传一张图 → 文章能正常显示，URL 形如 `/uploads/image/202611/封面图.webp`；
3. 用户中心投稿上传、评论插图各试一次；
4. 访问 `/uploads/` 不列目录；往 `uploads/` 放一个 `test.php` 应返回 403（Nginx 环境）；
5. 后台「设置 → 上传设置 → upload_max_mb」改大后，同步调整 `php.ini` 与 nginx 的 `client_max_body_size`；
6. 访问 `/content/updates/user-auth-codes.json`、`/content/recharge/orders.json`、`/tools/diag.php`、
   `/diag.php` 应返回 403；同时确认 `/update/{包名}.zip` 在线更新包仍可下载；
7. 首页 / 分类页的评论数、后台「评论列表」四个计数、「写文章」分类编号预览与改前一致
   （本轮合并查询的等价性自检）。

---

## 九、程序更名与「萌Z 版娘」插件

### 9.1 名称

程序名统一为 **MoeZ**（中文 **萌Z**）。可见文案、后台标题、页脚、安装向导、开发文档与代码注释均已同步。

**有意保留的协议标识**（改了会让在线更新 / 授权失效，故不动）：

| 保留写法 | 出现位置 | 原因 |
|---|---|---|
| `MoeZ/` | `includes/auth.php`、`system.php`、`mail.php`、`linkcheck.php`、`points.php` | 授权请求 / 版本检测的 User-Agent 与 `X-Mailer`，服务端按此识别客户端 |
| `moe-home-*.zip` | `update-feed.php`、`update/README.md` | 在线更新按「文件名含 moe-home」识别版本包，换名会导致发版识别不到 |
| `Moe_Home…` / `萌站` | 主题 `moezzz` 名称、历史附件文件名 | 属于主题名与既有附件路径，不是程序品牌名 |

### 9.2 版娘插件

看板娘原先整块埋在 `templates/fomalhaut/`，本轮迁到 `content/plugins/moez-companion/`：

```
content/plugins/moez-companion/
├── plugin.json / plugin.php   元信息、设置读取、资产代理、保存 API、钩子注册
├── front.php                  前台 DOM（从 fomalhaut/footer.php 迁出）
├── README.md                  启用与回滚说明
└── assets/
    ├── companion.css / companion.js   版娘样式与聊天逻辑（从 fomalhaut 迁出）
    ├── voice-core.js                  前后台共用的男声 / 女声枚举与试听
    ├── debug.js / admin.js            前台调试浮层 / 后台调试台
    ├── admin.css                      新增 UI 样式
    └── companion.png                  默认立绘（从 fomalhaut 复制）
```

**首次部署自动启用**：`includes/plugins.php` 会在第一次请求时把插件写入 `plugins_enabled`
（一次性 marker `moez_cp_autoinstall`，站长在「插件管理」停用后不会再被自动打开）。
启用后 7 套主题都能显示版娘；fomalhaut 自带挂载块会自动让出
（`footer.php` 里一行 `function_exists` 判断），主题 `app.js` 找不到 `#fo-companion`
也会直接返回，不会同页出现两套。

设置优先级：后台调试台 `moez_cp_*` → 当前主题 `themeopt_{主题}_*` → fomalhaut 历史
`themeopt_fomalhaut_*` → 内置默认（短键与长键按别名映射，如 `name` → `mascot_name`、
`style` → `voice_style`、`enabled` → `show_mascot`），迁移前的配置不丢。
所有 CSS / JS / 立绘走 `?moez_cp_asset=` 代理，不依赖 Web 服务器是否放行 `content/` 目录。

### 9.3 男女语音与调试

- 语音改用 `voice-core.js` 按性别筛选系统语音：男声优先 云希 / 云健 / 康康 等，女声优先 晓晓 / 晓伊 / 瑶瑶 等；
  原先「识别到男声扣 20 分」的逻辑已移除。四档音色（御姐 / 少女 / 原声 / 病娇）与朗读队列、手势解锁、保活全部保留。
- **前台**：聊天窗头部新增 `♀ / ♂` 一键切换；底部「🎙 语音调试」可列出系统语音、逐个试听、调语速音调、查看引擎诊断。
  网址带 `?voice_debug=1` 会自动展开。
- **后台**：「站点设置 → 语音调试」菜单项与全后台右下角的 🎙 调试台，可试听并「保存为站点默认」；
  保存接口仅站长可用并校验 CSRF。
- **AI 人设与聊天台词未改**（仍是原来的女孩子设定），本次只改朗读音色。

> 浏览器内置 `speechSynthesis`，不调用任何外部 TTS、不产生费用。
> 系统没装中文语音包 / 微信等无语音引擎的浏览器可能没有任何声音，调试台的「引擎诊断」会显示原因。

**每套主题可单独配置**：后台 →「模板管理 → 模板设置 → 版娘与语音」（7 套主题都有该页签），
可调显示开关、版娘名字 / 立绘 / 位置 / 范围、语音性别、音色、语速音调、朗读与前台调试开关。
优先级：后台「🎙 语音调试」保存的站点默认 > 当前主题设置 > fomalhaut 历史设置 > 内置默认值。
在主题设置里关掉版娘时，fomalhaut 也不会再回退输出自带版娘（兼容判断只看插件启用列表）。
> 注意：插件启用会按 CMS 既有逻辑关闭前台页面缓存（`moe_plugins_active()`），
> 若站点很依赖页面缓存，可在「插件管理」停用插件换回静态缓存。

## 十、主题 / 插件升级注意事项

本包对 7 套主题做了三类**写在主题文件里**的改动（第三轮路径适配、第十轮版娘设置、
2.2.30 性能优化如 `post_comment_count()` 合并查询），对版娘插件保持稳定接口
（`plugin.json` 格式、hooks、`moez_cp_*` 设置键均未改动）。

### 升级主题时

1. **不要整目录覆盖升级主题**：若用原厂主题包覆盖 `templates/xxx/`，该主题的
   路径适配、版娘 16 项设置、性能补丁会一起丢失（功能不受影响，但会退回未适配状态）。
2. 如确需覆盖升级，升级后对照 `改动清单.md` 第七 / 八 / 十节逐项核对：
   `info.php` 的 `settings` 是否含「版娘与语音」16 项、图标路径是否走
   `includes/theme-assets.php`、文章列表评论数是否用 `post_comment_count()`。
3. 推荐做法：以本包主题为准；原厂更新只做「对照合并」，不做整目录替换。

### 升级插件时

- 第三方插件照常升级即可；本包性能 / 安全加固不涉及插件 API、hooks 与 `plugin.json` 格式。
- 「萌Z 版娘」插件为独立插件，可直接通过「插件管理」停用 / 启用，不影响主题更新；
  其设置键（`moez_cp_*` 与主题 `themeopt_*` 别名）是稳定接口，主题依赖它判断启用状态
  （`moez_cp_plugin_installed()`），卸载插件后主题需确认 `function_exists()` 兜底是否生效。

### 与在线更新的关系

- 站点自身的在线更新（`/update/*.zip`、`update-feed.php`）未做任何改动；
  上述注意事项只针对「手动替换主题 / 插件目录」的场景。
