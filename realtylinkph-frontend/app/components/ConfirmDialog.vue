<script setup lang="ts">
/**
 * Global confirmation dialog. Mounted once in app.vue and driven entirely by
 * `useConfirm()`. Replaces the browser's native `confirm()`, which cannot be
 * styled, cannot show consequences, and reads as an error to most people.
 *
 * Built on AppDialog (centred on desktop, a bottom sheet on phones). On top it
 * shows what the action is about (a listing preview), what will happen, an
 * optional "type DELETE" lock for what can't be undone, and — when the caller
 * hands over the work — a spinner and any error right here, so the result is
 * never lost behind a closed dialog.
 */
const confirm = useConfirm()
const state = confirm.state

const typed = ref('')
watch(() => state.value.open, (open) => { if (open) typed.value = '' })

const tone = computed(() => state.value.tone ?? 'primary')
const locked = computed(() => !!state.value.typeToConfirm
  && typed.value.trim().toUpperCase() !== state.value.typeToConfirm.toUpperCase())

/* Destructive actions focus Cancel, so a stray Enter never deletes anything. */
const focusConfirm = computed(() => tone.value !== 'danger' && !state.value.typeToConfirm)

function submit() {
  if (!locked.value) confirm.accept()
}
</script>

<template>
  <AppDialog
    :open="state.open"
    :title="state.title"
    :subtitle="state.message"
    :tone="tone"
    :icon="state.icon"
    :busy="state.busy"
    @close="confirm.cancel()"
  >
    <template v-if="state.preview || state.subject || state.consequences?.length || state.typeToConfirm || state.error">
      <!-- What this is about -->
      <div
        v-if="state.preview"
        class="flex items-center gap-3 rounded-2xl p-2.5 pr-4 bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.06] dark:ring-white/10"
      >
        <span class="h-14 w-14 flex-none overflow-hidden rounded-xl bg-brand-navy/[0.06] dark:bg-white/10 flex items-center justify-center">
          <img v-if="state.preview.image" :src="state.preview.image" alt="" class="h-full w-full object-cover" />
          <svg v-else class="h-6 w-6 text-brand-navy/30 dark:text-white/30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
        </span>
        <span class="min-w-0">
          <span class="block truncate text-sm font-semibold text-brand-navy dark:text-white">{{ state.preview.title }}</span>
          <span v-if="state.preview.meta" class="mt-0.5 block truncate text-xs text-brand-navy/55 dark:text-white/50">{{ state.preview.meta }}</span>
        </span>
      </div>

      <div
        v-else-if="state.subject"
        class="rounded-2xl px-4 py-3 text-sm font-semibold truncate bg-brand-cream dark:bg-white/[0.05] ring-1 ring-brand-navy/[0.06] dark:ring-white/10 text-brand-navy dark:text-white"
      >
        {{ state.subject }}
      </div>

      <!-- What will happen -->
      <ul v-if="state.consequences?.length" class="space-y-2" :class="state.preview || state.subject ? 'mt-4' : ''">
        <li
          v-for="line in state.consequences"
          :key="line"
          class="flex items-start gap-2.5 text-sm leading-relaxed text-brand-navy/70 dark:text-white/65"
        >
          <span class="mt-[0.45rem] h-1.5 w-1.5 flex-none rounded-full" :class="tone === 'danger' ? 'bg-red-500' : tone === 'warning' ? 'bg-amber-500' : 'bg-brand-gold'" aria-hidden="true" />
          <span>{{ line }}</span>
        </li>
      </ul>

      <!-- Lock for what can't be undone -->
      <label v-if="state.typeToConfirm" class="mt-5 block">
        <span class="mb-1.5 block text-xs font-semibold text-brand-navy/70 dark:text-white/65">
          Type <span class="font-mono font-bold tracking-wider text-red-600 dark:text-red-400">{{ state.typeToConfirm }}</span> to confirm
        </span>
        <input
          v-model="typed"
          type="text"
          autocomplete="off"
          autocapitalize="characters"
          spellcheck="false"
          data-autofocus
          :disabled="state.busy"
          :placeholder="state.typeToConfirm"
          :class="DIALOG_FIELD"
          class="font-mono tracking-wider uppercase"
          @keydown.enter.prevent="submit"
        />
      </label>

      <p
        v-if="state.error"
        class="mt-4 flex gap-2 rounded-2xl bg-red-500/[0.08] px-4 py-3 text-sm leading-relaxed text-red-700 ring-1 ring-red-500/20 dark:text-red-300"
        role="alert"
      >
        <svg class="mt-0.5 h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
        {{ state.error }}
      </p>
    </template>

    <template #footer>
      <button
        type="button"
        :class="DIALOG_BTN.cancel"
        :disabled="state.busy"
        :data-autofocus="!focusConfirm && !state.typeToConfirm ? '' : undefined"
        @click="confirm.cancel()"
      >
        {{ state.cancelLabel }}
      </button>
      <button
        type="button"
        :class="DIALOG_BTN[tone]"
        :disabled="state.busy || locked"
        :data-autofocus="focusConfirm ? '' : undefined"
        @click="submit"
      >
        <svg v-if="state.busy" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
        {{ state.busy ? (state.busyLabel ?? 'Working…') : state.error ? 'Try again' : state.confirmLabel }}
      </button>
    </template>
  </AppDialog>
</template>
