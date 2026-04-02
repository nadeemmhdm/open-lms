document.addEventListener('DOMContentLoaded', function () {
    const fadeElements = document.querySelectorAll('.fade-in');
    fadeElements.forEach(el => {
        el.style.opacity = '1';
    });

    // Mobile Sidebar Toggle
    const toggle = document.getElementById('menuToggle');
    const closeBtn = document.getElementById('closeSidebar');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (toggle && sidebar && overlay) {
        const openSidebar = () => {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        };

        const closeSidebar = () => {
            sidebar.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        };

        toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            openSidebar();
        });

        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        if (overlay) overlay.addEventListener('click', closeSidebar);

        // Responsive Visibility
        const checkMobile = () => {
            if (window.innerWidth > 1100) {
                closeSidebar();
            }
        };

        checkMobile();
        window.addEventListener('resize', checkMobile);
    }

    // Confirm Deletes
    const deleteBtn = document.querySelectorAll('.btn-danger, .btn-delete');
    deleteBtn.forEach(btn => {
        btn.addEventListener('click', (e) => {
            // Only confirm if it's literally a delete link
            if (btn.getAttribute('href') && btn.getAttribute('href').includes('action=delete')) {
                if (!confirm('Are you sure you want to permanently delete this item?')) {
                    e.preventDefault();
                }
            }
        });
    });
});
