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

const FILTERS = [
  { key: '',      label: 'All' },
  { key: 'buyer', label: 'Buyers' },
  { key: 'agent', label: 'Agents' },
  { key: 'admin', label: 'Admins' },
]

async function load() {
  await fetchUsers({ page: page.value, role: roleFilter.value || undefined, search: search.value || undefined })
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
  <div class="max-w-5xl">
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
          <p class="text-[11px] font-bold uppercase tracking-wide text-gray-400">{{ s.label }}</p>
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

    <!-- Table -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <div v-if="loading" class="p-4 space-y-2">
        <AppSkeleton v-for="i in 6" :key="i" width="100%" height="56px" />
      </div>

      <div v-else-if="!users.length" class="text-center py-16 text-gray-400 text-sm">No users found.</div>

      <table v-else class="w-full text-sm">
        <thead>
          <tr class="text-left text-[11px] font-bold uppercase tracking-wide text-gray-400 border-b border-gray-100">
            <th class="px-4 py-3">User</th>
            <th class="px-4 py-3 hidden sm:table-cell">Role</th>
            <th class="px-4 py-3 hidden md:table-cell">Joined</th>
            <th class="px-4 py-3 text-right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id" class="border-b border-gray-50 last:border-0 hover:bg-gray-50/60 transition-colors">
            <td class="px-4 py-3">
              <div class="flex items-center gap-3 min-w-0">
                <div class="relative flex-shrink-0">
                  <AppAvatar :name="u.name" :src="u.avatar" size="sm" />
                  <span v-if="u.is_online" class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full bg-emerald-500 border-2 border-white" />
                </div>
                <div class="min-w-0">
                  <p class="font-semibold text-brand-navy truncate flex items-center gap-1.5">
                    {{ u.name }}
                    <span v-if="u.id === me" class="text-[9px] font-bold uppercase bg-brand-navy text-white px-1.5 py-0.5 rounded-full">You</span>
                  </p>
                  <p class="text-xs text-gray-400 truncate">{{ u.email }}</p>
                </div>
              </div>
            </td>
            <td class="px-4 py-3 hidden sm:table-cell">
              <span class="text-[10px] font-bold uppercase px-2 py-0.5 rounded-full" :class="roleBadge[u.role_type] ?? 'bg-gray-100 text-gray-500'">{{ roleLabel(u.role_type) }}</span>
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
  </div>
</template>
