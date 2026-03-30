/* Admin Portal — Sidebar Builder v2.0 */
function buildAdminSidebar(activePage) {
  const pages = [
    { id:'admin_dashboard.html',   icon:'📊', label:'Dashboard',   section:'main' },
    { id:'admin_requests.html',    icon:'📥', label:'Requests',    section:'main', badge:'reqBadge' },
    { id:'admin_contractors.html', icon:'🔧', label:'Contractors', section:'main' },
    { id:'admin_clients.html',     icon:'👥', label:'Clients',     section:'main' },
    { id:'admin_assignments.html', icon:'📌', label:'Assignments', section:'main' },
    { id:'admin_messages.html',    icon:'💬', label:'Messages',    section:'main', badge:'msgBadge' },
    { id:'admin_reports.html',     icon:'📈', label:'Reports',     section:'main' },
    { id:'admin_settings.html',    icon:'⚙️', label:'Settings',    section:'account' },
  ];

  const navHTML = () => {
    const main = pages.filter(p => p.section === 'main');
    const acc  = pages.filter(p => p.section === 'account');
    const item = p => `
      <a href="${p.id}" class="nav-item${p.id === activePage ? ' active' : ''}" data-page="${p.id}">
        <span class="nav-icon">${p.icon}</span>
        <span class="sidebar-label">${p.label}</span>
        ${p.badge ? `<span class="nav-badge" id="${p.badge}" style="display:none">0</span>` : ''}
        <span class="nav-tooltip">${p.label}</span>
      </a>`;
    return `
      <div class="nav-section-label">Management</div>
      ${main.map(item).join('')}
      <div class="nav-section-label">Account</div>
      ${acc.map(item).join('')}
      <div class="divider"></div>
      <button class="nav-item nav-logout" onclick="handleLogout(event)">
        <span class="nav-icon">🚪</span>
        <span class="sidebar-label">Log Out</span>
        <span class="nav-tooltip">Log Out</span>
      </button>`;
  };

  const html = `
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <div class="sidebar-logo">
          <div class="logo-mark">HF</div>
          <div class="sidebar-logo-text"><strong>HandyFix</strong><small>Admin Portal</small></div>
        </div>
        <button class="toggle-btn" id="sidebarToggle" title="Toggle sidebar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M15 18l-6-6 6-6"/>
          </svg>
        </button>
      </div>
      <div class="sidebar-user">
        <div class="user-avatar-wrap">
          <img class="user-avatar" id="sidebarAvatar" src="" alt="" style="display:none"/>
          <div class="user-avatar-fallback" id="sidebarAvatarFallback">AD</div>
          <span class="status-dot"></span>
        </div>
        <div class="user-info">
          <div class="user-name" id="sidebarUserName">Admin</div>
          <div class="user-role" id="sidebarUserRole">Administrator</div>
        </div>
      </div>
      <nav class="sidebar-nav">${navHTML()}</nav>
      <div class="sidebar-footer">
        <div class="sidebar-footer-text">HandyFix Admin v2.0 © 2026</div>
      </div>
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

  // Load pending requests badge
  loadBadgeCounts();
}

async function loadBadgeCounts() {
  try {
    const d = await HF.apiFetch('api/dashboard.php?action=stats');

    const reqBadge = document.getElementById('reqBadge');
    if (reqBadge && d.pending_requests > 0) {
      reqBadge.textContent = d.pending_requests;
      reqBadge.style.display = 'flex';
    }

    const msgBadge = document.getElementById('msgBadge');
    if (msgBadge) {
      try {
        const convos = await HF.apiFetch('api/messages.php?action=conversations');
        const unread = convos.reduce((s, c) => s + (parseInt(c.unread) || 0), 0);
        if (unread > 0) {
          msgBadge.textContent = unread;
          msgBadge.style.display = 'flex';
        }
      } catch(_) {}
    }
  } catch(_) {}
}