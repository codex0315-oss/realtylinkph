/**
 * Restores the saved session token before any other client plugin runs.
 *
 * Nuxt runs plugins in filename order, and all of them before app.vue's setup.
 * The token used to be restored in app.vue, which meant echo.client.ts built
 * its WebSocket auth header while the token was still null — literally
 * "Bearer null", frozen for the life of the page — so every private-channel
 * subscription was refused and nothing arrived in real time. The "00." prefix
 * puts this ahead of echo/heartbeat/notifications.
 */
export default defineNuxtPlugin(() => {
  useAuthStore().initFromStorage()
})
