<script setup lang="ts">
import type { Issue } from '@/types/issue'
import { PRIORITY_CONFIG } from '@/types/issue'
import { computed } from 'vue'
import { Badge } from '@/components/ui/badge'
import {
  FlameIcon,
  MessageCircleIcon,
  CalendarIcon,
} from '@lucide/vue'

interface IssueCardProps {
  issue: Issue
}

const props = defineProps<IssueCardProps>()

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
</script>

<template>
  <div
    class="group cursor-grab rounded-lg border border-border bg-card p-3 shadow-sm transition-shadow hover:shadow-md active:cursor-grabbing active:shadow-lg"
  >
    <!-- Top row: title + needs_attention -->
    <div class="flex items-start gap-2">
      <h4 class="flex-1 text-sm font-medium leading-snug text-card-foreground">
        {{ issue.title }}
      </h4>
      <FlameIcon
        v-if="issue.needs_attention"
        class="size-4 shrink-0 text-orange-500 dark:text-orange-400"
        aria-label="Needs attention"
      />
    </div>

    <!-- Summary preview -->
    <p v-if="truncatedSummary" class="mt-1.5 text-xs leading-relaxed text-muted-foreground">
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

    <!-- Footer row: deadline + comments count -->
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
    </div>
  </div>
</template>
