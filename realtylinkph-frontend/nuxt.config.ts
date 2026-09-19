// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  modules: [
    '@pinia/nuxt',
    '@vueuse/nuxt',
    '@nuxt/image',
  ],

  // Auth lives in the browser (localStorage). Render the authed areas
  // client-side so the server never redirects before the session loads
  // (fixes "refresh on dashboard kicks me to the landing page").
  routeRules: {
    '/dashboard/**': { ssr: false },
    '/admin/**':     { ssr: false },
    // Public pages are rendered on the server, which means the Vercel function
    // (US East) has to call the API (Oregon) before it can send a single byte
    // — measured at 2.3–2.9 s TTFB for the home page, and a 504 whenever the
    // API was asleep. These pages look identical to every visitor at render
    // time (the session lives in localStorage, so SSR never sees it; the
    // per-user bits hydrate on the client), so let the edge keep a copy and
    // refresh it in the background: visitors get the cached HTML instantly
    // and a slow or sleeping API can only delay the refresh, never the page.
    //
    // `isr`, not `swr`: on the Vercel preset Nitro maps `swr` to ISR with
    // *no* expiration (cached until the next deploy). `isr: N` is
    // stale-while-revalidate with an N-second freshness window, keyed by the
    // full URL, so each Browse filter combination gets its own copy.
    //
    // Listing detail pages are deliberately left out: the API counts a view
    // on every render, and a cached page would stop the agent's "views"
    // analytics from moving.
    '/':               { isr: 120 },
    '/properties':     { isr: 60 },
    '/agents':         { isr: 300 },
    '/agents/**':      { isr: 120 },
    // Proxy locally-served uploads so they're same-origin — the WebGL 360°
    // viewer can't use a cross-origin texture. Only applies when the backend
    // serves files itself; with UPLOAD_DISK=s3 the URLs point at the bucket
    // and CORS on the bucket is what allows the texture (see DEPLOYMENT.md).
    '/storage/**':   { proxy: `${process.env.NUXT_STORAGE_PROXY || 'http://127.0.0.1:8000'}/storage/**` },
    // Emergency detour. On 2026-09-19 Render's edge stopped accepting HTTPS
    // from a Philippine ISP (TCP to 216.24.57.16/.18:443 timed out) while
    // Vercel's servers in the US reached the API fine. If that happens during
    // the demo, set NUXT_PUBLIC_API_BASE=https://realtylinkph.vercel.app/api
    // on Vercel and redeploy: browsers then talk only to Vercel, which
    // forwards to Render. Costs ~100 ms per call and Vercel functions cap the
    // request body at 4.5 MB (large photo uploads fail), so it is not the
    // default — these routes just sit unused until apiBase points at them.
    // `/broadcasting` is included because echo.client.ts derives the Pusher
    // auth URL from apiBase by stripping `/api`.
    '/api/**':          { proxy: `${process.env.NUXT_API_PROXY_TARGET || 'https://realtylinkph-api.onrender.com'}/api/**` },
    '/broadcasting/**': { proxy: `${process.env.NUXT_API_PROXY_TARGET || 'https://realtylinkph-api.onrender.com'}/broadcasting/**` },
  },

  app: {
    head: {
      // Fallback title. Without one the tab shows the bare hostname; pages that
      // call useHead({ title }) still override this.
      title: 'RealtyLink PH — Verified real estate in the Philippines',
      link: [
        // Warm up the font origins before the stylesheet is even parsed.
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sora:wght@400;500;600;700;800&display=swap',
        },
        // Brand icons — the R monogram cropped out of the full logo, since the
        // wordmark is unreadable at 16px. `sizes: 'any'` stops Chrome from
        // preferring a PNG over the multi-resolution .ico.
        { rel: 'icon', href: '/favicon.ico', sizes: 'any' },
        { rel: 'icon', type: 'image/png', sizes: '192x192', href: '/icon-192.png' },
        { rel: 'icon', type: 'image/png', sizes: '512x512', href: '/icon-512.png' },
        { rel: 'apple-touch-icon', sizes: '180x180', href: '/apple-touch-icon.png' },
      ],
      script: [
        {
          // Apply the saved theme BEFORE first paint so dark mode never flashes
          // light on load. `theme.client.ts` takes over once Vue hydrates.
          innerHTML:
            "(function(){try{var t=localStorage.getItem('rl-theme');" +
            // Light is the product default. Anything missing or unrecognised
            // falls back to light rather than inheriting the OS preference.
            "if(t!=='dark'&&t!=='light'&&t!=='system'){t='light';}" +
            "var d=t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);" +
            "document.documentElement.classList.toggle('dark',d);}catch(e){}})();",
          tagPosition: 'head',
        },
      ],
    },
  },

  runtimeConfig: {
    public: {
      apiBase:    process.env.NUXT_PUBLIC_API_BASE    || 'http://127.0.0.1:8000/api',
      appUrl:     process.env.NUXT_PUBLIC_APP_URL     || 'http://localhost:3000',
      geoapifyKey: process.env.NUXT_PUBLIC_GEOAPIFY_KEY || '',
      reverbHost: process.env.NUXT_PUBLIC_REVERB_HOST || '127.0.0.1',
      reverbPort: process.env.NUXT_PUBLIC_REVERB_PORT || '8080',
      reverbKey:  process.env.NUXT_PUBLIC_REVERB_KEY  || '',
      // Setting both of these switches real-time onto hosted Pusher instead of
      // a self-hosted Reverb; leave them unset for local development.
      pusherKey:     process.env.NUXT_PUBLIC_PUSHER_KEY     || '',
      pusherCluster: process.env.NUXT_PUBLIC_PUSHER_CLUSTER || '',
    },
  },

  typescript: {
    strict: true,
  },

  css: ['~/assets/css/main.css'],

  postcss: {
    plugins: {
      tailwindcss: {},
      autoprefixer: {},
    },
  },

  image: {
    provider: 'none',
  },

  // Auto-import Pinia stores (path relative to srcDir which is 'app/')
  pinia: {
    storesDirs: ['stores/**'],
  },
})
