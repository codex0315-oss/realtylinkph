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
  if (!props.property || reason.value.trim().length < REASON_MIN || sending.value) return
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

function close() { if (!sending.value) emit('close') }
function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && props.open) close() }
onMounted(() => window.addEventListener('keydown', onKey))
onUnmounted(() => window.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <Transition name="lm-backdrop">
      <div v-if="open && property" class="fixed inset-0 z-[95] flex items-end sm:items-center justify-center sm:p-4 bg-brand-navy-deep/60 backdrop-blur-sm" @click.self="close">
        <Transition name="lm-panel" appear>
          <form
            v-if="open"
            role="dialog"
            aria-modal="true"
            aria-labelledby="lm-title"
            class="relative w-full sm:max-w-lg max-h-[92vh] overflow-y-auto rounded-t-[1.75rem] sm:rounded-[1.75rem] p-6 sm:p-7
                   bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 shadow-[0_40px_100px_-30px_rgba(6,24,58,0.6)]"
            @submit.prevent="submit"
          >
            <button
              v-if="!sending"
              type="button"
              class="absolute top-4 right-4 h-9 w-9 rounded-full flex items-center justify-center text-brand-navy/60 hover:text-brand-navy hover:bg-brand-navy/5 dark:text-white/60 dark:hover:text-white dark:hover:bg-white/10 transition"
              aria-label="Close"
              @click="close"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>

            <p class="text-[0.6875rem] font-bold uppercase tracking-[0.16em]" :class="mode === 'unpublish' ? 'text-amber-700 dark:text-amber-400' : 'text-red-600 dark:text-red-400'">
              {{ mode === 'unpublish' ? 'Unpublish listing' : 'Delete listing' }}
            </p>
            <h2 id="lm-title" class="mt-1 pr-10 font-heading text-2xl font-semibold tracking-[-0.01em] text-brand-navy dark:text-white">
              {{ mode === 'unpublish' ? 'Take this listing down?' : 'Delete this listing for good?' }}
            </h2>

            <!-- Which listing -->
            <div class="mt-5 flex items-center gap-3 rounded-2xl p-2.5 pr-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
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

            <p v-if="error" class="mt-4 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400" role="alert">{{ error }}</p>

            <div class="mt-6 flex flex-col-reverse sm:flex-row gap-3">
              <button type="button" class="flex-1 rounded-full px-5 py-3 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition" :disabled="sending" @click="close">Cancel</button>
              <button
                type="submit"
                class="flex-1 inline-flex items-center justify-center gap-2 rounded-full px-5 py-3 text-sm font-bold transition disabled:opacity-50 disabled:shadow-none"
                :class="mode === 'unpublish'
                  ? 'bg-brand-gold text-[#0B2A5C] shadow-[0_4px_14px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light'
                  : 'bg-red-600 text-white shadow-[0_4px_14px_rgba(220,38,38,0.35)] hover:bg-red-700'"
                :disabled="reason.trim().length < REASON_MIN || sending"
              >
                <svg v-if="sending" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.25" stroke-width="3" /><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                {{ sending ? (mode === 'unpublish' ? 'Unpublishing…' : 'Deleting…') : (mode === 'unpublish' ? 'Unpublish and notify' : 'Delete and notify') }}
              </button>
            </div>
          </form>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.lm-backdrop-enter-active, .lm-backdrop-leave-active { transition: opacity 0.22s ease; }
.lm-backdrop-enter-from, .lm-backdrop-leave-to { opacity: 0; }
.lm-panel-enter-active { transition: opacity 0.25s ease, transform 0.35s cubic-bezier(0.22, 1, 0.36, 1); }
.lm-panel-enter-from { opacity: 0; transform: translateY(24px) scale(0.98); }
@media (prefers-reduced-motion: reduce) { .lm-panel-enter-active { transition: none; } }
</style>
