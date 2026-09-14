<template>
  <div class="min-h-screen flex flex-col bg-white dark:bg-brand-navy-deep transition-colors duration-500">
    <TheNavbar />
    <main class="flex-1">
      <slot />
    </main>
    <TheFooter />

    <!-- Global auth modal (login / register) -->
    <AuthModal />
  </div>
</template>
