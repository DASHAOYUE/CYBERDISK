<?php
/**
 * CyberDisk 公共库：数据库连接、鉴权、工具函数
 * 赛博风格个人网盘 · 参照永硕网盘（ys168.com）核心功能
 *
 * 配置加载顺序：
 *   1. 同目录 config.php（虚拟主机安装向导生成）
 *   2. 内置默认值（本地开发 / 内置服务器部署）
 */
declare(strict_types=1);

/* PHP 7.4 兼容 polyfill（虚拟主机常见 7.4~8.x） */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'config.php')) {
    require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
} else {
    define('CD_DB_HOST', '127.0.0.1');
    define('CD_DB_PORT', '3306');
    define('CD_DB_USER', 'root');
    define('CD_DB_PASS', 'password');
    define('CD_DB_NAME', 'cyberdisk');
    define('CD_UPLOAD_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
}
if (!defined('CD_UPLOAD_DIR')) {
    define('CD_UPLOAD_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
}
define('CD_TMP_DIR', CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . '.tmp');

date_default_timezone_set('Asia/Shanghai');

/* ---------- 数据库 ---------- */
function cd_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . CD_DB_HOST . ';port=' . CD_DB_PORT . ';dbname=' . CD_DB_NAME . ';charset=utf8mb4',
            CD_DB_USER,
            CD_DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

/** 首次运行自动建表（开箱即用自愈） */
function cd_ensure_schema(): void
{
    $installed = true;
    try {
        cd_db()->query('SELECT 1 FROM cd_users LIMIT 1');
    } catch (Throwable $e) {
        $installed = false;
    }
    if ($installed) {
        // 老版本升级：cd_users 已存在时也要补建后续新增的表
        cd_ensure_extra_tables();
        return;
    }
    $db = cd_db();
    $db->exec('CREATE DATABASE IF NOT EXISTS cyberdisk DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db->exec('USE cyberdisk');
    $db->exec("CREATE TABLE IF NOT EXISTS cd_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(64) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 10737418240,
        is_admin TINYINT UNSIGNED NOT NULL DEFAULT 0,
        role_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        last_login_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS cd_nodes (
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
        KEY idx_storage (storage_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS cd_shares (
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
        KEY idx_owner (owner_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS cd_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        action VARCHAR(64) NOT NULL,
        detail VARCHAR(512) NULL,
        ip VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        KEY idx_user_time (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $db->prepare('INSERT IGNORE INTO cd_users (username,password_hash,quota_bytes,is_admin,role_id,created_at) VALUES (?,?,?,1,1,?)')
        ->execute(['admin', $hash, 53687091200, date('Y-m-d H:i:s')]);
    cd_ensure_extra_tables();
}

/**
 * 增量建表：老版本升级后补建新增表，不影响已有数据。
 * 每次请求执行一次 CREATE TABLE IF NOT EXISTS，成本极低。
 * 注意：必须显式声明 utf8mb4 —— 部分虚拟主机面板建库默认 latin1，
 * 否则新表继承库默认字符集，中文写入时被转成问号（???）。
 */
function cd_ensure_extra_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        cd_db()->exec("CREATE TABLE IF NOT EXISTS cd_settings (
            skey VARCHAR(64) NOT NULL PRIMARY KEY,
            sval VARCHAR(512) NULL,
            updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // 角色权限表（账号/角色管理）
        cd_db()->exec("CREATE TABLE IF NOT EXISTS cd_roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL UNIQUE,
            memo VARCHAR(255) NULL,
            perms TEXT NOT NULL,
            built_in TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // 老库补列：cd_users 增加 role_id（列已存在时报 Duplicate column，捕获忽略）
        try {
            cd_db()->exec("ALTER TABLE cd_users ADD COLUMN role_id INT UNSIGNED NULL AFTER is_admin");
        } catch (Throwable $e) {
            // 1060 Duplicate column 忽略
        }
        // 字符集自愈：若这两张表由旧版本 auto-heal 建成了 latin1 等错误字符集，
        // 转换为 utf8mb4（主机限制 ALTER 权限时忽略）
        cd_repair_table_charset('cd_settings');
        cd_repair_table_charset('cd_roles');
        // 内置角色按 id 幂等 upsert：修复曾被写坏（???）的名称与说明；
        // 已存在时不覆盖 perms —— 保留管理员对内置角色的权限收紧
        $now = date('Y-m-d H:i:s');
        $st = cd_db()->prepare('INSERT INTO cd_roles (id, name, memo, perms, built_in, created_at) VALUES (?,?,?,?,1,?)
                                ON DUPLICATE KEY UPDATE name = VALUES(name), memo = VALUES(memo), built_in = 1');
        $st->execute([1, '管理员', '系统内置 · 全部权限（含账号与角色管理）', json_encode(array_keys(CD_PERMS)), $now]);
        $st->execute([2, '成员', '系统内置 · 上传下载与整理，不含系统管理', json_encode(['upload','download','preview','share','mkdir','rename','move','trash','recycle']), $now]);
        $st->execute([3, '访客', '系统内置 · 仅查看与下载', json_encode(['download','preview']), $now]);
        // 清理游离的内置角色行（防御性，正常不存在）
        cd_db()->exec('DELETE FROM cd_roles WHERE built_in = 1 AND id NOT IN (1,2,3)');
        // 未设置角色的老用户默认给「成员」，管理员绑定「管理员」
        cd_db()->exec("UPDATE cd_users SET role_id = 2 WHERE role_id IS NULL AND is_admin = 0");
        cd_db()->exec("UPDATE cd_users SET role_id = 1 WHERE role_id IS NULL AND is_admin = 1");
    } catch (Throwable $e) {
        // 只读账号等特殊情况：忽略，后续读写设置会走默认值
    }
}

/**
 * 检测表实际字符集，非 utf8mb4 时尝试转换为 utf8mb4。
 * 通过 information_schema 查询（标准、廉价），ALTER 失败（权限受限）则忽略。
 */
function cd_repair_table_charset(string $table): void
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return; // 白名单校验，防注入
    }
    try {
        $st = cd_db()->prepare("SELECT CCSA.character_set_name FROM information_schema.TABLES t
            JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
              ON t.TABLE_COLLATION = CCSA.COLLATION_NAME
            WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ?");
        $st->execute([$table]);
        $cs = $st->fetchColumn();
        if ($cs && strtolower((string)$cs) !== 'utf8mb4') {
            cd_db()->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    } catch (Throwable $e) {
        // information_schema 无权限或 ALTER 被主机限制：忽略
    }
}

/* ---------- 角色与权限 ---------- */
/** 权限目录（键 => 中文标签），角色保存时仅接受此处列出的键 */
const CD_PERMS = [
    'upload'       => '上传文件（含文件夹/分片上传）',
    'download'     => '下载 / 打包下载',
    'preview'      => '在线预览（图片/媒体/Office）',
    'share'        => '创建 / 取消分享',
    'mkdir'        => '新建文件夹',
    'rename'       => '重命名',
    'move'         => '移动文件（拖拽/目录树）',
    'trash'        => '删除到回收站',
    'recycle'      => '回收站恢复 / 彻底删除 / 清空',
    'settings'     => '系统上传设置（分片大小）',
    'manage_users' => '账号管理（增删改账号/配额）',
    'manage_roles' => '角色管理（增删改角色/权限）',
];

/** 解析用户权限集合：管理员全量；否则取所属角色（无角色按「成员」兜底） */
function cd_user_perms(array $user): array
{
    if ((int)($user['is_admin'] ?? 0) === 1) {
        return array_keys(CD_PERMS);
    }
    $roleId = isset($user['role_id']) ? (int)$user['role_id'] : 0;
    $db = cd_db();
    $st = $db->prepare('SELECT perms FROM cd_roles WHERE id = ?');
    $st->execute([$roleId ?: 2]); // 兜底「成员」
    $raw = $st->fetchColumn();
    $perms = json_decode((string)$raw, true);
    if (!is_array($perms)) {
        $perms = [];
    }
    // 只保留合法键
    $valid = [];
    foreach ($perms as $p) {
        if (isset(CD_PERMS[$p])) {
            $valid[$p] = true;
        }
    }
    return array_keys($valid);
}

/** 权限校验：不通过直接 403 */
function cd_require_perm(array $user, string $perm): void
{
    if (!in_array($perm, cd_user_perms($user), true)) {
        cd_fail('当前账号无「' . (CD_PERMS[$perm] ?? $perm) . '」权限', 403);
    }
}

/** 角色信息（含权限数组），找不到返回 null */
function cd_get_role(int $roleId): ?array
{
    $st = cd_db()->prepare('SELECT * FROM cd_roles WHERE id = ?');
    $st->execute([$roleId]);
    $r = $st->fetch();
    return $r ?: null;
}

/* ---------- 输出 ---------- */
function cd_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
    exit;
}

function cd_ok(array $data = []): void
{
    cd_json(array_merge(['ok' => true], $data));
}

function cd_fail(string $msg, int $code = 400): void
{
    cd_json(['ok' => false, 'error' => $msg], $code);
}

/* ---------- 会话与鉴权 ---------- */
function cd_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('CYBERDISK_SESS');
        session_start();
    }
}

function cd_current_user(): ?array
{
    cd_session_start();
    if (empty($_SESSION['cd_uid'])) {
        return null;
    }
    $st = cd_db()->prepare('SELECT id, username, quota_bytes, is_admin, role_id, created_at, last_login_at FROM cd_users WHERE id = ?');
    $st->execute([$_SESSION['cd_uid']]);
    $u = $st->fetch();
    return $u ?: null;
}

function cd_require_user(): array
{
    $u = cd_current_user();
    if (!$u) {
        cd_fail('未登录或会话已过期', 401);
    }
    return $u;
}

/* ---------- 工具 ---------- */
function cd_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** 解析 php.ini 尺寸字符串（如 512M / 2G / 1024K / 1048576）为字节数；无效返回 0 */
function cd_ini_to_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '0' || $v === '-1') {
        return 0;
    }
    if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTPE]?B?)$/i', $v, $m)) {
        $unit = strtoupper($m[2]);
        $mult = [
            '' => 1, 'B' => 1,
            'K' => 1024, 'KB' => 1024,
            'M' => 1048576, 'MB' => 1048576,
            'G' => 1073741824, 'GB' => 1073741824,
            'T' => 1099511627776, 'TB' => 1099511627776,
            'P' => 1125899906842624, 'PB' => 1125899906842624,
        ];
        return (int)round(((float)$m[1]) * ($mult[$unit] ?? 1));
    }
    return (int)$v;
}

/* ---------- 系统设置（键值表） ---------- */
function cd_get_setting(string $key, ?string $default = null): ?string
{
    try {
        $st = cd_db()->prepare('SELECT sval FROM cd_settings WHERE skey = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string)$v;
    } catch (Throwable $e) {
        return $default;
    }
}

function cd_set_setting(string $key, string $value): void
{
    cd_db()->prepare('INSERT INTO cd_settings (skey, sval, updated_at) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE sval = VALUES(sval), updated_at = VALUES(updated_at)')
        ->execute([$key, $value, date('Y-m-d H:i:s')]);
}

/* ---------- 分片上传配置 ---------- */
define('CD_CHUNK_MIN_BYTES', 262144);       // 分片下限 256KB
define('CD_CHUNK_DEFAULT_BYTES', 4194304);  // 自动模式默认 4MB
define('CD_CHUNK_MAX_MB', 512);             // 自定义上限 512MB

/** 读取分片配置：['mode' => 'auto'|'custom', 'sizeMb' => int(0=未设置)] */
function cd_chunk_config(): array
{
    $mode = strtolower((string)cd_get_setting('chunk_mode', 'auto'));
    if ($mode !== 'custom') {
        $mode = 'auto';
    }
    $mb = (int)cd_get_setting('chunk_size_mb', '0');
    if ($mb < 1 || $mb > CD_CHUNK_MAX_MB) {
        $mb = 0;
    }
    return ['mode' => $mode, 'sizeMb' => $mb];
}

/** 主机允许的单请求上限（字节，0 = 未知/不限） */
function cd_host_upload_limits(): array
{
    return [
        'uploadMax' => cd_ini_to_bytes((string)(ini_get('upload_max_filesize') ?: '0')),
        'postMax' => cd_ini_to_bytes((string)(ini_get('post_max_size') ?: '0')),
    ];
}

/**
 * 计算实际生效的分片大小（字节）
 * - auto：默认 4MB
 * - custom：按配置的 MB
 * 两者都会被主机 upload_max_filesize / post_max_size 收紧，收紧时 clamped=true。
 */
function cd_chunk_effective(): array
{
    $cfg = cd_chunk_config();
    $lim = cd_host_upload_limits();
    $want = ($cfg['mode'] === 'custom' && $cfg['sizeMb'] > 0)
        ? $cfg['sizeMb'] * 1048576
        : CD_CHUNK_DEFAULT_BYTES;
    $eff = $want;
    if ($lim['postMax'] > 0) {
        $eff = min($eff, max(CD_CHUNK_MIN_BYTES, $lim['postMax'] - 1048576));
    }
    if ($lim['uploadMax'] > 0) {
        $eff = min($eff, max(CD_CHUNK_MIN_BYTES, $lim['uploadMax'] - 262144));
    }
    $eff = max(CD_CHUNK_MIN_BYTES, (int)floor($eff));
    return [
        'mode' => $cfg['mode'],
        'sizeMb' => $cfg['sizeMb'],
        'wantBytes' => (int)$want,
        'effectiveBytes' => $eff,
        'clamped' => $eff < (int)$want,
    ];
}

function cd_log(int $uid, string $action, string $detail = ''): void
{
    try {
        cd_db()->prepare('INSERT INTO cd_logs (user_id, action, detail, ip, created_at) VALUES (?,?,?,?,?)')
            ->execute([$uid, mb_substr($action, 0, 60), mb_substr($detail, 0, 500), cd_client_ip(), date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // 日志失败不影响主流程
    }
}

/** 清洗文件/文件夹名：剔除 Windows 非法字符与控制符 */
function cd_clean_name(string $raw): string
{
    $name = str_replace(["\0", "\r", "\n", "\t"], ' ', $raw);
    $name = preg_replace('/[\\\\\/:*?"<>|]/u', '_', $name);
    $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name);
    $name = trim($name);
    $name = rtrim($name, '. '); // Windows 目录名不能以点/空格结尾
    if ($name === '') {
        cd_fail('名称不能为空');
    }
    if (mb_strlen($name) > 200) {
        $name = mb_substr($name, 0, 200);
    }
    return $name;
}

function cd_random_hex(int $len = 16): string
{
    return bin2hex(random_bytes($len));
}

/** 同目录重名自动加 " (n)" 后缀（目录/上传通用，返回最终可用名） */
function cd_unique_name(int $owner, ?int $parentId, string $name, bool $isDir, ?int $excludeId = null): string
{
    $db = cd_db();
    $sql = 'SELECT name FROM cd_nodes WHERE owner_id = ? AND deleted_at IS NULL AND is_dir = ? AND name LIKE ?'
         . ($parentId === null ? ' AND parent_id IS NULL' : ' AND parent_id = ?')
         . ($excludeId ? ' AND id <> ?' : '');
    $params = [$owner, $isDir ? 1 : 0, $name . '%'];
    if ($parentId !== null) {
        $params[] = $parentId;
    }
    if ($excludeId) {
        $params[] = $excludeId;
    }
    $st = $db->prepare($sql);
    $st->execute($params);
    $existing = [];
    foreach ($st->fetchAll() as $row) {
        $existing[mb_strtolower($row['name'])] = true;
    }
    if (!isset($existing[mb_strtolower($name)])) {
        return $name;
    }
    $dot = mb_strrpos($name, '.');
    $base = ($dot !== false && $dot > 0) ? mb_substr($name, 0, $dot) : $name;
    $ext = ($dot !== false && $dot > 0) ? mb_substr($name, $dot) : '';
    for ($i = 2; $i < 10000; $i++) {
        $candidate = $base . ' (' . $i . ')' . $ext;
        if (!isset($existing[mb_strtolower($candidate)])) {
            return $candidate;
        }
    }
    return $base . ' (' . cd_random_hex(4) . ')' . $ext;
}

function cd_used_bytes(int $uid): int
{
    $st = cd_db()->prepare('SELECT COALESCE(SUM(size),0) FROM cd_nodes WHERE owner_id = ? AND is_dir = 0 AND deleted_at IS NULL');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** 物理存储路径：uploads/u{uid}/{random}.{ext} */
function cd_storage_path(int $uid, string $storageName): string
{
    return CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid . DIRECTORY_SEPARATOR . $storageName;
}

/** 取节点（校验属主与未删除） */
function cd_get_node(int $uid, int $nodeId, bool $allowDeleted = false): array
{
    $db = cd_db();
    $sql = 'SELECT * FROM cd_nodes WHERE id = ? AND owner_id = ?' . ($allowDeleted ? '' : ' AND deleted_at IS NULL');
    $st = $db->prepare($sql);
    $st->execute([$nodeId, $uid]);
    $n = $st->fetch();
    if (!$n) {
        cd_fail('文件或文件夹不存在', 404);
    }
    return $n;
}

/** 简易登录防爆破：同一会话连续失败 5 次锁定 60 秒 */
function cd_login_throttle(): void
{
    cd_session_start();
    $fail = $_SESSION['cd_login_fail'] ?? 0;
    $lockUntil = $_SESSION['cd_login_lock_until'] ?? 0;
    if ($lockUntil > time()) {
        cd_fail('尝试次数过多，请 ' . ($lockUntil - time()) . ' 秒后重试', 429);
    }
    if ($fail >= 5) {
        $_SESSION['cd_login_lock_until'] = time() + 60;
        $_SESSION['cd_login_fail'] = 0;
        cd_fail('尝试次数过多，请 60 秒后重试', 429);
    }
}

function cd_login_fail_tick(): void
{
    $_SESSION['cd_login_fail'] = ($_SESSION['cd_login_fail'] ?? 0) + 1;
}

/** 分享子树校验：node 是否在分享根的子树内 */
function cd_in_subtree(int $shareOwnerId, int $rootId, int $nodeId): bool
{
    if ($rootId === $nodeId) {
        return true;
    }
    $db = cd_db();
    // 向上回溯最多 64 层
    $cur = $nodeId;
    for ($i = 0; $i < 64; $i++) {
        $st = $db->prepare('SELECT id, parent_id FROM cd_nodes WHERE id = ? AND owner_id = ?');
        $st->execute([$cur, $shareOwnerId]);
        $row = $st->fetch();
        if (!$row) {
            return false;
        }
        if ((int)$row['parent_id'] === $rootId) {
            return true;
        }
        if ($row['parent_id'] === null) {
            return false;
        }
        $cur = (int)$row['parent_id'];
    }
    return false;
}

/** 校验分享有效性（返回 null 表示无效，供页面渲染做友好错误展示） */
function cd_find_valid_share(string $token): ?array
{
    // token 规范化：只取前 32 位 hex，容忍链接被拼接了尾缀文字（如复制时误带的备注）。
    // 这样即使链接尾部被污染，只要前 32 位 token 完整依然可以正常打开。
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{32}/', $token, $m)) {
        return null;
    }
    $token = $m[0];
    $db = cd_db();
    $st = $db->prepare('SELECT s.*, n.name AS node_name, n.is_dir AS node_is_dir, n.deleted_at AS node_deleted_at
                        FROM cd_shares s JOIN cd_nodes n ON n.id = s.node_id WHERE s.token = ?');
    $st->execute([$token]);
    $s = $st->fetch();
    if (!$s || $s['revoked'] || $s['node_deleted_at'] !== null) {
        return null;
    }
    if ($s['expires_at'] !== null && strtotime($s['expires_at']) < time()) {
        return null;
    }
    return $s;
}

/** 校验分享有效性（供访客下载/浏览用），无效直接终止 */
function cd_get_valid_share(string $token): array
{
    $s = cd_find_valid_share($token);
    if (!$s) {
        cd_fail('分享不存在或已被取消', 404);
    }
    return $s;
}

/* ---------- ZIP 辅助 ---------- */
/**
 * ZIP 条目名净化：压缩包条目名可能是 GBK 等非 UTF-8 编码（Windows 打包常见），
 * json_encode 遇到非法 UTF-8 会失败，因此统一转换为 UTF-8，转换失败则替换非法字节。
 */
function cd_zip_name(string $name): string
{
    if ($name === '') {
        return '';
    }
    if (@preg_match('//u', $name) === 1) {
        return $name; // 已是合法 UTF-8
    }
    foreach (['GB18030', 'GBK', 'CP936'] as $cs) {
        $conv = @iconv($cs, 'UTF-8//IGNORE', $name);
        if (is_string($conv) && $conv !== '' && @preg_match('//u', $conv) === 1) {
            return $conv;
        }
    }
    $out = @preg_replace('/[\x80-\xff]/', '?', $name);
    return is_string($out) && $out !== '' ? $out : '???_name';
}
