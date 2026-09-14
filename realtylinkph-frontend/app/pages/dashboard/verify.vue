<script setup lang="ts">
import type { AgentProfile, ApplicantType } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const { submitVerification, loading, error } = useAgent()
const { fetchMe } = useAuth()
const router = useRouter()

// Refresh the latest agent status so the pending/rejected notice is reliable
// even on client-side navigation (the store isn't auto-refreshed between pages).
await fetchMe()

if (authStore.isVerifiedAgent) {
  await router.push('/dashboard/listings')
}

const existing = computed(() => authStore.user?.agent_profile ?? null)

const reapply           = ref(false)
const submittedProfile  = ref<AgentProfile | null>(null)

// Show the wizard unless there's a pending application or we just submitted.
const showStatus = computed(() =>
  !reapply.value && (submittedProfile.value !== null || existing.value?.status === 'pending'),
)
const showRejected = computed(() =>
  !reapply.value && submittedProfile.value === null && existing.value?.status === 'rejected',
)

const aiComment = computed(() => submittedProfile.value?.ai_comment ?? existing.value?.ai_comment ?? null)

// 12h re-apply cool-down for rejected applicants.
const { inCooldown, text: cooldownText } = useReapplyCooldown(() => existing.value?.reapply_at)

/* ── Wizard state ── */
const type = ref<ApplicantType | null>(null)
const step = ref(0) // 0 = choose type; 1..N = type-specific steps

const stepLabels = computed(() =>
  type.value === 'broker'
    ? ['Information', 'License Card', 'Live Scan', 'Review']
    : ['Information', 'Accreditation', 'Valid ID', 'Live Scan', 'Review'],
)
const lastStep = computed(() => stepLabels.value.length)

const form = reactive({
  fullName:           authStore.user?.name  ?? '',
  mobile:             authStore.user?.phone ?? '',
  prcNumber:          '',
  supervisingBroker:  '',
})

const files = reactive<{ accreditationDoc: File | null; validId: File | null; licenseDoc: File | null; faceImage: File | null }>({
  accreditationDoc: null, validId: null, licenseDoc: null, faceImage: null,
})
const previews = reactive<Record<string, string>>({ accreditationDoc: '', validId: '', licenseDoc: '', faceImage: '' })

/*
 * LiveFaceCapture emits `null` on "Retake" to clear the previous selfie. This
 * used to hand that null straight to FileReader, which threw
 * "parameter 1 is not of type 'Blob'" and left the form half-cleared.
 */
function setFace(f: File | null) {
  files.faceImage = f
  if (!f) { previews.faceImage = ''; return }
  const reader = new FileReader()
  reader.onload = ev => { previews.faceImage = ev.target?.result as string }
  reader.readAsDataURL(f)
}

/* Mirror the server's limits so a bad file is rejected when picked, not after
   filling in four steps and submitting. */
const DOC_MAX_BYTES = 5 * 1024 * 1024
const DOC_TYPES     = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp']
const docError      = ref('')

function chooseType(t: ApplicantType) {
  type.value = t
  step.value = 1
}

function setDoc(key: 'accreditationDoc' | 'validId' | 'licenseDoc', e: Event) {
  const input = e.target as HTMLInputElement
  const file  = input.files?.[0]
  if (!file) return

  docError.value = ''
  if (!DOC_TYPES.includes(file.type)) {
    docError.value = `${file.name} isn't a supported format. Please upload a PDF, JPG, PNG or WebP.`
    input.value = ''
    return
  }
  if (file.size > DOC_MAX_BYTES) {
    docError.value = `${file.name} is ${(file.size / 1024 / 1024).toFixed(1)} MB — the limit is 5 MB. Try a smaller photo or a PDF.`
    input.value = ''
    return
  }

  files[key] = file
  if (file.type.startsWith('image/')) {
    const reader = new FileReader()
    reader.onload = ev => { previews[key] = ev.target?.result as string }
    reader.readAsDataURL(file)
  } else {
    previews[key] = '' // PDF — show icon
  }
}

// The label key for the current step (for validation + rendering)
const stepKey = computed(() => {
  const label = stepLabels.value[step.value - 1]
  return label
})

function stepDone(label: string | undefined): boolean {
  switch (label) {
    case 'Information':   return !!form.fullName.trim() && !!form.prcNumber.trim()
    case 'Accreditation': return !!files.accreditationDoc
    case 'Valid ID':      return !!files.validId
    case 'License Card':  return !!files.licenseDoc
    case 'Live Scan':     return !!files.faceImage
    case 'Review':        return true
    default:              return false
  }
}

const canAdvance = computed(() => stepDone(stepKey.value))

/* ── Side panel data ── */
const stepDescriptions: Record<string, string> = {
  'Information':   'Your details + license / accreditation no.',
  'License Card':  'PRC broker license card',
  'Accreditation': 'Accreditation document (front)',
  'Valid ID':      'A valid government-issued ID',
  'Live Scan':     'A quick live face scan',
  'Review':        'Confirm and submit',
}

// Live requirements checklist — ticks green as each step is completed (Review excluded).
const checklist = computed(() =>
  stepLabels.value.filter(l => l !== 'Review').map(label => ({ label, desc: stepDescriptions[label] ?? '', done: stepDone(label) })),
)

const benefits = [
  'Post your property listings',
  'Receive buyer inquiries & messages',
  'Appointment booking with Google Calendar sync',
  'A verified agent badge on your profile',
]

/* ── "RealtyLink AI is verifying" overlay — shown while the submit + AI check runs ── */
const verifyMessages = [
  'Reading your documents…',
  'Checking details & name consistency…',
  'Comparing your live face scan…',
  'Finalizing the AI pre-check…',
]
const verifyMsgIndex = ref(0)
const verifyMsg = computed(() => verifyMessages[verifyMsgIndex.value] ?? verifyMessages[0])
let verifyTimer: ReturnType<typeof setInterval> | null = null

watch(loading, (v) => {
  if (import.meta.client) document.body.style.overflow = v ? 'hidden' : ''
  if (v) {
    verifyMsgIndex.value = 0
    verifyTimer = setInterval(() => {
      verifyMsgIndex.value = (verifyMsgIndex.value + 1) % verifyMessages.length
    }, 1800)
  } else if (verifyTimer) {
    clearInterval(verifyTimer)
    verifyTimer = null
  }
})

onUnmounted(() => {
  if (verifyTimer) clearInterval(verifyTimer)
  if (import.meta.client) document.body.style.overflow = ''
})

function next() {
  if (!canAdvance.value) return
  if (step.value < lastStep.value) step.value++
}
function back() {
  if (step.value > 1) step.value--
  else { step.value = 0; type.value = null }
}

async function submit() {
  if (!type.value || !files.faceImage) return

  const fd = new FormData()
  fd.append('applicant_type', type.value)
  fd.append('full_name', form.fullName)
  if (form.mobile) fd.append('mobile', form.mobile)
  fd.append('prc_number', form.prcNumber)
  fd.append('face_image', files.faceImage)

  if (type.value === 'broker') {
    if (files.licenseDoc) fd.append('license_doc', files.licenseDoc)
  } else {
    if (files.accreditationDoc) fd.append('accreditation_doc', files.accreditationDoc)
    if (files.validId) fd.append('valid_id', files.validId)
    if (form.supervisingBroker) fd.append('supervising_broker', form.supervisingBroker)
  }

  const profile = await submitVerification(fd)
  if (profile) {
    submittedProfile.value = profile
    reapply.value = false
    await fetchMe() // refresh store so My Profile tracking + "already applied" notice show immediately
  }
}

function startReapply() {
  if (inCooldown.value) return // still inside the 12h cool-down
  reapply.value = true
  submittedProfile.value = null
  type.value = null
  step.value = 0
}
</script>

<template>
  <div class="max-w-6xl">

    <!-- ░░ RealtyLink AI verifying overlay ░░ -->
    <Teleport to="body">
      <Transition name="verify-fade">
        <div
          v-if="loading"
          class="fixed inset-0 z-[95] flex flex-col items-center justify-center gap-7 px-6 text-center"
          style="background: linear-gradient(160deg, #0d1f3c 0%, #08152F 100%)"
        >
          <div class="relative h-32 w-32 flex items-center justify-center">
            <span class="absolute inset-0 rounded-full border-2 border-brand-gold/20" />
            <span class="absolute inset-0 rounded-full border-t-2 border-brand-gold animate-spin" />
            <span class="absolute inset-3 rounded-full border border-brand-gold/10 animate-ping" />
            <img src="/realtylink-ai1.png" alt="RealtyLink AI" class="h-24 w-24 object-contain drop-shadow-xl" />
          </div>
          <div class="max-w-md">
            <h2 class="font-playfair text-2xl font-bold text-white">RealtyLink AI is reviewing your application…</h2>
            <p class="text-white/60 text-sm mt-2">{{ verifyMsg }}</p>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="h-2 w-2 rounded-full bg-brand-gold ai-pulse" style="animation-delay:0ms" />
            <span class="h-2 w-2 rounded-full bg-brand-gold ai-pulse" style="animation-delay:200ms" />
            <span class="h-2 w-2 rounded-full bg-brand-gold ai-pulse" style="animation-delay:400ms" />
          </div>
          <p class="text-white/30 text-[11px]">This usually takes just a few seconds.</p>
        </div>
      </Transition>
    </Teleport>

    <!-- ════════ Submitted / Pending status ════════ -->
    <div v-if="showStatus" class="max-w-4xl bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-8">
      <div class="text-center">
        <div class="h-16 w-16 rounded-full bg-emerald-100 flex items-center justify-center mx-auto mb-4">
          <svg class="h-8 w-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
        </div>
        <h2 class="font-playfair text-2xl font-bold text-brand-navy dark:text-white">Application Submitted</h2>
        <p class="text-sm text-gray-500 dark:text-white/50 mt-2 max-w-md mx-auto">
          Your application is now <span class="font-semibold text-amber-600">pending admin review</span>. Our team reviews applications <span class="font-semibold text-brand-navy dark:text-white">within 24 hours</span> — we'll notify you of the outcome.
        </p>
      </div>

      <!-- AI initial review -->
      <div v-if="aiComment" class="mt-6 rounded-2xl border border-brand-gold/25 bg-brand-gold/5 p-5">
        <div class="flex items-center gap-2 mb-2">
          <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
          <p class="text-sm font-bold text-brand-navy dark:text-white">RealtyLink AI — initial review</p>
        </div>
        <p class="text-sm text-brand-navy/80 dark:text-white/70 leading-relaxed whitespace-pre-wrap">{{ aiComment }}</p>
        <p class="text-[11px] text-gray-400 dark:text-white/40 mt-3">This is an automated pre-check to assist the admin — it is not a final decision.</p>
      </div>
    </div>

    <!-- ════════ Rejected ════════ -->
    <div v-else-if="showRejected" class="max-w-2xl bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-8 text-center">
      <div class="h-16 w-16 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
        <svg class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
      </div>
      <h2 class="font-playfair text-2xl font-bold text-brand-navy dark:text-white">Application Not Approved</h2>
      <p v-if="existing?.admin_note" class="text-sm text-gray-500 dark:text-white/50 mt-2">Reason: {{ existing.admin_note }}</p>

      <!-- 12h cool-down countdown -->
      <div v-if="inCooldown" class="mt-5">
        <div class="inline-flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/10 border border-amber-200 dark:border-amber-500/20 rounded-xl px-4 py-2.5">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          You can re-apply in <span class="font-bold tabular-nums">{{ cooldownText }}</span>
        </div>
        <p class="text-[11px] text-gray-400 dark:text-white/40 mt-2">There's a 12-hour cool-down before you can submit a new application.</p>
      </div>

      <button
        v-else
        class="mt-5 inline-flex items-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-6 py-3 rounded-xl hover:-translate-y-0.5 transition-all"
        @click="startReapply"
      >
        Re-apply
      </button>
    </div>

    <!-- ════════ Wizard ════════ -->
    <div v-else>
      <!-- Header -->
      <div class="mb-6">
        <h1 class="font-playfair text-2xl font-bold text-brand-navy dark:text-white">Become a Verified Agent</h1>
        <p class="text-sm text-gray-500 dark:text-white/50 mt-1">Complete your application — RealtyLink AI runs an initial check, then an admin approves.</p>
      </div>

      <div class="grid lg:grid-cols-3 gap-6 lg:gap-8 items-start">
      <!-- ░░░░ LEFT: wizard ░░░░ -->
      <div class="lg:col-span-2">

      <!-- Progress -->
      <div v-if="step > 0" class="flex items-center gap-2 mb-6">
        <template v-for="(label, i) in stepLabels" :key="label">
          <div class="flex items-center gap-2">
            <div
              class="h-7 w-7 rounded-full flex items-center justify-center text-xs font-bold transition-colors"
              :class="step > i + 1 ? 'bg-brand-gold text-brand-navy' : step === i + 1 ? 'bg-brand-navy dark:bg-white text-white dark:text-brand-navy' : 'bg-gray-200 dark:bg-white/10 text-gray-400'"
            >
              <svg v-if="step > i + 1" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
              <span v-else>{{ i + 1 }}</span>
            </div>
            <span class="text-xs font-medium hidden sm:block" :class="step === i + 1 ? 'text-brand-navy dark:text-white' : 'text-gray-400'">{{ label }}</span>
          </div>
          <div v-if="i < stepLabels.length - 1" class="flex-1 h-px bg-gray-200 dark:bg-white/10 min-w-[12px]" />
        </template>
      </div>

      <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-6 sm:p-8">
        <p v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-5">{{ error }}</p>
        <p v-if="docError" class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5">{{ docError }}</p>

        <!-- ── Step 0: choose type ── -->
        <div v-if="step === 0">
          <h2 class="font-bold text-brand-navy dark:text-white mb-1">What are you applying as?</h2>
          <p class="text-xs text-gray-500 dark:text-white/40 mb-5">This determines the documents you'll need.</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <button
              type="button"
              class="text-left p-5 rounded-2xl border-2 border-gray-200 dark:border-white/10 hover:border-brand-gold hover:bg-brand-gold/5 transition-all"
              @click="chooseType('broker')"
            >
              <div class="h-10 w-10 rounded-xl bg-brand-gold/10 flex items-center justify-center mb-3">
                <svg class="h-5 w-5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
              </div>
              <p class="font-bold text-brand-navy dark:text-white">Real Estate Broker</p>
              <p class="text-xs text-gray-500 dark:text-white/40 mt-1">Licensed to transact independently. Requires your PRC broker license card.</p>
            </button>
            <button
              type="button"
              class="text-left p-5 rounded-2xl border-2 border-gray-200 dark:border-white/10 hover:border-brand-gold hover:bg-brand-gold/5 transition-all"
              @click="chooseType('salesperson')"
            >
              <div class="h-10 w-10 rounded-xl bg-brand-gold/10 flex items-center justify-center mb-3">
                <svg class="h-5 w-5 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
              </div>
              <p class="font-bold text-brand-navy dark:text-white">Real Estate Salesperson</p>
              <p class="text-xs text-gray-500 dark:text-white/40 mt-1">Accredited under a licensed broker. Requires accreditation + a valid ID.</p>
            </button>
          </div>
        </div>

        <!-- ── Information ── -->
        <div v-else-if="stepKey === 'Information'" class="space-y-5">
          <h2 class="font-bold text-brand-navy dark:text-white">Your information</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Full name</label>
              <input v-model="form.fullName" type="text" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold" />
            </div>
            <div>
              <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Mobile number</label>
              <input v-model="form.mobile" type="tel" placeholder="+63 9XX XXX XXXX" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">
              {{ type === 'broker' ? 'PRC License Number' : 'Accreditation Number' }}
            </label>
            <input v-model="form.prcNumber" type="text" placeholder="0123456" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold" />
          </div>
          <div v-if="type === 'salesperson'">
            <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Supervising broker <span class="text-gray-400 font-normal">(optional)</span></label>
            <input v-model="form.supervisingBroker" type="text" placeholder="Broker name / PRC no." class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold" />
          </div>
        </div>

        <!-- ── Document uploads ── -->
        <div v-else-if="stepKey === 'License Card' || stepKey === 'Accreditation' || stepKey === 'Valid ID'">
          <h2 class="font-bold text-brand-navy dark:text-white mb-1">
            {{ stepKey === 'License Card' ? 'Broker license card' : stepKey === 'Accreditation' ? 'Accreditation document (front)' : 'Valid ID' }}
          </h2>
          <p class="text-xs text-gray-500 dark:text-white/40 mb-4">Upload a clear photo or PDF. JPG, PNG, or PDF up to 5MB.</p>

          <label
            class="block border-2 border-dashed border-gray-300 dark:border-white/15 hover:border-brand-gold rounded-2xl p-6 cursor-pointer transition-colors text-center"
          >
            <template v-if="stepKey === 'License Card'">
              <img v-if="previews.licenseDoc" :src="previews.licenseDoc" class="max-h-48 mx-auto rounded-lg" />
              <div v-else-if="files.licenseDoc" class="text-sm text-brand-navy dark:text-white">📄 {{ files.licenseDoc.name }}</div>
              <div v-else class="py-6 text-gray-400">
                <svg class="h-9 w-9 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                <p class="text-sm">Click to upload license card</p>
              </div>
              <input type="file" class="sr-only" accept="image/*,application/pdf" @change="setDoc('licenseDoc', $event)" />
            </template>
            <template v-else-if="stepKey === 'Accreditation'">
              <img v-if="previews.accreditationDoc" :src="previews.accreditationDoc" class="max-h-48 mx-auto rounded-lg" />
              <div v-else-if="files.accreditationDoc" class="text-sm text-brand-navy dark:text-white">📄 {{ files.accreditationDoc.name }}</div>
              <div v-else class="py-6 text-gray-400">
                <svg class="h-9 w-9 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                <p class="text-sm">Click to upload accreditation (front)</p>
              </div>
              <input type="file" class="sr-only" accept="image/*,application/pdf" @change="setDoc('accreditationDoc', $event)" />
            </template>
            <template v-else>
              <img v-if="previews.validId" :src="previews.validId" class="max-h-48 mx-auto rounded-lg" />
              <div v-else-if="files.validId" class="text-sm text-brand-navy dark:text-white">📄 {{ files.validId.name }}</div>
              <div v-else class="py-6 text-gray-400">
                <svg class="h-9 w-9 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" /></svg>
                <p class="text-sm">Click to upload your valid ID</p>
              </div>
              <input type="file" class="sr-only" accept="image/*,application/pdf" @change="setDoc('validId', $event)" />
            </template>
          </label>
        </div>

        <!-- ── Live Scan ── -->
        <div v-else-if="stepKey === 'Live Scan'">
          <h2 class="font-bold text-brand-navy dark:text-white mb-1">Live face scan</h2>
          <p class="text-xs text-gray-500 dark:text-white/40 mb-4">
            We use this to confirm you're a real applicant<span v-if="type === 'broker'"> and to compare with your license photo</span>. Your camera is only used when you start it.
          </p>
          <LiveFaceCapture @captured="setFace" />
        </div>

        <!-- ── Review ── -->
        <div v-else-if="stepKey === 'Review'" class="space-y-4">
          <h2 class="font-bold text-brand-navy dark:text-white mb-1">Review &amp; submit</h2>
          <p class="text-xs text-gray-500 dark:text-white/40 mb-4">Please confirm everything looks right before submitting for review.</p>

          <!-- Details -->
          <div class="rounded-2xl border border-gray-200 dark:border-white/10 divide-y divide-gray-100 dark:divide-white/10 overflow-hidden">
            <div class="flex items-center justify-between px-4 py-3 text-sm">
              <span class="text-gray-500 dark:text-white/50">Applying as</span>
              <span class="font-semibold text-brand-navy dark:text-white capitalize">{{ type }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3 text-sm">
              <span class="text-gray-500 dark:text-white/50">Full name</span>
              <span class="font-semibold text-brand-navy dark:text-white">{{ form.fullName }}</span>
            </div>
            <div v-if="form.mobile" class="flex items-center justify-between px-4 py-3 text-sm">
              <span class="text-gray-500 dark:text-white/50">Mobile</span>
              <span class="font-semibold text-brand-navy dark:text-white">{{ form.mobile }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3 text-sm">
              <span class="text-gray-500 dark:text-white/50">{{ type === 'broker' ? 'PRC license no.' : 'Accreditation no.' }}</span>
              <span class="font-semibold text-brand-navy dark:text-white">{{ form.prcNumber || '—' }}</span>
            </div>
          </div>

          <!-- Uploaded documents + face -->
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-2xl border border-gray-200 dark:border-white/10 p-3">
              <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-white/40 mb-2">{{ type === 'broker' ? 'License card' : 'Accreditation' }}</p>
              <img v-if="type === 'broker' ? previews.licenseDoc : previews.accreditationDoc" :src="type === 'broker' ? previews.licenseDoc : previews.accreditationDoc" class="w-full h-28 object-cover rounded-lg" />
              <div v-else class="h-28 flex items-center justify-center text-xs text-gray-400 dark:text-white/40">📄 PDF uploaded</div>
            </div>
            <div class="rounded-2xl border border-gray-200 dark:border-white/10 p-3">
              <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-white/40 mb-2">Live face scan</p>
              <img v-if="previews.faceImage" :src="previews.faceImage" class="w-full h-28 object-cover rounded-lg" />
              <div v-else class="h-28 flex items-center justify-center text-xs text-gray-400 dark:text-white/40">Captured ✓</div>
            </div>
          </div>

          <div v-if="type === 'salesperson'" class="rounded-2xl border border-gray-200 dark:border-white/10 p-3">
            <p class="text-[10px] font-bold uppercase tracking-wide text-gray-400 dark:text-white/40 mb-2">Valid ID</p>
            <img v-if="previews.validId" :src="previews.validId" class="w-full h-28 object-cover rounded-lg" />
            <div v-else class="h-28 flex items-center justify-center text-xs text-gray-400 dark:text-white/40">📄 PDF uploaded</div>
          </div>

          <p class="text-xs text-gray-500 dark:text-white/40 flex items-center gap-1.5">
            <svg class="h-3.5 w-3.5 text-brand-gold flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
            Your documents are encrypted and used only for verification.
          </p>
        </div>

        <!-- Nav buttons -->
        <div v-if="step > 0" class="flex items-center justify-between mt-7 pt-5 border-t border-gray-100 dark:border-white/10">
          <button type="button" class="text-sm font-semibold text-gray-500 dark:text-white/50 hover:text-brand-navy dark:hover:text-white flex items-center gap-1" @click="back">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" /></svg>
            Back
          </button>

          <button
            v-if="step < lastStep"
            type="button"
            :disabled="!canAdvance"
            class="inline-flex items-center gap-2 bg-brand-navy dark:bg-brand-gold text-white dark:text-brand-navy font-bold text-sm px-6 py-2.5 rounded-xl hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
            @click="next"
          >
            Continue
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
          </button>

          <button
            v-else
            type="button"
            :disabled="!canAdvance || loading"
            class="inline-flex items-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-6 py-2.5 rounded-xl shadow-[0_4px_18px_rgba(212,175,55,0.35)] hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
            @click="submit"
          >
            <svg v-if="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
            {{ loading ? 'Submitting & running AI check…' : 'Submit application' }}
          </button>
        </div>
      </div>
      </div><!-- /left -->

      <!-- ░░░░ RIGHT: sticky context panel ░░░░ -->
      <aside class="lg:sticky lg:top-6 space-y-4">

        <!-- What you'll need (live checklist) -->
        <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-5">
          <p class="text-sm font-bold text-brand-navy dark:text-white mb-3">What you'll need</p>
          <p v-if="!type" class="text-xs text-gray-500 dark:text-white/50 leading-relaxed">
            Pick <span class="font-semibold text-brand-navy dark:text-white">Broker</span> or
            <span class="font-semibold text-brand-navy dark:text-white">Salesperson</span> to see the exact documents for your path.
          </p>
          <ul v-else class="space-y-3">
            <li v-for="item in checklist" :key="item.label" class="flex items-start gap-3">
              <span
                class="mt-0.5 h-5 w-5 rounded-full flex items-center justify-center flex-shrink-0 transition-colors"
                :class="item.done ? 'bg-emerald-500' : 'bg-gray-200 dark:bg-white/10'"
              >
                <svg v-if="item.done" class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
                <span v-else class="h-1.5 w-1.5 rounded-full bg-gray-400 dark:bg-white/30" />
              </span>
              <div class="min-w-0">
                <p class="text-xs font-semibold" :class="item.done ? 'text-brand-navy dark:text-white' : 'text-gray-500 dark:text-white/60'">{{ item.label }}</p>
                <p class="text-[11px] text-gray-400 dark:text-white/40">{{ item.desc }}</p>
              </div>
            </li>
          </ul>
        </div>

        <!-- How verification works -->
        <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-5">
          <p class="text-sm font-bold text-brand-navy dark:text-white mb-3">How verification works</p>
          <ol class="space-y-3">
            <li class="flex gap-3">
              <span class="h-6 w-6 rounded-full bg-brand-navy dark:bg-white text-white dark:text-brand-navy text-xs font-bold flex items-center justify-center flex-shrink-0">1</span>
              <p class="text-xs text-gray-600 dark:text-white/70 leading-relaxed pt-0.5">You submit your documents and a live face scan.</p>
            </li>
            <li class="flex gap-3">
              <span class="h-6 w-6 rounded-full bg-brand-gold text-brand-navy text-xs font-bold flex items-center justify-center flex-shrink-0">2</span>
              <p class="text-xs text-gray-600 dark:text-white/70 leading-relaxed pt-0.5"><span class="font-semibold text-brand-navy dark:text-white">RealtyLink AI</span> runs an instant pre-check on your application.</p>
            </li>
            <li class="flex gap-3">
              <span class="h-6 w-6 rounded-full bg-brand-navy dark:bg-white text-white dark:text-brand-navy text-xs font-bold flex items-center justify-center flex-shrink-0">3</span>
              <p class="text-xs text-gray-600 dark:text-white/70 leading-relaxed pt-0.5">An admin reviews and makes the final approval.</p>
            </li>
          </ol>
          <p class="text-[11px] text-gray-400 dark:text-white/40 mt-3">Reviews are usually completed within 1–2 business days.</p>
        </div>

        <!-- Benefits -->
        <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-5">
          <p class="text-sm font-bold text-brand-navy dark:text-white mb-3">Why become verified?</p>
          <ul class="space-y-2.5">
            <li v-for="b in benefits" :key="b" class="flex items-start gap-2.5">
              <svg class="h-4 w-4 text-brand-gold flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
              <span class="text-xs text-gray-600 dark:text-white/70">{{ b }}</span>
            </li>
          </ul>
        </div>

        <!-- Tips for fast approval -->
        <div class="rounded-2xl border border-brand-gold/25 bg-brand-gold/5 p-5">
          <p class="text-sm font-bold text-brand-navy dark:text-white mb-2.5 flex items-center gap-1.5">
            <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
            Tips for fast approval
          </p>
          <ul class="space-y-1.5 text-xs text-brand-navy/80 dark:text-white/70 list-disc list-inside marker:text-brand-gold">
            <li>Use clear, well-lit photos — no glare or blur.</li>
            <li>Make sure your name matches your PRC record.</li>
            <li>Face the camera in good lighting for the scan.</li>
            <li>Upload the front of documents, fully in frame.</li>
          </ul>
        </div>

      </aside>
      </div><!-- /grid -->
    </div>
  </div>
</template>

<style scoped>
.verify-fade-enter-active, .verify-fade-leave-active { transition: opacity .3s ease; }
.verify-fade-enter-from, .verify-fade-leave-to { opacity: 0; }

.ai-pulse { animation: ai-pulse 1.1s infinite ease-in-out; }
@keyframes ai-pulse {
  0%, 100% { transform: scale(0.7); opacity: 0.4; }
  50%      { transform: scale(1);   opacity: 1; }
}
</style>
