<script setup lang="ts">
import type { PropertyType, OfferType } from '~/types'
import { PROPERTY_TYPES } from '~/types'

definePageMeta({ layout: 'dashboard' })

const router = useRouter()
const { createProperty, uploadPhoto, generateDescription, confirmPriceIfOdd, loading, error } = useProperty()

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

/* ── Photos ── */
const photos        = ref<File[]>([])
const photoPreview  = ref<string[]>([])
const panoramas     = ref<File[]>([])
const panoramaView  = ref<string[]>([])

/** Photos rejected before upload, or that failed during it. */
const photoError = ref('')

function addFiles(e: Event, target: 'photo' | 'pano') {
  const files  = Array.from((e.target as HTMLInputElement).files ?? [])
  const tooBig = files.filter(f => f.size > MAX_PHOTO_BYTES)
  const files_ = files.filter(f => f.size <= MAX_PHOTO_BYTES)

  photoError.value = tooBig.length
    ? `Skipped ${tooBig.map(f => f.name).join(', ')} — over ${MAX_PHOTO_LABEL}.`
    : ''

  files_.forEach(f => {
    const reader = new FileReader()
    reader.onload = ev => {
      if (target === 'photo') { photos.value.push(f); photoPreview.value.push(ev.target?.result as string) }
      else { panoramas.value.push(f); panoramaView.value.push(ev.target?.result as string) }
    }
    reader.readAsDataURL(f)
  })
  ;(e.target as HTMLInputElement).value = ''
}
function removePhoto(i: number) { photos.value.splice(i, 1); photoPreview.value.splice(i, 1) }
function removePano(i: number)  { panoramas.value.splice(i, 1); panoramaView.value.splice(i, 1) }

/* ── AI description (multimodal — scans the uploaded photos) ── */
const aiLoading = ref(false)
const aiError   = ref('')
async function doGenerateDescription() {
  const allPhotos = [...photos.value, ...panoramas.value]
  if (!allPhotos.length && !form.address.trim()) {
    aiError.value = 'Add a few photos (step 1) or fill in the address first.'
    return
  }
  aiLoading.value = true
  aiError.value   = ''
  const desc = await generateDescription({
    type:       form.type,
    offer_type: form.offer_type,
    price:      form.price || undefined,
    bedrooms:   form.bedrooms,
    bathrooms:  form.bathrooms,
    floor_area: form.floor_area,
    lot_area:   form.lot_area,
    address:    form.address,
  }, allPhotos)
  aiLoading.value = false
  if (desc) form.description = desc
  else aiError.value = 'Could not generate a description. The AI may be busy — try again.'
}

/* ── Submit ── */
async function submit() {
  if (!await confirmPriceIfOdd(form.price, form.offer_type)) return
  // Coordinates are geocoded server-side in the background — no need to block the save.
  const property = await createProperty({
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
  if (!property) return

  let order = 0
  const failed: string[] = []
  for (const f of photos.value)    if (!await uploadPhoto(property.id, f, order++, false)) failed.push(f.name)
  for (const f of panoramas.value) if (!await uploadPhoto(property.id, f, order++, true))  failed.push(f.name)

  // The return value used to be discarded and the redirect ran regardless, so a
  // rejected photo left the agent on a saved listing with an empty gallery and
  // no indication anything had gone wrong. Stay put and say what happened.
  if (failed.length) {
    photoError.value =
      `Your listing was saved, but ${failed.length} photo${failed.length === 1 ? '' : 's'} `
      + `could not be uploaded (${failed.join(', ')}).`
      + (error.value ? ` ${error.value}` : '')
      + ' You can add them from Edit listing.'
    return
  }

  router.push('/dashboard/listings')
}

const canSubmit = computed(() =>
  !!form.title.trim() && !!form.address.trim() && form.price > 0,
)
</script>

<template>
  <div class="max-w-5xl">
    <div class="flex items-center gap-3 mb-6">
      <NuxtLink to="/dashboard/listings" class="text-brand-text-secondary hover:text-brand-navy text-sm">← Back</NuxtLink>
      <h1 class="font-playfair text-2xl font-bold text-brand-navy">New Listing</h1>
    </div>

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
            <div v-for="(src, i) in photoPreview" :key="i" class="relative aspect-[4/3] rounded-xl overflow-hidden group">
              <img :src="src" class="h-full w-full object-cover" />
              <span v-if="i === 0" class="absolute top-1.5 left-1.5 text-[9px] font-bold bg-brand-gold text-brand-navy px-1.5 py-0.5 rounded">COVER</span>
              <button type="button" class="absolute top-1.5 right-1.5 bg-black/60 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity" @click="removePhoto(i)">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
            <label class="aspect-[4/3] rounded-xl border-2 border-dashed border-gray-300 hover:border-brand-gold cursor-pointer flex flex-col items-center justify-center text-gray-400 hover:text-brand-gold transition-colors">
              <svg class="h-7 w-7 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4" /></svg>
              <span class="text-xs font-medium">Add photos</span>
              <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'photo')" />
            </label>
          </div>
          <p class="text-[11px] text-gray-400 mt-3">{{ photos.length }} photo{{ photos.length === 1 ? '' : 's' }} added</p>
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
            <div v-for="(src, i) in panoramaView" :key="i" class="relative aspect-[16/7] rounded-xl overflow-hidden group">
              <img :src="src" class="h-full w-full object-cover" />
              <span class="absolute top-1.5 left-1.5 text-[9px] font-bold bg-brand-navy text-white px-1.5 py-0.5 rounded inline-flex items-center gap-1">
                <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z M3 12h18" /></svg>360°
              </span>
              <button type="button" class="absolute top-1.5 right-1.5 bg-black/60 text-white rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity" @click="removePano(i)">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
              </button>
            </div>
            <label class="aspect-[16/7] rounded-xl border-2 border-dashed border-gray-300 hover:border-brand-gold cursor-pointer flex flex-col items-center justify-center text-gray-400 hover:text-brand-gold transition-colors">
              <svg class="h-7 w-7 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18" /></svg>
              <span class="text-xs font-medium">Add 360° / panorama</span>
              <input type="file" class="sr-only" accept="image/*" multiple @change="addFiles($event, 'pano')" />
            </label>
          </div>
          <p class="text-[11px] text-gray-400 mt-3">{{ panoramas.length }} panorama{{ panoramas.length === 1 ? '' : 's' }} · you can skip this step.</p>
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
            <p v-else class="text-[11px] text-brand-text-secondary mt-1">The AI looks at your uploaded photos ({{ photos.length + panoramas.length }}) plus these details to draft a description.</p>
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
            {{ step === 2 && !panoramas.length ? 'Skip & continue' : 'Continue' }}
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
            {{ loading ? 'Creating…' : 'Create Listing' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
