<script setup lang="ts">
import type { Issue } from '@/types/issue'
import { PRIORITY_CONFIG } from '@/types/issue'
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import {
  FlameIcon,
  MessageCircleIcon,
  CalendarIcon,
  SparklesIcon,
  ArchiveIcon,
} from '@lucide/vue'
import type { PageProps } from '@/types'

interface IssueCardProps {
  issue: Issue
}

const props = defineProps<IssueCardProps>()

const emit = defineEmits<{
  select: [issueId: number]
}>()

const page = usePage<PageProps>()

const isOwnIssue = computed(() => page.props.auth.user.id === props.issue.user.id)

/** Derive 1–2 character initials from a full name. */
const creatorInitials = computed(() => {
  const parts = props.issue.user.name.trim().split(/\s+/)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

const priorityLabel = computed(() => PRIORITY_CONFIG[props.issue.priority].label)

const priorityVariant = computed<'default' | 'secondary' | 'destructive' | 'outline'>(() => {
  switch (props.issue.priority) {
    case 'critical':
      return 'destructive'
    case 'high':
      return 'default'
    case 'medium':
      return 'secondary'
    case 'low':
      return 'outline'
  }
})

const truncatedSummary = computed(() => {
  if (!props.issue.summary) return null
  return props.issue.summary.length > 80
    ? props.issue.summary.slice(0, 80) + '…'
    : props.issue.summary
})

const formattedDeadline = computed(() => {
  if (!props.issue.deadline_at) return null
  const date = new Date(props.issue.deadline_at)
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
})

const isOverdue = computed(() => {
  if (!props.issue.deadline_at) return false
  return new Date(props.issue.deadline_at) < new Date()
})

// Click-vs-drag discrimination: track pointer position
const pointerStart = ref<{ x: number; y: number } | null>(null)
const CLICK_THRESHOLD = 5

function handlePointerDown(evt: PointerEvent): void {
  pointerStart.value = { x: evt.clientX, y: evt.clientY }
}

function handlePointerUp(evt: PointerEvent): void {
  if (!pointerStart.value) return
  const dx = Math.abs(evt.clientX - pointerStart.value.x)
  const dy = Math.abs(evt.clientY - pointerStart.value.y)
  pointerStart.value = null

  if (dx < CLICK_THRESHOLD && dy < CLICK_THRESHOLD) {
    emit('select', props.issue.id)
  }
}
</script>

<template>
  <div
    class="group cursor-grab rounded-lg border border-border bg-card p-3 shadow-sm transition-shadow hover:shadow-md active:cursor-grabbing active:shadow-lg"
    :class="issue.archived_at ? 'opacity-50 cursor-default' : ''"
    @pointerdown="handlePointerDown"
    @pointerup="handlePointerUp"
  >
    <!-- Top row: title + archived badge + needs_attention -->
    <div class="flex items-start gap-2">
      <h4 class="flex-1 text-sm font-medium leading-snug text-card-foreground">
        {{ issue.title }}
      </h4>
      <!-- Archived badge — shown when archived_at is set -->
      <span
        v-if="issue.archived_at"
        class="inline-flex shrink-0 items-center gap-0.5 rounded-sm bg-muted px-1 py-0.5 text-[10px] font-medium text-muted-foreground"
        aria-label="Archived"
      >
        <ArchiveIcon class="size-2.5" />
        Archived
      </span>
      <FlameIcon
        v-if="issue.needs_attention"
        class="size-4 shrink-0 text-orange-500 dark:text-orange-400"
        aria-label="Needs attention"
      />
    </div>

    <!-- Summary preview / AI shimmer -->
    <div
      v-if="issue.summary_status === 'pending' || issue.summary_status === 'processing'"
      class="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground"
    >
      <SparklesIcon class="size-3 animate-pulse text-primary/60" />
      <span class="animate-pulse">AI analyzing…</span>
    </div>
    <p v-else-if="truncatedSummary" class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
      {{ truncatedSummary }}
    </p>

    <!-- Badges row -->
    <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
      <Badge :variant="priorityVariant" class="text-[10px]">
        {{ priorityLabel }}
      </Badge>
      <Badge variant="outline" class="text-[10px]">
        {{ issue.category.name }}
      </Badge>
    </div>

    <!-- Footer row: deadline + comments count + creator avatar -->
    <div class="mt-2.5 flex items-center gap-3 text-xs text-muted-foreground">
      <span
        v-if="formattedDeadline"
        class="flex items-center gap-1"
        :class="isOverdue ? 'text-destructive' : ''"
      >
        <CalendarIcon class="size-3" />
        {{ formattedDeadline }}
      </span>
      <span
        v-if="issue.comments_count !== undefined && issue.comments_count > 0"
        class="flex items-center gap-1"
      >
        <MessageCircleIcon class="size-3" />
        {{ issue.comments_count }}
      </span>

      <!-- Creator avatar — pushed to the right -->
      <Avatar
        size="sm"
        class="ml-auto"
        :title="isOwnIssue ? 'You' : issue.user.name"
        :aria-label="isOwnIssue ? 'Created by you' : `Created by ${issue.user.name}`"
      >
        <AvatarFallback
          :class="isOwnIssue
            ? 'bg-primary text-primary-foreground'
            : 'bg-muted text-muted-foreground'"
        >
          {{ isOwnIssue ? 'You' : creatorInitials }}
        </AvatarFallback>
      </Avatar>
    </div>
  </div>
</template>
