<script setup lang="ts">
interface Testimonial {
  quote:    string
  name:     string
  role:     string
  initials: string
}

const testimonials: Testimonial[] = [
  {
    quote:    'I found my dream condo in just a week! The agent was very professional and helpful throughout the entire process.',
    name:     'Maria Santos',
    role:     'Condo Buyer',
    initials: 'MS',
  },
  {
    quote:    'The verified agent system gave me confidence in every step. Highly recommend RealtyLink PH to anyone looking for property!',
    name:     'John Reyes',
    role:     'Homeowner',
    initials: 'JR',
  },
  {
    quote:    'Found a great commercial space for my business. The search filters made it very easy to narrow down the right options.',
    name:     'Ana Villanueva',
    role:     'Business Owner',
    initials: 'AV',
  },
  {
    quote:    'Excellent platform! The agents are very responsive and the listings are accurate and up to date.',
    name:     'Carlo Mendoza',
    role:     'Property Investor',
    initials: 'CM',
  },
]

const pageSize   = 2
const current    = ref(0)
const direction  = ref<'next' | 'prev'>('next')
const totalPages = computed(() => Math.ceil(testimonials.length / pageSize))
const visible    = computed(() => testimonials.slice(current.value * pageSize, current.value * pageSize + pageSize))

let timer: ReturnType<typeof setInterval> | null = null
const paused = ref(false)

function go(page: number) {
  direction.value = page > current.value ? 'next' : 'prev'
  current.value = (page + totalPages.value) % totalPages.value
}
function next() { go(current.value + 1) }
function prev() { go(current.value - 1) }

function start() {
  timer = setInterval(() => { if (!paused.value) next() }, 5500)
}
function stop() {
  if (timer) { clearInterval(timer); timer = null }
}

onMounted(start)
onUnmounted(stop)
</script>

<template>
  <section
    id="testimonials"
    class="relative bg-brand-cream dark:bg-brand-navy-deep py-20 transition-colors duration-500 scroll-mt-20 overflow-hidden"
  >
    <div class="absolute inset-0 bg-grid-lines mask-radial-fade pointer-events-none" />
    <div class="absolute inset-0 bg-grid-lines-lg mask-radial-fade pointer-events-none" />

    <div class="relative max-w-content mx-auto px-6">
      <!-- Header -->
      <div v-reveal class="flex items-center justify-center gap-4 mb-12">
        <div class="flex-1 max-w-[80px] h-px bg-brand-gold/40" />
        <h2 class="font-display text-2xl md:text-3xl font-bold text-brand-navy dark:text-white text-center whitespace-nowrap">
          What Our Clients Say
        </h2>
        <div class="flex-1 max-w-[80px] h-px bg-brand-gold/40" />
      </div>

      <!-- Cards -->
      <div
        v-reveal="{ delay: 120 }"
        class="relative max-w-4xl mx-auto"
        @mouseenter="paused = true"
        @mouseleave="paused = false"
      >
        <!-- Arrows -->
        <button
          class="hidden lg:flex absolute -left-14 top-1/2 -translate-y-1/2 h-10 w-10 rounded-full items-center justify-center
                 border border-gray-300 dark:border-white/15 text-brand-navy dark:text-white/70
                 hover:border-brand-gold hover:text-brand-gold hover:-translate-x-0.5 transition-all duration-200"
          aria-label="Previous testimonials"
          @click="prev"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7" /></svg>
        </button>
        <button
          class="hidden lg:flex absolute -right-14 top-1/2 -translate-y-1/2 h-10 w-10 rounded-full items-center justify-center
                 border border-gray-300 dark:border-white/15 text-brand-navy dark:text-white/70
                 hover:border-brand-gold hover:text-brand-gold hover:translate-x-0.5 transition-all duration-200"
          aria-label="Next testimonials"
          @click="next"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" /></svg>
        </button>

        <Transition :name="direction === 'next' ? 'slide-next' : 'slide-prev'" mode="out-in">
          <div :key="current" class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <figure
              v-for="t in visible"
              :key="t.name"
              class="relative bg-white dark:bg-brand-navy-mid rounded-2xl p-7 shadow-sm
                     border border-gray-100 dark:border-white/10
                     hover:shadow-[0_18px_44px_rgba(8,21,47,0.12)] dark:hover:shadow-[0_18px_44px_rgba(0,0,0,0.5)]
                     hover:-translate-y-1 transition-all duration-300"
            >
              <!-- decorative quote mark -->
              <span class="absolute top-4 right-6 font-display text-6xl leading-none text-brand-gold/15 select-none" aria-hidden="true">"</span>

              <AppRating :value="5" size="sm" :readonly="true" class="mb-4" />

              <blockquote class="relative text-sm text-brand-text-secondary dark:text-white/65 leading-relaxed italic">
                "{{ t.quote }}"
              </blockquote>

              <figcaption class="flex items-center gap-3 mt-6 pt-5 border-t border-gray-100 dark:border-white/10">
                <div class="h-10 w-10 rounded-full bg-brand-navy dark:bg-brand-gold flex items-center justify-center
                            text-brand-gold dark:text-brand-navy font-bold text-sm flex-shrink-0">
                  {{ t.initials }}
                </div>
                <div>
                  <p class="text-sm font-semibold text-brand-navy dark:text-white">{{ t.name }}</p>
                  <p class="text-xs text-brand-text-light dark:text-white/40">{{ t.role }}</p>
                </div>
              </figcaption>
            </figure>
          </div>
        </Transition>
      </div>

      <!-- Dot pagination -->
      <div class="flex justify-center gap-2 mt-9">
        <button
          v-for="i in totalPages"
          :key="i"
          class="h-2 rounded-full transition-all duration-300"
          :class="i - 1 === current
            ? 'w-7 bg-brand-gold'
            : 'w-2 bg-gray-300 dark:bg-white/20 hover:bg-gray-400 dark:hover:bg-white/40'"
          :aria-label="`Go to testimonial page ${i}`"
          @click="go(i - 1)"
        />
      </div>
    </div>
  </section>
</template>

<style scoped>
.slide-next-enter-active,
.slide-next-leave-active,
.slide-prev-enter-active,
.slide-prev-leave-active {
  transition: opacity 0.4s ease, transform 0.4s cubic-bezier(0.22, 1, 0.36, 1);
}
.slide-next-enter-from { opacity: 0; transform: translateX(36px); }
.slide-next-leave-to   { opacity: 0; transform: translateX(-36px); }
.slide-prev-enter-from { opacity: 0; transform: translateX(-36px); }
.slide-prev-leave-to   { opacity: 0; transform: translateX(36px); }

@media (prefers-reduced-motion: reduce) {
  .slide-next-enter-active,
  .slide-next-leave-active,
  .slide-prev-enter-active,
  .slide-prev-leave-active { transition: none; }
  .slide-next-enter-from, .slide-next-leave-to,
  .slide-prev-enter-from, .slide-prev-leave-to { transform: none; }
}
</style>
