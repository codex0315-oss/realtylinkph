<script setup lang="ts">
import type { AgentReview } from '~/types'

/**
 * The agent's Reviews page, in the same shape as the buyer's:
 *   heading with a one-line summary
 *   → tabs All / Needs a reply / Replied / 3★ & below
 *   → summary numbers, then tabs, search and sort
 *   → each review, full width, with a public reply the agent can write,
 *     edit or remove.
 */
const authStore = useAuthStore()
const toast = useToast()
const ask = useConfirm()
const { reviews, averageRating, loading, fetchAgentReviews, replyToReview, error } = useReview()

if (authStore.user) await fetchAgentReviews(authStore.user.id)

/* ── Summary + filter ── */
type Tab = 'all' | 'todo' | 'replied' | 'low'
const tab = ref<Tab>('all')
const counts = computed(() => ({
  all:     reviews.value.length,
  todo:    reviews.value.filter(r => !r.agent_reply).length,
  replied: reviews.value.filter(r => !!r.agent_reply).length,
  low:     reviews.value.filter(r => r.rating <= 3).length,
}))
const TABS: Array<{ key: Tab; label: string }> = [
  { key: 'all', label: 'All' }, { key: 'todo', label: 'Needs a reply' }, { key: 'replied', label: 'Replied' }, { key: 'low', label: '3 ★ & below' },
]
const search = ref('')
const sort = ref<'newest' | 'highest' | 'lowest'>('newest')
const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  const list = reviews.value.filter(r =>
    (tab.value === 'all' || (tab.value === 'todo' ? !r.agent_reply : tab.value === 'replied' ? !!r.agent_reply : r.rating <= 3))
    && (!q || `${r.buyer?.name ?? ''} ${r.review_text ?? ''} ${r.property_title ?? ''}`.toLowerCase().includes(q)))
  const by = {
    newest:  (a: AgentReview, b: AgentReview) => +new Date(b.created_at) - +new Date(a.created_at),
    highest: (a: AgentReview, b: AgentReview) => b.rating - a.rating || +new Date(b.created_at) - +new Date(a.created_at),
    lowest:  (a: AgentReview, b: AgentReview) => a.rating - b.rating || +new Date(b.created_at) - +new Date(a.created_at),
  }[sort.value]
  return [...list].sort(by)
})
/** Received in the last 7 days. */
const isNew = (r: AgentReview) => Date.now() - +new Date(r.created_at) < 7 * 86_400_000

/* ── Summary numbers ── */
const verifiedCount = computed(() => reviews.value.filter(r => r.is_verified).length)
const stats = computed(() => [
  { label: 'Average rating', value: reviews.value.length ? `${averageRating.value.toFixed(1)} ★` : '–', hint: reviews.value.length ? `${counts.value.all} ${counts.value.all === 1 ? 'review' : 'reviews'} in total` : 'No reviews yet' },
  { label: 'Waiting for a reply', value: counts.value.todo, hint: counts.value.todo ? 'Reply to show you care' : 'All answered', accent: counts.value.todo > 0, onSelect: () => { tab.value = 'todo' } },
  { label: 'Reply rate', value: `${replyRate.value}%`, hint: `${counts.value.replied} of ${counts.value.all} replied` },
  { label: 'Verified viewings', value: verifiedCount.value, hint: 'Reviews from a real viewing' },
])
const replyRate = computed(() => (reviews.value.length ? Math.round((counts.value.replied / reviews.value.length) * 100) : 0))

/* ── Reply editor (one open at a time) ── */
const editing = ref<number | null>(null)
const draft = ref('')
const saving = ref(false)
function firstName(r: AgentReview) { return r.buyer?.name?.split(' ')[0] ?? 'there' }
function starters(r: AgentReview) {
  const n = firstName(r)
  return r.rating >= 4
    ? [`Thank you, ${n}! It was a pleasure showing you around.`, `Thanks for the kind words, ${n}. Happy to help any time.`]
    : [`Thank you for the honest feedback, ${n}. I’m sorry it fell short.`, `Thanks, ${n}. I’ll work on this for the next viewing.`]
}
function openEditor(r: AgentReview) { editing.value = r.id; draft.value = r.agent_reply ?? '' }
function cancel() { editing.value = null; draft.value = '' }

/* A reply RealtyLink AI drafted: open that review's editor with it, unsent. */
const handed = useState<{ id: number; text: string } | null>('review-reply-draft', () => null)
watch([handed, reviews], () => {
  const h = handed.value
  const r = h && reviews.value.find(x => x.id === h.id)
  if (!h || !r) return
  handed.value = null
  tab.value = 'all'
  search.value = ''
  editing.value = r.id
  draft.value = h.text
  nextTick(() => document.getElementById(`review-${r.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}, { immediate: true })
async function save(r: AgentReview) {
  if (draft.value.trim().length < 2 || saving.value) return
  saving.value = true
  const first = !r.agent_reply
  const ok = await replyToReview(r.id, draft.value.trim())
  saving.value = false
  if (ok) { toast.success(first ? `Reply posted. ${firstName(r)} has been notified` : 'Reply updated'); cancel() }
  else toast.error(error.value || 'Couldn’t save your reply. Please try again.')
}
async function remove(r: AgentReview) {
  const sure = await ask({
    title: 'Remove your reply?',
    message: 'It disappears from your public profile. The review itself stays.',
    confirmLabel: 'Remove reply',
    tone: 'danger',
  })
  if (!sure) return
  if (await replyToReview(r.id, null)) toast.success('Reply removed')
  else toast.error('Couldn’t remove it. Please try again.')
}

/* ── Review text: "👍 tag · tag" line → chips, the rest → note ── */
function parts(r: AgentReview) {
  const lines = (r.review_text ?? '').split('\n')
  const hasTags = (lines[0] ?? '').startsWith('👍')
  return {
    tags: hasTags ? lines[0]!.slice(2).split('·').map(t => t.trim()).filter(Boolean) : [],
    note: (hasTags ? lines.slice(1) : lines).join('\n').trim(),
  }
}
function calm(t?: string | null): string {
  if (!t) return ''
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const date = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : '')
const STAR = 'M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z'
</script>

<template>
  <div>
    <!-- ── Heading ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Your reputation</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Reviews</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <template v-if="reviews.length">
            <span class="font-semibold text-brand-navy dark:text-white">{{ averageRating.toFixed(1) }} ★</span> from
            <span class="font-semibold text-brand-navy dark:text-white">{{ reviews.length }}</span> {{ reviews.length === 1 ? 'review' : 'reviews' }}<template v-if="counts.todo"> · <span class="font-semibold text-brand-gold-deep dark:text-brand-gold">{{ counts.todo }}</span> waiting for your reply</template>.
          </template>
          <template v-else>What buyers say after a viewing or a chat with you, and your replies.</template>
        </p>
      </div>
      <DashCta v-if="authStore.isVerifiedAgent && authStore.user" :to="`/agents/${authStore.user.id}`">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
        See public profile
      </DashCta>
    </header>

    <!-- ── Summary numbers ── -->
    <DashStatStrip v-if="reviews.length" :stats="stats" class="mb-6" />

    <div>
      <div class="min-w-0">
        <!-- ── Tabs + search + sort ── -->
        <div v-if="reviews.length" class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-center">
          <div class="-mx-1 px-1 flex overflow-x-auto no-scrollbar">
          <div class="inline-flex items-center gap-1 rounded-full p-1 bg-white dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10" role="tablist">
            <button
              v-for="t in TABS"
              :key="t.key"
              type="button"
              role="tab"
              :aria-selected="tab === t.key"
              :disabled="t.key !== 'all' && !counts[t.key]"
              class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-semibold transition disabled:opacity-40"
              :class="tab === t.key ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-brand-navy' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'"
              @click="tab = t.key"
            >
              {{ t.label }}
              <span class="text-xs tabular-nums" :class="tab === t.key ? 'opacity-75' : t.key === 'todo' && counts.todo ? 'rounded-full bg-brand-gold/20 px-1.5 text-brand-gold-deep dark:text-brand-gold' : 'opacity-50'">{{ counts[t.key] }}</span>
            </button>
          </div>
          </div>
          <div class="flex flex-1 gap-2 lg:justify-end">
            <div class="relative flex-1 lg:max-w-xs">
              <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
              <input v-model="search" type="search" placeholder="Search reviews" aria-label="Search reviews" class="w-full rounded-full border-0 bg-white dark:bg-white/[0.06] pl-10 pr-4 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold" />
            </div>
            <select v-model="sort" aria-label="Sort reviews" class="rounded-full border-0 bg-white dark:bg-white/[0.06] pl-4 pr-9 py-2.5 text-sm font-semibold text-brand-navy dark:text-white ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold dark:[color-scheme:dark]">
              <option value="newest">Newest</option>
              <option value="highest">Highest rated</option>
              <option value="lowest">Lowest rated</option>
            </select>
          </div>
        </div>

        <!-- ── Reviews ── -->
        <div v-if="loading && !reviews.length" class="space-y-3">
          <AppSkeleton v-for="i in 3" :key="i" width="100%" height="160px" rounded="lg" />
        </div>
        <ul v-else-if="shown.length" class="space-y-4">
          <li
            v-for="r in shown"
            :id="`review-${r.id}`"
            :key="r.id"
            class="relative overflow-hidden scroll-mt-24 rounded-3xl p-5 bg-white dark:bg-[#0B1A35] border-2 transition duration-300 hover:-translate-y-0.5 hover:shadow-[0_22px_44px_-26px_rgba(11,42,92,0.4)]"
            :class="editing === r.id ? 'border-brand-gold' : 'border-brand-navy/80 dark:border-brand-gold/70'"
          >
            <!-- Waiting for a reply: a gold edge down the left -->
            <span v-if="!r.agent_reply" class="absolute inset-y-0 left-0 w-1 bg-brand-gold" aria-hidden="true" />
            <!-- Buyer + stars -->
            <div class="flex items-start gap-3">
              <AppAvatar :name="r.buyer?.name" :src="r.buyer?.avatar" size="md" class="flex-none" />
              <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 font-semibold text-brand-navy dark:text-white">
                  <span class="truncate">{{ r.buyer?.name ?? 'Buyer' }}</span>
                  <span v-if="isNew(r)" class="flex-none rounded-full bg-brand-gold px-2 py-0.5 text-[0.5625rem] font-bold uppercase tracking-wide text-[#0B2A5C]">New</span>
                </p>
                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span class="inline-flex gap-0.5" :aria-label="`${r.rating} out of 5 stars`">
                    <svg v-for="n in 5" :key="n" class="h-4 w-4" :class="n <= r.rating ? 'text-brand-gold' : 'text-brand-navy/15 dark:text-white/15'" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
                  </span>
                  <span class="text-xs text-brand-navy/50 dark:text-white/45">{{ date(r.created_at) }}<template v-if="r.is_edited"> · edited</template></span>
                </div>
              </div>
              <span v-if="r.is_verified" class="flex-none inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                Verified viewing
              </span>
              <span v-else class="flex-none rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold bg-brand-navy/[0.05] text-brand-navy/55 dark:bg-white/[0.06] dark:text-white/50">{{ r.appointment_id ? 'Cancelled viewing' : 'Via chat' }}</span>
            </div>

            <!-- What it was about -->
            <p v-if="r.property_title" class="mt-3 flex items-center gap-1.5 text-xs text-brand-navy/55 dark:text-white/50">
              <svg class="h-3.5 w-3.5 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" /></svg>
              <span class="truncate">{{ calm(r.property_title) }}<template v-if="r.viewed_at"> · viewed {{ date(r.viewed_at) }}</template></span>
            </p>

            <!-- Tags + note -->
            <div v-if="parts(r).tags.length" class="mt-3 flex flex-wrap gap-1.5">
              <span v-for="t in parts(r).tags" :key="t" class="rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">{{ t }}</span>
            </div>
            <p v-if="parts(r).note" class="mt-3 text-sm leading-relaxed text-brand-navy/80 dark:text-white/75 whitespace-pre-wrap">“{{ parts(r).note }}”</p>

            <!-- ── Reply ── -->
            <div v-if="editing === r.id" class="mt-4 rounded-2xl bg-brand-cream/80 dark:bg-white/[0.04] p-4 ring-1 ring-brand-navy/[0.06] dark:ring-white/[0.08]">
              <p class="text-xs font-bold text-brand-navy dark:text-white">{{ r.agent_reply ? 'Edit your reply' : `Reply to ${firstName(r)}` }}</p>
              <div v-if="!draft" class="mt-2 flex flex-wrap gap-1.5">
                <button
                  v-for="s in starters(r)"
                  :key="s"
                  type="button"
                  class="rounded-full px-3 py-1 text-xs text-brand-navy bg-white ring-1 ring-brand-navy/10 hover:ring-brand-gold dark:bg-white/[0.06] dark:text-white dark:ring-white/15 transition"
                  @click="draft = s"
                >{{ s }}</button>
              </div>
              <textarea
                v-model="draft"
                rows="3"
                maxlength="1000"
                :placeholder="`Write a public reply to ${firstName(r)}…`"
                aria-label="Your reply"
                class="mt-2 w-full resize-none rounded-2xl border-0 bg-white dark:bg-white/[0.06] px-4 py-3 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
                @keydown.ctrl.enter.prevent="save(r)"
              />
              <div class="mt-2 flex items-center gap-2">
                <span class="text-[0.6875rem] text-brand-navy/45 dark:text-white/40">Public on your profile · {{ draft.length }}/1000</span>
                <span class="flex-1" />
                <button type="button" class="rounded-full px-4 py-1.5 text-xs font-semibold text-brand-navy/70 hover:text-brand-navy dark:text-white/60 dark:hover:text-white" @click="cancel">Cancel</button>
                <button
                  type="button"
                  class="rounded-full bg-brand-gold px-4 py-1.5 text-xs font-bold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-50 disabled:shadow-none transition"
                  :disabled="draft.trim().length < 2 || saving"
                  @click="save(r)"
                >{{ saving ? 'Saving…' : r.agent_reply ? 'Save reply' : 'Post reply' }}</button>
              </div>
            </div>

            <div v-else-if="r.agent_reply" class="mt-4 flex gap-3 rounded-2xl bg-brand-navy/[0.03] dark:bg-white/[0.04] p-4 border-l-[3px] border-brand-gold">
              <AppAvatar :name="authStore.user?.name" :src="authStore.user?.avatar" size="xs" class="flex-none" />
              <div class="min-w-0 flex-1">
                <p class="text-xs font-bold text-brand-navy dark:text-white">Your reply <span class="font-normal text-brand-navy/45 dark:text-white/40">· {{ date(r.agent_replied_at) }}</span></p>
                <p class="mt-1 text-sm leading-relaxed text-brand-navy/80 dark:text-white/75 whitespace-pre-wrap">{{ r.agent_reply }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold text-brand-navy bg-white ring-1 ring-brand-navy/15 hover:ring-brand-gold hover:-translate-y-px dark:bg-white/[0.06] dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition"
                    @click="openEditor(r)"
                  >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                    Edit reply
                  </button>
                  <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-bold text-red-600 bg-white ring-1 ring-red-200 hover:bg-red-50 hover:ring-red-300 dark:bg-white/[0.06] dark:text-red-400 dark:ring-red-400/30 dark:hover:bg-red-500/10 transition"
                    @click="remove(r)"
                  >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                    Remove
                  </button>
                </div>
              </div>
            </div>

            <div v-else class="mt-4 flex items-center justify-between gap-3 border-t border-brand-navy/[0.06] dark:border-white/10 pt-4">
              <span class="text-xs text-brand-navy/50 dark:text-white/45">No reply yet. Buyers read replies before they book.</span>
              <button
                type="button"
                class="flex-none inline-flex items-center gap-1.5 rounded-full bg-brand-navy px-4 py-1.5 text-xs font-bold text-white hover:-translate-y-px dark:bg-brand-gold dark:text-[#0B2A5C] transition"
                @click="openEditor(r)"
              >
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" /></svg>
                Reply
              </button>
            </div>
          </li>
        </ul>

        <!-- ── Empty ── -->
        <div v-else-if="!reviews.length" class="relative overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] px-6 py-16 text-center">
          <div class="absolute -top-16 left-1/2 -translate-x-1/2 h-48 w-80 rounded-full bg-brand-gold/10 blur-3xl pointer-events-none" />
          <span class="relative mx-auto mb-5 h-16 w-16 rounded-2xl bg-brand-gold/15 flex items-center justify-center text-brand-gold-deep dark:text-brand-gold">
            <svg class="h-8 w-8" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path :d="STAR" /></svg>
          </span>
          <p class="relative font-display text-xl font-bold text-brand-navy dark:text-white">No reviews yet</p>
          <p class="relative mx-auto mt-2 max-w-sm text-sm text-brand-text-secondary dark:text-white/55 leading-relaxed">
            Buyers can rate you after a viewing or a chat. Their reviews, and your replies, show up here and on your profile.
          </p>
        </div>
        <p v-else class="rounded-3xl border border-dashed border-brand-navy/15 dark:border-white/15 px-6 py-12 text-center text-sm text-brand-navy/55 dark:text-white/50">
          Nothing here. <button type="button" class="font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="tab = 'all'">Show all reviews</button>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }
</style>
