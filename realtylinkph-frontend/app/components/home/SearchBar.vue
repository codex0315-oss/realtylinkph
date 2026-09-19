<script setup lang="ts">
/**
 * The homepage's one job: get an inquirer to the right listings fast.
 * Where · Buy or rent · Budget, in a single pill — the real-estate version of
 * Airbnb's Where / When / Who. Submits to Browse Listings with the filters set.
 *
 * NOTE on `field`: @tailwindcss/forms is enabled globally and gives every input
 * and select a 1px border, its own padding, AND (for selects) a background-image
 * chevron. So each control needs `border-0 p-0 focus:ring-0`, and selects also
 * need `appearance-none bg-none` — `appearance-none` alone drops the OS arrow
 * but leaves the plugin's, which rendered as a double chevron.
 */
const router = useRouter()

const where = ref('')
const offer = ref<'' | 'sale' | 'rent'>('')

/* Budget is typed, not picked from a list, so a buyer can enter any figure.
   Stored as digits; shown with thousand separators as they type. */
const budgetDigits = ref('')
const budget = computed<string>({
  get: () => (budgetDigits.value ? Number(budgetDigits.value).toLocaleString('en-PH') : ''),
  set: (v: string) => { budgetDigits.value = v.replace(/\D/g, '').slice(0, 12) },
})
const budgetHint = computed(() => (offer.value === 'rent' ? '30,000' : '5,000,000'))

const field =
  'w-full border-0 bg-transparent p-0 text-[0.9375rem] leading-6 outline-none focus:ring-0 ' +
  'text-brand-navy dark:text-white'
const labelText = 'text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/70 dark:text-white/70 mb-0.5'
// Pill only once the cells sit in a row (sm+). Stacked on a phone, a
// rounded-full cell is a lozenge whose ends clip the label and value.
const cell =
  'relative flex flex-col justify-center px-6 py-3.5 rounded-2xl sm:rounded-full transition-colors ' +
  'hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.06] ' +
  'focus-within:bg-brand-navy/[0.04] dark:focus-within:bg-white/[0.06]'

function submit() {
  const query: Record<string, string> = {}
  if (where.value.trim()) query.search     = where.value.trim()
  if (offer.value)        query.offer_type = offer.value
  if (budgetDigits.value) query.max_price  = budgetDigits.value
  router.push({ path: '/properties', query })
}
</script>

<template>
  <!-- rounded-full is a pill when the cells are in a row, but on a phone the
       cells stack and the same radius turns the whole form into an oval that
       clips the top and bottom fields. Rounded card below sm, pill from sm. -->
  <form
    class="relative mx-auto w-full max-w-3xl rounded-[1.75rem] sm:rounded-full p-1.5
           bg-white/95 dark:bg-white/[0.08] backdrop-blur-md
           border border-white/70 dark:border-white/15 ring-1 ring-brand-navy/10 dark:ring-white/5
           shadow-[0_16px_40px_-16px_rgba(8,21,47,0.35)]
           flex flex-col sm:flex-row sm:items-stretch gap-1 sm:gap-0
           sm:pr-1"
    @submit.prevent="submit"
  >
    <!-- Where -->
    <label :class="[cell, 'flex-[1.4] min-w-0 cursor-text']">
      <span :class="labelText">Where</span>
      <input
        v-model="where"
        type="text"
        placeholder="City or barangay"
        :class="field"
        class="placeholder-brand-navy/45 dark:placeholder-white/45"
      />
    </label>

    <span class="hidden sm:block w-px my-3.5 bg-brand-navy/10 dark:bg-white/15" aria-hidden="true" />

    <!-- Buy / Rent -->
    <label :class="[cell, 'flex-1 min-w-0 cursor-pointer']">
      <span :class="labelText">Buy or rent</span>
      <select
        v-model="offer"
        :class="field"
        class="appearance-none bg-none pr-5 cursor-pointer dark:[color-scheme:dark]"
      >
        <option value="">Either</option>
        <option value="sale">Buy</option>
        <option value="rent">Rent</option>
      </select>
      <svg class="pointer-events-none absolute right-5 bottom-[1.05rem] h-4 w-4 text-brand-navy/45 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
      </svg>
    </label>

    <span class="hidden sm:block w-px my-3.5 bg-brand-navy/10 dark:bg-white/15" aria-hidden="true" />

    <!-- Budget — typed, so any figure is allowed -->
    <label :class="[cell, 'flex-1 min-w-0 cursor-text']">
      <span :class="labelText">Max budget</span>
      <span class="flex items-baseline gap-1">
        <span class="text-[0.9375rem] leading-6 text-brand-navy/45 dark:text-white/45 select-none">₱</span>
        <input
          v-model="budget"
          type="text"
          inputmode="numeric"
          autocomplete="off"
          :placeholder="budgetHint"
          :class="field"
          class="placeholder-brand-navy/45 dark:placeholder-white/45"
        />
      </span>
    </label>

    <!-- Go. `sm:ml-auto` pins it to the right end of the pill and absorbs any
         slack left by the fields, so it always sits flush at the edge. -->
    <div class="flex items-center justify-center sm:ml-auto sm:pl-2">
      <button
        type="submit"
        class="w-full sm:w-auto h-[3.25rem] px-7 rounded-full bg-brand-gold text-brand-navy font-bold text-sm
               flex items-center justify-center gap-2 shadow-[0_4px_14px_rgba(212,175,55,0.35)]
               hover:bg-brand-gold-light hover:-translate-y-0.5 transition-all"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
        </svg>
        Search
      </button>
    </div>
  </form>
</template>
