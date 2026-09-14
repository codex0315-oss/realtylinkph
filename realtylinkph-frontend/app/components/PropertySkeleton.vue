<template>
  <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <AppSkeleton width="100%" height="220px" rounded="none" />
    <div class="p-4 space-y-2.5">
      <AppSkeleton width="65%" height="16px" />
      <AppSkeleton width="80%" height="13px" />
      <div class="flex gap-4 pt-0.5">
        <AppSkeleton width="20%" height="13px" />
        <AppSkeleton width="20%" height="13px" />
        <AppSkeleton width="20%" height="13px" />
      </div>
      <AppSkeleton width="35%" height="18px" />
    </div>
  </div>
</template>
