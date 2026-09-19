<script setup lang="ts">
/**
 * Landing page for the emailed reset link:
 *   /reset-password?token=…&email=…
 *
 * Standalone (no navbar/footer) so the page has one job. Styled to match the
 * auth modal, since this is the same flow continued in a new tab.
 */
definePageMeta({ layout: false })

useHead({ title: 'Reset your password · RealtyLink PH' })

const route  = useRoute()
const router = useRouter()
const { resetPassword, loading } = useAuth()
const { open: openAuthModal } = useAuthModal()

const token = computed(() => (route.query.token as string) ?? '')
const email = computed(() => (route.query.email as string) ?? '')

/** A link that lost its parameters can never work — say so up front. */
const linkValid = computed(() => !!token.value && !!email.value)

const password = ref('')
const confirm  = ref('')
const showPw   = ref(false)
const showCf   = ref(false)

const ve      = reactive({ password: '', confirm: '' })
const apiError = ref('')
const done     = ref(false)

/** Mirrors the backend rule: min 8, at least one letter and one number. */
const strong = computed(() =>
  password.value.length >= 8 && /[a-zA-Z]/.test(password.value) && /\d/.test(password.value),
)

function validate(): boolean {
  ve.password = strong.value
    ? ''
    : 'Password must be at least 8 characters and include a letter and a number'
  ve.confirm = password.value === confirm.value ? '' : 'Passwords do not match'
  return !ve.password && !ve.confirm
}

async function submit() {
  apiError.value = ''
  if (!validate()) return

  const res = await resetPassword({
    token: token.value,
    email: email.value,
    password: password.value,
    password_confirmation: confirm.value,
  })

  if (res.ok) {
    done.value = true
  } else {
    apiError.value = res.message
  }
}

async function goSignIn() {
  await router.push('/')
  openAuthModal('login')
}

const fieldStyle = 'background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12)'
</script>

<template>
  <div
    class="min-h-screen flex items-center justify-center px-4 py-10"
    style="background: linear-gradient(160deg, #10264D 0%, #08152F 55%, #060E1F 100%)"
  >
    <!-- ambient texture -->
    <div class="fixed inset-0 bg-grid-gold opacity-[0.04] pointer-events-none" />
    <div class="fixed -top-32 -left-24 h-[420px] w-[420px] rounded-full bg-brand-gold/10 blur-[120px] pointer-events-none" />

    <div
      class="relative w-full max-w-[430px] rounded-3xl px-7 py-8 sm:px-9"
      style="
        background: linear-gradient(150deg, rgba(16,38,77,.96) 0%, rgba(8,21,47,.97) 60%, rgba(6,14,31,.98) 100%);
        border: 1px solid rgba(255,255,255,0.08);
        box-shadow: 0 30px 90px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.06);
      "
    >
      <!-- Header -->
      <div class="flex items-center gap-3 mb-6">
        <NuxtLink to="/"><AppLogo :on-dark="true" size="sm" /></NuxtLink>
        <div class="border-l border-white/10 pl-3">
          <h1 class="font-display text-xl font-bold text-white leading-tight">
            Choose a <span class="text-brand-gold">new password</span>
          </h1>
          <p v-if="linkValid && !done" class="text-white/40 text-xs mt-0.5 truncate max-w-[240px]">
            for {{ email }}
          </p>
        </div>
      </div>

      <!-- ── Broken link ── -->
      <div v-if="!linkValid" class="text-center py-4">
        <div class="h-12 w-12 rounded-full bg-red-500/10 border border-red-500/25 flex items-center justify-center mx-auto mb-4">
          <svg class="h-6 w-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
          </svg>
        </div>
        <p class="text-white font-semibold text-sm">This reset link is incomplete</p>
        <p class="text-white/50 text-xs mt-1.5 leading-relaxed">
          It may have been cut short by your email app. Request a fresh link and open it directly.
        </p>
        <button type="button" class="mt-5 text-xs font-semibold text-brand-gold hover:underline" @click="goSignIn">
          Request a new link
        </button>
      </div>

      <!-- ── Success ── -->
      <div v-else-if="done" class="text-center py-4">
        <div class="h-12 w-12 rounded-full bg-brand-gold/15 border border-brand-gold/30 flex items-center justify-center mx-auto mb-4">
          <svg class="h-6 w-6 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <p class="text-white font-semibold text-sm">Password updated</p>
        <p class="text-white/50 text-xs mt-1.5 leading-relaxed">
          For your security we signed you out everywhere else. Sign in with your new password.
        </p>
        <button
          type="button"
          class="w-full mt-6 bg-brand-gold text-brand-navy font-bold text-sm py-3 rounded-xl
                 shadow-[0_4px_16px_rgba(201,162,39,0.35)] hover:shadow-[0_6px_24px_rgba(201,162,39,0.5)]
                 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200"
          @click="goSignIn"
        >
          Go to sign in
        </button>
      </div>

      <!-- ── Form ── -->
      <form v-else @submit.prevent="submit">
        <div
          v-if="apiError"
          class="flex items-start gap-2 text-xs text-red-400 rounded-xl px-4 py-3 mb-4"
          style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2)"
        >
          <svg class="h-3.5 w-3.5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>{{ apiError }}</span>
        </div>

        <!-- New password -->
        <label class="block text-xs font-medium text-white/70 mb-1.5">New password</label>
        <div class="relative">
          <input
            v-model="password"
            :type="showPw ? 'text' : 'password'"
            autocomplete="new-password"
            placeholder="Min 8 chars, incl. a number"
            class="w-full rounded-xl px-4 py-3 pr-11 text-sm text-white placeholder-white/30 outline-none"
            :style="fieldStyle"
          />
          <button
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-white/35 hover:text-white/70 transition-colors"
            :aria-label="showPw ? 'Hide password' : 'Show password'"
            @click="showPw = !showPw"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
              <path v-if="!showPw" stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path v-if="!showPw" stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243" />
            </svg>
          </button>
        </div>
        <p v-if="ve.password" class="text-[0.6875rem] text-red-400 mt-1.5">{{ ve.password }}</p>

        <!-- Confirm -->
        <label class="block text-xs font-medium text-white/70 mb-1.5 mt-4">Confirm new password</label>
        <div class="relative">
          <input
            v-model="confirm"
            :type="showCf ? 'text' : 'password'"
            autocomplete="new-password"
            placeholder="Re-enter your new password"
            class="w-full rounded-xl px-4 py-3 pr-11 text-sm text-white placeholder-white/30 outline-none"
            :style="fieldStyle"
            @keydown.enter="submit"
          />
          <button
            type="button"
            class="absolute right-3 top-1/2 -translate-y-1/2 text-white/35 hover:text-white/70 transition-colors"
            :aria-label="showCf ? 'Hide password' : 'Show password'"
            @click="showCf = !showCf"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
              <path v-if="!showCf" stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path v-if="!showCf" stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              <path v-else stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243" />
            </svg>
          </button>
        </div>
        <p v-if="ve.confirm" class="text-[0.6875rem] text-red-400 mt-1.5">{{ ve.confirm }}</p>

        <button
          type="submit"
          :disabled="loading"
          class="w-full mt-6 bg-brand-gold text-brand-navy font-bold text-sm py-3 rounded-xl
                 shadow-[0_4px_16px_rgba(201,162,39,0.35)] hover:shadow-[0_6px_24px_rgba(201,162,39,0.5)]
                 hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200
                 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none"
        >
          {{ loading ? 'Updating…' : 'Update password' }}
        </button>

        <NuxtLink to="/" class="block text-center text-xs text-white/35 hover:text-brand-gold transition-colors mt-4">
          ← Back to RealtyLink PH
        </NuxtLink>
      </form>
    </div>
  </div>
</template>
