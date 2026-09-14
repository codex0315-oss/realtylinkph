<script setup lang="ts">
import type { Property } from '~/types'

interface Props {
  properties: Property[]
  loading?: boolean
  skeletonCount?: number
  dense?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  loading:       false,
  skeletonCount: 8,
  dense:         false,
})

defineEmits<{ toggle: [payload: { id: number; favorited: boolean }] }>()

const gridClass = computed(() =>
  props.dense
    ? 'grid grid-cols-1 sm:grid-cols-2 gap-5'
    : 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6',
)
</script>

<template>
  <div :class="gridClass">
    <template v-if="loading">
      <PropertySkeleton v-for="i in skeletonCount" :key="i" />
    </template>
    <template v-else>
      <PropertyCard v-for="p in properties" :key="p.id" :property="p" @toggle="$emit('toggle', $event)" />
    </template>
  </div>
</template>
