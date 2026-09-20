<script setup lang="ts">
import type { AdminAction, PaginatedResponse } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
if (!authStore.isAdmin) {
  throw createError({ statusCode: 403, statusMessage: 'Forbidden' })
}

const api = useApi()

/*
 * The audit trail. Every administrator action — approving an agent, taking a
 * listing down, hiding a review, deleting a user — is written server-side at
 * the moment it happens, with who did it and why. This page only reads.
 */
const FILTERS: Array<{ key: string; label: string }> = [
  { key: '',                    label: 'All' },
  { key: 'agent.approved',      label: 'Approvals' },
  { key: 'agent.rejected',      label: 'Rejections' },
  { key: 'listing.unpublished', label: 'Unpublished' },
  { key: 'listing.deleted',     label: 'Deleted listings' },
  { key: 'review.hidden',       label: 'Hidden reviews' },
  { key: 'user.deleted',        label: 'Deleted users' },
]

const TONE: Record<string, string> = {
  'agent.approved':      'bg-emerald-50 text-emerald-700',
  'agent.rejected':      'bg-red-50 text-red-600',
  'listing.unpublished': 'bg-amber-50 text-amber-700',
  'listing.deleted':     'bg-red-50 text-red-600',
  'review.hidden':       'bg-amber-50 text-amber-700',
  'review.shown':        'bg-emerald-50 text-emerald-700',
  'user.deleted':        'bg-red-50 text-red-600',
  'admin.created':       'bg-brand-navy/5 text-brand-navy',
}

const rows       = ref<AdminAction[]>([])
const pagination = ref<PaginatedResponse<AdminAction>['meta'] | null>(null)
const loading    = ref(false)
const filter     = ref('')
const search     = ref('')

async function load(page = 1) {
  loading.value = true
  try {
    const res = await api.get<PaginatedResponse<AdminAction>>('/admin/actions', {
      page,
      ...(filter.value ? { action: filter.value } : {}),
      ...(search.value.trim() ? { search: search.value.trim() } : {}),
    })
    rows.value       = res.data
    pagination.value = res.meta
  } finally {
    loading.value = false
  }
}

function setFilter(key: string) { filter.value = key; load() }
function onSearch()             { load() }
function goPage(p: number)      { load(p) }

function when(iso: string): string {
  return new Date(iso).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' })
}

/** The one detail worth showing inline; the rest sits in the tooltip. */
function detailLine(a: AdminAction): string {
  const d = a.details ?? {}
  if (typeof d.reason === 'string') return d.reason
  if (typeof d.agent === 'string')  return `Agent: ${d.agent}`
  if (typeof d.role === 'string')   return `Role: ${d.role}`
  return ''
}

await load()
</script>

<template>
  <div class="max-w-5xl mx-auto">
    <div class="flex items-end justify-between gap-3 mb-6">
      <div>
        <h1 class="font-playfair text-2xl font-bold text-brand-navy">Activity</h1>
        <p class="text-sm text-gray-500 mt-1">
          Every administrator action, newest first{{ pagination ? ` · ${pagination.total} recorded` : '' }}.
        </p>
      </div>
    </div>

    <div class="flex flex-col lg:flex-row lg:items-center gap-3 mb-5">
      <div class="flex flex-wrap gap-1 bg-gray-100 rounded-xl p-1">
        <button
          v-for="f in FILTERS"
          :key="f.key"
          class="text-xs font-semibold px-3 py-1.5 rounded-lg transition-colors"
          :class="filter === f.key ? 'bg-white text-brand-navy shadow-sm' : 'text-gray-500 hover:text-brand-navy'"
          @click="setFilter(f.key)"
        >{{ f.label }}</button>
      </div>
      <div class="relative flex-1 max-w-xs">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        <input
          v-model="search"
          type="text"
          placeholder="Search by name, listing or admin…"
          class="w-full pl-9 pr-3 py-2.5 text-sm rounded-xl bg-gray-50 border border-gray-200 focus:bg-white focus:outline-none focus:border-brand-gold/50 transition-colors"
          @keydown.enter="onSearch"
        />
      </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <div v-if="loading" class="p-4 space-y-2">
        <AppSkeleton v-for="i in 6" :key="i" width="100%" height="56px" />
      </div>

      <div v-else-if="!rows.length" class="text-center py-16 text-gray-400 text-sm">
        Nothing recorded yet. Actions taken from the Agents, Listings, Reviews and Users pages will appear here.
      </div>

      <table v-else class="w-full text-sm">
        <thead>
          <tr class="text-left text-[0.6875rem] font-bold uppercase tracking-wide text-gray-400 border-b border-gray-100">
            <th class="pl-4 pr-3 py-3 whitespace-nowrap">When</th>
            <th class="px-3 py-3">Admin</th>
            <th class="px-3 py-3">Action</th>
            <th class="px-3 py-3">Subject</th>
            <th class="px-4 py-3 hidden md:table-cell">Details</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in rows" :key="a.id" class="border-b border-gray-50 last:border-0 align-top hover:bg-gray-50/60 transition-colors">
            <td class="pl-4 pr-3 py-3 whitespace-nowrap text-gray-500 text-xs">{{ when(a.created_at) }}</td>
            <td class="px-3 py-3 font-medium text-brand-navy whitespace-nowrap">{{ a.admin_name }}</td>
            <td class="px-3 py-3">
              <span class="inline-block text-[0.6875rem] font-bold px-2 py-0.5 rounded-md whitespace-nowrap" :class="TONE[a.action] ?? 'bg-gray-100 text-gray-600'">
                {{ a.label }}
              </span>
            </td>
            <td class="px-3 py-3 text-brand-navy">
              {{ a.subject_label }}
              <span v-if="a.subject_type" class="block text-[0.6875rem] text-gray-400">{{ a.subject_type }} #{{ a.subject_id }}</span>
            </td>
            <td class="px-4 py-3 hidden md:table-cell text-gray-500 max-w-md" :title="a.details ? JSON.stringify(a.details, null, 1) : ''">
              {{ detailLine(a) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

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
  </div>
</template>
