import { LayoutDashboard, Send, MonitorPlay, Server } from 'lucide-vue-next'
import type { Component } from 'vue'

export interface NavItem {
  label: string
  to: string
  icon: Component
  description: string
}

export const navItems: NavItem[] = [
  {
    label: 'Overview',
    to: '/',
    icon: LayoutDashboard,
    description: 'Program health at a glance',
  },
  {
    label: 'Campaigns',
    to: '/campaigns',
    icon: Send,
    description: 'Simulated phishing campaigns',
  },
  {
    label: 'Servers',
    to: '/servers',
    icon: Server,
    description: 'Remote servers for campaign deployment',
  },
  {
    label: 'Browser',
    to: '/browser',
    icon: MonitorPlay,
    description: 'Live Chrome session, ready to navigate',
  },
]
