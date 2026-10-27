<template>
  <!-- [overflow-x:clip]: decorative layers and slide-in reveals must never give
       the public pages a sideways scrollbar. `clip`, not `hidden`, so it doesn't
       create a scroll container (which would break sticky positioning). -->
  <div class="min-h-screen flex flex-col bg-white dark:bg-brand-navy-deep transition-colors duration-500 [overflow-x:clip]">
    <TheNavbar />
    <main class="flex-1">
      <slot />
    </main>
    <TheFooter />

    <!-- Global auth modal (login / register) -->
    <AuthModal />
  </div>
</template>
