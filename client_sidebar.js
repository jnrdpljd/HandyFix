/* client_sidebar.js — injects sidebar + logout modal, loads badge counts */
function buildClientSidebar(activePage) {
  const pages = [
    { id:'client_home.html',     icon:'🏠', label:'Home',          section:'main' },
    { id:'client_services.html', icon:'🛠️', label:'Services',      section:'main' },
    { id:'client_bookings.html', icon:'📋', label:'My Bookings',   section:'main', badge:'bookingsBadge' },
    { id:'client_support.html',  icon:'💬', label:'Help & Support', section:'main' },
    { id:'client_profile.html',  icon:'👤', label:'Profile',        section:'account' },
    { id:'client_settings.html', icon:'⚙️', label:'Settings',       section:'account' },
  ];

  const item = p => `
    <a href="${p.id}" class="nav-item${p.id===activePage?' active':''}" data-page="${p.id}">
      <span class="nav-icon">${p.icon}</span>
      <span class="sidebar-label">${p.label}</span>
      ${p.badge?`<span class="nav-badge" id="${p.badge}" style="display:none">0</span>`:''}
      <span class="nav-tooltip">${p.label}</span>
    </a>`;

  const main = pages.filter(p=>p.section==='main');
  const acc  = pages.filter(p=>p.section==='account');

  const html = `
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">
          <div class="logo-mark">HF</div>
          <div class="sidebar-logo-text"><strong>HandyFix</strong><small>Client Portal</small></div>
        </div>
        <button class="toggle-btn" id="sidebarToggle" title="Toggle">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
      </div>
      <div class="sidebar-user">
        <div class="user-avatar-wrap">
          <img class="user-avatar" id="sidebarAvatar" src="" alt="" style="display:none"/>
          <div class="user-avatar-fallback" id="sidebarAvatarFallback">CL</div>
          <span class="status-dot"></span>
        </div>
        <div class="user-info">
          <div class="user-name" id="sidebarUserName">Loading…</div>
          <div class="user-role" id="sidebarUserRole">Client</div>
        </div>
      </div>
      <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        ${main.map(item).join('')}
        <div class="nav-section-label">Account</div>
        ${acc.map(item).join('')}
        <div class="divider"></div>
        <button class="nav-item nav-logout" onclick="handleLogout(event)">
          <span class="nav-icon">🚪</span>
          <span class="sidebar-label">Log Out</span>
          <span class="nav-tooltip">Log Out</span>
        </button>
      </nav>
      <div class="sidebar-footer"><div class="sidebar-footer-text">HandyFix v3.0 © 2026</div></div>
    </aside>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="modal-overlay" id="logoutModal">
      <div class="modal" style="max-width:360px;text-align:center">
        <div style="font-size:44px;margin-bottom:12px">🚪</div>
        <div class="modal-title" style="text-align:center;margin-bottom:8px">Log Out?</div>
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:22px">You will be returned to the login page.</p>
        <div style="display:flex;gap:10px;justify-content:center">
          <button class="btn btn-secondary" onclick="closeModal('logoutModal')">Stay</button>
          <button class="btn btn-maroon" onclick="confirmLogout()">Yes, Log Out</button>
        </div>
      </div>
    </div>`;

  document.body.insertAdjacentHTML('afterbegin', html);

  // Auth check + badge counts after sidebar is in DOM
  HF.checkAuth('client').then(user => {
    if (!user) return;
    loadBadgeCounts();
  });
}

async function loadBadgeCounts() {
  try {
    const bookings = await hfFetch('api/client.php?action=stats');
    const pending  = (bookings.pending || 0) + (bookings.active || 0);
    const badge    = document.getElementById('bookingsBadge');
    if (badge && pending > 0) {
      badge.textContent   = pending;
      badge.style.display = 'flex';
    }
  } catch(_) {}
}