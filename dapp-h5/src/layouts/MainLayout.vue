<template>
  <div class="layout-shell">
    <main class="layout-content">
      <router-view />
    </main>
    <van-tabbar route fixed placeholder safe-area-inset-bottom>
      <van-tabbar-item replace to="/home" icon="home-o">首页</van-tabbar-item>
      <van-tabbar-item replace to="/community" icon="friends-o">社区</van-tabbar-item>
      <van-tabbar-item replace to="/me" icon="manager-o">我的</van-tabbar-item>
    </van-tabbar>
  </div>
</template>

<style scoped>
.layout-shell {
  min-height: 100vh;
}

.layout-content {
  min-height: 100vh;
  padding-bottom: calc(72px + env(safe-area-inset-bottom));
  box-sizing: border-box;
}

:deep(.van-tabbar) {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 999;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(14px);
  box-shadow: 0 -8px 28px rgba(15, 23, 42, 0.08);
}
</style>
