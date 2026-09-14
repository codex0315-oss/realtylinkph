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
  size?: 'sm' | 'md' | 'lg'
}

const props = withDefaults(defineProps<Props>(), {
  onDark: null,
  size: 'md',
})

const imgFailed = ref(false)

const heightClass = computed(() => ({
  sm: 'h-11',
  md: 'h-[68px]',
  lg: 'h-24',
}[props.size]))

const NAVY  = '/realtylinkphlogo.png'
const LIGHT = '/realtylinkphlogo-light.png'

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
