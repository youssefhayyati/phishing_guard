<script setup lang="ts">
import { motion } from 'motion-v'
import {
  ArrowLeft, ExternalLink, Loader2, Trash2, Play, Pause, CheckCircle,
  Globe, Server, ArrowRightLeft, Monitor, Copy, RefreshCw, Zap,
  Rocket, Terminal, XCircle, GitBranch, Activity, Wifi, WifiOff, Check,
} from 'lucide-vue-next'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'
import { Card, CardHeader, CardTitle, CardContent } from '~/components/ui/card'
import { Button } from '~/components/ui/button'
import { Badge } from '~/components/ui/badge'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)

// ─── Types ────────────────────────────────────────────────────────────────────

interface Session {
  id: number
  token: string
  ip_address: string | null
  user_agent: string | null
  status: 'active' | 'triggered' | 'killed' | 'expired'
  triggered_action: string | null
  triggered_at: string | null
  ended_at: string | null
  created_at: string | null
}

interface Deployment {
  id: number
  status: 'pending' | 'running' | 'success' | 'failed'
  current_step: string | null
  log: string | null
  link: string | null
  admin_link: string | null
  error: string | null
  started_at: string | null
  finished_at: string | null
}

interface ServerOption {
  id: number
  name: string | null
  ip_address: string
  ssh_user: string
  ssh_port: number
  status: string
}

interface Campaign {
  id: number
  name: string
  description: string | null
  status: 'draft' | 'active' | 'scheduled' | 'completed'
  target_url: string | null
  server_id: number | null
  server_host: string | null
  server_domain: string | null
  deploy_repo_url: string | null
  deploy_branch: string | null
  has_site_tweaks?: boolean
  site_tweaks?: string | null
  deployment?: Deployment | null
  proxy_enabled: boolean
  proxy_on_the_fly: boolean
  redirect_type: 'url' | 'html'
  redirect_target: string | null
  trigger_action: string
  kill_session_url: string | null
  sent: number
  opened: number
  clicked: number
  reported: number
  start_date: string | null
  sessions: Session[]
  sessions_active: number
  agent_online?: boolean
  agent_version?: string | null
  agent_last_seen_at?: string | null
  events?: CampaignEvent[]
}

interface CampaignEvent {
  id: number
  event_type: string
  action: string | null
  severity: string | null
  payload: Record<string, any> | null
  occurred_at: string | null
}

// ─── Data ────────────────────────────────────────────────────────────────────

const campaign = ref<Campaign | null>(null)
const loading = ref(true)
const refreshing = ref(false)
const statusBusy = ref(false)
const killingId = ref<number | null>(null)
const copied = ref(false)

useHead(() => ({
  title: campaign.value ? `${campaign.value.name} — PhishGuard` : 'Campaign — PhishGuard',
}))

async function load(silent = false) {
  if (!silent) loading.value = true
  else refreshing.value = true
  try {
    const res = await useApiFetch<{ data: Campaign }>(`/api/campaigns/${id}`)
    campaign.value = res.data.data
    if (res.data.data.deployment) deployment.value = res.data.data.deployment
    syncDeployForm()
    if (isDeploying.value) startDeployPoll()
  } catch {
    // redirect to list if not found
    router.push('/campaigns')
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

// ─── Servers (for the deploy target picker) ────────────────────────────────────
const servers = ref<ServerOption[]>([])
async function loadServers() {
  try {
    const res = await useApiFetch<{ data: ServerOption[] }>('/api/servers')
    servers.value = res.data.data
  } catch { servers.value = [] }
}

// ─── Deployment (SSH provisioning) ─────────────────────────────────────────────
const deployment = ref<Deployment | null>(null)

// Admin view of the same session: no victim redirect (so embedding it never
// navigates the dashboard away).
const adminLink = computed(() => {
  const d = deployment.value
  if (!d) return ''
  return d.admin_link || (d.link ? d.link.replace('&redirect=1', '') : '')
})
const copiedWhich = ref('')
function copyLink(text: string, which: string) {
  navigator.clipboard.writeText(text).then(() => {
    copiedWhich.value = which
    setTimeout(() => { if (copiedWhich.value === which) copiedWhich.value = '' }, 1500)
  }).catch(() => {})
}
const deployForm = ref({
  server_id: null as number | null,
  deploy_repo_url: '',
  deploy_branch: 'master',
  trigger_action: 'login',
  redirect_target: '',
})

const TRIGGER_OPTIONS = [
  { value: 'login', label: 'Login / Sign-in' },
  { value: 'scan', label: 'QR code scan' },
  { value: 'click', label: 'Link click' },
  { value: 'submit', label: 'Form submit' },
  { value: 'download', label: 'File download' },
  { value: 'pageload', label: 'Page load' },
]
const deployStarting = ref(false)
const deployError = ref('')
let deployTimer: ReturnType<typeof setInterval> | null = null

const isDeploying = computed(() =>
  !!deployment.value && (deployment.value.status === 'pending' || deployment.value.status === 'running'),
)

function syncDeployForm() {
  if (!campaign.value) return
  deployForm.value.server_id = campaign.value.server_id ?? deployForm.value.server_id ?? null
  if (campaign.value.deploy_repo_url) deployForm.value.deploy_repo_url = campaign.value.deploy_repo_url
  if (campaign.value.deploy_branch) deployForm.value.deploy_branch = campaign.value.deploy_branch
  if (campaign.value.trigger_action) deployForm.value.trigger_action = campaign.value.trigger_action
  deployForm.value.redirect_target = campaign.value.redirect_target ?? ''
}

async function pollDeployment() {
  try {
    const res = await useApiFetch<{ data: Deployment | null }>(`/api/campaigns/${id}/deployment`)
    if (res.data.data) deployment.value = res.data.data
    if (!isDeploying.value) {
      stopDeployPoll()
      load(true) // refresh campaign status/sessions once provisioning settles
    }
  } catch { /* keep polling */ }
}

function startDeployPoll() {
  stopDeployPoll()
  deployTimer = setInterval(pollDeployment, 2000)
}
function stopDeployPoll() {
  if (deployTimer) { clearInterval(deployTimer); deployTimer = null }
}

async function startDeploy() {
  if (deployStarting.value || isDeploying.value) return
  deployError.value = ''
  if (!deployForm.value.server_id) {
    deployError.value = 'Select the server to deploy to first.'
    return
  }
  deployStarting.value = true
  try {
    const res = await useApiFetch<{ data: Campaign & { deployment: Deployment } }>(
      `/api/campaigns/${id}/deploy`,
      {
        method: 'POST',
        body: {
          server_id: deployForm.value.server_id,
          deploy_repo_url: deployForm.value.deploy_repo_url || null,
          deploy_branch: deployForm.value.deploy_branch || null,
          trigger_action: deployForm.value.trigger_action,
          redirect_type: 'url',
          redirect_target: deployForm.value.redirect_target || null,
        },
      },
    )
    campaign.value = { ...campaign.value!, ...res.data.data }
    deployment.value = res.data.data.deployment
    startDeployPoll()
  } catch (e: any) {
    deployError.value = e?.data?.message ?? 'Failed to start deployment.'
  } finally {
    deployStarting.value = false
  }
}

onMounted(() => { load(); loadServers() })

// Auto-refresh sessions every 15 s when campaign is active
let timer: ReturnType<typeof setInterval> | null = null
watch(() => campaign.value?.status, (s) => {
  if (s === 'active') {
    timer = setInterval(() => load(true), 15_000)
  } else {
    if (timer) clearInterval(timer)
  }
}, { immediate: true })
onUnmounted(() => { if (timer) clearInterval(timer); stopDeployPoll() })

// ─── Status management ───────────────────────────────────────────────────────

const STATUS_NEXT: Record<string, { label: string; icon: any; value: string; variant: string }[]> = {
  draft:     [{ label: 'Activate',  icon: Play,         value: 'active',    variant: 'default' }],
  active:    [{ label: 'Pause',     icon: Pause,        value: 'draft',     variant: 'outline' },
              { label: 'Complete',  icon: CheckCircle,  value: 'completed', variant: 'secondary' }],
  scheduled: [{ label: 'Activate',  icon: Play,         value: 'active',    variant: 'default' }],
  completed: [],
}

async function setStatus(value: string) {
  if (!campaign.value || statusBusy.value) return
  statusBusy.value = true
  try {
    const res = await useApiFetch<{ data: Campaign }>(`/api/campaigns/${id}/status`, {
      method: 'PATCH',
      body: { status: value },
    })
    campaign.value = { ...campaign.value, ...res.data.data }
  } finally {
    statusBusy.value = false
  }
}

// ─── Kill session ─────────────────────────────────────────────────────────────

async function killSession(sessionId: number) {
  if (killingId.value) return
  killingId.value = sessionId
  try {
    await useApiFetch(`/api/campaigns/${id}/sessions/${sessionId}`, { method: 'DELETE' })
    if (campaign.value) {
      const s = campaign.value.sessions.find(x => x.id === sessionId)
      if (s) { s.status = 'killed'; s.ended_at = new Date().toLocaleString() }
      campaign.value.sessions_active = Math.max(0, campaign.value.sessions_active - 1)
    }
  } finally {
    killingId.value = null
  }
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

const novncUrl = computed(() => {
  if (!campaign.value?.server_domain) return ''
  const d = campaign.value.server_domain
  return `http://${d}:6080/pgvnc.html?host=${d}&port=6080&autoconnect=true&scale=true`
})

const campaignUrl = computed(() => {
  if (!campaign.value?.server_domain) return ''
  return `https://${campaign.value.server_domain}`
})

function copyUrl() {
  if (!campaignUrl.value) return
  navigator.clipboard.writeText(campaignUrl.value).then(() => {
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
  })
}

function statusVariant(s: string) {
  return s === 'active' ? 'success' : s === 'scheduled' ? 'warning' : s === 'draft' ? 'outline' : 'secondary'
}

function sessionStatusVariant(s: string) {
  return s === 'active' ? 'success' : s === 'triggered' ? 'warning' : s === 'killed' ? 'destructive' : 'secondary'
}

function rate(part: number, total: number) {
  return total === 0 ? '—' : `${Math.round((part / total) * 100)}%`
}

const TRIGGER_LABEL: Record<string, string> = {
  login: 'Login / Sign-in', scan: 'QR scan', click: 'Link click',
  submit: 'Form submit', download: 'File download', pageload: 'Page load',
}

// Auto-scroll the deploy log to the bottom as new output streams in.
const logBox = ref<HTMLPreElement | null>(null)
watch(() => deployment.value?.log, async () => {
  await nextTick()
  if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight
})

const DEPLOY_STEPS = ['Connecting', 'Installing dependencies', 'Cloning project', 'Applying site tweaks', 'Starting containers', 'Done']
function stepState(label: string): 'done' | 'active' | 'todo' {
  const d = deployment.value
  if (!d) return 'todo'
  if (d.status === 'success') return 'done'
  const cur = DEPLOY_STEPS.indexOf(d.current_step ?? '')
  const mine = DEPLOY_STEPS.indexOf(label)
  if (cur === -1) return 'todo'
  if (mine < cur) return 'done'
  if (mine === cur) return d.status === 'failed' ? 'todo' : 'active'
  return 'todo'
}

function deployStatusVariant(s: string) {
  return s === 'success' ? 'success' : s === 'failed' ? 'destructive' : 'warning'
}

// ─── Activity feed (agent events) ──────────────────────────────────────────────
const EVENT_LABEL: Record<string, string> = {
  'campaign.activated': 'Agent activated',
  'session.notification': 'Session activity',
  'security.phishing_detected': 'Phishing detected',
  'evidence.uploaded': 'Evidence uploaded',
  'training.keylog_demo': 'Training: keylog demo',
  'training.cookies_demo': 'Training: cookie demo',
  'training.credentials_demo': 'Training: credential demo',
}
function eventLabel(e: CampaignEvent) {
  const base = EVENT_LABEL[e.event_type] ?? e.event_type
  return e.action ? `${base} — ${e.action.replace(/_/g, ' ')}` : base
}
function eventDetail(e: CampaignEvent) {
  const p = e.payload || {}
  if (p.url) return p.url
  if (p.metadata?.browser) return p.metadata.browser
  if (p.classification) return `${p.classification} (risk ${p.risk_score ?? '?'})`
  return ''
}
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6 p-4 md:p-6">

    <!-- Loading skeleton -->
    <div v-if="loading" class="flex h-64 items-center justify-center text-muted-foreground">
      <Loader2 class="mr-2 size-5 animate-spin" /> Loading campaign…
    </div>

    <template v-else-if="campaign">
      <!-- ── Header ──────────────────────────────────────────────────────── -->
      <motion.div
        :initial="{ opacity: 0, y: -8 }"
        :animate="{ opacity: 1, y: 0 }"
        :transition="{ duration: 0.35 }"
        class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
      >
        <div class="flex items-start gap-3">
          <NuxtLink
            to="/campaigns"
            class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md border border-border text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
          >
            <ArrowLeft class="size-4" />
          </NuxtLink>
          <div>
            <div class="flex items-center gap-2 flex-wrap">
              <h2 class="text-xl font-semibold tracking-tight">{{ campaign.name }}</h2>
              <Badge :variant="statusVariant(campaign.status)" class="capitalize">{{ campaign.status }}</Badge>
              <span
                v-if="campaign.status === 'active' && campaign.sessions_active > 0"
                class="inline-flex items-center gap-1 rounded-full bg-green-500/15 px-2 py-0.5 text-[11px] font-medium text-green-600"
              >
                <span class="size-1.5 rounded-full bg-green-500 animate-pulse inline-block" />
                {{ campaign.sessions_active }} live
              </span>
              <span
                v-if="campaign.agent_online"
                class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-medium text-primary"
                title="Agent is reporting data"
              >
                <Wifi class="size-3" /> Agent online
              </span>
              <span
                v-else-if="campaign.agent_last_seen_at"
                class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground"
                title="No recent agent heartbeat"
              >
                <WifiOff class="size-3" /> Agent offline
              </span>
            </div>
            <p v-if="campaign.description" class="text-sm text-muted-foreground mt-0.5">{{ campaign.description }}</p>
          </div>
        </div>

        <!-- Action buttons -->
        <div class="flex items-center gap-2 flex-wrap shrink-0">
          <Button
            variant="outline"
            size="sm"
            :disabled="refreshing"
            @click="load(true)"
          >
            <RefreshCw class="size-3.5" :class="{ 'animate-spin': refreshing }" />
          </Button>

          <Button
            v-if="novncUrl"
            variant="outline"
            size="sm"
            as="a"
            :href="novncUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            <Monitor class="size-3.5 mr-1.5" />
            noVNC
            <ExternalLink class="size-3 ml-1 opacity-50" />
          </Button>

          <Button
            v-if="campaignUrl"
            variant="outline"
            size="sm"
            @click="copyUrl"
          >
            <Copy class="size-3.5 mr-1.5" />
            {{ copied ? 'Copied!' : 'Copy URL' }}
          </Button>

          <template v-for="action in STATUS_NEXT[campaign.status]" :key="action.value">
            <Button
              :variant="action.variant as any"
              size="sm"
              :disabled="statusBusy || (action.value === 'active' && (deployStarting || isDeploying))"
              @click="action.value === 'active' ? startDeploy() : setStatus(action.value)"
            >
              <Loader2 v-if="(action.value === 'active' && (deployStarting || isDeploying)) || statusBusy" class="size-3.5 mr-1.5 animate-spin" />
              <component :is="action.icon" v-else class="size-3.5 mr-1.5" />
              {{ action.value === 'active' ? (isDeploying ? 'Deploying…' : 'Activate & Deploy') : action.label }}
            </Button>
          </template>
        </div>
      </motion.div>

      <!-- ── Stat tiles ──────────────────────────────────────────────────── -->
      <motion.div
        :initial="{ opacity: 0, y: 8 }"
        :animate="{ opacity: 1, y: 0 }"
        :transition="{ delay: 0.05, duration: 0.35 }"
        class="grid grid-cols-2 gap-3 lg:grid-cols-4"
      >
        <Card v-for="(tile, i) in [
          { label: 'Sent',       value: campaign.sent.toLocaleString() },
          { label: 'Open rate',  value: rate(campaign.opened, campaign.sent) },
          { label: 'Click rate', value: rate(campaign.clicked, campaign.sent) },
          { label: 'Reported',   value: rate(campaign.reported, campaign.sent) },
        ]" :key="tile.label">
          <CardContent class="p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ tile.label }}</p>
            <p class="mt-1 text-2xl font-bold tabular-nums">{{ tile.value }}</p>
          </CardContent>
        </Card>
      </motion.div>

      <!-- ── Config + Quick actions ──────────────────────────────────────── -->
      <motion.div
        :initial="{ opacity: 0, y: 8 }"
        :animate="{ opacity: 1, y: 0 }"
        :transition="{ delay: 0.1, duration: 0.35 }"
        class="grid grid-cols-1 gap-4 lg:grid-cols-3"
      >
        <!-- Configuration card -->
        <Card class="lg:col-span-2">
          <CardHeader>
            <CardTitle class="text-sm font-semibold">Configuration</CardTitle>
          </CardHeader>
          <CardContent class="grid grid-cols-1 gap-y-3 sm:grid-cols-2 text-sm">

            <div class="flex items-start gap-2.5">
              <Globe class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">Target website</p>
                <a
                  v-if="campaign.target_url"
                  :href="campaign.target_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="font-medium text-primary hover:underline break-all"
                >{{ campaign.target_url }}</a>
                <span v-else class="text-muted-foreground">—</span>
              </div>
            </div>

            <div class="flex items-start gap-2.5">
              <Server class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">Server</p>
                <p class="font-medium">{{ campaign.server_host ?? '—' }}</p>
                <p class="text-xs text-muted-foreground break-all">{{ campaign.server_domain ?? '' }}</p>
              </div>
            </div>

            <div class="flex items-start gap-2.5">
              <Zap class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">Trigger action</p>
                <p class="font-medium">{{ TRIGGER_LABEL[campaign.trigger_action] ?? campaign.trigger_action }}</p>
              </div>
            </div>

            <div class="flex items-start gap-2.5">
              <ArrowRightLeft class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
              <div>
                <p class="text-xs text-muted-foreground">Redirect</p>
                <p class="font-medium capitalize">{{ campaign.redirect_type }}</p>
                <p v-if="campaign.redirect_target" class="text-xs text-muted-foreground break-all">{{ campaign.redirect_target.slice(0, 60) }}{{ campaign.redirect_target.length > 60 ? '…' : '' }}</p>
              </div>
            </div>

            <div class="sm:col-span-2 flex flex-wrap gap-2 pt-1">
              <span
                class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium"
                :class="campaign.proxy_enabled ? 'border-primary/40 bg-primary/10 text-primary' : 'border-border text-muted-foreground'"
              >
                Proxy {{ campaign.proxy_enabled ? 'on' : 'off' }}
              </span>
              <span
                v-if="campaign.proxy_enabled"
                class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium"
                :class="campaign.proxy_on_the_fly ? 'border-primary/40 bg-primary/10 text-primary' : 'border-border text-muted-foreground'"
              >
                On-the-fly {{ campaign.proxy_on_the_fly ? 'on' : 'off' }}
              </span>
            </div>
          </CardContent>
        </Card>

        <!-- Quick actions card -->
        <Card>
          <CardHeader>
            <CardTitle class="text-sm font-semibold">Quick actions</CardTitle>
          </CardHeader>
          <CardContent class="space-y-2">
            <a
              v-if="novncUrl"
              :href="novncUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="flex w-full items-center gap-2 rounded-md border border-border px-3 py-2.5 text-sm font-medium hover:bg-muted transition-colors"
            >
              <Monitor class="size-4 text-muted-foreground" />
              <span class="flex-1">Open noVNC session</span>
              <ExternalLink class="size-3.5 text-muted-foreground" />
            </a>

            <button
              v-if="campaignUrl"
              class="flex w-full items-center gap-2 rounded-md border border-border px-3 py-2.5 text-sm font-medium hover:bg-muted transition-colors text-left"
              @click="copyUrl"
            >
              <Copy class="size-4 text-muted-foreground" />
              <span class="flex-1">{{ copied ? 'Copied!' : 'Copy campaign URL' }}</span>
            </button>

            <div
              v-if="campaign.kill_session_url"
              class="rounded-md border border-border/50 bg-muted/30 px-3 py-2.5"
            >
              <p class="text-xs text-muted-foreground">Kill-session redirect</p>
              <p class="text-xs font-mono text-foreground break-all mt-0.5">{{ campaign.kill_session_url }}</p>
            </div>
          </CardContent>
        </Card>
      </motion.div>

      <!-- ── Deployment (SSH provisioning) ───────────────────────────────── -->
      <motion.div
        :initial="{ opacity: 0, y: 8 }"
        :animate="{ opacity: 1, y: 0 }"
        :transition="{ delay: 0.12, duration: 0.35 }"
      >
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0">
            <div class="flex items-center gap-2">
              <Rocket class="size-4 text-primary" />
              <CardTitle class="text-sm font-semibold">Deployment</CardTitle>
              <Badge v-if="deployment" :variant="deployStatusVariant(deployment.status) as any" class="capitalize">
                {{ deployment.status }}
              </Badge>
            </div>
            <Button
              size="sm"
              :disabled="deployStarting || isDeploying"
              @click="startDeploy()"
            >
              <Loader2 v-if="deployStarting || isDeploying" class="size-3.5 mr-1.5 animate-spin" />
              <Rocket v-else class="size-3.5 mr-1.5" />
              {{ isDeploying ? 'Deploying…' : deployment ? 'Re-deploy' : 'Deploy' }}
            </Button>
          </CardHeader>

          <CardContent class="space-y-4">
            <p class="text-xs text-muted-foreground -mt-1">
              Activating connects to the server over SSH, installs Docker, clones the project,
              starts the containers, and gives you a link to the modified site.
            </p>

            <!-- Config (editable while not deploying) -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="space-y-1.5">
                <Label for="dep-server">Server <span class="text-destructive">*</span></Label>
                <select
                  id="dep-server"
                  v-model.number="deployForm.server_id"
                  :disabled="isDeploying"
                  class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                >
                  <option :value="null" disabled>Select a server…</option>
                  <option v-for="s in servers" :key="s.id" :value="s.id">
                    {{ s.name || s.ip_address }} — {{ s.ssh_user }}@{{ s.ip_address }}:{{ s.ssh_port }}
                  </option>
                </select>
                <p v-if="servers.length === 0" class="text-xs text-muted-foreground">
                  No servers yet — <NuxtLink to="/servers" class="text-primary hover:underline">add one</NuxtLink>.
                </p>
              </div>

              <div class="space-y-1.5 sm:col-span-2">
                <Label for="dep-repo">Git repository URL <span class="text-destructive">*</span></Label>
                <Input
                  id="dep-repo"
                  v-model="deployForm.deploy_repo_url"
                  :disabled="isDeploying"
                  placeholder="https://github.com/you/phishing.git"
                />
                <p class="text-xs text-muted-foreground">The server clones this to run the platform. Must be reachable from the server.</p>
              </div>

              <div class="space-y-1.5">
                <Label for="dep-branch"><GitBranch class="inline size-3" /> Branch</Label>
                <Input
                  id="dep-branch"
                  v-model="deployForm.deploy_branch"
                  :disabled="isDeploying"
                  placeholder="master"
                />
              </div>
            </div>

            <!-- Trigger + redirect (what happens when the victim acts) -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div class="space-y-1.5">
                <Label for="dep-trigger">Trigger</Label>
                <select
                  id="dep-trigger"
                  v-model="deployForm.trigger_action"
                  :disabled="isDeploying"
                  class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
                >
                  <option v-for="o in TRIGGER_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
              </div>
              <div class="space-y-1.5 sm:col-span-2">
                <Label for="dep-redirect">Redirect URL <span class="text-destructive">*</span></Label>
                <Input
                  id="dep-redirect"
                  v-model="deployForm.redirect_target"
                  :disabled="isDeploying"
                  type="url"
                  placeholder="https://your-awareness-page.com"
                />
                <p class="text-xs text-muted-foreground">Where the victim's browser is sent when the trigger fires. Must be a real page — not the target site's own login.</p>
              </div>
            </div>

            <p v-if="deployError" class="rounded-md border border-destructive/20 bg-destructive/10 px-3 py-2 text-sm text-destructive">
              {{ deployError }}
            </p>

            <!-- Step tracker -->
            <div v-if="deployment" class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
              <template v-for="(s, i) in DEPLOY_STEPS" :key="s">
                <span
                  class="inline-flex items-center gap-1.5"
                  :class="stepState(s) === 'done' ? 'text-green-600 dark:text-green-500'
                    : stepState(s) === 'active' ? 'text-primary font-medium' : 'text-muted-foreground'"
                >
                  <CheckCircle v-if="stepState(s) === 'done'" class="size-3.5" />
                  <Loader2 v-else-if="stepState(s) === 'active'" class="size-3.5 animate-spin" />
                  <span v-else class="inline-block size-3.5 rounded-full border border-current opacity-40" />
                  {{ s }}
                </span>
                <span v-if="i < DEPLOY_STEPS.length - 1" class="text-muted-foreground/40">→</span>
              </template>
            </div>

            <!-- Success: links + live admin view -->
            <div v-if="deployment?.status === 'success' && deployment.link" class="space-y-3">
              <!-- Victim link (send this one) -->
              <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2">
                <Badge variant="secondary" class="shrink-0">Victim</Badge>
                <code class="flex-1 truncate font-mono text-xs text-muted-foreground">{{ deployment.link }}</code>
                <button class="rounded p-1.5 hover:bg-muted" title="Copy victim link" @click="copyLink(deployment.link!, 'victim')">
                  <Check v-if="copiedWhich === 'victim'" class="size-3.5 text-green-600" />
                  <Copy v-else class="size-3.5" />
                </button>
                <a :href="deployment.link" target="_blank" rel="noopener noreferrer" class="rounded p-1.5 hover:bg-muted" title="Open">
                  <ExternalLink class="size-3.5" />
                </a>
              </div>

              <!-- Admin link (observe/control, never redirects) -->
              <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2">
                <Badge class="shrink-0">Admin</Badge>
                <code class="flex-1 truncate font-mono text-xs text-muted-foreground">{{ deployment.admin_link || deployment.link.replace('&redirect=1','') }}</code>
                <button class="rounded p-1.5 hover:bg-muted" title="Copy admin link" @click="copyLink(adminLink, 'admin')">
                  <Check v-if="copiedWhich === 'admin'" class="size-3.5 text-green-600" />
                  <Copy v-else class="size-3.5" />
                </button>
                <a :href="adminLink" target="_blank" rel="noopener noreferrer" class="rounded p-1.5 hover:bg-muted" title="Open">
                  <ExternalLink class="size-3.5" />
                </a>
              </div>

              <!-- Embedded live admin view of the same session -->
              <div class="space-y-1.5">
                <div class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                  <Monitor class="size-3.5" /> Live session (admin view)
                </div>
                <div class="h-[420px] overflow-hidden rounded-lg border border-border bg-black">
                  <NoVncFrame :url="adminLink" />
                </div>
              </div>
            </div>

            <!-- Failure -->
            <p
              v-if="deployment?.status === 'failed' && deployment.error"
              class="flex items-start gap-2 rounded-md border border-destructive/20 bg-destructive/10 px-3 py-2 text-sm text-destructive"
            >
              <XCircle class="mt-0.5 size-4 shrink-0" />
              <span>{{ deployment.error }}</span>
            </p>

            <!-- Live log -->
            <div v-if="deployment?.log" class="space-y-1.5">
              <div class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                <Terminal class="size-3.5" /> Provisioning log
                <span v-if="deployment.current_step && isDeploying" class="text-primary">· {{ deployment.current_step }}</span>
              </div>
              <pre
                ref="logBox"
                class="max-h-72 overflow-auto rounded-lg border border-border bg-black/90 p-3 font-mono text-[11px] leading-relaxed text-green-400 whitespace-pre-wrap break-words"
              >{{ deployment.log }}</pre>
            </div>
          </CardContent>
        </Card>
      </motion.div>

      <!-- ── Activity feed (data reported by the campaign agent) ──────────── -->
      <motion.div
        :initial="{ opacity: 0, y: 8 }"
        :animate="{ opacity: 1, y: 0 }"
        :transition="{ delay: 0.14, duration: 0.35 }"
      >
        <Card class="overflow-hidden p-0">
          <div class="flex items-center justify-between px-5 py-4 border-b border-border bg-muted/20">
            <div class="flex items-center gap-2">
              <Activity class="size-4 text-primary" />
              <h3 class="text-sm font-semibold">Activity</h3>
              <span class="text-xs text-muted-foreground">
                reported by the agent{{ campaign.agent_version ? ` · v${campaign.agent_version}` : '' }}
              </span>
            </div>
            <span v-if="campaign.agent_last_seen_at" class="text-xs text-muted-foreground">
              last seen {{ new Date(campaign.agent_last_seen_at).toLocaleTimeString() }}
            </span>
          </div>

          <div
            v-for="e in campaign.events"
            :key="e.id"
            class="flex items-start gap-3 border-b border-border/50 px-5 py-3 last:border-0 hover:bg-primary/5 transition-colors"
          >
            <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary/60" />
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium">{{ eventLabel(e) }}</p>
              <p v-if="eventDetail(e)" class="truncate text-xs text-muted-foreground font-mono">{{ eventDetail(e) }}</p>
            </div>
            <span class="shrink-0 text-xs text-muted-foreground">
              {{ e.occurred_at ? new Date(e.occurred_at).toLocaleString() : '' }}
            </span>
          </div>

          <div v-if="!campaign.events || campaign.events.length === 0" class="px-5 py-12 text-center text-sm text-muted-foreground">
            <Activity class="mx-auto mb-3 size-8 opacity-30" />
            <p class="font-medium text-foreground">No agent data yet</p>
            <p class="mt-1 text-xs max-w-sm mx-auto">
              Once a campaign is deployed and its agent connects, activation,
              heartbeats and session activity appear here.
            </p>
          </div>
        </Card>
      </motion.div>

      <!-- ── Sessions ────────────────────────────────────────────────────── -->
      <motion.div
        :initial="{ opacity: 0, y: 8 }"
        :animate="{ opacity: 1, y: 0 }"
        :transition="{ delay: 0.15, duration: 0.35 }"
      >
        <Card class="overflow-hidden p-0">
          <div class="flex items-center justify-between px-5 py-4 border-b border-border bg-muted/20">
            <div class="flex items-center gap-2">
              <h3 class="text-sm font-semibold">Sessions</h3>
              <span class="text-xs text-muted-foreground">{{ campaign.sessions.length }} total · {{ campaign.sessions_active }} active</span>
            </div>
            <Button variant="ghost" size="sm" :disabled="refreshing" @click="load(true)">
              <RefreshCw class="size-3.5" :class="{ 'animate-spin': refreshing }" />
              Refresh
            </Button>
          </div>

          <!-- Table head -->
          <div class="hidden grid-cols-12 gap-3 border-b border-border bg-muted/10 px-5 py-2.5 text-[11px] font-medium uppercase tracking-wider text-muted-foreground md:grid">
            <div class="col-span-3">Token / IP</div>
            <div class="col-span-2">Status</div>
            <div class="col-span-2">Trigger fired</div>
            <div class="col-span-2">Arrived</div>
            <div class="col-span-2">Ended</div>
            <div class="col-span-1 text-right">Kill</div>
          </div>

          <!-- Session rows -->
          <div
            v-for="(s, i) in campaign.sessions"
            :key="s.id"
            class="grid grid-cols-2 gap-3 border-b border-border/50 px-5 py-3.5 text-sm last:border-0 hover:bg-primary/5 transition-colors md:grid-cols-12 md:items-center"
          >
            <div class="col-span-2 md:col-span-3">
              <p class="font-mono text-xs font-medium text-foreground">{{ s.token.slice(0, 16) }}…</p>
              <p class="text-xs text-muted-foreground">{{ s.ip_address ?? 'unknown IP' }}</p>
            </div>
            <div class="md:col-span-2">
              <Badge :variant="sessionStatusVariant(s.status)" class="capitalize text-[11px]">{{ s.status }}</Badge>
            </div>
            <div class="text-xs md:col-span-2 text-muted-foreground">
              {{ s.triggered_action ? TRIGGER_LABEL[s.triggered_action] ?? s.triggered_action : '—' }}
              <p v-if="s.triggered_at" class="text-[10px]">{{ s.triggered_at }}</p>
            </div>
            <div class="text-xs text-muted-foreground md:col-span-2">{{ s.created_at ?? '—' }}</div>
            <div class="text-xs text-muted-foreground md:col-span-2">{{ s.ended_at ?? '—' }}</div>
            <div class="md:col-span-1 flex justify-end">
              <button
                v-if="s.status === 'active'"
                class="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-destructive hover:bg-destructive/10 transition-colors disabled:opacity-50"
                :disabled="killingId === s.id"
                @click="killSession(s.id)"
              >
                <Loader2 v-if="killingId === s.id" class="size-3 animate-spin" />
                <Trash2 v-else class="size-3" />
                Kill
              </button>
              <span v-else class="text-xs text-muted-foreground">—</span>
            </div>
          </div>

          <!-- Empty state -->
          <div v-if="campaign.sessions.length === 0" class="px-5 py-14 text-center text-sm text-muted-foreground">
            <Monitor class="mx-auto mb-3 size-8 opacity-30" />
            <p class="font-medium text-foreground">No sessions yet</p>
            <p class="mt-1 text-xs max-w-sm mx-auto">
              Sessions appear here once the proxy starts routing victims through this campaign.
              Make sure the campaign is <span class="font-medium text-foreground">active</span>.
            </p>
          </div>
        </Card>
      </motion.div>
    </template>

  </div>
</template>
