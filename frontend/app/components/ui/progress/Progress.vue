<script setup lang="ts">
import { ProgressIndicator, ProgressRoot } from 'reka-ui'
import { cn } from '~/lib/utils'

const props = withDefaults(defineProps<{
  modelValue?: number
  class?: any
}>(), {
  modelValue: 0,
})
</script>

<template>
  <ProgressRoot
    :model-value="props.modelValue"
    :class="cn('bg-primary/20 relative h-2 w-full overflow-hidden rounded-full', props.class)"
  >
    <ProgressIndicator
      class="bg-primary h-full w-full flex-1 transition-all duration-500"
      :style="`transform: translateX(-${100 - (props.modelValue ?? 0)}%);`"
    />
  </ProgressRoot>
</template>
