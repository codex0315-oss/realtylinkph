<script setup lang="ts">
import type { ApiResponse, User } from '~/types'

/**
 * An agent's profile cover — the one component every place uses to draw it
 * (public profile, My Profile, directory cards, "Listed by" on listings), so
 * the photo and its focal point look the same everywhere.
 *
 * No photo → the default navy banner with a gold glow.
 *
 * `editable` (My Profile — an agent's or buyer's own cover) adds the controls:
 *  - Add / Change cover: pick a file → it previews at once and drops straight
 *    into reposition mode, so the agent frames it before anything uploads;
 *  - Reposition: drag the photo up or down (or use ↑ ↓) to choose what the
 *    banner shows — saved as a 0–100 % vertical focal point;
 *  - Remove, behind a confirm.
 * Covers go live as soon as they're saved.
 *
 * Height, rounding and anything layered on top come from the parent (class +
 * default slot).
 */
const props = withDefaults(defineProps<{
  src?: string | null
  position?: number
  editable?: boolean
  /** Compact strips (cards) get a smaller glow and no texture. */
  compact?: boolean
}>(), { src: null, position: 50, editable: false, compact: false })

const authStore = useAuthStore()
const api = useApi()
const toast = useToast()
const confirm = useConfirm()

const MAX_MB = 5
const box = ref<HTMLElement | null>(null)
const img = ref<HTMLImageElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const repositioning = ref(false)
const busy = ref(false)
const pendingFile = ref<File | null>(null)
const previewUrl = ref<string | null>(null)
const draftPos = ref(props.position)

const shownSrc = computed(() => previewUrl.value ?? props.src)
const shownPos = computed(() => (repositioning.value ? draftPos.value : props.position))

watch(() => props.position, (p) => { if (!repositioning.value) draftPos.value = p })

/* ── Pick a file ─────────────────────────────────────────────────────── */
function pickFile() { fileInput.value?.click() }
function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''   // picking the same file again still fires change
  if (!file) return
  if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { toast.error('Use a JPG, PNG or WebP image.'); return }
  if (file.size > MAX_MB * 1024 * 1024) { toast.error(`That image is over ${MAX_MB} MB. Try a smaller one.`); return }
  clearPreview()
  pendingFile.value = file
  previewUrl.value = URL.createObjectURL(file)
  draftPos.value = 50
  repositioning.value = true
}
function clearPreview() {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = null
  pendingFile.value = null
}
onUnmounted(clearPreview)

/* ── Drag to reposition ─────────────────────────────────────────────────
 * object-fit: cover scales the photo to the banner's width; whatever is
 * taller than the banner is the range the focal point can travel. Dragging
 * the photo down shows more of its top, so position moves the other way. */
let dragStartY = 0
let dragStartPos = 50
let travel = 0
function startReposition() { draftPos.value = props.position; repositioning.value = true }
function onPointerDown(e: PointerEvent) {
  if (!repositioning.value || !box.value || !img.value) return
  const { naturalWidth: w, naturalHeight: h } = img.value
  const rendered = w ? box.value.clientWidth * (h / w) : 0
  travel = Math.max(0, rendered - box.value.clientHeight)
  if (!travel) return  // photo no taller than the banner — nothing to move
  dragStartY = e.clientY
  dragStartPos = draftPos.value
  box.value.setPointerCapture(e.pointerId)
}
function onPointerMove(e: PointerEvent) {
  if (!repositioning.value || !box.value?.hasPointerCapture(e.pointerId) || !travel) return
  const dy = e.clientY - dragStartY
  draftPos.value = Math.round(Math.min(100, Math.max(0, dragStartPos - (dy / travel) * 100)))
}
function onKey(e: KeyboardEvent) {
  if (!repositioning.value) return
  if (e.key === 'ArrowUp')   { e.preventDefault(); draftPos.value = Math.max(0, draftPos.value - 5) }
  if (e.key === 'ArrowDown') { e.preventDefault(); draftPos.value = Math.min(100, draftPos.value + 5) }
  if (e.key === 'Escape') cancel()
}

/* ── Save / cancel / remove ─────────────────────────────────────────── */
function cancel() {
  clearPreview()
  draftPos.value = props.position
  repositioning.value = false
}

async function save() {
  busy.value = true
  try {
    let res: ApiResponse<User>
    if (pendingFile.value) {
      const fd = new FormData()
      fd.append('cover', pendingFile.value)
      fd.append('position', String(draftPos.value))
      res = await api.postForm<ApiResponse<User>>('/me/cover', fd)
    } else {
      res = await api.put<ApiResponse<User>>('/me/cover/position', { position: draftPos.value })
    }
    authStore.setUser(res.data)
    toast.success(pendingFile.value ? 'Cover photo updated' : 'Cover position saved')
    clearPreview()
    repositioning.value = false
  } catch (e: unknown) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
    toast.error(data?.errors ? Object.values(data.errors)[0]?.[0] ?? 'Could not save the cover.' : data?.message ?? 'Could not save the cover.')
  } finally {
    busy.value = false
  }
}

async function remove() {
  const ok = await confirm({
    title: 'Remove your cover photo?',
    message: 'Your profile will show the default banner instead. You can add a new cover any time.',
    confirmLabel: 'Remove cover',
    tone: 'danger',
  })
  if (!ok) return
  busy.value = true
  try {
    const res = await api.del<ApiResponse<User>>('/me/cover')
    authStore.setUser(res.data)
    toast.success('Cover photo removed')
  } catch {
    toast.error('Could not remove the cover.')
  } finally {
    busy.value = false
  }
}

const ctrl =
  'inline-flex items-center gap-1.5 rounded-full px-3.5 py-2 text-xs font-semibold text-white ' +
  'bg-black/45 ring-1 ring-white/25 backdrop-blur-md hover:bg-black/60 transition disabled:opacity-50'
</script>

<template>
  <div
    ref="box"
    class="relative overflow-hidden isolate"
    :class="repositioning ? 'cursor-grab active:cursor-grabbing touch-none select-none' : ''"
    :tabindex="repositioning ? 0 : undefined"
    :aria-label="repositioning ? 'Drag the cover photo up or down to reposition it. Arrow keys work too.' : undefined"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @keydown="onKey"
  >
    <!-- Photo -->
    <img
      v-if="shownSrc"
      ref="img"
      :src="shownSrc"
      alt=""
      draggable="false"
      class="absolute inset-0 -z-10 h-full w-full object-cover"
      :class="repositioning ? '' : 'transition-[object-position] duration-500'"
      :style="{ objectPosition: `50% ${shownPos}%` }"
    />
    <!-- Default banner -->
    <div v-else class="absolute inset-0 -z-10 bg-gradient-to-br from-brand-navy via-brand-navy-mid to-brand-navy-deep">
      <div v-if="!compact" class="absolute inset-0 bg-grid-gold opacity-[0.08]" />
      <div class="absolute -right-10 -top-16 rounded-full bg-brand-gold/30" :class="compact ? 'h-24 w-24 blur-[40px]' : 'h-56 w-56 blur-[80px]'" />
      <div v-if="!compact" class="absolute left-1/3 -bottom-20 h-48 w-48 rounded-full bg-brand-navy-light/30 blur-[80px]" />
    </div>
    <!-- A soft bottom fade so overlapping content and controls always read -->
    <div v-if="shownSrc" class="absolute inset-0 -z-10 bg-gradient-to-t from-black/30 via-transparent to-black/10 pointer-events-none" />

    <slot />

    <!-- ── Editing controls ── -->
    <template v-if="editable">
      <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="hidden" @change="onFile" />

      <!-- Reposition mode -->
      <template v-if="repositioning">
        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
          <span class="inline-flex items-center gap-2 rounded-full bg-black/55 px-4 py-2 text-xs font-semibold text-white backdrop-blur-md">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 15L12 18.75 15.75 15m-7.5-6L12 5.25 15.75 9" /></svg>
            Drag to reposition
          </span>
        </div>
        <div class="absolute top-3 right-3 flex gap-2" @pointerdown.stop>
          <button type="button" :class="ctrl" :disabled="busy" @click="cancel">Cancel</button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-xs font-bold text-brand-navy bg-brand-gold shadow-lg hover:bg-brand-gold-light transition disabled:opacity-60"
            :disabled="busy"
            @click="save"
          >
            <span v-if="busy" class="h-3.5 w-3.5 rounded-full border-2 border-brand-navy/40 border-t-brand-navy animate-spin" aria-hidden="true" />
            {{ busy ? 'Saving…' : pendingFile ? 'Save cover' : 'Save position' }}
          </button>
        </div>
      </template>

      <!-- Idle -->
      <div v-else class="absolute top-3 right-3 flex flex-wrap justify-end gap-2">
        <button type="button" :class="ctrl" :disabled="busy" @click="pickFile">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" /></svg>
          {{ src ? 'Change cover' : 'Add cover photo' }}
        </button>
        <template v-if="src">
          <button type="button" :class="ctrl" :disabled="busy" @click="startReposition">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5" /></svg>
            Reposition
          </button>
          <button type="button" :class="ctrl" :disabled="busy" aria-label="Remove cover photo" @click="remove">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
          </button>
        </template>
      </div>

      <!-- Uploading -->
      <div v-if="busy && pendingFile" class="absolute inset-x-0 bottom-0 h-1 overflow-hidden bg-white/20">
        <div class="h-full w-1/3 bg-brand-gold cover-progress" />
      </div>
    </template>
  </div>
</template>

<style scoped>
.cover-progress { animation: cover-progress 1.1s ease-in-out infinite; }
@keyframes cover-progress {
  from { transform: translateX(-100%); }
  to   { transform: translateX(300%); }
}
@media (prefers-reduced-motion: reduce) {
  .cover-progress { animation: none; width: 100%; opacity: 0.6; }
}
</style>
