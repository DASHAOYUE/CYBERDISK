<?php
/**
 * CyberDisk 安装向导（虚拟主机部署用）
 * 访问 install.php 按提示完成：环境自检 → 填写数据库 → 自动建表 → 生成 config.php
 * 安装完成后请勿删除本文件（内有锁文件保护，重装需手动删除 data/installed.lock）
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

/** php.ini 尺寸字符串解析（8M → 8388608），供上传上限展示 */
function iparse($s)
{
    $s = trim((string)$s);
    if ($s === '' || $s === '0' || $s === '-1') return 0;
    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?B?)$/i', $s, $m)) {
        $u = strtoupper($m[2]);
        $k = ['' => 1, 'B' => 1, 'K' => 1024, 'KB' => 1024, 'M' => 1048576, 'MB' => 1048576, 'G' => 1073741824, 'GB' => 1073741824, 'T' => 1099511627776];
        return (int)round(((float)$m[1]) * ($k[$u] ?? 1));
    }
    return (int)$s;
}
$upMaxIni = ini_get('upload_max_filesize') ?: '未知';
$postMaxIni = ini_get('post_max_size') ?: '未知';
$upMaxB = iparse($upMaxIni);
$postMaxB = iparse($postMaxIni);
$limitSmall = ($upMaxB > 0 && $upMaxB < 104857600) || ($postMaxB > 0 && $postMaxB < 104857600); // 任一 < 100M 视为偏小

$LOCK = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'installed.lock';
$CONFIG = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
@mkdir(__DIR__ . DIRECTORY_SEPARATOR . 'data', 0755, true);
@mkdir(__DIR__ . DIRECTORY_SEPARATOR . 'uploads', 0755, true);

/* ---------- 环境自检 ---------- */
$checks = [];
$checks['PHP 版本 ≥ 7.4'] = [PHP_VERSION_ID >= 70400, PHP_VERSION];
$checks['PDO MySQL 扩展'] = [extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '已启用' : '未启用'];
$checks['mbstring 扩展'] = [extension_loaded('mbstring'), extension_loaded('mbstring') ? '已启用' : '未启用'];
$checks['fileinfo 扩展'] = [extension_loaded('fileinfo'), extension_loaded('fileinfo') ? '已启用' : '未启用'];
$checks['ZipArchive 扩展（文件夹打包用）'] = [class_exists('ZipArchive'), class_exists('ZipArchive') ? '已启用' : '未启用（文件夹打包不可用）'];
$checks['GD 扩展（缩略图用，可选）'] = [extension_loaded('gd'), extension_loaded('gd') ? '已启用' : '未启用（不影响核心功能）'];
$checks['网站根目录可写（生成 config.php）'] = [is_writable(__DIR__), is_writable(__DIR__) ? '可写' : '不可写'];
$checks['uploads 目录可写'] = [is_writable(__DIR__ . '/uploads'), is_writable(__DIR__ . '/uploads') ? '可写' : '不可写'];
$checks['data 目录可写'] = [is_writable(__DIR__ . '/data'), is_writable(__DIR__ . '/data') ? '可写' : '不可写'];
$hardFail = false;
foreach (['PHP 版本 ≥ 7.4', 'PDO MySQL 扩展', 'mbstring 扩展', 'fileinfo 扩展', '网站根目录可写（生成 config.php）', 'uploads 目录可写'] as $k) {
    if (!($checks[$k][0] ?? false)) $hardFail = true;
}

/* ---------- 状态判断 ---------- */
$installed = is_file($LOCK);
$errors = [];
$done = false;
$old = [
    'db_host' => $_POST['db_host'] ?? 'localhost',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? '',
    'db_user' => $_POST['db_user'] ?? '',
    'admin_user' => $_POST['admin_user'] ?? 'admin',
];

/* ---------- 安装处理 ---------- */
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && !$hardFail) {
    $dbHost = trim((string)($_POST['db_host'] ?? ''));
    $dbPort = trim((string)($_POST['db_port'] ?? '3306'));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminPass2 = (string)($_POST['admin_pass2'] ?? '');

    if ($dbHost === '') $errors[] = '数据库主机不能为空';
    if ($dbName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $dbName)) $errors[] = '数据库名不能为空且只能包含字母数字下划线';
    if ($dbUser === '') $errors[] = '数据库用户名不能为空';
    if ($adminUser === '' || !preg_match('/^[A-Za-z0-9_]{3,32}$/', $adminUser)) $errors[] = '管理员账号需 3-32 位字母数字下划线';
    if (strlen($adminPass) < 6) $errors[] = '管理员密码至少 6 位';
    if ($adminPass !== $adminPass2) $errors[] = '两次输入的管理员密码不一致';

    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
            );
            // 优先尝试建库（部分虚拟主机无权限，则要求库已存在）
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable $e) {
                // 忽略：可能无建库权限，连接具体库时再验证
            }
            $pdo->exec("USE `{$dbName}`");
            $pdo->exec("SET NAMES utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS cd_users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 10737418240,
                is_admin TINYINT UNSIGNED NOT NULL DEFAULT 0,
                role_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                last_login_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS cd_nodes (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                owner_id INT UNSIGNED NOT NULL,
                parent_id BIGINT UNSIGNED NULL,
                name VARCHAR(255) NOT NULL,
                is_dir TINYINT UNSIGNED NOT NULL DEFAULT 0,
                size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                mime VARCHAR(128) NULL,
                storage_name VARCHAR(128) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                KEY idx_owner_parent (owner_id, parent_id, deleted_at),
                KEY idx_storage (storage_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS cd_shares (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                token CHAR(32) NOT NULL UNIQUE,
                node_id BIGINT UNSIGNED NOT NULL,
                owner_id INT UNSIGNED NOT NULL,
                extract_code VARCHAR(16) NULL,
                expires_at DATETIME NULL,
                view_count INT UNSIGNED NOT NULL DEFAULT 0,
                download_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                revoked TINYINT UNSIGNED NOT NULL DEFAULT 0,
                KEY idx_owner (owner_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS cd_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                action VARCHAR(64) NOT NULL,
                detail VARCHAR(512) NULL,
                ip VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_user_time (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS cd_settings (
                skey VARCHAR(64) NOT NULL PRIMARY KEY,
                sval VARCHAR(512) NULL,
                updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS cd_roles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(64) NOT NULL UNIQUE,
                memo VARCHAR(255) NULL,
                perms TEXT NOT NULL,
                built_in TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // 内置角色（幂等）
            $permAll = json_encode(['upload','download','preview','share','mkdir','rename','move','trash','recycle','settings','manage_users','manage_roles']);
            $permMember = json_encode(['upload','download','preview','share','mkdir','rename','move','trash','recycle']);
            $permGuest = json_encode(['download','preview']);
            $stR = $pdo->prepare('INSERT IGNORE INTO cd_roles (name, memo, perms, built_in, created_at) VALUES (?,?,?,?,?)');
            $now = date('Y-m-d H:i:s');
            $stR->execute(['管理员', '系统内置 · 全部权限（含账号与角色管理）', $permAll, 1, $now]);
            $stR->execute(['成员', '系统内置 · 上传下载与整理，不含系统管理', $permMember, 1, $now]);
            $stR->execute(['访客', '系统内置 · 仅查看与下载', $permGuest, 1, $now]);

            // 管理员：库内已有用户则保留，否则创建
            $hasUser = (int)$pdo->query('SELECT COUNT(*) FROM cd_users')->fetchColumn();
            if ($hasUser === 0) {
                $st = $pdo->prepare('INSERT INTO cd_users (username,password_hash,quota_bytes,is_admin,role_id,created_at) VALUES (?,?,?,1,1,?)');
                $st->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 53687091200, date('Y-m-d H:i:s')]);
            }

            // 生成 config.php
            $cfg = "<?php\n"
                 . "// CyberDisk 配置（安装向导 " . date('Y-m-d H:i:s') . " 自动生成）\n"
                 . "// 请勿公开此文件；如需迁移，请修改下方数据库连接信息。\n"
                 . "define('CD_DB_HOST', " . var_export($dbHost, true) . ");\n"
                 . "define('CD_DB_PORT', " . var_export($dbPort, true) . ");\n"
                 . "define('CD_DB_USER', " . var_export($dbUser, true) . ");\n"
                 . "define('CD_DB_PASS', " . var_export($dbPass, true) . ");\n"
                 . "define('CD_DB_NAME', " . var_export($dbName, true) . ");\n"
                 . "define('CD_UPLOAD_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'uploads');\n";
            if (file_put_contents($CONFIG, $cfg, LOCK_EX) === false) {
                throw new RuntimeException('无法写入 config.php，请确认网站根目录可写');
            }
            @chmod($CONFIG, 0640);
            file_put_contents($LOCK, 'installed at ' . date('Y-m-d H:i:s') . PHP_EOL, LOCK_EX);
            $done = true;
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
            if (stripos($e->getMessage(), 'Unknown database') !== false) {
                $errors[] = '提示：你的主机商可能不允许程序自动建库，请先在主机面板（如 phpMyAdmin）中创建同名数据库，然后再提交。';
            }
            if (stripos($e->getMessage(), 'Access denied') !== false) {
                $errors[] = '提示：数据库用户名或密码不正确，或该用户无此库权限。';
            }
        }
    }
}

$h = function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CYBER DISK // 安装向导</title>
<style>
:root{
  --bg:#03060d; --panel:rgba(10,18,32,.9); --line:rgba(0,240,255,.28);
  --cyan:#00f0ff; --magenta:#ff2ec4; --green:#00ff9c; --warn:#ffb347; --danger:#ff3860;
  --text:#c8e6ff; --bright:#eaf8ff; --dim:#5f7f9e;
  --mono:'JetBrains Mono','Cascadia Code',Consolas,'Courier New',monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--mono);font-size:14px;line-height:1.7;min-height:100vh}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:
  radial-gradient(ellipse 80% 50% at 50% -10%, rgba(0,240,255,.10), transparent),
  linear-gradient(rgba(0,240,255,.04) 1px, transparent 1px),
  linear-gradient(90deg, rgba(0,240,255,.04) 1px, transparent 1px);
  background-size:100% 100%,44px 44px,44px 44px}
#wrap{position:relative;z-index:1;max-width:760px;margin:0 auto;padding:38px 20px 70px}
.brand{font-size:24px;font-weight:700;letter-spacing:3px;color:var(--cyan);text-shadow:0 0 14px rgba(0,240,255,.6)}
.brand .zh{color:var(--magenta);text-shadow:0 0 10px rgba(255,46,196,.5)}
.sub{color:var(--dim);font-size:11px;letter-spacing:4px;margin:4px 0 28px}
.hud{position:relative;background:var(--panel);border:1px solid var(--line);padding:24px 26px;margin-bottom:16px;
  box-shadow:0 0 24px rgba(0,240,255,.07);backdrop-filter:blur(4px)}
.hud::before,.hud::after{content:'';position:absolute;width:12px;height:12px}
.hud::before{top:-1px;left:-1px;border-top:2px solid var(--cyan);border-left:2px solid var(--cyan)}
.hud::after{bottom:-1px;right:-1px;border-bottom:2px solid var(--cyan);border-right:2px solid var(--cyan)}
h2{color:var(--cyan);font-size:14px;letter-spacing:2px;margin-bottom:14px;font-weight:500}
.chk{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed rgba(0,240,255,.1);font-size:12.5px}
.chk .v-ok{color:var(--green)} .chk .v-no{color:var(--danger)} .chk .v-warn{color:var(--warn)}
label{display:block;font-size:10px;letter-spacing:2px;color:var(--dim);margin:14px 0 6px}
input{width:100%;background:rgba(0,12,24,.9);border:1px solid var(--line);color:var(--bright);
  font-family:var(--mono);font-size:14px;padding:10px 12px;outline:none;transition:.2s}
input:focus{border-color:var(--cyan);box-shadow:0 0 12px rgba(0,240,255,.3)}
input::placeholder{color:rgba(95,127,158,.5)}
.row2{display:grid;grid-template-columns:2fr 1fr;gap:12px}
.btn{display:inline-block;background:rgba(0,240,255,.1);border:1px solid var(--cyan);color:#020a10;background:var(--cyan);
  font-family:var(--mono);font-size:14px;font-weight:700;letter-spacing:4px;padding:12px 30px;cursor:pointer;transition:.2s;
  margin-top:22px;border:none}
.btn:hover{box-shadow:0 0 22px rgba(0,240,255,.7)}
.btn.ghost{background:rgba(0,240,255,.08);color:var(--cyan);border:1px solid var(--line);font-weight:400}
.btn.ghost:hover{background:rgba(0,240,255,.16);box-shadow:0 0 16px rgba(0,240,255,.4)}
.err{border:1px solid rgba(255,56,96,.5);background:rgba(255,56,96,.07);color:var(--danger);padding:12px 16px;margin-bottom:14px;font-size:12.5px}
.err p{margin:3px 0}
.ok-box{border:1px solid rgba(0,255,156,.5);background:rgba(0,255,156,.06);padding:22px;text-align:center}
.ok-box .big{font-size:20px;color:var(--green);letter-spacing:4px;text-shadow:0 0 14px rgba(0,255,156,.6);margin-bottom:10px}
.note{color:var(--dim);font-size:11.5px;line-height:1.9;margin-top:10px}
.note b{color:var(--warn);font-weight:400}
.sep{margin:20px 0 4px;border-top:1px dashed rgba(0,240,255,.15);padding-top:14px;color:var(--cyan);font-size:11px;letter-spacing:3px}
footer{text-align:center;color:var(--dim);font-size:10px;letter-spacing:3px;margin-top:24px}
a{color:var(--cyan)}
</style>
</head>
<body>
<div id="wrap">
  <div class="brand">CYBER<span style="color:#eaf8ff">·</span>DISK <span class="zh">星辰网盘</span></div>
  <div class="sub">INSTALLER // 部署引导 · v1.0</div>

<?php if ($installed || $done): ?>
  <div class="hud">
    <div class="ok-box">
      <div class="big">◈ INSTALLATION COMPLETE</div>
      <p><?= $done ? '安装成功！配置已生成，数据表已就绪。' : '系统已完成安装。' ?></p>
      <p style="margin-top:14px">
        <a class="btn" href="index.php">进入网盘登录页</a>
      </p>
      <p class="note" style="margin-top:16px">
        安全提示：请立即用安装时设置的管理员账号登录并<strong style="color:var(--warn)">修改密码</strong>。<br>
        如需重新安装，请先删除 <code>data/installed.lock</code> 与 <code>config.php</code>。
      </p>
    </div>
  </div>
<?php else: ?>
  <div class="hud">
    <h2>01 · 环境自检</h2>
    <?php foreach ($checks as $name => $c): ?>
      <div class="chk">
        <span><?= $h($name) ?></span>
        <span class="<?= $c[0] ? 'v-ok' : (mb_strpos($name, '（可选') !== false ? 'v-warn' : 'v-no') ?>">
          <?= $c[0] ? '✔ ' : (mb_strpos($name, '（可选') !== false ? '○ ' : '✖ ') ?><?= $h($c[1]) ?>
        </span>
      </div>
    <?php endforeach; ?>
    <div class="chk">
      <span>单文件上传上限 upload_max_filesize</span>
      <span class="<?= $limitSmall ? 'v-warn' : 'v-ok' ?>"><?= $limitSmall ? '⚠ ' : '✔ ' ?><?= $h($upMaxIni) ?></span>
    </div>
    <div class="chk">
      <span>请求总量上限 post_max_size</span>
      <span class="<?= $limitSmall ? 'v-warn' : 'v-ok' ?>"><?= $limitSmall ? '⚠ ' : '✔ ' ?><?= $h($postMaxIni) ?></span>
    </div>
    <?php if ($limitSmall): ?>
      <div class="note" style="margin-top:12px">
        上传上限偏小（&lt;100M）：上传大文件会被服务器直接丢弃并提示"未收到文件"。
        请在主机面板「PHP 设置」把 upload_max_filesize 与 post_max_size 调到大于文件体积（如 128M / 135M）；
        Apache(mod_php) / LiteSpeed 主机 .htaccess 内的 php_value 亦可调整。
      </div>
    <?php endif; ?>
    <?php if ($hardFail): ?>
      <div class="err" style="margin-top:16px">
        <p>环境自检未通过：请先联系主机商开启缺失的扩展 / 调整目录权限，刷新本页后重试。</p>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($errors): ?>
    <div class="err">
      <?php foreach ($errors as $e): ?><p>✖ <?= $h($e) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="install.php">
  <div class="hud">
    <h2>02 · 数据库连接（主机面板中创建数据库后填写）</h2>
    <div class="row2">
      <div><label>DB HOST / 数据库主机</label>
        <input name="db_host" value="<?= $h($old['db_host']) ?>" placeholder="多数虚拟主机为 localhost"></div>
      <div><label>PORT / 端口</label>
        <input name="db_port" value="<?= $h($old['db_port']) ?>" placeholder="3306"></div>
    </div>
    <label>DB NAME / 数据库名</label>
    <input name="db_name" value="<?= $h($old['db_name']) ?>" placeholder="如 cyberdisk" required>
    <label>DB USER / 数据库用户名</label>
    <input name="db_user" value="<?= $h($old['db_user']) ?>" required>
    <label>DB PASSWORD / 数据库密码</label>
    <input name="db_pass" type="password" value="">
    <div class="note">
      提示：如果主机不支持程序建库，请先在面板创建数据库并记下库名/用户名/密码再填写此处；
      程序会自动建表（表不存在时）。
    </div>
  </div>

  <div class="hud">
    <h2>03 · 管理员账号（网盘登录用）</h2>
    <label>ADMIN USER / 管理员账号</label>
    <input name="admin_user" value="<?= $h($old['admin_user']) ?>" required>
    <label>ADMIN PASSWORD / 管理员密码（至少 6 位）</label>
    <input name="admin_pass" type="password" required>
    <label>CONFIRM / 确认密码</label>
    <input name="admin_pass2" type="password" required>
    <div class="note">
      安全提示：默认可自定义，<b>请勿使用弱口令</b>。安装完成后可在网盘右上角"密码"按钮随时修改。
    </div>
  </div>

  <div style="text-align:center">
    <button class="btn" type="submit" <?= $hardFail ? 'disabled style="opacity:.4;cursor:not-allowed"' : '' ?>>▶ 开始安装</button>
  </div>
  </form>
<?php endif; ?>

  <footer>CYBER DISK TERMINAL · INSTALLER · <?= date('Y') ?></footer>
</div>
</body>
</html>