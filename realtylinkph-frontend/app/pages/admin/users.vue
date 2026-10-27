<script setup lang="ts">
import type { User } from '~/types'
import type { UserQuickFilter, UserSort } from '~/composables/useAdmin'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const { users, pagination, loading, error, stats, fetchStats, fetchUser, fetchUsers, deleteUser } = useAdmin()
const ask   = useConfirm()
const toast = useToast()
const route = useRoute()

const roleFilter = ref('')      // '' = all
const search     = ref('')
const sort       = ref<UserSort>('newest')
const only       = ref<UserQuickFilter>('')
const page       = ref(1)
const busy       = ref<number | null>(null)
const selected   = ref<Set<number>>(new Set())

async function load() {
  await fetchUsers({ page: page.value, role: roleFilter.value || undefined, search: search.value || undefined, sort: sort.value, only: only.value })
  // A selection only ever refers to rows on screen; once the rows change it
  // would be a hidden list of ids the admin can no longer see or verify.
  selected.value = new Set()
}
await Promise.all([load(), fetchStats()])

/* The four tiles are the role filter: click one to show only those. */
const statCards = computed(() => [
  { key: '',      label: 'All users', value: stats.value?.total  ?? 0, dot: 'bg-brand-gold' },
  { key: 'buyer', label: 'Buyers',    value: stats.value?.buyers ?? 0, dot: 'bg-blue-500' },
  { key: 'agent', label: 'Agents',    value: stats.value?.agents ?? 0, dot: 'bg-emerald-500' },
  { key: 'admin', label: 'Admins',    value: stats.value?.admins ?? 0, dot: 'bg-brand-navy dark:bg-white' },
])

function setFilter(k: string) { roleFilter.value = k; page.value = 1; load() }
function goPage(p: number)    { page.value = p; load() }

const SORTS: Array<{ value: UserSort; label: string }> = [
  { value: 'newest', label: 'Newest' },
  { value: 'oldest', label: 'Oldest' },
  { value: 'name',   label: 'Name A–Z' },
  { value: 'active', label: 'Last active' },
]
watch(sort, () => { page.value = 1; load() })

/* Quick filters: one at a time, tap again to turn off. */
const CHIPS: Array<{ value: Exclude<UserQuickFilter, ''>; label: string; dot: string }> = [
  { value: 'online',     label: 'Online now',          dot: 'bg-emerald-500' },
  { value: 'unverified', label: 'Email not verified',  dot: 'bg-amber-500' },
  { value: 'applied',    label: 'Applied to be agent', dot: 'bg-brand-gold' },
]
function setOnly(v: Exclude<UserQuickFilter, ''>) { only.value = only.value === v ? '' : v; page.value = 1; load() }
function resetFilters() { search.value = ''; only.value = ''; sort.value = 'newest'; setFilter('') }

/* Search as you type, a beat after the last key. */
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; load() }, 300)
})
function clearSearch() { search.value = '' }

const me = computed(() => authStore.user?.id)

function roleLabel(r: string): string {
  return ({ super_admin: 'Super Admin', admin: 'Admin', agent: 'Agent', buyer: 'Buyer', ghost_buyer: 'Guest' } as Record<string, string>)[r] ?? r
}
const roleBadge: Record<string, string> = {
  super_admin: 'bg-brand-gold/20 text-brand-gold-deep dark:text-brand-gold',
  admin:       'bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold',
  agent:       'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
  buyer:       'bg-blue-500/10 text-blue-700 dark:text-blue-300',
  ghost_buyer: 'bg-gray-500/10 text-gray-500 dark:text-white/50',
}
function isAdminRole(r: string) { return r === 'admin' || r === 'super_admin' }
function joined(iso: string) { return new Date(iso).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) }

/* Status line under each name: online / last seen, email, agent application. */
function seen(u: User): string {
  if (u.is_online) return 'Online now'
  if (!u.last_seen_at) return 'Never signed in'
  const m = Math.round((Date.now() - +new Date(u.last_seen_at)) / 60000)
  if (m < 60) return `Seen ${Math.max(1, m)}m ago`
  const h = Math.round(m / 60)
  if (h < 24) return `Seen ${h}h ago`
  const d = Math.round(h / 24)
  return d < 30 ? `Seen ${d}d ago` : `Seen ${joined(u.last_seen_at)}`
}
const AGENT_STATUS: Record<string, { label: string; cls: string }> = {
  approved: { label: 'Approved agent',      cls: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' },
  pending:  { label: 'Applied to be agent', cls: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' },
  rejected: { label: 'Application rejected', cls: 'bg-red-500/10 text-red-600 dark:text-red-400' },
}

/* ── Row ⋯ menu ── */
const menuFor = ref<number | null>(null)
function toggleMenu(id: number) { menuFor.value = menuFor.value === id ? null : id }
function closeMenu() { menuFor.value = null }
onMounted(() => document.addEventListener('click', closeMenu))
onUnmounted(() => document.removeEventListener('click', closeMenu))

/* ── Details drawer ── */
const drawerUser = ref<User | null>(null)
const details = ref<Awaited<ReturnType<typeof fetchUser>>>(null)
const detailsLoading = ref(false)
async function openDetails(u: User) {
  closeMenu()
  drawerUser.value = u
  details.value = null
  detailsLoading.value = true
  details.value = await fetchUser(u.id)
  detailsLoading.value = false
}
function closeDetails() { drawerUser.value = null }
const counts = computed(() => {
  const c = details.value?.counts
  const u = drawerUser.value
  if (!c || !u) return []
  if (u.role_type === 'agent') return [
    { label: 'Listings', value: c.listings, hint: `${c.published} published` },
    { label: 'Viewings hosted', value: c.viewings_hosted },
    { label: 'Reviews', value: c.reviews_received, hint: c.reviews_received ? `${c.rating.toFixed(1)} ★ average` : undefined },
    { label: 'Saved homes', value: c.saved },
  ]
  return [
    { label: 'Viewings booked', value: c.viewings_booked },
    { label: 'Reviews written', value: c.reviews_written },
    { label: 'Saved homes', value: c.saved },
    { label: 'Listings', value: c.listings },
  ]
})
function onKey(e: KeyboardEvent) { if (e.key === 'Escape') { closeDetails(); closeMenu() } }
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))

/* ── Remove an agent's cover photo ─────────────────────────────────────
 * Covers go live without review, so an admin can take one down. The reason is
 * required: it's sent to the agent and kept in the activity log. */
const api = useApi()
const coverTarget  = ref<User | null>(null)
const coverReason  = ref('')
const coverError   = ref('')
const coverSending = ref(false)
const COVER_REASON_MIN = 10

function openCoverRemoval(u: User) {
  coverTarget.value = u
  coverReason.value = ''
  coverError.value  = ''
}
async function confirmCoverRemoval() {
  if (!coverTarget.value) return
  const reason = coverReason.value.trim()
  if (reason.length < COVER_REASON_MIN) {
    coverError.value = `Please give a reason the agent can act on (at least ${COVER_REASON_MIN} characters).`
    return
  }
  coverSending.value = true
  coverError.value   = ''
  try {
    await api.del(`/admin/agents/${coverTarget.value.id}/cover`, { reason })
    toast.success(`Cover removed — ${coverTarget.value.name} has been notified`)
    coverTarget.value = null
    await load()
  } catch (e: unknown) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
    coverError.value = data?.errors ? (Object.values(data.errors).flat()[0] ?? '') : (data?.message ?? 'Could not remove the cover.')
  } finally {
    coverSending.value = false
  }
}

async function remove(u: User) {
  if (u.id === me.value) return

  const isAdmin = isAdminRole(u.role_type)

  const ok = await ask({
    title:   isAdmin ? 'Remove this administrator?' : 'Delete this account?',
    message: isAdmin
      ? 'Their sign-in is revoked, but everything they moderated stays in place.'
      : `This permanently deletes the account and everything attached to it.`,
    subject: `${u.name} · ${roleLabel(u.role_type)}`,
    consequences: isAdmin
      ? [
          'They lose access to the admin area immediately',
          'Listings, reviews and users they actioned are untouched',
          'The last remaining admin cannot be removed',
        ]
      : [
          'Their listings and photos are deleted',
          'Their viewings, messages and reviews are deleted',
          'This cannot be undone',
        ],
    confirmLabel: isAdmin ? 'Remove admin' : 'Delete account',
    tone: 'danger',
  })
  if (!ok) return

  busy.value = u.id
  const done = await deleteUser(u.id)
  busy.value = null

  if (done) {
    toast.success(isAdmin ? 'Administrator removed' : 'Account deleted')
    if (drawerUser.value?.id === u.id) closeDetails()
    await Promise.all([load(), fetchStats()])
  } else {
    toast.error(error.value || 'Could not remove this user.')
  }
}

// ── Bulk delete ──
//
// Sequential, one request per account, on purpose: the existing endpoint's
// guards (not yourself, not the last admin) stay in force, one failure
// doesn't abort the rest, and the progress bar reports work actually done
// rather than an animation.

/** Rows that can be bulk-selected. Admins are excluded — they're removed one
 *  at a time via their own button, where the last-admin rule is explained.
 *  Bulk delete exists for clearing dummy buyers and agents. */
const selectable    = computed(() => users.value.filter(u => u.id !== me.value && !isAdminRole(u.role_type)))
const allSelected   = computed(() => selectable.value.length > 0 && selectable.value.every(u => selected.value.has(u.id)))
const someSelected  = computed(() => selected.value.size > 0 && !allSelected.value)
const selectedUsers = computed(() => users.value.filter(u => selected.value.has(u.id)))

function toggleAll() {
  const next = new Set(selected.value)
  if (allSelected.value) selectable.value.forEach(u => next.delete(u.id))
  else                   selectable.value.forEach(u => next.add(u.id))
  selected.value = next
}
function toggleOne(id: number) {
  const next = new Set(selected.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selected.value = next
}
function clearSelection() { selected.value = new Set() }

const bulk = reactive({ open: false, running: false, finished: false, total: 0, done: 0, failed: 0, current: '' })
const bulkProgress = computed(() => bulk.total ? Math.round(((bulk.done + bulk.failed) / bulk.total) * 100) : 0)

function openBulk() {
  if (!selected.value.size) return
  Object.assign(bulk, { open: true, running: false, finished: false, total: selected.value.size, done: 0, failed: 0, current: '' })
}
/** The modal can't be dismissed mid-run — closing it wouldn't stop the deletes. */
function closeBulk() {
  if (bulk.running) return
  bulk.open = false
}

async function runBulk() {
  // Snapshot so the selection can't shift under us while requests are in flight.
  const targets = selectedUsers.value.map(u => ({ id: u.id, name: u.name }))
  bulk.total   = targets.length
  bulk.running = true

  for (const t of targets) {
    bulk.current = t.name
    const ok = await deleteUser(t.id)
    if (ok) bulk.done++
    else    bulk.failed++
  }

  bulk.running  = false
  bulk.finished = true
  bulk.current  = ''
  await Promise.all([load(), fetchStats()])

  if (bulk.failed === 0) toast.success(`${bulk.done} account${bulk.done === 1 ? '' : 's'} deleted`)
  else                   toast.error(`${bulk.done} deleted · ${bulk.failed} could not be removed`)
}

// ── Create admin (CreateAdminDialog) ──
const showCreate = ref(false)
function openCreate() { showCreate.value = true }
// The Overview's "Create an admin" quick action lands here with ?create=1.
onMounted(() => { if (route.query.create === '1') openCreate() })
async function onAdminCreated() {
  roleFilter.value = 'admin'; page.value = 1
  await Promise.all([load(), fetchStats()])
}

/* Side panel: "Joined" relative to now. */
function since(iso: string): string {
  const d = Math.floor((Date.now() - +new Date(iso)) / 86400000)
  return d < 1 ? 'Today' : d === 1 ? 'Yesterday' : d < 7 ? `${d} days ago` : joined(iso)
}
</script>

<template>
  <div class="max-w-6xl mx-auto">
    <!-- ── Header ── -->
    <header class="flex flex-wrap items-end justify-between gap-4 mb-6">
      <div class="min-w-0">
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Manage</p>
        <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Users</h1>
        <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
          <span class="font-semibold text-brand-navy dark:text-white">{{ stats?.total ?? 0 }}</span> accounts<template v-if="stats?.week">
            · <span class="font-semibold text-brand-navy dark:text-white">{{ stats.week }}</span> joined this week</template>.
          Click a person to see their details.
        </p>
      </div>
      <DashCta @click="openCreate">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z" /></svg>
        Create admin
      </DashCta>
    </header>

    <!-- ── Role tiles: they're the filter ── -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5" role="radiogroup" aria-label="Show">
      <button
        v-for="s in statCards"
        :key="s.key"
        type="button"
        role="radio"
        :aria-checked="roleFilter === s.key"
        class="group rounded-2xl px-4 py-3.5 text-left ring-1 transition"
        :class="roleFilter === s.key
          ? 'bg-white ring-2 ring-brand-gold shadow-[0_10px_30px_-18px_rgba(212,165,74,0.8)] dark:bg-[#0B1A35]'
          : 'bg-white ring-brand-navy/[0.07] hover:ring-brand-gold/60 dark:bg-[#0B1A35] dark:ring-white/[0.08]'"
        @click="setFilter(s.key)"
      >
        <span class="flex items-center gap-2 text-[0.625rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">
          <span class="h-2 w-2 rounded-full" :class="s.dot" aria-hidden="true" />{{ s.label }}
        </span>
        <span class="mt-1.5 block text-[1.75rem] leading-none font-bold tabular-nums"
              :class="roleFilter === s.key ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy dark:text-white'">{{ s.value }}</span>
      </button>
    </div>

    <!-- Main column + side panel, as on the buyer pages -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
      <div class="min-w-0">
        <!-- ── Search + sort ── -->
        <div class="mb-3 flex flex-wrap items-center gap-3">
          <div class="relative min-w-[14rem] flex-1">
            <svg class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-brand-navy/40 dark:text-white/40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
            <input
              v-model="search"
              type="search"
              placeholder="Search by name or email"
              aria-label="Search users"
              class="w-full rounded-full border-0 bg-white dark:bg-white/[0.06] pl-10 pr-10 py-2.5 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35
                     ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
            />
            <button v-if="search" type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-7 w-7 rounded-full flex items-center justify-center text-brand-navy/45 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/45 dark:hover:text-white dark:hover:bg-white/10" aria-label="Clear search" @click="clearSearch">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>
          <label class="relative inline-flex items-center">
            <span class="sr-only">Sort users</span>
            <select
              v-model="sort"
              class="appearance-none bg-none rounded-full border-0 bg-white dark:bg-white/[0.06] py-2.5 pl-4 pr-10 text-sm font-semibold text-brand-navy dark:text-white
                     ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold cursor-pointer"
            >
              <option v-for="o in SORTS" :key="o.value" :value="o.value" class="text-brand-navy">Sort: {{ o.label }}</option>
            </select>
            <svg class="pointer-events-none absolute right-3.5 h-4 w-4 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>
          </label>
        </div>

        <!-- ── Quick filters ── -->
        <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Quick filters">
          <button
            v-for="c in CHIPS"
            :key="c.value"
            type="button"
            :aria-pressed="only === c.value"
            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition"
            :class="only === c.value
              ? 'bg-brand-navy text-white ring-brand-navy dark:bg-brand-gold dark:text-[#0B2A5C] dark:ring-brand-gold'
              : 'bg-white text-brand-navy/70 ring-brand-navy/10 hover:ring-brand-gold hover:text-brand-navy dark:bg-white/[0.05] dark:text-white/70 dark:ring-white/10 dark:hover:text-white'"
            @click="setOnly(c.value)"
          >
            <span class="h-1.5 w-1.5 rounded-full" :class="c.dot" aria-hidden="true" />
            {{ c.label }}
            <svg v-if="only === c.value" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <!-- Selection bar: only while something is selected -->
        <Transition name="bar">
          <div v-if="selected.size" class="flex items-center justify-between gap-3 mb-3 px-4 py-2.5 rounded-2xl bg-brand-navy text-white ring-1 ring-brand-gold/25">
            <p class="text-sm font-semibold">{{ selected.size }} account{{ selected.size === 1 ? '' : 's' }} selected</p>
            <div class="flex items-center gap-2">
              <button type="button" class="text-xs font-semibold text-white/70 hover:text-white px-3 py-1.5 transition-colors" @click="clearSelection">Clear</button>
              <button type="button" class="text-xs font-bold bg-red-500 hover:bg-red-600 text-white rounded-full px-4 py-1.5 transition-colors" @click="openBulk">Delete selected</button>
            </div>
          </div>
        </Transition>

        <!-- ── People ── -->
        <div class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <div v-if="loading && !users.length" class="p-4 space-y-2">
            <AppSkeleton v-for="i in 6" :key="i" width="100%" height="60px" />
          </div>

          <div v-else-if="!users.length" class="px-6 py-14 text-center">
            <p class="font-display text-lg font-bold text-brand-navy dark:text-white">No one matches that</p>
            <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Try another name or email, or show all users.</p>
            <button type="button" class="mt-4 rounded-full px-4 py-2 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20" @click="resetFilters">Show everyone</button>
          </div>

          <template v-else>
            <!-- Column heads (wider screens) -->
            <div class="hidden md:grid grid-cols-[1.25rem_minmax(0,1fr)_7rem_7.5rem_2.25rem] items-center gap-x-4 px-5 py-3 border-b border-brand-navy/[0.06] dark:border-white/[0.08]
                        text-[0.625rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">
              <input
                type="checkbox"
                class="h-4 w-4 rounded border-brand-navy/25 text-brand-navy focus:ring-brand-gold/40 cursor-pointer disabled:cursor-not-allowed disabled:opacity-40"
                :checked="allSelected"
                :indeterminate.prop="someSelected"
                :disabled="!selectable.length"
                :title="selectable.length ? 'Select all on this page' : 'Nothing on this page can be bulk-deleted'"
                aria-label="Select all on this page"
                @change="toggleAll"
              />
              <span>User</span><span>Role</span><span>Joined</span><span class="sr-only">Actions</span>
            </div>

            <ul :class="loading ? 'opacity-60 transition-opacity' : ''">
              <li
                v-for="u in users"
                :key="u.id"
                class="group relative grid grid-cols-[1.25rem_minmax(0,1fr)_2.25rem] md:grid-cols-[1.25rem_minmax(0,1fr)_7rem_7.5rem_2.25rem] items-center gap-x-4 px-5 py-3.5
                       border-b border-brand-navy/[0.05] dark:border-white/[0.06] last:border-0 last:rounded-b-3xl cursor-pointer transition-colors"
                :class="selected.has(u.id) ? 'bg-brand-gold/[0.08]' : 'hover:bg-brand-navy/[0.025] dark:hover:bg-white/[0.03]'"
                @click="openDetails(u)"
              >
                <span @click.stop>
                  <input
                    v-if="u.id !== me && !isAdminRole(u.role_type)"
                    type="checkbox"
                    class="h-4 w-4 rounded border-brand-navy/25 text-brand-navy focus:ring-brand-gold/40 cursor-pointer"
                    :checked="selected.has(u.id)"
                    :aria-label="`Select ${u.name}`"
                    @change="toggleOne(u.id)"
                  />
                </span>

                <!-- Person + status -->
                <div class="flex items-center gap-3 min-w-0">
                  <span class="relative flex-none">
                    <AppAvatar :name="u.name" :src="u.avatar" size="sm" />
                    <span v-if="u.is_online" class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-[#0B1A35]" />
                  </span>
                  <div class="min-w-0">
                    <p class="flex items-center gap-1.5 truncate font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">
                      <span class="truncate">{{ u.name }}</span>
                      <span v-if="u.id === me" class="flex-none text-[0.5625rem] font-bold uppercase bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C] px-1.5 py-0.5 rounded-full">You</span>
                    </p>
                    <p class="truncate text-xs text-brand-navy/55 dark:text-white/50">{{ u.email }}</p>
                    <p class="mt-1 flex flex-wrap items-center gap-1.5 text-[0.6875rem]">
                      <span class="md:hidden rounded-full px-2 py-0.5 font-bold uppercase text-[0.5625rem]" :class="roleBadge[u.role_type] ?? 'bg-gray-100 text-gray-500'">{{ roleLabel(u.role_type) }}</span>
                      <span :class="u.is_online ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-brand-navy/45 dark:text-white/40'">{{ seen(u) }}</span>
                      <span v-if="!u.email_verified_at" class="rounded-full px-2 py-0.5 font-semibold bg-amber-500/10 text-amber-700 dark:text-amber-400">Email not verified</span>
                      <span v-if="u.agent_profile && AGENT_STATUS[u.agent_profile.status]" class="rounded-full px-2 py-0.5 font-semibold" :class="AGENT_STATUS[u.agent_profile.status]!.cls">
                        {{ AGENT_STATUS[u.agent_profile.status]!.label }}
                      </span>
                      <span class="md:hidden text-brand-navy/45 dark:text-white/40">· Joined {{ joined(u.created_at) }}</span>
                    </p>
                  </div>
                </div>

                <span class="hidden md:block">
                  <span class="text-[0.625rem] font-bold uppercase px-2 py-0.5 rounded-full" :class="roleBadge[u.role_type] ?? 'bg-gray-100 text-gray-500'">{{ roleLabel(u.role_type) }}</span>
                </span>
                <span class="hidden md:block text-sm text-brand-navy/60 dark:text-white/55">{{ joined(u.created_at) }}</span>

                <!-- ⋯ actions -->
                <div class="relative justify-self-end" @click.stop>
                  <button
                    type="button"
                    class="h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/55 hover:text-brand-navy hover:bg-brand-navy/[0.06] dark:text-white/55 dark:hover:text-white dark:hover:bg-white/10 transition"
                    :aria-label="`Actions for ${u.name}`"
                    :aria-expanded="menuFor === u.id"
                    @click="toggleMenu(u.id)"
                  >
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" /></svg>
                  </button>
                  <Transition name="menu">
                    <div
                      v-if="menuFor === u.id"
                      class="absolute right-0 top-full z-20 mt-1 w-52 overflow-hidden rounded-2xl bg-white dark:bg-[#0F2347] ring-1 ring-brand-navy/10 dark:ring-white/10 shadow-[0_18px_40px_-16px_rgba(11,42,92,0.45)] py-1.5"
                      role="menu"
                    >
                      <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2 text-sm font-medium text-brand-navy hover:bg-brand-navy/[0.05] dark:text-white dark:hover:bg-white/[0.06]" @click="openDetails(u)">
                        <svg class="h-4 w-4 text-brand-navy/50 dark:text-white/50" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        View details
                      </button>
                      <button v-if="u.cover_url" type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2 text-sm font-medium text-amber-700 hover:bg-amber-500/10 dark:text-amber-400" @click="closeMenu(); openCoverRemoval(u)">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" /></svg>
                        Remove cover photo
                      </button>
                      <div class="my-1 h-px bg-brand-navy/[0.06] dark:bg-white/[0.08]" />
                      <button
                        type="button"
                        role="menuitem"
                        :disabled="u.id === me || busy === u.id"
                        :title="u.id === me ? 'You cannot remove yourself' : undefined"
                        class="flex w-full items-center gap-2.5 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-500/10 dark:text-red-400 disabled:opacity-40 disabled:cursor-not-allowed"
                        @click="closeMenu(); remove(u)"
                      >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                        {{ isAdminRole(u.role_type) ? 'Remove admin' : 'Delete account' }}
                      </button>
                    </div>
                  </Transition>
                </div>
              </li>
            </ul>
          </template>
        </div>

        <!-- Pagination -->
        <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-center gap-3 mt-5">
          <button
            type="button"
            :disabled="pagination.current_page <= 1"
            class="h-9 w-9 flex items-center justify-center rounded-full ring-1 ring-brand-navy/15 text-brand-navy hover:ring-brand-gold dark:text-white dark:ring-white/20 disabled:opacity-40 disabled:cursor-not-allowed"
            aria-label="Previous page"
            @click="goPage(pagination.current_page - 1)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
          </button>
          <span class="text-sm text-brand-navy/60 dark:text-white/55">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
          <button
            type="button"
            :disabled="pagination.current_page >= pagination.last_page"
            class="h-9 w-9 flex items-center justify-center rounded-full ring-1 ring-brand-navy/15 text-brand-navy hover:ring-brand-gold dark:text-white dark:ring-white/20 disabled:opacity-40 disabled:cursor-not-allowed"
            aria-label="Next page"
            @click="goPage(pagination.current_page + 1)"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          </button>
        </div>

      </div>

      <!-- ── Side panel ── -->
      <aside class="space-y-4 lg:sticky lg:top-24 lg:self-start">
        <section class="relative overflow-hidden rounded-3xl p-5 bg-brand-navy text-white ring-1 ring-brand-gold/25">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Newest members</p>
          <p class="relative mt-1 text-sm text-white/70">
            <span class="font-semibold text-white">{{ stats?.week ?? 0 }}</span> joined in the last 7 days
          </p>
          <ul v-if="stats?.recent?.length" class="relative mt-3 space-y-1">
            <li v-for="u in stats.recent" :key="u.id">
              <button type="button" class="group flex w-full items-center gap-3 rounded-xl p-1.5 -mx-1.5 text-left hover:bg-white/[0.07] transition" @click="openDetails(u)">
                <AppAvatar :name="u.name" :src="u.avatar" size="sm" />
                <span class="min-w-0 flex-1">
                  <span class="block truncate text-sm font-semibold group-hover:text-brand-gold transition-colors">{{ u.name }}</span>
                  <span class="block truncate text-xs text-white/55">{{ roleLabel(u.role_type) }} · {{ since(u.created_at) }}</span>
                </span>
              </button>
            </li>
          </ul>
        </section>

        <section class="rounded-3xl p-5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
          <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-bold text-brand-navy dark:text-white">Your admins</p>
            <button type="button" class="text-xs font-semibold text-brand-gold-deep hover:text-brand-navy dark:text-brand-gold dark:hover:text-white transition-colors" @click="openCreate">+ Add</button>
          </div>
          <ul class="mt-3 space-y-1">
            <li v-for="u in stats?.admin_list ?? []" :key="u.id">
              <button type="button" class="group flex w-full items-center gap-3 rounded-xl p-1.5 -mx-1.5 text-left hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05] transition" @click="openDetails(u)">
                <span class="relative flex-none">
                  <AppAvatar :name="u.name" :src="u.avatar" size="sm" />
                  <span v-if="u.is_online" class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white dark:ring-[#0B1A35]" />
                </span>
                <span class="min-w-0 flex-1">
                  <span class="flex items-center gap-1.5 truncate text-sm font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition-colors">
                    <span class="truncate">{{ u.name }}</span>
                    <span v-if="u.id === me" class="flex-none text-[0.5625rem] font-bold uppercase bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C] px-1.5 py-0.5 rounded-full">You</span>
                  </span>
                  <span class="block truncate text-xs text-brand-navy/50 dark:text-white/45">{{ roleLabel(u.role_type) }} · {{ seen(u) }}</span>
                </span>
              </button>
            </li>
          </ul>
          <p class="mt-3 rounded-xl bg-brand-cream/80 dark:bg-white/[0.04] px-3 py-2 text-xs text-brand-navy/70 dark:text-white/60">
            The last admin can't be removed, so there's always someone in charge.
          </p>
        </section>
      </aside>
    </div>

    <!-- Create admin -->
    <CreateAdminDialog :open="showCreate" @close="showCreate = false" @created="onAdminCreated" />

    <!-- Bulk delete: confirm → progress → summary, in one dialog -->
    <AppModal :open="bulk.open" size="sm" @close="closeBulk">
      <div class="p-6">

        <!-- 1. Confirm -->
        <template v-if="!bulk.running && !bulk.finished">
          <div class="flex items-start gap-3 mb-4">
            <div class="h-10 w-10 rounded-full bg-red-50 flex items-center justify-center flex-shrink-0">
              <svg class="h-5 w-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
            </div>
            <div>
              <h3 class="font-bold text-brand-navy text-base leading-snug">
                Delete {{ bulk.total }} account{{ bulk.total === 1 ? '' : 's' }}?
              </h3>
              <p class="text-sm text-gray-500 mt-1">This permanently deletes each account and everything attached to it.</p>
            </div>
          </div>

          <ul class="max-h-40 overflow-y-auto rounded-xl bg-gray-50 border border-gray-100 divide-y divide-gray-100 mb-4 text-sm">
            <li v-for="u in selectedUsers" :key="u.id" class="flex items-center justify-between px-3 py-2">
              <span class="font-medium text-brand-navy truncate">{{ u.name }}</span>
              <span class="text-xs text-gray-400 truncate ml-3">{{ u.email }}</span>
            </li>
          </ul>

          <ul class="text-xs text-gray-500 space-y-1 mb-5">
            <li class="flex gap-2"><span class="text-red-400">•</span> Their listings, photos, viewings, messages and reviews are deleted</li>
            <li class="flex gap-2"><span class="text-red-400">•</span> This cannot be undone</li>
          </ul>

          <div class="flex gap-3">
            <AppButton variant="ghost" full-width @click="closeBulk">Cancel</AppButton>
            <button
              class="w-full rounded-xl bg-red-500 hover:bg-red-600 text-white text-sm font-bold py-2.5 transition-colors"
              @click="runBulk"
            >Delete {{ bulk.total }} account{{ bulk.total === 1 ? '' : 's' }}</button>
          </div>
        </template>

        <!-- 2. Progress -->
        <template v-else-if="bulk.running">
          <h3 class="font-bold text-brand-navy text-base mb-1">Deleting accounts…</h3>
          <p class="text-sm text-gray-500 mb-4 truncate">
            {{ Math.min(bulk.done + bulk.failed + 1, bulk.total) }} of {{ bulk.total }}<span v-if="bulk.current"> · {{ bulk.current }}</span>
          </p>

          <div class="flex items-center justify-between text-xs font-semibold text-gray-500 mb-1.5">
            <span>Progress</span>
            <span class="text-brand-navy tabular-nums">{{ bulkProgress }}%</span>
          </div>
          <div class="h-2.5 w-full rounded-full bg-gray-100 overflow-hidden" role="progressbar" :aria-valuenow="bulkProgress" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-red-500 transition-[width] duration-300 ease-out" :style="{ width: bulkProgress + '%' }" />
          </div>

          <p class="text-[0.6875rem] text-gray-400 mt-4">Please keep this window open until it finishes.</p>
        </template>

        <!-- 3. Summary -->
        <template v-else>
          <div class="flex items-start gap-3 mb-4">
            <div class="h-10 w-10 rounded-full flex items-center justify-center flex-shrink-0" :class="bulk.failed ? 'bg-amber-50' : 'bg-emerald-50'">
              <svg v-if="!bulk.failed" class="h-5 w-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
              <svg v-else class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 5a7 7 0 100 14 7 7 0 000-14z" /></svg>
            </div>
            <div>
              <h3 class="font-bold text-brand-navy text-base leading-snug">
                {{ bulk.failed ? 'Finished with some failures' : 'All done' }}
              </h3>
              <p class="text-sm text-gray-500 mt-1">
                <span class="font-semibold text-brand-navy">{{ bulk.done }}</span> deleted<template v-if="bulk.failed">,
                <span class="font-semibold text-amber-700">{{ bulk.failed }}</span> could not be removed — they're still in the list</template>.
              </p>
            </div>
          </div>

          <div class="h-2.5 w-full rounded-full bg-gray-100 overflow-hidden mb-5">
            <div class="h-full rounded-full transition-[width] duration-300" :class="bulk.failed ? 'bg-amber-500' : 'bg-emerald-500'" style="width: 100%" />
          </div>

          <AppButton variant="primary" full-width @click="closeBulk">Done</AppButton>
        </template>

      </div>
    </AppModal>

    <!-- Remove cover — reason required, sent to the agent -->
    <AppModal :open="!!coverTarget" title="Remove this cover photo" @close="coverTarget = null">
      <div v-if="coverTarget" class="p-6 space-y-4">
        <div class="overflow-hidden rounded-xl border border-gray-200">
          <img
            v-if="coverTarget.cover_url"
            :src="coverTarget.cover_url"
            alt=""
            class="h-28 w-full object-cover"
            :style="{ objectPosition: `50% ${coverTarget.cover_position ?? 50}%` }"
          />
          <p class="px-3 py-2 text-xs text-brand-text-secondary">Cover of <span class="font-semibold text-brand-navy">{{ coverTarget.name }}</span></p>
        </div>

        <div>
          <label class="text-sm font-medium text-brand-text-primary block mb-1">
            Reason <span class="text-red-500">*</span>
          </label>
          <textarea
            v-model="coverReason"
            rows="3"
            maxlength="500"
            class="input-field resize-none"
            placeholder="e.g. The cover shows another brokerage's logo. Please use a photo of your own work."
          />
          <div class="flex items-center justify-between mt-1">
            <p class="text-[0.6875rem] text-brand-text-secondary">
              Sent to <span class="font-medium text-brand-navy">{{ coverTarget.name }}</span> and kept in the activity log.
            </p>
            <span class="text-[0.6875rem] tabular-nums" :class="coverReason.trim().length < COVER_REASON_MIN ? 'text-gray-400' : 'text-emerald-600'">
              {{ coverReason.trim().length }}/500
            </span>
          </div>
        </div>

        <p v-if="coverError" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl px-4 py-3">{{ coverError }}</p>

        <div class="flex gap-3">
          <AppButton variant="ghost" full-width :disabled="coverSending" @click="coverTarget = null">Cancel</AppButton>
          <AppButton variant="primary" full-width :disabled="coverSending || coverReason.trim().length < COVER_REASON_MIN" @click="confirmCoverRemoval">
            {{ coverSending ? 'Removing…' : 'Remove & notify agent' }}
          </AppButton>
        </div>
      </div>
    </AppModal>

    <!-- ── Details drawer ── -->
    <Teleport to="body">
      <Transition name="drawer">
        <div v-if="drawerUser" class="fixed inset-0 z-[70] flex justify-end" role="dialog" aria-modal="true" :aria-label="`Details for ${drawerUser.name}`">
          <div class="absolute inset-0 bg-[#06183A]/50 backdrop-blur-sm" @click="closeDetails" />

          <aside class="drawer-panel relative flex h-full w-full max-w-md flex-col overflow-hidden bg-[#ffffff] dark:bg-[#0B1A35] shadow-2xl">
            <button type="button" class="absolute right-3 top-3 z-10 h-9 w-9 rounded-full flex items-center justify-center bg-black/35 text-white hover:bg-black/55 backdrop-blur-sm transition" aria-label="Close details" @click="closeDetails">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <!-- Cover and details scroll together, so the avatar can overlap the cover -->
            <div class="flex-1 overflow-y-auto">
              <div class="relative h-28 bg-gradient-to-br from-[#0d2550] via-[#0B2A5C] to-[#06183A]">
                <img v-if="drawerUser.cover_url" :src="drawerUser.cover_url" alt="" class="h-full w-full object-cover" :style="{ objectPosition: `50% ${drawerUser.cover_position ?? 50}%` }" />
                <div v-else class="absolute inset-0 bg-grid-gold opacity-[0.08]" />
              </div>

              <div class="px-6 pb-6">
                <div class="relative -mt-9 flex items-end gap-3">
                  <span class="relative rounded-full ring-4 ring-[#ffffff] dark:ring-[#0B1A35]">
                    <AppAvatar :name="drawerUser.name" :src="drawerUser.avatar" size="xl" />
                    <span v-if="drawerUser.is_online" class="absolute bottom-1 right-1 h-3.5 w-3.5 rounded-full bg-emerald-500 ring-2 ring-[#ffffff] dark:ring-[#0B1A35]" />
                  </span>
                  <span class="mb-1 text-[0.625rem] font-bold uppercase px-2 py-0.5 rounded-full" :class="roleBadge[drawerUser.role_type] ?? 'bg-gray-100 text-gray-500'">{{ roleLabel(drawerUser.role_type) }}</span>
                </div>
                <h2 class="mt-3 font-display text-xl font-bold text-brand-navy dark:text-white">{{ drawerUser.name }}</h2>
                <p class="text-sm text-brand-navy/60 dark:text-white/55 break-all">{{ drawerUser.email }}</p>

                <!-- Account facts -->
                <dl class="mt-5 space-y-2.5 rounded-2xl bg-brand-cream/70 dark:bg-white/[0.04] p-4 text-sm">
                  <div class="flex justify-between gap-3">
                    <dt class="text-brand-navy/55 dark:text-white/50">Email</dt>
                    <dd class="font-semibold" :class="drawerUser.email_verified_at ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'">{{ drawerUser.email_verified_at ? 'Verified' : 'Not verified' }}</dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-brand-navy/55 dark:text-white/50">Phone</dt>
                    <dd class="font-semibold text-brand-navy dark:text-white">{{ drawerUser.phone || '—' }}</dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-brand-navy/55 dark:text-white/50">Last active</dt>
                    <dd class="font-semibold text-brand-navy dark:text-white">{{ seen(drawerUser) }}</dd>
                  </div>
                  <div class="flex justify-between gap-3">
                    <dt class="text-brand-navy/55 dark:text-white/50">Joined</dt>
                    <dd class="font-semibold text-brand-navy dark:text-white">{{ joined(drawerUser.created_at) }}</dd>
                  </div>
                  <div v-if="drawerUser.reliability?.rate != null" class="flex justify-between gap-3">
                    <dt class="text-brand-navy/55 dark:text-white/50">Viewings kept</dt>
                    <dd class="font-semibold text-brand-navy dark:text-white">{{ drawerUser.reliability.rate }}%</dd>
                  </div>
                </dl>

                <!-- Agent application -->
                <section v-if="drawerUser.agent_profile" class="mt-4 rounded-2xl p-4 ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
                  <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-bold text-brand-navy dark:text-white">Agent application</p>
                    <span v-if="AGENT_STATUS[drawerUser.agent_profile.status]" class="rounded-full px-2 py-0.5 text-[0.6875rem] font-semibold" :class="AGENT_STATUS[drawerUser.agent_profile.status]!.cls">
                      {{ drawerUser.agent_profile.status === 'approved' ? 'Approved' : drawerUser.agent_profile.status === 'pending' ? 'Pending' : 'Rejected' }}
                    </span>
                  </div>
                  <p class="mt-1 text-xs text-brand-navy/60 dark:text-white/55">
                    {{ drawerUser.agent_profile.applicant_type === 'broker' ? 'Broker' : 'Salesperson' }}<template v-if="drawerUser.agent_profile.prc_number"> · {{ drawerUser.agent_profile.prc_number }}</template>
                  </p>
                  <NuxtLink v-if="drawerUser.agent_profile.status === 'pending'" to="/admin/agents" class="mt-3 inline-flex rounded-full bg-brand-gold px-4 py-1.5 text-xs font-bold text-[#0B2A5C] hover:bg-brand-gold-light transition">Review application</NuxtLink>
                </section>

                <!-- Activity counts -->
                <p class="mt-6 mb-2 text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">On RealtyLink</p>
                <div v-if="detailsLoading" class="grid grid-cols-2 gap-2">
                  <AppSkeleton v-for="i in 4" :key="i" width="100%" height="68px" rounded="lg" />
                </div>
                <div v-else-if="counts.length" class="grid grid-cols-2 gap-2">
                  <div v-for="c in counts" :key="c.label" class="rounded-2xl px-4 py-3 ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08]">
                    <p class="text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/45 dark:text-white/40">{{ c.label }}</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-brand-navy dark:text-white">{{ c.value }}</p>
                    <p v-if="c.hint" class="text-[0.6875rem] text-brand-navy/50 dark:text-white/45">{{ c.hint }}</p>
                  </div>
                </div>
                <p v-else class="text-sm text-brand-navy/55 dark:text-white/50">Couldn't load their activity.</p>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex-none flex flex-wrap gap-2 border-t border-brand-navy/[0.07] dark:border-white/[0.08] px-6 py-4">
              <button
                v-if="drawerUser.cover_url"
                type="button"
                class="flex-1 rounded-full px-4 py-2.5 text-sm font-semibold text-amber-700 ring-1 ring-amber-300 hover:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/40 transition"
                @click="openCoverRemoval(drawerUser); closeDetails()"
              >Remove cover</button>
              <button
                type="button"
                :disabled="drawerUser.id === me || busy === drawerUser.id"
                :title="drawerUser.id === me ? 'You cannot remove yourself' : undefined"
                class="flex-1 rounded-full px-4 py-2.5 text-sm font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 disabled:opacity-40 disabled:cursor-not-allowed transition"
                @click="remove(drawerUser)"
              >{{ isAdminRole(drawerUser.role_type) ? 'Remove admin' : 'Delete account' }}</button>
            </div>
          </aside>
        </div>
      </Transition>
    </Teleport>

  </div>
</template>

<style scoped>
.bar-enter-active, .bar-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.bar-enter-from, .bar-leave-to { opacity: 0; transform: translateY(-4px); }

.menu-enter-active, .menu-leave-active { transition: opacity 0.12s ease, transform 0.12s ease; }
.menu-enter-from, .menu-leave-to { opacity: 0; transform: translateY(-4px) scale(0.98); }

.drawer-enter-active, .drawer-leave-active { transition: opacity 0.25s ease; }
.drawer-enter-active .drawer-panel, .drawer-leave-active .drawer-panel { transition: transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-from .drawer-panel, .drawer-leave-to .drawer-panel { transform: translateX(100%); }
@media (prefers-reduced-motion: reduce) {
  .drawer-enter-active .drawer-panel, .drawer-leave-active .drawer-panel { transition: none; }
}
</style>
