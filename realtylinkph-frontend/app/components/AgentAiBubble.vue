<script setup lang="ts">
// Floating RealtyLink AI launcher for agents — opens the listing assistant
// full-screen. Mounted globally (app.vue), gated to agents.
const authStore = useAuthStore()

const open      = ref(false)
const hasOpened = ref(false)

const show = computed(() => authStore.isAuthenticated && authStore.isAgent)

function lockScroll(v: boolean) {
  if (import.meta.client) document.body.style.overflow = v ? 'hidden' : ''
}
function openChat() { hasOpened.value = true; open.value = true; lockScroll(true) }
function closeChat() { open.value = false; lockScroll(false) }

function onKey(e: KeyboardEvent) { if (e.key === 'Escape' && open.value) closeChat() }

// ── Draggable bubble ──────────────────────────────────────────────────────────
const BUBBLE = 64
const pos      = ref<{ x: number; y: number } | null>(null)
const dragging = ref(false)
let startX = 0, startY = 0, baseX = 0, baseY = 0, moved = false

const bubbleStyle = computed(() => {
  const skin = 'background: linear-gradient(135deg, #D4AF37 0%, #c9a227 100%); box-shadow: 0 10px 30px rgba(212,175,55,0.45);'
  return pos.value
    ? `${skin} left:${pos.value.x}px; top:${pos.value.y}px; right:auto; bottom:auto;`
    : `${skin} right:1.5rem; bottom:1.5rem;`
})
function clampPos(x: number, y: number) {
  const maxX = window.innerWidth - BUBBLE - 8
  const maxY = window.innerHeight - BUBBLE - 8
  return { x: Math.min(Math.max(8, x), maxX), y: Math.min(Math.max(8, y), maxY) }
}
function onPointerDown(e: PointerEvent) {
  const el = e.currentTarget as HTMLElement
  const r  = el.getBoundingClientRect()
  baseX = r.left; baseY = r.top
  startX = e.clientX; startY = e.clientY
  moved = false
  dragging.value = true
  el.setPointerCapture(e.pointerId)
}
function onPointerMove(e: PointerEvent) {
  if (!dragging.value) return
  const dx = e.clientX - startX, dy = e.clientY - startY
  if (Math.abs(dx) > 4 || Math.abs(dy) > 4) moved = true
  pos.value = clampPos(baseX + dx, baseY + dy)
}
function onPointerUp() {
  if (!dragging.value) return
  dragging.value = false
  if (moved) { if (import.meta.client && pos.value) localStorage.setItem('agent-ai-bubble-pos', JSON.stringify(pos.value)) }
  else openChat()
}
function reclamp() { if (pos.value) pos.value = clampPos(pos.value.x, pos.value.y) }

onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('resize', reclamp, { passive: true })
  try {
    const s = localStorage.getItem('agent-ai-bubble-pos')
    if (s) { const p = JSON.parse(s); if (p && typeof p.x === 'number') pos.value = clampPos(p.x, p.y) }
  } catch { /* ignore */ }
})
onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', reclamp)
  lockScroll(false)
})
</script>

<template>
  <Teleport to="body">
    <!-- Bubble (FAB) -->
    <Transition name="ai-fab">
      <button
        v-if="show && !open"
        type="button"
        class="fixed z-[80] h-16 w-16 rounded-full flex items-center justify-center ring-2 ring-white/60 select-none touch-none active:scale-95"
        :class="dragging ? 'cursor-grabbing' : 'cursor-grab hover:scale-105 transition-transform'"
        :style="bubbleStyle"
        aria-label="Open RealtyLink AI (drag to move)"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
      >
        <img src="/realtylink-ai1.png" alt="" class="h-14 w-14 object-contain drop-shadow" />
        <span class="absolute top-0.5 right-0.5 flex h-4 w-4">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-navy/40" />
          <span class="relative inline-flex rounded-full h-4 w-4 bg-brand-navy items-center justify-center text-[8px] font-bold text-white ring-2 ring-white/70">AI</span>
        </span>
      </button>
    </Transition>

    <!-- Full-screen overlay -->
    <Transition name="ai-overlay">
      <div v-if="hasOpened" v-show="open" class="fixed inset-0 z-[90] bg-white dark:bg-[#08152F]">
        <AgentAiChat closable @close="closeChat" />
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.ai-fab-enter-active, .ai-fab-leave-active { transition: opacity .2s ease, transform .2s ease; }
.ai-fab-enter-from, .ai-fab-leave-to { opacity: 0; transform: scale(0.6); }

.ai-overlay-enter-active, .ai-overlay-leave-active { transition: opacity .25s ease, transform .25s ease; }
.ai-overlay-enter-from, .ai-overlay-leave-to { opacity: 0; transform: translateY(16px); }
</style>
