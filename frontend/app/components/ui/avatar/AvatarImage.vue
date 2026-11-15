<script setup lang="ts">
import { AvatarImage } from 'reka-ui'
const props = defineProps<{ src: string; alt?: string }>()
</script>
<template>
  <AvatarImage v-bind="props" class="aspect-square size-full" />
</template>
