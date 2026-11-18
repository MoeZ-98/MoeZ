<?php
/**
 * MoeZ · 配置文件模板（不含任何真实凭据）
 * ------------------------------------------------------------
 * 参照 emlog 的 config.sample.php 补齐：对外分发程序包时请用它顶替 config.php，
 * 避免把明文数据库密码打进 zip。
 *
 * 正常安装无需手工创建本文件：浏览器访问域名会跳转 install.php，由安装器生成 config.php。
 * 若要手工部署，复制本文件为 config.php 并填写下列值。
 */
return array(
  'db' => array(
    'host'    => '127.0.0.1',
    'port'    => '3306',
    'name'    => 'your_database_name',
    'user'    => 'your_database_user',
    'pass'    => 'your_database_password',
    'prefix'  => 'moe_',
  ),
  // 32 位以上随机字符串，用于 Cookie / 签名；安装器会自动生成，不要使用示例值
  'key' => 'replace_with_a_random_32_char_string',
);
