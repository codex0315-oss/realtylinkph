<script setup lang="ts">
const emit = defineEmits<{ (e: 'captured', file: File | null): void }>()

const videoEl  = ref<HTMLVideoElement | null>(null)
const canvasEl = ref<HTMLCanvasElement | null>(null)
const stream   = ref<MediaStream | null>(null)
const preview  = ref<string | null>(null)
const active   = ref(false)
const error    = ref<string | null>(null)

async function start() {
  error.value = null
  try {
    stream.value = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
      audio: false,
    })
    active.value = true
    await nextTick()
    if (videoEl.value) {
      videoEl.value.srcObject = stream.value
      await videoEl.value.play()
    }
  } catch {
    error.value = 'Could not access the camera. Please allow camera permission in your browser, then try again.'
    active.value = false
  }
}

function stop() {
  stream.value?.getTracks().forEach(t => t.stop())
  stream.value = null
  active.value = false
}

function capture() {
  const v = videoEl.value
  const c = canvasEl.value
  if (!v || !c) return
  c.width = v.videoWidth
  c.height = v.videoHeight
  const ctx = c.getContext('2d')
  if (!ctx) return
  ctx.drawImage(v, 0, 0, c.width, c.height)
  preview.value = c.toDataURL('image/jpeg', 0.9)
  c.toBlob((blob) => {
    if (blob) emit('captured', new File([blob], 'selfie.jpg', { type: 'image/jpeg' }))
  }, 'image/jpeg', 0.9)
  stop()
}

function retake() {
  preview.value = null
  emit('captured', null)
  start()
}

onUnmounted(stop)
</script>

<template>
  <div>
    <div class="relative aspect-[4/3] rounded-2xl overflow-hidden bg-gray-900 flex items-center justify-center">
      <img v-if="preview" :src="preview" alt="Captured selfie" class="w-full h-full object-cover" />
      <video v-show="active && !preview" ref="videoEl" class="w-full h-full object-cover scale-x-[-1]" muted playsinline />

      <!-- Idle -->
      <div v-if="!active && !preview" class="text-center text-white/50 px-6">
        <svg class="h-10 w-10 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
        <p class="text-sm">Camera is off</p>
      </div>

      <!-- Face guide -->
      <div v-if="active && !preview" class="absolute inset-0 flex items-center justify-center pointer-events-none">
        <div class="h-52 w-40 border-2 border-brand-gold/70 rounded-[48%]" />
        <span class="absolute bottom-3 left-1/2 -translate-x-1/2 text-[0.6875rem] text-white/80 bg-black/40 px-2 py-1 rounded-full">Center your face in the oval</span>
      </div>

      <!-- Captured badge -->
      <div v-if="preview" class="absolute top-3 right-3 flex items-center gap-1 bg-emerald-500 text-white text-[0.625rem] font-bold px-2.5 py-1 rounded-full">
        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" /></svg>
        Captured
      </div>
    </div>

    <canvas ref="canvasEl" class="hidden" />

    <p v-if="error" class="text-xs text-red-500 mt-2">{{ error }}</p>

    <div class="flex items-center gap-2 mt-3">
      <button v-if="!active && !preview" type="button" class="inline-flex items-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-5 py-2.5 rounded-xl hover:-translate-y-0.5 transition-all" @click="start">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
        Start camera
      </button>
      <button v-if="active && !preview" type="button" class="inline-flex items-center gap-2 bg-brand-gold text-brand-navy font-bold text-sm px-5 py-2.5 rounded-xl hover:-translate-y-0.5 transition-all" @click="capture">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
        Capture selfie
      </button>
      <button v-if="preview" type="button" class="inline-flex items-center gap-2 border border-gray-300 dark:border-white/15 text-brand-navy dark:text-white font-semibold text-sm px-5 py-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-white/5 transition-all" @click="retake">
        Retake
      </button>
    </div>
  </div>
</template>
