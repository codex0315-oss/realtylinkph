<script setup lang="ts">
import type { ApiResponse, User } from '~/types'

definePageMeta({ layout: 'dashboard' })

const authStore = useAuthStore()
const api       = useApi()
const { theme, setTheme } = useTheme()

/* ── Profile info ── */
const form = reactive({
  name:  authStore.user?.name  ?? '',
  phone: authStore.user?.phone ?? '',
})
const avatarFile    = ref<File | null>(null)
const avatarPreview = ref('')
const savingProfile = ref(false)
const profileSaved  = ref(false)
const profileError  = ref<string | null>(null)

const currentAvatar = computed(() => avatarPreview.value || authStore.user?.avatar || null)

function onAvatarChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  avatarFile.value = file
  const reader = new FileReader()
  reader.onload = ev => { avatarPreview.value = ev.target?.result as string }
  reader.readAsDataURL(file)
}

async function saveProfile() {
  savingProfile.value = true
  profileSaved.value  = false
  profileError.value  = null
  try {
    const fd = new FormData()
    fd.append('_method', 'PUT')
    fd.append('name', form.name)
    fd.append('phone', form.phone ?? '')
    if (avatarFile.value) fd.append('avatar', avatarFile.value)
    const res = await api.postForm<ApiResponse<User>>('/me', fd)
    authStore.setUser(res.data)
    avatarFile.value = null
    avatarPreview.value = ''
    profileSaved.value = true
  } catch (e: unknown) {
    profileError.value = (e as { data?: { message?: string } })?.data?.message ?? 'Could not update profile.'
  } finally {
    savingProfile.value = false
  }
}

/* ── Theme ── */
const themeOptions = [
  { value: 'light'  as const, label: 'Light',  icon: 'M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z' },
  { value: 'dark'   as const, label: 'Dark',   icon: 'M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z' },
  { value: 'system' as const, label: 'System', icon: 'M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z' },
]

/* ── Property alerts ── */
const propertyAlerts = ref(authStore.user?.property_alerts ?? false)
const savingAlerts    = ref(false)

async function toggleAlerts() {
  const next = !propertyAlerts.value
  propertyAlerts.value = next // optimistic
  savingAlerts.value = true
  try {
    const res = await api.put<ApiResponse<User>>('/me', { property_alerts: next })
    authStore.setUser(res.data)
  } catch {
    propertyAlerts.value = !next // revert
  } finally {
    savingAlerts.value = false
  }
}

/* ── Change password ── */
const pw = reactive({ current_password: '', password: '', password_confirmation: '' })
const showPw = ref(false)
const savingPw = ref(false)
const pwSaved  = ref(false)
const pwError  = ref<string | null>(null)

async function changePassword() {
  savingPw.value = true
  pwSaved.value  = false
  pwError.value  = null
  try {
    await api.post('/me/password', { ...pw })
    pw.current_password = ''
    pw.password = ''
    pw.password_confirmation = ''
    pwSaved.value = true
  } catch (e: unknown) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
    pwError.value = data?.errors ? Object.values(data.errors)[0]?.[0] ?? data?.message ?? 'Could not change password.' : data?.message ?? 'Could not change password.'
  } finally {
    savingPw.value = false
  }
}

/* ── Email verification (6-digit code) ── */
const verifyStage     = ref<'idle' | 'sent'>('idle')
const verifyCode      = ref('')
const verifySending   = ref(false)
const verifyConfirming = ref(false)
const verifyMsg       = ref<string | null>(null)
const verifyError     = ref<string | null>(null)

async function sendCode() {
  verifySending.value = true
  verifyError.value   = null
  verifyMsg.value     = null
  try {
    const res = await api.post<ApiResponse<null>>('/email/resend')
    verifyStage.value = 'sent'
    verifyMsg.value   = res.message ?? 'Code sent. Check your email.'
  } catch (e: unknown) {
    verifyError.value = (e as { data?: { message?: string } })?.data?.message ?? 'Could not send the code.'
  } finally {
    verifySending.value = false
  }
}

async function confirmCode() {
  verifyConfirming.value = true
  verifyError.value      = null
  try {
    const res = await api.post<ApiResponse<User>>('/email/verify', { code: verifyCode.value })
    authStore.setUser(res.data)
    verifyCode.value = ''
    verifyStage.value = 'idle'
  } catch (e: unknown) {
    verifyError.value = (e as { data?: { message?: string } })?.data?.message ?? 'Invalid code.'
  } finally {
    verifyConfirming.value = false
  }
}

/* ── Agent application tracking ── */
const agentApp = computed(() => authStore.user?.agent_profile ?? null)
const appStatusMeta: Record<string, { label: string; cls: string }> = {
  pending:  { label: 'Under review', cls: 'bg-amber-100 text-amber-700' },
  approved: { label: 'Approved',     cls: 'bg-emerald-100 text-emerald-700' },
  rejected: { label: 'Not approved', cls: 'bg-red-100 text-red-600' },
}
const { inCooldown: appInCooldown, text: appCooldownText } = useReapplyCooldown(() => agentApp.value?.reapply_at)

/* ── Google Calendar ── */
const { connect: connectGcal, disconnect: disconnectGcalApi } = useGoogleCalendar()
const { fetchMe } = useAuth()
const route   = useRoute()
const router  = useRouter()
const gcalBusy = ref(false)
const gcalMsg  = ref<string | null>(null)
const gcalErr  = ref(false)

// Show the one-time connect result, then strip ?gcal from the URL so it can't
// linger (e.g. still showing "connected" after a later disconnect / refresh).
onMounted(() => {
  // Pull the freshest profile (incl. agent application status) so the tracking
  // card reflects reality even after client-side navigation.
  fetchMe()

  if (route.query.gcal === 'connected') { gcalMsg.value = 'Google Calendar connected — confirmed viewings will appear in a "RealtyLink PH Viewings" calendar in your Google account.'; gcalErr.value = false }
  else if (route.query.gcal === 'error') { gcalMsg.value = 'Could not connect Google Calendar. Please try again.'; gcalErr.value = true }
  if (route.query.gcal) {
    const q = { ...route.query }
    delete q.gcal
    router.replace({ query: q })
  }
})

async function doConnectGcal() {
  gcalBusy.value = true
  gcalMsg.value  = null
  const ok = await connectGcal() // redirects away to Google on success
  if (!ok) {
    gcalBusy.value = false
    gcalErr.value  = true
    gcalMsg.value  = 'Could not start the Google connection. Make sure the backend server is running, then try again.'
  }
}
async function doDisconnectGcal() {
  gcalBusy.value = true
  const ok = await disconnectGcalApi()
  if (ok) {
    await fetchMe()
    gcalErr.value = false
    gcalMsg.value = 'Google Calendar disconnected.'
  } else {
    gcalErr.value = true
    gcalMsg.value = 'Could not disconnect. Please try again.'
  }
  gcalBusy.value = false
}

/* ══════════ Buyer + agent view ══════════
 * Buyers and agents get a settings layout with a section menu, a completeness
 * meter, an activity snapshot, a password strength meter and an unsaved-changes
 * bar. Admins keep the page below as it was. */
const isBuyerView = computed(() => authStore.isBuyer || authStore.isAgent)

type Section = 'account' | 'security' | 'apps' | 'preferences' | 'application'
const SECTIONS = computed(() => [
  { key: 'account' as const,     label: 'Account',        hint: 'Name, phone, email',     icon: 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z' },
  { key: 'security' as const,    label: 'Security',       hint: 'Password',               icon: 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z' },
  { key: 'apps' as const,        label: 'Connected apps', hint: 'Google Calendar',        icon: 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244' },
  { key: 'preferences' as const, label: 'Preferences',    hint: 'Theme, alerts',          icon: 'M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75' },
  ...(agentApp.value ? [{ key: 'application' as const, label: 'Agent application', hint: appStatusMeta[agentApp.value.status]?.label ?? '', icon: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z' }] : []),
])
/* The open section lives in the URL (?section=), and each one you open is a
   history step, so Back goes Agent application → Security → Account before
   leaving the page. The section follows the URL, which is what makes Back work. */
const sectionFromUrl = (): Section => {
  const q = String(route.query.section ?? '')
  return ['security', 'apps', 'preferences', 'application'].includes(q) ? q as Section : 'account'
}
const section = ref<Section>(sectionFromUrl())
watch(() => route.query.section, () => { section.value = sectionFromUrl() })
function goSection(s: Section) {
  if (s === section.value) return
  section.value = s
  router.push({ query: { ...route.query, section: s === 'account' ? undefined : s } })
}

/* ── Activity snapshot ── */
const NuxtLinkCmp = resolveComponent('NuxtLink')
const { favorites, fetchFavorites } = useFavorite()
const { appointments, fetchMyAppointments } = useAppointment()
const { properties: myListings, fetchMyListings } = useProperty()
const { reviews: myReviews, averageRating, fetchAgentReviews, pagination: reviewsMeta } = useReview()
onMounted(() => {
  if (authStore.isAgent) {
    fetchMyListings()
    if (authStore.user) fetchAgentReviews(authStore.user.id)
    return
  }
  if (!isBuyerView.value) return
  fetchFavorites()
  if (!appointments.value.length) fetchMyAppointments()
})
const activity = computed(() => authStore.isAgent
  ? [
      { label: 'Active listings', value: myListings.value.filter(p => p.status === 'published').length, to: '/dashboard/listings' },
      { label: 'Listing views',   value: myListings.value.reduce((s, p) => s + (p.views ?? 0), 0), to: '/dashboard/listings' },
      { label: 'Rating',          value: myReviews.value.length ? `★ ${averageRating.value.toFixed(1)} · ${myReviews.value.length}` : reviewsMeta.value ? 'No reviews yet' : '…', to: '/dashboard/reviews' },
      { label: 'Member since',    value: memberSince.value, to: '' },
    ]
  : [
      { label: 'Saved homes',       value: favorites.value.length, to: '/dashboard/saved' },
      { label: 'Viewings booked',   value: appointments.value.filter(a => a.status !== 'cancelled').length, to: '/dashboard/appointments' },
      { label: 'Viewings done',     value: appointments.value.filter(a => a.status === 'completed').length, to: '/dashboard/history' },
      { label: 'Member since',      value: memberSince.value, to: '' },
    ])

/* ── Completeness ── */
const steps = computed(() => [
  { key: 'photo',  label: 'Add a profile photo', done: !!authStore.user?.avatar,            go: () => document.getElementById('avatar-input')?.click() },
  { key: 'cover',  label: 'Add a cover photo',   done: !!authStore.user?.cover_url,         go: () => window.scrollTo({ top: 0, behavior: 'smooth' }) },
  { key: 'email',  label: 'Verify your email',   done: !!authStore.user?.email_verified_at, go: () => goSection('account') },
  { key: 'phone',  label: 'Add a phone number',  done: !!authStore.user?.phone,             go: () => { goSection('account'); nextTick(() => document.getElementById('pf-phone')?.focus()) } },
  { key: 'gcal',   label: 'Connect Google Calendar', done: !!authStore.user?.has_gcal,      go: () => goSection('apps') },
])
const percent = computed(() => Math.round((steps.value.filter(s => s.done).length / steps.value.length) * 100))
const nextSteps = computed(() => steps.value.filter(s => !s.done))
const allSteps = ref(false)   // the meter shows two steps until "+N more"

/* ── Password strength ── */
const showCur = ref(false)
const showNew = ref(false)
const pwChecks = computed(() => [
  { label: 'At least 8 characters', ok: pw.password.length >= 8 },
  { label: 'A letter',              ok: /[A-Za-z]/.test(pw.password) },
  { label: 'A number',              ok: /\d/.test(pw.password) },
  { label: 'Passwords match',       ok: !!pw.password && pw.password === pw.password_confirmation },
])
const pwScore = computed(() => {
  const p = pw.password
  if (!p) return 0
  let s = 0
  if (p.length >= 8) s++
  if (/[A-Za-z]/.test(p) && /\d/.test(p)) s++
  if (p.length >= 12) s++
  if (/[^A-Za-z0-9]/.test(p) || /[a-z]/.test(p) && /[A-Z]/.test(p)) s++
  return s
})
const PW_LEVEL = [
  { label: '', bar: '' },
  { label: 'Weak', bar: 'bg-red-500' },
  { label: 'Fair', bar: 'bg-amber-500' },
  { label: 'Good', bar: 'bg-emerald-500' },
  { label: 'Strong', bar: 'bg-emerald-600' },
]
const pwReady = computed(() => !!pw.current_password && pwChecks.value.every(c => c.ok))

/* ── Unsaved changes ── */
const dirty = computed(() =>
  form.name.trim() !== (authStore.user?.name ?? '')
  || (form.phone ?? '') !== (authStore.user?.phone ?? '')
  || !!avatarFile.value,
)
function discard() {
  form.name = authStore.user?.name ?? ''
  form.phone = authStore.user?.phone ?? ''
  avatarFile.value = null
  avatarPreview.value = ''
  profileError.value = null
}
const confirmLeave = useConfirm()
onBeforeRouteLeave(async () => {
  if (!isBuyerView.value || !dirty.value) return true
  return await confirmLeave({
    title: 'Leave without saving?',
    message: 'Your changes to your name, phone or photo haven’t been saved.',
    confirmLabel: 'Leave page',
    cancelLabel: 'Keep editing',
    tone: 'warning',
    icon: 'leave',
  })
})
function onBeforeUnload(e: BeforeUnloadEvent) {
  if (isBuyerView.value && dirty.value) { e.preventDefault(); e.returnValue = '' }
}
onMounted(() => window.addEventListener('beforeunload', onBeforeUnload))
onUnmounted(() => window.removeEventListener('beforeunload', onBeforeUnload))
watch(profileSaved, (v) => { if (v) setTimeout(() => { profileSaved.value = false }, 3000) })

const fieldCls =
  'w-full rounded-2xl border-0 px-4 py-3 text-[0.9375rem] text-brand-navy dark:text-white bg-brand-navy/[0.04] dark:bg-white/[0.06] ' +
  'ring-1 ring-inset ring-brand-navy/10 dark:ring-white/10 placeholder-brand-navy/40 dark:placeholder-white/35 ' +
  'focus:bg-white dark:focus:bg-white/[0.09] focus:ring-2 focus:ring-brand-gold dark:focus:ring-brand-gold transition'
const labelCls = 'mb-1.5 block text-sm font-semibold text-brand-navy dark:text-white/90'

const roleLabel = computed(() => {
  if (authStore.isAdmin) return 'Administrator'
  if (authStore.isAgent) return authStore.isVerifiedAgent ? 'Verified Agent' : 'Agent'
  return 'Buyer'
})

const memberSince = computed(() =>
  authStore.user?.created_at
    ? new Date(authStore.user.created_at).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
    : '—',
)
</script>
<template>
  <div class="max-w-6xl mx-auto">

    <!-- Buyers: same page heading as Appointments, History… -->
    <header v-if="isBuyerView" class="mb-5">
      <p class="text-[0.6875rem] font-bold uppercase tracking-[0.18em] text-brand-navy/45 dark:text-white/40">Your account</p>
      <h1 class="mt-1.5 font-display text-2xl sm:text-[1.875rem] font-bold tracking-[-0.01em] leading-tight text-brand-navy dark:text-white">My Profile</h1>
      <p class="mt-1.5 text-sm text-brand-text-secondary dark:text-white/55">
        {{ authStore.isAgent ? 'Your details, sign-in, and how buyers see you.' : 'Your details, sign-in and preferences.' }}
      </p>
    </header>

    <!-- ════════ Header: cover, photo, identity ════════
         Agents and buyers can set a cover. An agent's is the same one buyers
         see on their public profile; a buyer's shows here. Admins get the
         default banner. -->
    <section class="pcard pin !p-0 overflow-hidden">
      <AgentCover
        :src="authStore.user?.cover_url"
        :position="authStore.user?.cover_position ?? 50"
        :editable="!authStore.isAdmin"
        :class="authStore.isAgent ? 'h-32 sm:h-44' : authStore.isAdmin ? 'h-28 sm:h-36' : isBuyerView ? 'h-28 sm:h-36' : 'h-44 sm:h-60'"
      />

      <div class="px-6 sm:px-8" :class="isBuyerView ? 'pb-5' : 'pb-6'">
        <div class="flex flex-col sm:flex-row sm:items-end gap-4 sm:gap-6">
          <!-- Photo, overlapping the cover -->
          <div class="relative w-max" :class="isBuyerView ? '-mt-11 sm:-mt-12' : '-mt-14 sm:-mt-16'">
            <div :class="isBuyerView ? 'h-24 w-24 sm:h-[6.5rem] sm:w-[6.5rem]' : 'h-28 w-28 sm:h-32 sm:w-32'" class="rounded-full overflow-hidden ring-[5px] ring-white dark:ring-[#0d2550] shadow-xl bg-gold-metal flex items-center justify-center">
              <img v-if="currentAvatar" :src="currentAvatar" alt="" class="w-full h-full object-cover" />
              <span v-else class="font-heading text-4xl font-semibold text-brand-navy">{{ form.name?.charAt(0) || '?' }}</span>
            </div>
            <label
              class="absolute bottom-1 right-1 h-9 w-9 rounded-full bg-brand-gold flex items-center justify-center cursor-pointer shadow-md ring-[3px] ring-white dark:ring-[#0d2550] hover:bg-brand-gold-light hover:scale-105 transition"
              title="Change profile photo"
            >
              <svg class="h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <span class="sr-only">Change profile photo</span>
              <input id="avatar-input" type="file" accept="image/*" class="hidden" @change="onAvatarChange" />
            </label>
          </div>

          <div class="min-w-0 flex-1 sm:pb-1">
            <component :is="isBuyerView ? 'h2' : 'h1'" class="flex flex-wrap items-center gap-2 font-heading font-semibold tracking-[-0.02em] text-brand-navy dark:text-white leading-tight" :class="isBuyerView ? 'text-2xl' : 'text-3xl'">
              {{ authStore.user?.name }}
              <AppVerifiedTick v-if="authStore.isVerifiedAgent" size="lg" />
            </component>
            <p class="mt-0.5 text-sm text-brand-text-secondary dark:text-white/55 break-all">{{ authStore.user?.email }}</p>
            <span class="mt-2.5 inline-flex items-center rounded-full px-3 py-1 text-[0.6875rem] font-bold uppercase tracking-wide bg-brand-gold/15 text-brand-gold-deep ring-1 ring-brand-gold/30 dark:text-brand-gold">
              {{ roleLabel }}
            </span>
          </div>

          <NuxtLink
            v-if="authStore.isVerifiedAgent && authStore.user"
            :to="`/agents/${authStore.user.id}`"
            target="_blank"
            class="sm:mb-1 inline-flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-semibold text-brand-navy ring-1 ring-brand-navy/15 dark:text-white dark:ring-white/20 hover:ring-brand-gold transition w-max"
          >
            View public profile
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
          </NuxtLink>
        </div>

        <!-- A picked photo only applies on Save — say so, right where it was picked -->
        <p v-if="avatarFile && !isBuyerView" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-amber-50 dark:bg-amber-500/10 px-3.5 py-2 text-xs font-medium text-amber-800 dark:text-amber-300">
          <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
          New photo selected — press <span class="font-bold">Save changes</span> below to apply it.
        </p>
        <p v-if="!authStore.isAdmin && !isBuyerView" class="mt-4 text-xs text-brand-text-secondary dark:text-white/45">
          <template v-if="authStore.isAgent">Buyers see your cover and photo on your public profile, the agent directory and your listings.</template>
          <template v-else>Make your profile yours with a cover photo — your dream home, a favourite view, anything you like.</template>
          A wide landscape photo works best (at least 1600 px across).
        </p>

        <!-- Buyers: completeness + activity, one compact row -->
        <div v-if="isBuyerView" class="mt-5 grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)] gap-3">
          <div class="rounded-2xl px-4 py-3 bg-brand-cream dark:bg-white/[0.04] ring-1 ring-brand-navy/[0.05] dark:ring-white/10">
            <div class="flex items-center justify-between gap-3">
              <p class="text-sm font-semibold text-brand-navy dark:text-white">
                <template v-if="percent < 100">Profile <span class="text-brand-gold-deep dark:text-brand-gold">{{ percent }}%</span> complete</template>
                <template v-else>Profile complete <span class="text-emerald-600 dark:text-emerald-400">✓</span></template>
              </p>
              <p v-if="percent < 100" class="text-xs text-brand-navy/50 dark:text-white/45">{{ nextSteps.length }} {{ nextSteps.length === 1 ? 'step' : 'steps' }} left</p>
            </div>
            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-brand-navy/10 dark:bg-white/10" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" aria-label="Profile completeness">
              <div class="h-full rounded-full bg-gradient-to-r from-brand-gold-deep via-brand-gold to-brand-gold-light transition-[width] duration-700" :style="{ width: `${percent}%` }" />
            </div>
            <div v-if="nextSteps.length" class="mt-2.5 flex flex-wrap gap-1.5">
              <button
                v-for="st in (allSteps ? nextSteps : nextSteps.slice(0, 2))"
                :key="st.key"
                type="button"
                class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold bg-white text-brand-navy ring-1 ring-brand-navy/10 hover:ring-brand-gold dark:bg-white/[0.06] dark:text-white dark:ring-white/15 dark:hover:ring-brand-gold transition"
                @click="st.go()"
              >
                <svg class="h-3 w-3 text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                {{ st.label }}
              </button>
              <button
                v-if="!allSteps && nextSteps.length > 2"
                type="button"
                class="rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold text-brand-gold-deep hover:underline underline-offset-4 dark:text-brand-gold"
                @click="allSteps = true"
              >+{{ nextSteps.length - 2 }} more</button>
            </div>
          </div>

          <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <component
              :is="t.to ? NuxtLinkCmp : 'div'"
              v-for="t in activity"
              :key="t.label"
              :to="t.to || undefined"
              class="pstat !py-2.5 block transition"
              :class="t.to ? 'hover:ring-brand-gold group' : ''"
            >
              <!-- One line, so every tile's number sits at the same height -->
              <dt class="pstat-label !text-[0.625rem] truncate" :title="t.label">{{ t.label }}</dt>
              <dd class="mt-1 flex items-center justify-between gap-2 font-bold text-brand-navy dark:text-white" :class="typeof t.value === 'number' ? 'text-xl tabular-nums' : 'text-sm pt-1'">
                {{ t.value }}
                <svg v-if="t.to" class="h-4 w-4 text-brand-navy/30 group-hover:text-brand-gold-deep dark:text-white/30 dark:group-hover:text-brand-gold transition" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
              </dd>
            </component>
          </dl>
        </div>

        <dl v-else class="mt-5 grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="pstat">
            <dt class="pstat-label">Member since</dt>
            <dd class="mt-1 text-sm font-semibold text-brand-navy dark:text-white">{{ memberSince }}</dd>
          </div>
          <div class="pstat">
            <dt class="pstat-label">Email</dt>
            <dd class="mt-1 text-sm font-semibold" :class="authStore.user?.email_verified_at ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
              {{ authStore.user?.email_verified_at ? 'Verified' : 'Not verified yet' }}
            </dd>
          </div>
          <div class="pstat">
            <dt class="pstat-label">Phone</dt>
            <dd class="mt-1 text-sm font-semibold text-brand-navy dark:text-white">{{ authStore.user?.phone || 'Not set' }}</dd>
          </div>
        </dl>
      </div>
    </section>

    <!-- ════════ Settings (buyers): section menu + one section at a time ════════ -->
    <div v-if="isBuyerView" class="mt-6 grid grid-cols-1 lg:grid-cols-[15rem_minmax(0,1fr)] gap-6 items-start">
      <!-- Menu: a sticky list on desktop, a tab strip on phones -->
      <nav class="lg:sticky lg:top-24" aria-label="Settings">
        <!-- The gold ring is drawn outside each button: on desktop nothing
             clips it; on phones (a scrolling strip) the list keeps 2px of
             breathing room so the ring isn't cut at the edges. -->
        <div class="-mx-5 px-5 lg:mx-0 lg:px-0 overflow-x-auto lg:overflow-visible no-scrollbar">
          <ul class="flex lg:flex-col gap-1.5 w-max lg:w-auto p-0.5">
            <li v-for="s in SECTIONS" :key="s.key">
              <button
                type="button"
                class="group w-full flex items-center gap-3 rounded-2xl px-3.5 py-2.5 lg:py-3 text-left transition"
                :class="section === s.key
                  ? 'bg-white ring-1 ring-brand-gold shadow-[0_10px_24px_-18px_rgba(11,42,92,0.5)] dark:bg-[#0d2550]'
                  : 'hover:bg-white/70 dark:hover:bg-white/[0.04]'"
                :aria-current="section === s.key ? 'page' : undefined"
                @click="goSection(s.key)"
              >
                <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center transition-colors"
                      :class="section === s.key ? 'bg-brand-gold text-brand-navy' : 'bg-brand-navy/[0.05] text-brand-navy/60 dark:bg-white/[0.06] dark:text-white/60'">
                  <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="s.icon" /></svg>
                </span>
                <span class="min-w-0 whitespace-nowrap lg:whitespace-normal">
                  <span class="block text-sm font-semibold" :class="section === s.key ? 'text-brand-navy dark:text-white' : 'text-brand-navy/75 dark:text-white/70'">{{ s.label }}</span>
                  <span class="hidden lg:block text-xs text-brand-navy/45 dark:text-white/40">{{ s.hint }}</span>
                </span>
              </button>
            </li>
          </ul>
        </div>
      </nav>

      <Transition name="sec" mode="out-in">
        <!-- ── Account ── -->
        <section v-if="section === 'account'" key="account" class="pcard">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Account</h2>
          <p class="mt-1 mb-6 text-sm text-brand-text-secondary dark:text-white/55">
            <template v-if="authStore.isAgent">Your name, phone, photo and cover appear on your public profile, the agent directory and every listing. A wide cover photo works best (at least 1600 px across).</template>
            <template v-else>Your name and phone are shared with agents when you book or message.</template>
          </p>

          <div v-if="profileError" class="mb-5 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ profileError }}</div>

          <!-- Two columns on desktop so the section fits without scrolling -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-5">
            <div class="space-y-5">
            <div>
              <label for="pf-name" :class="labelCls">Full name</label>
              <input id="pf-name" v-model="form.name" type="text" autocomplete="name" :class="fieldCls" />
            </div>
            <div>
              <label for="pf-phone" :class="labelCls">Phone number</label>
              <input id="pf-phone" v-model="form.phone" type="tel" autocomplete="tel" placeholder="+63 9XX XXX XXXX" :class="fieldCls" />
            </div>
            </div>

          <div>
            <label for="pf-email" :class="labelCls">Email address</label>
            <div class="relative">
              <input id="pf-email" :value="authStore.user?.email" type="email" disabled :class="[fieldCls, 'pr-28 cursor-not-allowed opacity-70']" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 rounded-full px-2.5 py-1 text-[0.6875rem] font-bold"
                    :class="authStore.user?.email_verified_at ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-amber-500/10 text-amber-700 dark:text-amber-400'">
                {{ authStore.user?.email_verified_at ? '✓ Verified' : 'Unverified' }}
              </span>
            </div>
            <p class="mt-1.5 text-xs text-brand-navy/45 dark:text-white/40">Your sign-in email can’t be changed here.</p>

            <!-- Verify email (6-digit code) -->
            <div v-if="!authStore.user?.email_verified_at" class="mt-4 rounded-2xl bg-amber-500/[0.07] ring-1 ring-amber-500/25 p-4">
              <p class="text-sm font-semibold text-amber-800 dark:text-amber-300">Verify your email</p>
              <p class="mt-0.5 text-xs text-amber-800/75 dark:text-amber-200/70">
                <template v-if="verifyStage === 'idle'">Confirm it’s you so agents can reach you and you can recover your account.</template>
                <template v-else>We sent a 6-digit code to <span class="font-semibold">{{ authStore.user?.email }}</span>.</template>
              </p>
              <button v-if="verifyStage === 'idle'" type="button" :disabled="verifySending" class="mt-3 rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-brand-navy hover:bg-brand-gold-light disabled:opacity-60 transition" @click="sendCode">
                {{ verifySending ? 'Sending…' : 'Send verification code' }}
              </button>
              <div v-else class="mt-3 flex flex-wrap items-center gap-2">
                <input v-model="verifyCode" type="text" inputmode="numeric" maxlength="6" placeholder="••••••" aria-label="Verification code"
                       class="w-36 rounded-xl border-0 px-3 py-2.5 text-center text-base font-bold tracking-[0.4em] bg-white dark:bg-white/[0.08] dark:text-white ring-1 ring-amber-500/30 focus:ring-2 focus:ring-brand-gold" @keydown.enter.prevent="confirmCode" />
                <button type="button" :disabled="verifyConfirming || verifyCode.length !== 6" class="rounded-full bg-brand-navy px-5 py-2.5 text-xs font-bold text-white dark:bg-brand-gold dark:text-brand-navy disabled:opacity-50 transition" @click="confirmCode">
                  {{ verifyConfirming ? 'Checking…' : 'Verify' }}
                </button>
                <button type="button" :disabled="verifySending" class="text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline disabled:opacity-60" @click="sendCode">
                  {{ verifySending ? 'Resending…' : 'Resend code' }}
                </button>
              </div>
              <p v-if="verifyMsg && verifyStage === 'sent'" class="mt-2 text-xs text-emerald-700 dark:text-emerald-400">{{ verifyMsg }}</p>
              <p v-if="verifyError" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ verifyError }}</p>
            </div>
          </div>
          </div>

          <Transition name="fade-up">
            <p v-if="profileSaved" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-emerald-500/10 px-3.5 py-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              Changes saved
            </p>
          </Transition>
        </section>

        <!-- ── Security ── -->
        <section v-else-if="section === 'security'" key="security" class="pcard">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Security</h2>
          <p class="mt-1 mb-6 text-sm text-brand-text-secondary dark:text-white/55">Change the password you sign in with.</p>

          <form class="grid grid-cols-1 lg:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)] gap-x-8 gap-y-5" @submit.prevent="changePassword">
            <div>
            <div v-if="pwSaved" class="mb-5 inline-flex items-center gap-2 rounded-xl bg-emerald-500/10 px-3.5 py-2 text-sm font-medium text-emerald-700 dark:text-emerald-400">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              Password changed
            </div>
            <div v-if="pwError" class="mb-5 rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600 dark:text-red-400">{{ pwError }}</div>

            <label for="pw-cur" :class="labelCls">Current password</label>
            <div class="relative">
              <input id="pw-cur" v-model="pw.current_password" :type="showCur ? 'text' : 'password'" autocomplete="current-password" :class="[fieldCls, 'pr-12']" />
              <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 h-8 w-8 rounded-full flex items-center justify-center text-brand-navy/45 hover:text-brand-navy dark:text-white/45 dark:hover:text-white" :aria-label="showCur ? 'Hide password' : 'Show password'" @click="showCur = !showCur">
                <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path v-if="!showCur" stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path v-else stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
              </button>
            </div>

            <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="pw-new" :class="labelCls">New password</label>
                <div class="relative">
                  <input id="pw-new" v-model="pw.password" :type="showNew ? 'text' : 'password'" autocomplete="new-password" :class="[fieldCls, 'pr-12']" />
                  <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 h-8 w-8 rounded-full flex items-center justify-center text-brand-navy/45 hover:text-brand-navy dark:text-white/45 dark:hover:text-white" :aria-label="showNew ? 'Hide passwords' : 'Show passwords'" @click="showNew = !showNew">
                    <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path v-if="!showNew" stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path v-else stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                  </button>
                </div>
              </div>
              <div>
                <label for="pw-conf" :class="labelCls">Confirm new password</label>
                <input id="pw-conf" v-model="pw.password_confirmation" :type="showNew ? 'text' : 'password'" autocomplete="new-password" :class="fieldCls" />
              </div>
            </div>

            </div>

            <!-- Strength -->
            <div class="flex flex-col rounded-2xl p-5 bg-brand-cream/80 ring-1 ring-brand-navy/[0.05] dark:bg-white/[0.04] dark:ring-white/[0.08]">
              <p class="mb-3 text-sm font-semibold text-brand-navy dark:text-white">Password strength</p>
              <div class="flex gap-1.5" aria-hidden="true">
                <span v-for="i in 4" :key="i" class="h-1.5 flex-1 rounded-full transition-colors duration-300" :class="i <= pwScore ? PW_LEVEL[pwScore]!.bar : 'bg-brand-navy/10 dark:bg-white/10'" />
              </div>
              <p class="mt-1.5 h-4 text-xs font-semibold" :class="pwScore >= 3 ? 'text-emerald-700 dark:text-emerald-400' : pwScore === 2 ? 'text-amber-700 dark:text-amber-400' : 'text-red-600 dark:text-red-400'" aria-live="polite">
                {{ pw.password ? `Strength: ${PW_LEVEL[pwScore]!.label || 'Weak'}` : '' }}
              </p>
              <ul class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
                <li v-for="c in pwChecks" :key="c.label" class="flex items-center gap-2 text-xs" :class="c.ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-brand-navy/50 dark:text-white/45'">
                  <span class="h-4 w-4 flex-none rounded-full flex items-center justify-center transition-colors" :class="c.ok ? 'bg-emerald-500 text-white' : 'ring-1 ring-brand-navy/20 dark:ring-white/25'">
                    <svg v-if="c.ok" class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                  </span>
                  {{ c.label }}
                </li>
              </ul>

            <button type="submit" :disabled="savingPw || !pwReady" class="mt-5 self-start rounded-full bg-brand-navy px-6 py-3 text-sm font-bold text-white dark:bg-brand-gold dark:text-brand-navy hover:-translate-y-0.5 transition disabled:opacity-40 disabled:hover:translate-y-0 disabled:cursor-not-allowed">
              {{ savingPw ? 'Updating…' : 'Update password' }}
            </button>
            </div>
          </form>
        </section>

        <!-- ── Connected apps ── -->
        <section v-else-if="section === 'apps'" key="apps" class="pcard">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Connected apps</h2>
          <p class="mt-1 mb-6 text-sm text-brand-text-secondary dark:text-white/55">Services RealtyLinkPH works with on your behalf.</p>

          <div v-if="gcalMsg" class="mb-4 rounded-xl px-4 py-3 text-sm" :class="gcalErr ? 'bg-red-500/10 text-red-600 dark:text-red-400' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'">{{ gcalMsg }}</div>

          <!-- Phones: icon + name, then the description and button at full width.
               Wider: icon | name over description | button, in one row. -->
          <div class="grid grid-cols-[auto_minmax(0,1fr)] sm:grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 sm:gap-y-0.5 rounded-2xl p-4 ring-1 ring-brand-navy/[0.08] dark:ring-white/10">
            <span class="h-12 w-12 flex-none rounded-xl bg-[#ffffff] ring-1 ring-brand-navy/10 flex items-center justify-center sm:row-span-2">
              <svg class="h-8 w-8" viewBox="0 0 48 48" aria-hidden="true"><rect width="22" height="22" x="13" y="13" fill="#fff"/><polygon fill="#1e88e5" points="25.68,20.92 26.688,22.36 28.272,21.208 28.272,29.56 30,29.56 30,18.616 28.56,18.616"/><path fill="#1e88e5" d="M22.943,23.745c0.625-0.574,1.013-1.37,1.013-2.249c0-1.747-1.533-3.168-3.417-3.168c-1.602,0-2.972,1.009-3.33,2.453l1.657,0.421c0.165-0.664,0.868-1.146,1.673-1.146c0.942,0,1.709,0.646,1.709,1.44c0,0.794-0.767,1.44-1.709,1.44h-0.997v1.728h0.997c1.081,0,1.993,0.751,1.993,1.64c0,0.904-0.866,1.64-1.931,1.64c-0.962,0-1.784-0.61-1.914-1.418l-1.708,0.275c0.262,1.638,1.789,2.873,3.552,2.873c1.981,0,3.593-1.514,3.593-3.371C24.118,25.439,23.671,24.412,22.943,23.745z"/><polygon fill="#fbc02d" points="34,42 14,42 13,38 14,34 34,34 35,38"/><polygon fill="#4caf50" points="38,35 42,34 42,14 38,13 34,14 34,34"/><path fill="#1e88e5" d="M34,14l1-4l-1-4H9C7.343,6,6,7.343,6,9v25l4,1l4-1V14H34z"/><polygon fill="#e53935" points="34,34 34,42 42,34"/><polygon fill="#1565c0" points="39,6 34,6 34,14 42,14 42,9 41,6"/><polygon fill="#2e7d32" points="9,42 14,42 14,34 6,34 6,39 7,42"/></svg>
            </span>
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 font-semibold text-brand-navy dark:text-white sm:self-end">
              Google Calendar
              <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[0.6875rem] font-bold" :class="authStore.user?.has_gcal ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' : 'bg-brand-navy/[0.06] text-brand-navy/55 dark:bg-white/10 dark:text-white/55'">
                {{ authStore.user?.has_gcal ? 'Connected' : 'Not connected' }}
              </span>
            </p>
            <p class="col-span-2 sm:col-span-1 sm:col-start-2 sm:self-start text-xs leading-relaxed text-brand-text-secondary dark:text-white/50">Confirmed viewings go into a separate “RealtyLink PH Viewings” calendar. Your other calendars are never touched.</p>
            <button v-if="!authStore.user?.has_gcal" type="button" :disabled="gcalBusy" class="col-span-2 sm:col-span-1 sm:col-start-3 sm:row-start-1 sm:row-span-2 rounded-full bg-brand-gold px-5 py-2.5 text-sm font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-60 transition" @click="doConnectGcal">
              {{ gcalBusy ? 'Connecting…' : 'Connect' }}
            </button>
            <button v-else type="button" :disabled="gcalBusy" class="col-span-2 sm:col-span-1 sm:col-start-3 sm:row-start-1 sm:row-span-2 rounded-full px-5 py-2.5 text-sm font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 disabled:opacity-60 transition" @click="doDisconnectGcal">
              {{ gcalBusy ? 'Disconnecting…' : 'Disconnect' }}
            </button>
          </div>
        </section>

        <!-- ── Preferences ── -->
        <section v-else-if="section === 'preferences'" key="preferences" class="pcard">
          <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Preferences</h2>
          <p class="mt-1 mb-6 text-sm text-brand-text-secondary dark:text-white/55">How RealtyLinkPH looks, and what we email you about.</p>

          <!-- Theme left, email alerts right -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-7">
          <div>
          <p :class="labelCls">Theme</p>
          <div class="grid grid-cols-3 gap-3">
            <button
              v-for="opt in themeOptions"
              :key="opt.value"
              type="button"
              :aria-pressed="theme === opt.value"
              class="flex flex-col items-center gap-2 rounded-2xl py-4 ring-1 transition"
              :class="theme === opt.value ? 'ring-2 ring-brand-gold bg-brand-gold/10' : 'ring-brand-navy/10 hover:ring-brand-gold dark:ring-white/10'"
              @click="setTheme(opt.value)"
            >
              <svg class="h-5 w-5" :class="theme === opt.value ? 'text-brand-gold-deep dark:text-brand-gold' : 'text-brand-navy/45 dark:text-white/45'" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" :d="opt.icon" /></svg>
              <span class="text-xs font-semibold" :class="theme === opt.value ? 'text-brand-navy dark:text-white' : 'text-brand-navy/60 dark:text-white/55'">{{ opt.label }}</span>
            </button>
          </div>

          </div>

          <!-- Agents: new-listing emails are a buyer thing; point at the public profile instead -->
          <div v-if="authStore.isAgent">
            <p :class="labelCls">Public profile</p>
            <NuxtLink
              v-if="authStore.isVerifiedAgent && authStore.user"
              :to="`/agents/${authStore.user.id}`"
              target="_blank"
              class="w-full flex items-center justify-between gap-4 rounded-2xl px-4 py-[1.125rem] text-left ring-1 ring-brand-navy/10 hover:ring-brand-gold dark:ring-white/10 transition"
            >
              <span>
                <span class="block text-sm font-semibold text-brand-navy dark:text-white">See what buyers see</span>
                <span class="block text-xs text-brand-text-secondary dark:text-white/50">Your cover, photo, listings and reviews, as a buyer views them.</span>
              </span>
              <svg class="h-5 w-5 flex-none text-brand-gold-deep dark:text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
            </NuxtLink>
            <p v-else class="rounded-2xl px-4 py-[1.125rem] text-xs text-brand-text-secondary dark:text-white/50 ring-1 ring-brand-navy/10 dark:ring-white/10">Your public profile goes live once your agent application is approved.</p>
          </div>

          <div v-else>
            <p :class="labelCls">Email alerts</p>
            <button
              type="button"
              role="switch"
              :aria-checked="propertyAlerts"
              :disabled="savingAlerts"
              class="w-full flex items-center justify-between gap-4 rounded-2xl px-4 py-[1.125rem] text-left ring-1 transition disabled:opacity-60"
              :class="propertyAlerts ? 'ring-brand-gold bg-brand-gold/10' : 'ring-brand-navy/10 hover:ring-brand-gold dark:ring-white/10'"
              @click="toggleAlerts"
            >
              <span>
                <span class="block text-sm font-semibold text-brand-navy dark:text-white">New listing alerts</span>
                <span class="block text-xs text-brand-text-secondary dark:text-white/50">An email whenever a new property is listed on RealtyLinkPH.</span>
              </span>
              <span class="relative h-6 w-11 flex-none rounded-full transition-colors" :class="propertyAlerts ? 'bg-brand-gold' : 'bg-brand-navy/15 dark:bg-white/15'">
                <span class="absolute top-0.5 h-5 w-5 rounded-full bg-[#ffffff] shadow transition-all" :class="propertyAlerts ? 'left-[1.375rem]' : 'left-0.5'" />
              </span>
            </button>
          </div>
          </div>
        </section>

        <!-- ── Agent application ── -->
        <section v-else-if="section === 'application' && agentApp" key="application" class="pcard">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-heading text-xl font-semibold text-brand-navy dark:text-white">Agent application</h2>
            <span class="rounded-full px-2.5 py-1 text-[0.6875rem] font-bold uppercase tracking-wide" :class="appStatusMeta[agentApp.status]?.cls">{{ appStatusMeta[agentApp.status]?.label }}</span>
          </div>
          <p class="mt-1 mb-5 text-sm text-brand-text-secondary dark:text-white/55">{{ agentApp.applicant_type === 'broker' ? 'Real Estate Broker' : 'Real Estate Salesperson' }} application</p>

          <!-- Status on the left, the application link level with it on the right -->
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p v-if="agentApp.status === 'pending'" class="min-w-0 sm:flex-1 text-sm text-brand-navy/75 dark:text-white/70">An admin is reviewing your documents, usually within 24 hours. We’ll notify you of the outcome.</p>
            <p v-else-if="agentApp.status === 'approved'" class="min-w-0 sm:flex-1 flex items-center gap-2 rounded-xl bg-emerald-500/10 px-3.5 py-2.5 text-sm font-medium text-emerald-700 dark:text-emerald-400">
              <svg class="h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
              You’re a verified agent. Buyers see the verified tick on your profile and listings.
            </p>
            <span v-else />
            <NuxtLink to="/dashboard/verify" class="self-end sm:self-auto inline-flex flex-none whitespace-nowrap rounded-full px-5 py-2.5 text-sm font-bold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold dark:text-white dark:ring-white/20 transition">
              {{ agentApp.status === 'rejected' && !appInCooldown ? 'Re-apply' : 'View application' }}
            </NuxtLink>
          </div>
          <div v-if="agentApp.status === 'rejected'" class="mt-4">
            <p v-if="agentApp.admin_note" class="rounded-xl bg-red-500/[0.07] px-4 py-3 text-sm text-red-700 dark:text-red-300">Reason: {{ agentApp.admin_note }}</p>
            <p v-if="appInCooldown" class="mt-3 text-sm text-amber-700 dark:text-amber-300">You can re-apply in <span class="font-bold tabular-nums">{{ appCooldownText }}</span>.</p>
            <p class="mt-2 text-xs text-brand-navy/50 dark:text-white/45">This review clears from your account when the wait is over, and your uploaded documents are deleted.</p>
          </div>
          <div v-if="agentApp.ai_comment" class="mt-5 rounded-2xl bg-brand-gold/[0.07] ring-1 ring-brand-gold/25 p-4">
            <p class="text-xs font-bold text-brand-navy dark:text-white">RealtyLink AI · initial review</p>
            <p class="mt-1.5 text-sm leading-relaxed text-brand-navy/80 dark:text-white/70 whitespace-pre-wrap">{{ agentApp.ai_comment }}</p>
          </div>
        </section>
      </Transition>
    </div>

    <!-- Unsaved changes (buyers): name, phone or photo -->
    <Transition name="bar">
      <div v-if="isBuyerView && dirty" class="sticky bottom-5 z-30 mx-auto mt-6 w-full max-w-xl" role="region" aria-label="Unsaved changes">
        <div class="flex items-center gap-3 rounded-full p-2 pl-5 bg-brand-navy text-white ring-1 ring-brand-gold/40 shadow-[0_20px_50px_-15px_rgba(6,24,58,0.7)] dark:bg-[#0d2550]">
          <span class="h-2 w-2 flex-none rounded-full bg-brand-gold animate-pulse motion-reduce:animate-none" aria-hidden="true" />
          <p class="min-w-0 flex-1 text-sm font-semibold">
            Unsaved changes
            <span class="hidden sm:inline font-normal text-white/60">· {{ avatarFile ? 'new photo' : 'profile details' }}</span>
          </p>
          <button type="button" class="px-2 text-sm font-semibold text-white/70 hover:text-white" @click="discard">Discard</button>
          <button type="button" :disabled="savingProfile" class="rounded-full bg-brand-gold px-5 py-2.5 text-sm font-bold text-brand-navy hover:bg-brand-gold-light disabled:opacity-60 transition" @click="saveProfile">
            {{ savingProfile ? 'Saving…' : 'Save changes' }}
          </button>
        </div>
      </div>
    </Transition>

    <!-- ════════ Settings (admins): unchanged ════════ -->
    <div v-if="!isBuyerView" class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
      <div class="lg:col-span-2 space-y-6">
    <!-- ── Account details ── -->
    <div class="pcard">
      <h2 class="font-bold text-brand-navy dark:text-white mb-1">Account Details</h2>
      <p class="text-xs text-gray-500 dark:text-white/40 mb-5">Update your name and contact information.</p>

      <form class="space-y-5" @submit.prevent="saveProfile">
        <div v-if="profileSaved" class="flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
          Profile updated successfully.
        </div>
        <div v-if="profileError" class="flex items-center gap-2 text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ profileError }}
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Full name</label>
            <input v-model="form.name" type="text" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold transition-colors" />
          </div>
          <div>
            <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Phone number</label>
            <input v-model="form.phone" type="tel" placeholder="+63 9XX XXX XXXX" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold transition-colors" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Email address</label>
          <div class="flex items-center gap-2">
            <input :value="authStore.user?.email" type="email" disabled class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-100 dark:bg-white/5 text-gray-500 dark:text-white/40 cursor-not-allowed" />
            <span v-if="authStore.user?.email_verified_at" class="flex-shrink-0 text-[0.625rem] font-bold uppercase bg-emerald-100 text-emerald-700 px-2.5 py-1 rounded-full">Verified</span>
            <span v-else class="flex-shrink-0 text-[0.625rem] font-bold uppercase bg-amber-100 text-amber-700 px-2.5 py-1 rounded-full">Unverified</span>
          </div>

          <!-- Email verification (6-digit code) -->
          <div
            v-if="!authStore.user?.email_verified_at"
            class="mt-3 rounded-xl border border-amber-200 dark:border-amber-500/20 bg-amber-50 dark:bg-amber-500/5 p-4"
          >
            <div class="flex items-start gap-3">
              <svg class="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
              </svg>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-amber-800 dark:text-amber-400">Verify your email</p>
                <p class="text-xs text-amber-700/80 dark:text-amber-400/70 mt-0.5">
                  <template v-if="verifyStage === 'idle'">Confirm your email address to secure your account.</template>
                  <template v-else>We sent a 6-digit code to <span class="font-semibold">{{ authStore.user?.email }}</span>. Enter it below.</template>
                </p>

                <!-- idle: send button -->
                <button
                  v-if="verifyStage === 'idle'"
                  type="button"
                  :disabled="verifySending"
                  class="mt-3 inline-flex items-center gap-2 bg-brand-gold text-brand-navy font-bold text-xs px-4 py-2 rounded-lg hover:-translate-y-0.5 transition-all disabled:opacity-60"
                  @click="sendCode"
                >
                  <svg v-if="verifySending" class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                  {{ verifySending ? 'Sending…' : 'Send verification code' }}
                </button>

                <!-- sent: code input -->
                <div v-else class="mt-3">
                  <div class="flex items-center gap-2">
                    <input
                      v-model="verifyCode"
                      type="text"
                      inputmode="numeric"
                      maxlength="6"
                      placeholder="••••••"
                      class="w-32 text-center tracking-[0.4em] font-bold text-base px-3 py-2.5 rounded-lg border border-amber-300 dark:border-amber-500/30 bg-white dark:bg-white/5 dark:text-white outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold"
                      @keydown.enter.prevent="confirmCode"
                    />
                    <button
                      type="button"
                      :disabled="verifyConfirming || verifyCode.length !== 6"
                      class="inline-flex items-center gap-2 bg-brand-navy dark:bg-brand-gold text-white dark:text-brand-navy font-bold text-xs px-5 py-2.5 rounded-lg hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                      @click="confirmCode"
                    >
                      <svg v-if="verifyConfirming" class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                      Verify
                    </button>
                  </div>
                  <div class="flex items-center gap-3 mt-2">
                    <button type="button" :disabled="verifySending" class="text-xs text-brand-gold font-medium hover:underline disabled:opacity-60" @click="sendCode">
                      {{ verifySending ? 'Resending…' : 'Resend code' }}
                    </button>
                    <span v-if="verifyMsg" class="text-xs text-emerald-600 dark:text-emerald-400">{{ verifyMsg }}</span>
                  </div>
                </div>

                <p v-if="verifyError" class="text-xs text-red-600 dark:text-red-400 mt-2">{{ verifyError }}</p>
              </div>
            </div>
          </div>
        </div>

        <button type="submit" :disabled="savingProfile" class="inline-flex items-center justify-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-7 py-3 rounded-xl shadow-[0_4px_18px_rgba(212,165,74,0.35)] hover:-translate-y-0.5 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
          <svg v-if="savingProfile" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
          {{ savingProfile ? 'Saving…' : 'Save changes' }}
        </button>
      </form>
    </div>

    <!-- ── Security (change password) ── -->
    <div class="pcard">
      <h2 class="font-bold text-brand-navy dark:text-white mb-1">Change Password</h2>
      <p class="text-xs text-gray-500 dark:text-white/40 mb-5">Use at least 8 characters with letters and numbers.</p>

      <form class="space-y-5" @submit.prevent="changePassword">
        <div v-if="pwSaved" class="flex items-center gap-2 text-sm text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
          Password changed successfully.
        </div>
        <div v-if="pwError" class="flex items-center gap-2 text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
          <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ pwError }}
        </div>

        <div>
          <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Current password</label>
          <input v-model="pw.current_password" :type="showPw ? 'text' : 'password'" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold transition-colors" />
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
          <div>
            <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">New password</label>
            <input v-model="pw.password" :type="showPw ? 'text' : 'password'" placeholder="Min 8 characters" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold transition-colors" />
          </div>
          <div>
            <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-1.5">Confirm new password</label>
            <input v-model="pw.password_confirmation" :type="showPw ? 'text' : 'password'" placeholder="Re-enter password" class="w-full px-4 py-3 text-sm border border-gray-200 dark:border-white/10 rounded-xl bg-gray-50 dark:bg-white/5 dark:text-white focus:bg-white dark:focus:bg-white/10 focus:outline-none focus:ring-2 focus:ring-brand-gold/30 focus:border-brand-gold transition-colors" />
          </div>
        </div>
        <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-white/40 cursor-pointer select-none">
          <input v-model="showPw" type="checkbox" class="accent-brand-gold" />
          Show passwords
        </label>

        <button type="submit" :disabled="savingPw" class="inline-flex items-center justify-center gap-2 bg-brand-navy dark:bg-brand-gold text-white dark:text-brand-navy font-bold text-sm px-7 py-3 rounded-xl hover:-translate-y-0.5 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
          <svg v-if="savingPw" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
          {{ savingPw ? 'Updating…' : 'Update password' }}
        </button>
      </form>
    </div>
      </div>

      <div class="space-y-6">
    <!-- ── Agent application tracking ── -->
    <div v-if="agentApp" class="pcard">
      <div class="flex items-center justify-between gap-3 mb-1">
        <h2 class="font-bold text-brand-navy dark:text-white">Agent Application</h2>
        <span class="text-[0.625rem] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full" :class="appStatusMeta[agentApp.status]?.cls">
          {{ appStatusMeta[agentApp.status]?.label }}
        </span>
      </div>
      <p class="text-xs text-gray-500 dark:text-white/40 mb-4">
        {{ agentApp.applicant_type === 'broker' ? 'Real Estate Broker' : 'Real Estate Salesperson' }} application
      </p>

      <p v-if="agentApp.status === 'pending'" class="text-sm text-gray-600 dark:text-white/60">An admin is reviewing your documents — usually within 24 hours. We'll notify you of the outcome.</p>
      <div v-else-if="agentApp.status === 'rejected'">
        <p v-if="agentApp.admin_note" class="text-sm text-red-600 dark:text-red-400">Reason: {{ agentApp.admin_note }}</p>
        <p v-if="appInCooldown" class="text-xs text-amber-700 dark:text-amber-300 mt-2 inline-flex items-center gap-1.5">
          <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          You can re-apply in <span class="font-bold tabular-nums">{{ appCooldownText }}</span>
        </p>
        <!-- Says plainly that this block clears itself, so a rejection doesn't
             look like a permanent mark on the account. -->
        <p class="text-xs text-gray-500 dark:text-white/40 mt-2">
          This review clears from your account when the wait is over, and your uploaded documents are deleted.
        </p>
      </div>
      <p v-else-if="agentApp.status === 'approved'" class="text-sm text-emerald-600 dark:text-emerald-400">You're an approved agent! 🎉</p>

      <!-- AI initial review -->
      <div v-if="agentApp.ai_comment" class="mt-4 rounded-xl border border-brand-gold/25 bg-brand-gold/5 p-4">
        <div class="flex items-center gap-2 mb-1.5">
          <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" /></svg>
          <p class="text-xs font-bold text-brand-navy dark:text-white">RealtyLink AI — initial review</p>
        </div>
        <p class="text-sm text-brand-navy/80 dark:text-white/70 whitespace-pre-wrap leading-relaxed">{{ agentApp.ai_comment }}</p>
      </div>

      <NuxtLink to="/dashboard/verify" class="text-xs font-semibold text-brand-gold hover:underline mt-4 inline-block">
        {{ agentApp.status === 'rejected' && !appInCooldown ? 'Re-apply' : 'View application' }}
      </NuxtLink>
    </div>

    <!-- ── Google Calendar ── -->
    <div class="pcard">
      <h2 class="font-bold text-brand-navy dark:text-white mb-1">Google Calendar</h2>
      <p class="text-xs text-gray-500 dark:text-white/40 mb-5">Confirmed viewings are added to a dedicated "RealtyLink PH Viewings" calendar in your Google account — it never touches your other calendars.</p>

      <div v-if="gcalMsg" class="mb-4 text-sm rounded-xl px-4 py-3 border" :class="gcalErr ? 'text-red-600 bg-red-50 border-red-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200'">
        {{ gcalMsg }}
      </div>

      <div class="flex items-center justify-between gap-4 flex-wrap">
        <div class="flex items-center gap-3">
          <svg class="h-9 w-9 flex-shrink-0" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <rect width="22" height="22" x="13" y="13" fill="#fff"/>
            <polygon fill="#1e88e5" points="25.68,20.92 26.688,22.36 28.272,21.208 28.272,29.56 30,29.56 30,18.616 28.56,18.616"/>
            <path fill="#1e88e5" d="M22.943,23.745c0.625-0.574,1.013-1.37,1.013-2.249c0-1.747-1.533-3.168-3.417-3.168c-1.602,0-2.972,1.009-3.33,2.453l1.657,0.421c0.165-0.664,0.868-1.146,1.673-1.146c0.942,0,1.709,0.646,1.709,1.44c0,0.794-0.767,1.44-1.709,1.44h-0.997v1.728h0.997c1.081,0,1.993,0.751,1.993,1.64c0,0.904-0.866,1.64-1.931,1.64c-0.962,0-1.784-0.61-1.914-1.418l-1.708,0.275c0.262,1.638,1.789,2.873,3.552,2.873c1.981,0,3.593-1.514,3.593-3.371C24.118,25.439,23.671,24.412,22.943,23.745z"/>
            <polygon fill="#fbc02d" points="34,42 14,42 13,38 14,34 34,34 35,38"/>
            <polygon fill="#4caf50" points="38,35 42,34 42,14 38,13 34,14 34,34"/>
            <path fill="#1e88e5" d="M34,14l1-4l-1-4H9C7.343,6,6,7.343,6,9v25l4,1l4-1V14H34z"/>
            <polygon fill="#e53935" points="34,34 34,42 42,34"/>
            <polygon fill="#1565c0" points="39,6 34,6 34,14 42,14 42,9 41,6"/>
            <polygon fill="#2e7d32" points="9,42 14,42 14,34 6,34 6,39 7,42"/>
          </svg>
          <div>
            <p class="text-sm font-semibold text-brand-navy dark:text-white">Google Calendar</p>
            <p class="text-xs" :class="authStore.user?.has_gcal ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-400 dark:text-white/40'">
              {{ authStore.user?.has_gcal ? 'Connected' : 'Not connected' }}
            </p>
          </div>
        </div>

        <button
          v-if="!authStore.user?.has_gcal"
          type="button"
          :disabled="gcalBusy"
          class="inline-flex items-center gap-2.5 border border-gray-200 dark:border-white/15 text-brand-navy dark:text-white font-semibold text-sm px-5 py-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-white/5 transition-all disabled:opacity-60"
          @click="doConnectGcal"
        >
          <svg class="h-4 w-4 flex-shrink-0" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
            <rect width="22" height="22" x="13" y="13" fill="#fff"/>
            <polygon fill="#1e88e5" points="25.68,20.92 26.688,22.36 28.272,21.208 28.272,29.56 30,29.56 30,18.616 28.56,18.616"/>
            <path fill="#1e88e5" d="M22.943,23.745c0.625-0.574,1.013-1.37,1.013-2.249c0-1.747-1.533-3.168-3.417-3.168c-1.602,0-2.972,1.009-3.33,2.453l1.657,0.421c0.165-0.664,0.868-1.146,1.673-1.146c0.942,0,1.709,0.646,1.709,1.44c0,0.794-0.767,1.44-1.709,1.44h-0.997v1.728h0.997c1.081,0,1.993,0.751,1.993,1.64c0,0.904-0.866,1.64-1.931,1.64c-0.962,0-1.784-0.61-1.914-1.418l-1.708,0.275c0.262,1.638,1.789,2.873,3.552,2.873c1.981,0,3.593-1.514,3.593-3.371C24.118,25.439,23.671,24.412,22.943,23.745z"/>
            <polygon fill="#fbc02d" points="34,42 14,42 13,38 14,34 34,34 35,38"/>
            <polygon fill="#4caf50" points="38,35 42,34 42,14 38,13 34,14 34,34"/>
            <path fill="#1e88e5" d="M34,14l1-4l-1-4H9C7.343,6,6,7.343,6,9v25l4,1l4-1V14H34z"/>
            <polygon fill="#e53935" points="34,34 34,42 42,34"/>
            <polygon fill="#1565c0" points="39,6 34,6 34,14 42,14 42,9 41,6"/>
            <polygon fill="#2e7d32" points="9,42 14,42 14,34 6,34 6,39 7,42"/>
          </svg>
          {{ gcalBusy ? 'Connecting…' : 'Connect' }}
        </button>
        <button
          v-else
          type="button"
          :disabled="gcalBusy"
          class="inline-flex items-center gap-2 border border-red-200 dark:border-red-500/30 text-red-600 dark:text-red-400 font-semibold text-sm px-5 py-2.5 rounded-xl hover:bg-red-50 dark:hover:bg-red-500/10 transition-all disabled:opacity-60"
          @click="doDisconnectGcal"
        >
          {{ gcalBusy ? 'Disconnecting…' : 'Disconnect' }}
        </button>
      </div>
    </div>

    <!-- ── Preferences (theme + alerts) ── -->
    <div class="pcard">
      <h2 class="font-bold text-brand-navy dark:text-white mb-1">Preferences</h2>
      <p class="text-xs text-gray-500 dark:text-white/40 mb-5">Customize how RealtyLinkPH looks and what you hear about.</p>

      <!-- Theme -->
      <div class="mb-7">
        <label class="block text-sm font-semibold text-brand-navy dark:text-white/80 mb-3">Theme</label>
        <div class="grid grid-cols-3 gap-3 max-w-md">
          <button
            v-for="opt in themeOptions"
            :key="opt.value"
            type="button"
            class="flex flex-col items-center gap-2 py-4 rounded-xl border-2 transition-all"
            :class="theme === opt.value
              ? 'border-brand-gold bg-brand-gold/10'
              : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20'"
            @click="setTheme(opt.value)"
          >
            <svg class="h-5 w-5" :class="theme === opt.value ? 'text-brand-gold' : 'text-gray-400 dark:text-white/40'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" :d="opt.icon" />
            </svg>
            <span class="text-xs font-semibold" :class="theme === opt.value ? 'text-brand-navy dark:text-white' : 'text-gray-500 dark:text-white/50'">{{ opt.label }}</span>
          </button>
        </div>
      </div>

      <!-- Property alerts -->
      <div class="flex items-center justify-between gap-4 pt-5 border-t border-gray-100 dark:border-white/10">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-brand-navy dark:text-white">Property Alerts</p>
          <p class="text-xs text-gray-500 dark:text-white/40 mt-0.5">Get an email whenever a new property is listed on RealtyLinkPH.</p>
        </div>
        <button
          type="button"
          role="switch"
          :aria-checked="propertyAlerts"
          :disabled="savingAlerts"
          class="relative h-6 w-11 rounded-full transition-colors flex-shrink-0 disabled:opacity-60"
          :class="propertyAlerts ? 'bg-brand-gold' : 'bg-gray-300 dark:bg-white/20'"
          @click="toggleAlerts"
        >
          <span class="absolute top-0.5 left-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform" :class="propertyAlerts ? 'translate-x-5' : 'translate-x-0'" />
        </button>
      </div>
    </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Same surface as the public profile, so what the agent edits looks like what buyers see. */
.pcard {
  @apply rounded-[1.75rem] bg-white dark:bg-[#0d2550] p-6 sm:p-8
         ring-1 ring-brand-navy/[0.06] dark:ring-white/10
         shadow-[0_18px_44px_-30px_rgba(11,42,92,0.45)] transition-colors;
}
.pstat { @apply rounded-2xl px-4 py-3 bg-brand-cream dark:bg-white/[0.04] ring-1 ring-brand-navy/[0.05] dark:ring-white/10; }
.pstat-label { @apply text-[0.6875rem] font-bold uppercase tracking-wide text-brand-navy/50 dark:text-white/45; }

.pin { animation: pin 0.5s cubic-bezier(0.22, 1, 0.36, 1) both; }
@keyframes pin {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: none; }
}
.no-scrollbar { scrollbar-width: none; }
.no-scrollbar::-webkit-scrollbar { display: none; }

/* Switching sections: a short cross-fade with a small rise. */
.sec-enter-active, .sec-leave-active { transition: opacity 0.18s ease, transform 0.22s cubic-bezier(0.22, 1, 0.36, 1); }
.sec-enter-from { opacity: 0; transform: translateY(8px); }
.sec-leave-to   { opacity: 0; transform: translateY(-4px); }
.bar-enter-active, .bar-leave-active { transition: opacity 0.2s ease, transform 0.3s cubic-bezier(0.22, 1, 0.36, 1); }
.bar-enter-from, .bar-leave-to { opacity: 0; transform: translateY(16px); }
.fade-up-enter-active, .fade-up-leave-active { transition: opacity 0.25s ease, transform 0.25s ease; }
.fade-up-enter-from, .fade-up-leave-to { opacity: 0; transform: translateY(4px); }

@media (prefers-reduced-motion: reduce) {
  .pin { animation: none; }
  .sec-enter-active, .sec-leave-active, .bar-enter-active, .bar-leave-active, .fade-up-enter-active, .fade-up-leave-active { transition: none; }
}
</style>
