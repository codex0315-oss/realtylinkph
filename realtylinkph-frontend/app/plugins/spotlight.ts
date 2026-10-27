/**
 * `v-spotlight` — tracks the pointer over an element as CSS variables
 * (--mx / --my) for the `.spotlight` glow in main.css. Adds the class itself.
 *
 * Universal for the same reason as v-reveal: the SSR renderer looks every
 * directive up, so the server gets a no-op that only adds the class.
 * Mouse/pen only — a touch "hover" would just leave a stuck glow.
 */
export default defineNuxtPlugin((nuxtApp) => {
  if (import.meta.server) {
    nuxtApp.vueApp.directive('spotlight', { getSSRProps: () => ({ class: 'spotlight' }) })
    return
  }

  const handlers = new WeakMap<HTMLElement, (e: PointerEvent) => void>()

  nuxtApp.vueApp.directive<HTMLElement>('spotlight', {
    mounted(el) {
      el.classList.add('spotlight')
      const onMove = (e: PointerEvent) => {
        if (e.pointerType === 'touch') return
        const r = el.getBoundingClientRect()
        el.style.setProperty('--mx', `${e.clientX - r.left}px`)
        el.style.setProperty('--my', `${e.clientY - r.top}px`)
      }
      handlers.set(el, onMove)
      el.addEventListener('pointermove', onMove, { passive: true })
    },
    unmounted(el) {
      const onMove = handlers.get(el)
      if (onMove) el.removeEventListener('pointermove', onMove)
    },
  })
})
