<script setup lang="ts">
import { motion } from 'motion-v'
import { Send, MailOpen, MousePointerClick, Flag, Loader2 } from 'lucide-vue-next'
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '~/components/ui/card'
import { Badge } from '~/components/ui/badge'
import { Progress } from '~/components/ui/progress'
import StatCard from '~/components/dashboard/StatCard.vue'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Overview — PhishGuard' })

interface Overview {
  stats: { campaigns: number; emails_sent: number; click_rate: number; report_rate: number }
  trend: { label: string; value: number }[]
  departments: { name: string; risk: number }[]
  recent: { id: number; name: string; status: string; click_rate: number; sent: number }[]
}

const data = ref<Overview | null>(null)
const pending = ref(true)

onMounted(async () => {
  try {
    const res = await useApiFetch<Overview>('/api/dashboard/overview')
    data.value = res.data
  } catch (e) {
    console.error('Failed to load overview', e)
  } finally {
    pending.value = false
  }
})

const maxTrend = computed(() => Math.max(...(data.value?.trend.map(t => t.value) ?? [1]), 1))

function statusVariant(s: string) {
  return s === 'active' ? 'success' : s === 'scheduled' ? 'warning' : 'secondary'
}
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6 p-4 md:p-6">
    <!-- Header -->
    <motion.div
      :initial="{ opacity: 0, y: -8 }"
      :animate="{ opacity: 1, y: 0 }"
      :transition="{ duration: 0.4 }"
      class="flex flex-col gap-1"
    >
      <h2 class="text-xl font-semibold tracking-tight">Program Overview</h2>
      <p class="text-sm text-muted-foreground">
        Simulated phishing results across your organization — last 30 days.
      </p>
    </motion.div>

    <div v-if="pending" class="flex h-64 items-center justify-center text-muted-foreground">
      <Loader2 class="mr-2 size-5 animate-spin" /> Loading metrics…
    </div>

    <template v-else-if="data">
      <!-- Stat cards -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard :index="0" label="Campaigns" :value="String(data.stats.campaigns)" :delta="8" :icon="Send" />
        <StatCard :index="1" label="Emails sent" :value="data.stats.emails_sent.toLocaleString()" :delta="12" :icon="MailOpen" />
        <StatCard :index="2" label="Click rate" :value="`${data.stats.click_rate}%`" :delta="-4" delta-good="down" :icon="MousePointerClick" />
        <StatCard :index="3" label="Report rate" :value="`${data.stats.report_rate}%`" :delta="6" :icon="Flag" />
      </div>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Trend chart -->
        <Card class="lg:col-span-2">
          <CardHeader>
            <CardTitle>Click rate trend</CardTitle>
            <CardDescription>Percentage of recipients who clicked the simulated lure</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="flex h-56 items-end gap-3">
              <div v-for="(t, i) in data.trend" :key="t.label" class="flex h-full flex-1 flex-col items-center justify-end gap-2">
                <div class="relative w-full flex-1">
                  <motion.div
                    class="absolute bottom-0 w-full rounded-t-md bg-gradient-to-t from-primary/40 to-primary"
                    :style="{ height: `${Math.max((t.value / maxTrend) * 100, 3)}%`, transformOrigin: 'bottom' }"
                    :initial="{ scaleY: 0, opacity: 0 }"
                    :animate="{ scaleY: 1, opacity: 1 }"
                    :transition="{ delay: 0.08 * i, duration: 0.6, ease: 'easeOut' }"
                  />
                </div>
                <span class="text-xs text-muted-foreground">{{ t.label }}</span>
                <span class="text-xs font-medium tabular-nums">{{ t.value }}%</span>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Department risk -->
        <Card>
          <CardHeader>
            <CardTitle>Risk by department</CardTitle>
            <CardDescription>Based on click & report behavior</CardDescription>
          </CardHeader>
          <CardContent class="space-y-4">
            <div v-for="(d, i) in data.departments" :key="d.name" class="space-y-1.5">
              <div class="flex items-center justify-between text-sm">
                <span>{{ d.name }}</span>
                <span class="font-medium tabular-nums" :class="d.risk > 50 ? 'text-primary' : 'text-muted-foreground'">{{ d.risk }}%</span>
              </div>
              <Progress :model-value="d.risk" />
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Recent campaigns -->
      <Card>
        <CardHeader>
          <CardTitle>Recent campaigns</CardTitle>
          <CardDescription>Most recently launched simulations</CardDescription>
        </CardHeader>
        <CardContent class="space-y-2">
          <motion.div
            v-for="(c, i) in data.recent"
            :key="c.id"
            :initial="{ opacity: 0, x: -10 }"
            :animate="{ opacity: 1, x: 0 }"
            :transition="{ delay: 0.05 * i, duration: 0.3 }"
            class="flex items-center justify-between rounded-lg border border-border/70 bg-card/50 px-4 py-3 transition-colors hover:border-primary/40"
          >
            <div class="flex flex-col">
              <span class="text-sm font-medium">{{ c.name }}</span>
              <span class="text-xs text-muted-foreground">{{ c.sent.toLocaleString() }} recipients</span>
            </div>
            <div class="flex items-center gap-4">
              <span class="text-sm tabular-nums text-muted-foreground">{{ c.click_rate }}% clicked</span>
              <Badge :variant="statusVariant(c.status)" class="capitalize">{{ c.status }}</Badge>
            </div>
          </motion.div>
        </CardContent>
      </Card>
    </template>

    <div v-else class="flex h-64 items-center justify-center text-sm text-muted-foreground">
      Couldn't load dashboard data. Is the API running?
    </div>
  </div>
</template>
