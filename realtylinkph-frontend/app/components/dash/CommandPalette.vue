<script setup lang="ts">
import type { Conversation, PaginatedResponse, Property } from '~/types'

/**
 * Ctrl K / ⌘ K search for the dashboard: one box that finds
 *  - pages (the sidebar's links, plus a few actions),
 *  - your chats (by person, property or what was said),
 *  - listings (live from /properties, debounced).
 * ↑ ↓ to move, Enter to go, Esc to close. Opened from the top bar or the
 * shortcut (the layout owns the shortcut; this owns the dialog).
 */
interface PageLink { label: string; href: string; icon: string }
const props = defineProps<{ pages: PageLink[] }>()
const open = defineModel<boolean>('open', { default: false })

const authStore = useAuthStore()
const convStore = useConversationStore()
const api = useApi()
const propertyHref = usePropertyHref()

const q = ref('')
const inputEl = ref<HTMLInputElement | null>(null)
const active = ref(0)

/* ── Listings (debounced) ────────────────────────────────────────────── */
const listings = ref<Property[]>([])
const searching = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined
let seq = 0
watch(q, (val) => {
  active.value = 0
  clearTimeout(timer)
  const term = val.trim()
  if (term.length < 2) { listings.value = []; searching.value = false; return }
  searching.value = true
  timer = setTimeout(async () => {
    const mine = ++seq
    try {
      const res = await api.get<PaginatedResponse<Property>>('/properties', { search: term })
      if (mine === seq) listings.value = res.data.slice(0, 5)
    } catch { if (mine === seq) listings.value = [] }
    finally { if (mine === seq) searching.value = false }
  }, 250)
})

/* ── Results ─────────────────────────────────────────────────────────── */
interface Item { key: string; group: string; label: string; sub?: string; icon?: string; img?: string | null; avatar?: { name?: string; src?: string | null }; to: string }

function other(c: Conversation) { return authStore.user?.id === c.buyer_id ? c.agent : c.buyer }
function peso(p: Property) {
  const n = Number(p.price)
  const s = n >= 1_000_000 ? `₱${+(n / 1_000_000).toFixed(1)}M` : n >= 1_000 ? `₱${Math.round(n / 1_000)}K` : `₱${n}`
  return p.offer_type === 'rent' ? `${s}/mo` : s
}

const items = computed<Item[]>(() => {
  const term = q.value.trim().toLowerCase()
  const match = (...s: Array<string | undefined | null>) => !term || s.some(x => (x ?? '').toLowerCase().includes(term))

  const pages: Item[] = props.pages
    .filter(p => match(p.label))
    .slice(0, term ? 6 : 8)
    .map(p => ({ key: `p:${p.href}`, group: 'Pages', label: p.label, icon: p.icon, to: p.href }))

  const chats: Item[] = authStore.isAdmin ? [] : convStore.conversations
    .filter(c => term && match(other(c)?.name, c.property?.title, c.latest_message?.body))
    .slice(0, 4)
    .map(c => ({
      key: `c:${c.id}`, group: 'Chats', label: other(c)?.name ?? 'Chat',
      sub: c.property?.title ?? c.latest_message?.body, avatar: { name: other(c)?.name, src: other(c)?.avatar },
      to: `/dashboard/messages?c=${c.id}`,
    }))

  const homes: Item[] = listings.value.map(p => ({
    key: `l:${p.id}`, group: 'Listings', label: p.title, sub: `${peso(p)} · ${p.address}`,
    img: p.photos?.find(ph => !ph.is_360)?.thumb_url ?? p.photos?.[0]?.thumb_url ?? null,
    to: propertyHref(p.id),
  }))

  // Searching for a place? Offer Browse with the term as the last row.
  const browse: Item[] = term.length >= 2 && !authStore.isAdmin
    ? [{ key: 'b', group: 'Listings', label: `Search all listings for “${q.value.trim()}”`, icon: 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z', to: `/dashboard/browse?search=${encodeURIComponent(q.value.trim())}` }]
    : []

  return [...chats, ...homes, ...browse, ...pages]
})
const groups = computed(() => {
  const order = ['Chats', 'Listings', 'Pages']
  return order
    .map(g => ({ g, list: items.value.filter(i => i.group === g) }))
    .filter(x => x.list.length)
})
const flat = computed(() => groups.value.flatMap(x => x.list))
const indexOf = (it: Item) => flat.value.indexOf(it)

/* ── Open / close / go ───────────────────────────────────────────────── */
watch(open, (on) => {
  if (!on) return
  q.value = ''
  active.value = 0
  nextTick(() => inputEl.value?.focus())
})
async function go(it: Item | undefined) {
  if (!it) return
  open.value = false
  await navigateTo(it.to)
}
function onKey(e: KeyboardEvent) {
  const n = flat.value.length
  if (e.key === 'ArrowDown') { e.preventDefault(); active.value = n ? (active.value + 1) % n : 0; scrollActive() }
  else if (e.key === 'ArrowUp') { e.preventDefault(); active.value = n ? (active.value - 1 + n) % n : 0; scrollActive() }
  else if (e.key === 'Enter') { e.preventDefault(); go(flat.value[active.value]) }
  else if (e.key === 'Escape') { e.preventDefault(); open.value = false }
}
const listEl = ref<HTMLElement | null>(null)
function scrollActive() {
  nextTick(() => listEl.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' }))
}
</script>

<template>
  <Teleport to="body">
    <Transition name="cmdk">
      <div v-if="open" class="fixed inset-0 z-[96] flex items-start justify-center p-3 sm:p-6 sm:pt-[12vh] bg-brand-navy-deep/50 backdrop-blur-sm" @click.self="open = false">
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Search"
          class="cmdk-panel w-full max-w-xl overflow-hidden rounded-3xl bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 shadow-[0_40px_100px_-30px_rgba(6,24,58,0.65)]"
        >
          <div class="flex items-center gap-3 px-5 border-b border-brand-navy/[0.06] dark:border-white/10">
            <svg class="h-5 w-5 flex-none text-brand-navy/45 dark:text-white/45" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
            <input
              ref="inputEl"
              v-model="q"
              type="text"
              role="combobox"
              aria-expanded="true"
              aria-controls="cmdk-list"
              :aria-activedescendant="flat[active] ? `cmdk-${flat[active]!.key}` : undefined"
              placeholder="Search listings, chats and pages…"
              class="w-full border-0 bg-transparent py-4 text-base text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/40 focus:ring-0"
              @keydown="onKey"
            />
            <span v-if="searching" class="h-4 w-4 flex-none rounded-full border-2 border-brand-gold/40 border-t-brand-gold animate-spin" aria-hidden="true" />
            <kbd class="hidden sm:inline-flex flex-none rounded-md px-1.5 py-0.5 text-[0.625rem] font-semibold text-brand-navy/50 ring-1 ring-brand-navy/15 dark:text-white/50 dark:ring-white/15">Esc</kbd>
          </div>

          <div id="cmdk-list" ref="listEl" role="listbox" class="max-h-[60vh] overflow-y-auto overscroll-contain p-2">
            <p v-if="!flat.length && !searching" class="px-4 py-10 text-center text-sm text-brand-navy/50 dark:text-white/50">
              Nothing found for “{{ q }}”.
            </p>
            <section v-for="grp in groups" :key="grp.g" class="mb-1">
              <p class="px-3 pt-2 pb-1 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/40 dark:text-white/40">{{ grp.g }}</p>
              <button
                v-for="it in grp.list"
                :id="`cmdk-${it.key}`"
                :key="it.key"
                type="button"
                role="option"
                :aria-selected="indexOf(it) === active"
                :data-active="indexOf(it) === active"
                class="w-full flex items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition-colors"
                :class="indexOf(it) === active ? 'bg-brand-gold/15' : 'hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05]'"
                @mouseenter="active = indexOf(it)"
                @click="go(it)"
              >
                <AppAvatar v-if="it.avatar" :name="it.avatar.name" :src="it.avatar.src" size="sm" />
                <img v-else-if="it.img" :src="it.img" alt="" class="h-9 w-12 flex-none rounded-lg object-cover" />
                <span v-else class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-brand-navy/[0.05] dark:bg-white/[0.07] text-brand-navy/70 dark:text-white/70">
                  <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="it.icon" /></svg>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ it.label }}</span>
                  <span v-if="it.sub" class="block truncate text-xs text-brand-navy/50 dark:text-white/50">{{ it.sub }}</span>
                </span>
                <svg v-if="indexOf(it) === active" class="h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
              </button>
            </section>
          </div>

          <div class="hidden sm:flex items-center gap-4 px-5 py-2.5 border-t border-brand-navy/[0.06] dark:border-white/10 text-[0.6875rem] text-brand-navy/45 dark:text-white/40">
            <span><kbd class="kbd">↑</kbd> <kbd class="kbd">↓</kbd> to move</span>
            <span><kbd class="kbd">Enter</kbd> to open</span>
            <span class="ml-auto"><kbd class="kbd">Ctrl</kbd> <kbd class="kbd">K</kbd> anytime</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.kbd { @apply inline-flex rounded px-1 py-px font-semibold ring-1 ring-brand-navy/15 dark:ring-white/15; }

.cmdk-enter-active, .cmdk-leave-active { transition: opacity 0.18s ease; }
.cmdk-enter-from, .cmdk-leave-to { opacity: 0; }
.cmdk-enter-active .cmdk-panel { transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1); }
.cmdk-enter-from .cmdk-panel { transform: translateY(-10px) scale(0.98); }
@media (prefers-reduced-motion: reduce) {
  .cmdk-enter-active, .cmdk-leave-active, .cmdk-enter-active .cmdk-panel { transition: none; }
}
</style>
