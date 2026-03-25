<!-- Footer -->
<footer class="app-footer" data-testid="footer">
  <span>&copy; <?php echo date('Y'); ?> Sri Plasst </span>
  <div class="d-flex align-items-center gap-3">
    <a href="#">Support</a>
    <a href="#">Documentation</a>
    <span>v1.0.0</span>
  </div>
</footer>

<!-- Sidebar Toggle Script -->
<script>
  function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('show');
    document.getElementById('sidebarOverlay').classList.toggle('show');
  }

  function toggleCompactSidebar() {
    var sidebar = document.getElementById('sidebar');
    sidebar.classList.toggle('compact');
    var isCompact = sidebar.classList.contains('compact');
    localStorage.setItem('sidebarCompact', isCompact ? '1' : '0');
    var icon = document.getElementById('compactIcon');
    if (icon) {
      icon.className = isCompact ? 'bi bi-layout-sidebar' : 'bi bi-layout-sidebar-inset';
    }
  }

  (function() {
    if (localStorage.getItem('sidebarCompact') === '1') {
      var sidebar = document.getElementById('sidebar');
      if (sidebar) {
        sidebar.classList.add('compact');
        var icon = document.getElementById('compactIcon');
        if (icon) {
          icon.className = 'bi bi-layout-sidebar';
        }
      }
    }
  })();
</script>
