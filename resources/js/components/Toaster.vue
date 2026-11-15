<script setup lang="ts">
import { Toaster } from 'vue-sonner';
import 'vue-sonner/style.css';
</script>

<template>
    <!--
      App-wide toast host (shadcn/Sonner). Mounted once in AppLayout; push with
      `toast()` from vue-sonner. Dark theme + rich colors give semantic
      error/success styling out of the box; tokens are tuned in app.css.
    -->
    <Toaster theme="dark" position="bottom-right" :duration="4000" :gap="10" close-button rich-colors />
</template>
