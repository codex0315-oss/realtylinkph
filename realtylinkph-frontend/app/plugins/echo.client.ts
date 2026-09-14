import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}

export default defineNuxtPlugin(() => {
  const config    = useRuntimeConfig()
  const authStore = useAuthStore()

  window.Pusher = Pusher

  // Laravel registers the broadcasting auth route at the host root
  // (/broadcasting/auth), NOT under the /api prefix — strip /api so we don't 404.
  const authEndpoint = `${(config.public.apiBase as string).replace(/\/api\/?$/, '')}/broadcasting/auth`

  const echo = new Echo({
    broadcaster:       'reverb',
    key:               config.public.reverbKey as string,
    wsHost:            config.public.reverbHost as string,
    wsPort:            Number(config.public.reverbPort),
    wssPort:           Number(config.public.reverbPort),
    forceTLS:          false,
    enabledTransports: ['ws', 'wss'],

    /*
     * Custom authorizer so the bearer token is read at the moment a channel is
     * subscribed, not once at construction. The static `auth.headers` form
     * captured whatever the token was when this plugin ran — null on a fresh
     * page load — and kept using it forever, so private channels never
     * authorised. This also means logging in or out without a reload just works.
     */
    authorizer: (channel: { name: string }) => ({
      authorize: (
        socketId: string,
        callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void,
      ) => {
        $fetch<{ auth: string; channel_data?: string }>(authEndpoint, {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${authStore.token ?? ''}`,
            Accept: 'application/json',
          },
          body: { socket_id: socketId, channel_name: channel.name },
        })
          .then(data => callback(null, data))
          .catch(err => {
            if (import.meta.dev) console.warn(`[echo] auth failed for ${channel.name}:`, err?.status ?? '', err?.message ?? err)
            callback(err instanceof Error ? err : new Error(String(err)), null)
          })
      },
    }),
  })

  /*
   * Dev-only visibility. Real-time failures are otherwise silent — a refused
   * channel or a dropped socket just means "nothing arrives". With this, the
   * browser console says exactly which step broke.
   */
  if (import.meta.dev) {
    const conn = echo.connector.pusher.connection
    conn.bind('state_change', (s: { previous: string; current: string }) =>
      console.info(`[echo] ${s.previous} → ${s.current}`))
    conn.bind('error', (e: unknown) => console.warn('[echo] connection error', e))
    // Raw frames carry the channel name; the per-channel emitters don't.
    conn.bind('message', (m: { event?: string; channel?: string; data?: unknown }) => {
      if (m.event === 'pusher_internal:subscription_succeeded') console.info(`[echo] subscribed ${m.channel}`)
      if (m.event === 'pusher:subscription_error')              console.warn(`[echo] subscription refused ${m.channel}`, m.data)
    })
  }

  return {
    provide: { echo },
  }
})
