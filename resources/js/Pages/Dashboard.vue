<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold text-foreground">Dashboard</h1>
    <p class="mt-2 text-muted-foreground">
      Welcome back. Your Kanban board will appear here.
    </p>
  </div>
</template>
