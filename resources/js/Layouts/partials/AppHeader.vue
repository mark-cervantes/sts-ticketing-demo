<script setup lang="ts">
import { usePage, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import { MoonIcon, SunIcon, MenuIcon, LogOutIcon, UserIcon, TagIcon } from '@lucide/vue'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useDarkMode } from '@/composables/useDarkMode'

interface AppHeaderProps {
  onToggleSidebar?: () => void
  onOpenCategories?: () => void
}

defineProps<AppHeaderProps>()

const page = usePage()
const { isDark, toggle: toggleDarkMode } = useDarkMode()

const userName = computed(() => page.props.auth.user.name)
const userInitial = computed(() => userName.value.charAt(0).toUpperCase())

function handleLogout(): void {
  router.post(route('logout'))
}
</script>

<template>
  <header class="sticky top-0 z-40 flex h-14 items-center border-b border-border bg-background px-4">
    <!-- Mobile hamburger -->
    <Button
      variant="ghost"
      size="icon"
      class="md:hidden mr-2"
      aria-label="Toggle sidebar"
      @click="onToggleSidebar?.()"
    >
      <MenuIcon class="size-5" />
    </Button>

    <!-- Logo / App name -->
    <Link :href="route('dashboard')" class="flex items-center gap-2 font-heading font-semibold text-foreground">
      <span class="text-primary text-lg font-bold">●</span>
      <span class="hidden sm:inline">Ticketing</span>
    </Link>

    <div class="flex-1" />

    <!-- Manage Categories -->
    <Button
      variant="ghost"
      size="icon"
      aria-label="Manage categories"
      @click="onOpenCategories?.()"
    >
      <TagIcon class="size-5" />
    </Button>

    <!-- Dark mode toggle -->
    <Button
      variant="ghost"
      size="icon"
      aria-label="Toggle dark mode"
      @click="toggleDarkMode"
    >
      <SunIcon v-if="isDark" class="size-5" />
      <MoonIcon v-else class="size-5" />
    </Button>

    <!-- User dropdown -->
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button variant="ghost" class="ml-1 gap-2 px-2">
          <Avatar size="sm">
            <AvatarFallback>{{ userInitial }}</AvatarFallback>
          </Avatar>
          <span class="hidden sm:inline text-sm font-medium">{{ userName }}</span>
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent align="end" class="w-48">
        <DropdownMenuLabel>{{ userName }}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem as-child>
          <Link :href="route('profile.edit')" class="flex items-center gap-2">
            <UserIcon class="size-4" />
            Profile
          </Link>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem @click="handleLogout" class="flex items-center gap-2">
          <LogOutIcon class="size-4" />
          Log Out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </header>
</template>
