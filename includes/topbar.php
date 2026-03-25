<!-- Top Navigation Bar -->
<div class="topbar">
  <button class="btn-toggle-sidebar" onclick="toggleSidebar()" data-testid="button-toggle-sidebar">
    <i class="bi bi-list"></i>
  </button>

  <h4 class="page-title"><?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></h4>

  <div class="search-box">
    <i class="bi bi-search"></i>
    <input type="search" placeholder="Search jobs, customers..." data-testid="input-search">
  </div>

  <div class="topbar-actions">
    <div class="dropdown">
      <button class="btn-icon" data-bs-toggle="dropdown" data-testid="button-notifications">
        <i class="bi bi-bell"></i>
        <span class="notification-dot"></span>
      </button>
      <div class="dropdown-menu dropdown-menu-end p-0" style="width: 320px;">
        <div class="p-3 border-bottom">
          <h6 class="mb-0 fw-semibold" style="font-size: 14px;">Notifications</h6>
        </div>
        <div class="p-2">
          <a href="#" class="dropdown-item rounded-2 p-2 mb-1">
            <div class="d-flex align-items-start gap-2">
              <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                   style="width: 32px; height: 32px; background: var(--success-bg); color: var(--success); font-size: 14px;">
                <i class="bi bi-check-circle-fill"></i>
              </div>
              <div>
                <div style="font-size: 13px; font-weight: 500;">Job completed</div>
                <div style="font-size: 12px; color: var(--text-muted);">Business cards ready for pickup</div>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">5 min ago</div>
              </div>
            </div>
          </a>
          <a href="#" class="dropdown-item rounded-2 p-2 mb-1">
            <div class="d-flex align-items-start gap-2">
              <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                   style="width: 32px; height: 32px; background: var(--primary-bg); color: var(--primary); font-size: 14px;">
                <i class="bi bi-bag-plus-fill"></i>
              </div>
              <div>
                <div style="font-size: 13px; font-weight: 500;">New job created</div>
                <div style="font-size: 12px; color: var(--text-muted);">A4 Brochure job added</div>
                <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">20 min ago</div>
              </div>
            </div>
          </a>
        </div>
      </div>
    </div>

    <button class="btn-icon" data-testid="button-settings">
      <i class="bi bi-gear"></i>
    </button>

    <div class="dropdown">
      <button class="user-btn" data-bs-toggle="dropdown" data-testid="button-user-menu">
        <div class="avatar-sm">SP</div>
        <span class="d-none d-sm-inline">Admin</span>
        <i class="bi bi-chevron-down" style="font-size: 12px; color: var(--text-muted);"></i>
      </button>
      <div class="dropdown-menu dropdown-menu-end">
        <a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Profile</a>
        
        <div class="dropdown-divider"></div>
        <a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a>
      </div>
    </div>
  </div>
</div>
