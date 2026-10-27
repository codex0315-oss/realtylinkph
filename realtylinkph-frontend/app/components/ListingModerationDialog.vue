<script setup lang="ts">
import type { Property } from '~/types'

/**
 * Unpublish or delete a listing (admin Listings page). Either way the agent
 * is told why: preset chips add common reasons, and the admin can write
 * their own. Unpublishing keeps the listing (the agent fixes and re-publishes);
 * deleting removes it and its photos for good.
 */
const props = defineProps<{ open: boolean; mode: 'unpublish' | 'delete'; property: Property | null }>()
const emit = defineEmits<{ close: []; done: [mode: 'unpublish' | 'delete'] }>()

const api = useApi()
const toast = useToast()

const reason = ref('')
const sending = ref(false)
const error = ref<string | null>(null)
const REASON_MIN = 10

watch(() => props.open, (o) => {
  if (!o) return
  reason.value = ''
  error.value = null
  sending.value = false
})

const agentName = computed(() => props.property?.agent?.name ?? 'the agent')
const agentFirst = computed(() => agentName.value.split(' ')[0])
const PRESETS = [
  "The photos don't match the property described.",
  'The price looks wrong or misleading.',
  'This is a duplicate of another listing.',
  'Key details are missing (address, size or rooms).',
  "This property doesn't appear to be yours to list.",
  'The description has contact details or outside links.',
]
function addPreset(p: string) {
  if (reason.value.includes(p)) return
  reason.value = reason.value.trim() ? `${reason.value.trim()} ${p}` : p
}

function calm(t?: string | null): string {
  if (!t) return ''
  const letters = t.replace(/[^A-Za-z]/g, '')
  return letters.length > 3 && letters === letters.toUpperCase()
    ? t.toLowerCase().replace(/\b([a-z])/g, (_, c: string) => c.toUpperCase())
    : t
}
const photo = computed(() => {
  const ps = props.property?.photos ?? []
  const p = ps.find(x => !x.is_360) ?? ps[0]
  return p ? (p.thumb_url ?? p.url) : null
})

async function submit() {
  if (!props.property || !ready.value || sending.value) return
  sending.value = true
  error.value = null
  try {
    if (props.mode === 'unpublish') await api.post(`/admin/properties/${props.property.id}/unpublish`, { reason: reason.value.trim() })
    else await api.del(`/admin/properties/${props.property.id}`, { reason: reason.value.trim() })
    toast.success(props.mode === 'unpublish' ? `Unpublished. ${agentFirst.value} has been told why` : `Listing deleted. ${agentFirst.value} has been told why`)
    emit('done', props.mode)
  } catch (e: unknown) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
    error.value = data?.errors ? (Object.values(data.errors).flat()[0] ?? null) : (data?.message ?? null)
    error.value ??= 'That didn’t go through. Please try again.'
  } finally {
    sending.value = false
  }
}

/* Deleting can't be undone, so it also asks for the word DELETE. */
const typed = ref('')
watch(() => props.open, (o) => { if (o) typed.value = '' })
const ready = computed(() => reason.value.trim().length >= REASON_MIN
  && (props.mode === 'unpublish' || typed.value.trim().toUpperCase() === 'DELETE'))

function close() { if (!sending.value) emit('close') }
</script>

<template>
  <AppDialog
    :open="open && !!property"
    :title="mode === 'unpublish' ? 'Take this listing down?' : 'Delete this listing for good?'"
    :subtitle="mode === 'unpublish' ? 'Unpublish listing: the agent is told why' : 'Delete listing: this can’t be undone'"
    :tone="mode === 'unpublish' ? 'warning' : 'danger'"
    :icon="mode === 'unpublish' ? 'hide' : 'trash'"
    :busy="sending"
    size="md"
    @close="close"
  >
          <form v-if="property" @submit.prevent="submit">
            <!-- Which listing -->
            <div class="flex items-center gap-3 rounded-2xl p-2.5 pr-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
              <span class="h-14 w-16 flex-none overflow-hidden rounded-xl bg-brand-navy/5 dark:bg-white/10">
                <img v-if="photo" :src="photo" alt="" class="h-full w-full object-cover" />
              </span>
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ calm(property.title) }}</p>
                <p class="truncate text-xs text-brand-navy/55 dark:text-white/55">Listed by {{ agentName }}</p>
              </div>
            </div>

            <ul class="mt-4 space-y-1 text-xs text-brand-navy/65 dark:text-white/60">
              <template v-if="mode === 'unpublish'">
                <li class="flex gap-2"><span class="text-amber-500">•</span>It's hidden from buyers right away; nothing is deleted.</li>
                <li class="flex gap-2"><span class="text-amber-500">•</span>{{ agentFirst }} sees your reason on the listing and can fix it and re-publish.</li>
              </template>
              <template v-else>
                <li class="flex gap-2"><span class="text-red-500">•</span>The listing, its photos, inquiries and viewing history are removed.</li>
                <li class="flex gap-2"><span class="text-red-500">•</span>This can't be undone. To give {{ agentFirst }} a chance to fix it, unpublish instead.</li>
              </template>
            </ul>

            <p class="mt-5 text-sm font-semibold text-brand-navy dark:text-white">Common reasons <span class="font-normal text-brand-navy/50 dark:text-white/45">(tap to add)</span></p>
            <div class="mt-2 flex flex-wrap gap-1.5">
              <button
                v-for="p in PRESETS"
                :key="p"
                type="button"
                class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition"
                :class="reason.includes(p)
                  ? (mode === 'unpublish' ? 'bg-amber-500/10 text-amber-800 ring-amber-400/50 dark:text-amber-300' : 'bg-red-500/10 text-red-700 ring-red-400/40 dark:text-red-300')
                  : 'text-brand-navy/75 ring-brand-navy/10 hover:ring-brand-gold dark:text-white/70 dark:ring-white/15'"
                @click="addPreset(p)"
              >{{ p }}</button>
            </div>

            <label for="lm-reason" class="mt-4 mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white">Reason <span class="text-red-500">*</span></label>
            <textarea
              id="lm-reason"
              v-model="reason"
              rows="4"
              maxlength="500"
              placeholder="Tell the agent exactly what's wrong so they can fix it."
              class="w-full resize-none rounded-xl border-0 bg-brand-navy/[0.04] dark:bg-white/[0.06] px-4 py-3 text-sm text-brand-navy dark:text-white placeholder-brand-navy/35 dark:placeholder-white/35 ring-1 ring-brand-navy/10 dark:ring-white/10 focus:ring-2 focus:ring-brand-gold"
            />
            <div class="mt-1 flex items-start justify-between gap-3">
              <p class="text-xs text-brand-navy/55 dark:text-white/50">Sent to {{ agentName }} as a notification and kept in Activity.</p>
              <span class="flex-none text-xs tabular-nums" :class="reason.trim().length >= REASON_MIN ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/40 dark:text-white/35'">{{ reason.trim().length }}/500</span>
            </div>

            <label v-if="mode === 'delete'" class="mt-5 block">
              <span class="mb-1.5 block text-xs font-semibold text-brand-navy/70 dark:text-white/65">
                Type <span class="font-mono font-bold tracking-wider text-red-600 dark:text-red-400">DELETE</span> to confirm
              </span>
              <input v-model="typed" type="text" autocomplete="off" spellcheck="false" placeholder="DELETE" :class="DIALOG_FIELD" class="font-mono tracking-wider uppercase" :disabled="sending" />
            </label>

            <p v-if="error" class="mt-4 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm text-red-700 ring-1 ring-red-500/20 dark:text-red-300" role="alert">{{ error }}</p>
          </form>

    <template #footer>
      <button type="button" :class="DIALOG_BTN.cancel" :disabled="sending" @click="close">Cancel</button>
      <button type="button" :class="mode === 'unpublish' ? DIALOG_BTN.warning : DIALOG_BTN.danger" :disabled="!ready || sending" @click="submit">
        <svg v-if="sending" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
        {{ sending ? (mode === 'unpublish' ? 'Unpublishing…' : 'Deleting…') : (mode === 'unpublish' ? 'Unpublish and notify' : 'Delete and notify') }}
      </button>
    </template>
  </AppDialog>
</template>
