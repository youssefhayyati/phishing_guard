<script setup lang="ts">
import { motion } from 'motion-v'
import { TrendingUp, TrendingDown } from 'lucide-vue-next'
import { Card } from '~/components/ui/card'

const props = defineProps<{
  label: string
  value: string
  delta?: number
  deltaGood?: 'up' | 'down'
  icon: any
  index?: number
}>()

const isPositive = computed(() => {
  if (props.delta === undefined) return true
  const good = props.deltaGood ?? 'up'
  return good === 'up' ? props.delta >= 0 : props.delta <= 0
})
</script>

<template>
  <motion.div
    :initial="{ opacity: 0, y: 16 }"
    :animate="{ opacity: 1, y: 0 }"
    :transition="{ delay: 0.06 * (index ?? 0), duration: 0.4, ease: 'easeOut' }"
    :while-hover="{ y: -4 }"
  >
    <Card class="relative gap-0 overflow-hidden p-5">
      <div class="absolute right-0 top-0 size-24 translate-x-6 -translate-y-6 rounded-full bg-primary/10 blur-2xl" />
      <div class="flex items-center justify-between">
        <span class="text-sm text-muted-foreground">{{ label }}</span>
        <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary ring-1 ring-primary/20">
          <component :is="icon" class="size-4.5" />
        </div>
      </div>
      <div class="mt-3 flex items-end justify-between">
        <span class="text-2xl font-semibold tracking-tight tabular-nums">{{ value }}</span>
        <span
          v-if="delta !== undefined"
          class="flex items-center gap-1 text-xs font-medium"
          :class="isPositive ? 'text-emerald-400' : 'text-primary'"
        >
          <component :is="delta >= 0 ? TrendingUp : TrendingDown" class="size-3.5" />
          {{ Math.abs(delta) }}%
        </span>
      </div>
    </Card>
  </motion.div>
</template>
