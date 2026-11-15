<script setup lang="ts">
import CreateCampaignWizard from '~/components/campaigns/CreateCampaignWizard.vue'
import type { CampaignPayload } from '~/types/campaign'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'New campaign — PhishGuard' })

function onClose() {
  navigateTo('/campaigns')
}

function onCreated(campaign: CampaignPayload) {
  // Jump straight to the new campaign if the API returned its id, else the list.
  const id = (campaign as { id?: number }).id
  navigateTo(id ? `/campaigns/${id}` : '/campaigns')
}
</script>

<template>
  <!-- Fill the viewport below the sticky h-16 navbar so the wizard (and its
       noVNC preview on step 3) gets the full available height. -->
  <div class="h-[calc(100dvh-4rem)]">
    <CreateCampaignWizard @close="onClose" @created="onCreated" />
  </div>
</template>
