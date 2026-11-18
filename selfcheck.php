<?php
/**
 * 2.2.13 文件分类整理兼容桩：正文已迁至 tools/selfcheck.php
 * 2.2.30 起该诊断入口禁止 Web 访问（根 .htaccess / nginx.conf / Caddyfile 已拦截）；
 * 文件保留为兼容桩，避免历史书签落到无鉴权的诊断输出，正常功能不依赖它。
 */
require __DIR__ . '/tools/selfcheck.php';
