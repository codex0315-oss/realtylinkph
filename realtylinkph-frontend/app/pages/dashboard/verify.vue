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
  await router.push('/dashboard')
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

/* ── Submit overlay ──
   The bar is driven by bytes uploaded, so it's honest: the selfie and
   documents are the slow part of submitting, especially from a phone. Once
   the upload completes the server saves the application and answers — that
   last stretch is short and shown as "Saving". The AI pre-check runs in the
   background for the admin; the applicant never waits on it. */
const uploadPercent = ref(0)
const submitPhase = computed(() => uploadPercent.value < 100 ? 'Uploading your documents…' : 'Saving your application…')

watch(loading, (v) => {
  if (import.meta.client) document.body.style.overflow = v ? 'hidden' : ''
  if (v) uploadPercent.value = 0
})

onUnmounted(() => {
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

  const profile = await submitVerification(fd, (p) => { uploadPercent.value = p })
  if (profile) {
    submittedProfile.value = profile
    reapply.value = false
    await fetchMe() // refresh store so My Profile tracking + "already applied" notice show immediately
  }
}

/* ── Redesign helpers ── */

/* Drag a file onto an upload box: same checks as picking one. */
const dragKey = ref<string | null>(null)
function dropDoc(key: 'accreditationDoc' | 'validId' | 'licenseDoc', e: DragEvent) {
  dragKey.value = null
  const file = e.dataTransfer?.files?.[0]
  if (!file) return
  setDoc(key, { target: { files: [file], value: '' } } as unknown as Event)
}
function clearDoc(key: 'accreditationDoc' | 'validId' | 'licenseDoc') {
  files[key] = null
  previews[key] = ''
}

/* Review → "Edit" jumps straight to that step. */
function goStep(label: string) {
  const i = stepLabels.value.indexOf(label)
  if (i >= 0) step.value = i + 1
}

/* What each path involves (start screen). */
const PATHS = [
  {
    type: 'broker' as ApplicantType,
    title: 'Real Estate Broker',
    blurb: 'Licensed by the PRC to transact on your own.',
    needs: ['PRC broker license number', 'Photo of your PRC license card', 'A live face scan'],
    time: 'About 3 minutes',
    icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
  },
  {
    type: 'salesperson' as ApplicantType,
    title: 'Real Estate Salesperson',
    blurb: 'Accredited and working under a licensed broker.',
    needs: ['Accreditation number', 'Accreditation document + a valid ID', 'A live face scan'],
    time: 'About 4 minutes',
    icon: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
  },
]

/* Submitted / pending: where the application is. */
const pending = computed(() => submittedProfile.value ?? existing.value)
const submittedOn = computed(() =>
  pending.value?.created_at ? new Date(pending.value.created_at).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) : '',
)
const TRACK = computed(() => [
  { label: 'Submitted',      sub: submittedOn.value || 'Received', state: 'done' },
  { label: 'AI pre-check',   sub: pending.value?.ai_comment ? 'Done' : 'Running in the background', state: pending.value?.ai_comment ? 'done' : 'active' },
  { label: 'Admin review',   sub: 'Usually within 24 hours', state: pending.value?.ai_comment ? 'active' : 'todo' },
  { label: 'Approved',       sub: 'You can start listing', state: 'todo' },
])

const fieldCls =
  'w-full rounded-2xl border-0 px-4 py-3 text-[0.9375rem] text-brand-navy dark:text-white bg-brand-navy/[0.04] dark:bg-white/[0.06] ' +
  'ring-1 ring-inset ring-brand-navy/10 dark:ring-white/10 placeholder-brand-navy/40 dark:placeholder-white/35 ' +
  'focus:bg-white dark:focus:bg-white/[0.09] focus:ring-2 focus:ring-brand-gold dark:focus:ring-brand-gold transition'
const labelCls = 'mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white/90'

/* Which file the current upload step is for. */
const docKey = computed<'licenseDoc' | 'accreditationDoc' | 'validId' | null>(() =>
  stepKey.value === 'License Card' ? 'licenseDoc'
  : stepKey.value === 'Accreditation' ? 'accreditationDoc'
  : stepKey.value === 'Valid ID' ? 'validId' : null,
)
const reviewDocs = computed(() => type.value === 'broker'
  ? [{ k: 'licenseDoc', label: 'License card', step: 'License Card' }]
  : [{ k: 'accreditationDoc', label: 'Accreditation', step: 'Accreditation' }, { k: 'validId', label: 'Valid ID', step: 'Valid ID' }])
/* Submitted screen: what was sent, and useful things to do meanwhile. */
const sentDocs = computed(() => [
  ...(pending.value?.applicant_type === 'broker' ? ['PRC license card'] : ['Accreditation document', 'Valid ID']),
  'Live face scan',
])
const WHILE_WAITING = [
  { to: '/dashboard/browse', label: 'Keep browsing', hint: 'See what agents are listing', icon: 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z' },
  { to: '/dashboard/profile', label: 'Finish your profile', hint: 'Photo, cover and phone', icon: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z' },
  { to: '/dashboard/profile?section=apps', label: 'Connect Google Calendar', hint: 'Viewings sync once you list', icon: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5' },
]
const TIPS = [
  'Clear, well-lit photos with no glare or blur',
  'Your name matches your PRC record',
  'The whole document, front side, in frame',
  'Good lighting on your face for the scan',
]

function startReapply() {
  if (inCooldown.value) return // still inside the 12h cool-down
  reapply.value = true
  submittedProfile.value = null
  type.value = null
  step.value = 0
}
</script>

<template>
  <div class="max-w-6xl mx-auto">

    <!-- ░░ Submit overlay: real upload progress ░░ -->
    <Teleport to="body">
      <Transition name="verify-fade">
        <div v-if="loading" class="fixed inset-0 z-[95] overflow-hidden flex items-center justify-center px-6 bg-brand-cream dark:bg-brand-navy-deep">
          <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
          <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
          <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[28rem] w-[28rem] max-w-full rounded-full bg-brand-gold/15 dark:bg-brand-gold/[0.08] blur-[110px] pointer-events-none" />
          <div class="relative w-full max-w-xs flex flex-col items-center text-center" role="status" aria-live="polite">
            <img src="/realtylinkphlogo-trim.png" alt="RealtyLinkPH" class="h-20 w-auto block dark:hidden" />
            <img src="/realtylinkphlogo-light-trim.png" alt="RealtyLinkPH" class="h-20 w-auto hidden dark:block" />
            <p class="mt-7 font-heading text-[1.375rem] font-semibold text-brand-navy dark:text-white">Submitting your application</p>
            <div class="mt-5 h-1.5 w-full overflow-hidden rounded-full bg-brand-navy/10 dark:bg-white/10" role="progressbar" :aria-valuenow="uploadPercent" aria-valuemin="0" aria-valuemax="100" aria-label="Upload">
              <div class="h-full rounded-full bg-gradient-to-r from-brand-gold-deep via-brand-gold to-brand-gold-light transition-[width] duration-200 ease-out" :style="{ width: uploadPercent + '%' }" />
            </div>
            <p class="mt-3 flex w-full items-center justify-between text-xs font-medium text-brand-navy/55 dark:text-white/55">
              <span>{{ submitPhase }}</span>
              <span class="tabular-nums font-bold text-brand-gold-deep dark:text-brand-gold">{{ uploadPercent }}%</span>
            </p>
            <p class="mt-10 text-[0.6875rem] text-brand-navy/40 dark:text-white/35">Please keep this page open until it finishes.</p>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- ── Page heading (every state) ── -->
    <header class="mb-6">
      <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Agent verification</p>
      <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">Become a Verified Agent</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
        <template v-if="showStatus">Your application is in. Here's where it stands.</template>
        <template v-else-if="showRejected">Your last application wasn't approved. Here's why, and when you can try again.</template>
        <template v-else>A few minutes of details and documents. RealtyLink AI runs a first check, then an admin approves.</template>
      </p>
    </header>

    <!-- ════════ Submitted / under review ════════ -->
    <div v-if="showStatus" class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_20rem] gap-6 items-start">
    <section class="vcard lg:sticky lg:top-24">
      <div class="flex flex-wrap items-start gap-4">
        <span class="h-14 w-14 flex-none rounded-2xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center">
          <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        </span>
        <div class="min-w-0 flex-1">
          <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[0.6875rem] font-bold uppercase tracking-wide bg-amber-500/10 text-amber-700 dark:text-amber-400">
            <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse motion-reduce:animate-none" /> Under review
          </span>
          <h2 class="mt-2 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Application submitted</h2>
          <p class="mt-1 text-sm text-brand-text-secondary dark:text-white/55">
            {{ pending?.applicant_type === 'broker' ? 'Real Estate Broker' : 'Real Estate Salesperson' }} application<template v-if="submittedOn">, sent {{ submittedOn }}</template>.
            An admin usually decides <span class="font-semibold text-brand-navy dark:text-white">within 24 hours</span>, and we'll notify you either way.
          </p>
        </div>
      </div>

      <!-- Tracker -->
      <ol class="mt-7 grid grid-cols-1 sm:grid-cols-4 gap-3">
        <li
          v-for="(t, i) in TRACK"
          :key="t.label"
          class="rounded-2xl p-4 ring-1"
          :class="t.state === 'done' ? 'ring-emerald-500/30 bg-emerald-500/[0.06]' : t.state === 'active' ? 'ring-brand-gold bg-brand-gold/[0.08]' : 'ring-brand-navy/[0.08] dark:ring-white/10'"
        >
          <span
            class="h-7 w-7 rounded-full flex items-center justify-center text-xs font-bold"
            :class="t.state === 'done' ? 'bg-emerald-500 text-white' : t.state === 'active' ? 'bg-brand-gold text-brand-navy' : 'bg-brand-navy/[0.07] text-brand-navy/50 dark:bg-white/10 dark:text-white/50'"
          >
            <svg v-if="t.state === 'done'" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
            <template v-else>{{ i + 1 }}</template>
          </span>
          <p class="mt-2.5 text-sm font-semibold text-brand-navy dark:text-white">{{ t.label }}</p>
          <p class="text-xs text-brand-navy/55 dark:text-white/50">{{ t.sub }}</p>
        </li>
      </ol>

      <div v-if="pending?.ai_comment" class="mt-6 rounded-2xl bg-brand-gold/[0.07] ring-1 ring-brand-gold/25 p-4">
        <p class="flex items-center gap-2 text-xs font-bold text-brand-navy dark:text-white">
          <img src="/ai/avatar-96.webp" alt="" class="h-6 w-6 rounded-full object-cover" /> RealtyLink AI · first check
        </p>
        <p class="mt-2 text-sm leading-relaxed text-brand-navy/80 dark:text-white/70 whitespace-pre-wrap">{{ pending.ai_comment }}</p>
        <p class="mt-2 text-[0.6875rem] text-brand-navy/45 dark:text-white/40">This is only a helper note. The admin makes the decision.</p>
      </div>

      <div class="mt-6 flex flex-wrap gap-2">
        <NuxtLink to="/dashboard" class="rounded-full bg-brand-navy px-5 py-2.5 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 transition">Back to dashboard</NuxtLink>
        <NuxtLink to="/dashboard/profile?section=application" class="rounded-full px-5 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition">Track it in My Profile</NuxtLink>
      </div>
    </section>
      <aside class="space-y-4">
        <section class="vside">
          <p class="text-sm font-bold text-brand-navy dark:text-white">What you sent</p>
          <dl class="mt-3 space-y-2.5 text-sm">
            <div class="flex items-center justify-between gap-3">
              <dt class="text-brand-navy/55 dark:text-white/50">Applying as</dt>
              <dd class="font-semibold text-brand-navy dark:text-white">{{ pending?.applicant_type === 'broker' ? 'Broker' : 'Salesperson' }}</dd>
            </div>
            <div v-if="pending?.prc_number" class="flex items-center justify-between gap-3">
              <dt class="text-brand-navy/55 dark:text-white/50">{{ pending?.applicant_type === 'broker' ? 'PRC license no.' : 'Accreditation no.' }}</dt>
              <dd class="font-semibold tabular-nums text-brand-navy dark:text-white">{{ pending.prc_number }}</dd>
            </div>
          </dl>
          <ul class="mt-4 space-y-2 border-t border-brand-navy/[0.07] dark:border-white/10 pt-4">
            <li v-for="d in sentDocs" :key="d" class="flex items-center gap-2.5 text-sm text-brand-navy/80 dark:text-white/75">
              <span class="h-5 w-5 flex-none rounded-full flex items-center justify-center bg-emerald-500 text-white">
                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              </span>
              {{ d }}
            </li>
          </ul>
        </section>

        <section class="vside">
          <p class="text-sm font-bold text-brand-navy dark:text-white">While you wait</p>
          <ul class="mt-3 space-y-1.5">
            <li v-for="w in WHILE_WAITING" :key="w.to">
              <NuxtLink :to="w.to" class="group flex items-center gap-3 rounded-xl p-2 -mx-2 hover:bg-brand-navy/[0.04] dark:hover:bg-white/[0.05] transition">
                <span class="h-8 w-8 flex-none rounded-lg flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="w.icon" /></svg>
                </span>
                <span class="min-w-0 flex-1">
                  <span class="block text-sm font-semibold text-brand-navy dark:text-white">{{ w.label }}</span>
                  <span class="block text-xs text-brand-navy/50 dark:text-white/45">{{ w.hint }}</span>
                </span>
                <svg class="h-4 w-4 flex-none text-brand-navy/30 group-hover:text-brand-gold-deep dark:text-white/30 dark:group-hover:text-brand-gold transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </NuxtLink>
            </li>
          </ul>
        </section>

        <section class="relative overflow-hidden rounded-3xl p-5 bg-brand-navy text-white ring-1 ring-brand-gold/25">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Once you're approved</p>
          <ul class="relative mt-3 space-y-2.5">
            <li v-for="b in benefits" :key="b" class="flex items-start gap-2.5 text-sm text-white/85">
              <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              {{ b }}
            </li>
          </ul>
        </section>
      </aside>
    </div>

    <!-- ════════ Not approved ════════ -->
    <div v-else-if="showRejected" class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_20rem] gap-6 items-start">
    <section class="vcard lg:sticky lg:top-24">
      <div class="flex flex-wrap items-start gap-4">
        <span class="h-14 w-14 flex-none rounded-2xl bg-red-500/10 text-red-600 dark:text-red-400 flex items-center justify-center">
          <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
        </span>
        <div class="min-w-0 flex-1">
          <span class="inline-flex rounded-full px-2.5 py-1 text-[0.6875rem] font-bold uppercase tracking-wide bg-red-500/10 text-red-600 dark:text-red-400">Not approved</span>
          <h2 class="mt-2 font-heading text-2xl font-semibold text-brand-navy dark:text-white">Your application wasn't approved this time</h2>
        </div>
      </div>

      <div v-if="existing?.admin_note" class="mt-5 rounded-2xl bg-red-500/[0.06] ring-1 ring-red-500/20 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-red-700/70 dark:text-red-300/70">Reason from the admin</p>
        <p class="mt-1 text-sm text-red-800 dark:text-red-200">{{ existing.admin_note }}</p>
      </div>

      <p class="mt-5 mb-2 text-sm font-semibold text-brand-navy dark:text-white">Before you re-apply</p>
      <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm text-brand-navy/75 dark:text-white/70">
        <li v-for="t in TIPS" :key="t" class="flex items-start gap-2">
          <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
          {{ t }}
        </li>
      </ul>

      <div class="mt-6 flex flex-wrap items-center gap-3">
        <div v-if="inCooldown" class="inline-flex items-center gap-2 rounded-full bg-amber-500/10 px-4 py-2.5 text-sm text-amber-800 dark:text-amber-300">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          You can re-apply in <span class="font-bold tabular-nums">{{ cooldownText }}</span>
        </div>
        <button v-else type="button" class="rounded-full bg-brand-gold px-6 py-2.5 text-sm font-bold text-brand-navy shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition" @click="startReapply">
          Re-apply now
        </button>
      </div>
      <p class="mt-3 text-xs text-brand-navy/45 dark:text-white/40">This review clears from your account when the wait is over, and your uploaded documents are deleted.</p>
    </section>
      <aside class="space-y-4">
        <section class="vside">
          <p class="text-sm font-bold text-brand-navy dark:text-white">Your last application</p>
          <dl class="mt-3 space-y-2.5 text-sm">
            <div class="flex items-center justify-between gap-3">
              <dt class="text-brand-navy/55 dark:text-white/50">Applied as</dt>
              <dd class="font-semibold text-brand-navy dark:text-white">{{ existing?.applicant_type === 'broker' ? 'Broker' : 'Salesperson' }}</dd>
            </div>
            <div v-if="existing?.prc_number" class="flex items-center justify-between gap-3">
              <dt class="text-brand-navy/55 dark:text-white/50">{{ existing?.applicant_type === 'broker' ? 'PRC license no.' : 'Accreditation no.' }}</dt>
              <dd class="font-semibold tabular-nums text-brand-navy dark:text-white">{{ existing.prc_number }}</dd>
            </div>
            <div v-if="existing?.reviewed_at" class="flex items-center justify-between gap-3">
              <dt class="text-brand-navy/55 dark:text-white/50">Reviewed</dt>
              <dd class="font-semibold text-brand-navy dark:text-white">{{ new Date(existing.reviewed_at).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }) }}</dd>
            </div>
          </dl>
        </section>

        <section class="vside">
          <p class="text-sm font-bold text-brand-navy dark:text-white">How verification works</p>
          <ol class="mt-3 space-y-3">
            <li class="flex gap-3"><span class="h-6 w-6 flex-none rounded-full flex items-center justify-center text-xs font-bold bg-brand-navy text-white dark:bg-white dark:text-brand-navy">1</span><p class="pt-0.5 text-xs leading-relaxed text-brand-navy/70 dark:text-white/65">You send your details, documents and a live face scan.</p></li>
            <li class="flex gap-3"><span class="h-6 w-6 flex-none rounded-full flex items-center justify-center text-xs font-bold bg-brand-gold text-brand-navy">2</span><p class="pt-0.5 text-xs leading-relaxed text-brand-navy/70 dark:text-white/65"><span class="font-semibold text-brand-navy dark:text-white">RealtyLink AI</span> runs a first check for the admin.</p></li>
            <li class="flex gap-3"><span class="h-6 w-6 flex-none rounded-full flex items-center justify-center text-xs font-bold bg-brand-navy text-white dark:bg-white dark:text-brand-navy">3</span><p class="pt-0.5 text-xs leading-relaxed text-brand-navy/70 dark:text-white/65">An admin reviews it and approves, usually within 24 hours.</p></li>
          </ol>
        </section>
      </aside>
    </div>

    <!-- ════════ Wizard ════════ -->
    <div v-else class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_20rem] gap-6 items-start">
      <div class="min-w-0">

        <!-- Step bar -->
        <div v-if="step > 0" class="mb-5 rounded-2xl px-5 py-4 bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10">
          <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-semibold text-brand-navy dark:text-white">
              Step {{ step }} of {{ lastStep }} · <span class="text-brand-gold-deep dark:text-brand-gold">{{ stepKey }}</span>
            </p>
            <p class="text-xs text-brand-navy/50 dark:text-white/45">{{ type === 'broker' ? 'Broker' : 'Salesperson' }} application</p>
          </div>
          <ol class="mt-3 flex gap-1.5" aria-label="Progress">
            <li v-for="(label, i) in stepLabels" :key="label" class="flex-1 min-w-0">
              <span
                class="block h-1.5 rounded-full transition-colors duration-300"
                :class="step > i + 1 ? 'bg-emerald-500' : step === i + 1 ? 'bg-brand-gold' : 'bg-brand-navy/10 dark:bg-white/10'"
              />
              <span
                class="mt-1.5 hidden sm:block truncate text-[0.6875rem]"
                :class="step === i + 1 ? 'font-bold text-brand-navy dark:text-white' : step > i + 1 ? 'text-brand-navy/60 dark:text-white/55' : 'text-brand-navy/35 dark:text-white/30'"
              >{{ label }}</span>
            </li>
          </ol>
        </div>

        <section class="vcard">
          <p v-if="error" class="mb-5 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ error }}</p>
          <p v-if="docError" class="mb-5 rounded-xl bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-300">{{ docError }}</p>

          <Transition name="vstep" mode="out-in">
            <!-- ── Step 0: choose a path ── -->
            <div v-if="step === 0" key="choose">
              <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">What are you applying as?</h2>
              <p class="mt-1 mb-5 text-sm text-brand-text-secondary dark:text-white/55">This decides which documents we ask for.</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <button
                  v-for="p in PATHS"
                  :key="p.type"
                  type="button"
                  class="group flex flex-col text-left rounded-3xl p-5 ring-1 ring-brand-navy/10 hover:ring-2 hover:ring-brand-gold hover:-translate-y-0.5 hover:shadow-[0_18px_40px_-26px_rgba(11,42,92,0.55)] dark:ring-white/10 dark:hover:ring-brand-gold transition"
                  @click="chooseType(p.type)"
                >
                  <span class="h-11 w-11 rounded-2xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold group-hover:bg-brand-gold group-hover:text-brand-navy transition-colors">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="p.icon" /></svg>
                  </span>
                  <span class="mt-4 font-heading text-lg font-semibold text-brand-navy dark:text-white">{{ p.title }}</span>
                  <span class="mt-0.5 text-sm text-brand-text-secondary dark:text-white/55">{{ p.blurb }}</span>
                  <span class="mt-4 text-[0.6875rem] font-bold uppercase tracking-[0.14em] text-brand-navy/45 dark:text-white/40">You'll need</span>
                  <span class="mt-2 space-y-1.5">
                    <span v-for="n in p.needs" :key="n" class="flex items-start gap-2 text-sm text-brand-navy/80 dark:text-white/75">
                      <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                      {{ n }}
                    </span>
                  </span>
                  <span class="mt-auto pt-5 flex items-center justify-between">
                    <span class="text-xs text-brand-navy/50 dark:text-white/45">{{ p.time }}</span>
                    <span class="inline-flex items-center gap-1 text-sm font-bold text-brand-gold-deep dark:text-brand-gold">
                      Start
                      <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </span>
                  </span>
                </button>
              </div>
            </div>

            <!-- ── Information ── -->
            <div v-else-if="stepKey === 'Information'" key="info">
              <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Your information</h2>
              <p class="mt-1 mb-6 text-sm text-brand-text-secondary dark:text-white/55">Use your name exactly as it appears on your PRC record.</p>
              <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                  <label for="v-name" :class="labelCls">Full name <span class="text-red-500" aria-hidden="true">*</span></label>
                  <input id="v-name" v-model="form.fullName" type="text" autocomplete="name" aria-required="true" :class="fieldCls" />
                </div>
                <div>
                  <label for="v-mobile" :class="labelCls">Mobile number</label>
                  <input id="v-mobile" v-model="form.mobile" type="tel" autocomplete="tel" placeholder="+63 9XX XXX XXXX" :class="fieldCls" />
                </div>
                <div>
                  <label for="v-prc" :class="labelCls">{{ type === 'broker' ? 'PRC license number' : 'Accreditation number' }} <span class="text-red-500" aria-hidden="true">*</span></label>
                  <input id="v-prc" v-model="form.prcNumber" type="text" placeholder="0123456" aria-required="true" :class="fieldCls" />
                </div>
                <div v-if="type === 'salesperson'">
                  <label for="v-broker" :class="labelCls">Supervising broker <span class="text-xs font-medium text-brand-navy/40 dark:text-white/40">Optional</span></label>
                  <input id="v-broker" v-model="form.supervisingBroker" type="text" placeholder="Broker name / PRC no." :class="fieldCls" />
                </div>
              </div>
            </div>

            <!-- ── Document upload ── -->
            <div v-else-if="docKey" :key="stepKey">
              <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">
                {{ stepKey === 'License Card' ? 'Your PRC license card' : stepKey === 'Accreditation' ? 'Your accreditation document' : 'A valid government ID' }}
              </h2>
              <p class="mt-1 mb-5 text-sm text-brand-text-secondary dark:text-white/55">A clear photo or PDF of the front. JPG, PNG, WebP or PDF, up to 5 MB.</p>

              <!-- Picked -->
              <div v-if="files[docKey]" class="flex flex-col sm:flex-row items-center gap-4 rounded-3xl p-4 ring-1 ring-emerald-500/30 bg-emerald-500/[0.05]">
                <img v-if="previews[docKey]" :src="previews[docKey]" alt="" class="h-36 w-full sm:w-56 flex-none rounded-2xl object-cover ring-1 ring-brand-navy/10" />
                <span v-else class="h-36 w-full sm:w-56 flex-none rounded-2xl flex flex-col items-center justify-center bg-white dark:bg-white/[0.06] ring-1 ring-brand-navy/10 dark:ring-white/10 text-brand-navy/60 dark:text-white/60">
                  <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                  <span class="mt-1 text-xs font-bold">PDF</span>
                </span>
                <div class="min-w-0 flex-1 text-center sm:text-left">
                  <p class="flex items-center justify-center sm:justify-start gap-1.5 text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                    Uploaded
                  </p>
                  <p class="mt-0.5 truncate text-sm text-brand-navy dark:text-white">{{ files[docKey]!.name }}</p>
                  <p class="text-xs text-brand-navy/50 dark:text-white/45">{{ (files[docKey]!.size / 1024 / 1024).toFixed(1) }} MB</p>
                  <div class="mt-3 flex justify-center sm:justify-start gap-2">
                    <label class="cursor-pointer rounded-full px-4 py-2 text-xs font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition">
                      Replace
                      <input type="file" class="sr-only" accept="image/*,application/pdf" @change="setDoc(docKey, $event)" />
                    </label>
                    <button type="button" class="rounded-full px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-500/10 dark:text-red-400 transition" @click="clearDoc(docKey)">Remove</button>
                  </div>
                </div>
              </div>

              <!-- Empty: click or drop -->
              <label
                v-else
                class="flex flex-col items-center justify-center rounded-3xl border-2 border-dashed px-6 py-12 text-center cursor-pointer transition"
                :class="dragKey === docKey ? 'border-brand-gold bg-brand-gold/[0.08]' : 'border-brand-navy/15 hover:border-brand-gold hover:bg-brand-gold/[0.04] dark:border-white/15'"
                @dragover.prevent="dragKey = docKey"
                @dragleave="dragKey = null"
                @drop.prevent="dropDoc(docKey, $event)"
              >
                <span class="h-14 w-14 rounded-2xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
                  <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                </span>
                <span class="mt-4 text-sm font-semibold text-brand-navy dark:text-white">Drag a file here, or <span class="text-brand-gold-deep dark:text-brand-gold underline underline-offset-4">browse</span></span>
                <span class="mt-1 text-xs text-brand-navy/50 dark:text-white/45">Photos from your phone work fine.</span>
                <input type="file" class="sr-only" accept="image/*,application/pdf" @change="setDoc(docKey, $event)" />
              </label>
            </div>

            <!-- ── Live scan ── -->
            <div v-else-if="stepKey === 'Live Scan'" key="scan">
              <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Live face scan</h2>
              <p class="mt-1 mb-5 text-sm text-brand-text-secondary dark:text-white/55">
                This confirms you're a real person<span v-if="type === 'broker'"> and lets the admin compare you with your license photo</span>. The camera only turns on when you start it.
              </p>
              <LiveFaceCapture @captured="setFace" />
            </div>

            <!-- ── Review ── -->
            <div v-else-if="stepKey === 'Review'" key="review">
              <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Review and submit</h2>
              <p class="mt-1 mb-5 text-sm text-brand-text-secondary dark:text-white/55">Check everything once more. You can edit any part.</p>

              <div class="overflow-hidden rounded-2xl ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
                <div class="flex items-center justify-between gap-3 px-4 py-3 bg-brand-cream/80 dark:bg-white/[0.04]">
                  <p class="text-xs font-bold uppercase tracking-wide text-brand-navy/55 dark:text-white/50">Your information</p>
                  <button type="button" class="text-xs font-bold text-brand-gold-deep dark:text-brand-gold hover:underline underline-offset-4" @click="goStep('Information')">Edit</button>
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 text-sm">
                  <div class="px-4 py-3 border-t border-brand-navy/[0.06] dark:border-white/10"><dt class="text-xs text-brand-navy/50 dark:text-white/45">Applying as</dt><dd class="font-semibold text-brand-navy dark:text-white capitalize">{{ type }}</dd></div>
                  <div class="px-4 py-3 border-t border-brand-navy/[0.06] dark:border-white/10"><dt class="text-xs text-brand-navy/50 dark:text-white/45">Full name</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ form.fullName }}</dd></div>
                  <div class="px-4 py-3 border-t border-brand-navy/[0.06] dark:border-white/10"><dt class="text-xs text-brand-navy/50 dark:text-white/45">{{ type === 'broker' ? 'PRC license no.' : 'Accreditation no.' }}</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ form.prcNumber || '—' }}</dd></div>
                  <div class="px-4 py-3 border-t border-brand-navy/[0.06] dark:border-white/10"><dt class="text-xs text-brand-navy/50 dark:text-white/45">Mobile</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ form.mobile || '—' }}</dd></div>
                  <div v-if="type === 'salesperson'" class="px-4 py-3 border-t border-brand-navy/[0.06] dark:border-white/10 sm:col-span-2"><dt class="text-xs text-brand-navy/50 dark:text-white/45">Supervising broker</dt><dd class="font-semibold text-brand-navy dark:text-white">{{ form.supervisingBroker || '—' }}</dd></div>
                </dl>
              </div>

              <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-3">
                <div v-for="d in reviewDocs" :key="d.k" class="rounded-2xl p-3 ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
                  <div class="mb-2 flex items-center justify-between">
                    <p class="text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/50 dark:text-white/45">{{ d.label }}</p>
                    <button type="button" class="text-[0.6875rem] font-bold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="goStep(d.step)">Edit</button>
                  </div>
                  <img v-if="previews[d.k]" :src="previews[d.k]" alt="" class="h-24 w-full rounded-xl object-cover" />
                  <div v-else class="h-24 rounded-xl flex items-center justify-center bg-brand-navy/[0.04] dark:bg-white/[0.05] text-xs font-semibold text-brand-navy/55 dark:text-white/50">PDF uploaded</div>
                </div>
                <div class="rounded-2xl p-3 ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
                  <div class="mb-2 flex items-center justify-between">
                    <p class="text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/50 dark:text-white/45">Face scan</p>
                    <button type="button" class="text-[0.6875rem] font-bold text-brand-gold-deep dark:text-brand-gold hover:underline" @click="goStep('Live Scan')">Retake</button>
                  </div>
                  <img v-if="previews.faceImage" :src="previews.faceImage" alt="" class="h-24 w-full rounded-xl object-cover" />
                  <div v-else class="h-24 rounded-xl flex items-center justify-center bg-emerald-500/[0.07] text-xs font-semibold text-emerald-700 dark:text-emerald-400">Captured ✓</div>
                </div>
              </div>

              <p class="mt-5 flex items-start gap-2 text-xs text-brand-navy/55 dark:text-white/50">
                <svg class="mt-px h-4 w-4 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                Your documents are stored privately and only seen by the admin who reviews your application.
              </p>
            </div>
          </Transition>

          <!-- Nav -->
          <div v-if="step > 0" class="mt-7 pt-5 flex items-center justify-between gap-3 border-t border-brand-navy/[0.07] dark:border-white/10">
            <button type="button" class="inline-flex items-center gap-1 rounded-full px-4 py-2.5 text-sm font-semibold text-brand-navy/70 ring-1 ring-brand-navy/15 hover:ring-brand-gold hover:text-brand-navy dark:text-white/70 dark:ring-white/20 dark:hover:text-white transition" @click="back">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
              Back
            </button>
            <button
              v-if="step < lastStep"
              type="button"
              :disabled="!canAdvance"
              class="inline-flex items-center gap-1.5 rounded-full bg-brand-navy px-6 py-2.5 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 transition disabled:opacity-40 disabled:hover:translate-y-0 disabled:cursor-not-allowed"
              @click="next"
            >
              Continue
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
            </button>
            <button
              v-else
              type="button"
              :disabled="!canAdvance || loading"
              class="inline-flex items-center gap-1.5 rounded-full bg-brand-gold px-6 py-2.5 text-sm font-bold text-brand-navy shadow-[0_6px_18px_rgba(212,165,74,0.45)] hover:bg-brand-gold-light hover:-translate-y-0.5 transition disabled:opacity-40"
              @click="submit"
            >
              Submit application
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
            </button>
          </div>
        </section>
      </div>

      <!-- ░░ Side column ░░ -->
      <aside class="lg:sticky lg:top-24 space-y-4">
        <section class="vside">
          <p class="text-sm font-bold text-brand-navy dark:text-white">What you'll need</p>
          <p v-if="!type" class="mt-2 text-xs leading-relaxed text-brand-text-secondary dark:text-white/55">Pick <span class="font-semibold text-brand-navy dark:text-white">Broker</span> or <span class="font-semibold text-brand-navy dark:text-white">Salesperson</span> to see the exact list.</p>
          <ul v-else class="mt-3 space-y-3">
            <li v-for="item in checklist" :key="item.label" class="flex items-start gap-3">
              <span class="mt-0.5 h-5 w-5 flex-none rounded-full flex items-center justify-center transition-colors" :class="item.done ? 'bg-emerald-500 text-white' : 'ring-1 ring-brand-navy/20 dark:ring-white/25'">
                <svg v-if="item.done" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              </span>
              <div class="min-w-0">
                <p class="text-sm font-semibold" :class="item.done ? 'text-brand-navy dark:text-white' : 'text-brand-navy/60 dark:text-white/60'">{{ item.label }}</p>
                <p class="text-xs text-brand-navy/45 dark:text-white/40">{{ item.desc }}</p>
              </div>
            </li>
          </ul>
        </section>

        <section class="vside">
          <p class="text-sm font-bold text-brand-navy dark:text-white">How verification works</p>
          <ol class="mt-3 space-y-3">
            <li class="flex gap-3"><span class="h-6 w-6 flex-none rounded-full flex items-center justify-center text-xs font-bold bg-brand-navy text-white dark:bg-white dark:text-brand-navy">1</span><p class="pt-0.5 text-xs leading-relaxed text-brand-navy/70 dark:text-white/65">You send your details, documents and a live face scan.</p></li>
            <li class="flex gap-3"><span class="h-6 w-6 flex-none rounded-full flex items-center justify-center text-xs font-bold bg-brand-gold text-brand-navy">2</span><p class="pt-0.5 text-xs leading-relaxed text-brand-navy/70 dark:text-white/65"><span class="font-semibold text-brand-navy dark:text-white">RealtyLink AI</span> runs a first check for the admin.</p></li>
            <li class="flex gap-3"><span class="h-6 w-6 flex-none rounded-full flex items-center justify-center text-xs font-bold bg-brand-navy text-white dark:bg-white dark:text-brand-navy">3</span><p class="pt-0.5 text-xs leading-relaxed text-brand-navy/70 dark:text-white/65">An admin reviews it and approves, usually within 24 hours.</p></li>
          </ol>
        </section>

        <section class="relative overflow-hidden rounded-3xl p-5 bg-brand-navy text-white ring-1 ring-brand-gold/25">
          <div class="absolute inset-0 bg-grid-gold opacity-[0.06] pointer-events-none" />
          <p class="relative text-[0.6875rem] font-bold uppercase tracking-[0.16em] text-brand-gold">Why get verified</p>
          <ul class="relative mt-3 space-y-2.5">
            <li v-for="b in benefits" :key="b" class="flex items-start gap-2.5 text-sm text-white/85">
              <svg class="mt-0.5 h-4 w-4 flex-none text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              {{ b }}
            </li>
          </ul>
        </section>

        <section class="rounded-3xl p-5 bg-brand-gold/[0.07] ring-1 ring-brand-gold/25">
          <p class="text-sm font-bold text-brand-navy dark:text-white">Tips for a fast approval</p>
          <ul class="mt-2.5 space-y-1.5 text-xs text-brand-navy/75 dark:text-white/70 list-disc list-inside marker:text-brand-gold">
            <li v-for="t in TIPS" :key="t">{{ t }}</li>
          </ul>
        </section>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.vcard { @apply rounded-[1.75rem] bg-white dark:bg-[#0d2550] p-6 sm:p-8 ring-1 ring-brand-navy/[0.06] dark:ring-white/10 shadow-[0_18px_44px_-30px_rgba(11,42,92,0.45)]; }
.vside { @apply rounded-3xl p-5 bg-white dark:bg-[#0d2550] ring-1 ring-brand-navy/[0.06] dark:ring-white/10; }
.vstep-enter-active, .vstep-leave-active { transition: opacity 0.18s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1); }
.vstep-enter-from { opacity: 0; transform: translateX(12px); }
.vstep-leave-to   { opacity: 0; transform: translateX(-8px); }
@media (prefers-reduced-motion: reduce) { .vstep-enter-active, .vstep-leave-active { transition: none; } }
.verify-fade-enter-active, .verify-fade-leave-active { transition: opacity .3s ease; }
.verify-fade-enter-from, .verify-fade-leave-to { opacity: 0; }
</style>
