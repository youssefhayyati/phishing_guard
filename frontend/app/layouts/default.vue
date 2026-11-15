<script setup lang="ts">
import { motion, AnimatePresence } from 'motion-v'
import AppSidebar from '~/components/dashboard/AppSidebar.vue'
import AppNavbar from '~/components/dashboard/AppNavbar.vue'

const sidebarOpen = useState<boolean>('sidebarOpen', () => false)
const route = useRoute()

// Close the mobile drawer on navigation.
watch(() => route.path, () => { sidebarOpen.value = false })
</script>

<template>
  <div class="flex min-h-screen bg-background text-foreground">
    <!-- Desktop sidebar -->
    <div class="hidden md:block">
      <div class="sticky top-0 h-screen">
        <AppSidebar />
      </div>
    </div>

    <!-- Mobile drawer -->
    <AnimatePresence>
      <template v-if="sidebarOpen">
        <motion.div
          :initial="{ opacity: 0 }"
          :animate="{ opacity: 1 }"
          :exit="{ opacity: 0 }"
          class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm md:hidden"
          @click="sidebarOpen = false"
        />
        <motion.div
          :initial="{ x: -280 }"
          :animate="{ x: 0 }"
          :exit="{ x: -280 }"
          :transition="{ type: 'spring', stiffness: 320, damping: 32 }"
          class="fixed inset-y-0 left-0 z-50 md:hidden"
        >
          <AppSidebar />
        </motion.div>
      </template>
    </AnimatePresence>

    <!-- Main column -->
    <div class="flex min-w-0 flex-1 flex-col">
      <AppNavbar />
      <main class="flex-1 bg-grid">
        <slot />
      </main>
    </div>
  </div>
</template>
