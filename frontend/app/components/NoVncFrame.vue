<script setup lang="ts">
import { ExternalLink } from 'lucide-vue-next'

// Live noVNC viewer. The VNC framebuffer is fixed at 1920×1080; we scale the
// iframe down with a CSS transform so it fits the container (contain), and let
// noVNC's own scale=true handle fitting the canvas inside the iframe.
const props = defineProps<{ url: string }>()

const VNC_W = 1920
const VNC_H = 1080
const container = ref<HTMLDivElement | null>(null)
const scale = ref(1)

function compute() {
  const el = container.value
  if (!el) return
  const w = el.clientWidth
  const h = el.clientHeight
  if (w > 0 && h > 0) scale.value = Math.min(w / VNC_W, h / VNC_H)
}

let ro: ResizeObserver | null = null

onMounted(() => {
  nextTick(() => requestAnimationFrame(() => {
    compute()
    if (container.value) {
      ro = new ResizeObserver(compute)
      ro.observe(container.value)
    }
    // Re-measure after layout settles.
    setTimeout(compute, 150)
    setTimeout(compute, 350)
  }))
  window.addEventListener('resize', compute)
})

onBeforeUnmount(() => {
  ro?.disconnect()
  window.removeEventListener('resize', compute)
})
</script>

<template>
  <div ref="container" class="relative h-full w-full overflow-hidden rounded-lg border border-border bg-black">
    <iframe
      :src="props.url"
      class="absolute left-1/2 top-1/2 border-0"
      :style="{
        width:           VNC_W + 'px',
        height:          VNC_H + 'px',
        transformOrigin: 'center center',
        transform:       `translate(-50%, -50%) scale(${scale})`,
      }"
      scrolling="no"
      allow="fullscreen clipboard-read clipboard-write"
      allowfullscreen
      @load="compute"
    />
    <!-- Fallback hint -->
    <div class="absolute bottom-3 right-3 z-10 flex items-center gap-2 rounded-md border border-border bg-background/90 px-3 py-1.5 text-xs text-muted-foreground backdrop-blur-sm">
      Not loading?
      <a :href="props.url" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1 text-primary hover:underline">
        Open in new tab <ExternalLink class="size-3" />
      </a>
    </div>
  </div>
</template>
