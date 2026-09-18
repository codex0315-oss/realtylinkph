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
  }, shots.map(s => s.file).filter((f): f is File => !!f))
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

/* ── Leaving mid-listing ── */
const leaveModal  = ref(false)
const leaveBusy   = ref(false)
let leaveTarget   = '/dashboard/listings'

onBeforeRouteLeave((to) => {
  if (leavingOnPurpose) return true
  if (!draftId.value && !dirty) return true
  leaveTarget = to.fullPath
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
  <div class="max-w-5xl">
    <div class="flex items-center gap-3 mb-2 flex-wrap">
      <NuxtLink to="/dashboard/listings" class="text-brand-text-secondary hover:text-brand-navy text-sm">← Back</NuxtLink>
      <h1 class="font-playfair text-2xl font-bold text-brand-navy">{{ resumed ? 'Continue Listing' : 'New Listing' }}</h1>

      <!-- Autosave status — the answer to "did that save?" without asking. -->
      <Transition name="fade">
        <span
          v-if="saveLabel"
          class="ml-auto inline-flex items-center gap-1.5 text-xs font-medium"
          :class="saveState === 'error' ? 'text-red-600' : saveState === 'saved' ? 'text-emerald-600' : 'text-gray-400'"
        >
          <svg v-if="saveState === 'saving'" class="animate-spin h-3 w-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
          <svg v-else-if="saveState === 'saved'" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
          {{ saveLabel }}
        </span>
      </Transition>
    </div>

    <p v-if="resumed" class="text-xs text-gray-500 mb-5">
      Picking up your unfinished listing.
      <button type="button" class="font-semibold text-brand-gold hover:underline" @click="startFresh">Start a new listing instead</button>
      — this one stays saved in My Listings.
    </p>
    <div v-else class="mb-5" />

    <div class="grid lg:grid-cols-3 gap-6 items-start">

      <!-- ░░ Left rail: stepper ░░ -->
      <aside class="lg:sticky lg:top-6">
        <div class="rounded-2xl p-5 text-white relative overflow-hidden" style="background: linear-gradient(160deg, #10264D 0%, #08152F 70%, #060E1F 100%)">
          <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full border border-brand-gold/10" />
          <p class="text-brand-gold text-[10px] font-bold uppercase tracking-[0.2em] mb-4 relative">Create a listing</p>
          <ol class="space-y-1 relative">
            <li v-for="s in steps" :key="s.n">
              <button
                type="button"
                class="w-full flex items-start gap-3 rounded-xl p-3 text-left transition-colors"
                :class="step === s.n ? 'bg-white/10' : 'hover:bg-white/5'"
                @click="step = s.n"
              >
                <span
                  class="h-7 w-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 transition-colors"
                  :class="step > s.n ? 'bg-brand-gold text-brand-navy' : step === s.n ? 'bg-white text-brand-navy' : 'bg-white/10 text-white/50'"
                >
                  <svg v-if="step > s.n" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                  <span v-else>{{ s.n }}</span>
                </span>
                <span class="min-w-0">
                  <span class="block text-sm font-semibold" :class="step === s.n ? 'text-white' : 'text-white/70'">{{ s.label }}</span>
                  <span class="block text-[11px] text-white/40 mt-0.5">{{ s.desc }}</span>
                </span>
              </button>
            </li>
          </ol>
          <p class="text-[11px] text-white/40 mt-4 relative leading-relaxed">
            💡 Upload photos first — the AI can <span class="text-brand-gold/90">look at them</span> to write your description in step 3.
          </p>
        </div>
      </aside>

      <!-- ░░ Right: step content ░░ -->
      <div class="lg:col-span-2">
        <p v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-4">{{ error }}</p>
        <p v-if="photoError" class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-4">{{ photoError }}</p>

        <!-- ── Step 1: Property photos ── -->
        <div v-show="step === 1" class="card p-6">
          <h2 class="font-bold text-brand-navy">Property Photos</h2>
          <p class="text-xs text-gray-500 mt-1 mb-5">Clear, well-lit photos of the actual property. The first photo is the cover. JPG, PNG, or WebP.</p>

          <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
            <div v-for="(shot, i) in gallery" :key="shot.id" class="relative aspect-[4/3] rounded-xl overflow-hidden group">
              <img :src="shot.url" class="h-full w-full object-cover transition-opacity" :class="shot.uploading ? 'opacity-50' : ''" />
              <!-- Upload in flight: the photo is visible from the local file, the server copy is on its way. -->
              <span v-if="shot.uploading" class="absolute inset-0 flex items-center justify-center">
                <svg class="animate-spin h-6 w-6 text-white drop-shadow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
              </span>
              <span v-if="i === 0" class="absolute top-1.5 left-1.5 text-[9px] font-bold bg-brand-gold text-brand-navy px-1.5 py-0.5 rounded">COVER</span>
              <button v-if="!shot.uploading" type="button" class="absolute top-1.5 right-1.5 bg-black/60 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity" @click="removePhoto(i)">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
            <label class="aspect-[4/3] rounded-xl border-2 border-dashed border-gray-300 hover:border-brand-gold cursor-pointer flex flex-col items-center justify-center text-gray-400 hover:text-brand-gold transition-colors">
              <svg class="h-7 w-7 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4" /></svg>
              <span class="text-xs font-medium">Add photos</span>
              <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'photo')" />
            </label>
          </div>
          <p class="text-[11px] text-gray-400 mt-3">{{ gallery.length }} photo{{ gallery.length === 1 ? '' : 's' }} added · saved as you go</p>
        </div>

        <!-- ── Step 2: Virtual tour ── -->
        <div v-show="step === 2" class="card p-6">
          <div class="flex items-center gap-2">
            <h2 class="font-bold text-brand-navy">Virtual Tour</h2>
            <span class="text-[10px] font-bold uppercase tracking-wide bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">Optional</span>
          </div>
          <p class="text-xs text-gray-500 mt-1 mb-3">Upload <span class="font-semibold">360° / panoramic</span> shots for an immersive, draggable virtual tour on your listing page.</p>

          <div class="flex items-start gap-2.5 rounded-xl border border-brand-gold/25 bg-brand-gold/5 p-3 mb-5">
            <svg class="h-4 w-4 text-brand-gold mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <p class="text-[11px] text-brand-navy/70 leading-relaxed">
              <span class="font-semibold text-brand-navy">Works best with equirectangular 360° photos</span> — a single wide 2:1 wrap-around shot from a 360° camera or app (Insta360, Google Street View, etc.). Regular photos will still upload, but they'll look stretched in the tour viewer.
            </p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div v-for="(shot, i) in panos" :key="shot.id" class="relative aspect-[16/7] rounded-xl overflow-hidden group">
              <img :src="shot.url" class="h-full w-full object-cover transition-opacity" :class="shot.uploading ? 'opacity-50' : ''" />
              <span v-if="shot.uploading" class="absolute inset-0 flex items-center justify-center">
                <svg class="animate-spin h-6 w-6 text-white drop-shadow" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
              </span>
              <span class="absolute top-1.5 left-1.5 text-[9px] font-bold bg-brand-navy text-white px-1.5 py-0.5 rounded inline-flex items-center gap-1">
                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z M3 12h18" /></svg>360°
              </span>
              <button v-if="!shot.uploading" type="button" class="absolute top-1.5 right-1.5 bg-black/60 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity" @click="removePano(i)">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
            <label class="aspect-[16/7] rounded-xl border-2 border-dashed border-gray-300 hover:border-brand-gold cursor-pointer flex flex-col items-center justify-center text-gray-400 hover:text-brand-gold transition-colors">
              <svg class="h-7 w-7 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18" /></svg>
              <span class="text-xs font-medium">Add 360° / panorama</span>
              <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'pano')" />
            </label>
          </div>
          <p class="text-[11px] text-gray-400 mt-3">{{ panos.length }} panorama{{ panos.length === 1 ? '' : 's' }} · you can skip this step.</p>
        </div>

        <!-- ── Step 3: Details ── -->
        <div v-show="step === 3" class="card p-6 space-y-5">
          <h2 class="font-bold text-brand-navy">Listing Details</h2>

          <!-- Offer toggle -->
          <div>
            <label class="text-sm font-medium text-brand-text-primary">This listing is <span class="text-red-500">*</span></label>
            <div class="mt-1.5 inline-flex rounded-xl border border-gray-200 p-1 bg-gray-50">
              <button type="button" class="px-6 py-2 text-sm font-semibold rounded-lg transition-colors" :class="form.offer_type === 'sale' ? 'bg-brand-navy text-white shadow-sm' : 'text-gray-500 hover:text-brand-navy'" @click="form.offer_type = 'sale'">For Sale</button>
              <button type="button" class="px-6 py-2 text-sm font-semibold rounded-lg transition-colors" :class="form.offer_type === 'rent' ? 'bg-brand-navy text-white shadow-sm' : 'text-gray-500 hover:text-brand-navy'" @click="form.offer_type = 'rent'">For Rent</button>
            </div>
          </div>

          <AppInput v-model="form.title"   label="Title"   placeholder="Beautiful 3BR house in Quezon City" required />
          <AppInput v-model="form.address" label="Address" placeholder="123 Main St, Quezon City, Metro Manila" required />

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="text-sm font-medium text-brand-text-primary">Type <span class="text-red-500">*</span></label>
              <select v-model="form.type" class="input-field mt-1" required>
                <option v-for="(label, key) in PROPERTY_TYPES" :key="key" :value="key">{{ label }}</option>
              </select>
            </div>
            <AppInput v-model.number="form.price" label="Price (₱)" type="number" placeholder="5000000" required />
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <AppInput v-model.number="form.bedrooms"   label="Bedrooms"   type="number" placeholder="3" />
            <AppInput v-model.number="form.bathrooms"  label="Bathrooms"  type="number" placeholder="2" />
            <AppInput v-model.number="form.floor_area" label="Floor (sqm)" type="number" placeholder="120" />
            <AppInput v-model.number="form.lot_area"   label="Lot (sqm)"   type="number" placeholder="200" />
          </div>

          <!-- Description + AI -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="text-sm font-medium text-brand-text-primary">Description</label>
              <button
                type="button"
                :disabled="aiLoading"
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-gold hover:text-brand-navy disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                @click="doGenerateDescription"
              >
                <svg v-if="aiLoading" class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                <svg v-else class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
                {{ aiLoading ? 'Reading your photos…' : 'Generate with AI' }}
              </button>
            </div>
            <textarea v-model="form.description" rows="6" class="input-field resize-y" placeholder="Describe your property, or tap Generate with AI to write it from your photos…" />
            <p v-if="aiError" class="text-[11px] text-red-500 mt-1">{{ aiError }}</p>
            <p v-else class="text-[11px] text-brand-text-secondary mt-1">The AI looks at your uploaded photos ({{ gallery.length + panos.length }}) plus these details to draft a description.</p>
          </div>
        </div>

        <!-- ── Nav ── -->
        <div class="flex items-center justify-between mt-5">
          <button v-if="step > 1" type="button" class="text-sm font-semibold text-gray-500 hover:text-brand-navy inline-flex items-center gap-1" @click="step--">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            Back
          </button>
          <span v-else />

          <button
            v-if="step < 3"
            type="button"
            class="inline-flex items-center gap-2 bg-brand-navy text-white font-bold text-sm px-6 py-2.5 rounded-xl hover:-translate-y-0.5 transition-all"
            @click="step++"
          >
            {{ step === 2 && !panos.length ? 'Skip & continue' : 'Continue' }}
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          </button>
          <button
            v-else
            type="button"
            :disabled="!canSubmit || loading"
            class="inline-flex items-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-6 py-2.5 rounded-xl shadow-[0_4px_18px_rgba(212,175,55,0.35)] hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
            @click="submit"
          >
            <svg v-if="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            {{ loading ? 'Saving…' : 'Save listing' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Leaving mid-listing. Everything is already saved, so this is a choice,
         not a warning: keep the draft for later, or throw it away. -->
    <AppModal :open="leaveModal" size="sm" @close="leaveBusy ? undefined : (leaveModal = false)">
      <div class="p-6">
        <div class="flex items-start gap-3 mb-4">
          <div class="h-10 w-10 rounded-full bg-brand-gold/15 flex items-center justify-center flex-shrink-0">
            <svg class="h-5 w-5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          </div>
          <div>
            <h3 class="font-bold text-brand-navy text-base leading-snug">Continue this listing later?</h3>
            <p class="text-sm text-gray-500 mt-1">
              Your photos and details are saved as a draft. You can pick it up anytime from <span class="font-semibold text-brand-navy">My Listings</span> — it won't be visible to buyers until you publish it.
            </p>
          </div>
        </div>

        <div class="space-y-2">
          <AppButton variant="primary" full-width :disabled="leaveBusy" @click="leaveSaving">
            {{ leaveBusy ? 'Saving…' : 'Save as draft' }}
          </AppButton>
          <AppButton variant="ghost" full-width :disabled="leaveBusy" @click="leaveModal = false">Keep editing</AppButton>
          <button
            type="button"
            class="w-full text-xs font-semibold text-red-500 hover:text-red-600 py-2 transition-colors disabled:opacity-40"
            :disabled="leaveBusy"
            @click="leaveDiscarding"
          >Discard this draft</button>
        </div>
      </div>
    </AppModal>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
