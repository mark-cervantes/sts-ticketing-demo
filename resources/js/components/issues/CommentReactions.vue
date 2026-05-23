<script setup lang="ts">
import { ref } from 'vue'
import { SmilePlusIcon } from '@lucide/vue'
import { toast } from 'vue-sonner'
import { apiPost } from '@/composables/useApiFetch'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import type { ReactionSummary, ReactionToggleResponse } from '@/types/issue'

/** The 8 allowed emoji defined by the backend AllowedEmoji enum. */
const ALLOWED_EMOJI = ['👍', '👎', '😄', '🎉', '😕', '❤️', '🚀', '👀'] as const

interface Props {
  commentId: number
  initialReactions: Record<string, ReactionSummary> | undefined
}

const props = defineProps<Props>()

/** Local mutable copy of the reactions summary — updated optimistically then corrected by the server response. */
const reactions = ref<Record<string, ReactionSummary>>(
  props.initialReactions ? { ...props.initialReactions } : {},
)

const toggling = ref<string | null>(null)
const popoverOpen = ref(false)

/** Emojis that currently have at least one reaction (shown as pills). */
function visibleEmoji(): string[] {
  return ALLOWED_EMOJI.filter((e) => (reactions.value[e]?.count ?? 0) > 0)
}

async function toggleEmoji(emoji: string, closePopover = false): Promise<void> {
  if (toggling.value !== null) return

  toggling.value = emoji

  // Optimistic update
  const prev = reactions.value[emoji]
  if (prev) {
    reactions.value = {
      ...reactions.value,
      [emoji]: {
        count: prev.reacted ? prev.count - 1 : prev.count + 1,
        reacted: !prev.reacted,
      },
    }
  } else {
    reactions.value = {
      ...reactions.value,
      [emoji]: { count: 1, reacted: true },
    }
  }

  if (closePopover) {
    popoverOpen.value = false
  }

  try {
    const response = await apiPost(`/api/comments/${props.commentId}/reactions`, { emoji })

    if (response.status === 401) {
      toast.error('Session expired — please log in again.')
      reactions.value = props.initialReactions ? { ...props.initialReactions } : {}
      return
    }

    if (response.status === 422) {
      const err = (await response.json()) as { message?: string }
      toast.error(err.message ?? 'Invalid emoji.')
      reactions.value = props.initialReactions ? { ...props.initialReactions } : {}
      return
    }

    if (!response.ok) {
      toast.error('Could not toggle reaction.')
      reactions.value = props.initialReactions ? { ...props.initialReactions } : {}
      return
    }

    const data = (await response.json()) as ReactionToggleResponse
    // Authoritative server state replaces the optimistic copy
    reactions.value = { ...data.reactions_summary }
  } catch {
    toast.error('Network error — reaction not saved.')
    reactions.value = props.initialReactions ? { ...props.initialReactions } : {}
  } finally {
    toggling.value = null
  }
}
</script>

<template>
  <TooltipProvider :delay-duration="300">
    <div class="mt-1.5 flex flex-wrap items-center gap-1">
      <!-- Existing reaction pills -->
      <Tooltip
        v-for="emoji in visibleEmoji()"
        :key="emoji"
      >
        <TooltipTrigger as-child>
          <button
            type="button"
            :disabled="toggling !== null"
            :aria-label="`React with ${emoji}`"
            :aria-pressed="reactions[emoji]?.reacted ?? false"
            class="inline-flex h-6 cursor-pointer items-center gap-1 rounded-full border px-2 text-xs transition-colors disabled:pointer-events-none disabled:opacity-50"
            :class="
              reactions[emoji]?.reacted
                ? 'bg-primary/10 text-primary border-primary/20 hover:bg-primary/20'
                : 'bg-muted/50 text-muted-foreground border-transparent hover:bg-muted'
            "
            @click="toggleEmoji(emoji)"
          >
            <span>{{ emoji }}</span>
            <span class="tabular-nums">{{ reactions[emoji]?.count ?? 0 }}</span>
          </button>
        </TooltipTrigger>
        <TooltipContent side="top">
          {{ reactions[emoji]?.reacted ? 'Remove reaction' : 'Add reaction' }}
        </TooltipContent>
      </Tooltip>

      <!-- Add reaction button — opens emoji picker popover -->
      <Popover v-model:open="popoverOpen">
        <PopoverTrigger as-child>
          <Button
            variant="ghost"
            size="icon-xs"
            :aria-label="'Add reaction'"
            class="text-muted-foreground hover:text-foreground"
          >
            <SmilePlusIcon class="size-3.5" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          class="w-auto p-2"
          side="top"
          align="start"
        >
          <div class="grid grid-cols-4 gap-1">
            <button
              v-for="emoji in ALLOWED_EMOJI"
              :key="emoji"
              type="button"
              :aria-label="`React with ${emoji}`"
              :aria-pressed="reactions[emoji]?.reacted ?? false"
              class="flex size-8 cursor-pointer items-center justify-center rounded-md text-base transition-colors hover:bg-muted"
              :class="reactions[emoji]?.reacted ? 'bg-primary/10 ring-1 ring-primary/20' : ''"
              @click="toggleEmoji(emoji, true)"
            >
              {{ emoji }}
            </button>
          </div>
        </PopoverContent>
      </Popover>
    </div>
  </TooltipProvider>
</template>
