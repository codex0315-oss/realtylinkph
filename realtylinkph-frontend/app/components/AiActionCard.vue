<script setup lang="ts">
import type { AiAction } from '~/composables/useRealtyAi'

/**
 * The card under a RealtyLink AI reply that did something in the app rather
 * than search: switch theme (already applied; offers Undo), connect or
 * disconnect Google Calendar, change password, or open a page.
 *
 * The password form posts straight to /me/password. Its values live only in
 * this component, never in the chat thread, and are never sent to the AI.
 *
 * Shared by the buyer and agent assistants: the card reports what changed
 * (`update`) and whichever thread shows it keeps the state.
 */
const props = defineProps<{ action: AiAction }>()
const emit = defineEmits<{ navigate: []; update: [patch: Partial<AiAction>] }>()

const authStore = useAuthStore()
const api       = useApi()
const toast     = useToast()
const confirm   = useConfirm()
const route     = useRoute()
const { fetchMe } = useAuth()
const { setTheme } = useTheme()
const updateAction = (patch: Partial<AiAction>) => emit('update', patch)
const { connect: connectGcal, disconnect: disconnectGcal } = useGoogleCalendar()
const { open: openPanel, openInquiries } = useDashPanel()

const busy = ref(false)

const THEME_NAME = { dark: 'Dark mode', light: 'Light mode', system: 'Device theme' } as const
const THEME_ICON = {
  dark:   'M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z',
  light:  'M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z',
  system: 'M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25',
} as const

/* ── Theme ── */
async function undoTheme() {
  if (props.action.type !== 'theme' || !props.action.previous) return
  await setTheme(props.action.previous)
  updateAction({ undone: true })
}

/* ── Google Calendar ── */
const calendarOn = computed(() => !!authStore.user?.has_gcal)

async function startConnect() {
  busy.value = true
  const ok = await connectGcal()   // leaves for Google on success
  if (!ok) {
    busy.value = false
    toast.error('Couldn’t start the Google sign-in. Please try again.')
  }
}

async function startDisconnect() {
  const ok = await confirm({
    title: 'Disconnect Google Calendar?',
    message: 'RealtyLink PH will stop adding your viewings to Google Calendar.',
    consequences: ['New and changed viewings won’t sync any more', 'Viewings already in your calendar stay there', 'You can connect again any time'],
    confirmLabel: 'Disconnect',
    tone: 'danger',
  })
  if (!ok) return
  busy.value = true
  if (await disconnectGcal()) {
    await fetchMe()
    updateAction({ done: true })
    toast.success('Google Calendar disconnected.')
  } else {
    toast.error('Couldn’t disconnect. Please try again.')
  }
  busy.value = false
}

/* ── Password ── */
const pw     = reactive({ current_password: '', password: '', password_confirmation: '' })
const showPw = ref(false)
const pwError = ref<string | null>(null)

const checks = computed(() => [
  { ok: pw.password.length >= 8,                         label: '8+ characters' },
  { ok: /[a-z]/i.test(pw.password) && /\d/.test(pw.password), label: 'Letters and numbers' },
  { ok: !!pw.password && pw.password === pw.password_confirmation, label: 'Both match' },
])
const pwReady = computed(() => !!pw.current_password && checks.value.every(c => c.ok))

async function changePassword() {
  if (!pwReady.value || busy.value) return
  busy.value = true
  pwError.value = null
  try {
    await api.post('/me/password', { ...pw })
    Object.assign(pw, { current_password: '', password: '', password_confirmation: '' })
    updateAction({ done: true })
    toast.success('Password changed.')
  } catch (e: unknown) {
    const data = (e as { data?: { message?: string; errors?: Record<string, string[]> } })?.data
    pwError.value = data?.errors ? Object.values(data.errors)[0]?.[0] ?? data?.message ?? 'Could not change password.' : data?.message ?? 'Could not change password.'
  } finally {
    busy.value = false
  }
}

/* ── Open a page ── */
function openPage() {
  if (props.action.type !== 'open_page') return
  const { page, href } = props.action
  // Messages, Inquiries and Notifications are dashboard panels, not pages.
  if (route.path.startsWith('/dashboard')) {
    if (page === 'messages' || page === 'notifications') { openPanel(page); return }
    if (page === 'inquiries') { openInquiries(); return }
  }
  emit('navigate')
  navigateTo(href)
}

const field = 'w-full rounded-xl border-0 bg-brand-navy/[0.05] dark:bg-white/[0.07] px-3 py-2 text-sm text-brand-navy dark:text-white placeholder-brand-navy/40 dark:placeholder-white/35 focus:ring-2 focus:ring-brand-gold'
</script>

<template>
  <div v-if="action.type !== 'capabilities'" class="mt-2 rounded-xl bg-white ring-1 ring-brand-navy/[0.07] dark:bg-white/[0.06] dark:ring-white/10 p-3">
    <!-- Theme -->
    <div v-if="action.type === 'theme'" class="flex items-center gap-3">
      <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-brand-gold/15 text-brand-gold-deep dark:text-brand-gold">
        <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="THEME_ICON[action.undone && action.previous ? action.previous : action.mode]" /></svg>
      </span>
      <p class="min-w-0 flex-1 text-[0.8125rem] font-semibold text-brand-navy dark:text-white">
        {{ action.undone && action.previous ? `Back to ${THEME_NAME[action.previous].toLowerCase()}` : `${THEME_NAME[action.mode]} is on` }}
      </p>
      <button
        v-if="!action.undone && action.previous && action.previous !== action.mode"
        type="button"
        class="flex-none rounded-full px-3 py-1.5 text-xs font-semibold text-brand-navy ring-1 ring-brand-navy/15 hover:ring-brand-gold hover:bg-brand-gold/10 dark:text-white dark:ring-white/20 dark:hover:ring-brand-gold transition"
        @click="undoTheme"
      >
        Undo
      </button>
    </div>

    <!-- Google Calendar: connect / disconnect -->
    <div v-else-if="action.type === 'gcal_connect' || action.type === 'gcal_disconnect'" class="flex items-center gap-3">
      <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-[#ffffff] ring-1 ring-brand-navy/[0.08]">
        <svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true"><rect width="22" height="22" x="13" y="13" fill="#fff" /><path fill="#1e88e5" d="M25.68 20.92l1.008 1.44 1.584-1.152v8.352H30V18.64h-1.44zM22.943 23.745c.625-.574 1.013-1.37 1.013-2.249 0-1.747-1.533-3.168-3.417-3.168-1.602 0-2.972 1.009-3.33 2.453l1.657.421c.165-.664.868-1.146 1.673-1.146.942 0 1.709.646 1.709 1.44s-.767 1.44-1.709 1.44h-.997v1.728h.997c1.081 0 1.993.751 1.993 1.64 0 .904-.866 1.64-1.931 1.64-.962 0-1.784-.61-1.914-1.418L17 26.802c.262 1.636 1.81 2.87 3.6 2.87 2.007 0 3.64-1.511 3.64-3.368 0-1.023-.504-1.941-1.297-2.559z" /><path fill="#fbc02d" d="M34 42H14l-1-4 1-4h20l1 4z" /><path fill="#4caf50" d="M38 35l4-1V14l-4-1-4 1v20z" /><path fill="#1e88e5" d="M34 14l1-4-1-4H9a3 3 0 00-3 3v25l4 1 4-1V14h20z" /><path fill="#e53935" d="M34 34v8l8-8z" /><path fill="#1565c0" d="M39 6h-5v8h8V9a3 3 0 00-3-3zM9 42h5v-8H6v5a3 3 0 003 3z" /></svg>
      </span>
      <div class="min-w-0 flex-1">
        <p class="text-[0.8125rem] font-semibold text-brand-navy dark:text-white">Google Calendar</p>
        <p class="text-[0.6875rem] font-semibold" :class="calendarOn ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/50 dark:text-white/45'">
          {{ calendarOn ? 'Connected' : 'Not connected' }}
        </p>
      </div>
      <button
        v-if="!calendarOn && action.type === 'gcal_connect'"
        type="button"
        class="flex-none rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-60 transition"
        :disabled="busy"
        @click="startConnect"
      >
        {{ busy ? 'Opening Google…' : 'Connect' }}
      </button>
      <button
        v-else-if="calendarOn && action.type === 'gcal_disconnect'"
        type="button"
        class="flex-none rounded-full px-4 py-2 text-xs font-semibold text-red-600 ring-1 ring-red-300 hover:bg-red-500/10 dark:text-red-400 dark:ring-red-400/40 disabled:opacity-60 transition"
        :disabled="busy"
        @click="startDisconnect"
      >
        {{ busy ? 'Disconnecting…' : 'Disconnect' }}
      </button>
    </div>

    <!-- Password -->
    <template v-else-if="action.type === 'password'">
      <div v-if="!action.can_change" class="flex items-center gap-3">
        <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-[#ffffff] ring-1 ring-brand-navy/[0.08]">
          <svg class="h-[1.125rem] w-[1.125rem]" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z" /><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" /><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" /><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z" /></svg>
        </span>
        <p class="min-w-0 flex-1 text-[0.8125rem] font-semibold text-brand-navy dark:text-white">You sign in with Google</p>
      </div>

      <div v-else-if="action.done" class="flex items-center gap-3">
        <span class="h-9 w-9 flex-none rounded-xl flex items-center justify-center bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
          <svg class="h-[1.125rem] w-[1.125rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
        </span>
        <p class="min-w-0 flex-1 text-[0.8125rem] font-semibold text-brand-navy dark:text-white">Password changed</p>
      </div>

      <form v-else class="space-y-2" autocomplete="on" @submit.prevent="changePassword">
        <div class="flex items-center gap-2 pb-1">
          <svg class="h-4 w-4 flex-none text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
          <p class="text-[0.6875rem] font-semibold text-brand-navy/60 dark:text-white/55">Secure form: goes straight to RealtyLink PH, never to the AI</p>
        </div>
        <input v-model="pw.current_password" :type="showPw ? 'text' : 'password'" :class="field" placeholder="Current password" autocomplete="current-password" aria-label="Current password" required />
        <input v-model="pw.password" :type="showPw ? 'text' : 'password'" :class="field" placeholder="New password" autocomplete="new-password" aria-label="New password" required />
        <input v-model="pw.password_confirmation" :type="showPw ? 'text' : 'password'" :class="field" placeholder="Confirm new password" autocomplete="new-password" aria-label="Confirm new password" required />

        <ul class="flex flex-wrap gap-x-3 gap-y-1 pt-0.5">
          <li v-for="c in checks" :key="c.label" class="flex items-center gap-1 text-[0.6875rem] font-medium" :class="c.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-brand-navy/45 dark:text-white/40'">
            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="c.ok ? 'M4.5 12.75l6 6 9-13.5' : 'M12 12h.01'" /></svg>
            {{ c.label }}
          </li>
        </ul>

        <p v-if="pwError" class="text-xs font-medium text-red-600 dark:text-red-400" role="alert">{{ pwError }}</p>

        <div class="flex items-center justify-between gap-2 pt-1">
          <label class="flex items-center gap-1.5 text-[0.6875rem] font-medium text-brand-navy/60 dark:text-white/55 cursor-pointer select-none">
            <input v-model="showPw" type="checkbox" class="h-3.5 w-3.5 rounded border-brand-navy/30 text-brand-gold focus:ring-brand-gold" />
            Show passwords
          </label>
          <button
            type="submit"
            class="rounded-full bg-brand-gold px-4 py-2 text-xs font-bold text-brand-navy shadow-[0_4px_14px_rgba(212,165,74,0.4)] hover:bg-brand-gold-light disabled:opacity-50 disabled:shadow-none transition"
            :disabled="!pwReady || busy"
          >
            {{ busy ? 'Saving…' : 'Change password' }}
          </button>
        </div>
      </form>
    </template>

    <!-- Open a page -->
    <button
      v-else-if="action.type === 'open_page'"
      type="button"
      class="group w-full flex items-center justify-between gap-3 text-left"
      @click="openPage"
    >
      <span class="text-[0.8125rem] font-semibold text-brand-navy dark:text-white group-hover:text-brand-gold-deep dark:group-hover:text-brand-gold transition">Open {{ action.label }}</span>
      <span class="h-8 w-8 flex-none rounded-full flex items-center justify-center bg-brand-gold text-brand-navy group-hover:bg-brand-gold-light transition">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
      </span>
    </button>
  </div>
</template>
