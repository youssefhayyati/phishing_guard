<script setup lang="ts">
import { motion } from 'motion-v'
import { Plus, Loader2, Search, Trash2, ChevronLeft, ChevronRight } from 'lucide-vue-next'
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
interface Meta { current_page: number; last_page: number; per_page: number; total: number }

const campaigns = ref<Campaign[]>([])
const meta = ref<Meta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
const pending = ref(true)
const query = ref('')
const page = ref(1)
const selected = ref<Set<number>>(new Set())
const deletingBulk = ref(false)
const deletingId = ref<number | null>(null)

async function load() {
  pending.value = true
  try {
    const res = await useApiFetch<{ data: Campaign[]; meta: Meta }>(
      `/api/campaigns?page=${page.value}&per_page=${meta.value.per_page}&q=${encodeURIComponent(query.value)}`,
    )
    campaigns.value = res.data.data
    meta.value = res.data.meta
  } catch (e) {
    console.error('Failed to load campaigns', e)
  } finally {
    pending.value = false
  }
}
onMounted(load)

// Debounced server-side search; resets to page 1.
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(query, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; load() }, 350)
})

function goto(p: number) {
  if (p < 1 || p > meta.value.last_page || p === meta.value.current_page) return
  page.value = p
  load()
}

// ─── Selection ────────────────────────────────────────────────────────────────
function toggle(id: number) {
  const s = new Set(selected.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selected.value = s
}
const allOnPageSelected = computed(() =>
  campaigns.value.length > 0 && campaigns.value.every(c => selected.value.has(c.id)),
)
function toggleAll() {
  const s = new Set(selected.value)
  if (allOnPageSelected.value) campaigns.value.forEach(c => s.delete(c.id))
  else campaigns.value.forEach(c => s.add(c.id))
  selected.value = s
}

// ─── Delete ───────────────────────────────────────────────────────────────────
async function deleteOne(c: Campaign) {
  if (!confirm(`Delete campaign "${c.name}"? This also tears down its containers on the server.`)) return
  deletingId.value = c.id
  try {
    await useApiFetch(`/api/campaigns/${c.id}`, { method: 'DELETE' })
    const s = new Set(selected.value); s.delete(c.id); selected.value = s
    if (campaigns.value.length === 1 && page.value > 1) page.value--
    await load()
  } catch (e) { console.error('Delete failed', e) } finally { deletingId.value = null }
}

async function deleteSelected() {
  const ids = [...selected.value]
  if (ids.length === 0) return
  if (!confirm(`Delete ${ids.length} campaign(s)? This also tears down their containers on the server.`)) return
  deletingBulk.value = true
  try {
    await useApiFetch('/api/campaigns/bulk-delete', { method: 'POST', body: { ids } })
    selected.value = new Set()
    if (page.value > 1 && ids.length >= campaigns.value.length) page.value = 1
    await load()
  } catch (e) { console.error('Bulk delete failed', e) } finally { deletingBulk.value = false }
}

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
      <div class="flex items-center gap-2">
        <Button
          v-if="selected.size > 0"
          variant="destructive"
          :disabled="deletingBulk"
          @click="deleteSelected"
        >
          <Loader2 v-if="deletingBulk" class="size-4 animate-spin" />
          <Trash2 v-else class="size-4" />
          Delete ({{ selected.size }})
        </Button>
        <Button @click="navigateTo('/campaigns/new')">
          <Plus class="size-4" />
          New campaign
        </Button>
      </div>
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
        <div class="hidden grid-cols-12 items-center gap-4 border-b border-border bg-muted/30 px-5 py-3 text-xs font-medium uppercase tracking-wider text-muted-foreground md:grid">
          <div class="col-span-1">
            <input type="checkbox" class="size-4 cursor-pointer rounded border-input accent-primary" :checked="allOnPageSelected" @change="toggleAll" />
          </div>
          <div class="col-span-3">Campaign</div>
          <div class="col-span-2">Status</div>
          <div class="col-span-2 text-right">Sent</div>
          <div class="col-span-2 text-right">Open rate</div>
          <div class="col-span-2 text-right">Actions</div>
        </div>

        <!-- Rows -->
        <motion.div
          v-for="(c, i) in campaigns"
          :key="c.id"
          :initial="{ opacity: 0, y: 10 }"
          :animate="{ opacity: 1, y: 0 }"
          :transition="{ delay: 0.03 * i, duration: 0.3 }"
          class="grid grid-cols-2 items-center gap-4 border-b border-border/60 px-5 py-4 transition-colors last:border-0 hover:bg-primary/5 md:grid-cols-12"
          :class="selected.has(c.id) ? 'bg-primary/5' : ''"
        >
          <div class="col-span-1 hidden md:block">
            <input
              type="checkbox"
              class="size-4 cursor-pointer rounded border-input accent-primary"
              :checked="selected.has(c.id)"
              @click.stop
              @change="toggle(c.id)"
            />
          </div>
          <div class="col-span-2 cursor-pointer md:col-span-3" @click="navigateTo(`/campaigns/${c.id}`)">
            <p class="text-sm font-medium">{{ c.name }}</p>
            <p class="text-xs text-muted-foreground">
              {{ c.template ?? c.target_url ?? '—' }}
              <template v-if="c.start_date"> · {{ c.start_date }}</template>
            </p>
          </div>
          <div class="cursor-pointer md:col-span-2" @click="navigateTo(`/campaigns/${c.id}`)">
            <Badge :variant="statusVariant(c.status)" class="capitalize">{{ c.status }}</Badge>
          </div>
          <div class="cursor-pointer text-right text-sm tabular-nums md:col-span-2" @click="navigateTo(`/campaigns/${c.id}`)">{{ c.sent.toLocaleString() }}</div>
          <div class="cursor-pointer text-right text-sm tabular-nums md:col-span-2" @click="navigateTo(`/campaigns/${c.id}`)">{{ rate(c.opened, c.sent) }}%</div>
          <div class="col-span-2 flex items-center justify-end gap-1">
            <button
              class="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-destructive hover:bg-destructive/10 transition-colors disabled:opacity-50"
              :disabled="deletingId === c.id"
              title="Delete campaign"
              @click.stop="deleteOne(c)"
            >
              <Loader2 v-if="deletingId === c.id" class="size-3.5 animate-spin" />
              <Trash2 v-else class="size-3.5" />
            </button>
          </div>
        </motion.div>

        <div v-if="campaigns.length === 0" class="px-5 py-16 text-center text-sm text-muted-foreground">
          <template v-if="query">No campaigns match "{{ query }}".</template>
          <template v-else>No campaigns yet. Click <span class="font-medium text-foreground">New campaign</span> to launch your first simulation.</template>
        </div>

        <!-- Pagination -->
        <div v-if="meta.total > 0" class="flex items-center justify-between gap-3 border-t border-border px-5 py-3 text-sm">
          <span class="text-muted-foreground">
            {{ meta.total }} campaign{{ meta.total === 1 ? '' : 's' }} · page {{ meta.current_page }} of {{ meta.last_page }}
          </span>
          <div class="flex items-center gap-1">
            <Button variant="outline" size="icon" :disabled="meta.current_page <= 1" @click="goto(meta.current_page - 1)">
              <ChevronLeft class="size-4" />
            </Button>
            <Button variant="outline" size="icon" :disabled="meta.current_page >= meta.last_page" @click="goto(meta.current_page + 1)">
              <ChevronRight class="size-4" />
            </Button>
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
