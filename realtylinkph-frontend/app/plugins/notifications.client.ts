/**
 * Loads persisted notifications and subscribes to the user's real-time channel.
 * Runs after echo.client.ts (alphabetical order) so $echo is available.
 *
 * Keyed on the user's *id*, not on "has a token". The channel name is
 * notifications.{id}, and the id only exists once /me has returned — which is
 * after the token is restored. Watching the token meant the subscribe ran with
 * no user yet, bailed out, and never got another chance, so the bell only
 * updated on a refresh.
 */
export default defineNuxtPlugin(() => {
  const authStore = useAuthStore()
  const { fetchNotifications, clear } = useNotification()
  const { listenForNotifications, stopNotifications } = useEcho()

  watch(
    () => authStore.user?.id,
    (id, prevId) => {
      if (prevId) stopNotifications(prevId)
      if (id) {
        fetchNotifications()
        listenForNotifications(() => fetchNotifications())
      } else {
        clear()
      }
    },
    { immediate: true },
  )
})
