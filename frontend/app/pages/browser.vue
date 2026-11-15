<script setup lang="ts">
import { Loader2, RefreshCw, ExternalLink, WifiOff } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import NoVncFrame from '~/components/NoVncFrame.vue'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Browser — PhishGuard' })

type State = 'loading' | 'ready' | 'offline'
const state = ref<State>('loading')
const vncUrl = ref('')
const error = ref('')

// Point at the live noVNC/Chrome session without navigating it anywhere —
// Chrome stays on about:blank, running and ready for the user to drive.
async function connect() {
  state.value = 'loading'
  error.value = ''
  try {
    const res = await useApiFetch<{ reachable: boolean; url: string }>('/api/novnc/status')
    vncUrl.value = res.data.url
    if (res.data.reachable) {
      state.value = 'ready'
    } else {
      state.value = 'offline'
      error.value = 'The noVNC container is not reachable. Make sure it is running.'
    }
  } catch (e: any) {
    state.value = 'offline'
    error.value = e?.data?.message ?? 'Could not reach the backend.'
  }
}

onMounted(connect)
</script>

<template>
  <div class="flex h-[calc(100dvh-4rem)] flex-col gap-3 p-4 md:p-6">
    <!-- Header -->
    <div class="flex shrink-0 flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Browser</h2>
        <p class="text-sm text-muted-foreground">Live Chrome session — running and ready to navigate.</p>
      </div>
      <div class="flex items-center gap-3">
        <span v-if="state === 'ready'" class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
          <span class="relative flex size-2">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-green-500 opacity-60" />
            <span class="relative inline-flex size-2 rounded-full bg-green-500" />
          </span>
          Connected
        </span>
        <a
          v-if="vncUrl && state === 'ready'"
          :href="vncUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="flex items-center gap-1 text-xs font-medium text-primary hover:underline"
        >
          Full tab <ExternalLink class="size-3" />
        </a>
        <Button variant="outline" size="sm" :disabled="state === 'loading'" @click="connect">
          <RefreshCw class="size-4" :class="state === 'loading' ? 'animate-spin' : ''" />
          Reconnect
        </Button>
      </div>
    </div>

    <!-- Body -->
    <div class="min-h-0 flex-1">
      <!-- Loading -->
      <div
        v-if="state === 'loading'"
        class="flex h-full items-center justify-center rounded-lg border border-border bg-muted/20 text-sm text-muted-foreground"
      >
        <Loader2 class="mr-2 size-5 animate-spin" /> Connecting to Chrome…
      </div>

      <!-- Offline -->
      <div
        v-else-if="state === 'offline'"
        class="flex h-full flex-col items-center justify-center gap-3 rounded-lg border border-border bg-muted/20 px-6 text-center"
      >
        <div class="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
          <WifiOff class="size-6" />
        </div>
        <div>
          <p class="text-sm font-semibold">Chrome session unavailable</p>
          <p class="mt-1 max-w-sm text-xs text-muted-foreground">{{ error }}</p>
        </div>
        <Button size="sm" @click="connect">
          <RefreshCw class="size-4" /> Try again
        </Button>
      </div>

      <!-- Live viewer -->
      <NoVncFrame v-else :url="vncUrl" />
    </div>
  </div>
</template>
