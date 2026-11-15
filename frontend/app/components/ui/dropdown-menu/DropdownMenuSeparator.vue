<script setup lang="ts">
import { DropdownMenuSeparator } from 'reka-ui'
import { cn } from '~/lib/utils'

const props = defineProps<{ class?: any }>()
</script>

<template>
  <DropdownMenuSeparator :class="cn('bg-border -mx-1 my-1 h-px', props.class)" />
</template>
