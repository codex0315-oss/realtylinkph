/**
 * `v-reveal` — reveals an element once it scrolls into view.
 *
 *   <div v-reveal />                        fade + rise
 *   <div v-reveal="{ delay: 120 }" />       staggered
 *   <div v-reveal="{ variant: 'left' }" />  slide in from the left
 *
 * Variants: 'up' (default) | 'left' | 'right' | 'zoom' | 'blur'
 *
 * This plugin is deliberately UNIVERSAL, not client-only: Vue's SSR renderer
 * looks up every directive it encounters and calls `getSSRProps`, so a
 * client-only registration crashes server rendering.
 *
 * Content is never hidden during SSR. Elements already inside the viewport when
 * they mount are left visible, so above-the-fold copy never flashes and nothing
 * stays invisible if JS fails to run — only off-screen elements animate in.
 */

interface RevealOptions {
  delay?: number
  variant?: 'up' | 'left' | 'right' | 'zoom' | 'blur'
  /** Replay each time it re-enters the viewport (default: reveal once). */
  once?: boolean
}

function readOptions(value: unknown): RevealOptions {
  return typeof value === 'object' && value !== null ? (value as RevealOptions) : {}
}

export default defineNuxtPlugin((nuxtApp) => {
  // On the server there is nothing to observe — register a no-op that still
  // satisfies the SSR renderer's directive lookup.
  if (import.meta.server) {
    nuxtApp.vueApp.directive('reveal', { getSSRProps: () => ({}) })
    return
  }

  const registry = new WeakMap<Element, RevealOptions>()

  const observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        const opts = registry.get(entry.target) ?? {}
        if (entry.isIntersecting) {
          entry.target.classList.add('reveal-in')
          if (opts.once !== false) observer.unobserve(entry.target)
        } else if (opts.once === false) {
          entry.target.classList.remove('reveal-in')
        }
      }
    },
    { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
  )

  nuxtApp.vueApp.directive('reveal', {
    getSSRProps: () => ({}),

    mounted(el: HTMLElement, binding) {
      const opts = readOptions(binding.value)

      // Already on screen (or motion is unwanted) → leave it alone.
      const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
      const rect = el.getBoundingClientRect()
      const alreadyVisible = rect.top < window.innerHeight && rect.bottom > 0

      if (reduced || alreadyVisible) return

      el.classList.add('reveal-init')
      if (opts.variant && opts.variant !== 'up') el.classList.add(`reveal-${opts.variant}`)
      if (opts.delay) el.style.setProperty('--reveal-delay', `${opts.delay}ms`)

      registry.set(el, opts)
      observer.observe(el)
    },

    unmounted(el: HTMLElement) {
      observer.unobserve(el)
      registry.delete(el)
    },
  })
})
