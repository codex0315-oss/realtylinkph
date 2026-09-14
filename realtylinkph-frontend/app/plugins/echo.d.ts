import type Echo from 'laravel-echo'

declare module '#app' {
  interface NuxtApp {
    $echo: Echo
  }
}

declare module 'vue' {
  interface ComponentCustomProperties {
    $echo: Echo
  }
}

export {}
