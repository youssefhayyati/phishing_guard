<script setup lang="ts">
import { AvatarFallback } from 'reka-ui'
import { cn } from '~/lib/utils'
const props = defineProps<{ delayMs?: number; class?: any }>()
</script>
<template>
  <AvatarFallback :delay-ms="props.delayMs" :class="cn('bg-muted flex size-full items-center justify-center rounded-full text-sm font-medium', props.class)">
    <slot />
  </AvatarFallback>
</template>
