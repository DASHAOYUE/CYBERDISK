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

/* ========== 压缩包：浏览 / 解压 / 压缩打包 ==========
 * 浏览（纯 PHP，不依赖任何组件）：ZIP / RAR(RAR3+RAR5) / 7Z / TAR / TAR.GZ / TGZ / TAR.BZ2 / GZ / BZ2
 * 解压：ZIP、TAR 系完整解压；RAR 在无组件主机仅解“存储”条目（有 rar 扩展/unrar 时完整）；
 *       7Z 仅浏览（LZMA 纯 PHP 不实现）。ZIP 打包创建保持不变。 */
define('CD_ZIP_MAX_ENTRIES', 20000);        // 单次压缩/解压条目上限
define('CD_ZIP_MAX_UNCOMPRESS', 4294967296); // 单次解压体积上限 4GB

/** 识别压缩包格式并做基本校验：返回 ['format' => ..., 'path' => 物理路径] */
function cd_archive_guard(array $node): array
{
    if ((int)$node['is_dir'] === 1) {
        cd_fail('请选择压缩包文件');
    }
    $name = (string)$node['name'];
    $lower = mb_strtolower($name);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $format = '';
    if ($ext === 'zip') {
        $format = 'zip';
    } elseif ($ext === 'rar') {
        $format = 'rar';
    } elseif ($ext === '7z') {
        $format = '7z';
    } elseif ($ext === 'tar') {
        $format = 'tar';
    } elseif ($ext === 'tgz' || str_ends_with($lower, '.tar.gz')) {
        $format = 'tgz';
    } elseif ($ext === 'tbz2' || $ext === 'tbz' || str_ends_with($lower, '.tar.bz2')) {
        $format = 'tbz2';
    } elseif ($ext === 'gz') {
        $format = 'gz';
    } elseif ($ext === 'bz2') {
        $format = 'bz2';
    } else {
        cd_fail('暂不支持该格式；目前可浏览 ZIP / RAR / 7Z / TAR / TAR.GZ / GZ 等常用压缩包');
    }
    if ($format === 'zip' && !class_exists('ZipArchive')) {
        cd_fail('服务器缺少 Zip 扩展，无法处理 ZIP 压缩包');
    }
    if ($format === 'tgz' && !function_exists('gzopen')) {
        cd_fail('服务器缺少 zlib 扩展，无法处理 TAR.GZ');
    }
    if (in_array($format, ['tbz2', 'bz2'], true) && !function_exists('bzopen')) {
        cd_fail('服务器缺少 bz2 扩展，无法处理 BZ2 压缩包');
    }
    $path = cd_storage_path((int)$node['owner_id'], (string)$node['storage_name']);
    if (!is_file($path)) {
        cd_fail('压缩包数据已丢失', 404);
    }
    return ['format' => $format, 'path' => $path];
}

/** 解压能力说明（给前端展示）：full=完整解压，partial=部分可解压，none=仅浏览 */
function cd_archive_extract_capability(string $format): array
{
    switch ($format) {
        case 'zip':
        case 'tar':
        case 'tgz':
        case 'tbz2':
        case 'gz':
        case 'bz2':
            return ['mode' => 'full', 'note' => null];
        case 'rar':
            $backend = cd_rar_backend();
            if ($backend === 'basic') {
                return ['mode' => 'partial', 'note' =>
                    '当前服务器没有 rar 扩展或 unrar 组件：可完整浏览；解压只能还原「存储（不压缩）」方式打包的条目，'
                    . '其余条目会自动跳过。完整解压请下载后用 WinRAR / 7-Zip 打开，或让上传者改传 ZIP。'];
            }
            return ['mode' => 'full', 'note' => null];
        default:
            return ['mode' => 'none', 'note' =>
                '7Z 使用 LZMA 系列压缩，纯 PHP 环境仅支持浏览内容；解压请下载后用 7-Zip / WinRAR 等工具完成。'];
    }
}

/** 创建“解压目标”根目录节点：folder 模式新建文件夹，here 模式直接用当前目录 */
function cd_archive_root(int $uid, ?int $parentId, string $mode, string $archiveName): array
{
    if ($mode !== 'folder') {
        return [$parentId, null];
    }
    $base = pathinfo($archiveName, PATHINFO_FILENAME);
    $base = preg_replace('/\.tar$/i', '', $base); // a.tar.gz → a
    $base = cd_zip_seg($base === '' ? '解压文件' : $base);
    $final = cd_unique_name($uid, $parentId, $base, true);
    $now = date('Y-m-d H:i:s');
    cd_db()->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, created_at, updated_at)
                      VALUES (?,?,?,1,0,?,?)')
        ->execute([$uid, $parentId, $final, $now, $now]);
    return [(int)cd_db()->lastInsertId(), $final];
}

/** 解压用：按需创建（或复用）目录节点，返回目录 id（$parent 为 null 表根） */
function cd_archive_ensure_dir(int $uid, $parent, string $seg, array &$dirMap): int
{
    $clean = cd_zip_seg($seg);
    $key = ($parent ?? 0) . '|' . mb_strtolower($clean);
    if (isset($dirMap[$key])) {
        return $dirMap[$key];
    }
    $pid = $parent === null ? null : (int)$parent;
    $final = cd_unique_name($uid, $pid, $clean, true);
    $now = date('Y-m-d H:i:s');
    cd_db()->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, created_at, updated_at)
                      VALUES (?,?,?,1,0,?,?)')
        ->execute([$uid, $pid, $final, $now, $now]);
    $id = (int)cd_db()->lastInsertId();
    $dirMap[$key] = $id;
    return $id;
}

/** 递归删除临时目录（仅用于本程序自建的解压临时目录） */
function cd_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $n;
        if (is_dir($p) && !is_link($p)) {
            cd_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/** 目录内是否有任何文件（含子目录），最多检查 20000 项 */
function cd_dir_has_files(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }
    $stack = [$dir];
    $seen = 0;
    while ($stack && $seen < 20000) {
        $d = array_pop($stack);
        foreach (scandir($d) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $seen++;
            if ($seen > 20000) break 2;
            $p = $d . DIRECTORY_SEPARATOR . $n;
            if (is_file($p)) {
                return true;
            }
            if (is_dir($p)) {
                $stack[] = $p;
            }
        }
    }
    return false;
}

/** 将临时目录中的解压结果导入网盘（供 CLI/PECL 解压路径复用） */
function cd_archive_import_dir(int $uid, $rootDir, string $fsDir, int $quota, int $used, array &$st, array &$dirMap, int $depth = 0): void
{
    if ($depth > 64) {
        return;
    }
    $items = @scandir($fsDir);
    if (!$items) {
        return;
    }
    // 先目录再文件
    foreach ($items as $n) {
        if ($n === '.' || $n === '..' || $n === '__MACOSX') {
            continue;
        }
        $p = $fsDir . DIRECTORY_SEPARATOR . $n;
        if (!is_dir($p)) {
            continue;
        }
        if ($st['files'] >= CD_ZIP_MAX_ENTRIES) {
            return;
        }
        $id = cd_archive_ensure_dir($uid, $rootDir, $n, $dirMap);
        cd_archive_import_dir($uid, $id, $p, $quota, $used, $st, $dirMap, $depth + 1);
    }
    foreach ($items as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $fsDir . DIRECTORY_SEPARATOR . $n;
        if (!is_file($p)) {
            continue;
        }
        if ($st['files'] >= CD_ZIP_MAX_ENTRIES) {
            return;
        }
        $size = (int)@filesize($p);
        if ($size <= 0) {
            $st['skipped']++;
            continue;
        }
        if ($used + $st['bytes'] + $size > $quota) {
            throw new RuntimeException('解压中途超出存储配额，已保留已解压部分');
        }
        $clean = cd_zip_seg($n);
        $final = cd_unique_name($uid, $rootDir, $clean, false);
        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
        $storageName = cd_random_hex(8) . '.' . ($ext !== '' ? $ext : 'bin');
        $dst = cd_storage_path($uid, $storageName);
        if (!@rename($p, $dst) && !@copy($p, $dst)) {
            $st['skipped']++;
            continue;
        }
        $mime = mime_content_type($dst) ?: 'application/octet-stream';
        $now = date('Y-m-d H:i:s');
        cd_db()->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                          VALUES (?,?,?,0,?,?,?,?,?)')
            ->execute([$uid, $rootDir, $final, $size, $mime, $storageName, $now, $now]);
        $st['files']++;
        $st['bytes'] += $size;
    }
}

/* ---------- RAR 服务器能力探测（无组件也能浏览；解压分级） ---------- */
function cd_proc_available(): bool
{
    if (!function_exists('proc_open')) {
        return false;
    }
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('proc_open', $dis, true);
}

/** 解析压缩包名称类文本（tar/rar 非 UTF-8 场景走 GBK/Big5 兜底） */
function cd_archive_name(string $raw): string
{
    $raw = rtrim($raw, "\0");
    if ($raw === '') {
        return '';
    }
    if (@preg_match('//u', $raw) === 1) {
        return $raw;
    }
    foreach (['GB18030', 'GBK', 'BIG5'] as $cs) {
        $c = @iconv($cs, 'UTF-8//IGNORE', $raw);
        if (is_string($c) && $c !== '' && @preg_match('//u', $c) === 1) {
            return $c;
        }
    }
    $out = @preg_replace('/[\x80-\xff]/', '?', $raw);
    return is_string($out) && $out !== '' ? $out : '???';
}

/** RAR 解压通道：pecl（rar 扩展）> cli（unrar/7z/bsdtar）> basic（纯 PHP，仅存储条目） */
function cd_rar_backend(): string
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    if (defined('CD_ARCHIVE_CLI') && CD_ARCHIVE_CLI === false) {
        return $b = 'basic';
    }
    if (class_exists('RarArchive')) {
        return $b = 'pecl';
    }
    if (cd_proc_available() && cd_rar_cli_find() !== '') {
        return $b = 'cli';
    }
    return $b = 'basic';
}

/** 查找可用的解压命令行组件（返回路径，未找到为空串） */
function cd_rar_cli_find(): string
{
    static $found = null;
    if ($found !== null) {
        return $found;
    }
    $cands = [];
    if (defined('CD_RAR_BIN') && CD_RAR_BIN) {
        $cands[] = (string)CD_RAR_BIN;
    }
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        foreach ([getenv('ProgramW6432'), getenv('ProgramFiles'), getenv('ProgramFiles(x86)'), 'C:\\Program Files', 'C:\\Program Files (x86)'] as $base) {
            if ($base) {
                $cands[] = $base . '\\WinRAR\\UnRAR.exe';
                $cands[] = $base . '\\7-Zip\\7z.exe';
            }
        }
        $cands[] = 'C:\\Windows\\System32\\tar.exe'; // bsdtar（libarchive，可读 RAR）
    } else {
        foreach (['/usr/bin/unrar', '/usr/local/bin/unrar', '/usr/bin/7z', '/usr/local/bin/7z', '/usr/bin/7za', '/opt/homebrew/bin/7z', '/usr/bin/bsdtar'] as $p) {
            $cands[] = $p;
        }
    }
    foreach ($cands as $p) {
        if ($p !== '' && @is_file($p)) {
            return $found = $p;
        }
    }
    return $found = '';
}

/** 用命令行组件把 RAR 解到临时目录（以 uploads 为工作目录 + 纯 ASCII 相对路径，规避中文路径/编码问题） */
function cd_rar_cli_extract(int $uid, string $storageName, string $destAbs): string
{
    $bin = cd_rar_cli_find();
    if ($bin === '') {
        return '未找到解压组件';
    }
    if (!cd_proc_available()) {
        return '服务器禁用了 proc_open';
    }
    if (!is_dir($destAbs)) {
        @mkdir($destAbs, 0700, true);
    }
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $sep = DIRECTORY_SEPARATOR;
    $relArc = 'u' . $uid . $sep . $storageName;
    $relDest = '.tmp' . $sep . basename($destAbs);
    $lower = strtolower(basename($bin));
    if (strpos($lower, 'unrar') !== false) {
        $cmd = '"' . $bin . '" x -y -p- "' . $relArc . '" "' . $relDest . $sep . '"';
    } elseif (strpos($lower, '7z') !== false) {
        $cmd = '"' . $bin . '" x -y -p -- "' . $relArc . '" -o"' . $relDest . '"';
    } else {
        $cmd = '"' . $bin . '" -xf "' . $relArc . '" -C "' . $relDest . '"'; // bsdtar
    }
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $opts = $isWin ? ['bypass_shell' => true, 'suppress_errors' => true] : [];
    $proc = @proc_open($cmd, $desc, $pipes, CD_UPLOAD_DIR, null, $opts);
    if (!is_resource($proc)) {
        return '无法启动解压组件';
    }
    foreach ([1, 2] as $i) {
        if (isset($pipes[$i])) {
            stream_set_blocking($pipes[$i], false);
        }
    }
    $out = '';
    $started = time();
    $exit = null;
    while (true) {
        $status = proc_get_status($proc);
        foreach ([1, 2] as $i) {
            if (isset($pipes[$i]) && is_resource($pipes[$i])) {
                $out .= (string)stream_get_contents($pipes[$i]);
            }
        }
        if (!$status['running']) {
            $exit = $status['exitcode'];
            break;
        }
        if (time() - $started > 300) {
            @proc_terminate($proc);
            $exit = null;
            break;
        }
        usleep(50000);
    }
    foreach ($pipes as $pp) {
        if (is_resource($pp)) {
            @fclose($pp);
        }
    }
    @proc_close($proc);
    if (($exit === 0 || $exit === 1) && cd_dir_has_files($destAbs)) {
        return '';
    }
    $msg = trim((string)preg_replace('/\s+/u', ' ', $out));
    if ($msg !== '' && @preg_match('//u', $msg) !== 1) {
        $conv = @iconv('GB18030', 'UTF-8//IGNORE', $msg);
        if (is_string($conv) && $conv !== '') {
            $msg = $conv;
        }
    }
    $msg = mb_substr($msg, -180);
    return '解压组件失败（code=' . ($exit === null ? 'timeout' : $exit) . '）' . ($msg !== '' ? '：' . $msg : '');
}

/** 用 PECL rar 扩展把压缩包解到临时目录 */
function cd_pecl_rar_extract(string $path, string $destAbs): string
{
    if (!class_exists('RarArchive')) {
        return '无 rar 扩展';
    }
    try {
        $rar = RarArchive::open($path);
        if (!$rar) {
            return '无法打开压缩包';
        }
        @$rar->setAllowBroken(true);
        $entries = $rar->getEntries();
        if (!$entries) {
            $rar->close();
            return '压缩包无条目';
        }
        foreach ($entries as $e) {
            if ($e->isDirectory()) {
                continue;
            }
            $name = str_replace('\\', '/', (string)$e->getName());
            $segs = array_values(array_filter(explode('/', $name), function ($s) {
                return $s !== '' && $s !== '.' && $s !== '..';
            }));
            if (!$segs) {
                continue;
            }
            $rel = implode(DIRECTORY_SEPARATOR, array_map('cd_zip_seg', $segs));
            $target = $destAbs . DIRECTORY_SEPARATOR . $rel;
            @mkdir(dirname($target), 0700, true);
            $ok = @$e->extract('', $target);
            if (!$ok || !is_file($target)) {
                @$e->extract(dirname($target), basename($target));
            }
        }
        $rar->close();
        return '';
    } catch (Throwable $ex) {
        return '扩展解压异常：' . $ex->getMessage();
    }
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

/* ---------- RAR 浏览：纯 PHP 解析 RAR3/4 与 RAR5 头部（不依赖任何扩展/组件） ---------- */
function cd_rar_vint_str(string $b, int &$p, bool &$ok): int
{
    $v = 0;
    $shift = 0;
    $len = strlen($b);
    while ($p < $len) {
        $c = ord($b[$p++]);
        $v |= (($c & 0x7F) << $shift);
        if (($c & 0x80) === 0) {
            return $v;
        }
        $shift += 7;
        if ($shift > 56) {
            $ok = false;
            return 0;
        }
    }
    $ok = false;
    return 0;
}

/** RAR3 DOS 时间 → Unix 时间戳 */
function cd_rar_dostime(int $d): int
{
    if ($d <= 0) {
        return 0;
    }
    $y = (($d >> 25) & 0x7F) + 1980;
    $mo = ($d >> 21) & 0x0F;
    $da = ($d >> 16) & 0x1F;
    $h = ($d >> 11) & 0x1F;
    $mi = ($d >> 5) & 0x3F;
    $s = ($d & 0x1F) * 2;
    if ($mo < 1 || $mo > 12 || $da < 1 || $da > 31) {
        return 0;
    }
    $t = @mktime($h, $mi, $s, $mo, $da, $y);
    return $t === false ? 0 : (int)$t;
}

/** RAR3 文件名：flag 0x200 时取 \0 前的 ANSI 部分，再按 UTF-8/GBK 兜底解码 */
function cd_rar3_name(string $raw, int $flags): string
{
    if (($flags & 0x0200) !== 0) {
        $z = strpos($raw, "\0");
        if ($z !== false) {
            $raw = substr($raw, 0, $z);
        }
    }
    return cd_archive_name($raw);
}

/**
 * 解析 RAR 条目。返回：
 * ['entries'=>[['name','dir','size','mtime','store','enc','split','offset','packed']], 'total','dirs','files','uncompressed','items','truncated','compressed']
 */
function cd_rar_list(string $path): array
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        cd_fail('压缩包无法打开', 404);
    }
    $fsize = (int)filesize($path);
    $head = (string)fread($fh, 8);
    $isRar5 = false;
    $start = 0;
    if (strlen($head) >= 8 && substr($head, 0, 8) === "Rar!\x1a\x07\x01\x00") {
        $isRar5 = true;
        $start = 8;
    } elseif (strlen($head) >= 7 && substr($head, 0, 7) === "Rar!\x1a\x07\x00") {
        $start = 7;
    } else {
        // 容忍尾部/前置数据：前 512KB 内找签名（如被改名/带壳）
        fclose($fh);
        $scan = (string)@file_get_contents($path, false, null, 0, min($fsize, 524288));
        $p5 = strpos($scan, "Rar!\x1a\x07\x01\x00");
        $p3 = strpos($scan, "Rar!\x1a\x07\x00");
        if ($p5 !== false) {
            $isRar5 = true;
            $start = $p5 + 8;
        } elseif ($p3 !== false) {
            $start = $p3 + 7;
        } else {
            cd_fail('不是有效的 RAR 文件（可能不是 RAR 或已损坏）');
        }
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            cd_fail('压缩包无法打开', 404);
        }
    }

    $entries = [];
    $dirs = 0;
    $files = 0;
    $uncompressed = 0;
    $compressed = 0;
    $truncated = false;

    if (!$isRar5) {
        /* ---- RAR 1.5~4.x（HEAD_FILE=0x74） ---- */
        $firstType = null;
        $pos = $start;
        for ($guard = 0; $guard < 200000; $guard++) {
            if ($pos + 7 > $fsize) {
                break;
            }
            fseek($fh, $pos);
            $h = (string)fread($fh, 7);
            if (strlen($h) < 7) {
                break;
            }
            $type = ord($h[2]);
            $flags = (int)unpack('v', substr($h, 3, 2))[1];
            $size = (int)unpack('v', substr($h, 5, 2))[1];
            if ($size < 7) {
                break;
            }
            if ($firstType === null) {
                $firstType = $type;
                if ($type !== 0x73) {
                    break; // 首块必须是主头，否则视为头部加密/损坏
                }
            }
            if ($type === 0x74 || $type === 0x7A || $type === 0x7B) {
                $bodyLen = min($size - 7, $fsize - $pos - 7);
                if ($bodyLen < 25) {
                    break;
                }
                fseek($fh, $pos + 7);
                $body = (string)fread($fh, $bodyLen);
                if (strlen($body) < 25) {
                    break;
                }
                $pack = (int)unpack('V', substr($body, 0, 4))[1];
                $unp = (int)unpack('V', substr($body, 4, 4))[1];
                $host = ord($body[8]);
                $ftime = (int)unpack('V', substr($body, 13, 4))[1];
                $method = ord($body[18]);
                $nameSize = (int)unpack('v', substr($body, 19, 2))[1];
                $attr = (int)unpack('V', substr($body, 21, 4))[1];
                $p = 25;
                $high = 0;
                if (($flags & 0x0100) !== 0) {
                    if (strlen($body) < 33) {
                        break;
                    }
                    $high = (int)unpack('V', substr($body, 25, 4))[1];
                    $p = 33;
                }
                $enc = ($flags & 0x0004) !== 0; // LHD_PASSWORD
                if (($flags & 0x0400) !== 0) {
                    $p += 8; // LHD_SALT
                }
                $rawName = substr($body, $p, max(0, min($nameSize, strlen($body) - $p)));
                $packFull = $pack + ($high << 32);
                if ($type === 0x74) {
                    $name = str_replace('\\', '/', cd_rar3_name($rawName, $flags));
                    $isDir = false;
                    if ($host === 0 || $host === 1) {
                        $isDir = ($attr & 0x10) !== 0;
                    } else {
                        $isDir = (($attr & 0xF000) === 0x4000);
                    }
                    if ($name !== '') {
                        $split = ($flags & 0x0003) !== 0; // LHD_SPLIT_BEFORE/AFTER（LONG_BLOCK=0x8000 不算分卷）
                        $store = ($method === 0x30) && !$split;
                        if (!$isDir) {
                            $files++;
                            $uncompressed += $unp;
                            if (!($store && !$enc)) {
                                $compressed++;
                            }
                        } else {
                            $dirs++;
                        }
                        if (count($entries) < CD_ZIP_MAX_ENTRIES) {
                            $entries[] = [
                                'name' => $isDir ? rtrim($name, '/') . '/' : $name,
                                'dir' => $isDir,
                                'size' => $isDir ? 0 : $unp,
                                'mtime' => cd_rar_dostime($ftime),
                                'store' => $store,
                                'enc' => $enc,
                                'split' => $split,
                                'offset' => $pos + $size,
                                'packed' => $packFull,
                            ];
                        } else {
                            $truncated = true;
                        }
                    }
                }
                $pos = $pos + $size + $packFull;
            } elseif ($type === 0x73) {
                $pos += $size; // 主头（HighPosAv/PosAv 含在 size 内）
            } elseif ($type === 0x7C) {
                break; // HEAD_END
            } else {
                // 其他头：LONG_BLOCK 时跟随 4 字节附加数据长度（旧式注释/AV 等）
                $add = 0;
                if (($flags & 0x8000) !== 0) {
                    fseek($fh, $pos + 7);
                    $ab = (string)fread($fh, 4);
                    if (strlen($ab) === 4) {
                        $add = (int)unpack('V', $ab)[1];
                    }
                }
                $pos += $size + $add;
            }
            if ($pos <= $start) {
                break;
            }
        }
        if ($firstType === null) {
            fclose($fh);
            cd_fail('RAR 内容解析失败：可能是加密头部或文件损坏');
        }
        if ($firstType !== 0x73) {
            fclose($fh);
            cd_fail('RAR 头部已加密（需密码才能列出内容），可下载后用 WinRAR / 7-Zip 打开');
        }
    } else {
        /* ---- RAR 5.x ---- */
        $firstType = null;
        $pos = $start;
        for ($guard = 0; $guard < 200000; $guard++) {
            if ($pos + 5 > $fsize) {
                break;
            }
            fseek($fh, $pos);
            if (strlen((string)fread($fh, 4)) < 4) {
                break;
            }
            $sz = 0;
            $bytes = 0;
            $okv = true;
            while (true) {
                $ch = fread($fh, 1);
                if ($ch === '' || $ch === false) {
                    $okv = false;
                    break;
                }
                $b = ord($ch);
                $bytes++;
                if ($bytes > 8) {
                    $okv = false;
                    break;
                }
                $sz |= (($b & 0x7F) << (7 * ($bytes - 1)));
                if (($b & 0x80) === 0) {
                    break;
                }
            }
            if (!$okv || $sz < 6 || $sz > 1048576) {
                break;
            }
            $bodyStart = ftell($fh);
            if ($bodyStart + $sz > $fsize) {
                break;
            }
            $body = (string)fread($fh, $sz);
            if (strlen($body) < $sz) {
                break;
            }
            $bp = 0;
            $okp = true;
            $type = cd_rar_vint_str($body, $bp, $okp);
            $hflags = $okp ? cd_rar_vint_str($body, $bp, $okp) : 0;
            $extraSize = 0;
            $dataSize = 0;
            if ($okp && ($hflags & 0x0001)) {
                $extraSize = cd_rar_vint_str($body, $bp, $okp);
            }
            if ($okp && ($hflags & 0x0002)) {
                $dataSize = cd_rar_vint_str($body, $bp, $okp);
            }
            if (!$okp) {
                break;
            }
            if ($firstType === null) {
                $firstType = $type;
                if ($type === 4) {
                    break;
                }
                if ($type !== 1) {
                    break;
                }
            }
            if ($type === 2) {
                $fileFlags = cd_rar_vint_str($body, $bp, $okp);
                $unp = cd_rar_vint_str($body, $bp, $okp);
                $attr = cd_rar_vint_str($body, $bp, $okp);
                $mtime = 0;
                if ($okp && ($fileFlags & 0x0002)) {
                    if ($bp + 4 > strlen($body)) {
                        break;
                    }
                    $mtime = (int)unpack('V', substr($body, $bp, 4))[1];
                    $bp += 4;
                }
                if ($okp && ($fileFlags & 0x0004)) {
                    $bp += 4;
                }
                $compInfo = cd_rar_vint_str($body, $bp, $okp);
                $hostOS = cd_rar_vint_str($body, $bp, $okp);
                $nameSize = cd_rar_vint_str($body, $bp, $okp);
                if (!$okp || $bp + $nameSize > strlen($body)) {
                    break;
                }
                $rawName = substr($body, $bp, $nameSize);
                $bp += $nameSize;
                $enc = false;
                if ($extraSize > 0 && $bp < strlen($body)) {
                    $pEnd = min(strlen($body), $bp + $extraSize);
                    $p2 = $bp;
                    while ($p2 < $pEnd - 1) {
                        $rstart = $p2;
                        $rs = cd_rar_vint_str($body, $p2, $okp);
                        if (!$okp || $rs <= 0) {
                            break;
                        }
                        $rt = cd_rar_vint_str($body, $p2, $okp);
                        if (!$okp) {
                            break;
                        }
                        if ($rt === 1) {
                            $enc = true;
                        }
                        $p2 = $rstart + $rs;
                    }
                }
                $name = str_replace('\\', '/', cd_archive_name($rawName));
                $isDir = ($fileFlags & 0x0001) !== 0;
                if ($name !== '') {
                    $split = ($hflags & 0x0008) !== 0 || ($hflags & 0x0010) !== 0;
                    $store = ((($compInfo >> 7) & 7) === 0) && !$split;
                    if (!$isDir) {
                        $files++;
                        $uncompressed += $unp;
                        if (!($store && !$enc)) {
                            $compressed++;
                        }
                    } else {
                        $dirs++;
                    }
                    if (count($entries) < CD_ZIP_MAX_ENTRIES) {
                        $entries[] = [
                            'name' => $isDir ? rtrim($name, '/') . '/' : $name,
                            'dir' => $isDir,
                            'size' => $isDir ? 0 : $unp,
                            'mtime' => $mtime,
                            'store' => $store,
                            'enc' => $enc,
                            'split' => $split,
                            'offset' => $bodyStart + $sz,
                            'packed' => $dataSize,
                        ];
                    } else {
                        $truncated = true;
                    }
                }
            }
            $pos = $bodyStart + $sz + $dataSize;
            if ($type === 5) {
                break; // END
            }
            if ($pos <= $start) {
                break;
            }
        }
        if ($firstType === null || $firstType !== 1) {
            fclose($fh);
            cd_fail('RAR 头部已加密或格式异常，无法列出内容（可下载后用 WinRAR / 7-Zip 打开）');
        }
    }
    fclose($fh);

    if (!$entries && $files === 0 && $dirs === 0) {
        cd_fail('RAR 内容为空或解析失败');
    }
    $items = [];
    foreach ($entries as $e) {
        if (count($items) < 3000) {
            $items[] = ['name' => $e['name'], 'dir' => $e['dir'], 'size' => $e['size'], 'mtime' => $e['mtime']];
        }
    }
    usort($items, function ($a, $b) {
        if ($a['dir'] !== $b['dir']) {
            return $a['dir'] ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    return [
        'entries' => $entries,
        'total' => (int)count($entries),
        'dirs' => $dirs,
        'files' => $files,
        'uncompressed' => $uncompressed,
        'items' => $items,
        'truncated' => $truncated,
        'compressed' => $compressed,
    ];
}

/* ---------- TAR / TAR.GZ / TAR.BZ2 解析（纯 PHP，流式） ---------- */
function cd_tar_open(string $path, ?string $comp): array
{
    if ($comp === 'gz') {
        $fp = @gzopen($path, 'rb');
        if (!$fp) {
            cd_fail('TAR.GZ 打开失败');
        }
        return ['kind' => 'gz', 'fp' => $fp];
    }
    if ($comp === 'bz2') {
        $fp = @bzopen($path, 'r');
        if (!$fp) {
            cd_fail('TAR.BZ2 打开失败');
        }
        return ['kind' => 'bz2', 'fp' => $fp];
    }
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        cd_fail('压缩包无法打开', 404);
    }
    return ['kind' => 'plain', 'fp' => $fp];
}

function cd_tar_read(array $s, int $len): string
{
    $out = '';
    while (strlen($out) < $len) {
        $chunk = min($len - strlen($out), 65536);
        if ($s['kind'] === 'gz') {
            $buf = @gzread($s['fp'], $chunk);
        } elseif ($s['kind'] === 'bz2') {
            $buf = @bzread($s['fp'], $chunk);
        } else {
            $buf = @fread($s['fp'], $chunk);
        }
        if ($buf === false || $buf === '') {
            break;
        }
        $out .= $buf;
    }
    return $out;
}

function cd_tar_skip(array $s, int $len): void
{
    if ($len <= 0) {
        return;
    }
    if ($s['kind'] === 'plain') {
        fseek($s['fp'], $len, SEEK_CUR);
        return;
    }
    if ($s['kind'] === 'gz') {
        @gzseek($s['fp'], $len, SEEK_CUR);
        return;
    }
    while ($len > 0) {
        $b = @bzread($s['fp'], min($len, 65536));
        if ($b === false || $b === '') {
            break;
        }
        $len -= strlen($b);
    }
}

function cd_tar_close(array $s): void
{
    if ($s['kind'] === 'gz') {
        @gzclose($s['fp']);
    } elseif ($s['kind'] === 'bz2') {
        @bzclose($s['fp']);
    } else {
        @fclose($s['fp']);
    }
}

/** 解析 512 字节 TAR 头（含 ustar 前缀与 GNU base-256 尺寸） */
function cd_tar_hdr(string $blk): ?array
{
    if (strlen($blk) < 512 || trim($blk, "\0") === '') {
        return null;
    }
    $name = rtrim(substr($blk, 0, 100), "\0");
    $prefix = rtrim(substr($blk, 345, 155), "\0");
    if ($prefix !== '') {
        $name = $prefix . '/' . $name;
    }
    $size = 0;
    $sizeRaw = substr($blk, 124, 12);
    if ($sizeRaw !== '' && ord($sizeRaw[0]) >= 0x80) {
        for ($i = 1; $i < 12; $i++) {
            $size = ($size << 8) | ord($sizeRaw[$i]); // base-256
        }
    } else {
        $sz = trim($sizeRaw, " \0");
        $size = $sz === '' ? 0 : (int)octdec($sz);
    }
    $mt = trim(substr($blk, 136, 12), " \0");
    return [
        'name' => $name,
        'size' => $size,
        'mtime' => $mt === '' ? 0 : (int)octdec($mt),
        'type' => substr($blk, 156, 1),
    ];
}

/** 遍历 TAR：$onEntry(name, isDir, size, mtime, headerBlock, stream) 返回 false 可提前结束；$onData 读取条目内容 */
/**
 * 遍历 TAR：$onEntry(name, isDir, size, mtime, ?reader) —— 文件条目可用 $reader(n) 读取内容；
 * 回调返回 false 提前结束。读取未消费的数据与 512 对齐填充由遍历器自动跳过。
 */
function cd_tar_walk(string $path, ?string $comp, callable $onEntry): void
{
    $s = cd_tar_open($path, $comp);
    $pending = null;
    $paxPath = null;
    $paxMtime = null;
    for ($guard = 0; $guard < 400000; $guard++) {
        $blk = cd_tar_read($s, 512);
        if (strlen($blk) < 512) {
            break;
        }
        if (trim($blk, "\0") === '') {
            break; // 结束块
        }
        $h = cd_tar_hdr($blk);
        if ($h === null) {
            break;
        }
        $type = $h['type'];
        $size = $h['size'];
        $pad = (512 - ($size % 512)) % 512;
        if ($type === 'L') { // GNU 长文件名
            $pending = rtrim(cd_tar_read($s, $size), "\0\n\r");
            cd_tar_skip($s, $pad);
            continue;
        }
        if ($type === 'K') {
            cd_tar_skip($s, $size + $pad);
            continue;
        }
        if ($type === 'x' || $type === 'g') { // pax 扩展头
            $data = cd_tar_read($s, $size);
            cd_tar_skip($s, $pad);
            if ($type === 'x') {
                if (preg_match('/(?:^|\n)\d+ path=(.*)/', $data, $m)) {
                    $paxPath = $m[1];
                }
                if (preg_match('/(?:^|\n)\d+ mtime=(\d+)/', $data, $m2)) {
                    $paxMtime = (int)$m2[1];
                }
            }
            continue;
        }
        $name = $h['name'];
        if ($pending !== null) {
            $name = $pending;
            $pending = null;
        }
        if ($paxPath !== null) {
            $name = $paxPath;
            $paxPath = null;
        }
        $mtime = $paxMtime !== null ? $paxMtime : $h['mtime'];
        $paxMtime = null;
        $name = str_replace('\\', '/', cd_archive_name($name));
        $name = preg_replace('#^(?:\./)+#', '', $name);
        $isDir = ($type === '5') || substr($name, -1) === '/';
        $cleanName = rtrim($name, '/');
        if ($cleanName === '' || $cleanName === '.') {
            cd_tar_skip($s, $size + $pad); // 根目录条目
            continue;
        }
        if ($isDir) {
            if ($onEntry($cleanName, true, 0, $mtime, null) === false) {
                break;
            }
            continue;
        }
        $isFile = ($type === '0' || $type === "\0" || $type === '7');
        if (!$isFile) { // 链接/设备等特殊条目：跳过
            cd_tar_skip($s, $size + $pad);
            continue;
        }
        $remaining = $size;
        $reader = function (int $want) use (&$remaining, $s): string {
            $take = min($want, $remaining);
            if ($take <= 0) {
                return '';
            }
            $buf = cd_tar_read($s, $take);
            $remaining -= strlen($buf);
            return $buf;
        };
        $go = $onEntry($cleanName, false, $size, $mtime, $reader);
        cd_tar_skip($s, $remaining + $pad); // 未消费内容 + 对齐填充
        if ($go === false) {
            break;
        }
    }
    cd_tar_close($s);
}

function cd_tar_list(string $path, ?string $comp): array
{
    $dirs = 0;
    $files = 0;
    $uncompressed = 0;
    $entries = [];
    $truncated = false;
    cd_tar_walk($path, $comp, function (string $name, bool $isDir, int $size, int $mtime, $reader) use (&$entries, &$dirs, &$files, &$uncompressed, &$truncated) {
        if (count($entries) >= CD_ZIP_MAX_ENTRIES) {
            $truncated = true;
            return false;
        }
        if ($isDir) {
            $dirs++;
            $entries[] = ['name' => $name . '/', 'dir' => true, 'size' => 0, 'mtime' => $mtime];
        } else {
            $files++;
            $uncompressed += $size;
            $entries[] = ['name' => $name, 'dir' => false, 'size' => $size, 'mtime' => $mtime];
        }
        return true;
    });
    if (!$entries && $files === 0 && $dirs === 0) {
        cd_fail('TAR 内容为空或解析失败');
    }
    $items = array_slice($entries, 0, 3000);
    usort($items, function ($a, $b) {
        if ($a['dir'] !== $b['dir']) {
            return $a['dir'] ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    return [
        'total' => count($entries),
        'dirs' => $dirs,
        'files' => $files,
        'uncompressed' => $uncompressed,
        'items' => $items,
        'truncated' => $truncated,
        'compressed' => 0,
    ];
}

/* ---------- 单文件 GZ / BZ2：浏览为“1 个条目” ---------- */
function cd_single_list(string $path, string $format, string $nodeName): array
{
    $size = 0;
    $mtime = 0;
    if ($format === 'gz') {
        $fp = @fopen($path, 'rb');
        if ($fp) {
            fseek($fp, -8, SEEK_END);
            $tail = (string)fread($fp, 8);
            if (strlen($tail) === 8) {
                $mtime = (int)unpack('V', substr($tail, 0, 4))[1];
                $size = (int)unpack('V', substr($tail, 4, 4))[1]; // ISIZE（超 4GB 时为余数）
            }
            fclose($fp);
        }
    }
    $inner = preg_replace('/\.(gz|bz2)$/i', '', $nodeName);
    if ($inner === '' || $inner === null) {
        $inner = '解压文件';
    }
    $items = [['name' => $inner, 'dir' => false, 'size' => $size, 'mtime' => $mtime]];
    return [
        'total' => 1,
        'dirs' => 0,
        'files' => 1,
        'uncompressed' => $size,
        'items' => $items,
        'truncated' => false,
        'compressed' => 0,
    ];
}

/* ---------- 7Z 浏览：纯 PHP 解析 7z 头部（含文件夹/子流/名称/时间） ---------- */
function cd_7z_list(string $path): array
{
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        cd_fail('压缩包无法打开', 404);
    }
    $fsize = (int)filesize($path);
    $start = (string)fread($fp, 32);
    if (strlen($start) < 32 || substr($start, 0, 6) !== "7z\xBC\xAF\x27\x1C") {
        fclose($fp);
        cd_fail('不是有效的 7Z 文件');
    }
    $nextOff = (int)unpack('P', substr($start, 12, 8))[1];
    $nextSize = (int)unpack('P', substr($start, 20, 8))[1];
    if ($nextSize <= 0 || $nextSize > 67108864 || 32 + $nextOff + $nextSize > $fsize) {
        fclose($fp);
        cd_fail('7Z 头部异常或文件不完整');
    }
    fseek($fp, 32 + $nextOff);
    $hdr = (string)fread($fp, $nextSize);
    fclose($fp);
    if (strlen($hdr) < $nextSize) {
        cd_fail('7Z 头部读取不完整');
    }

    $n = strlen($hdr);
    $p = 0;
    $ok = true;
    $rb = function () use (&$p, $n, &$ok, $hdr): int {
        if ($p >= $n) {
            $ok = false;
            return 0;
        }
        return ord($hdr[$p++]);
    };
    $rn = function () use (&$p, $n, &$ok, $hdr): int {
        if ($p >= $n) {
            $ok = false;
            return 0;
        }
        $first = ord($hdr[$p++]);
        $mask = 0x80;
        $value = 0;
        for ($i = 0; $i < 8; $i++) {
            if (($first & $mask) === 0) {
                $value |= (($first & ($mask - 1)) << (8 * $i));
                return $value;
            }
            if ($p >= $n) {
                $ok = false;
                return 0;
            }
            $value |= (ord($hdr[$p++]) << (8 * $i));
            $mask >>= 1;
        }
        return $value;
    };
    $rbytes = function (int $len) use (&$p, $n, &$ok, $hdr): string {
        if ($len < 0 || $p + $len > $n) {
            $ok = false;
            return '';
        }
        $s = substr($hdr, $p, $len);
        $p += $len;
        return $s;
    };
    /** BoolVector2：AllAreDefined 字节 + （非全定义时）MSB 位向量 */
    $vec2 = function (int $count) use ($rb, $rbytes, &$ok): array {
        $bits = [];
        if ($count <= 0) {
            return $bits;
        }
        $all = $rb();
        if ($all) {
            for ($i = 0; $i < $count; $i++) {
                $bits[$i] = true;
            }
            return $bits;
        }
        $need = (int)(($count + 7) >> 3);
        $data = $rbytes($need);
        for ($i = 0; $i < $count; $i++) {
            $byte = ord($data[$i >> 3] ?? "\0");
            $bits[$i] = (($byte >> (7 - ($i & 7))) & 1) === 1;
        }
        return $bits;
    };

    // 主头
    $id = $rb();
    if ($id === 0x17) { // kEncodedHeader：目录头经 LZMA 压缩
        cd_fail('该 7Z 的目录头经过压缩（7-Zip 默认设置），纯 PHP 环境无法直接列出内容；'
            . '可下载后用 7-Zip / WinRAR 打开，或用 7-Zip 以「-mhc=off」重建后上传');
    }
    if ($id !== 0x01) {
        cd_fail('7Z 头部解析失败');
    }

    $numFiles = 0;
    $names = [];
    $mtimes = [];
    $emptyStream = [];
    $emptyFile = [];
    $folderOuts = [];   // 每个 folder 的输出流数
    $outSizes = [];     // 全部输出流尺寸（按 folder 顺序）
    $numStreams = [];   // 每个 folder 的子流数
    $streamSizes = [];  // 展平后的子流尺寸（按文件顺序，仅含真实数据流）

    while ($ok) {
        $prop = $rb();
        if (!$ok || $prop === 0x00) {
            break;
        }
        if ($prop === 0x02) { // kArchiveProperties
            while ($ok) {
                $pid = $rb();
                if (!$ok || $pid === 0x00) {
                    break;
                }
                $sz = $rn();
                $rbytes($sz);
            }
            continue;
        }
        if ($prop === 0x03) { // kAdditionalStreamsInfo
            cd_fail('该 7Z 包含附加流（常用于存储特殊属性），暂不支持在线浏览');
        }
        if ($prop === 0x04) { // kMainStreamsInfo
            while ($ok) {
                $sid = $rb();
                if (!$ok || $sid === 0x00) {
                    break;
                }
                if ($sid === 0x06) { // kPackInfo
                    $rn(); // PackPos
                    $numPack = $rn();
                    while ($ok) {
                        $pid = $rb();
                        if (!$ok || $pid === 0x00) {
                            break;
                        }
                        if ($pid === 0x09) { // kSize
                            for ($i = 0; $i < $numPack; $i++) {
                                $rn();
                            }
                        } elseif ($pid === 0x0A) { // kCRC
                            $bits = $vec2($numPack);
                            $rbytes(4 * count(array_filter($bits)));
                        } else {
                            $sz = $rn();
                            $rbytes($sz);
                        }
                    }
                } elseif ($sid === 0x07) { // kUnpackInfo
                    $id2 = $rb();
                    if ($id2 !== 0x0B) {
                        $ok = false;
                        break;
                    }
                    $numFolders = $rn();
                    if ($rb()) { // External
                        cd_fail('该 7Z 使用外部文件夹定义，暂不支持在线浏览');
                    }
                    for ($f = 0; $f < $numFolders; $f++) {
                        $numCoders = $rn();
                        $o = 1; // 实测 7-Zip / libarchive 输出：单输出流；多输出（如 BCJ2）极罕见
                        for ($c = 0; $c < $numCoders; $c++) {
                            $cf = $rb();
                            $rbytes($cf & 0x0F); // CodecId
                            if ($cf & 0x20) { // PropertiesSize + Properties（实测字段止于此）
                                $psz = $rn();
                                $rbytes($psz);
                            }
                        }
                        $folderOuts[] = $o;
                    }
                    while ($ok) {
                        $pid = $rb();
                        if (!$ok || $pid === 0x00) {
                            break;
                        }
                        if ($pid === 0x0C) { // kCodersUnpackSize
                            foreach ($folderOuts as $o) {
                                for ($i = 0; $i < $o; $i++) {
                                    $outSizes[] = $rn();
                                }
                            }
                        } elseif ($pid === 0x0A) { // kCRC（按 folder）
                            $bits = $vec2($numFolders);
                            $rbytes(4 * count(array_filter($bits)));
                        } else {
                            $sz = $rn();
                            $rbytes($sz);
                        }
                    }
                } elseif ($sid === 0x08) { // kSubStreamsInfo
                    foreach ($folderOuts as $o) {
                        $numStreams[] = 1;
                    }
                    while ($ok) {
                        $pid = $rb();
                        if (!$ok || $pid === 0x00) {
                            break;
                        }
                        if ($pid === 0x0D) { // kNumUnpackStream
                            $numStreams = [];
                            foreach ($folderOuts as $o) {
                                $numStreams[] = $rn();
                            }
                        } elseif ($pid === 0x09) { // kSize
                            foreach ($folderOuts as $f => $o) {
                                $cnt = $numStreams[$f] ?? 1;
                                if ($cnt <= 0) {
                                    continue;
                                }
                                $sum = 0;
                                for ($k = 0; $k < $cnt - 1; $k++) {
                                    $v = $rn();
                                    $streamSizes[] = $v;
                                    $sum += $v;
                                }
                                $prefix = 0;
                                for ($i = 0; $i < $f; $i++) {
                                    $prefix += $folderOuts[$i];
                                }
                                $folderSize = $outSizes[$prefix + $o - 1] ?? 0;
                                $streamSizes[] = max(0, $folderSize - $sum);
                            }
                        } elseif ($pid === 0x0A) { // kCRC（按子流）
                            $total = 0;
                            foreach ($numStreams as $c) {
                                $total += max(0, $c);
                            }
                            $bits = $vec2($total);
                            $rbytes(4 * count(array_filter($bits)));
                        } else {
                            $sz = $rn();
                            $rbytes($sz);
                        }
                    }
                } else {
                    $sz = $rn();
                    $rbytes($sz);
                }
            }
            continue;
        }
        if ($prop === 0x05) { // kFilesInfo
            $numFiles = $rn();
            while ($ok) {
                $pid = $rb();
                if (!$ok || $pid === 0x00) {
                    break;
                }
                $psz = $rn();
                $data = $rbytes($psz);
                if (!$ok) {
                    break;
                }
                if ($pid === 0x0E) { // kEmptyStream
                    for ($i = 0; $i < $numFiles; $i++) {
                        $byte = ord($data[$i >> 3] ?? "\0");
                        $emptyStream[$i] = (($byte >> (7 - ($i & 7))) & 1) === 1;
                    }
                } elseif ($pid === 0x0F) { // kEmptyFile（仅空流条目，按顺序）
                    $k = 0;
                    for ($i = 0; $i < $numFiles; $i++) {
                        if (!empty($emptyStream[$i])) {
                            $byte = ord($data[$k >> 3] ?? "\0");
                            $emptyFile[$k] = (($byte >> (7 - ($k & 7))) & 1) === 1;
                            $k++;
                        }
                    }
                } elseif ($pid === 0x11) { // kName（UTF-16LE，\0\0 分隔）
                    $q = 0;
                    $external = ord($data[$q] ?? "\0");
                    $q++;
                    if ($external === 0) {
                        $names = [];
                        $cur = '';
                        $len = strlen($data);
                        for (; $q + 1 < $len; $q += 2) {
                            if ($data[$q] === "\0" && $data[$q + 1] === "\0") {
                                $names[] = $cur;
                                $cur = '';
                                if (count($names) >= $numFiles) {
                                    break;
                                }
                                continue;
                            }
                            $cur .= $data[$q] . $data[$q + 1];
                        }
                        if (count($names) < $numFiles && $cur !== '') {
                            $names[] = $cur;
                        }
                        foreach ($names as $k => $nm) {
                            $names[$k] = cd_archive_name((string)@iconv('UTF-16LE', 'UTF-8//IGNORE', $nm));
                        }
                    }
                } elseif ($pid === 0x14) { // kMTime
                    $q = 0;
                    $allDef = ord($data[$q] ?? "\0");
                    $q++;
                    $external = ord($data[$q] ?? "\0");
                    $q++;
                    if ($external === 0) {
                        $defined = [];
                        if ($allDef) {
                            for ($i = 0; $i < $numFiles; $i++) {
                                $defined[$i] = true;
                            }
                        } else {
                            $need = (int)(($numFiles + 7) >> 3);
                            for ($i = 0; $i < $numFiles; $i++) {
                                $byte = ord($data[$q + ($i >> 3)] ?? "\0");
                                $defined[$i] = (($byte >> (7 - ($i & 7))) & 1) === 1;
                            }
                            $q += $need;
                        }
                        for ($i = 0; $i < $numFiles; $i++) {
                            if (empty($defined[$i])) {
                                $mtimes[$i] = 0;
                                continue;
                            }
                            if ($q + 8 > strlen($data)) {
                                $mtimes[$i] = 0;
                                continue;
                            }
                            $ft = unpack('P', substr($data, $q, 8))[1];
                            $q += 8;
                            $mtimes[$i] = $ft > 116444736000000000 ? (int)intdiv($ft, 10000000) - 11644473600 : 0;
                        }
                    }
                }
            }
            continue;
        }
        // 未知顶层属性：按“尺寸 + 内容”跳过
        $sz = $rn();
        $rbytes($sz);
    }
    if (!$ok) {
        cd_fail('7Z 头部解析失败（文件可能损坏，或使用了加密/不支持的变体）');
    }
    if ($numFiles <= 0) {
        cd_fail('7Z 内没有任何文件');
    }

    // 组装条目：空流条目 = 目录或空文件；其余按子流尺寸顺序取
    $dirs = 0;
    $files = 0;
    $uncompressed = 0;
    $entries = [];
    $streamIdx = 0;
    $emptyIdx = 0;
    for ($i = 0; $i < $numFiles; $i++) {
        $name = isset($names[$i]) ? (string)$names[$i] : ('file_' . $i);
        $name = str_replace('\\', '/', $name);
        $name = preg_replace('#^(?:\./)+#', '', $name);
        if ($name === '' || $name === '.') {
            continue;
        }
        $isDir = false;
        $size = 0;
        if (empty($emptyStream[$i])) { // 有数据流
            $size = isset($streamSizes[$streamIdx]) ? (int)$streamSizes[$streamIdx] : 0;
            $streamIdx++;
            if ($size < 0) {
                $size = 0;
            }
        } else { // 无数据流：目录或空文件
            $isDir = !(isset($emptyFile[$emptyIdx]) && $emptyFile[$emptyIdx] === true);
            $emptyIdx++;
        }
        $mtime = isset($mtimes[$i]) ? (int)$mtimes[$i] : 0;
        if ($isDir) {
            $dirs++;
            $entries[] = ['name' => rtrim($name, '/') . '/', 'dir' => true, 'size' => 0, 'mtime' => $mtime];
        } else {
            $files++;
            $uncompressed += $size;
            $entries[] = ['name' => $name, 'dir' => false, 'size' => $size, 'mtime' => $mtime];
        }
    }
    if (!$entries) {
        cd_fail('7Z 内没有任何文件');
    }
    $items = array_slice($entries, 0, 3000);
    usort($items, function ($a, $b) {
        if ($a['dir'] !== $b['dir']) {
            return $a['dir'] ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    return [
        'total' => count($entries),
        'dirs' => $dirs,
        'files' => $files,
        'uncompressed' => $uncompressed,
        'items' => $items,
        'truncated' => count($entries) > 3000,
        'compressed' => 0,
    ];
}
/** ZIP 条目目录（原 ZipArchive 逻辑，供 ziplist 分发复用） */
function cd_zip_list(string $path): array
{
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
    usort($items, function ($a, $b) {
        if ($a['dir'] !== $b['dir']) {
            return $a['dir'] ? -1 : 1;
        }
        return strnatcasecmp($a['name'], $b['name']);
    });
    return [
        'total' => $total,
        'dirs' => $dirs,
        'files' => $files,
        'uncompressed' => $uncompressed,
        'items' => $items,
        'truncated' => $total > $limit,
        'compressed' => 0,
    ];
}

/** 读取压缩包条目目录（浏览用，按格式分发；RAR/7Z/TAR 系为纯 PHP 解析） */
if ($action === 'ziplist') {
    cd_require_perm($me, 'preview');
    $node = cd_get_node($uid, (int)($_GET['id'] ?? 0));
    $info = cd_archive_guard($node);
    switch ($info['format']) {
        case 'zip':
            $data = cd_zip_list($info['path']);
            break;
        case 'rar':
            $data = cd_rar_list($info['path']);
            break;
        case '7z':
            $data = cd_7z_list($info['path']);
            break;
        case 'tar':
            $data = cd_tar_list($info['path'], null);
            break;
        case 'tgz':
            $data = cd_tar_list($info['path'], 'gz');
            break;
        case 'tbz2':
            $data = cd_tar_list($info['path'], 'bz2');
            break;
        default:
            $data = cd_single_list($info['path'], $info['format'], (string)$node['name']);
            break;
    }
    unset($data['entries']); // RAR 内部条目结构不下发
    $cap = cd_archive_extract_capability($info['format']);
    $extra = [];
    if ($info['format'] === 'rar') {
        $extra['rarBackend'] = cd_rar_backend();
        $extra['compressedCount'] = (int)($data['compressed'] ?? 0);
    }
    cd_ok(array_merge([
        'id' => (int)$node['id'],
        'name' => $node['name'],
        'format' => $info['format'],
        'extractMode' => $cap['mode'],
        'extractNote' => $cap['note'],
    ], $extra, $data));
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
    $info = cd_archive_guard($node);
    if ($info['format'] !== 'zip') {
        cd_archive_extract($uid, $me, $node, $info, $mode, $parentId);
        cd_fail('解压流程异常', 500); // 不会到达：cd_archive_extract 内部已输出
    }
    $path = $info['path'];
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
    // 目录节点创建统一走共享实现（与 RAR/TAR 解压一致）
    $ensureDir = function ($parent, string $seg) use ($uid, &$dirMap): int {
        return cd_archive_ensure_dir($uid, $parent, $seg, $dirMap);
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

/* ========== 非 ZIP 格式的解压实现 ========== */

/** 按格式分派解压（zip 由 zipextract 主流程处理） */
function cd_archive_extract(int $uid, array $me, array $node, array $info, string $mode, ?int $parentId): void
{
    switch ($info['format']) {
        case 'rar':
            cd_rar_extract($uid, $me, $node, $info['path'], $mode, $parentId);
            return;
        case 'tar':
        case 'tgz':
        case 'tbz2':
            cd_tar_extract($uid, $me, $node, $info, $mode, $parentId);
            return;
        case 'gz':
        case 'bz2':
            cd_single_extract($uid, $me, $node, $info, $parentId);
            return;
        default:
            cd_fail('7Z 暂不支持在线解压：请下载后用 7-Zip / WinRAR 解压（浏览内容不受影响）');
    }
}

/**
 * RAR 解压：服务器有 rar 扩展/命令行组件时完整解压（含压缩与固实压缩包）；
 * 纯 PHP 环境仅还原“存储（不压缩）”条目，其余条目跳过并回报数量。
 */
function cd_rar_extract(int $uid, array $me, array $node, string $path, string $mode, ?int $parentId): void
{
    $list = cd_rar_list($path);
    $entries = $list['entries'];
    $total = count($entries);
    if ($total > CD_ZIP_MAX_ENTRIES) {
        cd_fail('压缩包条目过多（' . $total . ' 项），超过 ' . CD_ZIP_MAX_ENTRIES . ' 项上限');
    }
    $need = 0;
    foreach ($entries as $e) {
        if (!$e['dir']) {
            $need += $e['size'];
        }
    }
    if ($need > CD_ZIP_MAX_UNCOMPRESS) {
        cd_fail('解压体积约 ' . round($need / 1073741824, 1) . ' GB，超过单次 4GB 上限');
    }
    $quota = (int)$me['quota_bytes'];
    $used = cd_used_bytes($uid);
    if ($used + $need > $quota) {
        cd_fail('解压后超出存储配额（需约 ' . round($need / 1048576, 1) . ' MB，剩余 '
            . round(max(0, $quota - $used) / 1048576, 1) . ' MB）');
    }
    [$rootDir, $rootName] = cd_archive_root($uid, $parentId, $mode, (string)$node['name']);
    $st = ['files' => 0, 'skipped' => 0, 'bytes' => 0];
    $dirMap = [];

    // ① 组件可用：整体解到临时目录再导入（支持压缩/固实 RAR）
    $backend = cd_rar_backend();
    if ($backend === 'pecl' || $backend === 'cli') {
        $tmp = CD_TMP_DIR . DIRECTORY_SEPARATOR . 'rarx_' . cd_random_hex(8);
        @mkdir($tmp, 0700, true);
        $err = $backend === 'cli'
            ? cd_rar_cli_extract($uid, (string)$node['storage_name'], $tmp)
            : cd_pecl_rar_extract($path, $tmp);
        if ($err === '' && cd_dir_has_files($tmp)) {
            try {
                cd_archive_import_dir($uid, $rootDir, $tmp, $quota, $used, $st, $dirMap);
            } catch (Throwable $ex) {
                cd_rrmdir($tmp);
                cd_fail($ex->getMessage());
            }
            cd_rrmdir($tmp);
            cd_log($uid, 'zipextract', $node['name'] . ' format=rar backend=' . $backend . ' files=' . $st['files']);
            cd_ok([
                'files' => $st['files'], 'skipped' => $st['skipped'], 'bytes' => $st['bytes'],
                'mode' => $mode, 'folder' => $rootName, 'folderId' => $rootDir,
            ]);
        }
        cd_rrmdir($tmp); // 组件失败：回退纯 PHP 路径
    }

    // ② 纯 PHP：仅“存储（不压缩）”条目可直接还原
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        cd_fail('压缩包无法打开');
    }
    $storageDir = CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid;
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }
    foreach ($entries as $e) {
        $name = str_replace('\\', '/', $e['name']);
        $trim = rtrim($name, '/');
        if ($trim === '') {
            continue;
        }
        if ($trim === '__MACOSX' || str_starts_with($trim, '__MACOSX/')) {
            $st['skipped']++;
            continue;
        }
        $segs = array_values(array_filter(explode('/', $trim), function ($x) {
            return $x !== '' && $x !== '.';
        }));
        if (!$segs || in_array('..', $segs, true)) {
            $st['skipped']++;
            continue;
        }
        $cur = $rootDir;
        $last = count($segs) - 1;
        foreach ($segs as $k => $seg) {
            if ($k < $last || $e['dir']) {
                $cur = cd_archive_ensure_dir($uid, $cur, $seg, $dirMap);
                continue;
            }
            if (!($e['store'] && !$e['enc'] && !$e['split']) || $e['packed'] <= 0) {
                $st['skipped']++; // 压缩/加密/分卷条目：纯 PHP 无法还原
                break;
            }
            $clean = cd_zip_seg($seg);
            $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
            $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
            $storageName = cd_random_hex(8) . '.' . ($ext !== '' ? $ext : 'bin');
            $dstPath = cd_storage_path($uid, $storageName);
            fseek($fh, $e['offset']);
            $out = @fopen($dstPath, 'wb');
            if (!$out) {
                $st['skipped']++;
                break;
            }
            $remain = (int)$e['packed'];
            $copyOk = true;
            while ($remain > 0) {
                $buf = fread($fh, min($remain, 524288));
                if ($buf === false || $buf === '') {
                    $copyOk = false;
                    break;
                }
                if (fwrite($out, $buf) === false) {
                    $copyOk = false;
                    break;
                }
                $remain -= strlen($buf);
            }
            fclose($out);
            if (!$copyOk) {
                @unlink($dstPath);
                $st['skipped']++;
                break;
            }
            if ($used + $st['bytes'] + (int)$e['packed'] > $quota) {
                @unlink($dstPath);
                fclose($fh);
                cd_fail('解压中途超出存储配额，已保留已解压部分');
            }
            $final = cd_unique_name($uid, $cur, $clean, false);
            $mime = mime_content_type($dstPath) ?: 'application/octet-stream';
            $now = date('Y-m-d H:i:s');
            cd_db()->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                              VALUES (?,?,?,0,?,?,?,?,?)')
                ->execute([$uid, $cur, $final, (int)$e['packed'], $mime, $storageName, $now, $now]);
            $st['files']++;
            $st['bytes'] += (int)$e['packed'];
            break;
        }
    }
    fclose($fh);
    cd_log($uid, 'zipextract', $node['name'] . ' format=rar backend=basic files=' . $st['files'] . ' skipped=' . $st['skipped']);
    cd_ok([
        'files' => $st['files'], 'skipped' => $st['skipped'], 'bytes' => $st['bytes'],
        'mode' => $mode, 'folder' => $rootName, 'folderId' => $rootDir,
    ]);
}

/** TAR / TAR.GZ / TAR.BZ2 完整解压（纯 PHP 流式） */
function cd_tar_extract(int $uid, array $me, array $node, array $info, string $mode, ?int $parentId): void
{
    $comp = $info['format'] === 'tgz' ? 'gz' : ($info['format'] === 'tbz2' ? 'bz2' : null);
    $list = cd_tar_list($info['path'], $comp);
    if ($list['uncompressed'] > CD_ZIP_MAX_UNCOMPRESS) {
        cd_fail('解压体积约 ' . round($list['uncompressed'] / 1073741824, 1) . ' GB，超过单次 4GB 上限');
    }
    if ($list['total'] > CD_ZIP_MAX_ENTRIES) {
        cd_fail('条目过多（' . $list['total'] . ' 项），超过 ' . CD_ZIP_MAX_ENTRIES . ' 项上限');
    }
    $quota = (int)$me['quota_bytes'];
    $used = cd_used_bytes($uid);
    if ($used + $list['uncompressed'] > $quota) {
        cd_fail('解压后超出存储配额（需约 ' . round($list['uncompressed'] / 1048576, 1) . ' MB，剩余 '
            . round(max(0, $quota - $used) / 1048576, 1) . ' MB）');
    }
    [$rootDir, $rootName] = cd_archive_root($uid, $parentId, $mode, (string)$node['name']);
    $dirMap = [];
    $st = ['files' => 0, 'skipped' => 0, 'bytes' => 0];
    $storageDir = CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid;
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }
    try {
        cd_tar_walk($info['path'], $comp, function (string $name, bool $isDir, int $size, int $mtime, $reader) use ($uid, $rootDir, $quota, $used, &$st, &$dirMap) {
            if ($st['files'] >= CD_ZIP_MAX_ENTRIES) {
                return false;
            }
            $trim = rtrim(preg_replace('#^(?:\./)+#', '', $name), '/');
            if ($trim === '' || $trim === '.') {
                return true;
            }
            if ($trim === '__MACOSX' || str_starts_with($trim, '__MACOSX/')) {
                return true;
            }
            $segs = array_values(array_filter(explode('/', $trim), function ($x) {
                return $x !== '' && $x !== '.';
            }));
            if (!$segs || in_array('..', $segs, true)) {
                return true;
            }
            $cur = $rootDir;
            $last = count($segs) - 1;
            foreach ($segs as $k => $seg) {
                if ($k < $last || $isDir) {
                    $cur = cd_archive_ensure_dir($uid, $cur, $seg, $dirMap);
                    continue;
                }
                $clean = cd_zip_seg($seg);
                if ($size <= 0) {
                    $st['skipped']++; // 空文件不入库（与 ZIP 解压口径一致）
                    return true;
                }
                $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
                $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
                $storageName = cd_random_hex(8) . '.' . ($ext !== '' ? $ext : 'bin');
                $dstPath = cd_storage_path($uid, $storageName);
                $out = @fopen($dstPath, 'wb');
                if (!$out) {
                    $st['skipped']++;
                    return true;
                }
                $written = 0;
                while ($written < $size) {
                    $buf = $reader(min(524288, $size - $written));
                    if ($buf === '') {
                        break;
                    }
                    if (fwrite($out, $buf) === false) {
                        break;
                    }
                    $written += strlen($buf);
                }
                fclose($out);
                if ($written !== $size) {
                    @unlink($dstPath);
                    $st['skipped']++;
                    return true;
                }
                if ($used + $st['bytes'] + $size > $quota) {
                    @unlink($dstPath);
                    throw new RuntimeException('解压中途超出存储配额，已保留已解压部分');
                }
                $final = cd_unique_name($uid, $cur, $clean, false);
                $mime = mime_content_type($dstPath) ?: 'application/octet-stream';
                $now = date('Y-m-d H:i:s');
                cd_db()->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                                  VALUES (?,?,?,0,?,?,?,?,?)')
                    ->execute([$uid, $cur, $final, $size, $mime, $storageName, $now, $now]);
                $st['files']++;
                $st['bytes'] += $size;
                return true;
            }
            return true;
        });
    } catch (Throwable $ex) {
        cd_fail($ex->getMessage());
    }
    cd_log($uid, 'zipextract', $node['name'] . ' format=' . $info['format'] . ' files=' . $st['files'] . ' skipped=' . $st['skipped']);
    cd_ok([
        'files' => $st['files'], 'skipped' => $st['skipped'], 'bytes' => $st['bytes'],
        'mode' => $mode, 'folder' => $rootName, 'folderId' => $rootDir,
    ]);
}

/** 单文件 GZ / BZ2 解压为当前目录里的一个文件 */
function cd_single_extract(int $uid, array $me, array $node, array $info, ?int $parentId): void
{
    $quota = (int)$me['quota_bytes'];
    $used = cd_used_bytes($uid);
    $base = preg_replace('/\.(gz|bz2)$/i', '', (string)$node['name']);
    $clean = cd_zip_seg(($base === '' || $base === null) ? '解压文件' : $base);
    $storageDir = CD_UPLOAD_DIR . DIRECTORY_SEPARATOR . 'u' . $uid;
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }
    $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
    $ext = preg_match('/^[a-z0-9]{1,10}$/', $ext) ? $ext : '';
    $storageName = cd_random_hex(8) . '.' . ($ext !== '' ? $ext : 'bin');
    $dstPath = cd_storage_path($uid, $storageName);
    if ($info['format'] === 'gz') {
        $in = @gzopen($info['path'], 'rb');
    } else {
        $in = @bzopen($info['path'], 'r');
    }
    if (!$in) {
        cd_fail('压缩文件无法打开（可能已损坏）');
    }
    $out = @fopen($dstPath, 'wb');
    if (!$out) {
        $info['format'] === 'gz' ? @gzclose($in) : @bzclose($in);
        cd_fail('写入临时文件失败（磁盘空间或权限问题）');
    }
    $bytes = 0;
    $ok = true;
    while (true) {
        $buf = $info['format'] === 'gz' ? @gzread($in, 262144) : @bzread($in, 262144);
        if ($buf === false) {
            $ok = false;
            break;
        }
        if ($buf === '') {
            break;
        }
        if (fwrite($out, $buf) === false) {
            $ok = false;
            break;
        }
        $bytes += strlen($buf);
        if ($used + $bytes > $quota) {
            $ok = false;
            break;
        }
    }
    $info['format'] === 'gz' ? @gzclose($in) : @bzclose($in);
    fclose($out);
    if (!$ok || $bytes <= 0) {
        @unlink($dstPath);
        cd_fail('解压失败：文件可能已损坏，或解压后超出存储配额');
    }
    $final = cd_unique_name($uid, $parentId, $clean, false);
    $mime = mime_content_type($dstPath) ?: 'application/octet-stream';
    $now = date('Y-m-d H:i:s');
    cd_db()->prepare('INSERT INTO cd_nodes (owner_id, parent_id, name, is_dir, size, mime, storage_name, created_at, updated_at)
                      VALUES (?,?,?,0,?,?,?,?,?)')
        ->execute([$uid, $parentId, $final, $bytes, $mime, $storageName, $now, $now]);
    cd_log($uid, 'zipextract', $node['name'] . ' format=' . $info['format'] . ' bytes=' . $bytes);
    cd_ok([
        'files' => 1, 'skipped' => 0, 'bytes' => $bytes,
        'mode' => 'here', 'folder' => null, 'folderId' => $parentId,
    ]);
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
        if (in_array($m, ['application/zip', 'application/x-rar-compressed', 'application/vnd.rar',
            'application/x-rar', 'application/x-7z-compressed', 'application/x-compressed-tar',
            'application/gzip', 'application/x-gzip', 'application/x-bzip2', 'application/x-tar'], true)) return '压缩包';
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
