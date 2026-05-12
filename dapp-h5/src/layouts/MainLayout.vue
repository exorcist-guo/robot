<template>
  <div class="layout-shell">
    <main class="layout-content">
      <router-view />
    </main>
    <div class="tabbar-shell">
      <van-tabbar route class="layout-tabbar">
        <van-tabbar-item replace to="/home" icon="home-o">首页</van-tabbar-item>
        <van-tabbar-item replace to="/community" icon="friends-o">社区</van-tabbar-item>
        <van-tabbar-item replace to="/me" icon="manager-o">我的</van-tabbar-item>
      </van-tabbar>
    </div>
  </div>
</template>

<style scoped>
.layout-shell {
  min-height: 100vh;
  overflow-x: clip;
}

.layout-content {
  min-height: 100vh;
  padding-bottom: var(--safe-bottom);
  box-sizing: border-box;
}

.tabbar-shell {
  position: fixed;
  left: 0;
  right: 0;
  bottom: max(10px, env(safe-area-inset-bottom));
  z-index: 999;
  display: flex;
  justify-content: center;
  pointer-events: none;
}

.tabbar-shell :deep(.layout-tabbar) {
  position: relative;
  width: calc(100vw - 24px);
  max-width: 496px;
  margin: 0 12px;
  background: rgba(255, 255, 255, 0.92);
  border: 1px solid rgba(148, 163, 184, 0.16);
  border-radius: 24px;
  box-shadow: 0 20px 38px rgba(15, 23, 42, 0.12);
  backdrop-filter: blur(18px);
  overflow: hidden;
  pointer-events: auto;
}

.tabbar-shell :deep(.layout-tabbar::after) {
  display: none;
}

.tabbar-shell :deep(.layout-tabbar .van-tabbar-item) {
  flex: 1 1 0;
  min-width: 0;
}
</style>
