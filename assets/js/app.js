(() => {
  const root = document.documentElement;
  const saved = localStorage.getItem('open-lms-theme');
  const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  root.dataset.theme = saved || (systemDark ? 'dark' : 'light');
  document.querySelectorAll('[data-theme-toggle]').forEach((button) => button.addEventListener('click', () => {
    const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.dataset.theme = next;
    localStorage.setItem('open-lms-theme', next);
  }));
  document.querySelector('[data-menu-toggle]')?.addEventListener('click', () => document.body.classList.toggle('menu-open'));
  const driver = document.getElementById('db-driver');
  const mysql = document.getElementById('mysql-fields');
  if (driver && mysql) { const sync = () => mysql.hidden = driver.value !== 'mysql'; driver.addEventListener('change', sync); sync(); }
})();
