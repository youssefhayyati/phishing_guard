<script setup lang="ts">
import { motion } from 'motion-v'
import { ShieldAlert } from 'lucide-vue-next'
</script>

<template>
  <div class="relative flex min-h-screen items-center justify-center overflow-hidden bg-background px-4">
    <!-- Ambient red glow -->
    <div class="pointer-events-none absolute inset-0 bg-grid opacity-60" />
    <div class="pointer-events-none absolute -top-40 left-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-primary/20 blur-[120px]" />
    <div class="pointer-events-none absolute bottom-0 right-0 size-[24rem] rounded-full bg-primary/10 blur-[100px]" />

    <motion.div
      :initial="{ opacity: 0, y: 24, scale: 0.98 }"
      :animate="{ opacity: 1, y: 0, scale: 1 }"
      :transition="{ duration: 0.5, ease: 'easeOut' }"
      class="relative z-10 w-full max-w-sm"
    >
      <div class="mb-6 flex flex-col items-center text-center">
        <div class="mb-3 flex size-12 items-center justify-center rounded-xl bg-primary/15 ring-1 ring-primary/30">
          <ShieldAlert class="size-6 text-primary" />
        </div>
        <h1 class="text-xl font-semibold tracking-tight">PhishGuard</h1>
        <p class="text-sm text-muted-foreground">Security Awareness Training</p>
      </div>
      <slot />
    </motion.div>
  </div>
</template>
