<script setup lang="ts">
import { Menu, LogOut, User as UserIcon, Search, Bell } from 'lucide-vue-next'
import { Button } from '~/components/ui/button'
import { Avatar, AvatarFallback } from '~/components/ui/avatar'
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
} from '~/components/ui/dropdown-menu'
import { navItems } from '~/lib/navigation'

const sidebarOpen = useState<boolean>('sidebarOpen', () => false)
const auth = useAuthStore()
const route = useRoute()

const pageTitle = computed(() => {
  const match = navItems.find(n => n.to === '/' ? route.path === '/' : route.path.startsWith(n.to))
  return match?.label ?? 'Dashboard'
})

async function onLogout() {
  await auth.logout()
  await navigateTo('/login')
}
</script>

<template>
  <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-background/80 px-4 backdrop-blur-md md:px-6">
    <!-- Mobile menu toggle -->
    <Button variant="ghost" size="icon" class="md:hidden" @click="sidebarOpen = true">
      <Menu class="size-5" />
    </Button>

    <div class="flex flex-col">
      <h1 class="text-base font-semibold leading-tight tracking-tight">{{ pageTitle }}</h1>
      <p class="hidden text-xs text-muted-foreground sm:block">PhishGuard console</p>
    </div>

    <!-- Fake search -->
    <div class="ml-auto hidden items-center gap-2 rounded-md border border-border bg-card px-3 py-1.5 text-sm text-muted-foreground lg:flex">
      <Search class="size-4" />
      <span>Search targets, campaigns…</span>
      <kbd class="ml-2 rounded border border-border bg-muted px-1.5 text-[10px]">⌘K</kbd>
    </div>

    <Button variant="ghost" size="icon" class="relative ml-auto lg:ml-0">
      <Bell class="size-5" />
      <span class="absolute right-2 top-2 size-2 rounded-full bg-primary ring-2 ring-background" />
    </Button>

    <!-- User menu -->
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <button class="flex items-center gap-2 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring">
          <Avatar class="size-9 ring-1 ring-primary/30">
            <AvatarFallback class="bg-primary/15 text-primary">{{ auth.initials }}</AvatarFallback>
          </Avatar>
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel>
          <div class="flex flex-col">
            <span class="text-sm font-medium">{{ auth.user?.name ?? 'Account' }}</span>
            <span class="text-xs font-normal text-muted-foreground">{{ auth.user?.email }}</span>
          </div>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem>
          <UserIcon class="size-4" />
          <span>Profile</span>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem class="text-primary focus:text-primary" @select="onLogout">
          <LogOut class="size-4" />
          <span>Sign out</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </header>
</template>
