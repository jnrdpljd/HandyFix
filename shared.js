async function hfFetch(endpoint, method = 'GET', body = null) {
  const opts = {
    method,
    credentials: 'include',   // REQUIRED for PHP sessions
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  };
  if (body) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(body);
  }

  let res;
  try {
    res = await fetch(endpoint, opts);
  } catch (networkErr) {
    throw new Error('Cannot connect to server. Make sure XAMPP is running and you are accessing via http://localhost/handyfix/');
  }

  let data;
  try {
    data = await res.json();
  } catch (parseErr) {
    throw new Error('Server returned an unexpected response (not JSON). Check PHP error logs.');
  }

  if (!res.ok) {
    throw new Error(data.error || `Server error (${res.status})`);
  }
  return data;
}

/* ─── HF namespace (legacy compat + utilities) ───────────── */
const HF = (() => {

  /* ─── State ──────────────────────────────────────────────── */
  const state = {
    theme:   localStorage.getItem('hfTheme') || 'dark',
    sidebar: { collapsed: localStorage.getItem('hfSidebarCollapsed') === 'true' },
    user:    null,
  };

  /* ─── Theme ──────────────────────────────────────────────── */
  function applyTheme(t, save = true) {
    state.theme = t;
    document.documentElement.setAttribute('data-theme', t);
    document.querySelectorAll('.theme-toggle-btn').forEach(b => {
      b.textContent = t === 'dark' ? '☀️' : '🌙';
    });
    if (save) localStorage.setItem('hfTheme', t);
  }
  function toggleTheme() {
    const next = state.theme === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    showToast(`Switched to ${next} mode`, 'info', 2000);
  }

  /* ─── Toast ──────────────────────────────────────────────── */
  function initToastContainer() {
    if (!document.getElementById('toast-container')) {
      const c = document.createElement('div');
      c.id = 'toast-container';
      document.body.appendChild(c);
    }
  }
  function showToast(msg, type = 'info', dur = 3500) {
    const icons = { success:'✅', error:'❌', warning:'⚠️', info:'ℹ️' };
    let c = document.getElementById('toast-container');
    if (!c) { initToastContainer(); c = document.getElementById('toast-container'); }
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `<span class="toast-icon">${icons[type]||'ℹ️'}</span><span class="toast-msg">${msg}</span>`;
    c.appendChild(t);
    setTimeout(() => {
      t.style.cssText = 'opacity:0;transform:translateX(30px);transition:0.3s ease';
      setTimeout(() => t.remove(), 300);
    }, dur);
  }

  /* ─── Modal ──────────────────────────────────────────────── */
  function openModal(id)  { const m=document.getElementById(id); if(m){m.classList.add('open');document.body.style.overflow='hidden';} }
  function closeModal(id) { const m=document.getElementById(id); if(m){m.classList.remove('open');document.body.style.overflow='';} }
  function initModalClose() {
    document.querySelectorAll('[data-close-modal]').forEach(b =>
      b.addEventListener('click', () => closeModal(b.dataset.closeModal))
    );
    document.querySelectorAll('.modal-overlay').forEach(o =>
      o.addEventListener('click', e => {
        if (e.target === o) { o.classList.remove('open'); document.body.style.overflow = ''; }
      })
    );
  }

  /* ─── Sidebar ────────────────────────────────────────────── */
  function initSidebar() {
    const sidebar = document.getElementById('sidebar');
    const main    = document.getElementById('mainContent');
    const tog     = document.getElementById('sidebarToggle');
    const ov      = document.getElementById('sidebarOverlay');
    const mob     = document.getElementById('mobileMenuBtn');
    if (!sidebar) return;

    if (state.sidebar.collapsed && window.innerWidth > 768) {
      sidebar.classList.add('collapsed');
      main && main.classList.add('expanded');
    }
    tog && tog.addEventListener('click', () =>
      window.innerWidth <= 768 ? openMobileSidebar() : toggleDesktopSidebar()
    );
    mob && mob.addEventListener('click', openMobileSidebar);
    ov  && ov.addEventListener('click', closeMobileSidebar);
    window.addEventListener('resize', () => {
      if (window.innerWidth > 768) closeMobileSidebar();
    });
  }
  function toggleDesktopSidebar() {
    const s = document.getElementById('sidebar');
    const m = document.getElementById('mainContent');
    if (!s) return;
    s.classList.toggle('collapsed');
    m && m.classList.toggle('expanded');
    state.sidebar.collapsed = s.classList.contains('collapsed');
    localStorage.setItem('hfSidebarCollapsed', state.sidebar.collapsed);
  }
  function openMobileSidebar()  {
    document.getElementById('sidebar')?.classList.add('mobile-open');
    document.getElementById('sidebarOverlay')?.classList.add('visible');
  }
  function closeMobileSidebar() {
    document.getElementById('sidebar')?.classList.remove('mobile-open');
    document.getElementById('sidebarOverlay')?.classList.remove('visible');
  }

  /* ─── Auth check ─────────────────────────────────────────── */
  async function checkAuth(expectedRole = null) {
    try {
      const data = await hfFetch('api/auth.php?action=me');
      if (!data || !data.user) throw new Error('Not authenticated');
      if (expectedRole && data.user.role !== expectedRole) {
        window.location.href = 'guest_login.html';
        return null;
      }
      state.user = data.user;
      renderUserInSidebar(data.user);
      // Update headerUserName if present
      const hName = document.getElementById('headerUserName');
      if (hName) hName.textContent = (data.user.name || '').split(' ')[0];
      return data.user;
    } catch(e) {
      window.location.href = 'guest_login.html';
      return null;
    }
  }

  /* ─── User render ────────────────────────────────────────── */
  function renderUserInSidebar(user) {
    const ini = user.initials || (user.name||'').split(' ').map(n=>n[0]).join('').toUpperCase().slice(0,2);
    const setEl = (id, val) => { const el=document.getElementById(id); if(el) el.textContent=val; };
    setEl('sidebarUserName', user.name);
    setEl('sidebarUserRole', user.role.charAt(0).toUpperCase()+user.role.slice(1));
    const fallback = document.getElementById('sidebarAvatarFallback');
    const avatar   = document.getElementById('sidebarAvatar');
    if (user.avatar) {
      avatar   && (avatar.src=user.avatar, avatar.style.display='block');
      fallback && (fallback.style.display='none');
    } else {
      avatar   && (avatar.style.display='none');
      if (fallback) { fallback.style.display='flex'; fallback.textContent=ini; }
    }
  }

  /* ─── Logout ─────────────────────────────────────────────── */
  function handleLogout(e) {
    if (e) e.preventDefault();
    const modal = document.getElementById('logoutModal');
    if (modal) openModal('logoutModal');
    else if (confirm('Log out?')) confirmLogout();
  }
  async function confirmLogout() {
    try { await hfFetch('api/auth.php?action=logout', 'POST'); } catch(_) {}
    window.location.href = 'guest_login.html';
  }

  /* ─── Header date ────────────────────────────────────────── */
  function initHeaderDate() {
    const str = new Date().toLocaleDateString('en-PH', {
      weekday:'short', year:'numeric', month:'short', day:'numeric'
    });
    ['headerDate','headerDateChip'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.textContent = str;
    });
  }

  /* ─── Mark active page ───────────────────────────────────── */
  function markActivePage() {
    const path = window.location.pathname.split('/').pop();
    document.querySelectorAll('.nav-item[data-page]').forEach(item => {
      item.classList.toggle('active', item.dataset.page === path);
    });
  }

  /* ─── Helpers ────────────────────────────────────────────── */
  const formatDate = d => {
    if (!d) return '—';
    return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', {
      year:'numeric', month:'short', day:'numeric'
    });
  };
  const formatTime = t => {
    if (!t) return '—';
    const [h,m] = t.split(':').map(Number);
    return `${h%12||12}:${String(m).padStart(2,'0')} ${h>=12?'PM':'AM'}`;
  };
  const formatCurrency = a =>
    new Intl.NumberFormat('en-PH', { style:'currency', currency:'PHP' }).format(a || 0);
  const escapeHtml = s => {
    const d = document.createElement('div'); d.textContent = String(s||''); return d.innerHTML;
  };
  const debounce = (fn, d=300) => {
    let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), d); };
  };
  const timeAgo = mins => {
    mins = parseInt(mins) || 0;
    if (mins < 1)  return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const h = Math.floor(mins/60);
    if (h < 24)    return `${h}h ago`;
    return `${Math.floor(h/24)}d ago`;
  };
  const exportCSV = (rows, name='export.csv') => {
    const csv = rows.map(r => r.map(v=>`"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const a = Object.assign(document.createElement('a'), {
      href: URL.createObjectURL(new Blob([csv], {type:'text/csv'})),
      download: name,
    });
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  };
  function loadingHTML() {
    return `<div style="display:flex;align-items:center;justify-content:center;padding:40px;color:var(--text-muted);gap:10px">
      <div style="width:18px;height:18px;border:2px solid var(--border-soft);border-top-color:var(--gold);border-radius:50%;animation:hfSpin 0.8s linear infinite"></div>
      Loading…</div>`;
  }
  function emptyState(icon, title, sub='') {
    return `<div class="empty-state">
      <span class="empty-state-icon">${icon}</span>
      <div class="empty-state-title">${title}</div>
      ${sub?`<div style="font-size:12px;color:var(--text-dim);margin-top:4px">${escapeHtml(sub)}</div>`:''}
    </div>`;
  }
  function startCountdown(elId, secs=60, cb) {
    const el=document.getElementById(elId); if(!el) return;
    let r=secs; el.textContent=`Resend in ${r}s`; el.disabled=true;
    const iv=setInterval(()=>{
      r--; el.textContent=`Resend in ${r}s`;
      if(r<=0){ clearInterval(iv); el.textContent='Resend'; el.disabled=false; cb&&cb(); }
    },1000);
  }

  /* ─── Init ───────────────────────────────────────────────── */
  function init() {
    applyTheme(state.theme, false);
    initSidebar();
    initHeaderDate();
    initToastContainer();
    markActivePage();
    initModalClose();
    document.querySelectorAll('.theme-toggle-btn').forEach(b => {
      b.textContent = state.theme === 'dark' ? '☀️' : '🌙';
      b.addEventListener('click', toggleTheme);
    });
  }
  document.addEventListener('DOMContentLoaded', init);

  return {
    state,
    applyTheme, toggleTheme,
    showToast, openModal, closeModal,
    initSidebar, toggleDesktopSidebar,
    handleLogout, confirmLogout, checkAuth,
    renderUserInSidebar, markActivePage,
    apiFetch: hfFetch,   // alias so older code works
    formatDate, formatTime, formatCurrency, escapeHtml, debounce,
    timeAgo, exportCSV, loadingHTML, emptyState, startCountdown,
  };
})();

/* ─── Global shorthands ──────────────────────────────────── */
const showToast    = HF.showToast;
const openModal    = HF.openModal;
const closeModal   = HF.closeModal;
const handleLogout = HF.handleLogout;
const confirmLogout= HF.confirmLogout;

/* ─── Spin animation ─────────────────────────────────────── */
const _hfStyle = document.createElement('style');
_hfStyle.textContent = '@keyframes hfSpin { to { transform: rotate(360deg); } }';
document.head.appendChild(_hfStyle);