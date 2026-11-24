(() => {
  const button = document.getElementById('historyRefreshButton');
  if (!button) return;
  button.addEventListener('click', () => {
    button.disabled = true;
    button.innerHTML = '<span class="refresh-icon">↻</span> Refreshing…';
    window.location.reload();
  });
})();
