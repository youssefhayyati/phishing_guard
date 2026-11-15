<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { X, ExternalLink, Loader2, ChevronLeft, Globe, Server, ArrowRightLeft, Monitor } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Label } from '~/components/ui/label'
import type { CampaignPayload } from '~/types/campaign'

const emit = defineEmits<{
  close: []
  created: [campaign: CampaignPayload]
}>()

// ─── Steps ────────────────────────────────────────────────────────────────────

const STEPS = [
  { label: 'Basics',   icon: Globe },
  { label: 'Server',   icon: Server },
  { label: 'Redirect', icon: ArrowRightLeft },
  { label: 'Preview',  icon: Monitor },
] as const

const step = ref(0)

// ─── Form state ───────────────────────────────────────────────────────────────

const form = ref({
  name: '',
  description: '',
  target_url: '',
  server_id: null as number | null,
  server_host: '',
  server_domain: '',
  site_tweaks: '',
  proxy_enabled: false,
  proxy_on_the_fly: false,
  redirect_type: 'url' as 'url' | 'html',
  redirect_target: '',
  trigger_action: 'login',
  kill_session_url: '',
})

// ─── Servers (populate the Step 1 dropdown) ───────────────────────────────────
interface ServerOption {
  id: number
  name: string | null
  ip_address: string
  ssh_user: string
  ssh_port: number
  status: string
}

const servers = ref<ServerOption[]>([])
const serversLoading = ref(true)

async function loadServers() {
  serversLoading.value = true
  try {
    const res = await useApiFetch<{ data: ServerOption[] }>('/api/servers')
    servers.value = res.data.data
  } catch {
    servers.value = []
  } finally {
    serversLoading.value = false
  }
}
onMounted(loadServers)

// When a server is chosen, carry its IP into server_host (what the API expects).
watch(() => form.value.server_id, (id) => {
  const s = servers.value.find(x => x.id === id)
  form.value.server_host = s ? s.ip_address : ''
})


// ─── Validation per step ──────────────────────────────────────────────────────

const canProceed = computed(() => {
  switch (step.value) {
    case 0: return !!(form.value.name.trim() && form.value.target_url.trim())
    case 1: return form.value.server_id !== null && !!form.value.server_host.trim()
    case 2: return !!form.value.trigger_action
    case 3: return true
    default: return false
  }
})

// ─── noVNC preview session ────────────────────────────────────────────────────
// 'idle'      → show Launch button
// 'launching' → calling /api/novnc/launch (navigates Chrome via CDP)
// 'ready'     → iframe is live
// 'error'     → something went wrong
type VncState = 'idle' | 'launching' | 'ready' | 'error'

const vncState = ref<VncState>('idle')
const vncUrl   = ref('')   // noVNC web-UI URL returned by the backend
const vncError = ref('')

// Reset when the user goes back to an earlier step
watch(step, (s) => {
  if (s < 3) { vncState.value = 'idle'; vncUrl.value = ''; vncError.value = '' }
})

async function launchVnc() {
  vncState.value = 'launching'
  vncError.value = ''
  try {
    const res = await useApiFetch<{ url: string }>('/api/novnc/launch', {
      method: 'POST',
      body: { target_url: form.value.target_url },
    })
    vncUrl.value   = res.data.url
    vncState.value = 'ready'
    // Start every new campaign's editor from a clean slate — clear any tweaks
    // recalled from a previous session so the preview shows the unmodified site.
    setTimeout(() => {
      useApiFetch('/api/novnc/reset', { method: 'POST' }).catch(() => {})
    }, 1800)
  } catch (e: any) {
    vncError.value  = e?.data?.message ?? 'Could not reach the noVNC container. Is it running?'
    vncState.value = 'error'
  }
}

// Read the modifications the operator made in the preview browser (Site Tweaker)
// so they travel with the campaign and auto-apply on the deployed server.
async function captureTweaksFromPreview() {
  try {
    const res = await useApiFetch<{ data: { site_tweaks: string | null } }>('/api/novnc/tweaks')
    if (res.data.data.site_tweaks) form.value.site_tweaks = res.data.data.site_tweaks
  } catch { /* keep whatever was uploaded manually */ }
}

// ─── Navigation ──────────────────────────────────────────────────────────────

function next() {
  if (canProceed.value && step.value < STEPS.length - 1) step.value++
}

// ─── Submit ───────────────────────────────────────────────────────────────────

const submitting = ref(false)
const submitError = ref('')

async function submit() {
  if (submitting.value) return
  submitting.value = true
  submitError.value = ''
  try {
    // Auto-capture the modifications made in the preview browser before saving.
    if (vncState.value === 'ready') await captureTweaksFromPreview()
    const res = await useApiFetch<{ data: CampaignPayload }>('/api/campaigns', {
      method: 'POST',
      body: {
        name:             form.value.name,
        description:      form.value.description || null,
        target_url:       form.value.target_url,
        server_id:        form.value.server_id,
        server_host:      form.value.server_host,
        server_domain:    form.value.server_domain || null,
        site_tweaks:      form.value.site_tweaks || null,
        proxy_enabled:    form.value.proxy_enabled,
        proxy_on_the_fly: form.value.proxy_on_the_fly,
        redirect_type:    form.value.redirect_type,
        redirect_target:  form.value.redirect_target || null,
        trigger_action:   form.value.trigger_action,
        kill_session_url: form.value.kill_session_url || null,
      },
    })
    emit('created', res.data.data)
    emit('close')
  } catch (e: any) {
    submitError.value = e?.data?.message ?? 'Failed to create campaign. Check your inputs and try again.'
  } finally {
    submitting.value = false
  }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

const TRIGGER_OPTIONS = [
  { value: 'login',    label: 'Login / Sign-in' },
  { value: 'scan',     label: 'QR code scan' },
  { value: 'click',    label: 'Link click' },
  { value: 'submit',   label: 'Form submit' },
  { value: 'download', label: 'File download' },
  { value: 'pageload', label: 'Page load' },
]

const REDIRECT_TYPES = ['url', 'html'] as const

// ─── noVNC iframe CSS-transform scaling ───────────────────────────────────────
// The VNC framebuffer is fixed at 1920×1080. We scale the iframe down with a
// CSS transform so it fills the container exactly — no dependency on noVNC's
// internal resize behaviour, which doesn't trigger reliably inside iframes.
const VNC_W = 1920
const VNC_H = 1080
const vncContainer = ref<HTMLDivElement | null>(null)
const iframeScale  = ref(1)

function computeScale() {
  const el = vncContainer.value
  if (!el) return
  // clientWidth/Height = content box (excludes the 1px border), which is the
  // space the absolutely-positioned iframe actually lives in. Using the
  // border-box size (getBoundingClientRect) over-scales the iframe by the
  // border width, so it spills past the container.
  const w = el.clientWidth
  const h = el.clientHeight
  if (w > 0 && h > 0) iframeScale.value = Math.min(w / VNC_W, h / VNC_H)
}

let _ro: ResizeObserver | null = null

// Watch vncState so we know exactly when the iframe container mounts.
// nextTick waits for Vue's DOM commit, then rAF waits for the browser's
// layout pass — after both, clientWidth/Height return real dimensions.
watch(vncState, async (s) => {
  _ro?.disconnect()
  _ro = null
  if (s !== 'ready') return
  await nextTick()
  requestAnimationFrame(() => {
    computeScale()
    if (vncContainer.value) {
      _ro = new ResizeObserver(computeScale)
      _ro.observe(vncContainer.value)
    }
    // The instruction bar can wrap and the panel can finish its width/height
    // transition after the first measurement, shrinking the container. Without
    // a re-measure the iframe keeps the larger (now over-sized) scale and
    // overflows, so re-measure once layout has settled.
    setTimeout(computeScale, 150)
    setTimeout(computeScale, 350)
  })
})

onMounted(() => window.addEventListener('resize', computeScale))
onBeforeUnmount(() => {
  _ro?.disconnect()
  window.removeEventListener('resize', computeScale)
})
</script>

<template>
  <!-- Full-page wizard (fills the area below the navbar) -->
  <div class="relative flex h-full w-full flex-col bg-background">

      <!-- Header -->
      <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-border shrink-0">
        <div>
          <h2 class="text-base font-semibold tracking-tight">New Campaign</h2>
          <p class="text-xs text-muted-foreground mt-0.5">Configure a phishing-awareness training campaign</p>
        </div>
        <button
          type="button"
          class="flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
          @click="emit('close')"
        >
          <X class="size-4" />
          <span class="hidden sm:inline">Cancel</span>
        </button>
      </div>

      <!-- Step indicators -->
      <div class="flex items-center gap-0 px-6 pt-4 pb-3 shrink-0">
        <template v-for="(s, i) in STEPS" :key="i">
          <div class="flex items-center gap-1.5">
            <div
              class="flex size-6 items-center justify-center rounded-full text-[11px] font-semibold transition-colors"
              :class="i < step
                ? 'bg-primary text-primary-foreground'
                : i === step
                  ? 'bg-primary text-primary-foreground ring-2 ring-primary/30'
                  : 'bg-muted text-muted-foreground'"
            >
              <template v-if="i < step">✓</template>
              <template v-else>{{ i + 1 }}</template>
            </div>
            <span
              class="text-xs font-medium hidden sm:inline transition-colors"
              :class="i === step ? 'text-foreground' : 'text-muted-foreground'"
            >{{ s.label }}</span>
          </div>
          <div
            v-if="i < STEPS.length - 1"
            class="mx-2 h-px flex-1 transition-colors"
            :class="i < step ? 'bg-primary' : 'bg-border'"
          />
        </template>
      </div>

      <!-- Body — on step 3 becomes a flex column to let the iframe fill height -->
      <div
        class="flex-1 min-h-0 w-full"
        :class="step === 3 ? 'flex flex-col gap-3 px-4 py-3 overflow-hidden' : 'mx-auto max-w-2xl overflow-y-auto px-6 py-6 space-y-5'"
      >

        <!-- ── Step 0: Basics ─────────────────────────────────────────────── -->
        <template v-if="step === 0">
          <div class="space-y-4">
            <div class="space-y-1.5">
              <Label for="name">Campaign name <span class="text-destructive">*</span></Label>
              <Input
                id="name"
                v-model="form.name"
                placeholder="e.g. Q4 Password Reset"
                class="w-full"
              />
            </div>

            <div class="space-y-1.5">
              <Label for="description">Description</Label>
              <textarea
                id="description"
                v-model="form.description"
                rows="3"
                placeholder="What are employees expected to recognise in this scenario?"
                class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 resize-none"
              />
            </div>

            <div class="space-y-1.5">
              <Label for="target_url">Target website URL <span class="text-destructive">*</span></Label>
              <Input
                id="target_url"
                v-model="form.target_url"
                type="url"
                placeholder="https://mail.company.com"
                class="w-full"
              />
              <p class="text-xs text-muted-foreground">The real site your campaign will mirror through the proxy.</p>
            </div>
          </div>
        </template>

        <!-- ── Step 1: Server ─────────────────────────────────────────────── -->
        <template v-else-if="step === 1">
          <div class="space-y-4">
            <div class="space-y-1.5">
              <Label for="server_id">Server <span class="text-destructive">*</span></Label>

              <!-- Loading -->
              <div v-if="serversLoading" class="flex h-10 items-center gap-2 rounded-md border border-input px-3 text-sm text-muted-foreground">
                <Loader2 class="size-4 animate-spin" /> Loading servers…
              </div>

              <!-- No servers yet -->
              <div
                v-else-if="servers.length === 0"
                class="rounded-md border border-dashed border-border px-3 py-4 text-sm text-muted-foreground"
              >
                No servers registered yet.
                <NuxtLink to="/servers" class="font-medium text-primary hover:underline">Add a server</NuxtLink>
                first, then come back.
              </div>

              <!-- Server select -->
              <select
                v-else
                id="server_id"
                v-model.number="form.server_id"
                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <option :value="null" disabled>Select a server…</option>
                <option v-for="s in servers" :key="s.id" :value="s.id">
                  {{ s.name || s.ip_address }} — {{ s.ssh_user }}@{{ s.ip_address }}:{{ s.ssh_port }} ({{ s.status }})
                </option>
              </select>
              <p class="text-xs text-muted-foreground">The registered server that will run noVNC + Chrome for this campaign.</p>
            </div>

            <div class="space-y-1.5">
              <Label for="server_domain">Server domain <span class="text-muted-foreground">(optional)</span></Label>
              <Input
                id="server_domain"
                v-model="form.server_domain"
                placeholder="phish.company.internal"
                class="w-full"
              />
              <p class="text-xs text-muted-foreground">Public-facing domain employees receive in the simulated link. Leave blank if you don't have one yet.</p>
            </div>

            <!-- Proxy enabled toggle -->
            <div class="rounded-lg border border-border p-4 space-y-3">
              <div class="flex items-center justify-between">
                <div>
                  <p class="text-sm font-medium">Proxy</p>
                  <p class="text-xs text-muted-foreground">Route victim traffic through the reverse proxy</p>
                </div>
                <button
                  type="button"
                  :aria-checked="form.proxy_enabled"
                  class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                  :class="form.proxy_enabled ? 'bg-primary' : 'bg-input'"
                  @click="form.proxy_enabled = !form.proxy_enabled"
                >
                  <span
                    class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-background shadow-lg ring-0 transition-transform"
                    :class="form.proxy_enabled ? 'translate-x-4' : 'translate-x-0.5'"
                  />
                </button>
              </div>

              <!-- Proxy on-the-fly (only shown when proxy is on) -->
              <Transition name="fade">
                <div v-if="form.proxy_enabled" class="flex items-center justify-between pt-2 border-t border-border/50">
                  <div>
                    <p class="text-sm font-medium">Proxy on-the-fly</p>
                    <p class="text-xs text-muted-foreground">Apply proxy rules dynamically without restarting</p>
                  </div>
                  <button
                    type="button"
                    :aria-checked="form.proxy_on_the_fly"
                    class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="form.proxy_on_the_fly ? 'bg-primary' : 'bg-input'"
                    @click="form.proxy_on_the_fly = !form.proxy_on_the_fly"
                  >
                    <span
                      class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-background shadow-lg ring-0 transition-transform"
                      :class="form.proxy_on_the_fly ? 'translate-x-4' : 'translate-x-0.5'"
                    />
                  </button>
                </div>
              </Transition>
            </div>
          </div>
        </template>

        <!-- ── Step 2: Redirect & Trigger ─────────────────────────────────── -->
        <template v-else-if="step === 2">
          <div class="space-y-4">
            <!-- Trigger action -->
            <div class="space-y-1.5">
              <Label for="trigger_action">Trigger action <span class="text-destructive">*</span></Label>
              <select
                id="trigger_action"
                v-model="form.trigger_action"
                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                <option
                  v-for="opt in TRIGGER_OPTIONS"
                  :key="opt.value"
                  :value="opt.value"
                >{{ opt.label }}</option>
              </select>
              <p class="text-xs text-muted-foreground">The employee action that fires the redirect.</p>
            </div>

            <!-- Redirect type segmented control -->
            <div class="space-y-1.5">
              <Label>Redirect type</Label>
              <div class="flex gap-2">
                <button
                  v-for="t in REDIRECT_TYPES"
                  :key="t"
                  type="button"
                  class="flex-1 rounded-md border px-3 py-2 text-sm font-medium transition-colors"
                  :class="form.redirect_type === t
                    ? 'border-primary bg-primary/10 text-primary'
                    : 'border-border bg-background text-muted-foreground hover:bg-muted'"
                  @click="form.redirect_type = t"
                >
                  {{ t === 'url' ? 'URL redirect' : 'HTML page' }}
                </button>
              </div>
            </div>

            <!-- Redirect target -->
            <div class="space-y-1.5">
              <Label for="redirect_target">
                {{ form.redirect_type === 'url' ? 'Redirect URL' : 'HTML content' }}
              </Label>
              <Input
                v-if="form.redirect_type === 'url'"
                id="redirect_target"
                v-model="form.redirect_target"
                type="url"
                placeholder="https://company.com/phishing-awareness"
                class="w-full"
              />
              <textarea
                v-else
                id="redirect_target"
                v-model="form.redirect_target"
                rows="5"
                placeholder="<html>...</html>"
                class="flex min-h-[120px] w-full rounded-md border border-input bg-background px-3 py-2 font-mono text-xs ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring resize-none"
              />
              <p class="text-xs text-muted-foreground">
                {{ form.redirect_type === 'url'
                  ? 'Where to send the employee after the trigger fires.'
                  : 'An HTML page shown to the employee after the trigger fires.' }}
              </p>
            </div>

            <!-- Kill-session redirect -->
            <div class="space-y-1.5">
              <Label for="kill_session_url">Kill-session redirect URL</Label>
              <Input
                id="kill_session_url"
                v-model="form.kill_session_url"
                type="url"
                placeholder="https://company.com/session-ended"
                class="w-full"
              />
              <p class="text-xs text-muted-foreground">Where to redirect the employee when you manually end their session from the dashboard.</p>
            </div>
          </div>
        </template>

        <!-- ── Step 3: Preview in noVNC ───────────────────────────────────── -->
        <template v-else-if="step === 3">

          <!-- ① Idle — show Launch button -->
          <div
            v-if="vncState === 'idle' || vncState === 'error'"
            class="flex flex-1 flex-col items-center justify-center gap-5 py-8"
          >
            <div class="flex flex-col items-center gap-2 text-center">
              <div class="flex size-14 items-center justify-center rounded-full bg-primary/10">
                <Monitor class="size-7 text-primary" />
              </div>
              <p class="text-base font-semibold">Launch preview session</p>
              <p class="max-w-sm text-sm text-muted-foreground">
                Clicking launch opens <strong class="text-foreground">Chrome</strong> inside the
                noVNC container and navigates it to
                <code class="rounded bg-muted px-1 py-0.5 text-xs font-mono text-foreground">{{ form.target_url }}</code>.
                Use the <strong class="text-foreground">Site Tweaker</strong> extension to tweak
                the page, then come back here and click <strong class="text-foreground">Create Campaign</strong>.
              </p>
            </div>

            <!-- Error from previous attempt -->
            <p
              v-if="vncState === 'error'"
              class="max-w-sm rounded-md border border-destructive/20 bg-destructive/10 px-4 py-2 text-center text-sm text-destructive"
            >
              {{ vncError }}
            </p>

            <button
              type="button"
              class="flex items-center gap-2 rounded-lg bg-primary px-6 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 transition-colors"
              @click="launchVnc"
            >
              <Monitor class="size-4" />
              Launch noVNC session
            </button>

            <p class="text-xs text-muted-foreground">Requires the <code class="font-mono">phishguard-novnc</code> container to be running.</p>
          </div>

          <!-- ② Launching spinner -->
          <div
            v-else-if="vncState === 'launching'"
            class="flex flex-1 flex-col items-center justify-center gap-3"
          >
            <Loader2 class="size-8 animate-spin text-primary" />
            <p class="text-sm font-medium">Starting Chrome and navigating to target…</p>
            <p class="text-xs text-muted-foreground">This takes a few seconds on first run.</p>
          </div>

          <!-- ③ Ready — iframe + instruction bar -->
          <template v-else-if="vncState === 'ready'">
            <!-- Instruction bar -->
            <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/30 px-4 py-2.5">
              <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-muted-foreground">
                <span class="flex items-center gap-1.5">
                  <span class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary text-[10px] font-bold">1</span>
                  Chrome is at <code class="ml-0.5 font-mono text-foreground">{{ form.target_url }}</code>
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary text-[10px] font-bold">2</span>
                  Tweak the site with <strong class="text-foreground">Site Tweaker</strong>
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="inline-flex size-4 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary text-[10px] font-bold">3</span>
                  Click <strong class="text-foreground">Create Campaign</strong> when done
                </span>
              </div>
              <a
                :href="vncUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline"
              >
                Full tab <ExternalLink class="size-3" />
              </a>
            </div>

            <!-- noVNC iframe — CSS-transform scaled to fit the container -->
            <div
              ref="vncContainer"
              class="relative flex-1 min-h-0 overflow-hidden rounded-lg border border-border bg-black"
            >
              <iframe
                :src="vncUrl"
                class="absolute left-1/2 top-1/2 border-0"
                :style="{
                  width:           VNC_W + 'px',
                  height:          VNC_H + 'px',
                  transformOrigin: 'center center',
                  transform:       `translate(-50%, -50%) scale(${iframeScale})`,
                }"
                scrolling="no"
                allow="fullscreen clipboard-read clipboard-write"
                allowfullscreen
                @load="computeScale"
              />
              <!-- Floating fallback hint -->
              <div class="absolute bottom-3 right-3 z-10 flex items-center gap-2 rounded-md border border-border bg-background/90 px-3 py-1.5 text-xs text-muted-foreground backdrop-blur-sm">
                Not loading?
                <a :href="vncUrl" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1 text-primary hover:underline">
                  Open in new tab <ExternalLink class="size-3" />
                </a>
              </div>
            </div>
          </template>

          <!-- Submit error -->
          <p
            v-if="submitError"
            class="shrink-0 rounded-md border border-destructive/20 bg-destructive/10 px-3 py-2 text-sm text-destructive"
          >
            {{ submitError }}
          </p>
        </template>

      </div>

      <!-- Footer -->
      <div class="flex items-center justify-between gap-3 px-6 pb-5 pt-4 border-t border-border shrink-0">
        <Button
          variant="ghost"
          size="sm"
          :disabled="step === 0"
          @click="step > 0 && step--"
        >
          <ChevronLeft class="size-4 mr-1" />
          Back
        </Button>

        <div class="flex items-center gap-2">
          <!-- Step dots -->
          <div class="flex gap-1 mr-2">
            <span
              v-for="(_, i) in STEPS"
              :key="i"
              class="inline-block h-1.5 rounded-full transition-all"
              :class="i === step ? 'w-4 bg-primary' : 'w-1.5 bg-border'"
            />
          </div>

          <Button
            v-if="step < STEPS.length - 1"
            :disabled="!canProceed"
            @click="next()"
          >
            Next
          </Button>
          <Button
            v-else
            :disabled="submitting"
            @click="submit()"
          >
            <Loader2 v-if="submitting" class="size-4 mr-1.5 animate-spin" />
            Create Campaign
          </Button>
        </div>
      </div>

  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease, transform 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}
</style>
