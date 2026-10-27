<script setup lang="ts">
import type { PropertyType, OfferType } from '~/types'
import { PROPERTY_TYPES } from '~/types'

definePageMeta({ layout: 'dashboard' })

/*
 * Edit listing, in the same shape as New listing: title with the stepper
 * beside it, one step at a time in a card that fits the window (Back / Save
 * stay in view), photos inside their drop box, and a live preview.
 * Saving is available from any step.
 */
const route  = useRoute()
const router = useRouter()
const id     = Number(route.params.id)
const ask    = useConfirm()
const toast  = useToast()
const propertyHref = usePropertyHref()

const { property, loading, error, fetchProperty, updateProperty, uploadPhoto, deletePhoto, generateDescription, confirmPriceIfOdd } = useProperty()

const saving = ref(false)
await fetchProperty(id)

const step = ref(1)
const steps = [
  { n: 1, label: 'Property Photos', desc: 'The real photos buyers will see' },
  { n: 2, label: 'Virtual Tour',    desc: '360° / panorama shots · optional' },
  { n: 3, label: 'Listing Details',  desc: 'Title, price & description' },
]

const form = reactive({
  title:       property.value?.title       ?? '',
  description: property.value?.description ?? '',
  price:       Number(property.value?.price ?? 0),
  type:        (property.value?.type ?? 'house') as PropertyType,
  offer_type:  (property.value?.offer_type ?? 'sale') as OfferType,
  bedrooms:    property.value?.bedrooms    ?? undefined as number | undefined,
  bathrooms:   property.value?.bathrooms   ?? undefined as number | undefined,
  floor_area:  Number(property.value?.floor_area ?? 0) || undefined as number | undefined,
  lot_area:    Number(property.value?.lot_area ?? 0) || undefined as number | undefined,
  address:     property.value?.address     ?? '',
})

const existingRegular = computed(() => property.value?.photos?.filter(p => !p.is_360) ?? [])
const existing360     = computed(() => property.value?.photos?.filter(p => p.is_360) ?? [])

const newPhotos      = ref<File[]>([])
const newPhotoView   = ref<string[]>([])
const newPanoramas   = ref<File[]>([])
const newPanoView    = ref<string[]>([])

/** Photos rejected before upload, or that failed during it. */
const photoError = ref('')

function addFiles(e: Event, target: 'photo' | 'pano') {
  addFileList(Array.from((e.target as HTMLInputElement).files ?? []), target)
  ;(e.target as HTMLInputElement).value = ''
}
function addFileList(files: File[], target: 'photo' | 'pano') {
  const tooBig = files.filter(f => f.size > MAX_PHOTO_BYTES)
  const files_ = files.filter(f => f.size <= MAX_PHOTO_BYTES)

  photoError.value = tooBig.length
    ? `Skipped ${tooBig.map(f => f.name).join(', ')} — over ${MAX_PHOTO_LABEL}.`
    : ''

  files_.forEach(f => {
    const reader = new FileReader()
    reader.onload = ev => {
      if (target === 'photo') { newPhotos.value.push(f); newPhotoView.value.push(ev.target?.result as string) }
      else { newPanoramas.value.push(f); newPanoView.value.push(ev.target?.result as string) }
    }
    reader.readAsDataURL(f)
  })
}
/* Drag and drop onto the photo box. */
const dragOver = ref<'photo' | 'pano' | null>(null)
function onDrop(e: DragEvent, target: 'photo' | 'pano') {
  dragOver.value = null
  const files = Array.from(e.dataTransfer?.files ?? []).filter(f => f.type.startsWith('image/'))
  if (files.length) addFileList(files, target)
}
function removeNewPhoto(i: number) { newPhotos.value.splice(i, 1); newPhotoView.value.splice(i, 1) }
function removeNewPano(i: number)  { newPanoramas.value.splice(i, 1); newPanoView.value.splice(i, 1) }
/* A saved photo is deleted straight away, so ask first. */
async function removeExisting(photoId: number) {
  const ok = await ask({
    title: 'Remove this photo?',
    message: 'It’s deleted from the listing right away. Buyers stop seeing it.',
    confirmLabel: 'Remove photo',
    tone: 'danger',
  })
  if (!ok) return
  await deletePhoto(id, photoId)
  await fetchProperty(id)
}

const aiLoading = ref(false)
const aiError   = ref('')
async function doGenerateDescription() {
  const newUploads = [...newPhotos.value, ...newPanoramas.value]
  if (!newUploads.length && !existingRegular.value.length && !form.address.trim()) {
    aiError.value = 'Add photos or fill in the address first.'
    return
  }
  aiLoading.value = true
  aiError.value   = ''
  // Not-yet-saved photos go along (each under the AI's 5 MB limit); without
  // any, the server reads the listing's saved photos instead.
  const desc = await generateDescription({
    type:        form.type,
    offer_type:  form.offer_type,
    price:       form.price || undefined,
    bedrooms:    form.bedrooms,
    bathrooms:   form.bathrooms,
    floor_area:  form.floor_area,
    lot_area:    form.lot_area,
    address:     form.address,
    property_id: id,
  }, newUploads.filter(f => f.size <= 5 * 1024 * 1024).slice(0, 6))
  aiLoading.value = false
  if (desc) form.description = desc
  else aiError.value = 'Could not generate a description. The AI may be busy — try again.'
}

async function submit() {
  if (!await confirmPriceIfOdd(form.price, form.offer_type)) return
  saving.value = true
  // Address is re-geocoded server-side in the background — don't block the save.
  const updated = await updateProperty(id, {
    title:       form.title,
    description: form.description,
    price:       form.price,
    type:        form.type,
    offer_type:  form.offer_type,
    bedrooms:    form.bedrooms ?? 0,
    bathrooms:   form.bathrooms ?? 0,
    floor_area:  form.floor_area,
    lot_area:    form.lot_area,
    address:     form.address,
  })
  if (!updated) { saving.value = false; return }

  let order = property.value?.photos?.length ?? 0
  const failed: string[] = []
  for (const f of newPhotos.value)    if (!await uploadPhoto(id, f, order++, false)) failed.push(f.name)
  for (const f of newPanoramas.value) if (!await uploadPhoto(id, f, order++, true))  failed.push(f.name)
  saving.value = false

  // The return value used to be discarded and the redirect ran regardless, so a
  // rejected photo vanished with no indication anything had gone wrong.
  if (failed.length) {
    photoError.value =
      `Your changes were saved, but ${failed.length} photo${failed.length === 1 ? '' : 's'} `
      + `could not be uploaded (${failed.join(', ')}).`
      + (error.value ? ` ${error.value}` : '')
    return
  }

  toast.success('Changes saved')
  router.push('/dashboard/listings')
}

const canSubmit = computed(() => !!form.title.trim() && !!form.address.trim() && form.price > 0)
const unsavedPhotos = computed(() => newPhotos.value.length + newPanoramas.value.length)

/* ── Fit the step column to the window (desktop) ── */
const stepCol = ref<HTMLElement | null>(null)
const stepColHeight = ref<string | undefined>()
function fitStepCol() {
  if (!stepCol.value || window.innerWidth < 1024) { stepColHeight.value = undefined; return }
  const top = stepCol.value.getBoundingClientRect().top + window.scrollY
  stepColHeight.value = `${Math.max(360, Math.floor(window.innerHeight - top - 24))}px`
}
onMounted(() => { nextTick(fitStepCol); window.addEventListener('resize', fitStepCol) })
onUnmounted(() => window.removeEventListener('resize', fitStepCol))

/* ── Live preview ── */
const priceText = computed(() => listingPriceText(form.price, form.offer_type))
const coverUrl = computed(() => existingRegular.value[0]?.url ?? newPhotoView.value[0] ?? null)
const priceInput = computed({
  get: () => (form.price > 0 ? form.price : ''),
  set: (v: string | number) => { form.price = Number(v) || 0 },
})
const STATUS: Record<string, { label: string; cls: string }> = {
  published: { label: 'Published', cls: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-400' },
  draft:     { label: 'Draft',     cls: 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-400' },
  sold:      { label: 'Sold',      cls: 'bg-brand-navy/[0.06] text-brand-navy/70 ring-brand-navy/10 dark:bg-white/10 dark:text-white/70 dark:ring-white/10' },
}
const fieldCls = LISTING_FIELD_CLS
const labelCls = LISTING_LABEL_CLS
</script>

<template>
  <div class="max-w-6xl mx-auto pb-24 lg:pb-0">
    <!-- ── Heading + stepper ── -->
    <header class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
      <div class="min-w-0">
        <NuxtLink to="/dashboard/listings" class="inline-flex items-center gap-1 text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 hover:text-brand-gold-deep dark:text-white/40 dark:hover:text-brand-gold transition">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
          My listings
        </NuxtLink>
        <div class="mt-1.5 flex flex-wrap items-center gap-3">
          <h1 class="font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Edit listing</h1>
          <span v-if="property" class="rounded-full px-3 py-1 text-xs font-semibold ring-1" :class="STATUS[property.status]?.cls">{{ STATUS[property.status]?.label ?? property.status }}</span>
          <NuxtLink v-if="property?.status === 'published'" :to="propertyHref(id)" class="text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline">View live</NuxtLink>
        </div>
      </div>

      <div class="w-full lg:w-auto">
        <!-- Phones: the step's name, since the stepper only has room for numbers -->
        <p class="sm:hidden mb-2 text-xs font-semibold text-brand-navy/60 dark:text-white/55">
          Step {{ step }} of 3 · <span class="text-brand-navy dark:text-white">{{ steps[step - 1]!.label }}</span>
        </p>
        <ol class="grid grid-cols-3 gap-1.5 rounded-3xl p-1.5 bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] lg:w-[34rem]">
          <li v-for="s in steps" :key="s.n">
            <button
              type="button"
              class="w-full flex items-center gap-2.5 rounded-2xl px-2.5 py-2 text-left transition"
              :class="step === s.n ? 'bg-brand-navy text-white dark:bg-brand-gold dark:text-[#0B2A5C]' : 'hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05]'"
              :aria-current="step === s.n ? 'step' : undefined"
              :title="s.desc"
              @click="step = s.n"
            >
              <span
                class="h-7 w-7 flex-none rounded-full flex items-center justify-center text-xs font-bold transition"
                :class="step === s.n ? 'bg-white/15 text-white dark:bg-[#0B2A5C]/15 dark:text-[#0B2A5C]' : 'bg-brand-navy/[0.06] text-brand-navy/55 dark:bg-white/10 dark:text-white/55'"
              >{{ s.n }}</span>
              <span class="hidden sm:block min-w-0 truncate text-sm font-semibold" :class="step === s.n ? '' : 'text-brand-navy dark:text-white'">{{ s.label }}</span>
            </button>
          </li>
        </ol>
      </div>
    </header>

    <div v-if="loading && !property" class="space-y-4">
      <AppSkeleton width="100%" height="200px" />
      <AppSkeleton :lines="4" />
    </div>

    <div v-else class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] items-start">
      <!-- ░░ Step content: fills the window on desktop, so Save is always in view ░░ -->
      <div ref="stepCol" class="min-w-0 lg:flex lg:flex-col" :style="stepColHeight ? { height: stepColHeight } : undefined">
        <p v-if="error" class="mb-4 rounded-2xl bg-red-500/[0.07] ring-1 ring-red-500/20 px-4 py-3 text-sm text-red-700 dark:text-red-300">{{ error }}</p>
        <p v-if="photoError" class="mb-4 rounded-2xl bg-amber-500/[0.08] ring-1 ring-amber-500/20 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">{{ photoError }}</p>

        <!-- ── Step 1: Photos ── -->
        <section v-show="step === 1" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5 sm:p-7 lg:flex-1 lg:min-h-0 lg:flex lg:flex-col">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Property photos</h2>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">The photos buyers see. The first is the cover. New ones (gold edge) upload when you save.</p>

          <div
            class="mt-5 rounded-3xl border-2 border-dashed p-3 transition lg:flex-1 lg:min-h-0 overflow-y-auto overscroll-contain dark:[color-scheme:dark]"
            :class="dragOver === 'photo' ? 'border-brand-gold bg-brand-gold/[0.08]' : 'border-brand-navy/15 dark:border-white/15'"
            @dragover.prevent="dragOver = 'photo'"
            @dragleave.prevent="dragOver = null"
            @drop.prevent="onDrop($event, 'photo')"
          >
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
              <div v-for="(photo, i) in existingRegular" :key="photo.id" class="group relative aspect-[4/3] overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.07] dark:ring-white/10">
                <img :src="photo.thumb_url ?? photo.url" alt="" class="h-full w-full object-cover" />
                <span v-if="i === 0" class="absolute top-2 left-2 rounded-full bg-brand-gold px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C] shadow">Cover</span>
                <button type="button" class="absolute top-2 right-2 h-7 w-7 rounded-full bg-black/60 text-white flex items-center justify-center opacity-100 [@media(hover:hover)]:opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition" :aria-label="`Remove photo ${i + 1}`" @click="removeExisting(photo.id)">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <div v-for="(src, i) in newPhotoView" :key="`n${i}`" class="group relative aspect-[4/3] overflow-hidden rounded-2xl ring-2 ring-brand-gold">
                <img :src="src" alt="" class="h-full w-full object-cover" />
                <span class="absolute bottom-2 left-2 rounded-full bg-brand-gold px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C] shadow">New</span>
                <button type="button" class="absolute top-2 right-2 h-7 w-7 rounded-full bg-black/60 text-white flex items-center justify-center opacity-100 [@media(hover:hover)]:opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition" :aria-label="`Remove new photo ${i + 1}`" @click="removeNewPhoto(i)">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <label class="aspect-[4/3] flex cursor-pointer flex-col items-center justify-center rounded-2xl bg-brand-gold/[0.06] ring-1 ring-brand-gold/30 text-brand-gold-deep hover:bg-brand-gold/[0.12] dark:text-brand-gold transition">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                <span class="mt-1 text-xs font-bold">Add photos</span>
                <span class="text-[0.625rem] opacity-70">or drop them here</span>
                <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'photo')" />
              </label>
            </div>
          </div>
          <p class="mt-3 text-xs text-brand-navy/50 dark:text-white/45">{{ existingRegular.length }} saved<template v-if="newPhotos.length"> · <span class="font-semibold text-brand-gold-deep dark:text-brand-gold">{{ newPhotos.length }} new, uploaded when you save</span></template></p>
        </section>

        <!-- ── Step 2: Virtual tour ── -->
        <section v-show="step === 2" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5 sm:p-7 lg:flex-1 lg:min-h-0 lg:flex lg:flex-col">
          <div class="flex items-center gap-2">
            <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Virtual tour</h2>
            <span class="rounded-full bg-brand-navy/[0.06] dark:bg-white/10 px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-brand-navy/55 dark:text-white/55">Optional</span>
          </div>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">360° panoramas let buyers look around each room. Use 2:1 wrap-around shots from a 360° camera or app.</p>

          <div
            class="mt-5 rounded-3xl border-2 border-dashed p-3 transition lg:flex-1 lg:min-h-0 overflow-y-auto overscroll-contain dark:[color-scheme:dark]"
            :class="dragOver === 'pano' ? 'border-brand-gold bg-brand-gold/[0.08]' : 'border-brand-navy/15 dark:border-white/15'"
            @dragover.prevent="dragOver = 'pano'"
            @dragleave.prevent="dragOver = null"
            @drop.prevent="onDrop($event, 'pano')"
          >
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div v-for="photo in existing360" :key="photo.id" class="group relative aspect-[2/1] overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.07] dark:ring-white/10">
                <img :src="photo.thumb_url ?? photo.url" alt="" class="h-full w-full object-cover" />
                <span class="absolute top-2 left-2 rounded-full bg-brand-navy px-2 py-0.5 text-[0.625rem] font-bold text-brand-gold">360°</span>
                <button type="button" class="absolute top-2 right-2 h-7 w-7 rounded-full bg-black/60 text-white flex items-center justify-center opacity-100 [@media(hover:hover)]:opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition" aria-label="Remove panorama" @click="removeExisting(photo.id)">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <div v-for="(src, i) in newPanoView" :key="`np${i}`" class="group relative aspect-[2/1] overflow-hidden rounded-2xl ring-2 ring-brand-gold">
                <img :src="src" alt="" class="h-full w-full object-cover" />
                <span class="absolute bottom-2 left-2 rounded-full bg-brand-gold px-2 py-0.5 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C] shadow">New</span>
                <button type="button" class="absolute top-2 right-2 h-7 w-7 rounded-full bg-black/60 text-white flex items-center justify-center opacity-100 [@media(hover:hover)]:opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition" :aria-label="`Remove new panorama ${i + 1}`" @click="removeNewPano(i)">
                  <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <label class="aspect-[2/1] flex cursor-pointer flex-col items-center justify-center rounded-2xl bg-brand-gold/[0.06] ring-1 ring-brand-gold/30 text-brand-gold-deep hover:bg-brand-gold/[0.12] dark:text-brand-gold transition">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                <span class="mt-1 text-xs font-bold">Add 360° shots</span>
                <span class="text-[0.625rem] opacity-70">or drop them here</span>
                <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'pano')" />
              </label>
            </div>
          </div>
          <p class="mt-3 text-xs text-brand-navy/50 dark:text-white/45">{{ existing360.length }} saved<template v-if="newPanoramas.length"> · <span class="font-semibold text-brand-gold-deep dark:text-brand-gold">{{ newPanoramas.length }} new, uploaded when you save</span></template></p>
        </section>

        <!-- ── Step 3: Details ── -->
        <section v-show="step === 3" class="rounded-3xl bg-white dark:bg-[#0B1A35] ring-1 ring-brand-navy/[0.07] dark:ring-white/[0.08] p-5 sm:p-7 space-y-7 lg:flex-1 lg:min-h-0 lg:overflow-y-auto overscroll-contain dark:[color-scheme:dark]">
          <div>
            <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Listing details</h2>
            <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">Fields marked <span class="text-red-500">*</span> are required.</p>
          </div>

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
              <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                <button
                  v-for="(label, key) in PROPERTY_TYPES"
                  :key="key"
                  type="button"
                  class="flex items-center gap-2 rounded-2xl px-3 py-2.5 text-sm font-semibold ring-1 transition"
                  :class="form.type === key ? 'bg-brand-gold/15 ring-2 ring-brand-gold text-brand-navy dark:text-white' : 'ring-brand-navy/10 text-brand-navy/70 hover:ring-brand-gold dark:ring-white/10 dark:text-white/70'"
                  @click="form.type = key"
                >
                  <svg class="h-4 w-4 flex-none" :class="form.type === key ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/40 dark:text-white/40'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="PROPERTY_TYPE_ICONS[key] ?? PROPERTY_TYPE_ICONS.house" /></svg>
                  {{ label }}
                </button>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4">
            <div>
              <label for="el-title" :class="labelCls">Title <span class="text-red-500">*</span></label>
              <input id="el-title" v-model="form.title" type="text" maxlength="150" :class="fieldCls" />
            </div>
            <div>
              <label for="el-address" :class="labelCls">Address <span class="text-red-500">*</span></label>
              <input id="el-address" v-model="form.address" type="text" :class="fieldCls" />
            </div>
            <div>
              <label for="el-price" :class="labelCls">{{ form.offer_type === 'rent' ? 'Monthly rent' : 'Price' }} <span class="text-red-500">*</span></label>
              <div class="relative">
                <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 font-semibold text-brand-navy/50 dark:text-white/50">₱</span>
                <input id="el-price" v-model="priceInput" type="number" min="0" inputmode="numeric" placeholder="5000000" :class="[fieldCls, 'pl-9']" />
              </div>
              <p class="mt-1 text-xs" :class="form.price > 0 ? 'font-semibold text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/45 dark:text-white/40'">{{ priceText }}</p>
            </div>
          </div>

          <div>
            <p :class="labelCls">Size and rooms</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div v-for="f in [
                { key: 'bedrooms', label: 'Bedrooms', ph: '3' },
                { key: 'bathrooms', label: 'Bathrooms', ph: '2' },
                { key: 'floor_area', label: 'Floor area (sqm)', ph: '120' },
                { key: 'lot_area', label: 'Lot area (sqm)', ph: '200' },
              ] as const" :key="f.key">
                <label :for="`el-${f.key}`" class="mb-1 block text-xs font-semibold text-brand-navy/60 dark:text-white/55">{{ f.label }}</label>
                <input :id="`el-${f.key}`" v-model.number="form[f.key]" type="number" min="0" :placeholder="f.ph" :class="fieldCls" />
              </div>
            </div>
          </div>

          <div>
            <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
              <label for="el-desc" class="text-sm font-semibold text-brand-navy dark:text-white/90">Description</label>
              <button
                type="button"
                :disabled="aiLoading"
                class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold/15 py-1 pl-1 pr-3 text-xs font-bold text-brand-gold-deep ring-1 ring-brand-gold/40 hover:bg-brand-gold/25 dark:text-brand-gold disabled:opacity-50 transition"
                @click="doGenerateDescription"
              >
                <img src="/ai/avatar-96.webp" alt="" class="h-5 w-5 rounded-full bg-white" />
                {{ aiLoading ? 'Reading your photos…' : 'Rewrite it with AI' }}
              </button>
            </div>
            <textarea id="el-desc" v-model="form.description" rows="7" :class="[fieldCls, 'resize-y leading-relaxed']" />
            <p v-if="aiError" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ aiError }}</p>
            <p v-else class="mt-1 text-xs text-brand-navy/45 dark:text-white/40">RealtyLink AI reads this listing's photos and details to draft it. You can edit it after.</p>
          </div>
        </section>

        <!-- ── Nav: Save works from any step; a fixed bar on phones ── -->
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-brand-navy/[0.08] bg-white/95 backdrop-blur px-4 py-3 dark:border-white/10 dark:bg-[#0B1A35]/95 lg:static lg:mt-5 lg:border-0 lg:bg-transparent lg:p-0 lg:backdrop-blur-none dark:lg:bg-transparent">
          <div class="flex items-center justify-between gap-2">
            <button v-if="step > 1" type="button" class="inline-flex items-center gap-1 rounded-full px-4 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition" @click="step--">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
              Back
            </button>
            <span v-else class="text-xs text-brand-navy/45 dark:text-white/40">Step {{ step }} of 3</span>

            <div class="flex items-center gap-2">
              <button
                v-if="step < 3"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full px-5 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition"
                @click="step++"
              >
                Next
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
              </button>
              <button
                type="button"
                :disabled="!canSubmit || saving"
                class="inline-flex items-center gap-2 rounded-full bg-brand-gold px-6 py-2.5 text-sm font-bold text-[#0B2A5C] shadow-[0_6px_20px_rgba(212,165,74,0.4)] hover:-translate-y-0.5 transition disabled:opacity-50 disabled:shadow-none disabled:translate-y-0"
                @click="submit"
              >
                <svg v-if="saving" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                {{ saving ? 'Saving…' : unsavedPhotos ? `Save changes · ${unsavedPhotos} new` : 'Save changes' }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ░░ Live preview ░░ -->
      <aside class="space-y-3 lg:sticky lg:top-24">
        <section class="overflow-hidden rounded-3xl bg-white dark:bg-[#0B1A35] border-2 border-brand-navy/80 dark:border-brand-gold/70">
          <div class="relative aspect-[16/10] bg-brand-navy/5 dark:bg-white/[0.06]">
            <img v-if="coverUrl" :src="coverUrl" alt="" class="h-full w-full object-cover" />
            <div v-else class="h-full w-full flex flex-col items-center justify-center gap-1 text-brand-navy/35 dark:text-white/35">
              <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" /></svg>
              <span class="text-xs font-semibold">No cover photo</span>
            </div>
            <span class="absolute top-3 left-3 rounded-full bg-[#ffffff]/95 px-2.5 py-1 text-[0.625rem] font-bold uppercase tracking-wide text-[#0B2A5C] shadow-sm">{{ form.offer_type === 'rent' ? 'For rent' : 'For sale' }}</span>
            <span v-if="existing360.length || newPanoramas.length" class="absolute bottom-3 left-3 rounded-full bg-brand-navy px-2 py-0.5 text-[0.625rem] font-bold text-brand-gold">360° tour</span>
            <span v-if="property?.views" class="absolute bottom-3 right-3 rounded-full bg-black/55 px-2 py-0.5 text-[0.625rem] font-semibold text-white">{{ property.views }} views</span>
          </div>
          <div class="p-4">
            <p class="text-[0.625rem] font-bold uppercase tracking-[0.16em] text-brand-navy/45 dark:text-white/40">Preview</p>
            <p class="mt-1 font-display text-lg font-bold tabular-nums" :class="form.price > 0 ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/30 dark:text-white/30'">{{ priceText }}</p>
            <p class="line-clamp-2 font-semibold" :class="form.title ? 'text-brand-navy dark:text-white' : 'italic text-brand-navy/35 dark:text-white/35'">{{ form.title || 'Your listing title' }}</p>
            <p class="mt-0.5 truncate text-xs text-brand-navy/50 dark:text-white/45">{{ form.address || 'Address' }}</p>
            <div class="mt-3 flex flex-wrap gap-1.5 text-[0.6875rem] font-semibold text-brand-navy/70 dark:text-white/65">
              <span class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ PROPERTY_TYPES[form.type] }}</span>
              <span v-if="form.bedrooms" class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ form.bedrooms }} bed</span>
              <span v-if="form.bathrooms" class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ form.bathrooms }} bath</span>
              <span v-if="form.floor_area" class="rounded-full bg-brand-navy/[0.05] dark:bg-white/[0.07] px-2.5 py-1">{{ form.floor_area }} sqm</span>
            </div>
          </div>
        </section>
        <p class="px-1 text-xs text-brand-navy/50 dark:text-white/45">Changes go live as soon as you save<template v-if="property?.status !== 'published'"> and publish</template>.</p>
      </aside>
    </div>
  </div>
</template>
