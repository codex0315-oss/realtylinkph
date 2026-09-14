<script setup lang="ts">
interface Props {
  error: {
    statusCode: number
    statusMessage?: string
    message?: string
  }
}

const props = defineProps<Props>()

const is404 = computed(() => props.error.statusCode === 404)
const is403 = computed(() => props.error.statusCode === 403)

function handleError() {
  clearError({ redirect: '/' })
}
</script>

<template>
  <div class="min-h-screen bg-white flex flex-col items-center justify-center px-4 text-center">
    <div class="max-w-md">
      <p class="text-8xl font-playfair font-bold text-brand-gold mb-4">
        {{ error.statusCode }}
      </p>

      <h1 class="text-2xl font-bold text-brand-navy mb-3">
        <template v-if="is404">Page Not Found</template>
        <template v-else-if="is403">Access Denied</template>
        <template v-else>Something Went Wrong</template>
      </h1>

      <p class="text-brand-text-secondary mb-8">
        <template v-if="is404">The page you're looking for doesn't exist or has been moved.</template>
        <template v-else-if="is403">You don't have permission to access this page.</template>
        <template v-else>{{ error.statusMessage ?? error.message ?? 'An unexpected error occurred.' }}</template>
      </p>

      <div class="flex items-center justify-center gap-3">
        <button
          class="btn-primary"
          @click="handleError"
        >
          Go Home
        </button>
        <button
          class="btn-outline"
          @click="$router.back()"
        >
          Go Back
        </button>
      </div>
    </div>
  </div>
</template>
