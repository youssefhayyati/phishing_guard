<script setup lang="ts">
import { motion } from 'motion-v'
import { Plus, Loader2, Search } from 'lucide-vue-next'
import { Card, CardContent } from '~/components/ui/card'
import { Button } from '~/components/ui/button'
import { Badge } from '~/components/ui/badge'
import { Input } from '~/components/ui/input'
definePageMeta({ middleware: 'auth' })
useHead({ title: 'Campaigns — PhishGuard' })

interface Campaign {
  id: number
  name: string
  description: string | null
  template: string | null
  status: 'active' | 'scheduled' | 'completed' | 'draft'
  target_url: string | null
  server_domain: string | null
  sent: number
  opened: number
  clicked: number
  reported: number
  start_date: string | null
}

const campaigns = ref<Campaign[]>([])
const pending = ref(true)
const query = ref('')

onMounted(async () => {
  try {
    const res = await useApiFetch<{ data: Campaign[] }>('/api/campaigns')
    campaigns.value = res.data.data
  } catch (e) {
    console.error('Failed to load campaigns', e)
  } finally {
    pending.value = false
  }
})

const filtered = computed(() =>
  campaigns.value.filter(c => c.name.toLowerCase().includes(query.value.toLowerCase())),
)

function rate(part: number, total: number) {
  return total === 0 ? 0 : Math.round((part / total) * 100)
}

function statusVariant(s: Campaign['status']) {
  return s === 'active' ? 'success' : s === 'scheduled' ? 'warning' : s === 'draft' ? 'outline' : 'secondary'
}
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
    <!-- Header -->
    <motion.div
      :initial="{ opacity: 0, y: -8 }"
      :animate="{ opacity: 1, y: 0 }"
      :transition="{ duration: 0.4 }"
      class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Campaigns</h2>
        <p class="text-sm text-muted-foreground">Create and track simulated phishing campaigns.</p>
      </div>
      <Button @click="navigateTo('/campaigns/new')">
        <Plus class="size-4" />
        New campaign
      </Button>
    </motion.div>

    <!-- Search -->
    <div class="relative max-w-sm">
      <Search class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
      <Input v-model="query" placeholder="Search campaigns…" class="pl-9" />
    </div>

    <div v-if="pending" class="flex h-64 items-center justify-center text-muted-foreground">
      <Loader2 class="mr-2 size-5 animate-spin" /> Loading campaigns…
    </div>

    <Card v-else class="overflow-hidden p-0">
      <CardContent class="p-0">
        <!-- Table head -->
        <div class="hidden grid-cols-12 gap-4 border-b border-border bg-muted/30 px-5 py-3 text-xs font-medium uppercase tracking-wider text-muted-foreground md:grid">
          <div class="col-span-4">Campaign</div>
          <div class="col-span-2">Status</div>
          <div class="col-span-2 text-right">Sent</div>
          <div class="col-span-2 text-right">Open rate</div>
          <div class="col-span-2 text-right">Click rate</div>
        </div>

        <!-- Rows -->
        <NuxtLink
          v-for="(c, i) in filtered"
          :key="c.id"
          :to="`/campaigns/${c.id}`"
        >
        <motion.div
          :initial="{ opacity: 0, y: 10 }"
          :animate="{ opacity: 1, y: 0 }"
          :transition="{ delay: 0.04 * i, duration: 0.3 }"
          class="grid cursor-pointer grid-cols-2 gap-4 border-b border-border/60 px-5 py-4 transition-colors last:border-0 hover:bg-primary/5 md:grid-cols-12 md:items-center"
        >
          <div class="col-span-2 md:col-span-4">
            <p class="text-sm font-medium">{{ c.name }}</p>
            <p class="text-xs text-muted-foreground">
              {{ c.template ?? c.target_url ?? '—' }}
              <template v-if="c.start_date"> · {{ c.start_date }}</template>
            </p>
          </div>
          <div class="md:col-span-2">
            <Badge :variant="statusVariant(c.status)" class="capitalize">{{ c.status }}</Badge>
          </div>
          <div class="text-right text-sm tabular-nums md:col-span-2">{{ c.sent.toLocaleString() }}</div>
          <div class="text-right text-sm tabular-nums md:col-span-2">{{ rate(c.opened, c.sent) }}%</div>
          <div class="text-right md:col-span-2">
            <span
              class="text-sm font-medium tabular-nums"
              :class="rate(c.clicked, c.sent) > 20 ? 'text-primary' : 'text-foreground'"
            >
              {{ rate(c.clicked, c.sent) }}%
            </span>
          </div>
        </motion.div>
        </NuxtLink>

        <div v-if="filtered.length === 0" class="px-5 py-16 text-center text-sm text-muted-foreground">
          <template v-if="query">No campaigns match "{{ query }}".</template>
          <template v-else>No campaigns yet. Click <span class="font-medium text-foreground">New campaign</span> to launch your first simulation.</template>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
