<script setup lang="ts">
import type { ApiResponse } from '~/types'

/**
 * The homepage's one job: get an inquirer to the right listings fast.
 * Where · Buy or rent · Budget in one pill, submitting to Browse with the
 * filters set.
 *
 * Each field opens its own panel, and the bar behaves as one control:
 *  - the active field lifts out as a raised white segment while the rest of
 *    the pill dims, so it's always clear what you're editing;
 *  - Where suggests "Cities on RealtyLink" (live, from /properties/cities)
 *    and filters them as you type — arrow keys + Enter work;
 *  - Buy or rent is a three-option menu instead of a native select;
 *  - Budget stays typed (any figure) with one-tap presets that switch
 *    between sale and rent ranges;
 *  - choosing a value moves you on to the next field.
 * Esc or a click outside closes the panel. Nothing opens on its own.
 *
 * NOTE on inputs: @tailwindcss/forms is enabled globally and gives every
 * input a border, padding and focus ring, so each needs `border-0 p-0
 * focus:ring-0`.
 */
type Field = '' | 'where' | 'offer' | 'budget'
type Offer = '' | 'sale' | 'rent'
interface City { name: string; count: number }

/*
 * `to` is where a search lands. On the homepage that's Browse. On Browse
 * itself (or the dashboard's copy of it) it's the current page: the bar then
 * shows the search already in the URL, and submitting keeps the other filters
 * (type, beds, sort, map view) instead of wiping them.
 */
const props = withDefaults(defineProps<{ to?: string }>(), { to: '/properties' })

const router = useRouter()
const route = useRoute()
const api = useApi()
const onTarget = computed(() => route.path === props.to)

const formEl  = ref<HTMLFormElement | null>(null)
const whereEl = ref<HTMLInputElement | null>(null)
const budgetEl = ref<HTMLInputElement | null>(null)
const open = ref<Field>('')

const where = ref('')
const offer = ref<Offer>('')

/* Budget is typed, so a buyer can enter any figure. Stored as digits; shown
   with thousand separators as they type. */
const budgetDigits = ref('')
const budget = computed<string>({
  get: () => (budgetDigits.value ? Number(budgetDigits.value).toLocaleString('en-PH') : ''),
  set: (v: string) => { budgetDigits.value = v.replace(/\D/g, '').slice(0, 12) },
})

/* Mirror the URL's search, buy/rent and max price while on the target page,
   including when the Filters panel or a removed chip changes them. */
function syncFromRoute() {
  if (!onTarget.value) return
  const q = route.query
  where.value = typeof q.search === 'string' ? q.search : ''
  offer.value = q.offer_type === 'sale' || q.offer_type === 'rent' ? q.offer_type : ''
  budgetDigits.value = typeof q.max_price === 'string' ? q.max_price.replace(/\D/g, '') : ''
}
syncFromRoute()
watch(() => [route.query.search, route.query.offer_type, route.query.max_price], syncFromRoute)

/* ── Where: city suggestions ─────────────────────────────────────────── */
const cities = useState<City[] | null>('search-cities', () => null)
async function loadCities() {
  if (cities.value !== null) return
  cities.value = []
  try { cities.value = (await api.get<ApiResponse<City[]>>('/properties/cities')).data }
  catch { /* suggestions are a nicety — the field still works as free text */ }
}

const matches = computed(() => {
  const q = where.value.trim().toLowerCase()
  const list = cities.value ?? []
  return (q ? list.filter(c => c.name.toLowerCase().includes(q)) : list).slice(0, 6)
})
const activeIndex = ref(-1)
watch(where, () => { activeIndex.value = -1 })

function pickCity(name: string) {
  where.value = name
  open.value = 'offer'
}

function onWhereKey(e: KeyboardEvent) {
  if (open.value !== 'where') open.value = 'where'
  const n = matches.value.length
  if (e.key === 'ArrowDown' && n) { e.preventDefault(); activeIndex.value = (activeIndex.value + 1) % n }
  else if (e.key === 'ArrowUp' && n) { e.preventDefault(); activeIndex.value = (activeIndex.value - 1 + n) % n }
  else if (e.key === 'Enter' && activeIndex.value >= 0) { e.preventDefault(); pickCity(matches.value[activeIndex.value]!.name) }
}

/* ── Buy or rent ─────────────────────────────────────────────────────── */
const offers: Array<{ value: Offer; label: string; hint: string; icon: string }> = [
  { value: 'sale', label: 'Buy',    hint: 'Homes, condos and lots for sale', icon: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25' },
  { value: 'rent', label: 'Rent',   hint: 'Monthly rentals',                  icon: 'M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z' },
  { value: '',     label: 'Either', hint: 'Show me everything',               icon: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z' },
]
const offerLabel = computed(() => offers.find(o => o.value === offer.value && offer.value)?.label ?? '')

function pickOffer(v: Offer) {
  // Switching between buy and rent makes a typed budget meaningless (₱5M/mo).
  if (v !== offer.value) budgetDigits.value = ''
  offer.value = v
  open.value = 'budget'
  nextTick(() => budgetEl.value?.focus())
}

/* ── Budget presets ──────────────────────────────────────────────────── */
const presets = computed(() => offer.value === 'rent'
  ? [15_000, 25_000, 50_000, 100_000]
  : [3_000_000, 5_000_000, 10_000_000, 20_000_000, 50_000_000])
const budgetHint = computed(() => (offer.value === 'rent' ? '30,000' : '5,000,000'))

function short(n: number): string {
  return n >= 1_000_000 ? `₱${n / 1_000_000}M` : `₱${n / 1_000}K`
}
function pickBudget(n: number | null) {
  budgetDigits.value = n === null ? '' : String(n)
  open.value = ''
}

/* ── Open / close ────────────────────────────────────────────────────── */
function focusField(f: Field) {
  open.value = f
  if (f === 'where') { loadCities(); nextTick(() => whereEl.value?.focus()) }
  if (f === 'budget') nextTick(() => budgetEl.value?.focus())
}
function toggleOffer() { open.value = open.value === 'offer' ? '' : 'offer' }
/* Tabbing (or clicking) away from a text field closes its panel. Panel rows
   use mousedown.prevent, so picking from a panel never blurs the input. */
function closeIf(f: Field) { if (open.value === f) open.value = '' }

function onDocPointer(e: PointerEvent) {
  if (open.value && formEl.value && !formEl.value.contains(e.target as Node)) open.value = ''
}
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && open.value) open.value = '' }
onMounted(() => {
  document.addEventListener('pointerdown', onDocPointer)
  document.addEventListener('keydown', onKey)
})
onUnmounted(() => {
  document.removeEventListener('pointerdown', onDocPointer)
  document.removeEventListener('keydown', onKey)
})

function submit() {
  const query: Record<string, string> = {}
  if (onTarget.value) {
    for (const [k, v] of Object.entries(route.query)) if (typeof v === 'string') query[k] = v
  }
  delete query.search; delete query.offer_type; delete query.max_price
  if (where.value.trim()) query.search     = where.value.trim()
  if (offer.value)        query.offer_type = offer.value
  if (budgetDigits.value) query.max_price  = budgetDigits.value
  open.value = ''
  router.push({ path: props.to, query })
}

/* ── Styling ─────────────────────────────────────────────────────────── */
const labelText = 'block text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/70 dark:text-white/70 mb-0.5'
const field =
  'w-full border-0 bg-transparent p-0 text-[0.9375rem] leading-6 outline-none focus:ring-0 truncate ' +
  'text-brand-navy dark:text-white placeholder-brand-navy/45 dark:placeholder-white/45'

/* Pill segments from sm up; a stacked phone layout uses rounded cards so the
   ends don't clip the label and value. */
function cellClass(f: Field) {
  const active = open.value === f
  return [
    'relative flex flex-col justify-center text-left px-6 py-3 rounded-2xl sm:rounded-full transition-all duration-200',
    active
      ? 'bg-white dark:bg-brand-navy-mid shadow-[0_8px_28px_-8px_rgba(11,42,92,0.35)] ring-1 ring-brand-navy/5 dark:ring-white/10'
      : 'hover:bg-brand-navy/[0.05] dark:hover:bg-white/[0.06]',
  ]
}
const panel =
  'absolute z-40 top-full mt-3 left-0 rounded-3xl p-2.5 text-left ' +
  'bg-white dark:bg-brand-navy-mid ring-1 ring-brand-navy/[0.06] dark:ring-white/10 ' +
  'shadow-[0_24px_60px_-20px_rgba(11,42,92,0.45)] dark:shadow-[0_24px_60px_-16px_rgba(0,0,0,0.7)]'
const panelTitle = 'px-3 pt-1.5 pb-2 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/45'
</script>

<template>
  <form
    ref="formEl"
    class="relative mx-auto w-full max-w-3xl rounded-[1.75rem] sm:rounded-full p-1.5
           backdrop-blur-md ring-1 transition-colors duration-200
           shadow-[0_18px_44px_-18px_rgba(11,42,92,0.4)]
           flex flex-col sm:flex-row sm:items-stretch gap-1 sm:gap-0"
    :class="open
      ? 'bg-brand-navy/[0.06] ring-brand-navy/10 dark:bg-[#0d2550]/95 dark:ring-white/15'
      : 'bg-white/95 ring-brand-navy/10 dark:bg-white/[0.08] dark:ring-white/15'"
    role="search"
    @submit.prevent="submit"
  >
    <!-- Where -->
    <div :class="[cellClass('where'), 'sm:flex-[1.35] min-w-0 cursor-text']" @click="focusField('where')">
      <label for="rl-where" :class="labelText">Where</label>
      <input
        id="rl-where"
        ref="whereEl"
        v-model="where"
        type="text"
        autocomplete="off"
        placeholder="City or barangay"
        :class="field"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="open === 'where' && matches.length > 0"
        aria-controls="rl-where-list"
        :aria-activedescendant="activeIndex >= 0 ? `rl-city-${activeIndex}` : undefined"
        @focus="focusField('where')"
        @blur="closeIf('where')"
        @keydown="onWhereKey"
      />

      <Transition name="panel">
        <div v-if="open === 'where' && matches.length" :class="[panel, 'w-full sm:w-[22rem]']" @click.stop>
          <p :class="panelTitle">Cities on RealtyLink</p>
          <ul id="rl-where-list" role="listbox" aria-label="Cities on RealtyLink">
            <li
              v-for="(c, i) in matches"
              :id="`rl-city-${i}`"
              :key="c.name"
              role="option"
              :aria-selected="i === activeIndex"
              class="flex items-center gap-3 rounded-2xl px-3 py-2.5 cursor-pointer transition-colors"
              :class="i === activeIndex ? 'bg-brand-gold/15' : 'hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.06]'"
              @mousedown.prevent
              @mouseenter="activeIndex = i"
              @click="pickCity(c.name)"
            >
              <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                </svg>
              </span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ c.name }}</span>
                <span class="block text-xs text-brand-text-secondary dark:text-white/50">
                  {{ c.count }} {{ c.count === 1 ? 'listing' : 'listings' }}
                </span>
              </span>
            </li>
          </ul>
        </div>
      </Transition>
    </div>

    <span class="hidden sm:block w-px my-3.5 bg-brand-navy/10 dark:bg-white/15 transition-opacity" :class="open ? 'opacity-0' : ''" aria-hidden="true" />

    <!-- Buy or rent -->
    <div :class="[cellClass('offer'), 'sm:flex-1 min-w-0']">
      <button
        type="button"
        class="text-left w-full"
        aria-haspopup="listbox"
        :aria-expanded="open === 'offer'"
        @click="toggleOffer"
      >
        <span :class="labelText">Buy or rent</span>
        <span class="flex items-center justify-between gap-2 text-[0.9375rem] leading-6">
          <span :class="offerLabel ? 'text-brand-navy dark:text-white' : 'text-brand-navy/45 dark:text-white/45'">
            {{ offerLabel || 'Sale or rent' }}
          </span>
          <svg class="h-4 w-4 flex-none text-brand-navy/45 dark:text-white/50 transition-transform duration-200" :class="open === 'offer' ? 'rotate-180' : ''"
               fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </span>
      </button>

      <Transition name="panel">
        <div v-if="open === 'offer'" :class="[panel, 'w-full sm:w-72']" @click.stop>
          <ul role="listbox" aria-label="Buy or rent">
            <li v-for="o in offers" :key="o.label" role="option" :aria-selected="offer === o.value">
              <button
                type="button"
                class="w-full flex items-center gap-3 rounded-2xl px-3 py-2.5 text-left transition-colors"
                :class="offer === o.value ? 'bg-brand-gold/15' : 'hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.06]'"
                @click="pickOffer(o.value)"
              >
                <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-brand-navy/[0.05] dark:bg-white/[0.06] text-brand-navy dark:text-white">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="o.icon" />
                  </svg>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold text-brand-navy dark:text-white">{{ o.label }}</span>
                  <span class="block text-xs text-brand-text-secondary dark:text-white/50">{{ o.hint }}</span>
                </span>
                <svg v-if="offer === o.value" class="h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
              </button>
            </li>
          </ul>
        </div>
      </Transition>
    </div>

    <span class="hidden sm:block w-px my-3.5 bg-brand-navy/10 dark:bg-white/15 transition-opacity" :class="open ? 'opacity-0' : ''" aria-hidden="true" />

    <!-- Budget — typed, so any figure is allowed -->
    <div :class="[cellClass('budget'), 'sm:flex-1 min-w-0 cursor-text sm:pr-2']" @click="focusField('budget')">
      <label for="rl-budget" :class="labelText">Max budget</label>
      <span class="flex items-baseline gap-1">
        <span class="text-[0.9375rem] leading-6 text-brand-navy/45 dark:text-white/45 select-none">₱</span>
        <input
          id="rl-budget"
          ref="budgetEl"
          v-model="budget"
          type="text"
          inputmode="numeric"
          autocomplete="off"
          :placeholder="budgetHint"
          :class="field"
          @focus="open = 'budget'"
          @blur="closeIf('budget')"
        />
      </span>

      <!-- mousedown.prevent keeps focus in the input, so its blur doesn't
           close the panel before a preset's click lands. -->
      <Transition name="panel">
        <div v-if="open === 'budget'" :class="[panel, 'w-full sm:w-[19rem] sm:left-auto sm:right-0']" @click.stop @mousedown.prevent>
          <p :class="panelTitle">{{ offer === 'rent' ? 'Monthly rent up to' : 'Price up to' }}</p>
          <div class="flex flex-wrap gap-2 px-2 pb-2">
            <button
              v-for="n in presets"
              :key="n"
              type="button"
              class="rounded-full px-3.5 py-1.5 text-sm font-semibold ring-1 transition-colors"
              :class="budgetDigits === String(n)
                ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-brand-navy dark:ring-brand-gold'
                : 'text-brand-navy/80 ring-brand-navy/15 hover:ring-brand-gold dark:text-white/80 dark:ring-white/15'"
              @click="pickBudget(n)"
            >
              {{ short(n) }}{{ offer === 'rent' ? '/mo' : '' }}
            </button>
            <button
              type="button"
              class="rounded-full px-3.5 py-1.5 text-sm font-semibold text-brand-text-secondary dark:text-white/55 hover:text-brand-navy dark:hover:text-white"
              @click="pickBudget(null)"
            >
              Any price
            </button>
          </div>
        </div>
      </Transition>
    </div>

    <!-- Go. A round icon at rest; widens to show its label while you're
         filling the form in. Always full-width with a label on a phone. -->
    <div class="flex items-center justify-center sm:pl-1">
      <button
        type="submit"
        class="group w-full h-12 sm:h-[3.25rem] rounded-full bg-brand-gold text-brand-navy font-bold text-sm
               flex items-center justify-center gap-2 overflow-hidden
               shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:shadow-[0_8px_26px_rgba(212,165,74,0.6)]
               transition-all duration-300"
        :class="open ? 'sm:w-[7.5rem]' : 'sm:w-[3.25rem]'"
        aria-label="Search listings"
      >
        <svg class="h-5 w-5 flex-none transition-transform group-hover:scale-110" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        <span class="sm:hidden">Search</span>
        <span
          class="hidden sm:inline-block overflow-hidden whitespace-nowrap transition-[max-width,opacity] duration-300"
          :class="open ? 'max-w-[5rem] opacity-100' : 'max-w-0 opacity-0'"
        >Search</span>
      </button>
    </div>
  </form>
</template>

<style scoped>
.panel-enter-active, .panel-leave-active { transition: opacity 0.16s ease, transform 0.18s cubic-bezier(0.22, 1, 0.36, 1); }
.panel-enter-from, .panel-leave-to { opacity: 0; transform: translateY(-6px) scale(0.98); }
@media (prefers-reduced-motion: reduce) {
  .panel-enter-active, .panel-leave-active { transition: none; }
}
</style>
