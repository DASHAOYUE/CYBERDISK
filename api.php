<?php
/**
 * CyberDisk 后端 API
 * 路由：api.php?a=<action>
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

cd_ensure_schema();
header('X-Content-Type-Options: nosniff');

$action = $_GET['a'] ?? '';

/* ========== 未登录可用的接口 ========== */
if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        cd_fail('仅支持 POST');
    }
    cd_login_throttle();
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
        cd_fail('请输入账号和密码');
    }
    $db = cd_db();
    $st = $db->prepare('SELECT * FROM cd_users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        cd_login_fail_tick();
        cd_log((int)($u['id'] ?? 0), 'login_fail', 'username=' . $username);
        cd_fail('账号或密码错误', 401);
    }
    $_SESSION['cd_uid'] = (int)$u['id'];
    $_SESSION['cd_login_fail'] = 0;
    $db->prepare('UPDATE cd_users SET last_login_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $u['id']]);
    cd_log((int)$u['id'], 'login', '登录成功');
    cd_ok(['user' => ['id' => (int)$u['id'], 'username' => $u['username'], 'is_admin' => (int)$u['is_admin']]]);
}

if ($action === 'ping') {
    cd_ok(['service' => 'CyberDisk', 'time' => date('Y-m-d H:i:s')]);
}

/* ========== 以下均需登录 ========== */
$me = cd_require_user();
$uid = (int)$me['id'];

/* ---------- 会话 ---------- */
if ($action === 'me') {
    $perms = cd_user_perms($me);
    $role = $me['role_id'] ? cd_get_role((int)$me['role_id']) : null;
    $roleName = $role ? $role['name'] : ((int)$me['is_admin'] === 1 ? '管理员' : '成员');
    cd_ok([
        'user' => [
            'id' => $uid,
            'username' => $me['username'],
            'is_admin' => (int)$me['is_admin'],
            'role_id' => $me['role_id'] === null ? null : (int)$me['role_id'],
            'role_name' => $roleName,
            'perms' => $perms,
            'permCatalog' => CD_PERMS,
            'quota_bytes' => (int)$me['quota_bytes'],
            'used_bytes' => cd_used_bytes($uid),
            // 服务器 PHP 上传上限（前端上传前预检用，0 = 未知/不限）
            'upload_max_bytes' => cd_ini_to_bytes((string)(ini_get('upload_max_filesize') ?: '0')),
            'post_max_bytes' => cd_ini_to_bytes((string)(ini_get('post_max_size') ?: '0')),
            // 分片上传配置（mode / 自定义 MB / 实际生效字节 / 是否被主机上限收紧）
            'chunk' => cd_chunk_effective(),
        ],
    ]);
}

if ($action === 'logout') {
    cd_log($uid, 'logout');
    $_SESSION = [];
    session_destroy();
    cd_ok();
}

if ($action === 'changepw') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $old = (string)($body['oldPassword'] ?? '');
    $new = (string)($body['newPassword'] ?? '');
    if (strlen($new) < 6) cd_fail('新密码至少 6 位');
    $db = cd_db();
    $st = $db->prepare('SELECT password_hash FROM cd_users WHERE id = ?');
    $st->execute([$uid]);
    $hash = (string)$st->fetchColumn();
    if (!password_verify($old, $hash)) {
        cd_fail('原密码错误', 403);
    }
    $db->prepare('UPDATE cd_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    cd_log($uid, 'changepw', 'password updated');
    cd_ok();
}

/* ---------- 上传设置（分片大小可自定义） ---------- */
if ($action === 'settings') {
    $perms = cd_user_perms($me);
    cd_ok([
        'chunk' => cd_chunk_effective(),
        'limits' => cd_host_upload_limits(),
        'chunkMaxMb' => CD_CHUNK_MAX_MB,
        'can_edit' => in_array('settings', $perms, true),
    ]);
}

if ($action === 'savesettings') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'settings');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $mode = strtolower(trim((string)($body['chunkMode'] ?? 'auto')));
    if ($mode !== 'auto' && $mode !== 'custom') cd_fail('分片模式不合法');
    $mb = (int)($body['chunkSizeMb'] ?? 0);
    if ($mode === 'custom' && ($mb < 1 || $mb > CD_CHUNK_MAX_MB)) {
        cd_fail('自定义分片大小需在 1 ~ ' . CD_CHUNK_MAX_MB . ' MB 之间');
    }
    cd_set_setting('chunk_mode', $mode);
    if ($mode === 'custom') {
        cd_set_setting('chunk_size_mb', (string)$mb);
    }
    cd_log($uid, 'settings', 'chunk=' . $mode . ($mode === 'custom' ? ' ' . $mb . 'MB' : ''));
    cd_ok(['chunk' => cd_chunk_effective(), 'limits' => cd_host_upload_limits()]);
}

/* ---------- 音乐播放器（每用户个人设置） ---------- */
if ($action === 'music') {
    $enabled = cd_get_setting('music_enabled_u' . $uid, '0') === '1';
    $dirRaw = cd_get_setting('music_dir_u' . $uid, '');
    $dirId = ($dirRaw !== '' && $dirRaw !== '0') ? (int)$dirRaw : null;
    $tracks = [];
    $dirName = null;
    if ($enabled) {
        if ($dirId !== null) {
            $st = cd_db()->prepare('SELECT name, is_dir, deleted_at FROM cd_nodes WHERE id = ? AND owner_id = ?');
            $st->execute([$dirId, $uid]);
            $n = $st->fetch();
            if (!$n || !$n['is_dir'] || $n['deleted_at'] !== null) {
                // 所选文件夹已被删除：自动停用，避免播放器报错
                $enabled = false;
                cd_set_setting('music_enabled_u' . $uid, '0');
                cd_set_setting('music_dir_u' . $uid, '');
                $dirId = null;
            } else {
                $dirName = $n['name'];
            }
        }
        if ($enabled) {
            $tracks = cd_music_tracks($uid, $dirId);
        }
    }
    cd_ok(['enabled' => $enabled, 'dirId' => $dirId, 'dirName' => $dirName, 'tracks' => $tracks]);
}

if ($action === 'savemusic') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $enabled = !empty($body['enabled']);
    $dirParam = $body['dirId'] ?? null;
    $dirId = ($dirParam === null || $dirParam === '' || (int)$dirParam === 0) ? null : (int)$dirParam;
    if ($dirId !== null) {
        $st = cd_db()->prepare('SELECT is_dir, deleted_at FROM cd_nodes WHERE id = ? AND owner_id = ?');
        $st->execute([$dirId, $uid]);
        $n = $st->fetch();
        if (!$n || !$n['is_dir'] || $n['deleted_at'] !== null) cd_fail('所选音乐文件夹不存在');
    }
    cd_set_setting('music_enabled_u' . $uid, $enabled ? '1' : '0');
    cd_set_setting('music_dir_u' . $uid, $dirId === null ? '' : (string)$dirId);
    cd_log($uid, 'music', 'enabled=' . ($enabled ? 1 : 0) . ' dir=' . ($dirId === null ? 'all' : $dirId));
    cd_ok();
}

/** 收集音乐文件（含子文件夹），按名称排序，上限 500 首 */
function cd_music_tracks(int $uid, ?int $dirId): array
{
    $db = cd_db();
    // 仅收录浏览器可直接解码的格式
    $audio = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus'];
    if ($dirId === null) {
        $st = $db->prepare("SELECT id, name, size FROM cd_nodes
                            WHERE owner_id = ? AND deleted_at IS NULL AND is_dir = 0
                            ORDER BY name COLLATE utf8mb4_unicode_ci ASC");
        $st->execute([$uid]);
        $rows = $st->fetchAll();
    } else {
        $all = [];
        cd_collect_subtree($uid, $dirId, $all);
        $in = implode(',', array_fill(0, count($all), '?'));
        $st = $db->prepare("SELECT id, name, size FROM cd_nodes
                            WHERE owner_id = ? AND deleted_at IS NULL AND is_dir = 0 AND id IN ($in)
                            ORDER BY name COLLATE utf8mb4_unicode_ci ASC");
        $st->execute(array_merge([$uid], $all));
        $rows = $st->fetchAll();
    }
    $tracks = [];
    foreach ($rows as $r) {
        $ext = strtolower(pathinfo($r['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $audio, true)) {
            $tracks[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'size' => (int)$r['size']];
        }
    }
    if (count($tracks) > 500) {
        $tracks = array_slice($tracks, 0, 500);
    }
    return $tracks;
}

/* ---------- 目录与文件 ---------- */
if ($action === 'list') {
    $dirParam = $_GET['dir'] ?? '';
    $db = cd_db();
    if ($dirParam === '' || $dirParam === '0') {
        $parentId = null;
    } else {
        $parentId = (int)$dirParam;
        $node = cd_get_node($uid, $parentId);
        if (!$node['is_dir']) {
            cd_fail('目标不是文件夹');
        }
    }
    $sql = 'SELECT id, parent_id, name, is_dir, size, mime, created_at, updated_at FROM cd_nodes
            WHERE owner_id = ? AND deleted_at IS NULL AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?') . '
            ORDER BY is_dir DESC, name COLLATE utf8mb4_unicode_ci ASC';
    $st = $db->prepare($sql);
    $st->execute($parentId === null ? [$uid] : [$uid, $parentId]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'id' => (int)$r['id'],
            'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'],
            'name' => $r['name'],
            'is_dir' => (bool)$r['is_dir'],
            'size' => (int)$r['size'],
            'mime' => $r['mime'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }
    // 面包屑
    $crumbs = [];
    if ($parentId !== null) {
        $cur = $parentId;
        for ($i = 0; $i < 64; $i++) {
            $st = $db->prepare('SELECT id, parent_id, name FROM cd_nodes WHERE id = ? AND owner_id = ?');
            $st->execute([$cur, $uid]);
            $row = $st->fetch();
            if (!$row) break;
            array_unshift($crumbs, ['id' => (int)$row['id'], 'name' => $row['name']]);
            if ($row['parent_id'] === null) break;
            $cur = (int)$row['parent_id'];
        }
    }
    cd_ok(['dir' => $parentId, 'items' => $items, 'crumbs' => $crumbs]);
}

if ($action === 'mkdir') {
    cd_require_perm($me, 'mkdir');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $name = cd_clean_name((string)($body['name'] ?? ''));
    $parentParam = $body['parentId'] ?? null;
    $parentId = ($parentParam === null || $parentParam === '' || $parentParam === 0) ? null : (int)$parentParam;
    if ($parentId !== null) {
        $node = cd_get_node($uid, $parentId);
        if (!$node['is_dir']) cd_fail('父级不是文件夹');
    }
    $final = cd_unique_name($uid, $parentId, $name, true);
    $db = cd_db();
    $now = date('Y-m-d H:i:s');
    $db->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, created_at, updated_at) VALUES (?,?,?,1,0,?,?)')
        ->execute([$uid, $parentId, $final, $now, $now]);
    $newId = (int)$db->lastInsertId(); // 必须在 cd_log 之前取，否则取到的是日志表的 id
    cd_log($uid, 'mkdir', $final);
    cd_ok(['id' => $newId, 'name' => $final]);
}

if ($action === 'upload') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'upload');
    $parentParam = $_POST['parentId'] ?? '0';
    $parentId = ($parentParam === '' || $parentParam === '0') ? null : (int)$parentParam;
    if ($parentId !== null) {
        $node = cd_get_node($uid, $parentId);
        if (!$node['is_dir']) cd_fail('目标不是文件夹');
    }
    if (empty($_FILES['files'])) {
        // 区分"真没发文件"与"PHP 因超出 post_max_size 丢弃了整个 POST 体"
        // （后者的特征：请求体很大、进度能到 100%，但 $_FILES/$_POST 全空）
        $cl = (int)($_SERVER['CONTENT_LENGTH'] ?? ($_SERVER['HTTP_CONTENT_LENGTH'] ?? 0));
        if ($cl > 1024) {
            cd_fail(
                '服务器丢弃了上传数据：本次约 ' . round($cl / 1048576, 1) . 'MB，'
                . '超出 PHP 上传上限（当前 upload_max_filesize=' . (ini_get('upload_max_filesize') ?: '未知')
                . '，post_max_size=' . (ini_get('post_max_size') ?: '未知')
                . '）。请在主机面板「PHP 设置」把这两项调到大于文件体积（如 128M / 135M）后重试；'
                . 'Apache(mod_php)/LiteSpeed 主机 .htaccess 中的 php_value 亦可调整；'
                . '若已调大仍报此错，可能是主机 WAF/请求体限制拦截，请联系主机商。',
                413
            );
        }
        cd_fail('未收到文件');
    }
    $quota = (int)$me['quota_bytes'];
    $used = cd_used_bytes($uid);
    $db = cd_db();
    $now = date('Y-m-d H:i:s');
    $results = [];
    // 规范化 $_FILES：兼容多文件（数组结构）与单文件（标量结构）
    $raw = $_FILES['files'];
    $filesList = [];
    if (is_array($raw['name'])) {
        $cnt = count($raw['name']);
        for ($i = 0; $i < $cnt; $i++) {
            $filesList[] = [
                'name' => $raw['name'][$i],
                'tmp_name' => $raw['tmp_name'][$i],
                'size' => $raw['size'][$i],
                'error' => $raw['error'][$i],
            ];
        }
    } else {
        $filesList[] = [
            'name' => $raw['name'],
            'tmp_name' => $raw['tmp_name'],
            'size' => $raw['size'],
            'error' => $raw['error'],
        ];
    }
    foreach ($filesList as $f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errMap = [UPLOAD_ERR_INI_SIZE => '超出服务器限制', UPLOAD_ERR_FORM_SIZE => '超出表单限制',
                       UPLOAD_ERR_PARTIAL => '上传不完整', UPLOAD_ERR_NO_TMP_DIR => '临时目录缺失',
                       UPLOAD_ERR_CANT_WRITE => '磁盘写入失败'];
            $results[] = ['name' => $f['name'], 'ok' => false,
                          'error' => $errMap[$f['error']] ?? '上传失败'];
            continue;
        }
        $tmpPath = $f['tmp_name'];
        $origName = (string)$f['name'];
        // RFC 2047 encoded-word 兼容解码（.NET Framework HttpClient 等客户端会把中文文件名编码为 =?utf-8?B?...?=）
        if (preg_match('/^=\?[A-Za-z0-9_-]+\?[BQbq]\?.*\?=/', $origName)) {
            $decoded = @mb_decode_mimeheader($origName);
            if (is_string($decoded) && $decoded !== '' && $decoded !== $origName) {
                $origName = $decoded;
            }
        }
        $size = (int)($f['size'] ?? 0);
        if ($size <= 0) {
            $results[] = ['name' => $origName, 'ok' => false, 'error' => '空文件'];
            continue;
        }
        if ($used + $size > $quota) {
            $results[] = ['name' => $origName, 'ok' => false, 'error' => '超出存储配额'];
            continue;
        }
        $name = cd_clean_name($origName);
        // 物理存储名：随机 hex + 安全扩展
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
        $storageName = cd_random_hex(8) . '.' . ($ext !== '' ? $ext : 'bin');
        $dir = CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid;
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        if (!move_uploaded_file($tmpPath, cd_storage_path($uid, $storageName))) {
            $results[] = ['name' => $origName, 'ok' => false, 'error' => '保存失败'];
            continue;
        }
        $final = cd_unique_name($uid, $parentId, $name, false);
        $mime = mime_content_type(cd_storage_path($uid, $storageName)) ?: 'application/octet-stream';
        $db->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                      VALUES (?,?,?,0,?,?,?,?,?)')
            ->execute([$uid, $parentId, $final, $size, $mime, $storageName, $now, $now]);
        $used += $size;
        $results[] = ['name' => $final, 'ok' => true, 'id' => (int)$db->lastInsertId(), 'size' => $size];
        cd_log($uid, 'upload', $final . ' (' . $size . 'B)');
    }
    cd_ok(['results' => $results, 'used_bytes' => $used]);
}

/* ---------- 分片上传（绕过 post_max_size：大文件切片，每片单独请求，服务端追加合并） ---------- */
/** 分片任务文件名校验与路径解析（文件名带 u{uid}_ 前缀实现属主隔离） */
function cd_chunk_path(int $uid, string $id): string
{
    if (!preg_match('/^u' . $uid . '_[a-f0-9]{16}\.part$/', $id)) {
        cd_fail('分片任务不合法', 403);
    }
    return CD_TMP_DIR . DIRECTORY_SEPARATOR . $id;
}

if ($action === 'chunkinit') {
    cd_require_perm($me, 'upload');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $name = cd_clean_name((string)($body['name'] ?? ''));
    $size = (int)($body['size'] ?? 0);
    if ($size <= 0) cd_fail('文件大小不合法');
    $quota = (int)$me['quota_bytes'];
    if (cd_used_bytes($uid) + $size > $quota) cd_fail('超出存储配额');
    if (!is_dir(CD_TMP_DIR)) @mkdir(CD_TMP_DIR, 0700, true);
    // 清理超过 24 小时的残留分片任务
    foreach (glob(CD_TMP_DIR . DIRECTORY_SEPARATOR . 'u' . $uid . '_*.part') ?: [] as $pf) {
        if (@filemtime($pf) < time() - 86400) {
            @unlink($pf);
            @unlink($pf . '.meta');
        }
    }
    $uploadId = 'u' . $uid . '_' . cd_random_hex(8) . '.part';
    file_put_contents(CD_TMP_DIR . DIRECTORY_SEPARATOR . $uploadId, '');
    file_put_contents(
        CD_TMP_DIR . DIRECTORY_SEPARATOR . $uploadId . '.meta',
        json_encode(['name' => $name, 'size' => $size, 'parentId' => (int)($body['parentId'] ?? 0)], JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    cd_log($uid, 'chunk_init', $name . " ({$size}B)");
    cd_ok(['uploadId' => $uploadId]);
}

if ($action === 'chunk') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    $id = (string)($_GET['id'] ?? '');
    $path = cd_chunk_path($uid, $id);
    if (!is_file($path)) cd_fail('分片任务不存在或已失效，请重新上传', 404);
    $data = file_get_contents('php://input');
    if ($data === false || $data === '') {
        cd_fail('分片数据为空（可能超出 post_max_size）', 413);
    }
    $fp = fopen($path, 'ab');
    flock($fp, LOCK_EX);
    fwrite($fp, $data);
    flock($fp, LOCK_UN);
    fclose($fp);
    clearstatcache(true, $path);
    cd_ok(['received' => (int)filesize($path)]);
}

if ($action === 'chunkdone') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'upload');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (string)($body['id'] ?? '');
    $path = cd_chunk_path($uid, $id);
    $metaPath = $path . '.meta';
    if (!is_file($path) || !is_file($metaPath)) cd_fail('分片任务不存在或已失效，请重新上传', 404);
    $meta = json_decode((string)file_get_contents($metaPath), true) ?: [];
    $metaSize = (int)($meta['size'] ?? 0);
    $actual = (int)filesize($path);
    if ($metaSize <= 0 || $actual !== $metaSize) {
        @unlink($path);
        @unlink($metaPath);
        cd_fail('分片数据不完整（' . $actual . '/' . $metaSize . ' 字节），请重新上传');
    }
    $parentId = (int)($meta['parentId'] ?? 0);
    if ($parentId > 0) {
        $pnode = cd_get_node($uid, $parentId);
        if (!$pnode['is_dir']) cd_fail('目标文件夹不存在，请重新选择位置上传');
    } else {
        $parentId = null;
    }
    $name = cd_clean_name((string)($meta['name'] ?? '文件'));
    $final = cd_unique_name($uid, $parentId, $name, false);
    $ext = strtolower(pathinfo($final, PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
    $storageName = cd_random_hex(8) . '.' . ($ext !== '' ? $ext : 'bin');
    $dir = CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid;
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (!@rename($path, cd_storage_path($uid, $storageName))) {
        cd_fail('文件落盘失败，请重试');
    }
    @unlink($metaPath);
    $mime = mime_content_type(cd_storage_path($uid, $storageName)) ?: 'application/octet-stream';
    $now = date('Y-m-d H:i:s');
    $db = cd_db();
    $db->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                  VALUES (?,?,?,0,?,?,?,?,?)')
        ->execute([$uid, $parentId, $final, $metaSize, $mime, $storageName, $now, $now]);
    $newId = (int)$db->lastInsertId(); // 必须在 cd_log 之前取，否则取到的是日志表的 id
    cd_log($uid, 'chunk_done', $final . " ({$metaSize}B)");
    cd_ok(['id' => $newId, 'name' => $final, 'size' => $metaSize, 'used_bytes' => cd_used_bytes($uid)]);
}

if ($action === 'chunkabort') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (string)($body['id'] ?? '');
    $path = cd_chunk_path($uid, $id);
    @unlink($path);
    @unlink($path . '.meta');
    cd_ok();
}

/* ========== 压缩包：浏览 / 解压 / 压缩打包 ========== */
define('CD_ZIP_MAX_ENTRIES', 20000);        // 单次压缩/解压条目上限
define('CD_ZIP_MAX_UNCOMPRESS', 4294967296); // 单次解压体积上限 4GB

/** 压缩包文件校验：仅 ZIP、物理文件存在、服务器有 Zip 扩展，返回物理路径 */
function cd_zip_guard(array $node): string
{
    if ((int)$node['is_dir'] === 1) {
        cd_fail('请选择压缩包文件');
    }
    $ext = strtolower(pathinfo((string)$node['name'], PATHINFO_EXTENSION));
    if ($ext !== 'zip') {
        cd_fail('仅支持 ZIP 格式压缩包');
    }
    if (!class_exists('ZipArchive')) {
        cd_fail('服务器缺少 Zip 扩展，无法处理压缩包');
    }
    $path = cd_storage_path((int)$node['owner_id'], (string)$node['storage_name']);
    if (!is_file($path)) {
        cd_fail('压缩包数据已丢失', 404);
    }
    return $path;
}

/** 解压/压缩时清洗单个路径段（压缩包内名称不可信） */
function cd_zip_seg(string $raw): string
{
    $s = cd_zip_name($raw);
    $s = str_replace(['\\', '/', ':', '*', '?', '"', '<', '>', '|'], '_', $s);
    $s = preg_replace('/[\x00-\x1f\x7f]/u', '', $s);
    $s = $s === null ? '' : trim($s);
    $s = rtrim($s, '. ');
    if ($s === '') {
        $s = '_';
    }
    return mb_substr($s, 0, 200);
}

/** 读取压缩包条目目录（浏览用） */
if ($action === 'ziplist') {
    cd_require_perm($me, 'preview');
    $node = cd_get_node($uid, (int)($_GET['id'] ?? 0));
    $path = cd_zip_guard($node);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        cd_fail('压缩包无法打开（可能损坏或加密）');
    }
    $total = (int)$zip->numFiles;
    $limit = 3000;
    $scan = min($total, CD_ZIP_MAX_ENTRIES);
    $items = [];
    $dirs = 0;
    $files = 0;
    $uncompressed = 0;
    for ($i = 0; $i < $scan; $i++) {
        $st = $zip->statIndex($i);
        if (!$st) {
            continue;
        }
        $raw = (string)$st['name'];
        $name = cd_zip_name($raw);
        $isDir = substr($raw, -1) === '/';
        if ($isDir) {
            $dirs++;
        } else {
            $files++;
            $uncompressed += (int)$st['size'];
        }
        if (count($items) < $limit) {
            $items[] = [
                'name' => $name,
                'dir' => $isDir,
                'size' => (int)$st['size'],
                'mtime' => (int)$st['mtime'],
            ];
        }
    }
    $zip->close();
    // 文件夹优先，其次按名称排序（保持可读性）
    usort($items, function ($a, $b) {
        if ($a['dir'] !== $b['dir']) {
            return $a['dir'] ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    cd_ok([
        'id' => (int)$node['id'],
        'name' => $node['name'],
        'total' => $total,
        'dirs' => $dirs,
        'files' => $files,
        'uncompressed' => $uncompressed,
        'items' => $items,
        'truncated' => $total > $limit,
    ]);
}

/** 解压到当前目录或新建文件夹（需上传权限，解压相当于批量写入文件） */
if ($action === 'zipextract') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'upload');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $mode = (string)($body['mode'] ?? 'folder') === 'here' ? 'here' : 'folder';
    $parentParam = $body['parentId'] ?? null;
    $parentId = ($parentParam === null || $parentParam === '' || (int)$parentParam === 0) ? null : (int)$parentParam;
    if ($parentId !== null) {
        $pn = cd_get_node($uid, $parentId);
        if (!$pn['is_dir']) {
            cd_fail('目标不是文件夹');
        }
    }
    $node = cd_get_node($uid, $id);
    $path = cd_zip_guard($node);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        cd_fail('压缩包无法打开（可能损坏或加密）');
    }
    $total = (int)$zip->numFiles;
    if ($total > CD_ZIP_MAX_ENTRIES) {
        $zip->close();
        cd_fail('压缩包条目过多（' . $total . ' 项），超过 ' . CD_ZIP_MAX_ENTRIES . ' 项上限');
    }
    // 预估解压体积 + 配额预检（防止解压中途超限）
    $need = 0;
    for ($i = 0; $i < $total; $i++) {
        $st = $zip->statIndex($i);
        if ($st && substr((string)$st['name'], -1) !== '/') {
            $need += (int)$st['size'];
        }
    }
    if ($need > CD_ZIP_MAX_UNCOMPRESS) {
        $zip->close();
        cd_fail('解压体积约 ' . round($need / 1073741824, 1) . ' GB，超过单次 4GB 上限');
    }
    $quota = (int)$me['quota_bytes'];
    $used = cd_used_bytes($uid);
    if ($used + $need > $quota) {
        $zip->close();
        cd_fail('解压后超出存储配额（需约 ' . round($need / 1048576, 1) . ' MB，剩余 '
            . round(max(0, $quota - $used) / 1048576, 1) . ' MB）');
    }

    $db = cd_db();
    $now = date('Y-m-d H:i:s');
    $rootDir = $parentId;
    $rootName = null;
    if ($mode === 'folder') {
        $base = pathinfo((string)$node['name'], PATHINFO_FILENAME);
        $base = cd_zip_seg($base === '' ? '解压文件' : $base);
        $final = cd_unique_name($uid, $parentId, $base, true);
        $db->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, created_at, updated_at)
                      VALUES (?,?,?,1,0,?,?)')
            ->execute([$uid, $parentId, $final, $now, $now]);
        $rootDir = (int)$db->lastInsertId();
        $rootName = $final;
    }

    $dirMap = [];
    /** 按需创建（或复用）目录节点，返回目录 id（$parent 为 null 表示网盘根目录） */
    $ensureDir = function ($parent, string $seg) use ($uid, $db, $now, &$dirMap): int {
        $clean = cd_zip_seg($seg);
        $key = ($parent ?? 0) . '|' . mb_strtolower($clean);
        if (isset($dirMap[$key])) {
            return $dirMap[$key];
        }
        $parentIdForInsert = $parent === null ? null : (int)$parent;
        $final = cd_unique_name($uid, $parentIdForInsert, $clean, true);
        $db->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, created_at, updated_at)
                      VALUES (?,?,?,1,0,?,?)')
            ->execute([$uid, $parentIdForInsert, $final, $now, $now]);
        $id = (int)$db->lastInsertId();
        $dirMap[$key] = $id;
        return $id;
    };

    $storageDir = CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid;
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }
    $okFiles = 0;
    $skipped = 0;
    $bytes = 0;
    for ($i = 0; $i < $total; $i++) {
        $st = $zip->statIndex($i);
        if (!$st) {
            $skipped++;
            continue;
        }
        $rawName = (string)$st['name'];
        $name = str_replace('\\', '/', cd_zip_name($rawName));
        $isDir = substr($name, -1) === '/';
        $trim = rtrim($name, '/');
        if ($trim === '') {
            continue;
        }
        // 跳过 macOS 资源目录（打包残留物）
        if ($trim === '__MACOSX' || str_starts_with($trim, '__MACOSX/')) {
            $skipped++;
            continue;
        }
        $segs = array_values(array_filter(explode('/', $trim), function ($s) {
            return $s !== '' && $s !== '.';
        }));
        if (!$segs || in_array('..', $segs, true)) {
            $skipped++; // 路径穿越防护
            continue;
        }
        $cur = $rootDir;
        $last = count($segs) - 1;
        foreach ($segs as $k => $seg) {
            if ($k < $last || $isDir) {
                $cur = $ensureDir($cur, $seg);
                continue;
            }
            // 普通文件：从压缩包流式写出到物理存储
            $clean = cd_zip_seg($seg);
            $in = @$zip->getStream($rawName);
            if (!$in) {
                $skipped++; // 加密条目或损坏
                break;
            }
            $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
            $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
            $storageName = cd_random_hex(8) . '.' . ($ext !== '' ? $ext : 'bin');
            $dstPath = cd_storage_path($uid, $storageName);
            $fp = @fopen($dstPath, 'wb');
            if (!$fp) {
                fclose($in);
                $skipped++;
                break;
            }
            $written = stream_copy_to_stream($in, $fp);
            fclose($in);
            fclose($fp);
            if ($written === false || $written <= 0) {
                @unlink($dstPath);
                $skipped++;
                break;
            }
            if ($used + $bytes + (int)$written > $quota) {
                // 预检已通过，此处为极端情况兜底
                @unlink($dstPath);
                $zip->close();
                cd_fail('解压中途超出存储配额，已停止并保留已解压部分');
            }
            $final = cd_unique_name($uid, $cur, $clean, false);
            $mime = mime_content_type($dstPath) ?: 'application/octet-stream';
            $db->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                          VALUES (?,?,?,0,?,?,?,?,?)')
                ->execute([$uid, $cur, $final, (int)$written, $mime, $storageName, $now, $now]);
            $okFiles++;
            $bytes += (int)$written;
            break;
        }
    }
    $zip->close();
    cd_log($uid, 'zipextract', $node['name'] . ' files=' . $okFiles . ' skipped=' . $skipped . ' mode=' . $mode);
    cd_ok([
        'files' => $okFiles,
        'skipped' => $skipped,
        'bytes' => $bytes,
        'mode' => $mode,
        'folder' => $rootName,
        'folderId' => $rootDir,
    ]);
}

/** 把选中的文件/文件夹压缩为 ZIP，存到指定目录（默认当前目录） */
if ($action === 'zipcreate') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'upload');
    if (!class_exists('ZipArchive')) cd_fail('服务器缺少 Zip 扩展，无法压缩');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $ids = array_values(array_unique(array_map('intval', (array)($body['ids'] ?? []))));
    if (!$ids) cd_fail('未选择任何项目');
    if (count($ids) > 200) cd_fail('单次最多压缩 200 个项目');
    $parentParam = $body['parentId'] ?? null;
    $parentId = ($parentParam === null || $parentParam === '' || (int)$parentParam === 0) ? null : (int)$parentParam;
    if ($parentId !== null) {
        $pn = cd_get_node($uid, $parentId);
        if (!$pn['is_dir']) cd_fail('目标不是文件夹');
    }
    $nodes = [];
    foreach ($ids as $id) {
        $nodes[] = cd_get_node($uid, $id);
    }
    if (!is_dir(CD_TMP_DIR)) {
        @mkdir(CD_TMP_DIR, 0700, true);
    }
    $tmpZip = CD_TMP_DIR . DIRECTORY_SEPARATOR . 'pack_' . cd_random_hex(8) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        cd_fail('创建压缩包失败');
    }
    $cnt = 0;
    $addTree = function (int $dirId, string $prefix) use ($zip, $uid, &$addTree, &$cnt): void {
        if ($cnt >= CD_ZIP_MAX_ENTRIES) {
            return;
        }
        $st = cd_db()->prepare('SELECT id, name, is_dir, storage_name FROM cd_nodes
                                WHERE parent_id = ? AND owner_id = ? AND deleted_at IS NULL
                                ORDER BY is_dir DESC, name');
        $st->execute([$dirId, $uid]);
        foreach ($st->fetchAll() as $r) {
            if ($cnt >= CD_ZIP_MAX_ENTRIES) {
                return;
            }
            $n = (string)$r['name'];
            if ((int)$r['is_dir'] === 1) {
                $zip->addEmptyDir($prefix . $n);
                $cnt++;
                $addTree((int)$r['id'], $prefix . $n . '/');
            } else {
                $p = cd_storage_path($uid, (string)$r['storage_name']);
                if (is_file($p)) {
                    $zip->addFile($p, $prefix . $n);
                    $cnt++;
                }
            }
        }
    };
    foreach ($nodes as $n) {
        if ((int)$n['is_dir'] === 1) {
            $zip->addEmptyDir((string)$n['name']);
            $cnt++;
            $addTree((int)$n['id'], $n['name'] . '/');
        } else {
            $p = cd_storage_path($uid, (string)$n['storage_name']);
            if (is_file($p)) {
                $zip->addFile($p, (string)$n['name']);
                $cnt++;
            }
        }
    }
    if ($cnt === 0) {
        $zip->close();
        @unlink($tmpZip);
        cd_fail('所选项目没有可打包的文件');
    }
    if ($zip->close() !== true) {
        @unlink($tmpZip);
        cd_fail('压缩包写入失败（磁盘空间或权限问题）');
    }
    clearstatcache(true, $tmpZip);
    $size = (int)@filesize($tmpZip);
    if ($size <= 0) {
        @unlink($tmpZip);
        cd_fail('压缩包为空或写入失败');
    }
    $quota = (int)$me['quota_bytes'];
    if (cd_used_bytes($uid) + $size > $quota) {
        @unlink($tmpZip);
        cd_fail('压缩包大小 ' . round($size / 1048576, 1) . ' MB，超出剩余存储配额');
    }
    // 命名：单项目取原名称（文件去扩展名），多项为「打包文件-时间戳」
    if (count($nodes) === 1) {
        $base = (int)$nodes[0]['is_dir'] === 1
            ? (string)$nodes[0]['name']
            : pathinfo((string)$nodes[0]['name'], PATHINFO_FILENAME);
    } else {
        $base = '打包文件-' . date('Ymd-His');
    }
    $base = cd_zip_seg($base === '' ? '打包文件' : $base);
    $zipName = mb_substr($base, 0, 190) . '.zip';
    $final = cd_unique_name($uid, $parentId, $zipName, false);
    $storageName = cd_random_hex(8) . '.zip';
    $dstPath = cd_storage_path($uid, $storageName);
    $storageDir = CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid;
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }
    if (!@rename($tmpZip, $dstPath)) {
        if (!@copy($tmpZip, $dstPath)) {
            @unlink($tmpZip);
            cd_fail('保存压缩包失败');
        }
        @unlink($tmpZip);
    }
    $now = date('Y-m-d H:i:s');
    $db = cd_db();
    $db->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                  VALUES (?,?,?,0,?,?,?,?,?)')
        ->execute([$uid, $parentId, $final, $size, 'application/zip', $storageName, $now, $now]);
    $newId = (int)$db->lastInsertId();
    cd_log($uid, 'zipcreate', $final . ' items=' . $cnt . ' size=' . $size);
    cd_ok(['id' => $newId, 'name' => $final, 'size' => $size, 'items' => $cnt]);
}

if ($action === 'rename') {
    cd_require_perm($me, 'rename');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $name = cd_clean_name((string)($body['name'] ?? ''));
    $node = cd_get_node($uid, $id);
    $final = cd_unique_name($uid, $node['parent_id'] === null ? null : (int)$node['parent_id'], $name, (bool)$node['is_dir'], $id);
    cd_db()->prepare('UPDATE cd_nodes SET name = ?, updated_at = ? WHERE id = ?')
        ->execute([$final, date('Y-m-d H:i:s'), $id]);
    cd_log($uid, 'rename', $node['name'] . ' -> ' . $final);
    cd_ok(['name' => $final]);
}

if ($action === 'move') {
    cd_require_perm($me, 'move');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $ids = array_map('intval', (array)($body['ids'] ?? []));
    $targetParam = $body['target'] ?? null;
    $target = ($targetParam === null || $targetParam === '' || $targetParam === 0) ? null : (int)$targetParam;
    if (!$ids) cd_fail('未选择任何项目');
    if ($target !== null) {
        $tNode = cd_get_node($uid, $target);
        if (!$tNode['is_dir']) cd_fail('目标不是文件夹');
        // 目标不能是所选文件夹自身或其子树
        foreach ($ids as $id) {
            if ($id === $target || cd_in_subtree($uid, $id, $target)) {
                cd_fail('不能将文件夹移动到其自身内部');
            }
        }
    }
    $db = cd_db();
    $now = date('Y-m-d H:i:s');
    $moved = 0;
    $st = $db->prepare('UPDATE cd_nodes SET parent_id = ?, updated_at = ? WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    foreach ($ids as $id) {
        $node = cd_get_node($uid, $id);
        // 目标下重名自动改名
        $final = cd_unique_name($uid, $target, $node['name'], (bool)$node['is_dir'], $id);
        $db->prepare('UPDATE cd_nodes SET name = ? WHERE id = ?')->execute([$final, $id]);
        $st->execute([$target, $now, $id, $uid]);
        $moved++;
    }
    cd_log($uid, 'move', 'moved=' . $moved . ' target=' . ($target === null ? 'root' : $target));
    cd_ok(['moved' => $moved]);
}

/* ---------- 回收站 ---------- */
// 递归收集子树 id
function cd_collect_subtree(int $uid, int $rootId, array &$out): void
{
    $out[] = $rootId;
    $st = cd_db()->prepare('SELECT id FROM cd_nodes WHERE parent_id = ? AND owner_id = ?');
    $st->execute([$rootId, $uid]);
    foreach ($st->fetchAll() as $r) {
        cd_collect_subtree($uid, (int)$r['id'], $out);
    }
}

if ($action === 'trash') {
    cd_require_perm($me, 'trash');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $ids = array_map('intval', (array)($body['ids'] ?? []));
    if (!$ids) cd_fail('未选择任何项目');
    $db = cd_db();
    $now = date('Y-m-d H:i:s');
    $all = [];
    foreach ($ids as $id) {
        cd_get_node($uid, $id);
        cd_collect_subtree($uid, $id, $all);
    }
    $in = implode(',', array_fill(0, count($all), '?'));
    $db->prepare("UPDATE cd_nodes SET deleted_at = ? WHERE id IN ($in) AND owner_id = ? AND deleted_at IS NULL")
        ->execute(array_merge([$now], $all, [$uid]));
    cd_log($uid, 'trash', 'ids=' . implode(',', $ids));
    cd_ok(['count' => count($all)]);
}

if ($action === 'recycle') {
    $db = cd_db();
    $st = $db->prepare("SELECT id, parent_id, name, is_dir, size, deleted_at, updated_at FROM cd_nodes
                        WHERE owner_id = ? AND deleted_at IS NOT NULL
                        ORDER BY deleted_at DESC LIMIT 500");
    $st->execute([$uid]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'id' => (int)$r['id'],
            'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'],
            'name' => $r['name'],
            'is_dir' => (bool)$r['is_dir'],
            'size' => (int)$r['size'],
            'deleted_at' => $r['deleted_at'],
        ];
    }
    cd_ok(['items' => $items]);
}

if ($action === 'restore') {
    cd_require_perm($me, 'recycle');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $ids = array_map('intval', (array)($body['ids'] ?? []));
    if (!$ids) cd_fail('未选择任何项目');
    $db = cd_db();
    $restored = 0;
    $now = date('Y-m-d H:i:s');
    foreach ($ids as $id) {
        $node = cd_get_node($uid, $id, true);
        if ($node['deleted_at'] === null) continue;
        // 父目录不存在或仍在回收站 → 恢复到根
        $parentId = $node['parent_id'];
        if ($parentId !== null) {
            $st = $db->prepare('SELECT is_dir, deleted_at FROM cd_nodes WHERE id = ? AND owner_id = ?');
            $st->execute([$parentId, $uid]);
            $p = $st->fetch();
            if (!$p || $p['deleted_at'] !== null || !$p['is_dir']) $parentId = null;
        }
        // 恢复整个子树
        $all = [];
        cd_collect_subtree($uid, $id, $all);
        $in = implode(',', array_fill(0, count($all), '?'));
        $db->prepare("UPDATE cd_nodes SET deleted_at = NULL, updated_at = ? WHERE id IN ($in) AND owner_id = ?")
            ->execute(array_merge([$now], $all, [$uid]));
        $db->prepare('UPDATE cd_nodes SET parent_id = ? WHERE id = ?')->execute([$parentId, $id]);
        // 恢复后与现存重名 → 自动改名
        $final = cd_unique_name($uid, $parentId, $node['name'], (bool)$node['is_dir'], $id);
        if ($final !== $node['name']) {
            $db->prepare('UPDATE cd_nodes SET name = ? WHERE id = ?')->execute([$final, $id]);
        }
        $restored++;
    }
    cd_log($uid, 'restore', 'ids=' . implode(',', $ids));
    cd_ok(['restored' => $restored]);
}

if ($action === 'purge') {
    cd_require_perm($me, 'recycle');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $ids = array_map('intval', (array)($body['ids'] ?? []));
    if (!$ids) cd_fail('未选择任何项目');
    $db = cd_db();
    $freed = 0;
    foreach ($ids as $id) {
        $node = cd_get_node($uid, $id, true);
        if ($node['deleted_at'] === null) cd_fail('只能彻底删除回收站中的项目');
        $all = [];
        cd_collect_subtree($uid, $id, $all);
        $in = implode(',', array_fill(0, count($all), '?'));
        // 找出物理文件并删除
        $st = $db->prepare("SELECT storage_name FROM cd_nodes WHERE id IN ($in) AND owner_id = ? AND is_dir = 0 AND storage_name IS NOT NULL");
        $st->execute(array_merge($all, [$uid]));
        foreach ($st->fetchAll() as $r) {
            $path = cd_storage_path($uid, $r['storage_name']);
            if (is_file($path)) {
                $freed += (int)@filesize($path);
                @unlink($path);
            }
        }
        $db->prepare("DELETE FROM cd_shares WHERE node_id IN ($in)")->execute($all);
        $db->prepare("DELETE FROM cd_nodes WHERE id IN ($in) AND owner_id = ?")->execute(array_merge($all, [$uid]));
    }
    cd_log($uid, 'purge', 'ids=' . implode(',', $ids) . " freed={$freed}");
    cd_ok(['freed' => $freed]);
}

if ($action === 'emptyRecycle') {
    cd_require_perm($me, 'recycle');
    $db = cd_db();
    $st = $db->prepare('SELECT id FROM cd_nodes WHERE owner_id = ? AND deleted_at IS NOT NULL');
    $st->execute([$uid]);
    $ids = array_map('intval', array_column($st->fetchAll(), 'id'));
    if (!$ids) cd_ok(['freed' => 0]);
    $freed = 0;
    foreach ($ids as $id) {
        $all = [];
        cd_collect_subtree($uid, $id, $all);
        $in = implode(',', array_fill(0, count($all), '?'));
        $st2 = $db->prepare("SELECT storage_name FROM cd_nodes WHERE id IN ($in) AND owner_id = ? AND is_dir = 0 AND storage_name IS NOT NULL");
        $st2->execute(array_merge($all, [$uid]));
        foreach ($st2->fetchAll() as $r) {
            $path = cd_storage_path($uid, $r['storage_name']);
            if (is_file($path)) {
                $freed += (int)@filesize($path);
                @unlink($path);
            }
        }
        $db->prepare("DELETE FROM cd_shares WHERE node_id IN ($in)")->execute($all);
        $db->prepare("DELETE FROM cd_nodes WHERE id IN ($in) AND owner_id = ?")->execute(array_merge($all, [$uid]));
    }
    cd_log($uid, 'empty_recycle', "freed={$freed}");
    cd_ok(['freed' => $freed]);
}

/* ---------- 搜索 / 最近 ---------- */
if ($action === 'search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') cd_fail('请输入搜索关键词');
    $db = cd_db();
    $st = $db->prepare('SELECT id, parent_id, name, is_dir, size, updated_at FROM cd_nodes
                        WHERE owner_id = ? AND deleted_at IS NULL AND name LIKE ?
                        ORDER BY is_dir DESC, updated_at DESC LIMIT 200');
    $st->execute([$uid, '%' . $q . '%']);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'id' => (int)$r['id'], 'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'],
            'name' => $r['name'], 'is_dir' => (bool)$r['is_dir'], 'size' => (int)$r['size'], 'updated_at' => $r['updated_at'],
        ];
    }
    cd_ok(['items' => $items, 'q' => $q]);
}

if ($action === 'recent') {
    $db = cd_db();
    $st = $db->prepare('SELECT id, parent_id, name, is_dir, size, updated_at FROM cd_nodes
                        WHERE owner_id = ? AND deleted_at IS NULL AND is_dir = 0
                        ORDER BY updated_at DESC LIMIT 30');
    $st->execute([$uid]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'id' => (int)$r['id'], 'parent_id' => $r['parent_id'] === null ? null : (int)$r['parent_id'],
            'name' => $r['name'], 'is_dir' => false, 'size' => (int)$r['size'], 'updated_at' => $r['updated_at'],
        ];
    }
    cd_ok(['items' => $items]);
}

/* ---------- 分享 ---------- */
if ($action === 'share') {
    cd_require_perm($me, 'share');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $node = cd_get_node($uid, $id);
    $code = trim((string)($body['extractCode'] ?? ''));
    if ($code !== '' && !preg_match('/^[A-Za-z0-9]{1,16}$/', $code)) cd_fail('提取码只能是 1-16 位字母数字');
    $days = (int)($body['days'] ?? 0); // 0 = 永久
    if ($days < 0 || $days > 3650) cd_fail('有效期不合法');
    $token = cd_random_hex(16);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
    $db = cd_db();
    $db->prepare('INSERT INTO cd_shares (token, node_id, owner_id, extract_code, expires_at, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$token, $id, $uid, $code !== '' ? $code : null, $expires, date('Y-m-d H:i:s')]);
    cd_log($uid, 'share', $node['name'] . ' token=' . substr($token, 0, 8) . '...');
    cd_ok([
        'token' => $token,
        'url' => 'share.php?s=' . $token,
        'extract_code' => $code !== '' ? $code : null,
        'expires_at' => $expires,
    ]);
}

if ($action === 'shares') {
    $db = cd_db();
    $st = $db->prepare('SELECT s.id, s.token, s.node_id, s.extract_code, s.expires_at, s.view_count, s.download_count, s.created_at,
                               n.name, n.is_dir, n.size, n.deleted_at AS node_deleted_at
                        FROM cd_shares s JOIN cd_nodes n ON n.id = s.node_id
                        WHERE s.owner_id = ? AND s.revoked = 0
                        ORDER BY s.created_at DESC');
    $st->execute([$uid]);
    $items = [];
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'id' => (int)$r['id'],
            'token' => $r['token'],
            'node_id' => (int)$r['node_id'],
            'name' => $r['name'],
            'is_dir' => (bool)$r['is_dir'],
            'size' => (int)$r['size'],
            'has_code' => $r['extract_code'] !== null,
            'extract_code' => $r['extract_code'], // 明文返回：分享列表直接展示，便于复制告知访客
            'expires_at' => $r['expires_at'],
            'view_count' => (int)$r['view_count'],
            'download_count' => (int)$r['download_count'],
            'created_at' => $r['created_at'],
            'expired' => $r['expires_at'] !== null && strtotime($r['expires_at']) < time(),
            'deleted' => $r['node_deleted_at'] !== null,
        ];
    }
    cd_ok(['items' => $items]);
}

if ($action === 'unshare') {
    cd_require_perm($me, 'share');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $db = cd_db();
    $st = $db->prepare('UPDATE cd_shares SET revoked = 1 WHERE id = ? AND owner_id = ?');
    $st->execute([$id, $uid]);
    cd_log($uid, 'unshare', 'share_id=' . $id);
    cd_ok();
}

/* ---------- 统计 ---------- */
if ($action === 'stats') {
    $db = cd_db();
    $quota = (int)$me['quota_bytes'];
    $used = cd_used_bytes($uid);
    $st = $db->prepare('SELECT COUNT(*) FROM cd_nodes WHERE owner_id = ? AND deleted_at IS NULL AND is_dir = 0');
    $st->execute([$uid]);
    $fileCount = (int)$st->fetchColumn();
    $st = $db->prepare('SELECT COUNT(*) FROM cd_nodes WHERE owner_id = ? AND deleted_at IS NULL AND is_dir = 1');
    $st->execute([$uid]);
    $dirCount = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT mime, COUNT(*) c, COALESCE(SUM(size),0) s FROM cd_nodes
                        WHERE owner_id = ? AND deleted_at IS NULL AND is_dir = 0 GROUP BY mime");
    $st->execute([$uid]);
    // 分类聚合
    $cats = ['图片' => 0, '视频' => 0, '音频' => 0, '文档' => 0, '压缩包' => 0, '其他' => 0];
    $catCount = ['图片' => 0, '视频' => 0, '音频' => 0, '文档' => 0, '压缩包' => 0, '其他' => 0];
    $extMap = function (array $r): string {
        $m = strtolower((string)$r['mime']);
        $name = ''; // mime 为准，足够
        if (str_starts_with($m, 'image/')) return '图片';
        if (str_starts_with($m, 'video/')) return '视频';
        if (str_starts_with($m, 'audio/')) return '音频';
        if (in_array($m, ['application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/html', 'text/markdown', 'text/csv', 'text/x-php'], true)) return '文档';
        if (in_array($m, ['application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
            'application/gzip', 'application/x-tar'], true)) return '压缩包';
        return '其他';
    };
    foreach ($st->fetchAll() as $r) {
        $c = $extMap($r);
        $cats[$c] += (int)$r['s'];
        $catCount[$c] += (int)$r['c'];
    }
    cd_ok([
        'quota_bytes' => $quota,
        'used_bytes' => $used,
        'file_count' => $fileCount,
        'dir_count' => $dirCount,
        'categories' => $cats,
        'category_counts' => $catCount,
    ]);
}

/* ========== 角色管理 ========== */
if ($action === 'roles') {
    // 账号管理员编辑账号时也需要拉取角色列表（只读）
    if (!in_array('manage_roles', cd_user_perms($me), true) && !in_array('manage_users', cd_user_perms($me), true)) {
        cd_fail('当前账号无「角色管理」权限', 403);
    }
    $db = cd_db();
    $rows = $db->query('SELECT r.*, (SELECT COUNT(*) FROM cd_users u WHERE u.role_id = r.id) AS user_count FROM cd_roles r ORDER BY r.built_in DESC, r.id ASC')->fetchAll();
    $items = [];
    foreach ($rows as $r) {
        $perms = json_decode((string)$r['perms'], true) ?: [];
        $items[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'memo' => $r['memo'],
            'perms' => array_values(array_filter($perms, fn($p) => isset(CD_PERMS[$p]))),
            'built_in' => (bool)$r['built_in'],
            'user_count' => (int)$r['user_count'],
        ];
    }
    cd_ok(['items' => $items, 'catalog' => CD_PERMS]);
}

if ($action === 'saverole') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'manage_roles');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $name = cd_clean_name((string)($body['name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 32) cd_fail('角色名需 2~32 个字符');
    $memo = mb_substr(trim((string)($body['memo'] ?? '')), 0, 100);
    $permsIn = array_values(array_unique((array)($body['perms'] ?? [])));
    $perms = [];
    foreach ($permsIn as $p) {
        if (is_string($p) && isset(CD_PERMS[$p])) $perms[] = $p;
    }
    if (!$perms) cd_fail('请至少勾选一项权限');
    $db = cd_db();
    $now = date('Y-m-d H:i:s');
    if ($id > 0) {
        $role = cd_get_role($id);
        if (!$role) cd_fail('角色不存在', 404);
        if ($role['built_in']) {
            // 内置角色：仅允许调整权限，名称与说明不可改（保证系统语义稳定）
            $db->prepare('UPDATE cd_roles SET perms = ? WHERE id = ?')->execute([json_encode($perms), $id]);
            cd_log($uid, 'saverole', 'built-in id=' . $id . ' perms=' . implode(',', $perms));
            cd_ok(['id' => $id, 'name' => $role['name']]);
        }
        $st = $db->prepare('SELECT id FROM cd_roles WHERE name = ? AND id <> ?');
        $st->execute([$name, $id]);
        if ($st->fetch()) cd_fail('已存在同名角色');
        $db->prepare('UPDATE cd_roles SET name = ?, memo = ?, perms = ? WHERE id = ?')
            ->execute([$name, $memo, json_encode($perms), $id]);
        cd_log($uid, 'saverole', 'id=' . $id . ' name=' . $name . ' perms=' . implode(',', $perms));
        cd_ok(['id' => $id, 'name' => $name]);
    }
    $st = $db->prepare('SELECT id FROM cd_roles WHERE name = ?');
    $st->execute([$name]);
    if ($st->fetch()) cd_fail('已存在同名角色');
    $db->prepare('INSERT INTO cd_roles (name, memo, perms, built_in, created_at) VALUES (?,?,?,?,?)')
        ->execute([$name, $memo, json_encode($perms), 0, $now]);
    $newId = (int)$db->lastInsertId(); // 在 cd_log 之前取，避免取到日志表 id
    cd_log($uid, 'saverole', 'created id=' . $newId . ' name=' . $name . ' perms=' . implode(',', $perms));
    cd_ok(['id' => $newId, 'name' => $name]);
}

if ($action === 'delrole') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'manage_roles');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $role = cd_get_role($id);
    if (!$role) cd_fail('角色不存在', 404);
    if ($role['built_in']) cd_fail('内置角色（管理员/成员/访客）不可删除');
    $db = cd_db();
    $st = $db->prepare('SELECT COUNT(*) FROM cd_users WHERE role_id = ?');
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) cd_fail('仍有账号使用该角色，请先调整这些账号的角色');
    $db->prepare('DELETE FROM cd_roles WHERE id = ?')->execute([$id]);
    cd_log($uid, 'delrole', 'id=' . $id . ' name=' . $role['name']);
    cd_ok();
}

/* ========== 账号管理 ========== */
if ($action === 'users') {
    cd_require_perm($me, 'manage_users');
    $db = cd_db();
    $rows = $db->query('SELECT u.id, u.username, u.quota_bytes, u.is_admin, u.role_id, u.created_at, u.last_login_at,
                               r.name AS role_name, r.perms AS role_perms
                        FROM cd_users u LEFT JOIN cd_roles r ON r.id = u.role_id
                        ORDER BY u.id ASC')->fetchAll();
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int)$r['id'],
            'username' => $r['username'],
            'quota_bytes' => (int)$r['quota_bytes'],
            'used_bytes' => cd_used_bytes((int)$r['id']),
            'is_admin' => (bool)$r['is_admin'],
            'role_id' => $r['role_id'] === null ? null : (int)$r['role_id'],
            'role_name' => $r['role_name'] ?: '成员',
            'created_at' => $r['created_at'],
            'last_login_at' => $r['last_login_at'],
        ];
    }
    cd_ok(['items' => $items]);
}

if ($action === 'saveuser') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'manage_users');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $username = trim((string)($body['username'] ?? ''));
    if ($username === '' || mb_strlen($username) > 64) cd_fail('账号名不能为空且不超过 64 字符');
    if (!preg_match('/^[\w\-.@\x{4e00}-\x{9fa5}]+$/u', $username)) cd_fail('账号名仅支持字母数字下划线与中文');
    $password = (string)($body['password'] ?? '');
    $roleIdParam = $body['roleId'] ?? null; // ?? 兜底，避免缺键时 PHP Warning 污染 JSON 输出
    $roleId = ($roleIdParam === null || $roleIdParam === '' || (int)$roleIdParam === 0) ? null : (int)$roleIdParam;
    $isAdmin = !empty($body['isAdmin']) && (int)$body['isAdmin'] === 1 ? 1 : 0;
    $quotaGb = (float)($body['quotaGb'] ?? 10);
    if ($quotaGb < 0.1 || $quotaGb > 51200) cd_fail('配额需在 0.1GB ~ 51200GB(50TB) 之间');
    $quota = (int)round($quotaGb * 1073741824);
    $db = cd_db();
    $now = date('Y-m-d H:i:s');

    // 目标角色必须真实存在
    if ($roleId !== null) {
        if (!cd_get_role($roleId)) cd_fail('所选角色不存在', 404);
    }
    // 不能把唯一管理员降为普通账号
    if ($id > 0 && $isAdmin === 0) {
        $target = $db->prepare('SELECT is_admin FROM cd_users WHERE id = ?');
        $target->execute([$id]);
        $wasAdmin = (int)$target->fetchColumn();
        if ($wasAdmin === 1) {
            $st = $db->prepare('SELECT COUNT(*) FROM cd_users WHERE is_admin = 1 AND id <> ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() === 0) cd_fail('系统至少需要保留一个管理员');
        }
    }

    if ($id > 0) {
        $st = $db->prepare('SELECT id FROM cd_users WHERE username = ? AND id <> ?');
        $st->execute([$username, $id]);
        if ($st->fetch()) cd_fail('账号名已被占用');
        $st = $db->prepare('SELECT id FROM cd_users WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetch()) cd_fail('账号不存在', 404);
        if ($password !== '' && strlen($password) < 6) cd_fail('密码至少 6 位');
        if ($password !== '') {
            $db->prepare('UPDATE cd_users SET username = ?, quota_bytes = ?, is_admin = ?, role_id = ?, password_hash = ? WHERE id = ?')
                ->execute([$username, $quota, $isAdmin, $roleId ?: ($isAdmin === 1 ? 1 : 2), password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $db->prepare('UPDATE cd_users SET username = ?, quota_bytes = ?, is_admin = ?, role_id = ? WHERE id = ?')
                ->execute([$username, $quota, $isAdmin, $roleId ?: ($isAdmin === 1 ? 1 : 2), $id]);
        }
        cd_log($uid, 'saveuser', 'update id=' . $id . ' name=' . $username);
        cd_ok(['id' => $id]);
    }

    if ($password === '' || strlen($password) < 6) cd_fail('新账号密码至少 6 位');
    $st = $db->prepare('SELECT id FROM cd_users WHERE username = ?');
    $st->execute([$username]);
    if ($st->fetch()) cd_fail('账号名已被占用');
    $db->prepare('INSERT INTO cd_users (username, password_hash, quota_bytes, is_admin, role_id, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $quota, $isAdmin, $roleId ?: ($isAdmin === 1 ? 1 : 2), $now]);
    $newId = (int)$db->lastInsertId(); // 在 cd_log 之前取，避免取到日志表 id
    @mkdir(CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $newId, 0777, true);
    cd_log($uid, 'saveuser', 'created id=' . $newId . ' name=' . $username);
    cd_ok(['id' => $newId]);
}

if ($action === 'deluser') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cd_fail('仅支持 POST');
    cd_require_perm($me, 'manage_users');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
    $id = (int)($body['id'] ?? 0);
    $purge = !empty($body['purge']);
    if ($id === $uid) cd_fail('不能删除当前登录的账号');
    $db = cd_db();
    $st = $db->prepare('SELECT id, username, is_admin FROM cd_users WHERE id = ?');
    $st->execute([$id]);
    $target = $st->fetch();
    if (!$target) cd_fail('账号不存在', 404);
    if ((int)$target['is_admin'] === 1) {
        $st = $db->prepare('SELECT COUNT(*) FROM cd_users WHERE is_admin = 1 AND id <> ?');
        $st->execute([$id]);
        if ((int)$st->fetchColumn() === 0) cd_fail('系统至少需要保留一个管理员');
    }
    // 该账号是否还有文件（含回收站）
    $st = $db->prepare('SELECT COUNT(*) FROM cd_nodes WHERE owner_id = ?');
    $st->execute([$id]);
    $nodeCount = (int)$st->fetchColumn();
    if ($nodeCount > 0 && !$purge) {
        cd_fail('该账号还有 ' . $nodeCount . ' 个文件/文件夹（含回收站），请先让用户清空，或勾选「连文件一起删除」');
    }
    if ($nodeCount > 0 && $purge) {
        // 彻底删除该账号全部文件、分享与物理存储
        $st = $db->prepare('SELECT storage_name FROM cd_nodes WHERE owner_id = ? AND is_dir = 0 AND storage_name IS NOT NULL');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) {
            $p = cd_storage_path($id, (string)$r['storage_name']);
            if (is_file($p)) @unlink($p);
        }
        $db->prepare('DELETE FROM cd_shares WHERE owner_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM cd_nodes WHERE owner_id = ?')->execute([$id]);
    } else {
        $db->prepare('DELETE FROM cd_shares WHERE owner_id = ?')->execute([$id]);
    }
    $db->prepare('DELETE FROM cd_logs WHERE user_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM cd_users WHERE id = ?')->execute([$id]);
    @rmdir(CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $id);
    cd_log($uid, 'deluser', 'id=' . $id . ' name=' . $target['username'] . ($purge ? ' purge=1' : ''));
    cd_ok();
}

cd_fail('未知操作: ' . htmlspecialchars($action, ENT_QUOTES), 400);
