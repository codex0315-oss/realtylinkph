<script setup lang="ts">
import type { User } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const { users, pagination, loading, error, stats, fetchStats, fetchUsers, createAdmin, deleteUser } = useAdmin()
const ask   = useConfirm()
const toast = useToast()

const roleFilter = ref('')      // '' = all
const search     = ref('')
const page       = ref(1)
const busy       = ref<number | null>(null)
const selected   = ref<Set<number>>(new Set())

const FILTERS = [
  { key: '',      label: 'All' },
  { key: 'buyer', label: 'Buyers' },
  { key: 'agent', label: 'Agents' },
  { key: 'admin', label: 'Admins' },
]

async function load() {
  await fetchUsers({ page: page.value, role: roleFilter.value || undefined, search: search.value || undefined })
  // A selection only ever refers to rows on screen; once the rows change it
  // would be a hidden list of ids the admin can no longer see or verify.
  selected.value = new Set()
}
await Promise.all([load(), fetchStats()])

const statCards = computed(() => [
  { label: 'Total users', value: stats.value?.total  ?? 0, dot: 'bg-brand-gold' },
  { label: 'Buyers',      value: stats.value?.buyers ?? 0, dot: 'bg-blue-500' },
  { label: 'Agents',      value: stats.value?.agents ?? 0, dot: 'bg-emerald-500' },
  { label: 'Admins',      value: stats.value?.admins ?? 0, dot: 'bg-brand-navy' },
])

function setFilter(k: string) { roleFilter.value = k; page.value = 1; load() }
function onSearch()           { page.value = 1; load() }
function goPage(p: number)    { page.value = p; load() }

const me = computed(() => authStore.user?.id)

function roleLabel(r: string): string {
  return ({ super_admin: 'Super Admin', admin: 'Admin', agent: 'Agent', buyer: 'Buyer', ghost_buyer: 'Guest' } as Record<string, string>)[r] ?? r
}
const roleBadge: Record<string, string> = {
  super_admin: 'bg-brand-gold/20 text-brand-navy',
  admin:       'bg-brand-gold/15 text-brand-navy',
  agent:       'bg-emerald-100 text-emerald-700',
  buyer:       'bg-blue-100 text-blue-700',
  ghost_buyer: 'bg-gray-100 text-gray-500',
}
function isAdminRole(r: string) { return r === 'admin' || r === 'super_admin' }
function joined(iso: string) { return new Date(iso).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) }

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
    await load()
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

// ── Create admin ──
const showCreate  = ref(false)
const creating    = ref(false)
const createError = ref('')
const form = reactive({ name: '', email: '', password: '' })

function openCreate() {
  form.name = ''; form.email = ''; form.password = ''
  createError.value = ''
  showCreate.value = true
}
async function submitCreate() {
  if (!form.name.trim() || !form.email.trim() || form.password.length < 8) {
    createError.value = 'Enter a name, a valid email, and a password of at least 8 characters.'
    return
  }
  creating.value = true
  createError.value = ''
  const created = await createAdmin({ name: form.name.trim(), email: form.email.trim(), password: form.password })
  creating.value = false
  if (created) {
    showCreate.value = false
    roleFilter.value = 'admin'; page.value = 1
    await load()
  } else {
    createError.value = error.value || 'Could not create the admin.'
  }
}
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <!-- Header -->
    <div class="flex items-end justify-between gap-3 mb-6">
      <div>
        <h1 class="font-playfair text-2xl font-bold text-brand-navy">Users</h1>
        <p v-if="pagination" class="text-sm text-gray-500 mt-1">{{ pagination.total }} total accounts</p>
      </div>
      <AppButton variant="primary" @click="openCreate">+ Create Admin</AppButton>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
      <div v-for="s in statCards" :key="s.label" class="bg-white rounded-2xl border border-gray-200 p-4">
        <div class="flex items-center gap-2">
          <span class="h-2 w-2 rounded-full" :class="s.dot" />
          <p class="text-[0.6875rem] font-bold uppercase tracking-wide text-gray-400">{{ s.label }}</p>
        </div>
        <p class="text-2xl font-bold text-brand-navy mt-1.5">{{ s.value }}</p>
      </div>
    </div>

    <!-- Filters + search -->
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-5">
      <div class="flex gap-1 bg-gray-100 rounded-xl p-1">
        <button
          v-for="f in FILTERS"
          :key="f.key"
          class="text-xs font-semibold px-3.5 py-1.5 rounded-lg transition-colors"
          :class="roleFilter === f.key ? 'bg-white text-brand-navy shadow-sm' : 'text-gray-500 hover:text-brand-navy'"
          @click="setFilter(f.key)"
        >{{ f.label }}</button>
      </div>
      <div class="relative flex-1 max-w-xs">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        <input
          v-model="search"
          type="text"
          placeholder="Search name or email…"
          class="w-full pl-9 pr-3 py-2.5 text-sm rounded-xl bg-gray-50 border border-gray-200 focus:bg-white focus:outline-none focus:border-brand-gold/50 transition-colors"
          @keydown.enter="onSearch"
        />
      </div>
    </div>

    <!-- Selection bar — only exists while something is selected -->
    <Transition name="bar">
      <div
        v-if="selected.size"
        class="flex items-center justify-between gap-3 mb-3 px-4 py-2.5 rounded-xl bg-brand-navy text-white"
      >
        <p class="text-sm font-semibold">
          {{ selected.size }} account{{ selected.size === 1 ? '' : 's' }} selected
        </p>
        <div class="flex items-center gap-2">
          <button class="text-xs font-semibold text-white/70 hover:text-white px-3 py-1.5 transition-colors" @click="clearSelection">Clear</button>
          <button
            class="text-xs font-bold bg-red-500 hover:bg-red-600 text-white rounded-lg px-3.5 py-1.5 transition-colors"
            @click="openBulk"
          >Delete selected</button>
        </div>
      </div>
    </Transition>

    <!-- Table -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <div v-if="loading" class="p-4 space-y-2">
        <AppSkeleton v-for="i in 6" :key="i" width="100%" height="56px" />
      </div>

      <div v-else-if="!users.length" class="text-center py-16 text-gray-400 text-sm">No users found.</div>

      <table v-else class="w-full text-sm">
        <thead>
          <tr class="text-left text-[0.6875rem] font-bold uppercase tracking-wide text-gray-400 border-b border-gray-100">
            <th class="pl-4 pr-1 py-3 w-8">
              <input
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 text-brand-navy focus:ring-brand-gold/40 cursor-pointer disabled:cursor-not-allowed disabled:opacity-40"
                :checked="allSelected"
                :indeterminate.prop="someSelected"
                :disabled="!selectable.length"
                :title="selectable.length ? 'Select all on this page' : 'Nothing on this page can be bulk-deleted'"
                @change="toggleAll"
              />
            </th>
            <th class="px-3 py-3">User</th>
            <th class="px-4 py-3 hidden sm:table-cell">Role</th>
            <th class="px-4 py-3 hidden md:table-cell">Joined</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="u in users"
            :key="u.id"
            class="border-b border-gray-50 last:border-0 transition-colors"
            :class="selected.has(u.id) ? 'bg-brand-gold/[0.07]' : 'hover:bg-gray-50/60'"
          >
            <td class="pl-4 pr-1 py-3">
              <input
                v-if="u.id !== me && !isAdminRole(u.role_type)"
                type="checkbox"
                class="h-4 w-4 rounded border-gray-300 text-brand-navy focus:ring-brand-gold/40 cursor-pointer"
                :checked="selected.has(u.id)"
                @change="toggleOne(u.id)"
              />
            </td>
            <td class="px-3 py-3">
              <div class="flex items-center gap-3 min-w-0">
                <div class="relative flex-shrink-0">
                  <AppAvatar :name="u.name" :src="u.avatar" size="sm" />
                  <span v-if="u.is_online" class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 border-2 border-white" />
                </div>
                <div class="min-w-0">
                  <p class="font-semibold text-brand-navy truncate flex items-center gap-1.5">
                    {{ u.name }}
                    <span v-if="u.id === me" class="text-[0.5625rem] font-bold uppercase bg-brand-navy text-white px-1.5 py-0.5 rounded-full">You</span>
                  </p>
                  <p class="text-xs text-gray-400 truncate">{{ u.email }}</p>
                </div>
              </div>
            </td>
            <td class="px-4 py-3 hidden sm:table-cell">
              <span class="text-[0.625rem] font-bold uppercase px-2 py-0.5 rounded-full" :class="roleBadge[u.role_type] ?? 'bg-gray-100 text-gray-500'">{{ roleLabel(u.role_type) }}</span>
            </td>
            <td class="px-4 py-3 hidden md:table-cell text-gray-500">{{ joined(u.created_at) }}</td>
            <td class="px-4 py-3 text-right">
              <button
                :disabled="u.id === me || busy === u.id"
                class="text-xs font-semibold text-red-500 border border-red-200 rounded-lg px-3 py-1.5 hover:bg-red-50 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                :title="u.id === me ? 'You cannot remove yourself' : 'Remove'"
                @click="remove(u)"
              >{{ busy === u.id ? '…' : (isAdminRole(u.role_type) ? 'Remove admin' : 'Delete') }}</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div v-if="pagination && pagination.last_page > 1" class="flex items-center justify-center gap-2 mt-5">
      <button
        :disabled="pagination.current_page <= 1"
        class="h-9 w-9 flex items-center justify-center rounded-lg border border-gray-200 text-brand-navy hover:border-brand-gold disabled:opacity-40 disabled:cursor-not-allowed"
        @click="goPage(pagination.current_page - 1)"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
      </button>
      <span class="text-sm text-gray-500">Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
      <button
        :disabled="pagination.current_page >= pagination.last_page"
        class="h-9 w-9 flex items-center justify-center rounded-lg border border-gray-200 text-brand-navy hover:border-brand-gold disabled:opacity-40 disabled:cursor-not-allowed"
        @click="goPage(pagination.current_page + 1)"
      >
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
      </button>
    </div>

    <!-- Create admin modal -->
    <AppModal :open="showCreate" title="Create Admin" @close="showCreate = false">
      <div class="p-6 space-y-4">
        <p class="text-sm text-gray-500">Create another administrator — useful for handing over before stepping down. They'll have full admin access.</p>
        <AppInput v-model="form.name" label="Full name" placeholder="Jane Admin" />
        <AppInput v-model="form.email" label="Email" type="email" placeholder="admin@example.com" />
        <div>
          <label class="text-sm font-medium text-brand-text-primary">Password</label>
          <input v-model="form.password" type="password" placeholder="At least 8 characters" class="input-field mt-1" />
        </div>
        <p v-if="createError" class="text-sm text-red-500">{{ createError }}</p>
        <div class="flex gap-3">
          <AppButton variant="ghost" full-width @click="showCreate = false">Cancel</AppButton>
          <AppButton variant="primary" full-width :disabled="creating" @click="submitCreate">
            {{ creating ? 'Creating…' : 'Create Admin' }}
          </AppButton>
        </div>
      </div>
    </AppModal>

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
  </div>
</template>

<style scoped>
.bar-enter-active, .bar-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.bar-enter-from, .bar-leave-to { opacity: 0; transform: translateY(-4px); }
</style>
