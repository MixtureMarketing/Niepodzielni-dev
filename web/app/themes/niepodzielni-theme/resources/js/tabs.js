document.addEventListener('click', function (e) {
  if (!e.target.classList.contains('psy-tab-btn')) return;

  e.preventDefault();
  const targetId = e.target.getAttribute('data-target');

  document.querySelectorAll('.psy-tab-content').forEach(c => c.classList.remove('is-active'));
  document.querySelectorAll('.psy-tab-btn').forEach(b => b.classList.remove('is-active'));

  e.target.classList.add('is-active');
  document.getElementById(targetId)?.classList.add('is-active');
});
