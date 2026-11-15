export interface CampaignPayload {
  id: number
  name: string
  description: string | null
  status: string
  target_url: string
  server_host: string
  server_domain: string
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
}
