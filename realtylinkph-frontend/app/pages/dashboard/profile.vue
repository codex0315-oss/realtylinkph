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
  <div class="max-w-6xl grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- ════════ Left: profile summary (sticky) ════════ -->
    <aside class="lg:col-span-1">
      <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-6 lg:sticky lg:top-24 transition-colors">
        <div class="flex flex-col items-center text-center">
          <div class="relative">
            <div class="h-24 w-24 rounded-full overflow-hidden bg-brand-navy/5 dark:bg-white/5 flex items-center justify-center ring-4 ring-brand-gold/10">
              <img v-if="currentAvatar" :src="currentAvatar" alt="" class="w-full h-full object-cover" />
              <span v-else class="text-3xl font-bold text-brand-navy dark:text-white">{{ form.name?.charAt(0) || '?' }}</span>
            </div>
            <label class="absolute bottom-0 right-0 h-8 w-8 rounded-full bg-brand-gold flex items-center justify-center cursor-pointer shadow-md hover:bg-brand-gold-light transition-colors">
              <svg class="h-4 w-4 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
              <input type="file" accept="image/*" class="hidden" @change="onAvatarChange" />
            </label>
          </div>
          <p class="mt-4 font-bold text-brand-navy dark:text-white text-lg">{{ authStore.user?.name }}</p>
          <p class="text-xs text-gray-400 break-all">{{ authStore.user?.email }}</p>
          <span class="inline-block mt-2 text-[10px] font-bold uppercase tracking-wide bg-brand-gold/10 text-brand-gold px-3 py-1 rounded-full">{{ roleLabel }}</span>
        </div>

        <div class="mt-6 pt-5 border-t border-gray-100 dark:border-white/10 space-y-3.5">
          <div class="flex items-center gap-3 text-sm">
            <div class="h-8 w-8 rounded-lg bg-brand-gold/10 flex items-center justify-center flex-shrink-0">
              <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
            </div>
            <div class="min-w-0">
              <p class="text-[11px] text-gray-400 dark:text-white/40 leading-none">Member since</p>
              <p class="text-sm font-semibold text-brand-navy dark:text-white mt-0.5">{{ memberSince }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3 text-sm">
            <div class="h-8 w-8 rounded-lg bg-brand-gold/10 flex items-center justify-center flex-shrink-0">
              <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-[11px] text-gray-400 dark:text-white/40 leading-none">Email status</p>
              <p class="text-sm font-semibold mt-0.5" :class="authStore.user?.email_verified_at ? 'text-emerald-600' : 'text-amber-600'">
                {{ authStore.user?.email_verified_at ? 'Verified' : 'Unverified' }}
              </p>
            </div>
          </div>
          <div class="flex items-center gap-3 text-sm">
            <div class="h-8 w-8 rounded-lg bg-brand-gold/10 flex items-center justify-center flex-shrink-0">
              <svg class="h-4 w-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /></svg>
            </div>
            <div class="min-w-0">
              <p class="text-[11px] text-gray-400 dark:text-white/40 leading-none">Phone</p>
              <p class="text-sm font-semibold text-brand-navy dark:text-white mt-0.5">{{ authStore.user?.phone || 'Not set' }}</p>
            </div>
          </div>
        </div>
      </div>
    </aside>

    <!-- ════════ Right: editable sections ════════ -->
    <div class="lg:col-span-2 space-y-6">

    <!-- ── Account details ── -->
    <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-6 sm:p-8 transition-colors">
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
            <span v-if="authStore.user?.email_verified_at" class="flex-shrink-0 text-[10px] font-bold uppercase bg-emerald-100 text-emerald-700 px-2.5 py-1 rounded-full">Verified</span>
            <span v-else class="flex-shrink-0 text-[10px] font-bold uppercase bg-amber-100 text-amber-700 px-2.5 py-1 rounded-full">Unverified</span>
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

        <button type="submit" :disabled="savingProfile" class="inline-flex items-center justify-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-7 py-3 rounded-xl shadow-[0_4px_18px_rgba(212,175,55,0.35)] hover:-translate-y-0.5 transition-all disabled:opacity-60 disabled:cursor-not-allowed">
          <svg v-if="savingProfile" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
          {{ savingProfile ? 'Saving…' : 'Save changes' }}
        </button>
      </form>
    </div>

    <!-- ── Agent application tracking ── -->
    <div v-if="agentApp" class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-6 sm:p-8 transition-colors">
      <div class="flex items-center justify-between gap-3 mb-1">
        <h2 class="font-bold text-brand-navy dark:text-white">Agent Application</h2>
        <span class="text-[10px] font-bold uppercase tracking-wide px-2.5 py-1 rounded-full" :class="appStatusMeta[agentApp.status]?.cls">
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
    <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-6 sm:p-8 transition-colors">
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
    <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-6 sm:p-8 transition-colors">
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

    <!-- ── Security (change password) ── -->
    <div class="bg-white dark:bg-[#10264D] rounded-2xl border border-gray-200 dark:border-white/10 p-6 sm:p-8 transition-colors">
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

    </div><!-- /right column -->
  </div>
</template>
