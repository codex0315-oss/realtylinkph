<script setup lang="ts">
/**
 * Brand lockup. Swaps between the navy artwork and the cream/gold variant so
 * the mark stays legible on both light and dark surfaces.
 *
 * `onDark` forces one variant for surfaces that are dark regardless of theme —
 * the navy sidebar, the auth modal, the footer.
 *
 * Left unset it follows the theme, and does so in CSS rather than JS: both
 * images are rendered and `dark:` toggles which is visible. That keys off the
 * `dark` class the pre-paint script puts on <html>, so the right mark shows on
 * the very first frame with no hydration gap.
 */
interface Props {
  onDark?: boolean | null
  size?: 'sm' | 'md' | 'lg' | 'xl'
}

const props = withDefaults(defineProps<Props>(), {
  onDark: null,
  size: 'md',
})

const imgFailed = ref(false)

/*
 * The artwork is the original logo with its transparent border trimmed off
 * (the 500×500 source was ~37% empty margin, which is why the wordmark was
 * unreadable at nav size). sm/md/lg are scaled to show the mark at the same
 * size as before; xl is the larger lockup for the public navbar and footer,
 * where "RealtyLinkPH" needs to be legible.
 */
const heightClass = computed(() => ({
  sm: 'h-7',
  md: 'h-[43px]',
  lg: 'h-[61px]',
  xl: 'h-[3.75rem]',
}[props.size]))

const NAVY  = '/realtylinkphlogo-trim.png'
const LIGHT = '/realtylinkphlogo-light-trim.png'

/** Forced variants render a single image; auto renders both and lets CSS pick. */
const forced = computed(() => (props.onDark === null ? null : props.onDark ? LIGHT : NAVY))
</script>

<template>
  <div class="flex items-center select-none">
    <template v-if="!imgFailed">
      <!-- Forced variant -->
      <img
        v-if="forced"
        :src="forced"
        alt="RealtyLink PH"
        :class="[heightClass, 'w-auto transition-[filter] duration-300 group-hover:brightness-110']"
        draggable="false"
        @error="imgFailed = true"
      />

      <!-- Theme-following: both rendered, CSS decides -->
      <template v-else>
        <img
          :src="NAVY"
          alt="RealtyLink PH"
          :class="[heightClass, 'w-auto block dark:hidden transition-[filter] duration-300 group-hover:brightness-110']"
          draggable="false"
          @error="imgFailed = true"
        />
        <img
          :src="LIGHT"
          alt=""
          aria-hidden="true"
          :class="[heightClass, 'w-auto hidden dark:block transition-[filter] duration-300 group-hover:brightness-110']"
          draggable="false"
        />
      </template>
    </template>

    <!-- Typeset fallback if the artwork ever fails to load -->
    <template v-else>
      <span
        class="font-display font-bold text-2xl tracking-tight"
        :class="onDark === true ? 'text-white' : 'text-brand-navy dark:text-white'"
      >Realty</span>
      <span class="font-display font-bold text-2xl tracking-tight text-brand-gold">Link</span>
      <span class="font-display text-sm font-bold ml-0.5 self-end mb-1 text-brand-gold">PH</span>
    </template>
  </div>
</template>
