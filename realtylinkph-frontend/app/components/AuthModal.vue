<script setup lang="ts">
const { isOpen, mode, open, close, setMode } = useAuthModal()
const { login, register, forgotPassword, loading, error } = useAuth()
const { startGoogleSignIn } = useGoogleAuth()

/**
 * Which panel the modal is showing. Kept local rather than added to
 * `useAuthModal`'s mode so the login/register contract stays as-is —
 * "forgot" is a detour off the login form, not a third top-level mode.
 */
const view = ref<'form' | 'forgot'>('form')

const forgotEmail = ref('')
const forgotSent  = ref(false)
const forgotNote  = ref('')

function openForgot() {
  view.value        = 'forgot'
  forgotEmail.value = form.email        // carry over anything already typed
  forgotSent.value  = false
  forgotNote.value  = ''
  error.value       = null
}

function backToLogin() {
  view.value       = 'form'
  forgotSent.value = false
  forgotNote.value = ''
  error.value      = null
}

async function submitForgot() {
  if (!forgotEmail.value.trim()) {
    forgotNote.value = 'Enter the email address on your account.'
    return
  }
  const res = await forgotPassword(forgotEmail.value.trim())
  forgotSent.value = res.ok
  forgotNote.value = res.message
}

const googleLoading = ref(false)

/** Set when Google bounces us back with ?google=…. Survives resetForm(). */
const googleError = ref<string | null>(null)
/** "notice" is guidance (register first), "error" is a genuine failure. */
const googleErrorKind = ref<'error' | 'notice'>('error')

async function doGoogle() {
  googleLoading.value = true
  googleError.value = null
  error.value = null
  // Sign in only signs in. If the Google address has never registered here the
  // server refuses it, rather than quietly creating an account.
  const ok = await startGoogleSignIn(isLogin.value ? 'login' : 'register')
  if (!ok) {
    googleLoading.value = false
    error.value = 'Could not start Google sign-in. Make sure the server is running, then try again.'
  }
}

const route  = useRoute()
const router = useRouter()

/* ── Shared field state ── */
const form = reactive({
  name:                  '',
  email:                 '',
  password:              '',
  password_confirmation: '',
})
const showPassword = ref(false)
const showConfirm  = ref(false)
const remember     = ref(false)

const ve = reactive({ name: '', email: '', password: '', confirm: '' })

function resetForm() {
  form.name = ''
  form.email = ''
  form.password = ''
  form.password_confirmation = ''
  ve.name = ''; ve.email = ''; ve.password = ''; ve.confirm = ''
  showPassword.value = false
  showConfirm.value = false
  error.value = null
  view.value = 'form'
  forgotEmail.value = ''
  forgotSent.value = false
  forgotNote.value = ''
}

/* ── Validation ── */
function validateLogin() {
  ve.email    = form.email    ? '' : 'Email is required'
  ve.password = form.password ? '' : 'Password is required'
  return !ve.email && !ve.password
}

function validateRegister() {
  ve.name     = form.name     ? '' : 'Name is required'
  ve.email    = form.email    ? '' : 'Email is required'
  ve.password = (form.password.length >= 8 && /[a-zA-Z]/.test(form.password) && /\d/.test(form.password))
    ? ''
    : 'Password must be at least 8 characters and include a letter and a number'
  ve.confirm  = form.password === form.password_confirmation ? '' : 'Passwords do not match'
  return !ve.name && !ve.email && !ve.password && !ve.confirm
}

/* ── Submit ── */
async function submit() {
  if (mode.value === 'login') {
    if (!validateLogin()) return
    await login({ email: form.email, password: form.password })
  } else {
    if (!validateRegister()) return
    // Every account registers as a default buyer — no role choice.
    await register({
      name:                  form.name,
      email:                 form.email,
      password:              form.password,
      password_confirmation: form.password_confirmation,
      role_type:             'buyer',
    })
  }
  if (!error.value) close()
}

/* ── Switch between login / register ── */
function switchTo(m: 'login' | 'register') {
  resetForm()
  googleError.value = null
  setMode(m)
}

/* ── Reset whenever the modal opens, and lock body scroll ── */
function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && isOpen.value) close()
}

watch(isOpen, (val) => {
  if (val) resetForm()
  else googleError.value = null   // clear on close, not open — see maybeOpenFromQuery
  if (import.meta.client) {
    document.body.style.overflow = val ? 'hidden' : ''
  }
})

/* ── Auto-open from ?auth=login|register query, then clean the URL ── */
function maybeOpenFromQuery() {
  const q = route.query.auth
  const g = route.query.google
  const failed       = g === 'failed'
  // The Google account is genuine but has never registered here. The callback
  // sends us to the register form with this flag so the person can finish.
  const unregistered = g === 'unregistered'
  // The server couldn't reach Google at all. Retrying often just works, so say
  // that rather than implying the account or password is at fault.
  const unreachable  = g === 'unreachable'

  if (q !== 'login' && q !== 'register' && !failed && !unregistered && !unreachable) return

  open(q === 'register' ? 'register' : 'login')

  if (failed || unregistered || unreachable) {
    // Opening resets the form, so set this after the watcher has run.
    nextTick(() => {
      googleErrorKind.value = unregistered ? 'notice' : 'error'
      googleError.value = unregistered
        ? "That Google account isn't registered with RealtyLink PH yet. Create your account below — you can use the same Google account once it exists."
        : unreachable
          ? "We couldn't reach Google just then — that's usually a passing network hiccup. Please try again."
          : "We couldn't complete your Google sign-in. Please try again, or sign in with your email and password."
    })
  }

  const query = { ...route.query }
  delete query.auth
  delete query.google
  router.replace({ path: route.path, query })
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  maybeOpenFromQuery()
})
watch(() => [route.query.auth, route.query.google], maybeOpenFromQuery)

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  if (import.meta.client) document.body.style.overflow = ''
})

const isLogin = computed(() => mode.value === 'login')

/*
 * Field styling lives here rather than inline so light and dark share one
 * definition. The modal used to be hard-coded dark (inline rgba() styles plus
 * JS @focus/@blur border handlers), which meant light mode never applied to it.
 * Everything is now `dark:` variants, so the first paint is already correct.
 */
const fieldBase =
  'w-full rounded-xl px-4 py-3 text-sm outline-none border transition-colors ' +
  'bg-white/70 dark:bg-white/5 backdrop-blur-sm ' +
  'text-brand-navy dark:text-white placeholder-brand-navy/30 dark:placeholder-white/25 focus:ring-0'
const fieldBorder    = 'border-brand-navy/15 dark:border-white/10 focus:border-brand-gold dark:focus:border-brand-gold/50'
const fieldBorderErr = 'border-red-500/60 dark:border-red-500/50'
/** Ready-made class for fields with no inline validation state. */
const fieldClass = `${fieldBase} ${fieldBorder}`
</script>

<template>
  <Teleport to="body">
    <Transition name="auth-fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6"
        @click.self="close"
      >
        <!-- Blurred backdrop -->
        <div
          class="absolute inset-0 bg-brand-navy/40 dark:bg-[#060E1F]/65 backdrop-blur-[10px]"
          @click="close"
        />

        <!-- ── Modal Card ── -->
        <Transition name="auth-pop">
          <div
            v-if="isOpen"
            class="relative w-full rounded-3xl max-h-[94vh] overflow-hidden
                   transition-[max-width] duration-300
                   bg-brand-cream dark:bg-brand-navy-deep
                   border border-brand-navy/10 dark:border-white/10
                   shadow-[0_40px_100px_rgba(8,21,47,0.28)] dark:shadow-[0_40px_100px_rgba(0,0,0,0.6)]"
            :class="isLogin ? 'max-w-[420px]' : 'max-w-[620px]'"
          >
            <!-- Ruled grid, same texture as the landing page and Browse -->
            <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
            <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />
            <!-- Soft gold wash so the ground isn't flat -->
            <div class="absolute -top-20 -right-16 h-56 w-56 rounded-full bg-brand-gold/15 dark:bg-brand-gold/10 blur-[90px] pointer-events-none" />
            <div class="absolute -bottom-24 -left-16 h-56 w-56 rounded-full bg-brand-navy-light/10 dark:bg-brand-navy-light/15 blur-[90px] pointer-events-none" />

            <!-- Content sits above the texture layers. The scroll lives here, not on
                 the card, so the grid stays put instead of scrolling away with it. -->
            <div class="relative px-7 py-6 sm:px-9 max-h-[94vh] overflow-y-auto">
            <!-- Close button -->
            <button
              type="button"
              class="absolute top-5 right-5 h-8 w-8 rounded-full flex items-center justify-center
                     text-brand-navy/40 hover:text-brand-navy hover:bg-brand-navy/5
                     dark:text-white/40 dark:hover:text-white dark:hover:bg-white/10 transition-all z-10"
              aria-label="Close"
              @click="close"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>

            <!-- Header: logo + heading (compact, horizontal) -->
            <div class="flex items-center gap-3 mb-5">
              <AppLogo size="sm" />
              <div class="border-l border-brand-navy/10 dark:border-white/10 pl-3">
                <h2 class="font-playfair text-xl sm:text-2xl font-bold text-brand-navy dark:text-white leading-tight">
                  <template v-if="view === 'forgot'">Reset <span class="text-brand-gold-deep dark:text-brand-gold">password</span></template>
                  <template v-else-if="isLogin">Welcome <span class="text-brand-gold-deep dark:text-brand-gold">back!</span></template>
                  <template v-else>Create <span class="text-brand-gold-deep dark:text-brand-gold">account</span></template>
                </h2>
                <p class="text-brand-navy/50 dark:text-white/40 text-xs mt-0.5">
                  {{ view === 'forgot'
                    ? 'We\'ll email you a link to choose a new one.'
                    : (isLogin ? 'Sign in to access your account.' : 'Join RealtyLinkPH today.') }}
                </p>
              </div>
            </div>

            <!-- ═══════════ Forgot password ═══════════ -->
            <form v-if="view === 'forgot'" @submit.prevent="submitForgot">
              <!-- Sent: confirmation state -->
              <div v-if="forgotSent" class="text-center py-2">
                <div class="h-12 w-12 rounded-full bg-brand-gold/15 border border-brand-gold/30 flex items-center justify-center mx-auto mb-4">
                  <svg class="h-6 w-6 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                  </svg>
                </div>
                <p class="text-brand-navy dark:text-white font-semibold text-sm">Check your email</p>
                <p class="text-brand-navy/60 dark:text-white/50 text-xs mt-1.5 leading-relaxed">
                  If an account exists for
                  <span class="text-brand-gold-deep dark:text-brand-gold">{{ forgotEmail }}</span>,
                  a reset link is on its way. The link expires in 60 minutes.
                </p>
                <button
                  type="button"
                  class="mt-5 text-xs font-semibold text-brand-gold-deep dark:text-brand-gold hover:underline"
                  @click="backToLogin"
                >
                  ← Back to sign in
                </button>
              </div>

              <!-- Request form -->
              <template v-else>
                <div
                  v-if="error || forgotNote"
                  class="flex items-start gap-2 text-xs rounded-xl px-4 py-3 mb-4 border"
                  :class="error
                    ? 'text-red-600 dark:text-red-400 bg-red-500/10 border-red-500/25 dark:border-red-500/20'
                    : 'text-brand-navy/70 dark:text-white/70 bg-brand-navy/5 dark:bg-white/5 border-brand-navy/10 dark:border-white/10'"
                >
                  <svg class="h-3.5 w-3.5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span>{{ error || forgotNote }}</span>
                </div>

                <label class="block text-xs font-medium text-brand-navy/70 dark:text-white/70 mb-1.5">Email address</label>
                <input
                  v-model="forgotEmail"
                  type="email"
                  autocomplete="email"
                  placeholder="you@example.com"
                  :class="fieldClass"
                />

                <button
                  type="submit"
                  :disabled="loading"
                  class="w-full mt-5 bg-brand-gold text-brand-navy font-bold text-sm py-3 rounded-xl
                         shadow-[0_4px_16px_rgba(201,162,39,0.35)] hover:shadow-[0_6px_24px_rgba(201,162,39,0.5)]
                         hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200
                         disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
                >
                  {{ loading ? 'Sending…' : 'Send reset link' }}
                </button>

                <button
                  type="button"
                  class="w-full mt-3 text-xs font-semibold text-brand-navy/50 dark:text-white/50 hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors"
                  @click="backToLogin"
                >
                  ← Back to sign in
                </button>
              </template>
            </form>

            <!-- ═══════════ Login / Register ═══════════ -->
            <form v-else @submit.prevent="submit">

              <!-- Google sign-in failure, carried back on ?google=failed.
                   Kept separate from `error` because resetForm() clears that
                   when the modal opens, which would swallow this message. -->
              <div
                v-if="googleError"
                class="flex items-start gap-2 text-xs rounded-xl px-4 py-3 mb-4 border"
                :class="googleErrorKind === 'notice'
                  ? 'text-brand-navy/80 dark:text-white/75 bg-brand-gold/10 border-brand-gold/30'
                  : 'text-red-600 dark:text-red-400 bg-red-500/10 border-red-500/25 dark:border-red-500/20'"
              >
                <svg class="h-3.5 w-3.5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ googleError }}</span>
              </div>

              <!-- API error -->
              <div
                v-if="error"
                class="flex items-center gap-2 text-xs rounded-xl px-4 py-3 mb-4 border
                       text-red-600 dark:text-red-400 bg-red-500/10 border-red-500/25 dark:border-red-500/20"
              >
                <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                {{ error }}
              </div>

              <!-- Fields: stacked for login, 2-column grid for register -->
              <div :class="isLogin ? 'space-y-4' : 'grid grid-cols-1 sm:grid-cols-2 gap-x-5 gap-y-4'">

                <!-- Full name (register only) -->
                <div v-if="!isLogin" class="space-y-2">
                  <label class="block text-sm font-semibold text-brand-navy/70 dark:text-white/70">Full name</label>
                  <input
                    v-model="form.name"
                    type="text"
                    placeholder="Juan Dela Cruz"
                    :class="[fieldBase, ve.name ? fieldBorderErr : fieldBorder]"
                    @input="ve.name = ''"
                  />
                  <p v-if="ve.name" class="text-[11px] text-red-600 dark:text-red-400">{{ ve.name }}</p>
                </div>

                <!-- Email -->
                <div class="space-y-2">
                  <label class="block text-sm font-semibold text-brand-navy/70 dark:text-white/70">Email address</label>
                  <input
                    v-model="form.email"
                    type="email"
                    placeholder="you@example.com"
                    :class="[fieldBase, ve.email ? fieldBorderErr : fieldBorder]"
                    @input="ve.email = ''"
                  />
                  <p v-if="ve.email" class="text-[11px] text-red-600 dark:text-red-400">{{ ve.email }}</p>
                </div>

                <!-- Password -->
                <div class="space-y-2">
                  <label class="block text-sm font-semibold text-brand-navy/70 dark:text-white/70">Password</label>
                  <div class="relative">
                    <input
                      v-model="form.password"
                      :type="showPassword ? 'text' : 'password'"
                      :placeholder="isLogin ? 'Enter your password' : 'Min 8 chars, incl. a number'"
                      class="!pr-11"
                      :class="[fieldBase, ve.password ? fieldBorderErr : fieldBorder]"
                      @input="ve.password = ''"
                    />
                    <button
                      type="button"
                      class="absolute right-3 top-1/2 -translate-y-1/2 text-brand-navy/35 hover:text-brand-navy/70 dark:text-white/30 dark:hover:text-white/70 transition-colors"
                      @click="showPassword = !showPassword"
                    >
                      <svg v-if="!showPassword" style="height:18px;width:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                      <svg v-else style="height:18px;width:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                      </svg>
                    </button>
                  </div>
                  <p v-if="ve.password" class="text-[11px] text-red-600 dark:text-red-400">{{ ve.password }}</p>
                  <p v-else-if="!isLogin" class="text-[11px] text-brand-navy/45 dark:text-white/40">At least 8 characters, including a letter and a number.</p>
                </div>

                <!-- Confirm password (register only) -->
                <div v-if="!isLogin" class="space-y-2">
                  <label class="block text-sm font-semibold text-brand-navy/70 dark:text-white/70">Confirm password</label>
                  <div class="relative">
                    <input
                      v-model="form.password_confirmation"
                      :type="showConfirm ? 'text' : 'password'"
                      placeholder="Re-enter password"
                      class="!pr-11"
                      :class="[fieldBase, ve.confirm ? fieldBorderErr : fieldBorder]"
                      @input="ve.confirm = ''"
                    />
                    <button
                      type="button"
                      class="absolute right-3 top-1/2 -translate-y-1/2 text-brand-navy/35 hover:text-brand-navy/70 dark:text-white/30 dark:hover:text-white/70 transition-colors"
                      @click="showConfirm = !showConfirm"
                    >
                      <svg v-if="!showConfirm" style="height:18px;width:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                      <svg v-else style="height:18px;width:18px" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                      </svg>
                    </button>
                  </div>
                  <p v-if="ve.confirm" class="text-[11px] text-red-600 dark:text-red-400">{{ ve.confirm }}</p>
                </div>
              </div>

              <!-- Remember + Forgot (login only) -->
              <div v-if="isLogin" class="flex items-center justify-between mt-5">
                <label class="flex items-center gap-2.5 cursor-pointer select-none group" @click="remember = !remember">
                  <div
                    class="h-[18px] w-[18px] rounded-[5px] flex items-center justify-center transition-all flex-shrink-0 border-[1.5px]"
                    :class="remember
                      ? 'bg-brand-gold border-brand-gold'
                      : 'border-brand-navy/25 dark:border-white/20'"
                  >
                    <svg v-if="remember" class="h-2.5 w-2.5 text-brand-navy" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3.5" d="M5 13l4 4L19 7" />
                    </svg>
                  </div>
                  <span class="text-xs text-brand-navy/55 group-hover:text-brand-navy dark:text-white/45 dark:group-hover:text-white/65 transition-colors">Remember me</span>
                </label>
                <button
                  type="button"
                  class="text-xs text-brand-navy/55 dark:text-white/45 hover:text-brand-gold-deep dark:hover:text-brand-gold transition-colors"
                  @click="openForgot"
                >
                  Forgot password?
                </button>
              </div>

              <!-- Submit -->
              <button
                type="submit"
                :disabled="loading"
                class="w-full flex items-center justify-center gap-2 font-bold text-sm py-3.5 rounded-xl mt-5
                       transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                style="background: linear-gradient(135deg, #D4AF37 0%, #c9a227 100%); color: #08152F; box-shadow: 0 4px 24px rgba(212,175,55,0.4);"
              >
                <svg v-if="loading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                {{ loading ? (isLogin ? 'Signing in…' : 'Creating account…') : (isLogin ? 'Sign In' : 'Create account') }}
              </button>
            </form>

            <!-- Divider — hidden on the forgot-password detour -->
            <div v-if="view === 'form'" class="flex items-center gap-3 my-4">
              <div class="flex-1 h-px bg-brand-navy/10 dark:bg-white/10" />
              <span class="text-[11px] text-brand-navy/35 dark:text-white/25 font-medium">or</span>
              <div class="flex-1 h-px bg-brand-navy/10 dark:bg-white/10" />
            </div>

            <!-- Google -->
            <button
              v-if="view === 'form'"
              type="button"
              :disabled="googleLoading || loading"
              class="w-full flex items-center justify-center gap-3 text-sm font-medium rounded-xl py-3 border
                     transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed
                     bg-white/70 hover:bg-white border-brand-navy/10 text-brand-navy/75 hover:text-brand-navy
                     dark:bg-white/5 dark:hover:bg-white/10 dark:border-white/10 dark:text-white/60 dark:hover:text-white/90"
              @click="doGoogle"
            >
              <svg v-if="googleLoading" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              <svg v-else class="h-4 w-4 flex-shrink-0" viewBox="0 0 24 24">
                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
              </svg>
              {{ googleLoading ? 'Redirecting to Google…' : 'Continue with Google' }}
            </button>

            <!-- Switch login/register -->
            <p v-if="view === 'form'" class="text-center text-xs text-brand-navy/50 dark:text-white/35 mt-4">
              <template v-if="isLogin">
                Don't have an account?
                <button type="button" class="font-semibold ml-1 text-brand-gold-deep dark:text-brand-gold hover:underline" @click="switchTo('register')">
                  Create account
                </button>
              </template>
              <template v-else>
                Already have an account?
                <button type="button" class="font-semibold ml-1 text-brand-gold-deep dark:text-brand-gold hover:underline" @click="switchTo('login')">
                  Sign in
                </button>
              </template>
            </p>
            </div><!-- /content layer -->

          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.auth-fade-enter-active, .auth-fade-leave-active { transition: opacity 0.25s ease; }
.auth-fade-enter-from, .auth-fade-leave-to { opacity: 0; }

.auth-pop-enter-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.auth-pop-leave-active { transition: all 0.2s ease; }
.auth-pop-enter-from { opacity: 0; transform: scale(0.94) translateY(12px); }
.auth-pop-leave-to { opacity: 0; transform: scale(0.97); }
</style>
