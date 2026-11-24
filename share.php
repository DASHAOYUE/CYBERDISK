<?php
/**
 * CyberDisk 访客分享页（赛博风）
 * share.php?s=<token>
 * 支持提取码校验、子目录浏览、在线预览、下载（文件夹 zip）
 */
declare(strict_types=1);

require __DIR__ . '/lib.php';

cd_ensure_schema();
header('X-Content-Type-Options: nosniff');

$token = trim((string)($_GET['s'] ?? ''));
$action = $_GET['a'] ?? '';
$shareValid = false;
$share = null;
$needCode = false;

if ($token !== '') {
    $share = cd_find_valid_share($token);
    if ($share) {
        cd_session_start();
        $verifiedCode = $_SESSION['cd_share_' . $share['token']] ?? null;
        if ($share['extract_code'] !== null && $verifiedCode === null) {
            $needCode = true;
        } else {
            $shareValid = true;
        }
    }
}

/* ---------- JSON 接口 ---------- */
if ($action !== '') {
    if ($action === 'verify') {
        $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
        $code = trim((string)($body['code'] ?? ''));
        $sh = cd_find_valid_share($token);
        if (!$sh) {
            cd_fail('分享不存在或已过期', 404);
        }
        if ($sh['extract_code'] === null) { cd_ok(); }
        if (!hash_equals((string)$sh['extract_code'], $code)) {
            cd_fail('提取码错误', 403);
        }
        cd_session_start();
        $_SESSION['cd_share_' . $sh['token']] = $code;
        cd_ok();
    }
    if ($action === 'browse') {
        $sh = cd_find_valid_share($token);
        if (!$sh) {
            cd_fail('分享不存在或已过期', 404);
        }
        cd_session_start();
        if ($sh['extract_code'] !== null) {
            $vc = $_SESSION['cd_share_' . $sh['token']] ?? null;
            if ($vc === null || !hash_equals((string)$sh['extract_code'], (string)$vc)) {
                cd_fail('需要提取码', 401);
            }
        }
        $dirParam = (int)($_GET['dir'] ?? 0);
        $rootId = (int)$sh['node_id'];
        $dirId = $dirParam > 0 ? $dirParam : $rootId;
        if (!cd_in_subtree((int)$sh['owner_id'], $rootId, $dirId)) {
            cd_fail('无权访问', 403);
        }
        $db = cd_db();
        // 面包屑（分享根为起点；root 层由前端单独显示，chain 只含其下层级）
        $chain = [];
        $cur = $dirId;
        for ($i = 0; $i < 64; $i++) {
            $st = $db->prepare('SELECT id, parent_id, name FROM cd_nodes WHERE id = ? AND owner_id = ?');
            $st->execute([$cur, (int)$sh['owner_id']]);
            $row = $st->fetch();
            if (!$row) break;
            if ((int)$row['id'] !== $rootId) {
                array_unshift($chain, ['id' => (int)$row['id'], 'name' => $row['name']]);
            }
            if ((int)$row['id'] === $rootId || $row['parent_id'] === null) break;
            $cur = (int)$row['parent_id'];
        }
        $st = $db->prepare('SELECT id, name, is_dir, size, mime, updated_at FROM cd_nodes
                            WHERE parent_id = ? AND owner_id = ? AND deleted_at IS NULL
                            ORDER BY is_dir DESC, name COLLATE utf8mb4_unicode_ci');
        $st->execute([$dirId, (int)$sh['owner_id']]);
        $items = [];
        foreach ($st->fetchAll() as $r) {
            $items[] = [
                'id' => (int)$r['id'], 'name' => $r['name'], 'is_dir' => (bool)$r['is_dir'],
                'size' => (int)$r['size'], 'mime' => $r['mime'], 'updated_at' => $r['updated_at'],
            ];
        }
        // 单文件分享：浏览列表返回该文件自身（访客页直接可预览/下载）
        if (!$sh['node_is_dir'] && $dirId === $rootId) {
            $st = $db->prepare('SELECT id, name, is_dir, size, mime, updated_at FROM cd_nodes WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
            $st->execute([$rootId, (int)$sh['owner_id']]);
            if ($rn = $st->fetch()) {
                $items[] = [
                    'id' => (int)$rn['id'], 'name' => $rn['name'], 'is_dir' => false,
                    'size' => (int)$rn['size'], 'mime' => $rn['mime'], 'updated_at' => $rn['updated_at'],
                ];
            }
        }
        cd_ok(['items' => $items, 'crumbs' => $chain, 'root' => ['id' => $rootId, 'name' => (string)$sh['node_name']]]);
    }
    cd_fail('未知操作');
}

/* ---------- 访问计数（页面渲染一次） ---------- */
if ($shareValid) {
    cd_session_start();
    if (empty($_SESSION['cd_share_viewed_' . $token])) {
        $_SESSION['cd_share_viewed_' . $token] = 1;
        try {
            cd_db()->prepare('UPDATE cd_shares SET view_count = view_count + 1 WHERE id = ?')->execute([$share['id']]);
        } catch (Throwable $e) {}
    }
}

$h = function (?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$shareName = $share ? $share['node_name'] : '';
$shareIsDir = $share ? (bool)$share['node_is_dir'] : false;
$ownerName = '';
if ($share) {
    $st = cd_db()->prepare('SELECT username FROM cd_users WHERE id = ?');
    $st->execute([(int)$share['owner_id']]);
    $ownerName = (string)($st->fetchColumn() ?: '未知用户');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CYBER DISK // 分享中心</title>
<style>
:root{
  --bg:#03060d; --panel:rgba(10,18,32,.82); --line:rgba(0,240,255,.28);
  --cyan:#00f0ff; --magenta:#ff2ec4; --green:#00ff9c; --warn:#ffb347; --danger:#ff3860;
  --text:#c8e6ff; --dim:#5f7f9e; --mono:'JetBrains Mono','Cascadia Code',Consolas,'Courier New',monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  background:var(--bg); color:var(--text); font-family:var(--mono);
  font-size:14px; overflow-x:hidden;
}
body::before{
  content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
  background-image:
    radial-gradient(ellipse 80% 50% at 50% -10%, rgba(0,240,255,.12), transparent),
    radial-gradient(ellipse 60% 40% at 100% 100%, rgba(255,46,196,.08), transparent),
    linear-gradient(rgba(0,240,255,.045) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,240,255,.045) 1px, transparent 1px);
  background-size:100% 100%,100% 100%,42px 42px,42px 42px;
}
body::after{
  content:''; position:fixed; inset:0; z-index:1; pointer-events:none; opacity:.5;
  background:repeating-linear-gradient(0deg, rgba(0,0,0,.12) 0 1px, transparent 1px 3px);
}
#stage{position:relative; z-index:2; max-width:1080px; margin:0 auto; padding:28px 20px 60px}
/* HUD 面板 */
.hud{position:relative; background:var(--panel); border:1px solid var(--line);
  box-shadow:0 0 24px rgba(0,240,255,.08), inset 0 0 40px rgba(0,240,255,.03); backdrop-filter:blur(4px)}
.hud::before,.hud::after{content:''; position:absolute; width:14px; height:14px; pointer-events:none}
.hud::before{top:-1px; left:-1px; border-top:2px solid var(--cyan); border-left:2px solid var(--cyan)}
.hud::after{bottom:-1px; right:-1px; border-bottom:2px solid var(--cyan); border-right:2px solid var(--cyan)}
/* 标题 */
#head{display:flex; align-items:center; justify-content:space-between; margin-bottom:22px}
.logo{font-size:22px; font-weight:700; letter-spacing:2px; color:var(--cyan);
  text-shadow:0 0 12px rgba(0,240,255,.7); position:relative; display:inline-block}
.logo .zh{color:var(--magenta); text-shadow:0 0 12px rgba(255,46,196,.6)}
.logo::after{content:'▍'; color:var(--green); animation:blink 1.1s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.tag{font-size:11px; color:var(--dim); letter-spacing:3px}
/* 分享信息卡 */
#shareCard{padding:26px; margin-bottom:22px}
.fi{display:flex; gap:16px; align-items:center}
.fico{width:64px; height:64px; flex:none; display:flex; align-items:center; justify-content:center;
  border:1px solid var(--line); background:rgba(0,240,255,.05); font-size:11px; font-weight:700;
  color:var(--cyan); letter-spacing:1px; text-shadow:0 0 8px rgba(0,240,255,.6)}
.fname{font-size:19px; color:#eaf8ff; word-break:break-all; text-shadow:0 0 10px rgba(0,240,255,.35)}
.meta{margin-top:8px; font-size:12px; color:var(--dim)}
.meta b{color:var(--text); font-weight:500}
/* 提取码 */
#codeBox{max-width:430px; margin:0 auto; padding:34px 30px; text-align:center}
#codeBox h2{color:var(--cyan); font-size:16px; letter-spacing:2px; margin-bottom:6px}
#codeBox p{color:var(--dim); font-size:12px; margin-bottom:20px}
.codeRow{display:flex; gap:10px}
input.fld{
  flex:1; background:rgba(0,12,24,.9); border:1px solid var(--line); color:var(--cyan);
  font-family:var(--mono); font-size:18px; letter-spacing:8px; padding:12px 14px; outline:none;
  text-align:center; transition:.2s;
}
input.fld:focus{border-color:var(--cyan); box-shadow:0 0 14px rgba(0,240,255,.35), inset 0 0 12px rgba(0,240,255,.06)}
.btn{
  background:rgba(0,240,255,.08); border:1px solid var(--line); color:var(--cyan);
  font-family:var(--mono); font-size:13px; letter-spacing:2px; padding:10px 22px; cursor:pointer;
  transition:.18s; user-select:none; white-space:nowrap;
}
.btn:hover{background:rgba(0,240,255,.18); box-shadow:0 0 16px rgba(0,240,255,.45); text-shadow:0 0 8px rgba(0,240,255,.8)}
.btn.warn{border-color:rgba(255,46,196,.5); color:var(--magenta); background:rgba(255,46,196,.07)}
.btn.warn:hover{box-shadow:0 0 16px rgba(255,46,196,.4)}
.btn.small{padding:6px 12px; font-size:12px; letter-spacing:1px}
/* 列表 */
#browser{padding:0}
.crumbs{display:flex; flex-wrap:wrap; gap:4px; padding:14px 18px; border-bottom:1px solid rgba(0,240,255,.15);
  font-size:13px; color:var(--dim); align-items:center}
.crumbs a{color:var(--cyan); cursor:pointer; text-decoration:none}
.crumbs a:hover{text-shadow:0 0 8px rgba(0,240,255,.8)}
.crumbs .sep{color:var(--dim); margin:0 2px}
.crumbs .cur{color:#eaf8ff}
table{width:100%; border-collapse:collapse}
thead th{font-size:11px; letter-spacing:2px; color:var(--dim); text-align:left; padding:10px 14px;
  border-bottom:1px solid rgba(0,240,255,.15); font-weight:500; text-transform:uppercase}
tbody tr{border-bottom:1px solid rgba(0,240,255,.06); transition:.15s}
tbody tr:hover{background:rgba(0,240,255,.05); box-shadow:inset 3px 0 0 var(--cyan)}
tbody td{padding:10px 14px; font-size:13px}
.name{color:#eaf8ff; cursor:pointer; word-break:break-all}
.name:hover{text-shadow:0 0 8px rgba(0,240,255,.7); color:var(--cyan)}
.dim{color:var(--dim); font-size:12px; white-space:nowrap}
.badge{display:inline-block; padding:1px 8px; font-size:10px; letter-spacing:1px; border:1px solid; margin-right:6px}
.b-dir{color:var(--warn); border-color:rgba(255,179,71,.4); background:rgba(255,179,71,.06)}
.b-img{color:var(--green); border-color:rgba(0,255,156,.4); background:rgba(0,255,156,.06)}
.b-vid{color:var(--magenta); border-color:rgba(255,46,196,.4); background:rgba(255,46,196,.06)}
.b-aud{color:var(--cyan); border-color:rgba(0,240,255,.4); background:rgba(0,240,255,.06)}
.b-doc{color:#7ab8ff; border-color:rgba(122,184,255,.4); background:rgba(122,184,255,.06)}
.b-zip{color:var(--warn); border-color:rgba(255,179,71,.4); background:rgba(255,179,71,.06)}
.b-oth{color:var(--dim); border-color:rgba(95,127,158,.5)}
.op{display:flex; gap:6px; justify-content:flex-end}
.empty{padding:60px 20px; text-align:center; color:var(--dim); font-size:13px; letter-spacing:2px}
/* 错误页 */
#errBox{max-width:460px; margin:60px auto; padding:40px 34px; text-align:center}
#errBox .code{font-size:44px; color:var(--danger); text-shadow:0 0 18px rgba(255,56,96,.6); letter-spacing:4px; margin-bottom:10px}
#errBox p{color:var(--dim); font-size:13px; margin-bottom:22px}
/* 预览弹层 */
#preview{position:fixed; inset:0; z-index:50; display:none; align-items:center; justify-content:center;
  background:rgba(2,5,12,.88); backdrop-filter:blur(6px)}
#preview.on{display:flex}
#preview .box{max-width:92vw; min-width:560px; max-height:88vh; padding:14px; position:relative; display:flex; flex-direction:column}
#preview .box img{max-width:86vw; max-height:78vh; display:block; border:1px solid var(--line)}
#preview .box video,#preview .box audio,#preview .box iframe{max-width:86vw; max-height:78vh; display:block}
#preview .pv-title{font-size:13px; color:var(--bright); margin-bottom:10px; text-align:center; word-break:break-all; padding-right:34px; text-shadow:0 0 8px rgba(0,240,255,.3)}
#preview .cap{margin-top:10px; color:var(--dim); font-size:12px; text-align:center; word-break:break-all}
#preview .x{position:absolute; top:-16px; right:-16px; width:32px; height:32px; line-height:30px; text-align:center;
  background:#0a1220; border:1px solid var(--danger); color:var(--danger); cursor:pointer; font-size:16px}
#preview .x:hover{background:rgba(255,56,96,.2); box-shadow:0 0 14px rgba(255,56,96,.5)}
#txtView{max-width:86vw; max-height:74vh; overflow:auto; background:rgba(0,10,20,.95); border:1px solid var(--line);
  padding:16px; font-size:13px; line-height:1.6; color:var(--text); white-space:pre-wrap; word-break:break-all}
/* toast */
#toasts{position:fixed; top:18px; right:18px; z-index:99; display:flex; flex-direction:column; gap:8px}
.toast{background:rgba(6,14,26,.95); border:1px solid var(--line); color:var(--text); padding:10px 16px;
  font-size:13px; box-shadow:0 0 18px rgba(0,240,255,.2); animation:tin .25s ease}
.toast.err{border-color:rgba(255,56,96,.6); color:var(--danger)}
.toast.ok{border-color:rgba(0,255,156,.6); color:var(--green)}
@keyframes tin{from{transform:translateX(30px); opacity:0}}
footer{margin-top:26px; text-align:center; color:var(--dim); font-size:11px; letter-spacing:3px}
@media (max-width:640px){ .hide-sm{display:none} }
</style>
</head>
<body>
<div id="stage">
  <div id="head">
    <div class="logo">CYBER<span style="color:#eaf8ff">·</span>DISK <span class="zh">星辰网盘</span></div>
    <div class="tag">SHARE GATEWAY // 分享通道</div>
  </div>

<?php if (!$share): ?>
  <div class="hud" id="errBox">
    <div class="code">404</div>
    <p>分享不存在、已被取消或链接不完整</p>
    <button class="btn" onclick="location.reload()">重新加载</button>
  </div>
<?php elseif ($needCode): ?>
  <div class="hud" id="codeBox">
    <h2>◈ 加密传输通道 ◈</h2>
    <p>「<?= $h($shareName) ?>」已加密，请输入访问提取码</p>
    <div class="codeRow">
      <input class="fld" id="codeInput" maxlength="16" placeholder="提取码" autocomplete="off">
      <button class="btn" id="codeBtn" onclick="doVerify()">解 锁</button>
    </div>
    <p id="codeErr" style="color:var(--danger); margin-top:14px; display:none"></p>
  </div>
<?php else: ?>
  <div class="hud" id="shareCard">
    <div class="fi">
      <div class="fico" id="bigIco"><?= $shareIsDir ? 'DIR' : 'FILE' ?></div>
      <div>
        <div class="fname"><?= $h($shareName) ?></div>
        <div class="meta">分享者 <b><?= $h($ownerName) ?></b>
          <?php if ($share['expires_at']): ?> · 有效期至 <b><?= $h($share['expires_at']) ?></b><?php else: ?> · <b style="color:var(--green)">永久有效</b><?php endif; ?>
          <?php if ($share['extract_code'] !== null): ?> · 提取码 <b style="color:var(--warn)"><?= $h($share['extract_code']) ?></b><?php else: ?> · <b style="color:var(--green)">公开访问</b><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="hud" id="browser">
    <div class="crumbs" id="crumbs"></div>
    <div id="listWrap">
      <table>
        <thead><tr>
          <th style="width:46%">文件名称</th>
          <th class="hide-sm">类型</th>
          <th class="hide-sm">大小</th>
          <th class="hide-sm">修改时间</th>
          <th style="width:110px; text-align:right">操作</th>
        </tr></thead>
        <tbody id="tbody"></tbody>
      </table>
      <div class="empty" id="emptyBox" style="display:none">[ EMPTY // 目录为空 ]</div>
    </div>
  </div>
<?php endif; ?>

  <footer>CYBER DISK TERMINAL · SECURE SHARE NODE · <span style="color:var(--green)">LINK ACTIVE</span></footer>
</div>

<div id="preview" onclick="if(event.target===this)closePreview()"><div class="box hud" id="pvBox"></div></div>
<div id="toasts"></div>

<script>
const S = <?= $shareValid ? json_encode(['token' => $token, 'rootIsDir' => $shareIsDir, 'rootId' => (int)$share['node_id']], JSON_UNESCAPED_UNICODE) : 'null' ?>;
const API = {
  async get(p) {
    const r = await fetch(p, {credentials: 'same-origin'});
    return r.json();
  },
  async post(p, body) {
    const r = await fetch(p, {method: 'POST', credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
    return r.json();
  }
};
function esc(s){return String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function fmtSize(n){
  if(n < 1024) return n + ' B';
  const u = ['KB','MB','GB','TB']; let i = -1;
  do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
  return n.toFixed(n >= 100 ? 0 : 1) + ' ' + u[i];
}
function fmtTime(s){ if(!s) return '-'; return s.replace('T',' ').slice(0,16); }
function toast(msg, type){
  const d = document.createElement('div');
  d.className = 'toast ' + (type || '');
  d.textContent = msg;
  document.getElementById('toasts').appendChild(d);
  setTimeout(()=>{ d.style.opacity = '0'; d.style.transition = '.4s'; setTimeout(()=>d.remove(), 400); }, 2600);
}
function catOf(isDir, name, mime){
  if (isDir) return ['b-dir','DIR'];
  const ext = (name.split('.').pop() || '').toLowerCase();
  if ((mime||'').startsWith('image/') || ['png','jpg','jpeg','gif','webp','bmp','svg'].includes(ext)) return ['b-img','IMG'];
  if ((mime||'').startsWith('video/') || ['mp4','mkv','avi','mov','flv','webm'].includes(ext)) return ['b-vid','VID'];
  if ((mime||'').startsWith('audio/') || ['mp3','wav','flac','aac','ogg','m4a'].includes(ext)) return ['b-aud','AUD'];
  if ((mime||'').startsWith('text/') || ['pdf','doc','docx','xls','xlsx','ppt','pptx','md','txt','csv','html'].includes(ext)) return ['b-doc','DOC'];
  if (['zip','rar','7z','tar','gz'].includes(ext)) return ['b-zip','ZIP'];
  return ['b-oth', (ext.slice(0,4) || 'BIN').toUpperCase()];
}
/* ---------- 提取码 ---------- */
async function doVerify(){
  const code = document.getElementById('codeInput').value.trim();
  if (!code) { showCodeErr('请输入提取码'); return; }
  const r = await API.post('share.php?a=verify&s=<?= $h($token) ?>', {code});
  if (!r.ok) { showCodeErr(r.error || '校验失败'); return; }
  location.reload();
}
function showCodeErr(m){
  const e = document.getElementById('codeErr');
  e.textContent = '✖ ' + m; e.style.display = 'block';
}
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && document.getElementById('codeInput') === document.activeElement) doVerify();
});
/* ---------- 浏览 ---------- */
let state = {crumbs: [], curId: null, items: []};
async function loadDir(dirId){
  const r = await API.get(`share.php?a=browse&s=<?= $h($token) ?>&dir=${dirId ?? ''}`);
  if (!r.ok) { toast(r.error || '加载失败', 'err'); return; }
  state.crumbs = r.crumbs; state.curId = dirId; state.items = r.items;
  renderCrumbs(r.root);
  renderList(r.items);
}
function renderCrumbs(root){
  const el = document.getElementById('crumbs');
  let html = `<span class="tag" style="margin-right:8px">PATH //</span>`;
  const isRoot = state.curId === null;
  html += `<a onclick="loadDir(null)" style="${isRoot?'color:#eaf8ff':''}">▶ ${esc(root.name)}</a>`;
  state.crumbs.forEach((c, i) => {
    const last = i === state.crumbs.length - 1;
    html += `<span class="sep">/</span><a onclick="loadDir(${c.id})" style="${last?'color:#eaf8ff':''}">${esc(c.name)}</a>`;
  });
  el.innerHTML = html;
}
function renderList(items){
  const tb = document.getElementById('tbody');
  const empty = document.getElementById('emptyBox');
  tb.innerHTML = '';
  empty.style.display = items.length ? 'none' : 'block';
  items.forEach(it => {
    const [cls, label] = catOf(it.is_dir, it.name, it.mime);
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><span class="badge ${cls}">${label}</span><span class="name" ${it.is_dir ? `onclick="loadDir(${it.id})"` : ''}>${esc(it.name)}</span></td>
      <td class="dim hide-sm">${it.is_dir ? '文件夹' : (it.mime||'-').split('/')[0]}</td>
      <td class="dim hide-sm">${it.is_dir ? '-' : fmtSize(it.size)}</td>
      <td class="dim hide-sm">${fmtTime(it.updated_at)}</td>
      <td><div class="op">${it.is_dir
        ? `<button class="btn small" data-act="zip" data-id="${it.id}">打包下载</button>`
        : `<button class="btn small" data-act="pv" data-id="${it.id}">预览</button>
           <button class="btn small" data-act="dl" data-id="${it.id}">下载</button>`}</div></td>`;
    tb.appendChild(tr);
  });
  tb.querySelectorAll('button[data-act]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = parseInt(btn.dataset.id, 10);
      const it = state.items.find(x => x.id === id);
      if (!it) return;
      if (btn.dataset.act === 'zip') zipDir(id, it.name);
      else if (btn.dataset.act === 'pv') preview(id, it.name);
      else dlFile(id);
    });
  });
}
function dlUrl(id){ return `download.php?s=${S.token}&id=${id}`; }
function dlFile(id){ window.location.href = dlUrl(id); toast('开始下载…', 'ok'); }
function zipDir(id, name){ window.location.href = dlUrl(id); toast('正在打包「' + name + '」，完成后自动下载…', 'ok'); }
/* ---------- 预览 ---------- */
function closePreview(){
  const p = document.getElementById('preview');
  p.classList.remove('on');
  document.getElementById('pvBox').innerHTML = '';
}
async function preview(id, name){
  const ext = (name.split('.').pop() || '').toLowerCase();
  const url = dlUrl(id);
  const box = document.getElementById('pvBox');
  let inner = '';
  if (['png','jpg','jpeg','gif','webp','bmp','svg'].includes(ext)) {
    inner = `<img src="${url}&inline=1" alt="${esc(name)}">`;
  } else if (['mp4','webm','mov','mkv','flv','m4v'].includes(ext)) {
    inner = `<video src="${url}&inline=1" controls autoplay></video>`;
  } else if (['mp3','wav','flac','aac','ogg','m4a'].includes(ext)) {
    inner = `<audio src="${url}&inline=1" controls autoplay></audio>`;
  } else if (ext === 'pdf') {
    inner = `<iframe src="${url}&inline=1" style="width:80vw;height:72vh;border:1px solid rgba(0,240,255,.28);background:#0a1220"></iframe>`;
  } else {
    const r = await fetch(url + '&inline=1', {credentials:'same-origin'});
    const text = await r.text();
    inner = `<div id="txtView">${esc(text.slice(0, 200000))}</div>`;
  }
  box.innerHTML = `<div class="pv-title">◈ ${esc(name)}</div>` + inner + `<div class="cap">ESC 关闭</div><div class="x" onclick="closePreview()">✕</div>`;
  document.getElementById('preview').classList.add('on');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePreview(); });
/* ---------- init ---------- */
if (S) {
  // browse 端已兼容单文件分享（返回文件自身条目），统一从根加载
  loadDir(null);
}
</script>
</body>
</html>
