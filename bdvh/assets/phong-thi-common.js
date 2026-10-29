/* bdvh — phòng thi trực tiếp: tiện ích dùng chung cho phong-thi.html và giam-sat.html (chung mã với KIEMTRA) */
'use strict';

const KT = {
  API: new URL('api.php', document.baseURI).href,
  serverOffset: 0, // ms: giờ máy chủ − giờ máy này
};

async function api(action, data = {}, opt = {}) {
  const body = JSON.stringify(Object.assign({ action }, data));
  let res;
  try {
    res = await fetch(KT.API, {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain;charset=utf-8' },
      body,
      keepalive: !!opt.keepalive && body.length < 60000,
      cache: 'no-store',
    });
  } catch (e) {
    const err = new Error('Mất kết nối mạng. Đang thử lại…');
    err.network = true;
    throw err;
  }
  let j;
  try { j = await res.json(); } catch (e) { throw new Error('Máy chủ trả lời không hợp lệ (' + res.status + ').'); }
  if (j.data && j.data.serverNow) syncClock(j.data.serverNow);
  if (j.status !== 'success') throw new Error(j.message || 'Có lỗi xảy ra.');
  return opt.raw ? j : j.data;
}

/** Hiển thị lại công thức Toán (MathJax đã nạp ở trang). */
function typeset(el) {
  if (window.MathJax && window.MathJax.typesetPromise) {
    window.MathJax.typesetPromise(el ? [el] : undefined).catch(() => { });
  }
}

/** 'YYYY-MM-DD HH:MM:SS' (giờ VN) → ms epoch */
function parseDT(s) {
  if (!s) return null;
  const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
  if (!m) return null;
  return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4] - 7, +m[5], +m[6]);
}
function syncClock(serverNow) {
  const t = parseDT(serverNow);
  if (t) KT.serverOffset = t - Date.now();
}
function srvNow() { return Date.now() + KT.serverOffset; }
function fmtClock(ms) {
  ms = Math.max(0, ms);
  const s = Math.floor(ms / 1000), h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = s % 60;
  return (h ? h + ':' + String(m).padStart(2, '0') : String(m).padStart(2, '0')) + ':' + String(x).padStart(2, '0');
}
function fmtTime(s) { return s ? String(s).slice(11, 16) : '—'; }
function fmtDateTime(s) { return s ? String(s).slice(8, 10) + '/' + String(s).slice(5, 7) + ' ' + String(s).slice(11, 16) : '—'; }
function fmtNum(x, d = 2) { return x === null || x === undefined || isNaN(x) ? '—' : Number(x).toFixed(d).replace('.', ','); }

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function $(sel, root = document) { return root.querySelector(sel); }
function $$(sel, root = document) { return Array.from(root.querySelectorAll(sel)); }
function h(html) { const t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstElementChild; }

function toast(msg, kind = '', ms = 3200) {
  let box = $('#toast');
  if (!box) { box = document.createElement('div'); box.id = 'toast'; document.body.appendChild(box); }
  const el = h(`<div class="toast ${kind}" role="status">${esc(msg)}</div>`);
  box.appendChild(el);
  setTimeout(() => el.remove(), ms);
}


/** Hộp thoại đơn giản. Trả Promise<boolean|string>. */
function modal({ title, html, okText = 'Đồng ý', cancelText = 'Huỷ', danger = false, wide = false, onOpen, onOk, hideOk = false }) {
  return new Promise(resolve => {
    const bg = h(`<div class="modal-bg"><div class="modal" style="${wide ? 'max-width:1000px' : ''}" role="dialog" aria-modal="true">
      <header><h2>${esc(title)}</h2><button class="btn ghost sm" data-x aria-label="Đóng">✕</button></header>
      <div class="body">${html}</div>
      <footer>${cancelText ? `<button class="btn" data-x>${esc(cancelText)}</button>` : ''}${hideOk ? '' : `<button class="btn ${danger ? 'danger solid' : 'primary'}" data-ok>${esc(okText)}</button>`}</footer>
    </div></div>`);
    const close = v => { bg.remove(); document.removeEventListener('keydown', onKey); resolve(v); };
    const onKey = e => { if (e.key === 'Escape') close(false); };
    document.addEventListener('keydown', onKey);
    $$('[data-x]', bg).forEach(b => b.onclick = () => close(false));
    const ok = $('[data-ok]', bg);
    if (ok) ok.onclick = async () => {
      if (!onOk) return close(true);
      ok.disabled = true;
      try { const r = await onOk(bg); if (r !== false) close(r === undefined ? true : r); }
      catch (e) { toast(e.message, 'bad'); }
      finally { ok.disabled = false; }
    };
    document.body.appendChild(bg);
    if (onOpen) onOpen(bg, close);
  });
}

/** Vẽ mã QR vào <img> (dùng qrcode.js đã đóng gói sẵn — không cần mạng). */
function qrDataUrl(text, cell = 8) {
  const qr = qrcode(0, 'M');
  qr.addData(text);
  qr.make();
  return qr.createDataURL(cell, 2);
}

/** Tách bảng dán từ Excel (TSV) hoặc CSV (có ngoặc kép). */
function parseTable(text) {
  text = text.replace(/^﻿/, '').replace(/\r\n?/g, '\n');
  const first = text.split('\n')[0] || '';
  const delim = first.includes('\t') ? '\t' : (first.split(';').length > first.split(',').length ? ';' : ',');
  const rows = [];
  let row = [], cell = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) {
      if (c === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else q = false; }
      else cell += c;
    } else if (c === '"' && cell === '') q = true;
    else if (c === delim) { row.push(cell); cell = ''; }
    else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = ''; }
    else cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows.filter(r => r.some(x => String(x).trim() !== ''));
}
function deaccent(s) {
  return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase().trim().replace(/\s+/g, '_');
}

function downloadCSV(name, rows) {
  const csv = '﻿' + rows.map(r => r.map(v => {
    const s = v === null || v === undefined ? '' : String(v);
    return /[",\n;]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }).join(',')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 2000);
}

const BLOOM = { 1: 'Nhớ', 2: 'Hiểu', 3: 'Vận dụng', 4: 'Phân tích', 5: 'Đánh giá', 6: 'Sáng tạo' };
const DIFF = { 1: 'Dễ', 2: 'Trung bình', 3: 'Khó' };
const QTYPE = { single: 'Một đáp án', multi: 'Nhiều đáp án', short: 'Trả lời ngắn' };
