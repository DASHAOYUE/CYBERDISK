<?php
/**
 * CyberDisk 主界面（赛博朋克风 SPA）
 * 登录 + 文件管理 + 上传下载 + 分享 + 回收站 + 统计
 */
declare(strict_types=1);
require __DIR__ . '/lib.php';
cd_ensure_schema();
header('X-Content-Type-Options: nosniff');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CYBER DISK // 星辰网盘</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' fill='%2303060d'/%3E%3Cpath d='M16 4 L27 9 V16 C27 23 22 27 16 29 C10 27 5 23 5 16 V9 Z' fill='none' stroke='%2300f0ff' stroke-width='2'/%3E%3Ccircle cx='16' cy='14' r='4' fill='%23ff2ec4'/%3E%3Cpath d='M16 18 v6' stroke='%2300ff9c' stroke-width='2'/%3E%3C/svg%3E">
<style>
:root{
  --bg:#03060d; --panel:rgba(10,18,32,.86); --panel2:rgba(6,12,24,.92);
  --line:rgba(0,240,255,.28); --line-dim:rgba(0,240,255,.12);
  --cyan:#00f0ff; --magenta:#ff2ec4; --green:#00ff9c; --warn:#ffb347; --danger:#ff3860; --gold:#ffe066;
  --text:#c8e6ff; --bright:#eaf8ff; --dim:#5f7f9e;
  --mono:'JetBrains Mono','Cascadia Code',Consolas,'Courier New',monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{background:var(--bg);color:var(--text);font-family:var(--mono);font-size:14px;overflow:hidden}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background-image:
  radial-gradient(ellipse 80% 50% at 50% -10%, rgba(0,240,255,.10), transparent),
  radial-gradient(ellipse 55% 45% at 100% 105%, rgba(255,46,196,.07), transparent),
  linear-gradient(rgba(0,240,255,.04) 1px, transparent 1px),
  linear-gradient(90deg, rgba(0,240,255,.04) 1px, transparent 1px);
  background-size:100% 100%,100% 100%,44px 44px,44px 44px}
body::after{content:'';position:fixed;inset:0;z-index:1;pointer-events:none;opacity:.45;
  background:repeating-linear-gradient(0deg, rgba(0,0,0,.14) 0 1px, transparent 1px 3px)}
::-webkit-scrollbar{width:8px;height:8px}
::-webkit-scrollbar-track{background:rgba(0,240,255,.04)}
::-webkit-scrollbar-thumb{background:rgba(0,240,255,.3);border-radius:0}
::-webkit-scrollbar-thumb:hover{background:rgba(0,240,255,.55)}
button{font-family:var(--mono)}
input,select,textarea{font-family:var(--mono)}
a{color:var(--cyan)}
/* ===== HUD 面板 ===== */
.hud{position:relative;background:var(--panel);border:1px solid var(--line);
  box-shadow:0 0 24px rgba(0,240,255,.07), inset 0 0 40px rgba(0,240,255,.02);backdrop-filter:blur(5px)}
.hud::before,.hud::after{content:'';position:absolute;width:12px;height:12px;pointer-events:none;z-index:3}
.hud::before{top:-1px;left:-1px;border-top:2px solid var(--cyan);border-left:2px solid var(--cyan)}
.hud::after{bottom:-1px;right:-1px;border-bottom:2px solid var(--cyan);border-right:2px solid var(--cyan)}
/* ===== 按钮 ===== */
.btn{background:rgba(0,240,255,.07);border:1px solid var(--line);color:var(--cyan);font-size:12px;
  letter-spacing:1px;padding:8px 16px;cursor:pointer;transition:.18s;user-select:none;white-space:nowrap}
.btn:hover{background:rgba(0,240,255,.16);box-shadow:0 0 14px rgba(0,240,255,.4);text-shadow:0 0 8px rgba(0,240,255,.8)}
.btn.primary{background:rgba(0,240,255,.18);border-color:var(--cyan);color:#0a1520;font-weight:700}
.btn.primary:hover{background:var(--cyan);color:#020a10;box-shadow:0 0 22px rgba(0,240,255,.7)}
.btn.mag{border-color:rgba(255,46,196,.5);color:var(--magenta);background:rgba(255,46,196,.07)}
.btn.mag:hover{background:rgba(255,46,196,.16);box-shadow:0 0 14px rgba(255,46,196,.4)}
.btn.danger{border-color:rgba(255,56,96,.5);color:var(--danger);background:rgba(255,56,96,.07)}
.btn.danger:hover{background:rgba(255,56,96,.16);box-shadow:0 0 14px rgba(255,56,96,.4)}
.btn.small{padding:5px 10px;font-size:11px;letter-spacing:0}
.btn:disabled{opacity:.35;cursor:not-allowed;box-shadow:none}
/* ===== 登录 ===== */
#loginView{position:fixed;inset:0;z-index:20;display:flex;align-items:center;justify-content:center}
#loginCard{width:400px;max-width:92vw;padding:42px 36px 34px}
.glitch{position:relative;font-size:30px;font-weight:700;letter-spacing:4px;color:var(--cyan);
  text-shadow:0 0 16px rgba(0,240,255,.75);margin-bottom:4px;white-space:nowrap}
.glitch::before,.glitch::after{content:attr(data-text);position:absolute;left:0;top:0;width:100%;overflow:hidden}
.glitch::before{color:var(--magenta);animation:g1 3.2s infinite linear alternate-reverse;clip-path:inset(20% 0 55% 0);opacity:.8}
.glitch::after{color:var(--green);animation:g2 2.7s infinite linear alternate-reverse;clip-path:inset(60% 0 15% 0);opacity:.8}
@keyframes g1{0%,90%,100%{transform:none}92%{transform:translate(-3px,1px)}96%{transform:translate(3px,-1px)}}
@keyframes g2{0%,88%,100%{transform:none}90%{transform:translate(3px,-1px)}94%{transform:translate(-3px,1px)}}
.login-sub{color:var(--dim);font-size:11px;letter-spacing:4px;margin-bottom:30px}
.login-sub b{color:var(--magenta);font-weight:400}
.fld-wrap{margin-bottom:16px}
.fld-wrap label{display:block;font-size:10px;letter-spacing:3px;color:var(--dim);margin-bottom:6px}
.fld{width:100%;background:rgba(0,12,24,.9);border:1px solid var(--line);color:var(--bright);
  font-size:14px;padding:11px 13px;outline:none;transition:.2s;letter-spacing:1px}
.fld:focus{border-color:var(--cyan);box-shadow:0 0 14px rgba(0,240,255,.3), inset 0 0 10px rgba(0,240,255,.05)}
.fld::placeholder{color:rgba(95,127,158,.55)}
#loginBtn{width:100%;margin-top:10px;padding:13px;font-size:14px;letter-spacing:6px;font-weight:700}
#loginErr{color:var(--danger);font-size:12px;margin-top:14px;min-height:16px}
#loginFoot{margin-top:26px;padding-top:14px;border-top:1px dashed rgba(0,240,255,.15);
  color:var(--dim);font-size:10px;letter-spacing:2px;line-height:1.9}
#loginFoot .ok{color:var(--green)}
/* ===== 主布局 ===== */
#mainView{position:fixed;inset:0;z-index:10;display:flex;flex-direction:column;padding:14px;gap:14px}
#topbar{display:flex;align-items:center;gap:16px;padding:10px 18px}
.brand{font-size:18px;font-weight:700;letter-spacing:2px;color:var(--cyan);text-shadow:0 0 12px rgba(0,240,255,.65);white-space:nowrap;cursor:pointer}
.brand .zh{color:var(--magenta);text-shadow:0 0 10px rgba(255,46,196,.5)}
.brand::after{content:'▍';color:var(--green);animation:blink 1.1s steps(1) infinite;margin-left:2px}
@keyframes blink{50%{opacity:0}}
#searchBox{flex:1;max-width:430px;display:flex;gap:8px}
#searchInput{flex:1}
#storeBar{display:flex;align-items:center;gap:10px;min-width:210px}
#storeBar .sb-track{flex:1;height:8px;background:rgba(0,240,255,.08);border:1px solid var(--line-dim);position:relative;overflow:hidden}
#storeBar .sb-fill{position:absolute;inset:0;width:0%;background:linear-gradient(90deg,var(--cyan),var(--magenta));
  box-shadow:0 0 10px rgba(0,240,255,.6);transition:width .6s}
#storeBar .sb-txt{font-size:11px;color:var(--dim);white-space:nowrap}
.userbox{display:flex;align-items:center;gap:10px;white-space:nowrap}
.userbox .uname{color:var(--bright);font-size:13px}
.userbox .utag{font-size:9px;letter-spacing:1px;padding:2px 7px;border:1px solid rgba(0,255,156,.5);color:var(--green)}
#body{flex:1;display:flex;gap:14px;min-height:0}
/* ===== 侧栏 ===== */
#sidebar{width:230px;flex:none;display:flex;flex-direction:column;padding:14px 12px;gap:6px;overflow-y:auto}
.nav-item{display:flex;align-items:center;gap:10px;padding:11px 14px;cursor:pointer;color:var(--dim);
  border:1px solid transparent;transition:.15s;font-size:13px;letter-spacing:1px;position:relative}
.nav-item .nico{width:18px;text-align:center;font-size:14px}
.nav-item:hover{color:var(--cyan);background:rgba(0,240,255,.05);border-color:var(--line-dim)}
.nav-item.on{color:var(--bright);background:rgba(0,240,255,.1);border-color:var(--line);box-shadow:inset 3px 0 0 var(--cyan)}
.nav-item .cnt{margin-left:auto;font-size:10px;color:var(--dim);border:1px solid rgba(95,127,158,.4);padding:1px 7px}
.nav-item.on .cnt{color:var(--cyan);border-color:var(--line)}
#statsBlock{margin-top:14px;padding:16px 12px}
#statsBlock h4{font-size:10px;letter-spacing:3px;color:var(--dim);margin-bottom:12px;font-weight:500}
.ring-wrap{display:flex;justify-content:center;margin-bottom:14px}
.ring-txt{fill:var(--bright);font-size:15px;font-weight:700}
.ring-sub{fill:var(--dim);font-size:9px;letter-spacing:2px}
.cat-row{margin-bottom:10px}
.cat-row .cat-top{display:flex;justify-content:space-between;font-size:10px;color:var(--dim);margin-bottom:4px}
.cat-row .cat-top b{color:var(--text);font-weight:400}
.cat-track{height:5px;background:rgba(0,240,255,.07)}
.cat-fill{height:100%;background:var(--cc,var(--cyan));box-shadow:0 0 8px var(--cc,var(--cyan))}
#sysBlock{margin-top:auto;padding:12px 14px;font-size:9px;letter-spacing:2px;color:var(--dim);line-height:2}
#sysBlock .v{color:var(--green)}
/* ===== 主区 ===== */
#main{flex:1;display:flex;flex-direction:column;min-width:0;min-height:0}
#toolbar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--line-dim);flex-wrap:wrap}
#toolbar .tsep{width:1px;height:22px;background:var(--line-dim)}
#crumbs{flex:1;display:flex;align-items:center;gap:4px;font-size:13px;color:var(--dim);min-width:160px;overflow:hidden;white-space:nowrap}
#crumbs a{cursor:pointer;text-decoration:none}
#crumbs a:hover{text-shadow:0 0 8px rgba(0,240,255,.8)}
#crumbs .cur{color:var(--bright)}
#selInfo{font-size:11px;color:var(--warn);letter-spacing:1px}
#content{flex:1;overflow-y:auto;padding:6px 8px 20px;position:relative}
/* 表格 */
table{width:100%;border-collapse:collapse}
thead th{position:sticky;top:0;background:#060d18f2;font-size:10px;letter-spacing:2px;color:var(--dim);
  text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);font-weight:500;z-index:2;backdrop-filter:blur(4px)}
tbody tr{border-bottom:1px solid rgba(0,240,255,.05);transition:.15s;cursor:default}
tbody tr:hover{background:rgba(0,240,255,.05);box-shadow:inset 3px 0 0 var(--cyan)}
tbody tr.selected{background:rgba(0,240,255,.09);box-shadow:inset 3px 0 0 var(--magenta)}
tbody td{padding:9px 12px;font-size:13px}
.rowchk{width:15px;height:15px;accent-color:#00f0ff;cursor:pointer}
.fname{color:var(--bright);cursor:pointer;word-break:break-all;max-width:420px;display:inline-block;vertical-align:middle}
.fname:hover{color:var(--cyan);text-shadow:0 0 8px rgba(0,240,255,.6)}
.dim{color:var(--dim);font-size:12px;white-space:nowrap}
.badge{display:inline-block;padding:1px 7px;font-size:9px;letter-spacing:1px;border:1px solid;margin-right:8px;vertical-align:middle}
.b-dir{color:var(--warn);border-color:rgba(255,179,71,.4);background:rgba(255,179,71,.06)}
.b-img{color:var(--green);border-color:rgba(0,255,156,.4);background:rgba(0,255,156,.06)}
.b-vid{color:var(--magenta);border-color:rgba(255,46,196,.4);background:rgba(255,46,196,.06)}
.b-aud{color:var(--cyan);border-color:rgba(0,240,255,.4);background:rgba(0,240,255,.06)}
.b-doc{color:#7ab8ff;border-color:rgba(122,184,255,.4);background:rgba(122,184,255,.06)}
.b-zip{color:var(--gold);border-color:rgba(255,224,102,.4);background:rgba(255,224,102,.06)}
.b-oth{color:var(--dim);border-color:rgba(95,127,158,.5)}
.ops{display:flex;gap:5px;justify-content:flex-end;opacity:.45;transition:.15s}
tr:hover .ops{opacity:1}
.empty{padding:70px 20px;text-align:center;color:var(--dim);font-size:13px;letter-spacing:3px}
.empty .e-ico{font-size:34px;color:rgba(0,240,255,.25);margin-bottom:14px}
/* 网格 */
#gridWrap{display:grid;grid-template-columns:repeat(auto-fill,minmax(148px,1fr));gap:14px;padding:8px}
.gcell{position:relative;border:1px solid var(--line-dim);background:rgba(6,12,24,.9);padding:10px;cursor:pointer;transition:.18s}
.gcell:hover{border-color:var(--cyan);box-shadow:0 0 14px rgba(0,240,255,.25)}
.gcell.selected{border-color:var(--magenta);box-shadow:0 0 14px rgba(255,46,196,.35)}
.gthumb{height:92px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:rgba(0,10,20,.8);margin-bottom:8px}
.gthumb img{max-width:100%;max-height:100%;object-fit:cover}
.gthumb .gext{font-size:24px;font-weight:700;color:var(--cyan);text-shadow:0 0 12px rgba(0,240,255,.6);letter-spacing:2px}
.gname{font-size:11px;color:var(--bright);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gsize{font-size:9px;color:var(--dim);margin-top:3px}
.gchk{position:absolute;top:6px;left:6px;z-index:2}
/* ===== 账号/角色管理 ===== */
#adminWrap{padding:10px 6px}
#adminWrap .adm-title{font-size:12px;color:var(--dim);letter-spacing:2px;margin:4px 6px 12px}
#adminWrap .adm-title b{color:var(--cyan);font-weight:500}
.perm-chip{display:inline-block;padding:2px 8px;margin:2px 3px 2px 0;font-size:10px;color:var(--cyan);
  border:1px solid rgba(0,240,255,.3);background:rgba(0,240,255,.06);letter-spacing:1px}
.perm-chip.off{color:var(--dim);border-color:var(--line-dim);background:transparent;text-decoration:line-through}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px;margin:6px 0}
.perm-grid label{display:flex;gap:8px;align-items:flex-start;font-size:12px;color:var(--text);cursor:pointer;
  border:1px solid var(--line-dim);padding:8px 10px;letter-spacing:0;line-height:1.5}
.perm-grid label:hover{border-color:var(--cyan)}
.perm-grid label.on{border-color:rgba(0,240,255,.5);background:rgba(0,240,255,.07)}
.perm-grid input{margin-top:2px;accent-color:#00f0ff}
.role-tag{display:inline-block;padding:1px 8px;font-size:10px;border:1px solid;letter-spacing:1px}
.rt-admin{color:var(--magenta);border-color:rgba(255,46,196,.5);background:rgba(255,46,196,.07)}
.rt-member{color:var(--green);border-color:rgba(0,255,156,.4);background:rgba(0,255,156,.06)}
.rt-guest{color:var(--dim);border-color:var(--line-dim)}
.rt-custom{color:var(--gold);border-color:rgba(255,224,102,.4);background:rgba(255,224,102,.06)}
/* ===== 底部音乐播放器 ===== */
body.music-on #content{padding-bottom:64px}
body.music-on #upPanel{bottom:64px}
#musicBar{position:fixed;left:0;right:0;bottom:0;z-index:45;height:48px;display:flex;align-items:center;gap:10px;
  padding:0 14px;background:rgba(4,10,20,.96);border-top:1px solid rgba(0,240,255,.35);
  box-shadow:0 -6px 24px rgba(0,240,255,.12);backdrop-filter:blur(8px)}
.mb-btn{background:transparent;border:1px solid var(--line-dim);color:var(--cyan);font-size:13px;
  padding:5px 9px;cursor:pointer;letter-spacing:1px;transition:.15s;white-space:nowrap;font-family:inherit}
.mb-btn:hover{border-color:var(--cyan);box-shadow:0 0 10px rgba(0,240,255,.3)}
.mb-play{min-width:34px;color:var(--bright)}
.mb-info{min-width:150px;max-width:280px;overflow:hidden}
.mb-title{font-size:12px;color:var(--bright);white-space:nowrap;text-overflow:ellipsis;overflow:hidden;
  text-shadow:0 0 8px rgba(0,240,255,.4)}
.mb-time{font-size:10px;color:var(--dim);letter-spacing:1px}
.mb-track{flex:1;height:8px;background:rgba(0,240,255,.08);border:1px solid var(--line-dim);cursor:pointer;position:relative;min-width:120px}
.mb-track:hover .mb-fill{background:linear-gradient(90deg,#00f0ff,#ff2ec4)}
.mb-fill{height:100%;width:0;background:var(--cyan);box-shadow:0 0 8px rgba(0,240,255,.6);transition:width .2s linear}
.mb-vol{display:flex;align-items:center;gap:6px;color:var(--dim);font-size:12px}
.mb-vol input{width:70px;accent-color:#00f0ff;cursor:pointer}
#musicListPanel{position:fixed;right:14px;bottom:56px;width:380px;max-width:92vw;max-height:52vh;z-index:46;
  background:rgba(4,10,20,.97);border:1px solid var(--cyan);box-shadow:0 0 30px rgba(0,240,255,.25);
  display:flex;flex-direction:column}
.ml-head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid var(--line);
  font-size:11px;color:var(--cyan);letter-spacing:2px}
.ml-body{overflow-y:auto;flex:1}
.ml-row{display:flex;align-items:center;gap:10px;padding:7px 12px;cursor:pointer;border-bottom:1px solid rgba(0,240,255,.06);transition:.12s}
.ml-row:hover{background:rgba(0,240,255,.07)}
.ml-row.on{background:rgba(0,240,255,.12);box-shadow:inset 3px 0 0 var(--cyan)}
.ml-idx{width:26px;color:var(--dim);font-size:10px;text-align:right;flex-shrink:0}
.ml-name{flex:1;font-size:12px;color:var(--text);word-break:break-all;line-height:1.4}
.ml-row.on .ml-name{color:var(--cyan);text-shadow:0 0 8px rgba(0,240,255,.5)}
.ml-size{color:var(--dim);font-size:10px;flex-shrink:0}
@media (max-width:900px){ .mb-info{max-width:140px} .mb-vol{display:none} }
/* ===== 上传队列 ===== */
#upPanel{position:fixed;right:18px;bottom:18px;width:340px;z-index:40;display:none}
#upPanel.on{display:block}
#upPanel .up-head{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;
  border-bottom:1px solid var(--line-dim);font-size:11px;letter-spacing:2px;color:var(--cyan)}
.up-item{padding:9px 14px;border-bottom:1px solid rgba(0,240,255,.06);font-size:11px}
.up-item .un{color:var(--bright);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:6px}
.up-item .ustat{display:flex;justify-content:space-between;color:var(--dim);font-size:10px;margin-bottom:4px}
.up-item .ustat .st-ok{color:var(--green)}
.up-item .ustat .st-err{color:var(--danger)}
.up-track{height:4px;background:rgba(0,240,255,.08)}
.up-fill{height:100%;width:0%;background:linear-gradient(90deg,var(--cyan),var(--magenta));box-shadow:0 0 8px rgba(0,240,255,.5);transition:width .2s}
/* ===== 右键菜单 ===== */
#ctxMenu{position:fixed;z-index:60;display:none;min-width:170px;background:var(--panel2);
  border:1px solid var(--line);box-shadow:0 0 20px rgba(0,240,255,.2);padding:5px}
#ctxMenu .ci{padding:9px 14px;font-size:12px;cursor:pointer;color:var(--text);letter-spacing:1px;display:flex;gap:10px}
#ctxMenu .ci:hover{background:rgba(0,240,255,.12);color:var(--cyan)}
#ctxMenu .ci.danger:hover{background:rgba(255,56,96,.12);color:var(--danger)}
#ctxMenu .cdiv{height:1px;background:var(--line-dim);margin:4px 6px}
/* ===== 弹窗 ===== */
.modal-mask{position:fixed;inset:0;z-index:70;background:rgba(2,5,12,.8);backdrop-filter:blur(5px);
  display:none;align-items:center;justify-content:center}
.modal-mask.on{display:flex}
.modal{width:430px;max-width:92vw;max-height:86vh;display:flex;flex-direction:column}
.modal .m-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--line-dim)}
.modal .m-head .mt{font-size:13px;letter-spacing:2px;color:var(--cyan)}
.modal .m-head .mx{cursor:pointer;color:var(--dim);font-size:16px;padding:0 4px}
.modal .m-head .mx:hover{color:var(--danger)}
.modal .m-body{padding:18px;overflow-y:auto}
.modal .m-foot{padding:12px 18px;border-top:1px solid var(--line-dim);display:flex;justify-content:flex-end;gap:10px}
.m-row{margin-bottom:14px}
.m-row label{display:block;font-size:10px;letter-spacing:2px;color:var(--dim);margin-bottom:6px}
.share-result{margin-top:6px;padding:14px;border:1px dashed rgba(0,255,156,.4);background:rgba(0,255,156,.04)}
.share-result .sr-line{display:flex;gap:8px;margin-bottom:8px;align-items:center}
.share-result .sr-val{flex:1;font-size:12px;color:var(--bright);word-break:break-all;user-select:all}
.code-chip{display:inline-block;padding:4px 12px;border:1px solid rgba(255,179,71,.6);color:var(--warn);
  font-size:16px;letter-spacing:6px;font-weight:700;text-shadow:0 0 10px rgba(255,179,71,.6);background:rgba(255,179,71,.06)}
/* 移动目录树 */
.tree-box{max-height:300px;overflow-y:auto;border:1px solid var(--line-dim);background:rgba(0,10,20,.7);padding:8px}
.tree-node{display:flex;align-items:center;gap:6px;padding:7px 8px;cursor:pointer;font-size:12px;color:var(--text);border:1px solid transparent}
.tree-node:hover{background:rgba(0,240,255,.07);border-color:var(--line-dim)}
.tree-node.on{background:rgba(0,240,255,.12);border-color:var(--line);color:var(--cyan)}
.tree-kids{margin-left:18px;border-left:1px dashed rgba(0,240,255,.15);padding-left:6px}
.tree-load{color:var(--dim);font-size:10px;padding:6px;letter-spacing:1px}
/* Office 文档预览 */
#pvBox .pv-loading{display:flex;align-items:center;gap:12px;padding:24px 10px;color:var(--cyan);font-size:12px;letter-spacing:2px}
#pvBox .pv-loading .spin{width:18px;height:18px;border:2px solid rgba(0,240,255,.25);border-top-color:var(--cyan);border-radius:50%;animation:pvspin 1s linear infinite}
@keyframes pvspin{to{transform:rotate(360deg)}}
.docview{max-width:84vw;max-height:74vh;overflow:auto;background:#fff;border:1px solid var(--line)}
.docview .doc-body{min-width:420px;max-width:860px;margin:0 auto;padding:28px 38px;color:#1a1a1a;font:14px/1.8 "Microsoft YaHei","Segoe UI",Arial,sans-serif}
.docview .doc-body h1,.docview .doc-body h2,.docview .doc-body h3{margin:.6em 0 .3em;line-height:1.3}
.docview .doc-body p{margin:.5em 0}
.docview .doc-body table{border-collapse:collapse;margin:8px 0}
.docview .doc-body td,.docview .doc-body th{border:1px solid #999;padding:4px 10px}
.docview .doc-body img{max-width:100%}
.xwrap{max-width:84vw;max-height:70vh;overflow:auto;border:1px solid var(--line);background:rgba(0,10,20,.6)}
.tabsbar{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
.btab{padding:5px 14px;border:1px solid var(--line);color:var(--text);font-size:12px;cursor:pointer;background:rgba(0,20,40,.6);letter-spacing:1px}
.btab.on{color:var(--cyan);border-color:var(--cyan);box-shadow:0 0 10px rgba(0,240,255,.25)}
.btab:hover{border-color:var(--cyan)}
.xwrap table{border-collapse:collapse;margin:0;min-width:100%}
.xwrap th,.xwrap td{border:1px solid rgba(0,240,255,.18);padding:5px 12px;font-size:12px;color:var(--bright);white-space:nowrap;background:rgba(0,18,36,.75)}
.xwrap th{color:var(--cyan);letter-spacing:1px;background:rgba(0,30,55,.85)}
.xtab{display:none}.xtab.on{display:block;padding:10px}
.xwarn{padding:8px 10px;color:var(--warn);font-size:11px}
.pwrap{max-width:84vw;max-height:74vh;overflow:auto;border:1px solid var(--line);background:rgba(0,8,16,.8);padding:10px;display:flex;flex-direction:column;gap:14px;align-items:center}
.drag-src{opacity:.45}
.drop-hook{outline:2px dashed var(--cyan);outline-offset:-2px;background:rgba(0,240,255,.08)!important;cursor:copy}
/* 压缩包内容列表 */
.modal.wide{width:780px}
.zlist{max-height:50vh;overflow:auto;border:1px solid var(--line-dim);background:rgba(0,10,20,.7)}
.zlist table{font-size:12px}
.zlist thead th{position:sticky;top:0;background:#060d18f2;z-index:2}
.zlist tbody td{padding:7px 10px;border-bottom:1px solid rgba(0,240,255,.06);color:var(--text);word-break:break-all}
.zlist tbody tr:hover{background:rgba(0,240,255,.05);box-shadow:none}
.zlist tbody tr{cursor:default}
/* 预览 */
#preview{position:fixed;inset:0;z-index:80;background:rgba(2,5,12,.9);backdrop-filter:blur(8px);
  display:none;align-items:center;justify-content:center}
#preview.on{display:flex}
#pvBox{max-width:92vw;min-width:560px;max-height:88vh;padding:16px;position:relative;display:flex;flex-direction:column}
#pvBox .pv-title{font-size:13px;color:var(--bright);margin-bottom:12px;text-align:center;word-break:break-all;padding-right:34px;text-shadow:0 0 8px rgba(0,240,255,.3)}
#pvBox img{max-width:84vw;max-height:74vh;display:block;border:1px solid var(--line)}
#pvBox video,#pvBox audio,#pvBox iframe{max-width:84vw;max-height:74vh;display:block}
#pvBox .txtview{max-width:84vw;max-height:72vh;overflow:auto;background:rgba(0,10,20,.95);border:1px solid var(--line);
  padding:16px;font-size:12px;line-height:1.7;white-space:pre-wrap;word-break:break-all;color:var(--text)}
#pvBox .cap{margin-top:10px;color:var(--dim);font-size:11px;text-align:center;word-break:break-all}
.pv-x{position:absolute;top:-14px;right:-14px;width:30px;height:30px;line-height:28px;text-align:center;
  background:#0a1220;border:1px solid var(--danger);color:var(--danger);cursor:pointer;font-size:15px;z-index:5}
.pv-x:hover{background:rgba(255,56,96,.2);box-shadow:0 0 14px rgba(255,56,96,.5)}
/* 拖拽遮罩 */
#dropMask{position:fixed;inset:0;z-index:90;display:none;align-items:center;justify-content:center;
  background:rgba(2,8,16,.85);backdrop-filter:blur(4px)}
#dropMask.on{display:flex}
#dropMask .dz{width:60%;height:60%;border:2px dashed var(--cyan);display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:14px;box-shadow:0 0 40px rgba(0,240,255,.25), inset 0 0 60px rgba(0,240,255,.08);
  animation:pulse 1.6s infinite}
@keyframes pulse{50%{border-color:var(--magenta);box-shadow:0 0 40px rgba(255,46,196,.3), inset 0 0 60px rgba(255,46,196,.06)}}
#dropMask .dz-t{font-size:20px;color:var(--cyan);letter-spacing:6px;text-shadow:0 0 16px rgba(0,240,255,.7)}
#dropMask .dz-s{font-size:11px;color:var(--dim);letter-spacing:3px}
/* toast */
#toasts{position:fixed;top:18px;right:18px;z-index:99;display:flex;flex-direction:column;gap:8px}
.toast{background:rgba(6,14,26,.96);border:1px solid var(--line);color:var(--text);padding:10px 16px;
  font-size:12px;box-shadow:0 0 18px rgba(0,240,255,.18);animation:tin .25s ease;max-width:380px;word-break:break-all}
.toast.err{border-color:rgba(255,56,96,.6);color:var(--danger)}
.toast.ok{border-color:rgba(0,255,156,.6);color:var(--green)}
.toast.warn{border-color:rgba(255,179,71,.6);color:var(--warn)}
@keyframes tin{from{transform:translateX(30px);opacity:0}}
.hidden{display:none!important}
@media (max-width:900px){ #sidebar{width:180px} .hide-md{display:none} }
</style>
</head>
<body>

<!-- ============ 登录视图 ============ -->
<div id="loginView">
  <div class="hud" id="loginCard">
    <div class="glitch" data-text="CYBER DISK">CYBER DISK</div>
    <div class="login-sub">星辰网盘 · <b>私人数据终端</b> · v1.0</div>
    <div class="fld-wrap">
      <label>USER ID / 账号</label>
      <input class="fld" id="loginUser" placeholder="输入账号" autocomplete="username">
    </div>
    <div class="fld-wrap">
      <label>ACCESS KEY / 密码</label>
      <input class="fld" id="loginPass" type="password" placeholder="输入密码" autocomplete="current-password">
    </div>
    <button class="btn primary" id="loginBtn" onclick="doLogin()">连 接 终 端</button>
    <div id="loginErr"></div>
    <div id="loginFoot">
      &gt; NODE <span class="ok">LOCALHOST:8081</span><br>
      &gt; CIPHER <span class="ok">AES-SESSION</span> · STORAGE <span class="ok">MySQL 8.0</span>
    </div>
  </div>
</div>

<!-- ============ 主视图 ============ -->
<div id="mainView" class="hidden">
  <div class="hud" id="topbar">
    <div class="brand" onclick="navTo('files')" title="全部文件">CYBER<span style="color:#eaf8ff">·</span>DISK <span class="zh">星辰网盘</span></div>
    <div id="searchBox">
      <input class="fld" id="searchInput" placeholder="全域搜索文件名… (回车)" style="font-size:12px;padding:8px 12px">
    </div>
    <div id="storeBar">
      <div class="sb-track"><div class="sb-fill" id="sbFill"></div></div>
      <div class="sb-txt" id="sbTxt">--</div>
    </div>
    <div class="userbox">
      <span class="utag">OPERATOR</span>
      <span class="uname" id="unameBox">--</span>
      <button class="btn small" id="btnTopSettings" onclick="openSettings()" title="上传/分片设置">设置</button>
      <button class="btn small" onclick="openChangePw()">密码</button>
      <button class="btn small danger" onclick="doLogout()">断开</button>
    </div>
  </div>

  <div id="body">
    <div class="hud" id="sidebar">
      <div class="nav-item on" data-nav="files" onclick="navTo('files')"><span class="nico">▦</span>全部文件</div>
      <div class="nav-item" data-nav="recent" onclick="navTo('recent')"><span class="nico">⚡</span>最近文件</div>
      <div class="nav-item" data-nav="shares" onclick="navTo('shares')"><span class="nico">⇄</span>我的分享 <span class="cnt" id="cntShares">0</span></div>
      <div class="nav-item" data-nav="recycle" onclick="navTo('recycle')"><span class="nico">♻</span>回收站 <span class="cnt" id="cntRecycle">0</span></div>
      <div class="nav-item hidden" data-nav="users" id="navUsers" onclick="navTo('users')"><span class="nico">◍</span>账号管理</div>
      <div class="nav-item hidden" data-nav="roles" id="navRoles" onclick="navTo('roles')"><span class="nico">◈</span>角色管理</div>
      <div class="hud" id="statsBlock" style="border-color:var(--line-dim)">
        <h4>STORAGE / 存储舱</h4>
        <div class="ring-wrap">
          <svg width="110" height="110" viewBox="0 0 110 110">
            <circle cx="55" cy="55" r="46" fill="none" stroke="rgba(0,240,255,.1)" stroke-width="8"/>
            <circle id="ringFill" cx="55" cy="55" r="46" fill="none" stroke="url(#ringGrad)" stroke-width="8"
              stroke-dasharray="0 999" stroke-linecap="butt" transform="rotate(-90 55 55)" style="transition:stroke-dasharray .8s"/>
            <defs><linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#00f0ff"/><stop offset="1" stop-color="#ff2ec4"/></linearGradient></defs>
            <text class="ring-txt" x="55" y="53" text-anchor="middle" id="ringPct">0%</text>
            <text class="ring-sub" x="55" y="68" text-anchor="middle">USED</text>
          </svg>
        </div>
        <div id="catBox"></div>
        <div style="font-size:10px;color:var(--dim);margin-top:8px" id="cntBox">--</div>
      </div>
      <div class="hud" id="sysBlock" style="border-color:var(--line-dim)">
        &gt; SYS <span class="v">ONLINE</span><br>
        &gt; CORE <span class="v">PHP 8.3</span><br>
        &gt; DB <span class="v">MySQL 8.0</span><br>
        &gt; MODE <span class="v">PRIVATE-NODE</span>
      </div>
    </div>

    <div class="hud" id="main">
      <div id="toolbar">
        <button class="btn primary" id="btnUploadFile" onclick="document.getElementById('fileInput').click()">⬆ 上传文件</button>
        <button class="btn" id="btnDirUpload" onclick="document.getElementById('dirInput').click()">⬆ 上传文件夹</button>
        <span class="tsep"></span>
        <button class="btn" id="btnMkdir" onclick="openMkdir()">＋ 新建文件夹</button>
        <button class="btn" id="btnZipSel" onclick="zipCreate([...state.sel])">🗜 压缩选中</button>
        <button class="btn" id="btnBatchMove" onclick="batchMove()">⇢ 移动选中</button>
        <button class="btn" id="btnBatchTrash" onclick="batchTrash()">✖ 删除选中</button>
        <span class="tsep"></span>
        <button class="btn small" id="viewToggle" onclick="toggleView()">▤ 网格</button>
        <button class="btn primary hidden" id="btnNewUser" onclick="openUserEdit(null)">＋ 新增账号</button>
        <button class="btn primary hidden" id="btnNewRole" onclick="openRoleEdit(null)">＋ 新增角色</button>
        <div id="crumbs"></div>
        <div id="selInfo"></div>
      </div>
      <div id="content">
        <div id="listWrap">
          <table>
            <thead><tr>
              <th style="width:34px"><input type="checkbox" class="rowchk" id="chkAll" onclick="toggleAll(this.checked)"></th>
              <th style="width:46%">名称</th>
              <th class="hide-md">类型</th>
              <th>大小</th>
              <th class="hide-md">修改时间</th>
              <th style="width:250px;text-align:right">操作</th>
            </tr></thead>
            <tbody id="tbody"></tbody>
          </table>
        </div>
        <div id="gridWrap" class="hidden"></div>
        <div id="adminWrap" class="hidden"></div>
        <div class="empty hidden" id="emptyBox">
          <div class="e-ico">◈ ▩ ◈</div>
          <div id="emptyTxt">[ EMPTY // 当前目录为空 ]</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- 上传队列 -->
<div class="hud" id="upPanel">
  <div class="up-head"><span>▲ UPLOAD QUEUE / 上传队列</span><span style="cursor:pointer" onclick="clearUpPanel()">✕</span></div>
  <div id="upItems" style="max-height:260px;overflow-y:auto"></div>
</div>

<!-- 底部音乐播放器 -->
<div id="musicBar" class="hidden">
  <button class="mb-btn" id="mbPrev" title="上一首">⏮</button>
  <button class="mb-btn mb-play" id="mbPlay" title="播放 / 暂停">▶</button>
  <button class="mb-btn" id="mbNext" title="下一首">⏭</button>
  <div class="mb-info">
    <div class="mb-title" id="mbTitle">—</div>
    <div class="mb-time" id="mbTime">0:00 / 0:00</div>
  </div>
  <div class="mb-track" id="mbTrack"><div class="mb-fill" id="mbFill"></div></div>
  <button class="mb-btn" id="mbMode" title="播放模式（点击切换）">顺序</button>
  <div class="mb-vol"><span>♪</span><input type="range" id="mbVol" min="0" max="100" value="90"></div>
  <button class="mb-btn" id="mbList" title="播放清单">☰ 清单 <span id="mbCount">0</span></button>
</div>
<div id="musicListPanel" class="hidden">
  <div class="ml-head"><span>PLAYLIST / 播放清单</span><button class="btn small" id="mlClose">✕</button></div>
  <div class="ml-body" id="mlBody"></div>
</div>

<!-- 右键菜单 -->
<div id="ctxMenu"></div>

<!-- 弹窗 -->
<div class="modal-mask" id="modalMask" onclick="if(event.target===this)closeModal()">
  <div class="hud modal">
    <div class="m-head"><span class="mt" id="mTitle">TITLE</span><span class="mx" onclick="closeModal()">✕</span></div>
    <div class="m-body" id="mBody"></div>
    <div class="m-foot" id="mFoot"></div>
  </div>
</div>

<!-- 预览 -->
<div id="preview" onclick="if(event.target===this)closePreview()"><div class="hud" id="pvBox"></div></div>

<!-- 拖拽 -->
<div id="dropMask"><div class="dz"><div class="dz-t">释放以注入数据</div><div class="dz-s">DROP FILES INTO CYBER DISK // 支持多文件</div></div></div>

<div id="toasts"></div>
<input type="file" id="fileInput" multiple hidden>
<input type="file" id="dirInput" webkitdirectory hidden>

<script>
/* ================= 基础 ================= */
const $ = s => document.querySelector(s);
const API = {
  async get(q) { const r = await fetch('api.php?' + q, {credentials:'same-origin'}); return r.json(); },
  async post(a, body) {
    const r = await fetch('api.php?a=' + a, {method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'}, body: JSON.stringify(body||{})});
    return r.json();
  },
  async postForm(a, form) {
    const r = await fetch('api.php?a=' + a, {method:'POST', credentials:'same-origin', body: form});
    return r.json();
  }
};
function esc(s){return String(s??'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function fmtSize(n){
  if(n < 1024) return n + ' B';
  const u = ['KB','MB','GB','TB']; let i = -1; let x = n;
  do { x /= 1024; i++; } while (x >= 1024 && i < u.length-1);
  return x.toFixed(x >= 100 ? 0 : 1) + ' ' + u[i];
}
function fmtTime(s){ return s ? s.slice(0,16) : '-'; }
function toast(msg, type){
  const d = document.createElement('div');
  d.className = 'toast ' + (type||'');
  d.textContent = msg;
  $('#toasts').appendChild(d);
  setTimeout(()=>{ d.style.opacity='0'; d.style.transition='.4s'; setTimeout(()=>d.remove(),400); }, 2800);
}
async function copyText(t){
  try { await navigator.clipboard.writeText(t); toast('已复制到剪贴板', 'ok'); }
  catch(e) {
    const ta = document.createElement('textarea');
    ta.value = t; document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); ta.remove(); toast('已复制到剪贴板', 'ok');
  }
}

/* ================= 状态 ================= */
const state = {
  user:null, nav:'files', dirId:null, crumbs:[], items:[], sel:new Set(),
  view:'list', stats:null, shares:[], recycle:[], searching:false, dirCache:{},
  serverLimits:null, // {uploadMax, postMax} PHP 上传上限（字节，0=未知）
  chunk:null,        // {mode,sizeMb,wantBytes,effectiveBytes,clamped} 分片配置
  perms:[],          // 当前账号权限键集合
  permCatalog:{}     // 权限目录 {key: label}
};
/* 权限判断：后台下发 perms，空集兜底全有（兼容旧后端） */
function hasPerm(key){
  if(!state.perms || !state.perms.length) return true;
  return state.perms.includes(key);
}

/* ================= 登录 ================= */
async function doLogin(){
  const u = $('#loginUser').value.trim(), p = $('#loginPass').value;
  if(!u || !p){ $('#loginErr').textContent = '✖ 请输入账号与密码'; return; }
  $('#loginBtn').disabled = true; $('#loginBtn').textContent = '连接中…';
  const r = await API.post('login', {username:u, password:p});
  $('#loginBtn').disabled = false; $('#loginBtn').textContent = '连 接 终 端';
  if(!r.ok){ $('#loginErr').textContent = '✖ ' + (r.error||'登录失败'); return; }
  sessionStorage.removeItem('cd_kicked');
  boot();
}
$('#loginPass').addEventListener('keydown', e=>{ if(e.key==='Enter') doLogin(); });
$('#loginUser').addEventListener('keydown', e=>{ if(e.key==='Enter') doLogin(); });
async function doLogout(){
  await API.post('logout');
  state.user = null;
  $('#mainView').classList.add('hidden');
  $('#loginView').style.display = 'flex';
}
function openChangePw(){
  openModal('SECURITY / 修改密码', `
    <div class="m-row"><label>CURRENT / 原密码</label>
      <input class="fld" id="pwOld" type="password" autocomplete="current-password"></div>
    <div class="m-row"><label>NEW / 新密码（至少 6 位）</label>
      <input class="fld" id="pwNew" type="password" autocomplete="new-password"></div>
    <div class="m-row"><label>CONFIRM / 确认新密码</label>
      <input class="fld" id="pwNew2" type="password" autocomplete="new-password"></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn primary" id="pwBtn">确认修改</button>`);
  $('#pwBtn').onclick = async ()=>{
    const o = $('#pwOld').value, n = $('#pwNew').value, n2 = $('#pwNew2').value;
    if(!o){ toast('请输入原密码','warn'); return; }
    if(n.length < 6){ toast('新密码至少 6 位','warn'); return; }
    if(n !== n2){ toast('两次输入的新密码不一致','warn'); return; }
    const r = await API.post('changepw', {oldPassword: o, newPassword: n});
    if(!r.ok){ toast(r.error || '修改失败', 'err'); return; }
    toast('密码已更新，下次登录请使用新密码','ok');
    closeModal();
  };
  $('#pwOld').focus();
}

/* ================= 上传设置（分片大小可自定义） ================= */
function chunkModeSel(){ const el = document.querySelector('input[name=chunkMode]:checked'); return el ? el.value : 'auto'; }
/* 本地预演与后端一致的收紧规则，用于弹窗实时提示 */
function chunkPreview(mode, mb){
  const lim = state.serverLimits || {};
  const want = (mode === 'custom' && mb > 0) ? mb * 1048576 : 4 * 1024 * 1024;
  let eff = want;
  if (lim.postMax > 0) eff = Math.min(eff, Math.max(262144, lim.postMax - 1048576));
  if (lim.uploadMax > 0) eff = Math.min(eff, Math.max(262144, lim.uploadMax - 262144));
  eff = Math.max(262144, Math.floor(eff));
  return {want, eff, clamped: eff < want};
}
function setChunkMb(n){
  const i = $('#chunkMb');
  if (i && !i.disabled && i.dataset.ro !== '1') { i.value = n; updateChunkNote(); }
}
function updateChunkNote(){
  const box = $('#chunkNote');
  if (!box) return null;
  const mode = chunkModeSel();
  const input = $('#chunkMb');
  if (input) input.disabled = (input.dataset.ro === '1') || mode !== 'custom';
  const mb = Math.max(0, parseInt(input ? input.value : '0', 10) || 0);
  const p = chunkPreview(mode, mb);
  const lim = state.serverLimits || {};
  const lu = lim.uploadMax > 0 ? fmtSize(lim.uploadMax) : '未知';
  const lp = lim.postMax > 0 ? fmtSize(lim.postMax) : '未知';
  let h = `主机上限：upload_max_filesize <b style="color:var(--text)">${lu}</b> · post_max_size <b style="color:var(--text)">${lp}</b><br>`;
  h += `实际分片大小：<b style="color:#00f0ff">${fmtSize(p.eff)}</b>`;
  if (p.clamped) h += ` <span style="color:#ffb347">（受主机上限收紧，原设定 ${fmtSize(p.want)}）</span>`;
  h += `<br>超过 <b style="color:#00ff9c">${fmtSize(directUploadLimit())}</b> 的文件自动改用分片上传`;
  box.innerHTML = h;
  return p;
}
async function openSettings(){
  const [sr, mr] = await Promise.all([API.get('a=settings'), API.get('a=music')]);
  if (!sr.ok) { toast(sr.error || '读取设置失败', 'err'); return; }
  const c = sr.chunk, lim = sr.limits || {};
  const canEdit = !!sr.can_edit, maxMb = sr.chunkMaxMb || 512;
  state.chunk = c;
  state.serverLimits = {uploadMax: lim.uploadMax || 0, postMax: lim.postMax || 0};
  const dis = canEdit ? '' : ' disabled';
  const mst = mr.ok ? mr : {enabled:false, dirId:null, dirName:null};
  mst.selDir = mst.dirId || 0;
  const canStream = hasPerm('download');
  const dirCur = mst.enabled
    ? (mst.dirId ? ('「' + esc(mst.dirName) + '」（含子文件夹）') : '整个网盘')
    : '未启用';
  openModal('SETTINGS / 系统设置', `
    <div class="m-row"><label>UPLOAD / 上传设置${canEdit ? '' : '（仅管理员可修改，当前只读）'}</label>
      <div style="display:flex;gap:20px;align-items:center;font-size:13px;color:var(--text)">
        <label style="display:inline-flex;gap:6px;align-items:center;letter-spacing:0;margin:0">
          <input type="radio" name="chunkMode" value="auto"${c.mode === 'custom' ? '' : ' checked'}${dis}> 自动（推荐）</label>
        <label style="display:inline-flex;gap:6px;align-items:center;letter-spacing:0;margin:0">
          <input type="radio" name="chunkMode" value="custom"${c.mode === 'custom' ? ' checked' : ''}${dis}> 自定义</label>
      </div></div>
    <div class="m-row"><label>CHUNK SIZE / 自定义分片大小（1 ~ ${maxMb} MB）</label>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input class="fld" id="chunkMb" type="number" min="1" max="${maxMb}" step="1" value="${c.sizeMb || 8}" style="max-width:110px;padding:8px 10px"${dis}>
        <span style="font-size:12px;color:var(--dim)">MB</span>
        ${[1,2,4,8,16,32,64].map(n => `<span class="btn small" onclick="setChunkMb(${n})">${n}M</span>`).join('')}
      </div></div>
    <div id="chunkNote" style="font-size:11px;line-height:1.9;color:var(--dim);border:1px dashed var(--line-dim);padding:10px 12px;background:rgba(0,240,255,.03)"></div>
    <div style="font-size:10px;color:var(--dim);margin-top:8px;line-height:1.8">
      · 分片上传用于绕过主机 post_max_size 限制：大文件切成多片逐片发送，服务端按序合并。${canEdit ? '' : '<br>· 上传设置为全局配置，当前账号只读。'}
    </div>
    <div style="height:1px;background:var(--line-dim);margin:16px 0"></div>
    <div class="m-row"><label>MUSIC PLAYER / 底部音乐播放器（个人设置${canStream ? '' : ' · 需要「下载」权限才能播放'}）</label>
      <label style="display:inline-flex;gap:8px;align-items:center;font-size:13px;color:var(--text);letter-spacing:0;margin:0">
        <input type="checkbox" id="musicOn"${mst.enabled ? ' checked' : ''} style="accent-color:#00f0ff">
        启用播放器（保存后自动加载清单并开始播放）</label></div>
    <div class="m-row"><label>MUSIC FOLDER / 音乐文件夹（含全部子文件夹；不选 = 整个网盘）</label>
      <div style="font-size:12px;color:var(--cyan);margin-bottom:6px" id="musicDirCur">当前：${dirCur}</div>
      <div class="tree-box" id="musicTree" style="max-height:150px"></div>
      <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
        <span class="btn small" id="musicClear" style="opacity:.7">清除选择（扫描整个网盘）</span>
        <span style="font-size:10px;color:var(--dim)">单击选中 · 双击展开子目录 · 支持 mp3 / wav / flac / ogg / m4a / aac / opus</span>
      </div></div>`,
    `<button class="btn" onclick="closeModal()">取消</button><button class="btn primary" id="setBtn">保存设置</button>`);
  const input = $('#chunkMb');
  if (input) input.dataset.ro = canEdit ? '' : '1';
  document.querySelectorAll('input[name=chunkMode]').forEach(el => { el.onchange = updateChunkNote; });
  if (input) input.oninput = updateChunkNote;
  updateChunkNote();
  // 音乐文件夹树（单击选中，双击展开）
  await buildTree($('#musicTree'), 0, true, (selId, name)=>{
    mst.selDir = selId;
    $('#musicDirCur').textContent = '已选择：' + (selId === 0 ? '根目录（整个网盘）' : name) + '（含子文件夹）';
  });
  $('#musicClear').onclick = ()=>{
    mst.selDir = 0;
    $('#musicDirCur').textContent = '已选择：根目录（整个网盘）';
    document.querySelectorAll('#musicTree .tree-node').forEach(n=>n.classList.remove('on'));
    const root = document.querySelector('#musicTree .tree-node'); if(root) root.classList.add('on');
  };
  $('#setBtn').onclick = async ()=>{
    // 分片设置（仅管理员）
    if (canEdit) {
      const mode = chunkModeSel();
      const mb = parseInt($('#chunkMb').value, 10) || 0;
      if (mode === 'custom' && (mb < 1 || mb > maxMb)) { toast('自定义分片大小需在 1 ~ ' + maxMb + ' MB 之间', 'warn'); return; }
      const r = await API.post('savesettings', {chunkMode: mode, chunkSizeMb: mb});
      if (!r.ok) { toast(r.error || '分片设置保存失败', 'err'); return; }
      state.chunk = r.chunk;
      if (r.limits) state.serverLimits = {uploadMax: r.limits.uploadMax || 0, postMax: r.limits.postMax || 0};
      toast('分片设置已保存：' + fmtSize(r.chunk.effectiveBytes) + (r.chunk.clamped ? '（受主机上限限制）' : ''), 'ok');
    }
    // 音乐播放器（个人）
    const musicOn = $('#musicOn').checked;
    const rm = await API.post('savemusic', {enabled: musicOn, dirId: mst.selDir || null});
    if (!rm.ok) { toast(rm.error || '音乐设置保存失败', 'err'); return; }
    toast(musicOn ? '音乐播放器已启用' : '音乐播放器已关闭', musicOn ? 'ok' : 'warn');
    closeModal();
    musicLoad(musicOn); // 启用时自动加载清单并播放（保存点击本身即用户手势，允许自动播放）
  };
}

/* ================= 启动 ================= */
async function boot(){
  const r = await API.get('a=me');
  if(!r.ok){ return; }
  state.user = r.user;
  state.perms = r.user.perms || [];
  state.permCatalog = r.user.permCatalog || {};
  // 服务器 PHP 上传上限（上传前预检，避免大文件白传）
  state.serverLimits = {
    uploadMax: r.user.upload_max_bytes || 0,
    postMax: r.user.post_max_bytes || 0
  };
  state.chunk = r.user.chunk || null;
  $('#unameBox').textContent = r.user.username;
  $('#unameBox').title = '角色：' + (r.user.role_name || '-');
  $('#loginView').style.display = 'none';
  $('#mainView').classList.remove('hidden');
  applyPerms();
  renderStats(r.user);
  navTo('files');
  refreshBadges();
  musicLoad(true); // 已启用播放器时自动加载清单并尝试播放
}

/* ================= 底部音乐播放器 ================= */
const music = {
  enabled:false, tracks:[], idx:-1, mode:(localStorage.getItem('cd_music_mode')||'seq'),
  audio:null, playing:false, hist:[], vol: parseFloat(localStorage.getItem('cd_music_vol')||'0.9'),
  pendingSeek:0, autoFailTold:false
};
$('#mbPlay').onclick = musicToggle;
$('#mbPrev').onclick = musicPrev;
$('#mbNext').onclick = musicNext;
$('#mbMode').onclick = musicToggleMode;
$('#mbList').onclick = ()=>{ $('#musicListPanel').classList.toggle('hidden'); musicHighlightList(); };
$('#mlClose').onclick = ()=> $('#musicListPanel').classList.add('hidden');
$('#mbVol').value = Math.round(music.vol*100);
$('#mbVol').addEventListener('input', e=>{
  music.vol = e.target.value/100;
  if(music.audio) music.audio.volume = music.vol;
  localStorage.setItem('cd_music_vol', String(music.vol));
});
$('#mbTrack').addEventListener('click', e=>{
  if(!music.audio || !isFinite(music.audio.duration) || music.audio.duration<=0) return;
  const rect = e.currentTarget.getBoundingClientRect();
  const pct = Math.min(1, Math.max(0, (e.clientX-rect.left)/rect.width));
  music.audio.currentTime = pct * music.audio.duration;
});
window.addEventListener('beforeunload', ()=>{
  if(music.audio && music.idx>=0) musicSavePos();
});
async function musicLoad(autoPlay){
  if(!hasPerm('download')){ musicBarShow(false); return; }
  const r = await API.get('a=music');
  if(!r.ok) return;
  music.enabled = !!r.enabled;
  music.tracks = r.tracks || [];
  musicBarShow(music.enabled);
  if(!music.enabled) return;
  $('#mbCount').textContent = music.tracks.length;
  musicRenderList();
  if(!music.tracks.length){
    music.idx = -1;
    $('#mbTitle').textContent = '[ 清单为空：上传音乐或去「设置」更换文件夹 ]';
    return;
  }
  // 恢复上次播放位置（按曲目 id 记忆）
  let startIdx = 0, seekT = 0;
  try {
    const last = JSON.parse(localStorage.getItem('cd_music_last')||'null');
    if(last && last.id){
      const i = music.tracks.findIndex(t=>t.id===last.id);
      if(i>=0){ startIdx = i; seekT = last.t||0; }
    }
  } catch(e){}
  musicPlayIndex(startIdx, !!autoPlay, seekT);
}
function musicPlayIndex(i, autoplay, seekT){
  const n = music.tracks.length;
  if(!n){ return; }
  if(i<0) i = n-1;
  if(i>=n) i = 0;
  music.idx = i;
  const t = music.tracks[i];
  if(!music.audio){
    music.audio = new Audio();
    music.audio.preload = 'auto';
    music.audio.addEventListener('timeupdate', musicOnTime);
    music.audio.addEventListener('ended', ()=>{ musicSavePos(); musicNext(true); });
    music.audio.addEventListener('play', ()=>{ music.playing = true; musicUpdateBtns(); });
    music.audio.addEventListener('pause', ()=>{ music.playing = false; musicUpdateBtns(); });
    music.audio.addEventListener('error', ()=>{
      const nm = music.tracks[music.idx] ? music.tracks[music.idx].name : '';
      toast('「'+nm+'」播放失败（格式不支持或文件异常），已跳过','err');
      setTimeout(()=>{ if(music.enabled) musicNext(true); }, 900);
    });
  }
  music.pendingSeek = seekT && seekT>0 ? seekT : 0;
  music.audio.src = 'download.php?id=' + t.id + '&inline=1';
  music.audio.volume = music.vol;
  if(music.pendingSeek){
    const once = ()=>{ if(music.pendingSeek && isFinite(music.audio.duration) && music.audio.duration>0){ music.audio.currentTime = Math.min(music.pendingSeek, music.audio.duration-1); } music.pendingSeek = 0; music.audio.removeEventListener('loadedmetadata', once); };
    music.audio.addEventListener('loadedmetadata', once);
  }
  if(autoplay){
    const p = music.audio.play();
    if(p && p.catch) p.catch(()=>{
      // 浏览器自动播放策略拦截：保持暂停态，提示用户点击
      music.playing = false; musicUpdateBtns();
      if(!music.autoFailTold){ music.autoFailTold = true; toast('浏览器阻止自动播放：点击底部播放键开始音乐','warn'); }
    });
  }
  musicUpdateInfo();
  musicUpdateBtns();
  musicHighlightList();
}
function musicToggle(){
  if(music.idx<0 || !music.audio || !music.audio.src){
    if(music.tracks.length) musicPlayIndex(music.idx>=0?music.idx:0, true);
    return;
  }
  if(music.audio.paused){ const p = music.audio.play(); if(p && p.catch) p.catch(()=>{}); }
  else { musicSavePos(); music.audio.pause(); }
}
function musicNext(auto){
  const n = music.tracks.length;
  if(!n) return;
  if(music.mode==='rand'){
    if(n===1){ musicPlayIndex(0,true); return; }
    let ni = music.idx;
    let guard = 0;
    while(ni===music.idx && guard++ < 20){ ni = Math.floor(Math.random()*n); }
    music.hist.push(music.idx);
    if(music.hist.length>200) music.hist.shift();
    musicPlayIndex(ni, true);
  } else {
    musicPlayIndex((music.idx+1)%n, true);
  }
}
function musicPrev(){
  const n = music.tracks.length;
  if(!n) return;
  if(music.mode==='rand' && music.hist.length){
    musicPlayIndex(music.hist.pop(), true);
  } else {
    musicPlayIndex((music.idx-1+n)%n, true);
  }
}
function musicToggleMode(){
  music.mode = music.mode==='rand' ? 'seq' : 'rand';
  localStorage.setItem('cd_music_mode', music.mode);
  musicUpdateBtns();
  toast(music.mode==='rand' ? '已切换：随机播放' : '已切换：顺序循环', 'ok');
}
function musicOnTime(){
  const a = music.audio;
  if(!a) return;
  const d = a.duration||0, c = a.currentTime||0;
  $('#mbFill').style.width = (d>0 ? (c/d*100) : 0) + '%';
  $('#mbTime').textContent = fmtMusicTime(c) + ' / ' + fmtMusicTime(d);
  // 每 5 秒记忆进度（刷新后可续播）
  if(music.idx>=0 && Math.floor(c)>0 && Math.floor(c)%5===0) musicSavePos();
}
function musicSavePos(){
  try {
    const t = music.tracks[music.idx];
    if(t && music.audio) localStorage.setItem('cd_music_last', JSON.stringify({id:t.id, t:music.audio.currentTime||0}));
  } catch(e){}
}
function fmtMusicTime(s){
  if(!isFinite(s) || s<0) s = 0;
  const m = Math.floor(s/60), x = Math.floor(s%60);
  return m + ':' + String(x).padStart(2,'0');
}
function musicBarShow(on){
  $('#musicBar').classList.toggle('hidden', !on);
  document.body.classList.toggle('music-on', !!on);
  if(!on) $('#musicListPanel').classList.add('hidden');
}
function musicUpdateInfo(){
  const t = music.tracks[music.idx];
  $('#mbTitle').textContent = t ? t.name : (music.enabled ? '[ 清单为空 ]' : '—');
  $('#mbTitle').title = t ? t.name : '';
}
function musicUpdateBtns(){
  $('#mbPlay').textContent = music.playing ? '⏸' : '▶';
  $('#mbMode').textContent = music.mode==='rand' ? '随机' : '顺序';
  $('#mbMode').title = music.mode==='rand' ? '当前：随机播放（点击切换为顺序循环）' : '当前：顺序循环（点击切换为随机播放）';
}
function musicRenderList(){
  const body = $('#mlBody');
  body.innerHTML = music.tracks.length
    ? music.tracks.map((t,i)=>`<div class="ml-row" data-i="${i}">
        <span class="ml-idx">${i+1}</span><span class="ml-name" title="${esc(t.name)}">${esc(t.name)}</span>
        <span class="ml-size">${fmtSize(t.size)}</span></div>`).join('')
    : '<div class="dim" style="padding:14px">清单为空</div>';
  body.querySelectorAll('.ml-row').forEach(row=>{
    row.onclick = ()=>{
      music.hist = [];
      musicPlayIndex(+row.dataset.i, true);
      if(window.innerWidth < 700) $('#musicListPanel').classList.add('hidden');
    };
  });
}
function musicHighlightList(){
  document.querySelectorAll('#mlBody .ml-row').forEach(r=>r.classList.toggle('on', +r.dataset.i===music.idx));
  const cur = document.querySelector('#mlBody .ml-row.on');
  if(cur) cur.scrollIntoView({block:'nearest'});
}

/* ================= 权限驱动的按钮显隐 ================= */
function applyPerms(){
  const up = hasPerm('upload'), dl = hasPerm('download');
  $('#btnUploadFile').classList.toggle('hidden', !up);
  $('#btnDirUpload').classList.toggle('hidden', !up);
  $('#btnMkdir').classList.toggle('hidden', !hasPerm('mkdir'));
  $('#btnZipSel').classList.toggle('hidden', !up);
  $('#btnBatchMove').classList.toggle('hidden', !hasPerm('move'));
  $('#btnBatchTrash').classList.toggle('hidden', !hasPerm('trash'));
  // 设置按钮始终显示：内含个人音乐播放器设置（分片设置为管理员字段，非管理员只读）
  $('#navUsers').classList.toggle('hidden', !hasPerm('manage_users'));
  $('#navRoles').classList.toggle('hidden', !hasPerm('manage_roles'));
  // 回收站：无删除与恢复权限时隐藏入口
  $('#sidebar').querySelector('[data-nav=recycle]').classList.toggle('hidden', !(hasPerm('trash')||hasPerm('recycle')));
  // 上传投递（拖拽/粘贴）按权限关闭
  state._canUpload = up;
}

/* ================= 统计 ================= */
function renderStats(u){
  const used = u.used_bytes, quota = u.quota_bytes;
  const pct = quota > 0 ? Math.min(100, used/quota*100) : 0;
  $('#sbFill').style.width = pct.toFixed(1) + '%';
  $('#sbTxt').textContent = fmtSize(used) + ' / ' + fmtSize(quota);
  const C = 2*Math.PI*46;
  $('#ringFill').setAttribute('stroke-dasharray', (C*pct/100).toFixed(1) + ' ' + C.toFixed(1));
  $('#ringPct').textContent = pct.toFixed(pct>=10?0:1) + '%';
}
async function refreshStats(){
  const [su, ss] = await Promise.all([API.get('a=me'), API.get('a=stats')]);
  if(su.ok) { state.user = su.user; renderStats(su.user); }
  if(ss.ok){
    const cats = ss.categories, cnts = ss.category_counts;
    const colors = {'图片':'#00ff9c','视频':'#ff2ec4','音频':'#00f0ff','文档':'#7ab8ff','压缩包':'#ffe066','其他':'#5f7f9e'};
    let html = '';
    const total = Object.values(cats).reduce((a,b)=>a+b,0) || 1;
    for(const [k,v] of Object.entries(cats)){
      const w = v/total*100;
      if(cnts[k] === 0) continue;
      html += `<div class="cat-row"><div class="cat-top"><span style="color:${colors[k]}">◆ ${k} ×${cnts[k]}</span><b>${fmtSize(v)}</b></div>
               <div class="cat-track"><div class="cat-fill" style="width:${w.toFixed(1)}%;--cc:${colors[k]}"></div></div></div>`;
    }
    $('#catBox').innerHTML = html || '<div style="font-size:10px;color:var(--dim)">暂无数据</div>';
    $('#cntBox').innerHTML = `文件 <span style="color:var(--cyan)">${ss.file_count}</span> · 文件夹 <span style="color:var(--warn)">${ss.dir_count}</span>`;
  }
}
async function refreshBadges(){
  const [sr, sx] = await Promise.all([API.get('a=shares'), API.get('a=recycle')]);
  $('#cntShares').textContent = sr.ok ? sr.items.filter(x=>!x.expired && !x.deleted).length : 0;
  $('#cntRecycle').textContent = sx.ok ? sx.items.length : 0;
  refreshStats();
}

/* ================= 导航 ================= */
function navTo(nav, keepDir){
  state.nav = nav; state.sel.clear(); state.searching = false;
  $('#searchInput').value = '';
  document.querySelectorAll('.nav-item').forEach(el=>el.classList.toggle('on', el.dataset.nav===nav));
  // 文件操作工具栏 vs 管理视图工具栏
  const adminView = (nav==='users' || nav==='roles');
  if(adminView){
    ['btnUploadFile','btnDirUpload','btnMkdir','btnZipSel','btnBatchMove','btnBatchTrash','viewToggle'].forEach(id=>{
      $('#'+id).classList.add('hidden');
    });
  } else {
    // 退出管理视图后按权限恢复文件操作按钮（applyPerms 幂等）
    $('#viewToggle').classList.remove('hidden');
    applyPerms();
  }
  $('#btnNewUser').classList.toggle('hidden', nav!=='users');
  $('#btnNewRole').classList.toggle('hidden', nav!=='roles');
  $('#adminWrap').classList.toggle('hidden', !adminView);
  if(adminView){
    $('#listWrap').classList.add('hidden');
    $('#gridWrap').classList.add('hidden');
    $('#emptyBox').classList.add('hidden');
    $('#crumbs').innerHTML = nav==='users'
      ? '<span style="color:var(--dim);letter-spacing:2px">ACCOUNTS // 账号管理</span>'
      : '<span style="color:var(--dim);letter-spacing:2px">ROLES // 角色与权限</span>';
    if(nav==='users') loadUsers(); else loadRoles();
    return;
  }
  if(nav==='files'){
    if(!keepDir) state.dirId = null;
    loadDir(state.dirId);
  } else if(nav==='recent'){ loadRecent(); }
  else if(nav==='shares'){ loadShares(); }
  else if(nav==='recycle'){ loadRecycle(); }
}

/* ================= 文件列表 ================= */
async function loadDir(dirId){
  state.dirId = dirId;
  const r = await API.get('a=list&dir=' + (dirId??'0'));
  if(!r.ok){ toast(r.error||'加载失败','err'); return; }
  state.items = r.items; state.crumbs = r.crumbs; state.sel.clear();
  state.dirCache = {};
  renderCrumbs(r.crumbs);
  renderItems();
}
async function loadRecent(){
  const r = await API.get('a=recent');
  if(!r.ok){ toast(r.error||'加载失败','err'); return; }
  state.items = r.items; state.crumbs = []; state.sel.clear();
  $('#crumbs').innerHTML = '<span class="tag" style="color:var(--dim);letter-spacing:2px">RECENT // 最近 30 个文件</span>';
  renderItems();
}
async function loadRecycle(){
  const r = await API.get('a=recycle');
  if(!r.ok){ toast(r.error||'加载失败','err'); return; }
  state.items = r.items; state.crumbs = []; state.sel.clear();
  $('#crumbs').innerHTML = '<span style="color:var(--dim);letter-spacing:2px">RECYCLE BIN // 回收站（文件保留至清空）</span>';
  renderRecycle();
}
async function loadShares(){
  const r = await API.get('a=shares');
  if(!r.ok){ toast(r.error||'加载失败','err'); return; }
  state.shares = r.items; state.items = []; state.crumbs = []; state.sel.clear();
  $('#crumbs').innerHTML = '<span style="color:var(--dim);letter-spacing:2px">SHARES // 分享链路管理</span>';
  renderShares();
}
function renderCrumbs(crumbs){
  let html = `<a id="crumbRoot" onclick="navTo('files')" style="cursor:pointer" title="点击返回根目录 · 拖拽文件到此处 = 移动到根目录">▶ ROOT</a>`;
  crumbs.forEach((c,i)=>{
    const last = i===crumbs.length-1;
    html += `<span style="margin:0 3px;color:var(--dim)">/</span><a id="crumb-${c.id}" onclick="openDirById(${c.id})" style="${last?'color:var(--bright);pointer-events:none':''}" title="${last?'':'点击进入 · 拖拽到此处 = 移动到「'+esc(c.name)+'」'}">${esc(c.name)}</a>`;
  });
  $('#crumbs').innerHTML = html;
  // 面包屑作为拖拽移动目标：ROOT 移到根、父级移到对应目录（当前所在层级不可选入）
  const root = document.getElementById('crumbRoot');
  if(root) makeDropTarget(root, 0, '根目录');
  crumbs.forEach((c,i)=>{
    if(i===crumbs.length-1) return;
    const el = document.getElementById('crumb-' + c.id);
    if(el) makeDropTarget(el, c.id, c.name);
  });
}
async function openDirById(id){ state.dirId = id; navTo('files', true); await loadDir(id); }

function catOf(isDir, name, mime){
  if(isDir) return ['b-dir','DIR'];
  const ext = (name.split('.').pop()||'').toLowerCase();
  if((mime||'').startsWith('image/') || ['png','jpg','jpeg','gif','webp','bmp','svg'].includes(ext)) return ['b-img','IMG'];
  if((mime||'').startsWith('video/') || ['mp4','mkv','avi','mov','flv','webm','m4v'].includes(ext)) return ['b-vid','VID'];
  if((mime||'').startsWith('audio/') || ['mp3','wav','flac','aac','ogg','m4a'].includes(ext)) return ['b-aud','AUD'];
  if((mime||'').startsWith('text/') || ['pdf','doc','docx','xls','xlsx','ppt','pptx','md','txt','csv','html','php','json','xml','log'].includes(ext)) return ['b-doc','DOC'];
  if(['zip','rar','7z','tar','gz'].includes(ext)) return ['b-zip','ZIP'];
  return ['b-oth',(ext.slice(0,4)||'BIN').toUpperCase()];
}
function imgUrl(id){ return 'download.php?id=' + id + '&inline=1'; }

function renderItems(){
  if(state.nav==='recycle'){ renderRecycle(); return; }
  const tb = $('#tbody'), gw = $('#gridWrap'), eb = $('#emptyBox');
  tb.innerHTML=''; gw.innerHTML='';
  const items = state.items;
  eb.classList.toggle('hidden', items.length>0);
  if(state.nav==='recent' && items.length===0) $('#emptyTxt').textContent = '[ NO TRACE // 暂无文件，去上传吧 ]';
  else if(items.length===0) $('#emptyTxt').textContent = '[ EMPTY // 当前目录为空 ]';
  $('#listWrap').classList.toggle('hidden', state.view!=='list' || items.length===0);
  $('#gridWrap').classList.toggle('hidden', state.view!=='grid' || items.length===0);
  $('#viewToggle').textContent = state.view==='list' ? '▦ 网格' : '▤ 列表';
  if(state.view==='grid'){ renderGrid(items); updateSelInfo(); return; }
  items.forEach(it=>{
    const [cls,label] = catOf(it.is_dir, it.name, it.mime);
    const tr = document.createElement('tr');
    tr.dataset.id = it.id;
    tr.className = state.sel.has(it.id) ? 'selected' : '';
    const opsHtml = rowOpsHtml(it, state.nav==='recent');
    if(state.nav==='recent'){
      tr.innerHTML = `<td><input type="checkbox" class="rowchk" ${state.sel.has(it.id)?'checked':''} onclick="event.stopPropagation();selRow(${it.id},this.checked)"></td>
        <td><span class="badge ${cls}">${label}</span><span class="fname" onclick="openOrPreview(${it.id},${it.is_dir?1:0},'${it.id}')">${esc(it.name)}</span></td>
        <td class="dim hide-md">${it.is_dir?'文件夹':(it.mime||'-').split('/')[0]}</td>
        <td class="dim">${it.is_dir?'-':fmtSize(it.size)}</td>
        <td class="dim hide-md">${fmtTime(it.updated_at)}</td>
        <td><div class="ops">${opsHtml}</div></td>`;
    } else {
      tr.innerHTML = `<td><input type="checkbox" class="rowchk" ${state.sel.has(it.id)?'checked':''} onclick="event.stopPropagation();selRow(${it.id},this.checked)"></td>
        <td><span class="badge ${cls}">${label}</span><span class="fname" onclick="openOrPreview(${it.id},${it.is_dir?1:0})">${esc(it.name)}</span></td>
        <td class="dim hide-md">${it.is_dir?'文件夹':(it.mime||'-').split('/')[0]}</td>
        <td class="dim">${it.is_dir?'-':fmtSize(it.size)}</td>
        <td class="dim hide-md">${fmtTime(it.updated_at)}</td>
        <td><div class="ops">${opsHtml}</div></td>`;
    }
    tr.addEventListener('contextmenu', e=>{ e.preventDefault(); openCtx(e, it); });
    // 拖拽移动：行可拖、目录行可作为目标（回收站页除外）
    if(state.nav!=='recycle'){
      makeDraggable(tr, it);
      if(it.is_dir && state.nav!=='shares') makeDropTarget(tr, it.id, it.name);
    }
    tb.appendChild(tr);
    tr.querySelectorAll('button[data-op]').forEach(btn=>{
      btn.addEventListener('click', ev=>{
        ev.stopPropagation();
        const op = btn.dataset.op;
        if(op==='open'){ it.is_dir ? openDirById(it.id) : previewNode(it.id, it.name); }
        else if(op==='dl'){ location.href = 'download.php?id='+it.id; toast('开始下载：'+it.name,'ok'); }
        else if(op==='pv'){ previewNode(it.id, it.name); }
        else if(op==='zip'){ zipHint(it.name); location.href='download.php?id='+it.id; }
        else if(op==='zipbrowse'){ zipBrowse(it.id, it.name); }
        else if(op==='extract'){ openExtractFlow(it.id, it.name); }
        else if(op==='share'){ openShare([it]); }
        else if(op==='rename'){ openRename(it); }
        else if(op==='move'){ openMove([it.id]); }
        else if(op==='trash'){ trashNodes([it.id]); }
      });
    });
  });
  updateSelInfo();
}
function renderGrid(items){
  const gw = $('#gridWrap');
  items.forEach(it=>{
    const [cls,label] = catOf(it.is_dir, it.name, it.mime);
    const isImg = cls==='b-img';
    const d = document.createElement('div');
    d.className = 'gcell' + (state.sel.has(it.id)?' selected':'');
    d.dataset.id = it.id;
    d.innerHTML = `<input type="checkbox" class="rowchk gchk" ${state.sel.has(it.id)?'checked':''} onclick="event.stopPropagation();selRow(${it.id},this.checked)">
      <div class="gthumb">${isImg ? `<img src="${imgUrl(it.id)}" loading="lazy" onclick="openOrPreview(${it.id},0)">` : `<span class="gext" style="${cls==='b-dir'?'color:var(--warn);text-shadow:0 0 12px rgba(255,179,71,.6)':''}">${label}</span>`}</div>
      <div class="gname" title="${esc(it.name)}">${esc(it.name)}</div>
      <div class="gsize">${it.is_dir?'文件夹':fmtSize(it.size)}</div>`;
    d.addEventListener('dblclick', ()=>{ it.is_dir ? openDirById(it.id) : previewNode(it.id, it.name); });
    d.addEventListener('contextmenu', e=>{ e.preventDefault(); openCtx(e, it); });
    if(it.is_dir) makeDropTarget(d, it.id, it.name);
    makeDraggable(d, it);
    gw.appendChild(d);
  });
}
/* 行操作按钮（按权限过滤）：recent 视图只保留最小集 */
function rowOpsHtml(it, isRecent){
  const ops = [];
  const ext = (it.name.split('.').pop()||'').toLowerCase();
  if(it.is_dir){
    ops.push('<button class="btn small" data-op="open">打开</button>');
    if(!isRecent && hasPerm('download')) ops.push('<button class="btn small" data-op="zip">打包</button>');
  } else {
    if(hasPerm('download')) ops.push('<button class="btn small" data-op="dl">下载</button>');
    if(!isRecent && ext==='zip' && hasPerm('preview')) ops.push('<button class="btn small" data-op="zipbrowse">浏览</button>');
    if(!isRecent && ext==='zip' && hasPerm('upload')) ops.push('<button class="btn small" data-op="extract">解压</button>');
    if(hasPerm('preview')) ops.push('<button class="btn small" data-op="pv">预览</button>');
  }
  if(hasPerm('share')) ops.push('<button class="btn small mag" data-op="share">分享</button>');
  if(!isRecent){
    if(hasPerm('rename')) ops.push('<button class="btn small" data-op="rename">改名</button>');
    if(hasPerm('move')) ops.push('<button class="btn small" data-op="move">移动</button>');
    if(hasPerm('trash')) ops.push('<button class="btn small danger" data-op="trash">删除</button>');
  }
  return ops.join('');
}
function openOrPreview(id, isDir){
  if(isDir){ openDirById(id); return; }
  const it = state.items.find(x=>x.id===id);
  if(!hasPerm('preview')){
    // 无预览权限：有下载权限则直接下载，否则提示
    if(hasPerm('download')){ toast('当前账号无预览权限，已开始下载','warn'); location.href='download.php?id='+id; }
    else toast('当前账号无「在线预览」权限','warn');
    return;
  }
  previewNode(id, it ? it.name : '文件');
}
function zipHint(name){ toast('正在打包「'+name+'」… 完成后自动下载', 'warn'); }

/* ================= 压缩包：浏览 / 解压 / 压缩打包 ================= */
const ZIP_MAX_ENTRIES = 20000;
function openWideModal(){ document.querySelector('#modalMask .modal').classList.add('wide'); }
function fmtZipTime(ts){
  if(!ts) return '-';
  const d = new Date(ts*1000);
  const p = n => String(n).padStart(2,'0');
  return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
}
async function zipBrowse(id, name){
  openModal('ZIP / 压缩包内容', '<div class="pv-loading"><span class="spin"></span>READ // 正在读取压缩包目录…</div>',
    '<button class="btn" onclick="closeModal()">关闭</button><button class="btn primary hidden" id="zbEx">🗜 解压…</button>');
  openWideModal();
  const r = await API.get('a=ziplist&id=' + id);
  if(!r.ok){
    $('#mBody').innerHTML = `<div style="color:var(--danger);font-size:13px;line-height:2">✖ ${esc(r.error||'读取失败')}</div>`;
    return;
  }
  const rows = r.items.map(e=>{
    const icon = e.dir ? '<span style="color:var(--warn)">▸</span>' : '<span style="color:var(--cyan)">◈</span>';
    return `<tr><td>${icon} ${esc(e.name)}</td>
      <td class="dim" style="text-align:right;white-space:nowrap">${e.dir?'—':fmtSize(e.size)}</td>
      <td class="dim" style="white-space:nowrap">${e.dir?'—':fmtZipTime(e.mtime)}</td></tr>`;
  }).join('');
  $('#mBody').innerHTML = `
    <div style="font-size:11px;color:var(--dim);margin-bottom:10px;line-height:1.8">
      ◈ <b style="color:var(--bright)">${esc(name)}</b> · 共 <b style="color:var(--cyan)">${r.total}</b> 项
      （文件夹 ${r.dirs} / 文件 ${r.files}）· 解压后约 <b style="color:var(--bright)">${fmtSize(r.uncompressed)}</b>
      ${r.truncated ? `<br><span style="color:var(--warn)">条目较多，列表仅显示前 ${r.items.length} 项</span>` : ''}
    </div>
    <div class="zlist"><table><thead><tr><th>名称 / 路径</th>
      <th style="text-align:right;width:90px">大小</th><th style="width:130px">修改时间</th></tr></thead>
      <tbody>${rows || '<tr><td colspan="3" class="dim">压缩包为空</td></tr>'}</tbody></table></div>`;
  const exBtn = $('#zbEx');
  exBtn.classList.remove('hidden');
  exBtn.onclick = ()=> zipExtractDialog(id, name, r);
}
async function openExtractFlow(id, name){
  toast('READ // 正在解析压缩包…','');
  const r = await API.get('a=ziplist&id=' + id);
  if(!r.ok){ toast(r.error||'读取压缩包失败','err'); return; }
  zipExtractDialog(id, name, r);
}
function zipExtractDialog(id, name, meta){
  const base = (name.replace(/\.[^.]+$/,'') || '解压文件');
  openModal('EXTRACT / 解压压缩包', `
    <div class="m-row"><label>SOURCE / 压缩包</label>
      <div style="font-size:13px;color:var(--bright);word-break:break-all">◈ ${esc(name)}
        <div class="dim" style="margin-top:4px">${meta.total} 项 · 解压后约 ${fmtSize(meta.uncompressed)}</div></div></div>
    <div class="m-row"><label>DESTINATION / 解压位置（当前目录）</label>
      <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--text);letter-spacing:0;margin-bottom:9px;cursor:pointer">
        <input type="radio" name="zxMode" value="folder" checked style="accent-color:#00f0ff">
        解压到新建文件夹「${esc(base)}」（推荐）</label>
      <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--text);letter-spacing:0;cursor:pointer">
        <input type="radio" name="zxMode" value="here" style="accent-color:#00f0ff">
        直接解压到当前目录</label></div>
    <div style="font-size:10px;color:var(--dim);line-height:1.9;border:1px dashed var(--line-dim);padding:10px 12px;background:rgba(0,240,255,.03)">
      · 解压会占用存储配额（约 ${fmtSize(meta.uncompressed)}，超出将拒绝执行）<br>
      · 同名文件自动改名「name (2)」，不会覆盖现有文件<br>
      · macOS 资源目录 __MACOSX 与非法路径自动跳过<br>
      · 单次上限 20000 项 / 4GB
    </div>`,
    `<button class="btn" onclick="closeModal()">取消</button><button class="btn primary" id="zxGo">开始解压</button>`);
  $('#zxGo').onclick = async ()=>{
    const mode = document.querySelector('input[name=zxMode]:checked').value;
    const btn = $('#zxGo');
    btn.disabled = true; btn.textContent = '解压中…';
    const r = await API.post('zipextract', {id, mode, parentId: state.dirId ?? 0});
    if(!r.ok){
      toast(r.error||'解压失败','err');
      btn.disabled = false; btn.textContent = '开始解压';
      return;
    }
    toast(`✔ 已解压 ${r.files} 个文件${r.folder?` → 「${r.folder}」`:'到当前目录'}${r.skipped?`（跳过 ${r.skipped} 项）`:''}`, 'ok');
    closeModal();
    if(state.nav==='files' && !state.searching) await loadDir(state.dirId);
    refreshBadges();
  };
}
function zipCreate(ids){
  ids = (ids||[]).filter(Boolean);
  if(!ids.length){ toast('请先勾选要压缩的文件或文件夹','warn'); return; }
  const sel = state.items.filter(x=>ids.includes(x.id));
  const shown = sel.slice(0,12).map(x=>`<div style="font-size:12px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${x.is_dir?'▸ 文件夹':'◈'} ${esc(x.name)}</div>`).join('');
  openModal('ZIP / 压缩打包', `
    <div class="m-row"><label>SELECTED / 将打包 ${ids.length} 项 → 当前目录</label>
      <div style="border:1px solid var(--line-dim);background:rgba(0,10,20,.7);padding:10px 12px;max-height:180px;overflow:auto;line-height:1.9">
        ${shown}${sel.length>12?`<div class="dim">… 共 ${sel.length} 项</div>`:''}</div></div>
    <div style="font-size:10px;color:var(--dim);line-height:1.9">
      · 文件夹按原目录结构递归打包（单次上限 ${ZIP_MAX_ENTRIES} 项）<br>
      · 单个项目命名「原名称.zip」，多项目命名「打包文件-时间.zip」<br>
      · 生成的 .zip 占用存储配额
    </div>`,
    `<button class="btn" onclick="closeModal()">取消</button><button class="btn primary" id="zcGo">开始压缩</button>`);
  $('#zcGo').onclick = async ()=>{
    const btn = $('#zcGo');
    btn.disabled = true; btn.textContent = '压缩中…';
    const r = await API.post('zipcreate', {ids, parentId: state.dirId ?? 0});
    if(!r.ok){
      toast(r.error||'压缩失败','err');
      btn.disabled = false; btn.textContent = '开始压缩';
      return;
    }
    toast(`✔ 已生成「${r.name}」（${fmtSize(r.size)}，共 ${r.items} 项）`, 'ok');
    closeModal();
    if(state.nav==='files' && !state.searching) await loadDir(state.dirId);
    refreshBadges();
  };
}

/* ================= 选择 ================= */
function selRow(id, on){
  if(on) state.sel.add(id); else state.sel.delete(id);
  document.querySelectorAll('tr[data-id], .gcell[data-id]').forEach(el=>{
    el.classList.toggle('selected', state.sel.has(+el.dataset.id));
    const chk = el.querySelector('.rowchk'); if(chk) chk.checked = state.sel.has(+el.dataset.id);
  });
  updateSelInfo();
}
function toggleAll(on){
  state.items.forEach(it=>{ if(on) state.sel.add(it.id); else state.sel.delete(it.id); });
  document.querySelectorAll('.rowchk').forEach(c=>c.checked = on);
  document.querySelectorAll('tr[data-id], .gcell[data-id]').forEach(el=>el.classList.toggle('selected', on));
  updateSelInfo();
}
function updateSelInfo(){
  $('#selInfo').textContent = state.sel.size ? `已选 ${state.sel.size} 项` : '';
}

/* ================= 右键菜单 ================= */
function openCtx(e, it){
  const m = $('#ctxMenu');
  const isDir = it.is_dir;
  const ext = (it.name.split('.').pop()||'').toLowerCase();
  const items = [];
  items.push(`<div class="ci" data-c="open">⤷ ${isDir?'打开目录':(hasPerm('preview')?'预览':'下载')}</div>`);
  if(hasPerm('download')) items.push(`<div class="ci" data-c="dl">⬇ ${isDir?'打包下载':'下载'}</div>`);
  if(!isDir && ext==='zip' && hasPerm('preview')) items.push(`<div class="ci" data-c="zipbrowse">🗂 浏览压缩包内容</div>`);
  if(!isDir && ext==='zip' && hasPerm('upload')) items.push(`<div class="ci" data-c="extract">🗜 解压到…</div>`);
  if(hasPerm('share')) items.push(`<div class="ci" data-c="share">⇄ 创建分享</div>`);
  if(hasPerm('upload')) items.push(`<div class="ci" data-c="compress">📦 压缩为 ZIP</div>`);
  if(hasPerm('rename') || hasPerm('move')) items.push('<div class="cdiv"></div>');
  if(hasPerm('rename')) items.push(`<div class="ci" data-c="rename">✎ 重命名</div>`);
  if(hasPerm('move')) items.push(`<div class="ci" data-c="move">⇢ 移动到…</div>`);
  if(hasPerm('trash')) items.push('<div class="cdiv"></div><div class="ci danger" data-c="trash">✖ 删除到回收站</div>');
  m.innerHTML = items.join('');
  m.style.display='block';
  const mw = 180, mh = m.offsetHeight || 220;
  m.style.left = Math.min(e.clientX, innerWidth - mw - 8) + 'px';
  m.style.top = Math.min(e.clientY, innerHeight - mh - 8) + 'px';
  m.querySelectorAll('.ci').forEach(el=>{
    el.onclick = ()=>{
      closeCtx();
      const c = el.dataset.c;
      if(c==='open'){ it.is_dir ? openDirById(it.id) : openOrPreview(it.id, 0); }
      else if(c==='dl'){ if(isDir) zipHint(it.name); location.href='download.php?id='+it.id; }
      else if(c==='zipbrowse'){ zipBrowse(it.id, it.name); }
      else if(c==='extract'){ openExtractFlow(it.id, it.name); }
      else if(c==='compress'){ zipCreate(state.sel.has(it.id) ? [...state.sel] : [it.id]); }
      else if(c==='share'){ openShare([it]); }
      else if(c==='rename'){ openRename(it); }
      else if(c==='move'){ openMove([it.id]); }
      else if(c==='trash'){ trashNodes([it.id]); }
    };
  });
}
function closeCtx(){ $('#ctxMenu').style.display='none'; }
document.addEventListener('click', closeCtx);
document.addEventListener('contextmenu', e=>{ if(!e.target.closest('tr[data-id],.gcell[data-id]')) closeCtx(); });

/* ================= 弹窗 ================= */
function openModal(title, bodyHtml, footHtml){
  $('#mTitle').textContent = title;
  $('#mBody').innerHTML = bodyHtml;
  $('#mFoot').innerHTML = footHtml || '<button class="btn" onclick="closeModal()">关闭</button>';
  $('#modalMask').classList.add('on');
}
function closeModal(){ $('#modalMask').classList.remove('on'); document.querySelector('#modalMask .modal').classList.remove('wide'); }
function openMkdir(){
  openModal('NEW FOLDER / 新建文件夹', `
    <div class="m-row"><label>FOLDER NAME / 文件夹名</label>
    <input class="fld" id="mkName" placeholder="例如：任务资料" maxlength="200"></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn primary" id="mkBtn">创建</button>`);
  $('#mkBtn').onclick = async ()=>{
    const name = $('#mkName').value.trim();
    if(!name){ toast('请输入文件夹名','warn'); return; }
    const r = await API.post('mkdir', {parentId: state.dirId??0, name});
    if(!r.ok){ toast(r.error||'创建失败','err'); return; }
    toast('已创建「'+r.name+'」','ok'); closeModal();
    loadDir(state.dirId); refreshBadges();
  };
  $('#mkName').focus();
  $('#mkName').addEventListener('keydown', e=>{ if(e.key==='Enter') $('#mkBtn').click(); });
}
function openRename(it){
  openModal('RENAME / 重命名', `
    <div class="m-row"><label>NEW NAME / 新名称</label>
    <input class="fld" id="rnName" value="${esc(it.name)}" maxlength="200"></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn primary" id="rnBtn">确认</button>`);
  $('#rnBtn').onclick = async ()=>{
    const name = $('#rnName').value.trim();
    if(!name || name===it.name){ closeModal(); return; }
    const r = await API.post('rename', {id: it.id, name});
    if(!r.ok){ toast(r.error||'重命名失败','err'); return; }
    toast('已重命名为「'+r.name+'」','ok'); closeModal();
    if(state.nav==='recycle') loadRecycle(); else navTo(state.nav, true);
  };
  $('#rnName').focus();
  const dot = it.name.lastIndexOf('.');
  $('#rnName').setSelectionRange(0, dot>0 ? dot : it.name.length);
  $('#rnName').addEventListener('keydown', e=>{ if(e.key==='Enter') $('#rnBtn').click(); });
}

/* ---- 移动（目录树） ---- */
let moveTarget = null;
async function openMove(ids){
  moveTarget = null;
  openModal('MOVE / 移动到…', `
    <div style="font-size:11px;color:var(--dim);margin-bottom:10px">已选 ${ids.length} 项 · 选择目标文件夹（双击展开）</div>
    <div class="tree-box" id="treeBox"></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn primary" id="mvBtn">移动到此处</button>`);
  await buildTree($('#treeBox'), 0, true);
  $('#mvBtn').onclick = async ()=>{
    const r = await API.post('move', {ids, target: moveTarget??0});
    if(!r.ok){ toast(r.error||'移动失败','err'); return; }
    toast('已移动 '+r.moved+' 项','ok'); closeModal();
    navTo(state.nav, true); refreshBadges();
  };
}
async function buildTree(container, dirId, isRoot, onSelect){
  const r = await API.get('a=list&dir='+(dirId||'0'));
  if(!r.ok){ container.innerHTML = '<div class="tree-load">加载失败</div>'; return; }
  const dirs = r.items.filter(x=>x.is_dir);
  const row = document.createElement('div');
  row.className = 'tree-node' + (isRoot?' on':'');
  row.innerHTML = `<span style="color:var(--cyan)">▣</span> ${isRoot?'ROOT / 根目录（整个网盘）':esc(r.crumbs.length?r.crumbs[r.crumbs.length-1].name:'目录')}`;
  row.onclick = ()=>{ const selId = isRoot?0:dirId; moveTarget = selId;
    container.querySelectorAll('.tree-node').forEach(n=>n.classList.remove('on'));
    row.classList.add('on');
    if(onSelect) onSelect(selId, isRoot?'根目录（整个网盘）':(r.crumbs.length?r.crumbs[r.crumbs.length-1].name:'目录'));
  };
  container.appendChild(row);
  const kids = document.createElement('div');
  kids.className = 'tree-kids';
  if(dirs.length===0){
    kids.innerHTML = '<div class="tree-load">[ 无子目录 ]</div>';
  } else {
    dirs.forEach(d=>{
      const dk = document.createElement('div');
      dk.className = 'tree-node';
      dk.innerHTML = `<span style="color:var(--warn)">▸</span> ${esc(d.name)} <span class="tree-load" style="margin-left:auto">＋</span>`;
      let loaded = false;
      dk.onclick = ()=>{ moveTarget = d.id;
        container.querySelectorAll('.tree-node').forEach(n=>n.classList.remove('on'));
        dk.classList.add('on');
        if(onSelect) onSelect(d.id, d.name);
      };
      dk.ondblclick = async (e)=>{
        e.stopPropagation();
        if(loaded) return; loaded = true;
        dk.querySelector('span.tree-load').textContent = '';
        const sub = document.createElement('div');
        sub.className = 'tree-kids';
        dk.after(sub);
        await buildTree(sub, d.id, false, onSelect);
      };
      kids.appendChild(dk);
    });
  }
  container.appendChild(kids);
}

/* ---- 分享 ---- */
function openShare(nodes){
  const it = nodes[0];
  openModal('SHARE / 创建分享链路', `
    <div class="m-row"><label>TARGET / 分享对象</label>
      <div style="font-size:13px;color:var(--bright)">◈ ${esc(it.name)} <span class="dim">${it.is_dir?'（文件夹）':fmtSize(it.size)}</span></div></div>
    <div class="m-row"><label>EXTRACT CODE / 提取码（留空 = 公开访问）</label>
      <input class="fld" id="shCode" placeholder="4 位以上字母数字，如 6688" maxlength="16" style="letter-spacing:4px"></div>
    <div class="m-row"><label>EXPIRES / 有效期</label>
      <select class="fld" id="shDays" style="color:var(--bright)">
        <option value="0">永久有效</option><option value="1">1 天</option>
        <option value="7">7 天</option><option value="30">30 天</option>
      </select></div>
    <div id="shResult"></div>`,
    `<button class="btn" onclick="closeModal()">关闭</button>
     <button class="btn mag" id="shBtn">⚡ 生成分享链路</button>`);
  $('#shBtn').onclick = async ()=>{
    const code = $('#shCode').value.trim();
    const days = parseInt($('#shDays').value, 10);
    const r = await API.post('share', {id: it.id, extractCode: code, days});
    if(!r.ok){ toast(r.error||'分享失败','err'); return; }
    const fullUrl = new URL(r.url, location.href).href; // 兼容子目录部署（虚拟主机）
    $('#shResult').innerHTML = `
      <div class="share-result">
        <div class="sr-line"><span class="dim" style="width:60px">链接</span><span class="sr-val">${esc(fullUrl)}</span>
          <button class="btn small" onclick="copyText('${esc(fullUrl)}')">复制</button></div>
        ${r.extract_code ? `<div class="sr-line"><span class="dim" style="width:60px">提取码</span><span class="code-chip">${esc(r.extract_code)}</span>
          <button class="btn small" onclick="copyText('${esc(r.extract_code)}')">复制</button></div>` : `<div class="sr-line"><span class="dim" style="width:60px">模式</span><span style="color:var(--green);font-size:12px">公开访问 · 无需提取码</span></div>`}
        <div class="sr-line"><span class="dim" style="width:60px">有效期</span><span style="color:var(--cyan);font-size:12px">${r.expires_at ? '至 '+esc(r.expires_at) : '永久'}</span></div>
      </div>`;
    $('#shBtn').disabled = true; $('#shBtn').textContent = '✔ 链路已建立';
    toast('分享创建成功','ok');
    refreshBadges();
  };
}

/* ---- 回收站 ---- */
function renderRecycle(){
  const tb = $('#tbody');
  $('#listWrap').classList.remove('hidden');
  $('#gridWrap').classList.add('hidden');
  const eb = $('#emptyBox');
  eb.classList.toggle('hidden', state.items.length>0);
  $('#viewToggle').classList.add('hidden');
  if(state.items.length===0) $('#emptyTxt').textContent = '[ CLEAN // 回收站是空的 ]';
  tb.innerHTML='';
  // 工具行
  state.items.forEach(it=>{
    const tr = document.createElement('tr');
    tr.dataset.id = it.id;
    tr.innerHTML = `<td><input type="checkbox" class="rowchk" onclick="event.stopPropagation();selRow(${it.id},this.checked)"></td>
      <td><span class="badge ${it.is_dir?'b-dir':'b-oth'}">${it.is_dir?'DIR':'FILE'}</span><span class="fname">${esc(it.name)}</span></td>
      <td class="dim hide-md">回收于</td>
      <td class="dim">${it.is_dir?'-':fmtSize(it.size)}</td>
      <td class="dim hide-md">${fmtTime(it.deleted_at)}</td>
      <td><div class="ops">
        ${hasPerm('recycle') ? '<button class="btn small" data-op="restore">↩ 恢复</button>' : ''}
        ${hasPerm('recycle') ? '<button class="btn small danger" data-op="purge">✖ 彻底删除</button>' : ''}</div></td>`;
    tb.appendChild(tr);
    tr.querySelectorAll('button[data-op]').forEach(btn=>{
      btn.onclick = ()=>{
        if(btn.dataset.op==='restore') restoreNodes([it.id]);
        else purgeNodes([it.id]);
      };
    });
  });
  // 清空按钮
  if(state.items.length && hasPerm('recycle') && !$('#btnEmptyRC')){
    const bar = document.createElement('div');
    bar.id = 'btnEmptyRC';
    bar.style.cssText = 'padding:12px;text-align:right';
    bar.innerHTML = `<button class="btn danger" onclick="emptyRecycle()">☠ 清空回收站</button>`;
    tb.after(bar);
  }
  if(!state.items.length){ const b = $('#btnEmptyRC'); if(b) b.remove(); }
  updateSelInfo();
}
async function restoreNodes(ids){
  const r = await API.post('restore', {ids});
  if(!r.ok){ toast(r.error||'恢复失败','err'); return; }
  toast('已恢复 '+r.restored+' 项（原父目录不存在时恢复到根目录）','ok');
  loadRecycle(); refreshBadges();
}
async function purgeNodes(ids){
  openModal('PURGE / 彻底删除确认', `
    <div style="font-size:13px;color:var(--danger);line-height:2">⚠ 将永久删除 ${ids.length} 项及其全部内容<br>
    <span class="dim" style="color:var(--dim)">物理文件同步清除，不可恢复！</span></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn danger" id="pgBtn">确认彻底删除</button>`);
  $('#pgBtn').onclick = async ()=>{
    const r = await API.post('purge', {ids});
    if(!r.ok){ toast(r.error||'删除失败','err'); return; }
    toast('已彻底删除，释放 '+fmtSize(r.freed),'ok'); closeModal();
    loadRecycle(); refreshBadges();
  };
}
async function emptyRecycle(){
  openModal('PURGE / 清空回收站确认', `
    <div style="font-size:13px;color:var(--danger);line-height:2">⚠ 将清空回收站全部 ${state.items.length} 项<br>
    <span class="dim" style="color:var(--dim)">物理文件同步清除，不可恢复！</span></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn danger" id="erBtn">确认清空</button>`);
  $('#erBtn').onclick = async ()=>{
    const r = await API.post('emptyRecycle');
    if(!r.ok){ toast(r.error||'操作失败','err'); return; }
    toast('回收站已清空，释放 '+fmtSize(r.freed),'ok'); closeModal();
    loadRecycle(); refreshBadges();
  };
}

/* ---- 分享管理 ---- */
function renderShares(){
  $('#listWrap').classList.add('hidden');
  $('#gridWrap').classList.add('hidden');
  $('#viewToggle').classList.add('hidden');
  const eb = $('#emptyBox');
  eb.classList.toggle('hidden', state.shares.length>0);
  if(!state.shares.length) $('#emptyTxt').textContent = '[ NO LINKS // 暂无分享，去创建第一条链路 ]';
  const old = $('#shareCards'); if(old) old.remove();
  const box = document.createElement('div');
  box.id = 'shareCards';
  box.style.cssText = 'display:flex;flex-direction:column;gap:12px;padding:10px';
  state.shares.forEach(s=>{
    const d = document.createElement('div');
    d.className = 'hud';
    d.style.cssText = 'padding:14px 16px;border-color:var(--line-dim)';
    const url = new URL('share.php?s=' + s.token, location.href).href; // 兼容子目录部署
    const status = s.deleted ? '<span class="badge b-oth" style="color:var(--danger)">源文件已删除</span>'
      : s.expired ? '<span class="badge b-oth" style="color:var(--warn)">已过期</span>'
      : '<span class="badge b-img">生效中</span>';
    d.innerHTML = `
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="min-width:200px">
          <div style="color:var(--bright);font-size:13px">${status} ◈ ${esc(s.name)} <span class="dim">${s.is_dir?'文件夹':fmtSize(s.size)}</span></div>
          <div class="dim" style="margin-top:5px">浏览 ${s.view_count} · 下载 ${s.download_count} · ${s.expires_at?'有效期至 '+esc(s.expires_at):'永久'} · ${s.extract_code
            ? `<span style="color:var(--warn)">提取码</span> <span style="color:var(--warn);letter-spacing:2px;font-weight:700">${esc(s.extract_code)}</span>`
            : '<span style="color:var(--green)">公开访问</span>'}</div>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn small" data-op="copy">复制链接</button>
          ${s.extract_code ? `<button class="btn small" data-op="copycode">复制提取码</button>` : ''}
          <button class="btn small" data-op="visit">打开</button>
          <button class="btn small danger" data-op="unshare">取消分享</button>
        </div>
      </div>`;
    d.querySelectorAll('button[data-op]').forEach(btn=>{
      btn.onclick = ()=>{
        if(btn.dataset.op==='copy') copyText(url);
        else if(btn.dataset.op==='copycode') copyText(s.extract_code);
        else if(btn.dataset.op==='visit') window.open('share.php?s='+s.token, '_blank');
        else unshareNode(s.id);
      };
    });
    box.appendChild(d);
  });
  $('#emptyBox').before(box);
}
async function unshareNode(id){
  const r = await API.post('unshare', {id});
  if(!r.ok){ toast(r.error||'操作失败','err'); return; }
  toast('分享已取消','ok'); loadShares(); refreshBadges();
}

/* ================= 删除/批量 ================= */
async function trashNodes(ids){
  openModal('TRASH / 删除确认', `
    <div style="font-size:13px;line-height:2">将 ${ids.length} 项移入回收站？<br>
    <span class="dim">回收站中可随时恢复。</span></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn danger" id="trBtn">移入回收站</button>`);
  $('#trBtn').onclick = async ()=>{
    const r = await API.post('trash', {ids});
    if(!r.ok){ toast(r.error||'删除失败','err'); return; }
    toast('已移入回收站','ok'); closeModal();
    navTo(state.nav, true); refreshBadges();
  };
}
function batchTrash(){
  if(!state.sel.size){ toast('请先勾选要删除的项目','warn'); return; }
  trashNodes([...state.sel]);
}
function batchMove(){
  if(!state.sel.size){ toast('请先勾选要移动的项目','warn'); return; }
  openMove([...state.sel]);
}

/* ================= 账号管理 ================= */
async function loadUsers(){
  const r = await API.get('a=users');
  if(!r.ok){ toast(r.error||'加载账号失败','err'); return; }
  const roles = r.items.map(u=>u.role_id);
  let html = `<div class="adm-title">ACCOUNTS // 共 <b>${r.items.length}</b> 个账号 · 角色决定按钮与操作权限</div>
    <table><thead><tr>
      <th style="width:60px">ID</th><th style="width:16%">账号</th><th style="width:12%">角色</th>
      <th style="width:10%">管理员</th><th style="width:12%">配额 / 已用</th>
      <th class="hide-md" style="width:16%">创建 / 最近登录</th><th style="text-align:right">操作</th>
    </tr></thead><tbody>`;
  r.items.forEach(u=>{
    const pct = u.quota_bytes>0 ? Math.min(100, u.used_bytes/u.quota_bytes*100) : 0;
    html += `<tr${u.id===state.user.id ? ' class="selected"' : ''}>
      <td class="dim">${u.id}</td>
      <td><span class="fname" style="cursor:default">${esc(u.username)}${u.id===state.user.id?' <span style="color:var(--cyan);font-size:10px">（当前）</span>':''}</span></td>
      <td>${roleTag(u.role_id, u.role_name)}</td>
      <td>${u.is_admin ? '<span style="color:var(--magenta);letter-spacing:1px">◆ ADMIN</span>' : '<span class="dim">—</span>'}</td>
      <td><span class="dim" style="white-space:normal">${fmtSize(u.quota_bytes)}<br><span style="color:${pct>=90?'var(--danger)':'var(--cyan)'}">${fmtSize(u.used_bytes)} · ${pct.toFixed(0)}%</span></span></td>
      <td class="dim hide-md" style="white-space:nowrap">${fmtTime(u.created_at)}<br>${u.last_login_at?fmtTime(u.last_login_at):'<span style="opacity:.5">从未登录</span>'}</td>
      <td><div class="ops">
        <button class="btn small" onclick="openUserEdit(${u.id})">编辑</button>
        ${u.id!==state.user.id ? '<button class="btn small danger" onclick="delUser('+u.id+',\''+esc(u.username)+'\')">删除</button>' : ''}
      </div></td></tr>`;
  });
  html += '</tbody></table>';
  $('#adminWrap').innerHTML = html;
}
function roleTag(roleId, roleName){
  const cls = roleId===1 ? 'rt-admin' : roleId===2 ? 'rt-member' : roleId===3 ? 'rt-guest' : 'rt-custom';
  return `<span class="role-tag ${cls}">${esc(roleName)}</span>`;
}
async function openUserEdit(id){
  // 拉取角色列表供选择
  const rr = await API.get('a=roles');
  if(!rr.ok){ toast(rr.error||'读取角色失败（需角色管理权限）','err'); return; }
  const u = id ? (await API.get('a=users')).items.find(x=>x.id===id) : null;
  if(id && !u){ toast('账号不存在','err'); return; }
  const roles = rr.items;
  openModal(u ? ('EDIT USER / 编辑账号 · ' + u.username) : 'NEW USER / 新增账号', `
    <div class="m-row"><label>USERNAME / 账号名（字母数字或中文）</label>
      <input class="fld" id="uName" value="${u?esc(u.username):''}" maxlength="64" ${u?'':'placeholder="如：xiaoming"'}></div>
    <div class="m-row"><label>PASSWORD / 密码${u?'（留空 = 不修改）':'（至少 6 位）'}</label>
      <input class="fld" id="uPass" type="password" autocomplete="new-password" placeholder="${u?'留空保持原密码':'至少 6 位'}"></div>
    <div class="m-row"><label>ROLE / 角色（决定可用的功能与按钮）</label>
      <select class="fld" id="uRole" style="color:var(--bright)">
        ${roles.map(r=>`<option value="${r.id}" ${u && u.role_id===r.id?'selected':''}>${esc(r.name)} · ${r.perms.length} 项权限${r.built_in?'（内置）':''}</option>`).join('')}
      </select>
      <div style="font-size:10px;color:var(--dim);margin-top:6px" id="uRoleDesc"></div></div>
    <div class="m-row"><label>QUOTA / 配额（GB）</label>
      <input class="fld" id="uQuota" type="number" min="0.1" max="51200" step="0.1" value="${u?(u.quota_bytes/1073741824).toFixed(1):'10'}"></div>
    <div class="m-row"><label>ADMIN / 管理员标记（勾选后无视角色限制，拥有全部权限）</label>
      <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--text);letter-spacing:0">
        <input type="checkbox" id="uAdmin" ${u&&u.is_admin?'checked':''} style="accent-color:#ff2ec4"> 设为管理员</label></div>
    <div id="uDelPurge" style="display:none"></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn primary" id="uSave">${u?'保存修改':'创建账号'}</button>`);
  // 角色说明联动
  const desc = $('#uRoleDesc');
  const upd = ()=>{ const r = roles.find(x=>x.id==$('#uRole').value); if(r) desc.textContent = '权限：' + r.perms.map(p=>state.permCatalog[p]||p).join(' / '); };
  $('#uRole').onchange = upd; upd();
  $('#uSave').onclick = async ()=>{
    const name = $('#uName').value.trim();
    if(!name){ toast('请输入账号名','warn'); return; }
    const pass = $('#uPass').value;
    if(!u && pass.length < 6){ toast('新账号密码至少 6 位','warn'); return; }
    const body = {
      id: u?u.id:0, username: name, password: pass,
      roleId: parseInt($('#uRole').value,10), quotaGb: parseFloat($('#uQuota').value)||10,
      isAdmin: $('#uAdmin').checked ? 1 : 0
    };
    const r = await API.post('saveuser', body);
    if(!r.ok){ toast(r.error||'保存失败','err'); return; }
    toast(u?'账号已更新':'账号已创建：'+name,'ok');
    closeModal(); loadUsers();
  };
  $('#uName').focus();
}
async function delUser(id, name){
  openModal('DEL USER / 删除账号确认', `
    <div style="font-size:13px;color:var(--danger);line-height:2">⚠ 将删除账号「${esc(name)}」<br>
    <span style="color:var(--dim)">该账号的分享与登录记录会一并清除；</span></div>
    <div style="font-size:11px;color:var(--text);margin-top:10px;line-height:1.9">
      <label style="display:flex;gap:8px;align-items:center">
        <input type="checkbox" id="uPurge" style="accent-color:#ff3860"> 连同该账号的全部文件一起删除（含回收站，不可恢复）
      </label>
      <div id="uPurgeHint" style="color:var(--warn);font-size:10px;margin-top:6px;display:none">若不勾选：账号仍有文件时将拒绝删除</div>
    </div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn danger" id="uDelBtn">确认删除</button>`);
  $('#uPurge').onchange = e=>{ $('#uPurgeHint').style.display = e.target.checked?'block':'none'; };
  $('#uDelBtn').onclick = async ()=>{
    const r = await API.post('deluser', {id, purge: $('#uPurge').checked});
    if(!r.ok){ toast(r.error||'删除失败','err'); return; }
    toast('账号已删除','ok'); closeModal(); loadUsers();
  };
}

/* ================= 角色管理 ================= */
async function loadRoles(){
  const r = await API.get('a=roles');
  if(!r.ok){ toast(r.error||'加载角色失败','err'); return; }
  let html = `<div class="adm-title">ROLES // 共 <b>${r.items.length}</b> 个角色 · 勾选的权限决定该角色账号能看到哪些按钮与操作</div>
    <table><thead><tr>
      <th style="width:14%">角色</th><th style="width:22%">说明</th><th>权限（${Object.keys(r.catalog||{}).length} 项可分配）</th>
      <th style="width:9%">账号数</th><th style="width:130px;text-align:right">操作</th>
    </tr></thead><tbody>`;
  r.items.forEach(ro=>{
    const cats = r.catalog || {};
    const chips = Object.keys(cats).map(k=>{
      const on = ro.perms.includes(k);
      return `<span class="perm-chip${on?'':' off'}" title="${esc(cats[k])}">${esc(cats[k].split('（')[0])}</span>`;
    }).join('');
    html += `<tr>
      <td>${roleTag(ro.id, ro.name)}${ro.built_in?' <span class="dim" style="font-size:9px">内置</span>':''}</td>
      <td class="dim" style="white-space:normal">${ro.memo?esc(ro.memo):'—'}</td>
      <td style="white-space:normal">${chips}</td>
      <td>${ro.user_count>0?`<span style="color:var(--cyan)">${ro.user_count} 个</span>`:'<span class="dim">0</span>'}</td>
      <td><div class="ops">
        <button class="btn small" onclick="openRoleEdit(${ro.id})">${ro.built_in?'调整权限':'编辑'}</button>
        ${!ro.built_in ? `<button class="btn small danger" onclick="delRole(${ro.id},'${esc(ro.name)}')">删除</button>` : ''}
      </div></td></tr>`;
  });
  html += '</tbody></table>';
  $('#adminWrap').innerHTML = html;
}
async function openRoleEdit(id){
  const rr = await API.get('a=roles');
  if(!rr.ok){ toast(rr.error||'读取角色失败','err'); return; }
  const ro = id ? rr.items.find(x=>x.id===id) : null;
  if(id && !ro){ toast('角色不存在','err'); return; }
  const cats = rr.catalog || {};
  const permGrid = Object.entries(cats).map(([k,label])=>{
    const on = ro ? ro.perms.includes(k) : false;
    return `<label class="${on?'on':''}" data-k="${k}">
      <input type="checkbox" value="${k}" ${on?'checked':''}>
      <span>${esc(label)}</span></label>`;
  }).join('');
  openModal(ro ? ('EDIT ROLE / ' + (ro.built_in?'调整内置角色权限 · ':'编辑角色 · ') + ro.name) : 'NEW ROLE / 新增角色', `
    ${ro && ro.built_in ? '<div style="font-size:11px;color:var(--warn);margin-bottom:10px">内置角色不可改名与删除，但可以调整权限组合（例如收紧「访客」）。</div>' : ''}
    ${!ro || !ro.built_in ? `
    <div class="m-row"><label>ROLE NAME / 角色名（2~32 字符）</label>
      <input class="fld" id="roName" value="${ro?esc(ro.name):''}" maxlength="32" placeholder="如：只读访客 / 项目组"></div>
    <div class="m-row"><label>MEMO / 说明</label>
      <input class="fld" id="roMemo" value="${ro&&ro.memo?esc(ro.memo):''}" maxlength="100" placeholder="选填"></div>` : ''}
    <div class="m-row"><label>PERMISSIONS / 权限（勾选 = 该角色账号可用对应按钮）</label>
      <div class="perm-grid" id="roPerms">${permGrid}</div>
      <div style="font-size:10px;color:var(--dim)">已选 <b id="roCnt" style="color:var(--cyan)">0</b> / ${Object.keys(cats).length} 项</div></div>`,
    `<button class="btn" onclick="closeModal()">取消</button>
     <button class="btn primary" id="roSave">${ro?'保存':'创建角色'}</button>`);
  // 勾选联动样式与计数
  const grid = $('#roPerms');
  const cnt = ()=>{ $('#roCnt').textContent = grid.querySelectorAll('input:checked').length; };
  grid.querySelectorAll('label').forEach(lb=>{
    const cb = lb.querySelector('input');
    cb.onchange = ()=>{ lb.classList.toggle('on', cb.checked); cnt(); };
  });
  cnt();
  $('#roSave').onclick = async ()=>{
    const perms = [...grid.querySelectorAll('input:checked')].map(x=>x.value);
    if(!perms.length){ toast('请至少勾选一项权限','warn'); return; }
    const body = {id: ro?ro.id:0, perms};
    const nameEl = $('#roName'), memoEl = $('#roMemo');
    if(nameEl) body.name = nameEl.value.trim();
    if(memoEl) body.memo = memoEl.value.trim();
    if(nameEl && (!body.name || body.name.length<2)){ toast('角色名需 2~32 个字符','warn'); return; }
    const r = await API.post('saverole', body);
    if(!r.ok){ toast(r.error||'保存失败','err'); return; }
    toast(ro?'角色已更新':'角色已创建','ok');
    closeModal(); loadRoles();
  };
}
async function delRole(id, name){
  const r = await API.post('delrole', {id});
  if(!r.ok){ toast(r.error||'删除失败','err'); return; }
  toast('角色「'+name+'」已删除','ok'); loadRoles();
}

/* ================= 拖拽移动（列表/网格 → 文件夹） ================= */
function clearDropHook(){ document.querySelectorAll('.drop-hook').forEach(el=>el.classList.remove('drop-hook')); }
function makeDraggable(el, it){
  if(!hasPerm('move')) return; // 无移动权限的账号不可拖拽
  el.draggable = true;
  el.addEventListener('dragstart', e=>{
    // 拖动的项目在勾选集合内 → 整批移动，否则只移动这一项
    const ids = state.sel.has(it.id) ? [...state.sel] : [it.id];
    try { e.dataTransfer.setData('text/cd-move', JSON.stringify({ids})); } catch(ex){}
    e.dataTransfer.effectAllowed = 'move';
    el.classList.add('drag-src');
    toast(ids.length>1 ? `拖拽移动 ${ids.length} 项到目标文件夹` : `拖拽「${it.name}」到目标文件夹`, '');
  });
  el.addEventListener('dragend', ()=>{ el.classList.remove('drag-src'); clearDropHook(); });
}
function makeDropTarget(el, dirId, dirName){
  el.addEventListener('dragover', e=>{
    if(![...e.dataTransfer.types].includes('text/cd-move')) return; // 只接受内部移动（外部文件仍走拖拽上传）
    e.preventDefault(); e.stopPropagation();
    e.dataTransfer.dropEffect = 'move';
    el.classList.add('drop-hook');
  });
  el.addEventListener('dragleave', ()=>{ el.classList.remove('drop-hook'); });
  el.addEventListener('drop', async e=>{
    if(![...e.dataTransfer.types].includes('text/cd-move')) return;
    e.preventDefault(); e.stopPropagation();
    el.classList.remove('drop-hook');
    const raw = e.dataTransfer.getData('text/cd-move');
    if(!raw) return;
    let ids; try { ids = JSON.parse(raw).ids; } catch(ex){ return; }
    await doMove(ids, dirId, dirName);
  });
}
async function doMove(ids, target, targetName){
  const r = await API.post('move', {ids, target});
  if(!r.ok){ toast(r.error || '移动失败', 'err'); return; }
  toast(`已移动 ${r.moved} 项 → 「${targetName}」`, 'ok');
  if(state.nav==='files'){ await loadDir(state.dirId); }
  else { navTo('files', true); }
  refreshBadges();
}

/* ================= 搜索 ================= */
$('#searchInput').addEventListener('keydown', async e=>{
  if(e.key!=='Enter') return;
  const q = $('#searchInput').value.trim();
  if(!q) return;
  const r = await API.get('a=search&q=' + encodeURIComponent(q));
  if(!r.ok){ toast(r.error||'搜索失败','err'); return; }
  state.searching = true; state.items = r.items; state.sel.clear();
  document.querySelectorAll('.nav-item').forEach(el=>el.classList.remove('on'));
  $('#crumbs').innerHTML = `<span style="color:var(--dim);letter-spacing:2px">SEARCH // “${esc(q)}” 共 ${r.items.length} 项</span>`;
  renderItems();
});
$('#searchInput').addEventListener('input', e=>{
  if(!e.target.value.trim() && state.searching){ state.searching=false; navTo('files'); }
});

/* ================= 视图切换 ================= */
function toggleView(){
  state.view = state.view==='list' ? 'grid' : 'list';
  if(state.nav==='files' || state.searching || state.nav==='recent') renderItems();
}

/* ================= 上传 ================= */
let upQueue = [], upRunning = false, upDone = 0, upFail = 0;
$('#fileInput').addEventListener('change', e=>{ enqueue(e.target.files, null); e.target.value=''; });
$('#dirInput').addEventListener('change', e=>{ enqueue(e.target.files, 'dir'); e.target.value=''; });

async function ensureDirs(relPath, baseId){
  // relPath 形如 top/sub/ 或 top/sub/file.txt，逐级建目录，返回最深目录 id
  const parts = relPath.split('/').filter(p=>p && p!=='' && !p.includes('.htaccess'));
  if(parts.length <= 1) return {id: baseId, ok:true};
  let curId = baseId;
  // 若是文件路径最后一段是文件名，目录部分是前 n-1 段
  const isFile = !relPath.endsWith('/');
  const dirParts = isFile ? parts.slice(0, -1) : parts;
  let firstLevel = true;
  for(const p of dirParts){
    const key = (curId||0) + '/' + p;
    if(state.dirCache[key]){ curId = state.dirCache[key]; firstLevel = false; continue; }
    const parentBefore = curId;
    const r = await API.post('mkdir', {parentId: curId??0, name: p});
    if(r.ok){
      curId = r.id; state.dirCache[key] = curId;
      // 顶层新目录建好后立即在当前目录显示（文件夹上传时无需等文件传完）
      if(firstLevel) notifyUploaded(parentBefore);
      firstLevel = false;
    }
    else {
      // 重名（已存在）→ list 找到同名目录 id
      const lr = await API.get('a=list&dir=' + (curId??'0'));
      if(lr.ok){
        const found = lr.items.find(x=>x.is_dir && x.name===p);
        if(found){ curId = found.id; state.dirCache[key]=curId; continue; }
      }
      return {id: curId, ok:false};
    }
  }
  return {id: curId, ok:true};
}

function enqueue(fileList, mode){
  const files = [...fileList];
  if(!files.length) return;
  if(!hasPerm('upload')){ toast('当前账号无「上传」权限','err'); return; }
  // 超过单片上限的大文件自动走分片上传（绕过 post_max_size），此处不再拦截
  const bigCount = files.filter(f => f.size > directUploadLimit()).length;
  if (bigCount > 0) {
    toast(bigCount + ' 个大文件将使用分片上传（每片 ' + fmtSize(calcChunkSize()) + '，不受主机单请求上限影响）','ok');
  }
  if(mode==='dir'){
    // 文件夹上传：按 webkitRelativePath 建目录
    const topDirs = new Set(files.map(f=>f.webkitRelativePath.split('/')[0]));
    toast('检测到文件夹上传：' + [...topDirs].join('、') + '（将保留目录结构）','warn');
    files.forEach(f=>{
      upQueue.push({file:f, relPath:f.webkitRelativePath||f.name, size:f.size, status:'wait', pct:0});
    });
  } else {
    files.forEach(f=>{
      upQueue.push({file:f, relPath:f.name, size:f.size, status:'wait', pct:0});
    });
  }
  renderUpQueue();
  $('#upPanel').classList.add('on');
  if(!upRunning) runUpload();
}
/* XHR 响应解析：容忍 PHP Warning 污染（自动定位 JSON 起点），非 JSON 时给出可懂提示 */
function parseXhrResp(xhr){
  let data = null;
  const t = xhr.responseText || '';
  const i = t.indexOf('{"ok"');
  if(i >= 0){ try { data = JSON.parse(t.slice(i)); } catch(e){ data = null; } }
  if(data && typeof data.ok !== 'undefined') return data;
  const sc = xhr.status;
  const hint = sc === 413
    ? '服务器拒绝（413：超出主机请求体限制，请调大 upload_max_filesize / post_max_size）'
    : sc >= 400
      ? '服务器返回 HTTP ' + sc + '（可能被主机 WAF/错误页拦截，响应非 JSON）'
      : '响应异常（非 JSON）';
  return {ok:false, error:hint, status: sc};
}
/* 单请求直传上限：文件超过此值即改用分片上传（不再受文件大小限制） */
function directUploadLimit(){
  const lim = state.serverLimits || {};
  let m = 4 * 1024 * 1024;
  if (lim.postMax > 0) m = Math.min(m, Math.max(256*1024, lim.postMax - 1024*1024));
  if (lim.uploadMax > 0) m = Math.min(m, Math.max(256*1024, lim.uploadMax - 256*1024));
  return Math.max(256*1024, Math.floor(m));
}
/* 计算分片大小：优先使用后台配置（自动/自定义），再按主机上限收紧 */
function calcChunkSize(){
  const c = state.chunk;
  let cs = (c && c.effectiveBytes > 0) ? c.effectiveBytes : 4 * 1024 * 1024;
  const lim = state.serverLimits || {};
  if (lim.postMax > 0) cs = Math.min(cs, Math.max(256*1024, lim.postMax - 1024*1024));
  if (lim.uploadMax > 0) cs = Math.min(cs, Math.max(256*1024, lim.uploadMax - 256*1024));
  return Math.max(256*1024, Math.floor(cs));
}
async function uploadInChunks(job, parentId){
  let cs = calcChunkSize();
  job.chunked = true;
  const init = await API.post('chunkinit', {name: job.file.name, size: job.file.size, parentId: parentId===null ? 0 : parentId});
  if(!init.ok) throw new Error(init.error || '分片初始化失败');
  const upId = init.uploadId;
  let offset = 0, retry = 0;
  const sendChunk = (blob, off) => new Promise((resolve)=>{
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?a=chunk&id=' + encodeURIComponent(upId) + '&off=' + off, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Content-Type', 'application/octet-stream');
    xhr.upload.onprogress = ev=>{
      if(ev.lengthComputable){
        job.pct = Math.min(99, Math.round((off + ev.loaded) / job.file.size * 100));
        renderUpQueue();
      }
    };
    xhr.onload = ()=> resolve(parseXhrResp(xhr));
    xhr.onerror = ()=> resolve({ok:false, error:'网络错误（连接中断）'});
    xhr.send(blob);
  });
  try {
    while (offset < job.file.size) {
      const end = Math.min(offset + cs, job.file.size);
      const r = await sendChunk(job.file.slice(offset, end), offset);
      if(!r.ok){
        // 服务器仍嫌片大（post_max/upload_max 或网关限制）→ 片减半重试同一偏移，最多 3 次
        if((r.status === 413 || (r.error||'').indexOf('分片数据为空') >= 0) && retry < 3){
          retry++;
          cs = Math.max(256*1024, Math.floor(cs/2));
          continue;
        }
        throw new Error(r.error || '分片上传失败');
      }
      if (typeof r.received === 'number') offset = Math.max(offset, Math.min(end, r.received));
      else offset = end;
      retry = 0;
      job.pct = Math.round(offset / job.file.size * 100);
      renderUpQueue();
    }
    const fin = await API.post('chunkdone', {id: upId});
    if(!fin.ok) throw new Error(fin.error || '合并失败');
    return fin;
  } catch(err){
    API.post('chunkabort', {id: upId}).catch(()=>{});
    throw err;
  }
}
/* 上传完成即时刷新：单个文件上传完就立刻出现在当前目录，不必等整个队列跑完。
   连续完成多个时用 250ms 合并刷新，避免频繁重渲染。 */
let dirRefreshTimer = null, dirRefreshTarget = null;
function notifyUploaded(parentId){
  if(state.nav==='recent'){ scheduleDirRefresh('recent'); return; }
  if(state.nav!=='files' || state.searching) return; // 搜索结果页不打断用户
  const cur = state.dirId ?? 0;
  if((parentId ?? 0) === cur) scheduleDirRefresh(cur);
}
function scheduleDirRefresh(target){
  dirRefreshTarget = target;
  if(dirRefreshTimer) return;
  dirRefreshTimer = setTimeout(async ()=>{
    const t = dirRefreshTarget;
    dirRefreshTarget = null; dirRefreshTimer = null;
    if(t === 'recent'){
      if(state.nav==='recent') await loadRecent();
    } else if(state.nav==='files' && (state.dirId ?? 0) === t){
      await refreshCurrentDir();
    }
    refreshStats();
  }, 250);
}
/* 静默刷新当前目录：保留用户已勾选的项目，不清空选择 */
async function refreshCurrentDir(){
  const r = await API.get('a=list&dir=' + (state.dirId ?? '0'));
  if(!r.ok || state.nav!=='files') return;
  const keep = new Set(state.sel);
  state.items = r.items; state.crumbs = r.crumbs;
  state.sel = new Set(r.items.filter(x=>keep.has(x.id)).map(x=>x.id));
  renderCrumbs(r.crumbs);
  renderItems();
}
async function runUpload(){
  upRunning = true;
  while(upQueue.length){
    const job = upQueue[0];
    if(job.status==='done' || job.status==='error'){ upQueue.shift(); continue; }
    job.status = 'uploading'; renderUpQueue();
    try {
      // 建目录
      const d = await ensureDirs(job.relPath, state.nav==='files' ? state.dirId : null);
      if(!d.ok) throw new Error('目录创建失败');
      notifyUploaded(d.id); // 新建的子目录也立即显示
      if (job.file.size > directUploadLimit()) {
        // 大文件：分片上传（绕过 post_max_size，每片单独请求）
        const fin = await uploadInChunks(job, d.id);
        job.status='done'; job.pct=100; job.finalName=fin.name;
      } else {
        const fd = new FormData();
        fd.append('parentId', d.id===null ? '0' : String(d.id));
        fd.append('files', job.file, job.file.name);
        const result = await new Promise((resolve)=>{
          const xhr = new XMLHttpRequest();
          xhr.open('POST', 'api.php?a=upload', true);
          xhr.withCredentials = true;
          xhr.upload.onprogress = ev=>{
            if(ev.lengthComputable){
              job.pct = Math.round(ev.loaded/ev.total*100);
              renderUpQueue();
            }
          };
          xhr.onload = ()=> resolve(parseXhrResp(xhr));
          xhr.onerror = ()=> resolve({ok:false, error:'网络错误（连接中断，可能超出主机网关限制）'});
          xhr.send(fd);
        });
        if(result.ok){
          const one = result.results[0];
          if(one && one.ok){ job.status='done'; job.pct=100; job.finalName=one.name; }
          else { job.status='error'; job.error = one ? one.error : '上传失败'; upFail++; }
        } else { job.status='error'; job.error = result.error || '上传失败'; upFail++; }
      }
      if(job.status==='done') notifyUploaded(d.id); // 完成即刷新目录
    } catch(err){
      job.status='error'; job.error = err.message||'上传失败'; upFail++;
    }
    renderUpQueue();
    upDone++;
  }
  upRunning = false;
  if(upFail===0) toast('✔ 全部上传完成','ok');
  else toast(`上传完成：成功 ${upDone-upFail}，失败 ${upFail}`,'warn');
  upDone = 0; upFail = 0;
  if(state.nav==='files' && !state.searching){
    // 收尾刷新同样保留已勾选项（loadDir 会清空选择）
    await refreshCurrentDir();
  } else if(state.nav==='recent'){
    await loadRecent();
  }
  refreshBadges();
}
function renderUpQueue(){
  const box = $('#upItems');
  box.innerHTML = upQueue.slice(-40).map(j=>{
    const st = j.status==='done' ? '<span class="st-ok">✔ 完成</span>'
      : j.status==='error' ? `<span class="st-err">✖ ${esc(j.error||'失败')}</span>`
      : j.status==='uploading' ? `<span style="color:var(--cyan)">▲ ${j.chunked?'分片 ':''}${j.pct}%</span>`
      : '<span>… 等待</span>';
    return `<div class="up-item"><div class="un" title="${esc(j.relPath)}">${esc(j.relPath)}</div>
      <div class="ustat"><span>${fmtSize(j.size)}</span>${st}</div>
      <div class="up-track"><div class="up-fill" style="width:${j.pct}%"></div></div></div>`;
  }).join('');
}
function clearUpPanel(){
  upQueue = upQueue.filter(j=>j.status==='uploading');
  renderUpQueue();
  if(!upQueue.length) $('#upPanel').classList.remove('on');
}

/* ================= 拖拽上传 ================= */
let dragDepth = 0;
document.addEventListener('dragenter', e=>{
  if(![...e.dataTransfer.types].includes('Files')) return;
  dragDepth++; $('#dropMask').classList.add('on');
});
document.addEventListener('dragleave', e=>{
  dragDepth--; if(dragDepth<=0){ dragDepth=0; $('#dropMask').classList.remove('on'); }
});
document.addEventListener('dragover', e=>e.preventDefault());
document.addEventListener('drop', e=>{
  e.preventDefault(); dragDepth=0; $('#dropMask').classList.remove('on');
  if(e.dataTransfer.files.length) enqueue(e.dataTransfer.files, null);
});

/* ================= 预览 ================= */
const docxExts = ['docx'];
const sheetExts = ['xlsx','xlsm','xls','et','csv'];
const pptxExts = ['pptx'];
const legacyOfficeExts = ['doc','xls','ppt'];
async function previewNode(id, name){
  const ext = (name.split('.').pop()||'').toLowerCase();
  if(ext==='zip' && hasPerm('preview')){ zipBrowse(id, name); return; }
  const url = imgUrl(id);
  const box = $('#pvBox');
  let inner = '';
  const imgExts = ['png','jpg','jpeg','gif','webp','bmp','svg'];
  const vidExts = ['mp4','webm','mov','mkv','flv','m4v'];
  const audExts = ['mp3','wav','flac','aac','ogg','m4a'];
  const txtExts = ['txt','md','json','xml','log','php','js','css','html','ini','sql','yml','yaml','py','java','c','cpp','h','bat','sh','conf'];
  if(imgExts.includes(ext)){
    inner = `<img src="${url}" alt="${esc(name)}">`;
  } else if(vidExts.includes(ext)){
    inner = `<video src="${url}" controls autoplay></video>`;
  } else if(audExts.includes(ext)){
    inner = `<audio src="${url}" controls autoplay></audio>`;
  } else if(ext==='pdf'){
    inner = `<iframe src="${url}" style="width:80vw;height:72vh;border:1px solid var(--line);background:#0a1220"></iframe>`;
  } else if(docxExts.includes(ext) || sheetExts.includes(ext) || pptxExts.includes(ext)){
    // Office 在线预览（Word/Excel/PowerPoint，浏览器端本地解析，无需外网与 Office）
    await previewOffice(id, name, ext);
    return;
  } else if(legacyOfficeExts.includes(ext)){
    toast(ext.toUpperCase() + ' 为旧版二进制格式，暂不支持在线预览，已开始下载','warn');
    location.href = 'download.php?id=' + id;
    return;
  } else if(txtExts.includes(ext) || ['b-doc'].includes(catOf(false,name,null)[0])){
    const r = await fetch(url, {credentials:'same-origin'});
    const text = await r.text();
    inner = `<div class="txtview">${esc(text.slice(0,300000))}</div>`;
  } else {
    toast('该类型不支持在线预览，已开始下载','warn');
    location.href = 'download.php?id=' + id;
    return;
  }
  box.innerHTML = `<div class="pv-title">◈ ${esc(name)}</div>` + inner + `<div class="cap" style="margin-top:10px">ESC 关闭</div><div class="pv-x" onclick="closePreview()">✕</div>`;
  $('#preview').classList.add('on');
}

/* ---------- Office 预览渲染 ---------- */
const __loaded = {};
function loadScript(src){
  return new Promise((res, rej)=>{
    if(__loaded[src]) return res();
    const s = document.createElement('script');
    s.src = src; s.onload = ()=>{ __loaded[src] = 1; res(); }; s.onerror = ()=>rej(new Error('加载组件失败: ' + src));
    document.head.appendChild(s);
  });
}
function loadCss(href){
  return new Promise(res=>{
    if(__loaded[href]) return res();
    const l = document.createElement('link'); l.rel='stylesheet'; l.href = href;
    l.onload = l.onerror = ()=>{ __loaded[href] = 1; res(); };
    document.head.appendChild(l);
    __loaded[href] = 2; // Safari 可能不触发 onload
  });
}
async function fetchArrayBuffer(url){
  const r = await fetch(url, {credentials:'same-origin'});
  if(!r.ok) throw new Error('文件读取失败 (HTTP ' + r.status + ')');
  return await r.arrayBuffer();
}
async function previewOffice(id, name, ext){
  const box = $('#pvBox');
  box.innerHTML = `<div class="pv-title">◈ ${esc(name)}</div>
    <div class="pv-loading"><span class="spin"></span>DECODE // 正在解析文档…</div>
    <div class="cap">ESC 关闭</div><div class="pv-x" onclick="closePreview()">✕</div>`;
  $('#preview').classList.add('on');
  try {
    if(docxExts.includes(ext)){
      await loadScript('libs/mammoth.browser.min.js');
      const buf = await fetchArrayBuffer(imgUrl(id));
      const result = await window.mammoth.convertToHtml({arrayBuffer: buf});
      const html = result.value || '<p>（无文本内容）</p>';
      box.innerHTML = `<div class="pv-title">◈ ${esc(name)}</div>
        <div class="docview"><div class="doc-body">${html}</div></div>
        <div class="cap">Word 文档 · 本地渲染 · ESC 关闭</div><div class="pv-x" onclick="closePreview()">✕</div>`;
    } else if(sheetExts.includes(ext)){
      await loadScript('libs/xlsx.full.min.js');
      const buf = await fetchArrayBuffer(imgUrl(id));
      const wb = XLSX.read(buf, {type:'array'});
      let tabs = '', sheets = '';
      const rowCount = ws => {
        const rg = ws['!ref'] || 'A1';
        const m = rg.match(/([A-Z]+)(\d+):([A-Z]+)(\d+)/);
        return m ? (parseInt(m[4],10) - parseInt(m[2],10) + 1) : 0;
      };
      wb.SheetNames.forEach((sn, i)=>{
        const ws = wb.Sheets[sn];
        const full = XLSX.utils.sheet_to_html(ws, {id: ''});
        const rows = rowCount(ws);
        const trimmed = rows > 2000
          ? full.replace(/(<tr[^>]*>[\s\S]*?<\/tr>){2000}([\s\S]*)<\/table>/, (m)=>m)+`<div class="xwarn">（共 ${rows} 行，已显示前 2000 行）</div>`
          : full;
        sheets += `<div class="xtab${i===0?' on':''}" id="xtab-${i}">${trimmed}</div>`;
        tabs += `<button class="bt btab${i===0?' on':''}" onclick="sheetTab(${i},this)">${esc(sn)}${rows>2000?' *':''}</button>`;
      });
      box.innerHTML = `<div class="pv-title">◈ ${esc(name)}</div>
        <div class="tabsbar">${tabs}</div><div class="xwrap">${sheets}</div>
        <div class="cap">Excel 电子表格 · ${wb.SheetNames.length} 个工作表 · 本地渲染 · ESC 关闭</div><div class="pv-x" onclick="closePreview()">✕</div>`;
    } else if(pptxExts.includes(ext)){
      await loadCss('libs/pptx/pptxjs.css');
      await loadScript('libs/jquery.min.js');
      await loadScript('libs/pptx/filereader.min.js');
      await loadScript('libs/pptx/jszip.min.js');
      await loadScript('libs/pptx/pptxjs.min.js');
      box.innerHTML = `<div class="pv-title">◈ ${esc(name)}</div>
        <div class="pwrap"><div id="pptxHolder" style="width:100%"></div></div>
        <div class="cap">PowerPoint 幻灯片 · 本地渲染 · ESC 关闭</div><div class="pv-x" onclick="closePreview()">✕</div>`;
      await new Promise((res)=>{ setTimeout(res, 40); });
      window.jQuery('#pptxHolder').pptxToHtml({ pptxFileUrl: imgUrl(id), slideMode:false, slidesScale:'80%' });
    }
  } catch(err){
    box.innerHTML = `<div class="pv-title">◈ ${esc(name)}</div>
      <div style="padding:30px;color:var(--warn);font-size:13px">✖ 文档解析失败：${esc(err.message||'未知错误')}<br><span style="color:var(--dim);font-size:11px">文件可能损坏、加密或版本不兼容，可下载后用 Office 打开。</span></div>
      <div class="cap">ESC 关闭</div><div class="pv-x" onclick="closePreview()">✕</div>`;
  }
}
function sheetTab(i, btn){
  document.querySelectorAll('.xtab').forEach(x=>x.classList.remove('on'));
  document.querySelectorAll('.btab').forEach(x=>x.classList.remove('on'));
  $('#xtab-' + i).classList.add('on');
  btn.classList.add('on');
}
function closePreview(){
  $('#preview').classList.remove('on');
  $('#pvBox').innerHTML = '';
}
document.addEventListener('keydown', e=>{
  if(e.key==='Escape'){ closePreview(); closeModal(); closeCtx(); }
  if(e.key==='Delete' && state.sel.size && state.nav==='files' && $('#modalMask').classList.contains('on')===false && $('#preview').classList.contains('on')===false){
    batchTrash();
  }
  if((e.ctrlKey||e.metaKey) && e.key==='a' && !$('#searchInput').matches(':focus') && document.activeElement.tagName!=='INPUT'){
    e.preventDefault(); toggleAll(true);
  }
});

/* ================= 启动 ================= */
boot();
</script>
</body>
</html>
