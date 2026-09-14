// Keeps the signed-in user marked "online" by pinging /heartbeat periodically.
export default defineNuxtPlugin(() => {
  const authStore = useAuthStore()
  const api       = useApi()

  function ping() {
    if (authStore.token) api.post('/heartbeat').catch(() => {})
  }

  // Ping as soon as we're authenticated, then every 45s, and whenever the tab
  // regains focus.
  watch(() => authStore.token, (t) => { if (t) ping() }, { immediate: true })
  setInterval(ping, 45_000)
  document.addEventListener('visibilitychange', () => { if (!document.hidden) ping() })
})
