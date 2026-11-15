<script setup lang="ts">
import { Primitive } from 'reka-ui'
import { cn } from '~/lib/utils'
import { badgeVariants } from '.'

const props = withDefaults(defineProps<{
  variant?: 'default' | 'secondary' | 'destructive' | 'outline' | 'success' | 'warning'
  as?: any
  asChild?: boolean
  class?: any
}>(), { as: 'span' })
</script>

<template>
  <Primitive :as="as" :as-child="asChild" :class="cn(badgeVariants({ variant }), props.class)">
    <slot />
  </Primitive>
</template>
