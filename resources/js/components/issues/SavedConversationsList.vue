<script setup lang="ts">
import { computed } from 'vue'
import { Button } from '@/components/ui/button'
import { MessageSquareIcon } from '@lucide/vue'
import type { SavedConversation } from '@/types/chat'

interface Props {
  conversations: SavedConversation[]
}

const props = defineProps<Props>()

const emit = defineEmits<{
  continue: [id: number]
}>()

function relativeTime(isoString: string): string {
  const diff = Date.now() - new Date(isoString).getTime()
  const minutes = Math.floor(diff / 60_000)
  if (minutes < 1) return 'just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  const days = Math.floor(hours / 24)
  if (days < 7) return `${days}d ago`
  return new Date(isoString).toLocaleDateString()
}
</script>

<template>
  <div v-if="conversations.length > 0" class="space-y-2">
    <div class="flex items-center gap-2 text-sm font-medium text-foreground">
      <MessageSquareIcon class="size-4 text-muted-foreground" />
      Saved Conversations
    </div>

    <div class="space-y-2">
      <div
        v-for="conv in conversations"
        :key="conv.id"
        class="rounded-lg border border-border bg-muted/30 p-3"
      >
        <p class="truncate text-sm font-medium text-foreground">
          {{ conv.title ?? 'Untitled conversation' }}
        </p>
        <p class="mt-0.5 text-xs text-muted-foreground">
          Saved by {{ conv.saved_by.name ?? 'Unknown' }}
          · {{ conv.messages_count }} message{{ conv.messages_count === 1 ? '' : 's' }}
          · {{ relativeTime(conv.updated_at) }}
        </p>
        <Button
          type="button"
          variant="outline"
          size="sm"
          class="mt-2 h-7 text-xs"
          @click="emit('continue', conv.id)"
        >
          Continue
        </Button>
      </div>
    </div>
  </div>
</template>
