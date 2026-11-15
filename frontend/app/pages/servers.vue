<script setup lang="ts">
import { motion } from 'motion-v'
import { Plus, Loader2, Server as ServerIcon, Pencil, Trash2, X, KeyRound, PlugZap } from 'lucide-vue-next'
import { Card, CardContent } from '~/components/ui/card'
import { Button } from '~/components/ui/button'
import { Badge } from '~/components/ui/badge'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Servers — PhishGuard' })

interface Server {
  id: number
  name: string | null
  ip_address: string
  ssh_port: number
  ssh_user: string
  has_password: boolean
  status: string
  last_checked_at: string | null
  created_at: string | null
}

const servers = ref<Server[]>([])
const pending = ref(true)

async function load() {
  pending.value = true
  try {
    const res = await useApiFetch<{ data: Server[] }>('/api/servers')
    servers.value = res.data.data
  } catch (e) {
    console.error('Failed to load servers', e)
  } finally {
    pending.value = false
  }
}
onMounted(load)

// ─── Create / edit modal ──────────────────────────────────────────────────────
const showModal = ref(false)
const editing = ref<Server | null>(null)
const saving = ref(false)
const formError = ref('')

const form = ref({
  name: '',
  ip_address: '',
  ssh_user: 'root',
  ssh_port: 22,
  password: '',
})

function openCreate() {
  editing.value = null
  form.value = { name: '', ip_address: '', ssh_user: 'root', ssh_port: 22, password: '' }
  formError.value = ''
  showModal.value = true
}

function openEdit(s: Server) {
  editing.value = s
  form.value = {
    name: s.name ?? '',
    ip_address: s.ip_address,
    ssh_user: s.ssh_user,
    ssh_port: s.ssh_port,
    password: '',
  }
  formError.value = ''
  showModal.value = true
}

const canSave = computed(() =>
  form.value.ip_address.trim() !== '' && (editing.value !== null || form.value.password.trim() !== ''),
)

async function save() {
  if (!canSave.value || saving.value) return
  saving.value = true
  formError.value = ''
  try {
    const body: Record<string, unknown> = {
      name: form.value.name || null,
      ip_address: form.value.ip_address,
      ssh_user: form.value.ssh_user || 'root',
      ssh_port: Number(form.value.ssh_port) || 22,
    }
    // Only send the password when the user typed one.
    if (form.value.password.trim() !== '') body.password = form.value.password

    if (editing.value) {
      const res = await useApiFetch<{ data: Server }>(`/api/servers/${editing.value.id}`, {
        method: 'PUT',
        body,
      })
      const i = servers.value.findIndex(s => s.id === editing.value!.id)
      if (i !== -1) servers.value[i] = res.data.data
    } else {
      const res = await useApiFetch<{ data: Server }>('/api/servers', { method: 'POST', body })
      servers.value.unshift(res.data.data)
    }
    showModal.value = false
  } catch (e: any) {
    formError.value = e?.data?.message ?? 'Failed to save the server. Check your inputs and try again.'
  } finally {
    saving.value = false
  }
}

// ─── Test connectivity ────────────────────────────────────────────────────────
const testingId = ref<number | null>(null)
const testResult = ref<Record<number, string>>({})

async function test(s: Server) {
  testingId.value = s.id
  delete testResult.value[s.id]
  try {
    const res = await useApiFetch<{ data: Server & { reachable: boolean; message: string } }>(
      `/api/servers/${s.id}/test`,
      { method: 'POST' },
    )
    const i = servers.value.findIndex(x => x.id === s.id)
    if (i !== -1) servers.value[i] = res.data.data
    testResult.value[s.id] = res.data.data.message
  } catch (e: any) {
    testResult.value[s.id] = e?.data?.message ?? 'Test failed.'
  } finally {
    testingId.value = null
  }
}

// ─── Delete ───────────────────────────────────────────────────────────────────
const deletingId = ref<number | null>(null)

async function remove(s: Server) {
  if (!confirm(`Delete server ${s.name || s.ip_address}? This cannot be undone.`)) return
  deletingId.value = s.id
  try {
    await useApiFetch(`/api/servers/${s.id}`, { method: 'DELETE' })
    servers.value = servers.value.filter(x => x.id !== s.id)
  } catch (e) {
    console.error('Failed to delete server', e)
  } finally {
    deletingId.value = null
  }
}

function statusVariant(status: string) {
  return status === 'online' ? 'success' : status === 'offline' ? 'destructive' : 'secondary'
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
        <h2 class="text-xl font-semibold tracking-tight">Servers</h2>
        <p class="text-sm text-muted-foreground">Remote servers used to deploy and run campaigns.</p>
      </div>
      <Button @click="openCreate">
        <Plus class="size-4" />
        Add server
      </Button>
    </motion.div>

    <div v-if="pending" class="flex h-64 items-center justify-center text-muted-foreground">
      <Loader2 class="mr-2 size-5 animate-spin" /> Loading servers…
    </div>

    <Card v-else class="overflow-hidden p-0">
      <CardContent class="p-0">
        <!-- Table head -->
        <div class="hidden grid-cols-12 gap-4 border-b border-border bg-muted/30 px-5 py-3 text-xs font-medium uppercase tracking-wider text-muted-foreground md:grid">
          <div class="col-span-4">Server</div>
          <div class="col-span-3">Address</div>
          <div class="col-span-2">Credentials</div>
          <div class="col-span-1">Status</div>
          <div class="col-span-2 text-right">Actions</div>
        </div>

        <!-- Rows -->
        <motion.div
          v-for="(s, i) in servers"
          :key="s.id"
          :initial="{ opacity: 0, y: 10 }"
          :animate="{ opacity: 1, y: 0 }"
          :transition="{ delay: 0.04 * i, duration: 0.3 }"
          class="grid grid-cols-2 items-center gap-4 border-b border-border/60 px-5 py-4 transition-colors last:border-0 hover:bg-primary/5 md:grid-cols-12"
        >
          <div class="col-span-2 flex items-center gap-3 md:col-span-4">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary ring-1 ring-primary/20">
              <ServerIcon class="size-4.5" />
            </div>
            <div class="min-w-0">
              <p class="truncate text-sm font-medium">{{ s.name || s.ip_address }}</p>
              <p class="text-xs text-muted-foreground">Added {{ s.created_at ?? '—' }}</p>
            </div>
          </div>
          <div class="font-mono text-xs text-muted-foreground md:col-span-3">
            {{ s.ssh_user }}@{{ s.ip_address }}:{{ s.ssh_port }}
          </div>
          <div class="md:col-span-2">
            <span
              class="inline-flex items-center gap-1 text-xs"
              :class="s.has_password ? 'text-foreground' : 'text-muted-foreground'"
            >
              <KeyRound class="size-3.5" />
              {{ s.has_password ? 'Password set' : 'No password' }}
            </span>
          </div>
          <div class="md:col-span-1">
            <Badge :variant="statusVariant(s.status)" class="capitalize">{{ s.status }}</Badge>
          </div>
          <div class="col-span-2 flex items-center justify-end gap-1">
            <Button
              variant="outline"
              size="sm"
              title="Test connectivity"
              :disabled="testingId === s.id"
              @click="test(s)"
            >
              <Loader2 v-if="testingId === s.id" class="size-4 animate-spin" />
              <PlugZap v-else class="size-4" />
              <span class="hidden sm:inline">Test</span>
            </Button>
            <Button variant="ghost" size="icon" title="Edit" @click="openEdit(s)">
              <Pencil class="size-4" />
            </Button>
            <Button
              variant="ghost"
              size="icon"
              title="Delete"
              :disabled="deletingId === s.id"
              @click="remove(s)"
            >
              <Loader2 v-if="deletingId === s.id" class="size-4 animate-spin" />
              <Trash2 v-else class="size-4 text-destructive" />
            </Button>
          </div>

          <!-- Test result line -->
          <p
            v-if="testResult[s.id]"
            class="col-span-2 -mt-2 text-right text-xs md:col-span-12"
            :class="s.status === 'online' ? 'text-green-600 dark:text-green-500' : 'text-destructive'"
          >
            {{ testResult[s.id] }}
          </p>
        </motion.div>

        <div v-if="servers.length === 0" class="px-5 py-16 text-center text-sm text-muted-foreground">
          No servers yet. Click <span class="font-medium text-foreground">Add server</span> to register one.
        </div>
      </CardContent>
    </Card>
  </div>

  <!-- Create / edit modal -->
  <div
    v-if="showModal"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
    @click.self="showModal = false"
  >
    <div class="relative w-full max-w-md rounded-2xl border border-border bg-background shadow-2xl">
      <div class="flex items-center justify-between border-b border-border px-6 pb-4 pt-5">
        <div>
          <h3 class="text-base font-semibold tracking-tight">{{ editing ? 'Edit server' : 'Add server' }}</h3>
          <p class="mt-0.5 text-xs text-muted-foreground">SSH address and credentials.</p>
        </div>
        <button
          type="button"
          class="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          @click="showModal = false"
        >
          <X class="size-4" />
        </button>
      </div>

      <form class="space-y-4 px-6 py-5" @submit.prevent="save">
        <div class="space-y-1.5">
          <Label for="srv-name">Name <span class="text-muted-foreground">(optional)</span></Label>
          <Input id="srv-name" v-model="form.name" placeholder="Campaign server A" />
        </div>

        <div class="space-y-1.5">
          <Label for="srv-ip">IP address</Label>
          <Input id="srv-ip" v-model="form.ip_address" placeholder="203.0.113.10" required />
        </div>

        <div class="grid grid-cols-2 gap-3">
          <div class="space-y-1.5">
            <Label for="srv-user">SSH user</Label>
            <Input id="srv-user" v-model="form.ssh_user" placeholder="root" />
          </div>
          <div class="space-y-1.5">
            <Label for="srv-port">SSH port</Label>
            <Input id="srv-port" v-model.number="form.ssh_port" type="number" min="1" max="65535" placeholder="22" />
          </div>
        </div>

        <div class="space-y-1.5">
          <Label for="srv-pass">Password</Label>
          <Input
            id="srv-pass"
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            :placeholder="editing ? 'Leave blank to keep current password' : '••••••••'"
          />
          <p class="text-xs text-muted-foreground">Stored encrypted; never shown again.</p>
        </div>

        <p v-if="formError" class="rounded-md border border-destructive/20 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {{ formError }}
        </p>

        <div class="flex items-center justify-end gap-2 pt-1">
          <Button type="button" variant="ghost" @click="showModal = false">Cancel</Button>
          <Button type="submit" :disabled="!canSave || saving">
            <Loader2 v-if="saving" class="size-4 animate-spin" />
            {{ editing ? 'Save changes' : 'Add server' }}
          </Button>
        </div>
      </form>
    </div>
  </div>
</template>
