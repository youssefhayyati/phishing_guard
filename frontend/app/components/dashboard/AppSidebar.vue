<script setup lang="ts">
import { motion } from 'motion-v'
import { ShieldAlert } from 'lucide-vue-next'
import { navItems } from '~/lib/navigation'

const route = useRoute()
const isActive = (to: string) =>
  to === '/' ? route.path === '/' : route.path.startsWith(to)
</script>

<template>
  <aside class="flex h-full w-64 flex-col border-r border-sidebar-border bg-sidebar">
    <!-- Brand -->
    <div class="flex h-16 items-center gap-2.5 border-b border-sidebar-border px-5">
      <div class="flex size-9 items-center justify-center rounded-lg bg-primary/15 ring-1 ring-primary/30">
        <ShieldAlert class="size-5 text-primary" />
      </div>
      <div class="leading-tight">
        <p class="text-sm font-semibold tracking-tight">PhishGuard</p>
        <p class="text-[11px] text-muted-foreground">Awareness Training</p>
      </div>
    </div>

    <!-- Nav -->
    <nav class="flex-1 space-y-1 px-3 py-4">
      <p class="px-3 pb-2 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        Menu
      </p>
      <NuxtLink
        v-for="(item, i) in navItems"
        :key="item.to"
        :to="item.to"
        class="block"
      >
        <motion.div
          :initial="{ opacity: 0, x: -12 }"
          :animate="{ opacity: 1, x: 0 }"
          :transition="{ delay: 0.05 * i + 0.05, duration: 0.3 }"
          :while-hover="{ x: 3 }"
          class="group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
          :class="isActive(item.to)
            ? 'bg-primary/15 text-foreground'
            : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'"
        >
          <motion.span
            v-if="isActive(item.to)"
            layout-id="sidebar-active"
            class="absolute left-0 top-1/2 h-6 w-1 -translate-y-1/2 rounded-r-full bg-primary"
          />
          <component :is="item.icon" class="size-4.5 shrink-0" :class="isActive(item.to) ? 'text-primary' : ''" />
          <span>{{ item.label }}</span>
        </motion.div>
      </NuxtLink>
    </nav>

    <!-- Footer note -->
    <div class="border-t border-sidebar-border p-4">
      <div class="rounded-lg bg-primary/10 p-3 ring-1 ring-primary/20">
        <p class="text-xs font-medium text-foreground">Simulation only</p>
        <p class="mt-1 text-[11px] leading-snug text-muted-foreground">
          Campaigns run against consented employees for training.
        </p>
      </div>
    </div>
  </aside>
</template>
