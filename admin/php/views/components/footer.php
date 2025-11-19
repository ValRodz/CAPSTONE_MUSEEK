        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const content = document.querySelector('.admin-content');
    const isMobile = window.innerWidth <= 992;

    if (isMobile) {
        sidebar.classList.toggle('open');
        document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
    } else {
        sidebar.classList.toggle('closed');
        content.style.marginLeft = sidebar.classList.contains('closed') ? '60px' : '240px';
    }
}

let hoverTimeout;
const sidebar = document.getElementById('sidebar');
const content = document.querySelector('.admin-content');

if (window.innerWidth > 992) {
    sidebar.addEventListener('mouseenter', () => {
        clearTimeout(hoverTimeout);
        sidebar.classList.remove('closed');
        content.style.marginLeft = '240px';
    });
    sidebar.addEventListener('mouseleave', () => {
        hoverTimeout = setTimeout(() => {
            if (!sidebar.matches(':hover')) {
                sidebar.classList.add('closed');
                content.style.marginLeft = '60px';
            }
        }, 600);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.innerWidth > 992) {
        sidebar.classList.remove('closed');
        content.style.marginLeft = '240px';
    }
    const saved = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
});

function toggleDarkMode() {
    const html = document.documentElement;
    const current = html.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
}
</script>
</body>
</html>