/* ============================================================
   CONTRACTOR PORTAL — contractor.js v3.0
   Session-based auth, real DB calls via api/contractor.php
   ============================================================ */

/* ─── State ──────────────────────────────────────────────── */
const State = {
  sidebar: {
    collapsed:  localStorage.getItem('sidebarCollapsed') === 'true',
    mobileOpen: false,
  },
  user:        null,
  theme:       localStorage.getItem('portalTheme') || 'dark',
  currentPage: '',
};

/* ─── DOM Ready ─────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
  applyTheme(State.theme, false);
  initSidebar();
  initHeaderDate();
  initToastContainer();
  markActivePage();
  initModalClose();
  initThemeToggle();
  loadUser(); // check session
});

/* ─── Auth ───────────────────────────────────────────────── */
async function loadUser() {
  try {
    const data = await apiFetch('api/auth.php?action=me');
    if (!data.user || data.user.role !== 'contractor') {
      window.location.href = 'guest_login.html';
      return;
    }
    State.user = data.user;
    renderUserInSidebar(State.user);
    loadBadgeCounts();
  } catch(e) {
    window.location.href = 'guest_login.html';
  }
}

async function loadBadgeCounts() {
  try {
    const bookings = await apiFetch('api/contractor.php?action=bookings&status=scheduled');
    const newJobs  = Array.isArray(bookings) ? bookings.filter(b => b.status === 'scheduled').length : 0;
    const bb = document.getElementById('bookingsBadge');
    if (bb) { bb.textContent = newJobs; bb.style.display = newJobs > 0 ? 'flex' : 'none'; }

    const convos  = await apiFetch('api/contractor.php?action=conversations');
    const unread  = Array.isArray(convos) ? convos.reduce((s,c) => s + (parseInt(c.unread)||0), 0) : 0;
    const mb = document.getElementById('messagesBadge');
    if (mb) { mb.textContent = unread; mb.style.display = unread > 0 ? 'flex' : 'none'; }
  } catch(_) {}
}

/* ─── Core API fetch ─────────────────────────────────────── */
async function apiFetch(endpoint, method = 'GET', body = null) {
  const opts = { method, credentials: 'include', headers: {} };
  if (body) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
  const res = await fetch(endpoint, opts);
  if (res.status === 401) { window.location.href = 'guest_login.html'; throw new Error('Unauthorized'); }
  const data = await res.json();
  if (!res.ok) throw new Error(data.error || 'Request failed');
  return data;
}

/* ─── Theme ──────────────────────────────────────────────── */
function applyTheme(theme, save = true) {
  State.theme = theme;
  document.documentElement.setAttribute('data-theme', theme);
  document.querySelectorAll('.theme-toggle-btn').forEach(b => { b.textContent = theme === 'dark' ? '☀️' : '🌙'; });
  if (save) localStorage.setItem('portalTheme', theme);
}

function toggleTheme() {
  const next = State.theme === 'dark' ? 'light' : 'dark';
  applyTheme(next);
  showToast(`Switched to ${next} mode`, 'info', 2000);
}

function initThemeToggle() {
  document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
    btn.addEventListener('click', toggleTheme);
    btn.textContent = State.theme === 'dark' ? '☀️' : '🌙';
  });
}

/* ─── Sidebar ────────────────────────────────────────────── */
function initSidebar() {
  const sidebar   = document.getElementById('sidebar');
  const main      = document.getElementById('mainContent');
  const toggleBtn = document.getElementById('sidebarToggle');
  const overlay   = document.getElementById('sidebarOverlay');
  const mobileBtn = document.getElementById('mobileMenuBtn');
  if (!sidebar) return;

  if (State.sidebar.collapsed && window.innerWidth > 768) {
    sidebar.classList.add('collapsed');
    main && main.classList.add('expanded');
  }
  toggleBtn && toggleBtn.addEventListener('click', () => {
    if (window.innerWidth <= 768) openMobileSidebar();
    else toggleDesktopSidebar();
  });
  mobileBtn && mobileBtn.addEventListener('click', openMobileSidebar);
  overlay   && overlay.addEventListener('click', closeMobileSidebar);
  window.addEventListener('resize', () => { if (window.innerWidth > 768) closeMobileSidebar(); });
}

function toggleDesktopSidebar() {
  const sidebar = document.getElementById('sidebar');
  const main    = document.getElementById('mainContent');
  if (!sidebar) return;
  sidebar.classList.toggle('collapsed');
  main && main.classList.toggle('expanded');
  State.sidebar.collapsed = sidebar.classList.contains('collapsed');
  localStorage.setItem('sidebarCollapsed', State.sidebar.collapsed);
}
function openMobileSidebar()  { document.getElementById('sidebar')?.classList.add('mobile-open'); document.getElementById('sidebarOverlay')?.classList.add('visible'); }
function closeMobileSidebar() { document.getElementById('sidebar')?.classList.remove('mobile-open'); document.getElementById('sidebarOverlay')?.classList.remove('visible'); }

/* ─── User render ────────────────────────────────────────── */
function renderUserInSidebar(user) {
  const initials   = user.initials || (user.name||'').split(' ').map(n=>n[0]).join('').toUpperCase().slice(0,2);
  const nameEl     = document.getElementById('sidebarUserName');
  const roleEl     = document.getElementById('sidebarUserRole');
  const avatarEl   = document.getElementById('sidebarAvatar');
  const fallback   = document.getElementById('sidebarAvatarFallback');
  const headerName = document.getElementById('headerUserName');

  if (nameEl)     nameEl.textContent     = user.name;
  if (roleEl)     roleEl.textContent     = 'Contractor';
  if (headerName) headerName.textContent = (user.name||'').split(' ')[0];

  if (user.avatar) {
    avatarEl && (avatarEl.src = user.avatar, avatarEl.style.display = 'block');
    fallback && (fallback.style.display = 'none');
  } else {
    avatarEl && (avatarEl.style.display = 'none');
    if (fallback) { fallback.style.display = 'flex'; fallback.textContent = initials; }
  }
}

/* ─── Active page ────────────────────────────────────────── */
function markActivePage() {
  const path = window.location.pathname.split('/').pop();
  document.querySelectorAll('.nav-item[data-page]').forEach(item => {
    item.classList.toggle('active', item.dataset.page === path);
  });
  State.currentPage = path;
}

/* ─── Header date ────────────────────────────────────────── */
function initHeaderDate() {
  ['headerDate','headerDateChip'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = new Date().toLocaleDateString('en-PH', { weekday:'short', year:'numeric', month:'short', day:'numeric' });
  });
}

/* ─── Toast ──────────────────────────────────────────────── */
function initToastContainer() {
  if (!document.getElementById('toast-container')) {
    const c = document.createElement('div'); c.id = 'toast-container'; document.body.appendChild(c);
  }
}

function showToast(message, type = 'info', duration = 3500) {
  const icons = { success:'✅', error:'❌', warning:'⚠️', info:'ℹ️' };
  const c = document.getElementById('toast-container');
  if (!c) return;
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<span class="toast-icon">${icons[type]||'ℹ️'}</span><span class="toast-msg">${message}</span>`;
  c.appendChild(t);
  setTimeout(() => { t.style.cssText='opacity:0;transform:translateX(30px);transition:0.3s ease'; setTimeout(()=>t.remove(),300); }, duration);
}

/* ─── Modal ──────────────────────────────────────────────── */
function openModal(id)  { const m=document.getElementById(id); if(m){m.classList.add('open');document.body.style.overflow='hidden';} }
function closeModal(id) { const m=document.getElementById(id); if(m){m.classList.remove('open');document.body.style.overflow='';} }
function initModalClose() {
  document.querySelectorAll('[data-close-modal]').forEach(b => b.addEventListener('click', ()=>closeModal(b.dataset.closeModal)));
  document.querySelectorAll('.modal-overlay').forEach(o => o.addEventListener('click', e => {
    if (e.target === o) { o.classList.remove('open'); document.body.style.overflow=''; }
  }));
}

/* ─── Logout ─────────────────────────────────────────────── */
function handleLogout(e) {
  if (e) e.preventDefault();
  if (document.getElementById('logoutModal')) openModal('logoutModal');
  else if (confirm('Log out?')) confirmLogout();
}

async function confirmLogout() {
  try { await apiFetch('api/auth.php?action=logout', 'POST'); } catch(_) {}
  window.location.href = 'guest_login.html';
}

/* ─── Loading / Empty state helpers ─────────────────────── */
function loadingHTML() {
  return `<div style="display:flex;align-items:center;justify-content:center;padding:40px;color:var(--text-muted);gap:10px">
    <div style="width:18px;height:18px;border:2px solid var(--border);border-top-color:var(--gold);border-radius:50%;animation:spin 0.8s linear infinite"></div>
    Loading…</div>`;
}

function emptyState(icon, title, sub='') {
  return `<div class="empty-state"><span class="empty-state-icon">${icon}</span><div class="empty-state-title">${title}</div>${sub?`<div style="font-size:12px;color:var(--text-dim);margin-top:4px">${sub}</div>`:''}</div>`;
}

/* ─── Formatters ─────────────────────────────────────────── */
function formatDate(d) {
  if (!d) return '—';
  return new Date(d+'T00:00:00').toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
}
function formatTime12(t) {
  if (!t) return '—';
  const [h, m] = t.split(':').map(Number);
  return `${h%12||12}:${String(m).padStart(2,'0')} ${h>=12?'PM':'AM'}`;
}
function formatCurrency(a) {
  return new Intl.NumberFormat('en-PH', { style:'currency', currency:'PHP' }).format(a||0);
}
function escapeHtml(s) {
  const d = document.createElement('div'); d.textContent = String(s||''); return d.innerHTML;
}
function timeAgo(mins) {
  mins = parseInt(mins)||0;
  if (mins < 1)   return 'Just now';
  if (mins < 60)  return `${mins}m ago`;
  const h = Math.floor(mins/60);
  if (h < 24)     return `${h}h ago`;
  return `${Math.floor(h/24)}d ago`;
}
function debounce(fn, delay=300) {
  let t; return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), delay); };
}
function exportToCSV(rows, filename='export.csv') {
  const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
  const blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
  const a    = Object.assign(document.createElement('a'), { href:URL.createObjectURL(blob), download:filename });
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
function setupAvatarUpload(inputId, previewId) {
  const input   = document.getElementById(inputId);
  const preview = document.getElementById(previewId);
  if (!input || !preview) return;
  input.addEventListener('change', e => {
    const file = e.target.files[0]; if (!file) return;
    if (file.size > 5*1024*1024) { showToast('Image must be under 5MB','error'); return; }
    const reader = new FileReader();
    reader.onload = ev => { preview.src = ev.target.result; };
    reader.readAsDataURL(file);
  });
}
function startOTPCountdown(elementId, seconds=60, onEnd) {
  const el = document.getElementById(elementId); if (!el) return;
  let remaining = seconds;
  el.textContent = `Resend in ${remaining}s`; el.style.pointerEvents='none'; el.style.opacity='0.5';
  const iv = setInterval(() => {
    remaining--;
    el.textContent = `Resend in ${remaining}s`;
    if (remaining <= 0) {
      clearInterval(iv); el.textContent='Resend Code';
      el.style.pointerEvents=''; el.style.opacity='';
      onEnd && onEnd();
    }
  }, 1000);
}
function autoResizeTextarea(el) { el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,140)+'px'; }
function initTabs(selector) {
  document.querySelectorAll(selector).forEach(wrapper => {
    wrapper.querySelectorAll('.tab-btn').forEach(tab => {
      tab.addEventListener('click', () => {
        wrapper.querySelectorAll('.tab-btn').forEach(t=>t.classList.remove('active'));
        tab.classList.add('active');
        const target = tab.dataset.tab;
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id===target));
      });
    });
  });
}

/* ─── Global exports ─────────────────────────────────────── */
window.ContractorPortal = {
  State, applyTheme, toggleTheme,
  toggleDesktopSidebar, openMobileSidebar, closeMobileSidebar,
  showToast, openModal, closeModal,
  handleLogout, confirmLogout,
  apiFetch, loadingHTML, emptyState,
  formatDate, formatTime12, formatCurrency, escapeHtml, timeAgo,
  setupAvatarUpload, startOTPCountdown, debounce, exportToCSV,
  initTabs, autoResizeTextarea,
};