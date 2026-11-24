<?php
/**
 * CyberDisk 下载/预览模块
 *  - 登录下载: download.php?id=<nodeId>[&inline=1]
 *  - 分享下载: download.php?s=<token>&id=<nodeId>&code=<提取码>[&inline=1]
 *  - 文件夹自动 zip 打包（临时文件输出后删除）
 *  - 支持 HTTP Range（视频/音频在线播放可拖动进度条）
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

cd_ensure_schema();

$inline = isset($_GET['inline']) && $_GET['inline'] == '1';
$token = trim((string)($_GET['s'] ?? ''));
$nodeId = (int)($_GET['id'] ?? 0);
if ($nodeId <= 0) {
    cd_fail('缺少文件 ID');
}
$db = cd_db();

/* ---------- 身份：登录用户 或 分享访客 ---------- */
$visitor = false;
$share = null;
if ($token !== '') {
    $share = cd_find_valid_share($token);
    if (!$share) {
        // 分享失效/过期：引导回分享页展示友好错误
        header('Location: share.php?s=' . urlencode($token) . '&err=invalid');
        exit;
    }
    $ownerId = (int)$share['owner_id'];
    // 提取码校验
    if ($share['extract_code'] !== null) {
        cd_session_start();
        $code = (string)($_GET['code'] ?? ($_SESSION['cd_share_' . $share['token']] ?? ''));
        if (!hash_equals((string)$share['extract_code'], $code)) {
            cd_fail('提取码错误', 403);
        }
        // 校验通过则记住（本次会话内免重复输入）
        $_SESSION['cd_share_' . $share['token']] = $code;
    }
    if (!cd_in_subtree($ownerId, (int)$share['node_id'], $nodeId)) {
        cd_fail('无权访问该文件', 403);
    }
    $visitor = true;
} else {
    $me = cd_current_user();
    if (!$me) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => '未登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 登录用户：校验「下载」权限（在线预览同走本文件，与下载共用 download 权限）
    cd_require_perm($me, 'download');
    $ownerId = (int)$me['id'];
}

/* ---------- 取节点 ---------- */
$st = $db->prepare('SELECT * FROM cd_nodes WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
$st->execute([$nodeId, $ownerId]);
$node = $st->fetch();
if (!$node) {
    cd_fail('文件不存在或已被删除', 404);
}

/* ---------- 文件夹 → zip 打包 ---------- */
if ($node['is_dir']) {
    if (!class_exists('ZipArchive')) {
        cd_fail('服务器缺少 Zip 扩展，无法打包文件夹');
    }
    if (!is_dir(CD_TMP_DIR)) {
        @mkdir(CD_TMP_DIR, 0700, true);
    }
    $zipName = cd_random_hex(8) . '.zip';
    $zipPath = CD_TMP_DIR . DIRECTORY_SEPARATOR . $zipName;
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        cd_fail('打包失败');
    }
    // 递归入包（限制 20000 项防失控）
    $cnt = 0;
    function cd_zip_add(ZipArchive $zip, string $localPrefix, int $dirId, int $ownerId, int &$cnt): void
    {
        if ($cnt > 20000) return;
        $st = $GLOBALS['db']->prepare('SELECT * FROM cd_nodes WHERE parent_id = ? AND owner_id = ? AND deleted_at IS NULL ORDER BY is_dir DESC, name');
        $st->execute([$dirId, $ownerId]);
        foreach ($st->fetchAll() as $r) {
            if ($r['is_dir']) {
                $zip->addEmptyDir($localPrefix . $r['name']);
                $cnt++;
                cd_zip_add($zip, $localPrefix . $r['name'] . '/', (int)$r['id'], $ownerId, $cnt);
            } else {
                $path = cd_storage_path($ownerId, (string)$r['storage_name']);
                if (is_file($path)) {
                    $zip->addFile($path, $localPrefix . $r['name']);
                    $cnt++;
                }
            }
        }
    }
    cd_zip_add($zip, '', (int)$node['id'], $ownerId, $cnt);
    $zip->close();

    $safeName = str_replace(['"', "\r", "\n"], '', $node['name']) . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode($safeName));
    header('Content-Length: ' . (string)filesize($zipPath));
    header('Cache-Control: no-store');
    if (ob_get_level()) { ob_end_clean(); }
    readfile($zipPath);
    @unlink($zipPath);
    if ($visitor) {
        $db->prepare('UPDATE cd_shares SET download_count = download_count + 1 WHERE id = ?')->execute([$share['id']]);
    }
    cd_log($ownerId, $visitor ? 'share_zip_download' : 'zip_download', $node['name'] . " items={$cnt}");
    exit;
}

/* ---------- 普通文件 ---------- */
$path = cd_storage_path($ownerId, (string)$node['storage_name']);
if (!is_file($path)) {
    cd_fail('文件数据已丢失', 404);
}
$size = (int)$node['size'];
$mime = $node['mime'] ?: 'application/octet-stream';
// 文本类强制 UTF-8 展示
if ($inline && str_starts_with($mime, 'text/')) {
    $mime = $mime === 'text/html' ? 'text/plain' : $mime; // 防 XSS：html 按纯文本展示
    $mime .= '; charset=utf-8';
}
$safeName = str_replace(['"', "\r", "\n"], '', $node['name']);

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('Accept-Ranges: bytes');
header('Cache-Control: ' . ($inline ? 'private, max-age=86400' : 'no-store'));

if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }

/* Range 支持 */
$start = 0;
$end = $size - 1;
$status = 200;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int)$m[1];
    }
    if ($m[2] !== '') {
        $end = min((int)$m[2], $size - 1);
    }
    if ($m[1] === '' && $m[2] !== '') { // bytes=-N 尾部 N 字节
        $start = max(0, $size - (int)$m[2]);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
http_response_code($status);
header('Content-Length: ' . (string)($end - $start + 1));

$fp = fopen($path, 'rb');
if ($start > 0) {
    fseek($fp, $start);
}
$remain = $end - $start + 1;
$chunk = 512 * 1024;
while ($remain > 0 && !feof($fp)) {
    $len = (int)min($chunk, $remain);
    $data = fread($fp, $len);
    if ($data === false) break;
    echo $data;
    $remain -= strlen($data);
    flush();
}
fclose($fp);

if ($visitor) {
    $db->prepare('UPDATE cd_shares SET download_count = download_count + 1 WHERE id = ?')->execute([$share['id']]);
    cd_log((int)$share['owner_id'], 'share_download', $node['name']);
} else {
    cd_log($ownerId, 'download', $node['name']);
}
