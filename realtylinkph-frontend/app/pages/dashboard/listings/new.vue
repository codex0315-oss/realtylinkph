<script setup lang="ts">
import type { PropertyType, OfferType, Property } from '~/types'
import { PROPERTY_TYPES } from '~/types'

definePageMeta({ layout: 'dashboard' })

const router = useRouter()
const route  = useRoute()
const api    = useApi()
const { createProperty, updateProperty, deleteProperty, uploadPhoto, deletePhoto, generateDescription, confirmPriceIfOdd, loading, error } = useProperty()

const step = ref(1)
const steps = [
  { n: 1, label: 'Property Photos', desc: 'The real photos buyers will see' },
  { n: 2, label: 'Virtual Tour',    desc: '360° / panorama shots · optional' },
  { n: 3, label: 'Listing Details',  desc: 'Title, price & description' },
]

const form = reactive({
  title:       '',
  description: '',
  price:       0,
  type:        'house' as PropertyType,
  offer_type:  'sale' as OfferType,
  bedrooms:    undefined as number | undefined,
  bathrooms:   undefined as number | undefined,
  floor_area:  undefined as number | undefined,
  lot_area:    undefined as number | undefined,
  address:     '',
})

/* ── Draft ──────────────────────────────────────────────────────────────────
 * Nothing lives only in the browser. The moment the agent adds a photo or
 * types a field, a draft row is created and every photo goes straight to the
 * server; fields autosave as they change. A refresh, a closed tab or a
 * wrong click loses nothing — the wizard resumes from the draft, and it also
 * shows in My Listings as "Continue". The old flow held everything in memory
 * until the last button, so any interruption threw all of it away.
 */
const DRAFT_KEY = 'rl-listing-draft'

const draftId   = ref<number | null>(null)
const resumed   = ref(false)
const saveState = ref<'idle' | 'pending' | 'saving' | 'saved' | 'error'>('idle')
const savedAt   = ref<Date | null>(null)
/** Set when leaving on purpose (finished, saved, discarded) so the guard stands down. */
let leavingOnPurpose = false
let dirty      = false
let hydrating  = false
let saveTimer: ReturnType<typeof setTimeout> | null = null
let creating: Promise<number | null> | null = null

function payload() {
  return {
    title:       form.title,
    description: form.description,
    price:       form.price,
    type:        form.type,
    offer_type:  form.offer_type,
    bedrooms:    form.bedrooms,
    bathrooms:   form.bathrooms,
    floor_area:  form.floor_area,
    lot_area:    form.lot_area,
    address:     form.address,
  }
}

/** True once the agent has typed anything worth keeping (type/offer have defaults, so they don't count). */
function hasInput(): boolean {
  return !!form.title.trim() || !!form.address.trim() || !!form.description.trim()
    || form.price > 0 || form.bedrooms !== undefined || form.bathrooms !== undefined
    || form.floor_area !== undefined || form.lot_area !== undefined
}

/** Create the draft row once. Concurrent callers (several photos added at once) share one request. */
async function ensureDraft(): Promise<number | null> {
  if (draftId.value) return draftId.value
  if (creating) return creating
  creating = (async () => {
    const p = await createProperty(payload())
    creating = null
    if (!p) return null
    draftId.value = p.id
    dirty = false
    saveState.value = 'saved'
    savedAt.value = new Date()
    try { localStorage.setItem(DRAFT_KEY, String(p.id)) } catch { /* private mode — draft still exists on the server */ }
    return p.id
  })()
  return creating
}

/** Push pending field changes now. Resolves true when nothing is left unsaved. */
async function flushSave(): Promise<boolean> {
  if (saveTimer) { clearTimeout(saveTimer); saveTimer = null }
  if (!dirty) return true
  if (!draftId.value) {
    if (!hasInput()) { dirty = false; return true }
    return (await ensureDraft()) !== null
  }
  saveState.value = 'saving'
  const ok = await updateProperty(draftId.value, payload())
  if (ok) { dirty = false; saveState.value = 'saved'; savedAt.value = new Date() }
  else saveState.value = 'error'
  return !!ok
}

watch(form, () => {
  if (hydrating) return
  dirty = true
  saveState.value = 'pending'
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(flushSave, 800)
}, { deep: true })

const saveLabel = computed(() => {
  switch (saveState.value) {
    case 'pending': return 'Unsaved changes'
    case 'saving':  return 'Saving…'
    case 'saved':   return savedAt.value ? `Draft saved · ${savedAt.value.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}` : 'Draft saved'
    case 'error':   return 'Could not save — will retry on your next change'
    default:        return ''
  }
})

/* ── Photos — uploaded as they're added ── */
interface Shot { id: number; url: string; is360: boolean; file?: File; uploading?: boolean }
const gallery = ref<Shot[]>([])
const panos   = ref<Shot[]>([])

/** Photos rejected before upload, or that failed during it. */
const photoError = ref('')

async function addFiles(e: Event, target: 'photo' | 'pano') {
  const input  = e.target as HTMLInputElement
  const files  = Array.from(input.files ?? [])
  input.value  = ''
  await addFileList(files, target)
}

/* Drag and drop onto the upload area. */
const dragOver = ref<'photo' | 'pano' | null>(null)
function onDrop(e: DragEvent, target: 'photo' | 'pano') {
  dragOver.value = null
  const files = Array.from(e.dataTransfer?.files ?? []).filter(f => f.type.startsWith('image/'))
  if (files.length) addFileList(files, target)
}

async function addFileList(files: File[], target: 'photo' | 'pano') {
  const tooBig = files.filter(f => f.size > MAX_PHOTO_BYTES)
  const ok     = files.filter(f => f.size <= MAX_PHOTO_BYTES)
  photoError.value = tooBig.length ? `Skipped ${tooBig.map(f => f.name).join(', ')} — over ${MAX_PHOTO_LABEL}.` : ''
  if (!ok.length) return

  const id = await ensureDraft()
  if (!id) { photoError.value = error.value || 'Could not start your draft. Check your connection and try again.'; return }

  const list = target === 'pano' ? panos : gallery
  const failed: string[] = []
  for (const f of ok) {
    // Show it immediately from the local file; the upload runs behind it.
    const shot: Shot = { id: -Math.random(), url: URL.createObjectURL(f), is360: target === 'pano', file: f, uploading: true }
    list.value.push(shot)
    const saved = await uploadPhoto(id, f, gallery.value.length + panos.value.length - 1, target === 'pano')
    const i = list.value.indexOf(shot)
    if (saved) list.value[i] = { ...shot, id: saved.id, uploading: false }
    else { list.value.splice(i, 1); failed.push(f.name) }
  }
  if (failed.length) photoError.value = `${failed.join(', ')} could not be uploaded.${error.value ? ` ${error.value}` : ''}`
}

async function removeShot(list: Ref<Shot[]>, i: number) {
  const shot = list.value[i]
  if (!shot || shot.uploading) return
  list.value.splice(i, 1)
  if (shot.id > 0 && draftId.value) await deletePhoto(draftId.value, shot.id)
}
const removePhoto = (i: number) => removeShot(gallery, i)
const removePano  = (i: number) => removeShot(panos, i)

/* ── Resume ── */
async function resume(id: number): Promise<boolean> {
  try {
    const res = await api.get<{ data: Property }>(`/properties/${id}`)
    const p = res.data
    if (!p || p.status !== 'draft') return false
    hydrating = true
    draftId.value = id
    Object.assign(form, {
      title:       p.title ?? '',
      description: p.description ?? '',
      price:       Number(p.price) || 0,
      type:        p.type,
      offer_type:  p.offer_type ?? 'sale',
      bedrooms:    p.bedrooms || undefined,
      bathrooms:   p.bathrooms || undefined,
      floor_area:  p.floor_area != null ? Number(p.floor_area) : undefined,
      lot_area:    p.lot_area   != null ? Number(p.lot_area)   : undefined,
      address:     p.address ?? '',
    })
    const photos = p.photos ?? []
    gallery.value = photos.filter(ph => !ph.is_360).map(ph => ({ id: ph.id, url: ph.url, is360: false }))
    panos.value   = photos.filter(ph =>  ph.is_360).map(ph => ({ id: ph.id, url: ph.url, is360: true }))
    await nextTick()
    hydrating = false
    dirty = false
    saveState.value = 'saved'
    resumed.value = true
    // Land where they most likely left off.
    step.value = form.title || form.address || form.price ? 3 : gallery.value.length ? 2 : 1
    try { localStorage.setItem(DRAFT_KEY, String(id)) } catch { /* ignore */ }
    return true
  } catch {
    hydrating = false
    return false
  }
}

/** "Start a new listing instead" — the resumed draft stays saved in My Listings. */
function startFresh() {
  try { localStorage.removeItem(DRAFT_KEY) } catch { /* ignore */ }
  draftId.value = null
  resumed.value = false
  hydrating = true
  Object.assign(form, { title: '', description: '', price: 0, type: 'house', offer_type: 'sale', bedrooms: undefined, bathrooms: undefined, floor_area: undefined, lot_area: undefined, address: '' })
  gallery.value = []; panos.value = []
  nextTick(() => { hydrating = false; dirty = false; saveState.value = 'idle' })
  step.value = 1
}

if (import.meta.client) {
  let stored: number | null = null
  try { stored = Number(localStorage.getItem(DRAFT_KEY)) || null } catch { /* ignore */ }
  const wanted = Number(route.query.draft) || stored
  if (wanted && !(await resume(wanted))) {
    try { localStorage.removeItem(DRAFT_KEY) } catch { /* ignore */ }
  }
}

/* ── AI description (multimodal — scans the uploaded photos) ── */
const aiLoading = ref(false)
const aiError   = ref('')
async function doGenerateDescription() {
  const shots = [...gallery.value, ...panos.value]
  if (!shots.length && !form.address.trim()) {
    aiError.value = 'Add a few photos (step 1) or fill in the address first.'
    return
  }
  aiLoading.value = true
  aiError.value   = ''
  // Files are still in memory during this visit; after a resume the API reads
  // the draft's photos from storage via property_id instead.
  const desc = await generateDescription({
    type:        form.type,
    offer_type:  form.offer_type,
    price:       form.price || undefined,
    bedrooms:    form.bedrooms,
    bathrooms:   form.bathrooms,
    floor_area:  form.floor_area,
    lot_area:    form.lot_area,
    address:     form.address,
    property_id: draftId.value ?? undefined,
  },
  // Photos already uploaded to the draft are read on the server, so nothing is
  // re-sent (and a big photo can't trip the 5 MB limit for AI uploads). Only
  // without a draft do the files go along: up to 6, each under 5 MB.
  draftId.value ? [] : shots.map(s => s.file).filter((f): f is File => !!f && f.size <= 5 * 1024 * 1024).slice(0, 6))
  aiLoading.value = false
  if (desc) form.description = desc
  else aiError.value = 'Could not generate a description. The AI may be busy — try again.'
}

/* ── Finish ── */
async function submit() {
  if (!await confirmPriceIfOdd(form.price, form.offer_type)) return
  dirty = true
  if (!await flushSave() || !draftId.value) return
  leavingOnPurpose = true
  try { localStorage.removeItem(DRAFT_KEY) } catch { /* ignore */ }
  router.push('/dashboard/listings')
}

const canSubmit = computed(() =>
  !!form.title.trim() && !!form.address.trim() && form.price > 0,
)

/* ── Fit the step column to the window (desktop): the step scrolls inside,
   Back / Continue stay on screen. ── */
const stepCol = ref<HTMLElement | null>(null)
const stepColHeight = ref<string | undefined>()
function fitStepCol() {
  if (!stepCol.value || window.innerWidth < 1024) { stepColHeight.value = undefined; return }
  const top = stepCol.value.getBoundingClientRect().top + window.scrollY
  // Never smaller than 36rem: on a short window the heading, stepper and banner
  // leave almost nothing, and a step squeezed into that is unusable. Better that
  // the page scrolls a little than that the form or drop box collapses.
  const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16
  stepColHeight.value = `${Math.max(Math.round(36 * rem), Math.floor(window.innerHeight - top - 24))}px`
}
/* Re-fit when the room changes too (a panel opening narrows <main> and moves the step down). */
let mainObserver: ResizeObserver | null = null
onMounted(() => {
  nextTick(fitStepCol)
  window.addEventListener('resize', fitStepCol)
  const main = stepCol.value?.closest('main')
  if (main) { mainObserver = new ResizeObserver(() => fitStepCol()); mainObserver.observe(main) }
})
onUnmounted(() => { window.removeEventListener('resize', fitStepCol); mainObserver?.disconnect() })
watch(resumed, () => nextTick(fitStepCol))

/* ── Live preview ── */
const priceText = computed(() => listingPriceText(form.price, form.offer_type))
const coverUrl = computed(() => gallery.value[0]?.url ?? null)
/* An empty box (not "0") until a price is typed, so the placeholder shows. */
const priceInput = computed({
  get: () => (form.price > 0 ? form.price : ''),
  set: (v: string | number) => { form.price = Number(v) || 0 },
})
/* Preview: beside the form when there's room, else in a dialog from the Preview button */
const previewOpen = ref(false)
const previewProps = computed(() => ({
  image: coverUrl.value,
  offer: form.offer_type,
  price: priceText.value,
  hasPrice: form.price > 0,
  title: form.title,
  address: form.address,
  chips: [PROPERTY_TYPES[form.type], form.bedrooms ? `${form.bedrooms} bed` : '', form.bathrooms ? `${form.bathrooms} bath` : '', form.floor_area ? `${form.floor_area} sqm` : ''].filter(Boolean) as string[],
  tour: panos.value.length > 0,
}))
const TYPE_ICONS = PROPERTY_TYPE_ICONS
const fieldCls = LISTING_FIELD_CLS
const labelCls = LISTING_LABEL_CLS

/* ── Leaving mid-listing ── */
const leaveModal  = ref(false)
const leaveBusy   = ref(false)
/* "Discard" needs a second tap: it deletes the photos as well. */
const discardArmed = ref(false)
function closeLeave() { leaveModal.value = false; discardArmed.value = false }
let leaveTarget   = '/dashboard/listings'

onBeforeRouteLeave((to) => {
  if (leavingOnPurpose) return true
  if (!draftId.value && !dirty) return true
  leaveTarget = to.fullPath
  discardArmed.value = false
  leaveModal.value = true
  return false
})

async function leaveSaving() {
  leaveBusy.value = true
  const ok = await flushSave()
  leaveBusy.value = false
  if (!ok) return
  leavingOnPurpose = true
  leaveModal.value = false
  router.push(leaveTarget)
}
async function leaveDiscarding() {
  leaveBusy.value = true
  if (draftId.value) await deleteProperty(draftId.value)
  try { localStorage.removeItem(DRAFT_KEY) } catch { /* ignore */ }
  leaveBusy.value = false
  leavingOnPurpose = true
  leaveModal.value = false
  router.push(leaveTarget)
}

// The route guard can't see a refresh or a closed tab. Photos are already on
// the server and fields save within a second of typing, so this only fires in
// the small window where a change hasn't reached the server yet.
function onBeforeUnload(e: BeforeUnloadEvent) {
  if (dirty || saveState.value === 'saving') { e.preventDefault(); e.returnValue = '' }
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
onUnmounted(() => {
  window.removeEventListener('beforeunload', onBeforeUnload)
  if (saveTimer) clearTimeout(saveTimer)
  ;[...gallery.value, ...panos.value].forEach(s => { if (s.url.startsWith('blob:')) URL.revokeObjectURL(s.url) })
})
</script>

<template>
  <div class="page-shell pb-24 lg:pb-0">
    <!-- ── Heading ── -->
    <header class="mb-5 flex flex-col gap-4 cw-xl:flex-row cw-xl:items-end cw-xl:justify-between">
      <div class="min-w-0">
        <NuxtLink to="/dashboard/listings" class="inline-flex items-center gap-1 text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 hover:text-brand-gold-deep dark:text-white/40 dark:hover:text-brand-gold transition">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
          My listings
        </NuxtLink>
        <div class="mt-1.5 flex flex-wrap items-center gap-3">
        <h1 class="font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">{{ resumed ? 'Continue listing' : 'New listing' }}</h1>
      <!-- Autosave status: the answer to "did that save?" without asking -->
      <Transition name="fade">
        <span
          v-if="saveLabel"
          class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ring-1"
          :class="saveState === 'error' ? 'bg-red-500/10 text-red-700 ring-red-500/20 dark:text-red-300' : saveState === 'saved' ? 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-400' : 'bg-brand-navy/[0.04] text-brand-navy/60 ring-brand-navy/10 dark:bg-white/[0.06] dark:text-white/60 dark:ring-white/10'"
        >
          <svg v-if="saveState === 'saving'" class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
          <svg v-else-if="saveState === 'saved'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
          {{ saveLabel }}
        </span>
      </Transition>
        </div>
      </div>

      <!-- ── Stepper (beside the title on desktop, to keep Continue in view) ── -->
      <div class="w-full cw-xl:w-auto">
        <!-- Phones: the step's name, since the stepper only has room for numbers -->
        <p class="cw-sm:hidden mb-2 text-xs font-semibold text-brand-navy/60 dark:text-white/55">
          Step {{ step }} of 3 · <span class="text-brand-navy dark:text-white">{{ steps[step - 1]!.label }}</span>
        </p>
        <ol class="grid grid-cols-3 gap-1.5 rounded-3xl p-1.5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] cw-xl:w-[34rem]">
          <li v-for="s in steps" :key="s.n">
            <button
              type="button"
              class="w-full flex items-center justify-center cw-sm:justify-start gap-2 cw-sm:gap-2.5 rounded-2xl px-2 cw-sm:px-2.5 py-2 text-left transition"
              :class="step === s.n ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05]'"
              :aria-current="step === s.n ? 'step' : undefined"
              :title="s.desc"
              @click="step = s.n"
            >
              <span
                class="h-7 w-7 flex-none rounded-full flex items-center justify-center text-xs font-bold transition"
                :class="step === s.n ? 'bg-white/15 text-white dark:bg-[#0B2A5C]/15 dark:text-[#0B2A5C]' : step > s.n ? 'bg-emerald-500 text-white' : 'bg-brand-navy/[0.06] text-brand-navy/55 dark:bg-white/10 dark:text-white/55'"
              >
                <svg v-if="step > s.n" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                <span v-else>{{ s.n }}</span>
              </span>
              <span class="hidden cw-sm:block min-w-0 truncate text-sm font-semibold" :class="step === s.n ? '' : 'text-brand-navy dark:text-white'">{{ s.label }}</span>
            </button>
          </li>
        </ol>
      </div>
    </header>

    <!-- Resumed draft -->
    <div v-if="resumed" class="mb-5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl bg-brand-gold/[0.08] ring-1 ring-brand-gold/25 px-4 py-3 text-sm text-brand-navy/80 dark:text-white/75">
      <span class="font-semibold text-brand-navy dark:text-white">Picking up your unfinished listing.</span>
      <span>This one stays saved in My Listings.</span>
      <button type="button" class="font-bold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="startFresh">Start a new listing instead</button>
    </div>

    <div class="grid grid-cols-1 gap-6 cw-lg:grid-cols-[minmax(0,1fr)_20rem] cw-2xl:grid-cols-[minmax(0,1fr)_24rem] items-start">
      <!-- ░░ Step content: fills the window on desktop, so Continue is always in view ░░ -->
      <div ref="stepCol" class="min-w-0 lg:flex lg:flex-col" :style="stepColHeight ? { height: stepColHeight } : undefined">
        <p v-if="error" class="mb-4 rounded-2xl bg-red-500/[0.07] ring-1 ring-red-500/20 px-4 py-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>
        <p v-if="photoError" class="mb-4 rounded-2xl bg-amber-500/[0.08] ring-1 ring-amber-500/20 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">{{ photoError }}</p>

        <!-- ── Step 1: Photos ── -->
        <section v-show="step === 1" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5 sm:p-7 lg:flex-1 lg:min-h-0 lg:flex lg:flex-col">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Property photos</h2>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Clear, well-lit photos of the actual property. The first one is the cover buyers see first.</p>

          <!-- One box: the drop area, and the photos inside it. It scrolls on its own,
               so adding photos never pushes the page (or Continue) down. -->
          <div
            class="mt-5 rounded-3xl border-2 border-dashed p-3 transition min-h-[12rem] lg:flex-1 lg:min-h-[12rem] overflow-y-auto overscroll-contain dark:[color-scheme:dark]"
            :class="dragOver === 'photo' ? 'border-brand-gold bg-brand-gold/[0.08]' : 'border-brand-navy/15 dark:border-white/15'"
            @dragover.prevent="dragOver = 'photo'"
            @dragleave.prevent="dragOver = null"
            @drop.prevent="onDrop($event, 'photo')"
          >
            <label v-if="!gallery.length" class="flex h-full min-h-[14rem] cursor-pointer flex-col items-center justify-center rounded-2xl px-6 py-8 text-center hover:bg-brand-gold/[0.04] transition">
              <span class="mb-3 h-14 w-14 rounded-2xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" /></svg>
              </span>
              <span class="font-semibold text-brand-navy dark:text-white">Drag photos here, or <span class="text-brand-gold-deep dark:text-brand-gold underline underline-offset-4">browse</span></span>
              <span class="mt-1 text-xs text-brand-navy/50 dark:text-white/45">JPG, PNG or WebP · up to {{ MAX_PHOTO_LABEL }} each · add several at once</span>
              <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'photo')" />
            </label>

            <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(8.5rem,1fr))] gap-3">
              <div v-for="(shot, i) in gallery" :key="shot.id" class="group relative aspect-[4/3] overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.07] dark:ring-white/10">
                <img :src="shot.url" alt="" class="h-full w-full object-cover transition-opacity" :class="shot.uploading ? 'opacity-50' : ''" />
                <span v-if="shot.uploading" class="absolute inset-0 flex items-center justify-center">
                  <svg class="animate-spin h-6 w-6 text-white drop-shadow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                </span>
                <span v-if="i === 0" class="absolute top-2 left-2 rounded-full bg-brand-gold px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C] shadow">Cover</span>
                <button
                  v-if="!shot.uploading"
                  type="button"
                  class="absolute top-2 right-2 h-7 w-7 rounded-full bg-black/60 text-white flex items-center justify-center opacity-100 [@media(hover:hover)]:opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition"
                  :aria-label="`Remove photo ${i + 1}`"
                  @click="removePhoto(i)"
                >
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <!-- Add more, as the last tile -->
              <label class="aspect-[4/3] flex cursor-pointer flex-col items-center justify-center rounded-2xl bg-brand-gold/[0.06] ring-1 ring-brand-gold/30 text-brand-gold-deep hover:bg-brand-gold/[0.12] dark:text-brand-gold transition">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                <span class="mt-1 text-xs font-bold">Add more</span>
                <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'photo')" />
              </label>
            </div>
          </div>
          <p class="mt-3 text-xs text-brand-navy/50 dark:text-white/45">{{ gallery.length }} {{ gallery.length === 1 ? 'photo' : 'photos' }} added · drop more onto the box any time · saved as you go</p>
        </section>

        <!-- ── Step 2: Virtual tour ── -->
        <section v-show="step === 2" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5 sm:p-7 lg:flex-1 lg:min-h-0 lg:flex lg:flex-col">
          <div class="flex items-center gap-2">
            <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Virtual tour</h2>
            <span class="rounded-full bg-brand-navy/[0.06] dark:bg-white/10 px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/55 dark:text-white/55">Optional</span>
          </div>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Add 360° panoramas and buyers can look around each room on your listing page.</p>

          <p class="mt-4 flex items-start gap-2.5 rounded-2xl bg-brand-gold/[0.07] ring-1 ring-brand-gold/25 p-3.5 text-xs leading-relaxed text-brand-navy/75 dark:text-white/70">
            <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" /></svg>
            <span><span class="font-semibold text-brand-navy dark:text-white">Use 2:1 wrap-around 360° shots</span> from a 360° camera or app (Insta360, Google Street View…). Regular photos upload too, but look stretched in the viewer.</span>
          </p>

          <div
            class="mt-5 rounded-3xl border-2 border-dashed p-3 transition min-h-[12rem] lg:flex-1 lg:min-h-[12rem] overflow-y-auto overscroll-contain dark:[color-scheme:dark]"
            :class="dragOver === 'pano' ? 'border-brand-gold bg-brand-gold/[0.08]' : 'border-brand-navy/15 dark:border-white/15'"
            @dragover.prevent="dragOver = 'pano'"
            @dragleave.prevent="dragOver = null"
            @drop.prevent="onDrop($event, 'pano')"
          >
            <label v-if="!panos.length" class="flex h-full min-h-[12rem] cursor-pointer flex-col items-center justify-center rounded-2xl px-6 py-8 text-center hover:bg-brand-gold/[0.04] transition">
              <span class="mb-3 h-12 w-12 rounded-2xl flex items-center justify-center bg-brand-navy/[0.06] dark:bg-white/10 text-brand-navy/70 dark:text-white/70">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" /></svg>
              </span>
              <span class="font-semibold text-brand-navy dark:text-white">Drag 360° shots here, or <span class="text-brand-gold-deep dark:text-brand-gold underline underline-offset-4">browse</span></span>
              <span class="mt-1 text-xs text-brand-navy/50 dark:text-white/45">You can skip this step.</span>
              <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'pano')" />
            </label>

            <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(14rem,1fr))] gap-3">
              <div v-for="(shot, i) in panos" :key="shot.id" class="group relative aspect-[2/1] overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.07] dark:ring-white/10">
                <img :src="shot.url" alt="" class="h-full w-full object-cover transition-opacity" :class="shot.uploading ? 'opacity-50' : ''" />
                <span v-if="shot.uploading" class="absolute inset-0 flex items-center justify-center">
                  <svg class="animate-spin h-6 w-6 text-white drop-shadow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                </span>
                <span class="absolute top-2 left-2 rounded-full bg-brand-navy px-2 py-0.5 text-[0.625rem] font-bold text-brand-gold">360°</span>
                <button
                  v-if="!shot.uploading"
                  type="button"
                  class="absolute top-2 right-2 h-7 w-7 rounded-full bg-black/60 text-white flex items-center justify-center opacity-100 [@media(hover:hover)]:opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition"
                  :aria-label="`Remove panorama ${i + 1}`"
                  @click="removePano(i)"
                >
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <label class="aspect-[2/1] flex cursor-pointer flex-col items-center justify-center rounded-2xl bg-brand-gold/[0.06] ring-1 ring-brand-gold/30 text-brand-gold-deep hover:bg-brand-gold/[0.12] dark:text-brand-gold transition">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                <span class="mt-1 text-xs font-bold">Add more</span>
                <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'pano')" />
              </label>
            </div>
          </div>
          <p class="mt-3 text-xs text-brand-navy/50 dark:text-white/45">{{ panos.length }} {{ panos.length === 1 ? 'panorama' : 'panoramas' }} added</p>
        </section>

        <!-- ── Step 3: Details ── -->
        <section v-show="step === 3" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5 sm:p-7 space-y-7 lg:flex-1 lg:min-h-0 lg:overflow-y-auto overscroll-contain dark:[color-scheme:dark]">
          <div>
            <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Listing details</h2>
            <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Fields marked <span class="text-red-500">*</span> are needed before it can go live.</p>
          </div>

          <!-- Offer + type -->
          <div class="space-y-4">
            <div>
              <p :class="labelCls">This listing is <span class="text-red-500">*</span></p>
              <div class="inline-flex rounded-full p-1 bg-brand-navy/[0.04] dark:bg-white/[0.05] ring-1 ring-brand-navy/10 dark:ring-white/10">
                <button v-for="o in (['sale', 'rent'] as const)" :key="o" type="button" class="rounded-full px-5 py-2 text-sm font-semibold transition" :class="form.offer_type === o ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'text-brand-navy/65 hover:text-brand-navy dark:text-white/60 dark:hover:text-white'" @click="form.offer_type = o">
                  {{ o === 'sale' ? 'For sale' : 'For rent' }}
                </button>
              </div>
            </div>
            <div>
              <p :class="labelCls">Property type <span class="text-red-500">*</span></p>
              <div class="grid grid-cols-[repeat(auto-fit,minmax(8rem,1fr))] gap-2">
                <button
                  v-for="(label, key) in PROPERTY_TYPES"
                  :key="key"
                  type="button"
                  class="flex items-center gap-2 rounded-2xl px-3 py-2.5 text-sm font-semibold ring-1 transition"
                  :class="form.type === key ? 'bg-brand-gold/15 ring-2 ring-brand-gold text-brand-navy dark:text-white' : 'ring-brand-navy/10 text-brand-navy/70 hover:ring-brand-gold dark:ring-white/10 dark:text-white/70'"
                  @click="form.type = key"
                >
                  <svg class="h-4 w-4 flex-none" :class="form.type === key ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/40 dark:text-white/40'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="TYPE_ICONS[key] ?? TYPE_ICONS.house" /></svg>
                  {{ label }}
                </button>
              </div>
            </div>
          </div>

          <!-- Basics, and the size beside it when the card is wide -->
          <div class="grid grid-cols-1 cw-xl:grid-cols-2 gap-x-8 gap-y-7 items-start">
          <div class="grid grid-cols-1 gap-4">
            <div>
              <label for="nl-title" :class="labelCls">Title <span class="text-red-500">*</span></label>
              <input id="nl-title" v-model="form.title" type="text" maxlength="150" placeholder="Bright 3-bedroom house near schools in Quezon City" :class="fieldCls" />
            </div>
            <div>
              <label for="nl-address" :class="labelCls">Address <span class="text-red-500">*</span></label>
              <input id="nl-address" v-model="form.address" type="text" placeholder="123 Main St, Barangay, City" :class="fieldCls" />
            </div>
            <div>
              <label for="nl-price" :class="labelCls">{{ form.offer_type === 'rent' ? 'Monthly rent' : 'Price' }} <span class="text-red-500">*</span></label>
              <div class="relative">
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-semibold text-brand-navy/50 dark:text-white/50">₱</span>
                <input id="nl-price" v-model="priceInput" type="number" min="0" inputmode="numeric" placeholder="5000000" :class="[fieldCls, 'pl-9']" />
              </div>
              <p class="mt-1 text-xs" :class="form.price > 0 ? 'font-semibold text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/45 dark:text-white/40'">{{ priceText }}</p>
            </div>
          </div>

          <!-- Size -->
          <div>
            <p :class="labelCls">Size and rooms</p>
            <div class="grid grid-cols-[repeat(auto-fit,minmax(6.5rem,1fr))] gap-3">
              <div v-for="f in [
                { key: 'bedrooms', label: 'Bedrooms', ph: '3' },
                { key: 'bathrooms', label: 'Bathrooms', ph: '2' },
                { key: 'floor_area', label: 'Floor area (sqm)', ph: '120' },
                { key: 'lot_area', label: 'Lot area (sqm)', ph: '200' },
              ] as const" :key="f.key">
                <label :for="`nl-${f.key}`" class="mb-1 block text-xs font-semibold text-brand-navy/60 dark:text-white/55">{{ f.label }}</label>
                <input :id="`nl-${f.key}`" v-model.number="form[f.key]" type="number" min="0" :placeholder="f.ph" :class="fieldCls" />
              </div>
            </div>
          </div>
          </div>

          <!-- Description + AI -->
          <div>
            <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
              <label for="nl-desc" class="text-sm font-semibold text-brand-navy dark:text-white/90">Description</label>
              <button
                type="button"
                :disabled="aiLoading"
                class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold/15 py-1 pl-1 pr-3 text-xs font-bold text-brand-gold-deep ring-1 ring-brand-gold/40 hover:bg-brand-gold/25 dark:text-brand-gold disabled:opacity-50 transition"
                @click="doGenerateDescription"
              >
                <img src="/ai/avatar-96.webp" alt="" class="h-5 w-5 rounded-full bg-white" />
                {{ aiLoading ? 'Reading your photos…' : 'Write it with AI' }}
              </button>
            </div>
            <textarea id="nl-desc" v-model="form.description" rows="7" :class="[fieldCls, 'resize-y leading-relaxed']" placeholder="What makes this place special? Or let RealtyLink AI write it from your photos." />
            <p v-if="aiError" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ aiError }}</p>
            <p v-else class="mt-1 text-xs text-brand-navy/45 dark:text-white/40">RealtyLink AI reads your {{ gallery.length + panos.length }} {{ gallery.length + panos.length === 1 ? 'photo' : 'photos' }} and these details to draft it. You can edit it after.</p>
          </div>
        </section>

        <!-- ── Nav: a bar fixed to the bottom on phones ── -->
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-brand-navy/[0.08] bg-white/95 backdrop-blur px-4 py-3 dark:border-white/10 dark:bg-[#0B1A35]/95 lg:static lg:mt-5 lg:border-0 lg:bg-transparent lg:p-0 lg:backdrop-blur-none dark:lg:bg-transparent">
          <div class="flex items-center justify-between gap-3">
            <button v-if="step > 1" type="button" class="inline-flex items-center gap-1 rounded-full px-4 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition" @click="step--">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
              Back
            </button>
            <span v-else class="hidden cw-sm:inline text-xs text-brand-navy/45 dark:text-white/40">Step {{ step }} of 3</span>

            <!-- Preview: when there's no room beside the form, it opens in a dialog -->
            <button type="button" class="cw-lg:hidden inline-flex items-center gap-1.5 rounded-full px-4 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition" aria-label="Preview this listing" @click="previewOpen = true">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
              <span class="hidden cw-sm:inline">Preview</span>
            </button>

            <button
              v-if="step < 3"
              type="button"
              class="whitespace-nowrap inline-flex items-center gap-2 rounded-full bg-brand-navy px-6 py-2.5 text-sm font-bold text-white hover:-translate-y-0.5 dark:bg-brand-gold dark:text-[#0B2A5C] transition"
              @click="step++"
            >
              {{ step === 2 && !panos.length ? 'Skip for now' : 'Continue' }}
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
            </button>
            <button
              v-else
              type="button"
              :disabled="!canSubmit || loading"
              class="whitespace-nowrap inline-flex items-center gap-2 rounded-full bg-brand-gold px-6 py-2.5 text-sm font-bold text-[#0B2A5C] shadow-[0_6px_20px_rgba(212,165,74,0.4)] hover:-translate-y-0.5 transition disabled:opacity-50 disabled:shadow-none disabled:translate-y-0"
              @click="submit"
            >
              <svg v-if="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
              {{ loading ? 'Saving…' : 'Save listing' }}
            </button>
          </div>
        </div>
      </div>

      <!-- ░░ Live preview (beside the form only when there is room) ░░ -->
      <aside class="hidden cw-lg:block space-y-4 cw-lg:sticky cw-lg:top-24">
        <ListingPreviewCard v-bind="previewProps" />
        <p class="px-1 text-xs text-brand-navy/50 dark:text-white/45">Saved listings start as drafts. Publish them from My Listings when you're ready.</p>
      </aside>
    </div>

    <!-- Preview in a dialog (shown from the Preview button when there's no room beside the form) -->
    <AppDialog :open="previewOpen" title="Preview" subtitle="How buyers will see this listing." icon="photo" @close="previewOpen = false">
      <ListingPreviewCard v-bind="previewProps" />
      <template #footer>
        <button type="button" :class="DIALOG_BTN.primary" @click="previewOpen = false">Done</button>
      </template>
    </AppDialog>

    <!-- Leaving mid-listing. Everything is already saved, so this is a choice,
         not a warning: keep the draft for later, or throw it away. -->
    <AppDialog
      :open="leaveModal"
      title="Finish this listing later?"
      subtitle="Everything you've added is kept as a draft in My Listings. Buyers won't see it until you publish it."
      icon="leave"
      :busy="leaveBusy"
      @close="closeLeave"
    >
      <!-- The draft at a glance -->
      <div class="flex items-center gap-3 rounded-2xl p-2.5 pr-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
        <span class="h-14 w-16 flex-none overflow-hidden rounded-xl bg-brand-navy/[0.06] dark:bg-white/10 flex items-center justify-center">
          <img v-if="coverUrl" :src="coverUrl" alt="" class="h-full w-full object-cover" />
          <svg v-else class="h-6 w-6 text-brand-navy/30 dark:text-white/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" /></svg>
        </span>
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold text-brand-navy dark:text-white">{{ form.title || 'Untitled listing' }}</p>
          <p class="mt-1 flex flex-wrap gap-1.5 text-[0.6875rem] font-semibold text-brand-navy/70 dark:text-white/65">
            <span class="rounded-full bg-brand-navy/[0.06] dark:bg-white/10 px-2 py-0.5">{{ gallery.length }} {{ gallery.length === 1 ? 'photo' : 'photos' }}</span>
            <span v-if="panos.length" class="rounded-full bg-brand-navy/[0.06] dark:bg-white/10 px-2 py-0.5">{{ panos.length }} 360°</span>
            <span v-if="form.price > 0" class="rounded-full bg-brand-gold/20 px-2 py-0.5 text-brand-gold-deep dark:text-brand-gold">{{ priceText }}</span>
          </p>
        </div>
      </div>

      <!-- Discard asks twice: it deletes the photos too -->
      <div class="mt-4">
        <button
          v-if="!discardArmed"
          type="button"
          class="inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400 transition disabled:opacity-50"
          :disabled="leaveBusy"
          @click="discardArmed = true"
        >
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="DIALOG_ICON.trash" /></svg>
          Discard this draft instead
        </button>
        <div v-else class="rounded-2xl bg-red-500/[0.07] ring-1 ring-red-500/20 p-3.5">
          <p class="text-sm text-red-700 dark:text-red-300">This deletes the draft and its {{ gallery.length + panos.length }} {{ gallery.length + panos.length === 1 ? 'photo' : 'photos' }} for good.</p>
          <div class="mt-3 flex flex-col-reverse sm:flex-row gap-2">
            <button type="button" :class="DIALOG_BTN.cancel" class="sm:flex-1 !py-2" :disabled="leaveBusy" @click="discardArmed = false">Keep it</button>
            <button type="button" :class="DIALOG_BTN.danger" class="sm:flex-1 !py-2" :disabled="leaveBusy" @click="leaveDiscarding">{{ leaveBusy && discardArmed ? 'Deleting…' : 'Yes, delete it' }}</button>
          </div>
        </div>
      </div>

      <template #footer>
        <button type="button" :class="DIALOG_BTN.cancel" :disabled="leaveBusy" @click="closeLeave">Keep editing</button>
        <button type="button" :class="DIALOG_BTN.primary" :disabled="leaveBusy" data-autofocus @click="leaveSaving">
          <svg v-if="leaveBusy && !discardArmed" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
          {{ leaveBusy && !discardArmed ? 'Saving…' : 'Save draft and leave' }}
        </button>
      </template>
    </AppDialog>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
