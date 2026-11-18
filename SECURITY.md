# 安全漏洞报告指引

参照 emlog Pro 2.6.36 的 `SECURITY.md` 补齐。感谢你负责任地披露安全问题。

## 请不要这样做

- 不要在公开 Issue、论坛、评论区或社交媒体上直接贴出漏洞细节与复现步骤；
- 不要在生产站点上做破坏性验证（写入 Webshell、删数据、批量刷接口）。

## 建议这样报告

请通过站点站长的私下联系方式（后台「关于 / 联系方式」或邮件）提交，并尽量包含：

1. 受影响版本（见根目录 `version.json`）；
2. 漏洞类型与影响（如：上传绕过、存储型 XSS、越权、SQL 注入）；
3. 最小复现步骤与必要的请求样例（**请隐去真实数据库密码、Cookie、Token**）；
4. 你建议的修复方向（可选）。

## 处理约定

- 确认后会在下个版本修复，并在 `version.json` 的更新说明中简述；
- 修复发布前不公开细节，避免被大规模利用；
- 若你希望匿名，请在报告中注明。

## 部署侧自查清单

- `config.php` 不可通过 Web 访问（Apache 见根 `.htaccess`，Nginx / Caddy 见新增配置）；
- `uploads/` 下任何 `.php/.phtml/.cgi/...` 必须返回 403；
- `cache/`、`includes/`、`content/backups/`、`content/archive/`、`docs/` 不可直接访问；
  `update/` 是版本包投放目录、需要可下载（不要整体 deny），但应禁止执行其中的 PHP；
  原 `tests/` 已归档到 `content/archive/dev-tests/`，根目录不再有该目录；
- PHP 开启 `fileinfo` 扩展，让上传走真实 MIME 校验；
- `php.ini` 的 `upload_max_filesize` / `post_max_size` 与后台上传上限、nginx `client_max_body_size` 三者一致；
- 整包备份不要公开分发（含明文数据库凭据与运行数据）。

## 版娘插件的接口安全

`content/plugins/moez-companion/plugin.php` 顶部有 `MOE_ROOT` 守卫，被 Web 直接请求时返回 403。
插件暴露两个入口：

- `?moez_cp_asset=...`：白名单静态资产代理（7 个固定文件），无写操作；
- `?moez_cp_api=save`：**仅站长**（`is_admin()` + `moe_is_owner()`）+ `X-CSRF-TOKEN` 校验，
  只接收 gender / style / rate / pitch / voice_uri / debug_front 六个白名单字段并做取值校验。

前台访客可以试听与临时切换音色，但无法保存站点默认。

### 自动启用写入 settings 表
`includes/plugins.php` 首次请求会写入两个设置键：`moez_cp_autoinstall=1` 与
`plugins_enabled`（追加 `moez-companion`）。写入走站内 `setting_set()`，数据库未就绪时
用 `try/catch` 静默跳过，不会中断安装向导。站长在「插件管理」停用后 marker 仍为 1，
因此不会被再次自动启用。若需重置为「未自动启用」，删除 `moez_cp_autoinstall` 这个设置键即可。

