<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { ChevronDownIcon, SearchIcon, ZapIcon, Check } from '@lucide/vue'

// ─────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────

interface OpenRouterModel {
  id: string
  name: string
  context_length: number
  pricing: {
    prompt: string
    completion: string
  }
}

// ─────────────────────────────────────────────────────────────
// Popular models curated list (moved from AiSettings.vue)
// ─────────────────────────────────────────────────────────────

const POPULAR_MODELS = [
  'google/gemini-2.5-flash',
  'google/gemini-2.5-pro',
  'anthropic/claude-sonnet-4',
  'anthropic/claude-haiku-4',
  'openai/gpt-4.1-mini',
  'openai/gpt-4.1-nano',
  'meta-llama/llama-4-maverick',
  'deepseek/deepseek-chat-v3-0324',
  'deepseek/deepseek-r1',
  'qwen/qwen3-30b-a3b',
  'mistralai/mistral-small-3.2',
  'google/gemma-3-27b-it',
]

// ─────────────────────────────────────────────────────────────
// Props & Emits
// ─────────────────────────────────────────────────────────────

interface Props {
  modelValue: string
  models: OpenRouterModel[]
  loading: boolean
  placeholder?: string
  /** Called when the combobox opens for the first time (lazy-load trigger) */
  onOpen?: () => void
}

const props = withDefaults(defineProps<Props>(), {
  placeholder: 'Select a model…',
  onOpen: undefined,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

// ─────────────────────────────────────────────────────────────
// Internal state
// ─────────────────────────────────────────────────────────────

const open = ref(false)
const modelSearch = ref('')
const showAllModels = ref(false)
const showFreeOnly = ref(false)

// Track whether we've already triggered the lazy-load once per mount
const hasTriggeredLoad = ref(false)

// ─────────────────────────────────────────────────────────────
// Computed
// ─────────────────────────────────────────────────────────────

const selectedLabel = computed(() => {
  if (!props.modelValue) return ''
  const found = props.models.find((m) => m.id === props.modelValue)
  return found ? found.name : props.modelValue
})

const filteredModels = computed(() => {
  let models = props.models

  if (!showAllModels.value) {
    const popularSet = new Set(POPULAR_MODELS)
    models = models.filter((m) => popularSet.has(m.id))
  }

  if (showFreeOnly.value) {
    models = models.filter((m) => m.pricing?.prompt === '0')
  }

  if (modelSearch.value.trim()) {
    const q = modelSearch.value.toLowerCase()
    models = models.filter(
      (m) => m.id.toLowerCase().includes(q) || m.name.toLowerCase().includes(q),
    )
  }

  return models
})

// ─────────────────────────────────────────────────────────────
// Helpers (moved from AiSettings.vue)
// ─────────────────────────────────────────────────────────────

function formatPrice(price: string): string {
  if (price === '0') return 'Free'
  const n = parseFloat(price)
  if (isNaN(n)) return price
  return `$${(n * 1_000_000).toFixed(2)}/M`
}

function formatContext(length: number): string {
  if (length >= 1_000_000) return `${(length / 1_000_000).toFixed(0)}M ctx`
  if (length >= 1_000) return `${(length / 1_000).toFixed(0)}K ctx`
  return `${length} ctx`
}

// ─────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────

function onOpenChange(value: boolean): void {
  open.value = value

  // Lazy-load: trigger only on first open, only if not already loading/loaded
  if (value && !hasTriggeredLoad.value && props.models.length === 0 && !props.loading) {
    hasTriggeredLoad.value = true
    props.onOpen?.()
  }
}

function selectModel(modelId: string): void {
  emit('update:modelValue', modelId)
  modelSearch.value = ''
  open.value = false
}

// When user types directly in the search box, emit the typed value immediately
// so free-text fallback works even if the ID is not in the list.
function onSearchInput(event: Event): void {
  const value = (event.target as HTMLInputElement).value
  modelSearch.value = value
  emit('update:modelValue', value)
}

// Reset hasTriggeredLoad if the models list is cleared externally (provider change)
watch(
  () => props.models.length,
  (len) => {
    if (len === 0) {
      hasTriggeredLoad.value = false
    }
  },
)
</script>

<template>
  <Popover :open="open" @update:open="onOpenChange">
    <PopoverTrigger as-child>
      <button
        type="button"
        class="flex w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm ring-offset-background transition-colors hover:bg-accent focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
        :aria-expanded="open"
      >
        <span
          class="truncate font-mono text-sm"
          :class="{ 'text-muted-foreground': !modelValue }"
        >
          {{ modelValue ? (selectedLabel || modelValue) : placeholder }}
        </span>
        <ChevronDownIcon
          class="ml-2 size-4 shrink-0 text-muted-foreground transition-transform duration-200"
          :class="{ 'rotate-180': open }"
        />
      </button>
    </PopoverTrigger>

    <PopoverContent
      class="w-[var(--reka-popover-trigger-width)] p-0"
      align="start"
      :side-offset="4"
    >
      <!-- ── Controls row ───────────────────────────────────── -->
      <div class="flex items-center justify-between border-b border-border px-3 py-2 gap-3">
        <!-- Search input -->
        <div class="flex flex-1 items-center gap-2 min-w-0">
          <SearchIcon class="size-3.5 shrink-0 text-muted-foreground" />
          <input
            :value="modelSearch"
            type="text"
            placeholder="Search or type a model ID…"
            class="w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            @input="onSearchInput"
            @click.stop
          />
        </div>

        <!-- Filters -->
        <div class="flex shrink-0 items-center gap-3">
          <label class="flex cursor-pointer items-center gap-1 text-xs text-muted-foreground select-none">
            <input
              v-model="showFreeOnly"
              type="checkbox"
              class="size-3.5 rounded"
            />
            <ZapIcon class="size-3" />
            Free
          </label>
          <button
            type="button"
            class="whitespace-nowrap text-xs text-primary underline-offset-2 hover:underline"
            @click.stop="showAllModels = !showAllModels"
          >
            {{ showAllModels ? 'Popular' : 'All' }}
          </button>
        </div>
      </div>

      <!-- ── Loading skeletons ──────────────────────────────── -->
      <div v-if="loading" class="p-3 space-y-2">
        <Skeleton class="h-7 w-full" />
        <Skeleton class="h-7 w-full" />
        <Skeleton class="h-7 w-full" />
        <Skeleton class="h-7 w-4/5" />
      </div>

      <!-- ── Model list ─────────────────────────────────────── -->
      <div v-else-if="filteredModels.length > 0" class="max-h-64 overflow-y-auto">
        <!-- Section label when showing popular -->
        <p
          v-if="!showAllModels && !modelSearch.trim() && !showFreeOnly"
          class="px-3 pt-2 pb-1 text-xs font-medium text-muted-foreground uppercase tracking-wide"
        >
          Popular
        </p>
        <button
          v-for="model in filteredModels"
          :key="model.id"
          type="button"
          class="flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors hover:bg-accent"
          :class="{ 'bg-accent': modelValue === model.id }"
          @click="selectModel(model.id)"
        >
          <!-- Left: id + name -->
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
              <Check
                v-if="modelValue === model.id"
                class="size-3 shrink-0 text-primary"
              />
              <span class="truncate font-mono text-xs text-foreground">{{ model.id }}</span>
            </div>
            <div class="truncate text-xs text-muted-foreground pl-4.5">{{ model.name }}</div>
          </div>
          <!-- Right: pricing + context -->
          <div class="ml-2 flex shrink-0 items-center gap-1.5">
            <Badge
              v-if="model.pricing?.prompt === '0'"
              variant="secondary"
              class="py-0 h-4 text-xs"
            >
              Free
            </Badge>
            <span
              v-else-if="model.pricing?.prompt"
              class="text-xs text-muted-foreground"
            >
              {{ formatPrice(model.pricing.prompt) }}
            </span>
            <span
              v-if="model.context_length"
              class="text-xs text-muted-foreground"
            >
              {{ formatContext(model.context_length) }}
            </span>
          </div>
        </button>
      </div>

      <!-- ── Empty / free-text hint ─────────────────────────── -->
      <div v-else class="p-4 text-center text-sm text-muted-foreground space-y-1">
        <template v-if="models.length === 0 && !loading">
          <p>Save an OpenRouter API key first to browse models.</p>
        </template>
        <template v-else-if="modelSearch.trim()">
          <p>No models match <span class="font-mono text-xs text-foreground">{{ modelSearch }}</span>.</p>
          <p class="text-xs">
            Press <kbd class="rounded border border-border px-1 py-0.5 font-mono text-xs">Enter</kbd> or close to use this as a custom model ID.
          </p>
        </template>
        <template v-else>
          <p>No models found.</p>
        </template>
      </div>
    </PopoverContent>
  </Popover>
</template>
